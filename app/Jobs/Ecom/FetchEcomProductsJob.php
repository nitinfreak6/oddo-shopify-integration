<?php

namespace App\Jobs\Ecom;

use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pull NEW and UPDATED products from ecom → ERP.
 *
 * Cursor: last_ecom_write_date in sync_queue_state (type = 'products').
 * Only products updated since last run are fetched — no repeated full pulls.
 */
class FetchEcomProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;
    public int $timeout   = 600;

    public function __construct(
        private readonly bool    $fullSync     = false,
        private readonly ?int    $limit        = null,
        private readonly ?string $updatedSince = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, ProductSyncService $syncService, SettingsService $settings): void
    {
        if (!$settings->isProductSyncEnabled()) {
            Log::info('FetchEcomProductsJob: skipped — product sync disabled.');
            return;
        }

        $mode = $settings->productSyncMode();

        if ($mode === 'erp_to_ecom') {
            Log::info("FetchEcomProductsJob: skipped — mode is {$mode}.");
            return;
        }

        $state  = SyncQueueState::forType('products');
        $driver = $ecom->driverName();

        if ($state->is_running && $state->run_started_at?->gt(now()->subMinutes(10))) {
            Log::warning('FetchEcomProductsJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            if ($this->updatedSince) {
                $since = $this->updatedSince;
            } elseif ($this->fullSync) {
                $since = null;
            } else {
                $since = $state->last_ecom_write_date ?? now()->subDays(30)->toIso8601String();
            }

            Log::info("FetchEcomProductsJob [{$driver}]: fetching products" . ($since ? " updated since {$since}" : ' (full)'));

            $filters = [];
            if ($since) {
                $filters['updated_at_min'] = $since;
            }
            if ($this->limit) {
                $filters['limit'] = $this->limit;
            }

            $products = $ecom->getProducts($filters);
            $total    = count($products);

            Log::info("FetchEcomProductsJob [{$driver}]: found {$total} products.");

            $stored          = 0;
            $latestUpdatedAt = null;

            foreach ($products as $ecomProduct) {
                $ecomId    = (string) ($ecomProduct['id'] ?? '');
                $updatedAt = $ecomProduct['updated_at'] ?? null;
                if (!$ecomId) continue;

                $existing = \App\Models\SyncMapping::where('entity_type', 'product')
                    ->where('ecom_id', $ecomId)
                    ->first();

                // ── Save full product JSON to storage ──
                $cacheData = [
                    'fetched_at'  => now()->toISOString(),
                    'ecom_id'     => $ecomId,
                    'ecom_driver' => $driver,
                    'product'     => $ecomProduct,
                ];
                $filePath = 'ecom_products/' . $ecomId . '.json';
                Storage::disk('local')->put($filePath, json_encode($cacheData, JSON_PRETTY_PRINT));

                $alreadyInErp = $existing && $existing->erp_id && $existing->erp_id !== '0';

                // ── Detect whether the ecom product actually changed ──
                // Compare the freshly-fetched updated_at against what we stored
                // the last time we pushed this product. A new product, or one
                // whose updated_at moved forward, must be re-marked pending so
                // the push step (ProductsController::postAll) picks it up again.
                $prevUpdatedAt = $existing?->ecom_updated_at;
                $isChanged = true;
                if ($alreadyInErp && $prevUpdatedAt && $updatedAt) {
                    $isChanged = \Carbon\Carbon::parse($updatedAt)->gt($prevUpdatedAt);
                }
                $needsPush = !$alreadyInErp || $isChanged;

                // ── Update ProductCache ──
                $cacheFields = [
                    'name'         => $ecomProduct['title'] ?? $ecomProduct['name'] ?? null,
                    'default_code' => $ecomProduct['variants'][0]['sku'] ?? null,
                    'file_path'    => $filePath,
                    'raw_data'     => $cacheData,
                    'fetched_at'   => now(),
                ];
                if ($needsPush) {
                    $cacheFields['ecom_status']    = ProductCache::STATUS_PENDING;
                    $cacheFields['shopify_status'] = ProductCache::STATUS_PENDING;
                }
                ProductCache::updateOrCreate(['ecom_product_id' => $ecomId], $cacheFields);

                // ── Update SyncMapping — always refresh metadata + updated_at ──
                $mappingFields = [
                    'ecom_handle'         => $ecomProduct['handle'] ?? null,
                    'last_sync_direction' => 'ecom_to_erp',
                    'metadata'            => $ecomProduct,
                    'last_synced_at'      => now(),
                    'ecom_updated_at'     => $updatedAt,
                ];
                if ($needsPush) {
                    $mappingFields['ecom_status'] = 'pending';
                }
                \App\Models\SyncMapping::updateOrCreate(
                    ['entity_type' => 'product', 'ecom_id' => $ecomId, 'ecom_driver' => $driver],
                    $mappingFields
                );

                // ── SyncLog ──
                SyncLog::create([
                    'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                    'entity_type'     => 'product',
                    'entity_id'       => $ecomId,
                    'action'          => 'fetch',
                    'status'          => SyncLog::STATUS_SUCCESS,
                    'request_payload' => json_encode($ecomProduct),
                    'synced_at'       => now(),
                ]);

                if ($updatedAt && ($latestUpdatedAt === null || $updatedAt > $latestUpdatedAt)) {
                    $latestUpdatedAt = $updatedAt;
                }

                $stored++;
            }

            $notes = $stored === 0 ? 'nothing_changed' : "fetched:{$stored}";

            $stateFields = [
                'is_running'     => false,
                'last_poll_at'   => now(),
                'run_started_at' => null,
                'notes'          => $notes,
            ];
            if ($latestUpdatedAt !== null) {
                $stateFields['last_ecom_write_date'] = $latestUpdatedAt;
            }
            $state->update($stateFields);

            Log::info("FetchEcomProductsJob [{$driver}]: stored={$stored}");

        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            Log::error("FetchEcomProductsJob [{$driver}]: job failed — " . $e->getMessage());
            throw $e;
        }
    }
}