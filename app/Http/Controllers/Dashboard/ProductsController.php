<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Ecom\FetchEcomProductsJob;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        $syncMode  = $this->settings->productSyncMode();
        $search    = $request->input('search', '');
        $status    = $request->input('status', 'all');
        $perPage   = (int) $request->input('per_page', 25);
        $direction = $request->input('direction', 'erp_to_ecom');

        $products = match($syncMode) {
            'erp_to_ecom'   => $this->getErpToEcomProducts($search, $status, $perPage),
            'ecom_to_erp'   => $this->getEcomToErpProducts($search, $status, $perPage),
            'bidirectional' => $this->getBidirectionalProducts($search, $status, $perPage, $direction),
            default         => collect([]),
        };

        $stats = match($syncMode) {
            'erp_to_ecom'   => $this->getErpToEcomStats(),
            'ecom_to_erp'   => $this->getEcomToErpStats(),
            'bidirectional' => $this->getBidirectionalStats(),
            default         => [],
        };

        $ecomDriver   = $this->settings->ecomDriver();
        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');


        return view('dashboard.products', compact(
            'products', 'search', 'status', 'perPage', 'stats',
            'syncMode', 'ecomDriver', 'shopifyStore', 'direction'
        ));
    }

    // ── Fetch: ERP → cache only (no push) ───────────────────────────────────
    public function fetch()
    {
        // Use ProductCacheService directly instead of FetchErpProductsJob
        // to avoid ShouldBeUnique lock issues and ensure autoPush is never triggered.
        try {
            $erp       = app(\App\Services\Erp\ErpInterface::class);
            $cache     = app(\App\Services\ProductCacheService::class);
            $state     = \App\Models\SyncQueueState::forType('products');
            $writeDate = $state->getErpWriteDate();

            $products = $erp->getProductsModifiedSince($writeDate);

            if (empty($products)) {
                $state->markComplete($writeDate, 'nothing_changed');
                return redirect()->route('dashboard.products')->with('info', 'No new or updated products in ' . $this->settings->erpDisplayName() . ' since last sync.');
            }

            $fetched         = 0;
            $latestWriteDate = $writeDate;

            foreach ($products as $product) {
                $cache->fetchAndCacheSingle((int) $product['id']);
                $fetched++;
                if (($product['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $product['write_date'];
                }
            }

            // Advance cursor by 1 second
            $cursor = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
            $state->markComplete($cursor, "synced:{$fetched}");

            return redirect()->route('dashboard.products')->with('success', "{$fetched} product(s) fetched from " . $this->settings->erpDisplayName() . '. Use "Push to ' . $this->settings->ecomDisplayName() . '" to sync.');

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.products')->with('error', 'Fetch from ERP failed: ' . $e->getMessage());
        }
    }

    // ── Pull: Ecom → local (fetch only, no push to ERP) ─────────────────────
    public function pull()
    {
        FetchEcomProductsJob::dispatchSync(fullSync: false);

        $notes = \App\Models\SyncQueueState::forType('products')->fresh()->notes ?? '';

        if ($notes === 'nothing_changed' || str_starts_with($notes, 'fetched:0')) {
            return redirect()->route('dashboard.products')->with('info', 'No new or updated products in ' . $this->settings->ecomDisplayName() . ' since last sync.');
        }

        if (str_starts_with($notes, 'fetched:')) {
            preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
            $fetched = $m[1] ?? '?';
            $skipped = isset($m[2]) ? " ({$m[2]} unchanged skipped)" : '';
            return redirect()->route('dashboard.products')->with('success', "{$fetched} product(s) fetched{$skipped}. Click <strong>Push to " . $this->settings->erpDisplayName() . '</strong> to sync.');
        }

        return redirect()->route('dashboard.products')->with('success', 'Products fetched from ' . $this->settings->ecomDisplayName() . '. Click Push to ' . $this->settings->erpDisplayName() . '.');
    }

    // ── Post all: direction-aware push ───────────────────────────────────────
    // ecom_to_erp: push pulled Shopify products → Odoo
    // erp_to_ecom: push cached Odoo products → Shopify
    public function postAll(Request $request)
    {
        $syncMode   = $this->settings->productSyncMode();
        $ecomDriver = $this->settings->ecomDriver();

        // ── Shopify → Odoo (ecom_to_erp) ─────────────────────────────────
        if ($syncMode === 'ecom_to_erp') {
            $pending = SyncMapping::where('entity_type', 'product')
                ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                ->where(function ($q) {
                    // New products not yet in ERP
                    $q->whereNull('erp_id')
                      ->orWhere('erp_id', '0')
                      ->orWhere('erp_id', '')
                      // Re-fetched products that need an update pushed to ERP
                      ->orWhere('ecom_status', 'pending');
                })
                ->get();

            if ($pending->isEmpty()) {
                return redirect()->route('dashboard.products')->with('info', 'No products pending push to ' . $this->settings->erpDisplayName() . '. Run "Fetch from ' . $this->settings->ecomDisplayName() . '" first.');
            }

            $pushed = 0;
            $failed = 0;
            $ecom   = app(\App\Services\Ecom\EcomInterface::class);

            foreach ($pending as $mapping) {
                try {
                    // Fetch fresh product data from Shopify
                    $ecomProduct = $ecom->getProduct($mapping->ecom_id);

                    if (empty($ecomProduct)) {
                        \Illuminate\Support\Facades\Log::warning("postAll ecom_to_erp: no data for ecom#{$mapping->ecom_id}");
                        $failed++;
                        continue;
                    }

                    // Create product in Odoo directly — maps Shopify title/price/SKU
                    $erp           = app(\App\Services\Erp\ErpInterface::class);
                    $alreadyInErp  = $mapping->erp_id && $mapping->erp_id !== '0';

                    if ($alreadyInErp) {
                        $erp->upsertProduct(array_merge($ecomProduct, ['id' => (int) $mapping->erp_id]));
                        $erpId = $mapping->erp_id;
                    } else {
                        $erpId = $erp->createProduct($ecomProduct);
                    }

                    if ($erpId) {
                        $mapping->update([
                            'erp_id'              => (string) $erpId,
                            'ecom_status'         => 'posted',
                            'last_synced_at'      => now(),
                            'last_sync_direction' => 'ecom_to_erp',
                        ]);
                    }

                    $pushed++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("postAll ecom_to_erp: failed for ecom#{$mapping->ecom_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} product(s) pushed to " . $this->settings->erpDisplayName() . ".";
            if ($failed) $msg .= " {$failed} failed — check logs.";
            return redirect()->route('dashboard.products')->with($failed ? 'warning' : 'success', $msg);
        }

        // ── ERP → Ecom (erp_to_ecom or bidirectional) ───────────────────
        $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

        if (!$ecomJobClass) {
            return redirect()->route('dashboard.products')->with('error', "No push job registered for ecom driver [{$ecomDriver}].");
        }

        $amazonEnabled = $this->settings->isAmazonChannelEnabled();

        // Only push pending/failed — skip already sent with no changes
        $erpIdCol = ProductCache::erpIdColumn();
        $records  = ProductCache::where(function ($q) {
                $col = ProductCache::ecomStatusColumn();
                $q->where($col, ProductCache::STATUS_PENDING)
                  ->orWhere($col, ProductCache::STATUS_FAILED)
                  ->orWhereNull($col);
            })->get();

        if ($records->isEmpty()) {
            return redirect()->route('dashboard.products')->with('info', 'No products pending push. Fetch from ' . $this->settings->erpDisplayName() . ' first.');
        }

        $queued  = 0;
        $skipped = 0;
        foreach ($records as $record) {
            $erpId = (int) $record->$erpIdCol;
            if (!$erpId) continue;

            // Skip if sent and write_date unchanged since last fetch
            if ($record->ecom_status === ProductCache::STATUS_SENT && $record->fetched_at) {
                $product      = app(\App\Services\Erp\ErpInterface::class)->getProductById($erpId);
                $erpWriteDate = $product['write_date'] ?? null;
                if ($erpWriteDate && !\Carbon\Carbon::parse($erpWriteDate)->isAfter(\Carbon\Carbon::parse($record->fetched_at))) {
                    $skipped++;
                    continue;
                }
            }

            $ecomJobClass::dispatch($erpId);
            if ($amazonEnabled) \App\Jobs\Amazon\PushProductToAmazonJob::dispatch($erpId);
            $queued++;
        }

        if ($queued === 0) {
            return redirect()->route('dashboard.products')->with('info', "All products already pushed and unchanged." . ($skipped > 0 ? " {$skipped} skipped." : ''));
        }

        $skipNote = $skipped > 0 ? " ({$skipped} already up to date skipped)" : '';
        return redirect()->route('dashboard.products')->with('success', "{$queued} product(s) queued to push to " . $this->settings->ecomDisplayName() . "{$skipNote}.");
    }

    // ── Show product detail ───────────────────────────────────────────────────

    public function show(string $id)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return $this->showEcomToErp($id);
        }

        return $this->showErpToEcom((int) $id);
    }

    private function showErpToEcom(int $erpId)
    {
        $productCache = ProductCache::where('erp_id', $erpId)
            ->orWhere('odoo_id', $erpId)
            ->first();

        if (!$productCache || !$productCache->cacheExists()) {
            return redirect()->route('dashboard.products')
                ->with('error', "No cached data for product #{$erpId}.");
        }

        $data = $productCache->readCache();

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', (string) $erpId)
            ->whereIn('direction', ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'])
            ->latest()
            ->first();

        // Only build the outgoing payload when the product has actually been
        // pushed at least once. Before that the Shopify tab shows a placeholder.
        $ecomPayload = null;
        if ($syncLog) {
            // Prefer the exact payload that was sent (stored on the log row).
            // Fall back to rebuilding it live so the tab is never blank on old logs.
            if ($syncLog->request_payload) {
                $decoded     = json_decode($syncLog->request_payload, true);
                $ecomPayload = $decoded ?? [];
            }

            if (empty($ecomPayload)) {
                try {
                    $shopifyService = app(\App\Services\Shopify\ShopifyProductService::class);
                    $ecomPayload    = $shopifyService->buildPayload(
                        $data['template']         ?? [],
                        $data['variants']         ?? [],
                        $data['attribute_values'] ?? [],
                    );
                } catch (\Throwable $e) {
                    $ecomPayload = ['_error' => $e->getMessage()];
                }
            }
        }

        $ecomResponse = null;
        if ($syncLog?->response_payload) {
            $ecomResponse = json_decode($syncLog->response_payload, true) ?? $syncLog->response_payload;
        }

        $odooId = $erpId;
        $shopifyStore = $this->settings->shopifyShop();
        $apiVersion  = $this->settings->shopifyVersion() ?: '2024-01';

        $syncMode = 'erp_to_ecom';
        return view('dashboard.products-detail', compact(
            'syncMode', 'erpId', 'odooId', 'data', 'shopifyStore', 'apiVersion', 'productCache', 'syncLog',
            'ecomPayload', 'ecomResponse'
        ));
    }

    private function showEcomToErp(string $id)
    {
        // ID may be ecom_id (Shopify) or erp_id (Odoo)
        $mapping = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->where(function ($q) use ($id) {
                $q->where('ecom_id', $id)->orWhere('erp_id', $id);
            })
            ->first();

        if (!$mapping) {
            return redirect()->route('dashboard.products')->with('error', "No product mapping found for ID #{$id}.");
        }

        // ── Load cached JSON from storage (mirrors erp_to_ecom readCache) ──
        $filePath  = 'ecom_products/' . $mapping->ecom_id . '.json';
        $cacheData = null;

        if (Storage::disk('local')->exists($filePath)) {
            $raw       = Storage::disk('local')->get($filePath);
            $cacheData = $raw ? json_decode($raw, true) : null;
        }

        // Fallback: rebuild from SyncMapping metadata if file missing
        if (!$cacheData) {
            $meta      = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true);
            $cacheData = [
                'fetched_at'  => $mapping->last_synced_at?->toISOString(),
                'ecom_id'     => $mapping->ecom_id,
                'ecom_driver' => $mapping->ecom_driver ?? $this->settings->ecomDriver(),
                'product'     => $meta,
            ];
        }

        // ── Push log only (create/update) — fetch logs are NOT a push ──
        $pushLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', $mapping->ecom_id)
            ->whereIn('direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->whereIn('action', ['create', 'update'])
            ->latest()
            ->first();

        // What was sent TO the ERP and what ERP returned (only present after an actual push)
        $erpPayload = null;
        if ($pushLog?->request_payload) {
            $erpPayload = json_decode($pushLog->request_payload, true) ?? [];
        }

        $erpResponse = $pushLog?->response_payload
            ? (json_decode($pushLog->response_payload, true) ?? $pushLog->response_payload)
            : null;

        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');
        $apiVersion   = $this->settings->shopifyVersion() ?: '2024-01';

        return view('dashboard.products-detail', [
            'syncMode'     => 'ecom_to_erp',
            'erpId'        => $mapping->erp_id ?: null,
            'odooId'       => $mapping->erp_id ?: null,
            'ecomId'       => $mapping->ecom_id,
            'data'         => $cacheData,
            'productCache' => null,
            'syncLog'      => $pushLog,      // null until actually pushed to ERP
            'ecomPayload'  => $erpPayload,   // payload sent to ERP
            'ecomResponse' => $erpResponse,  // ERP response
            'mapping'      => $mapping,
            'shopifyStore' => $shopifyStore,
            'apiVersion'   => $apiVersion,
        ]);
    }

    // ── Single product actions ─────────────────────────────────────────────────

    public function fetchSingle(int $erpId)
    {
        try {
            $cacheService = app(\App\Services\ProductCacheService::class);
            $erpIdCol     = ProductCache::erpIdColumn();
            $before       = ProductCache::where($erpIdCol, $erpId)->value('fetched_at');

            $cache = $cacheService->fetchAndCacheSingle($erpId);

            // Detect if the cache was actually updated (fetched_at changed) or skipped
            $after = $cache->fresh()->fetched_at;
            $wasSkipped = $before && $after && \Carbon\Carbon::parse($before)->eq(\Carbon\Carbon::parse($after));

            if ($wasSkipped) {
                return redirect()->route('dashboard.products.show', $erpId)->with('info', "Product #{$erpId} is up to date — no changes in " . $this->settings->erpDisplayName() . ".");
            }

            return redirect()->route('dashboard.products.show', $erpId)->with('success', "Product #{$erpId} fetched from " . $this->settings->erpDisplayName() . '.');
        } catch (\Throwable $e) {
            return redirect()->route('dashboard.products.show', $erpId)->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    public function postSingle(int $erpId)
    {
        $ecomDriver   = $this->settings->ecomDriver();
        $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

        if (!$ecomJobClass) {
            return redirect()->route('dashboard.products.show', $erpId)->with('error', "No push job registered for driver [{$ecomDriver}].");
        }

        // Skip if already sent and ERP write_date hasn't changed since last push
        $erpIdCol = ProductCache::erpIdColumn();
        $cache    = ProductCache::where($erpIdCol, $erpId)->first();

        if ($cache && $cache->ecom_status === ProductCache::STATUS_SENT) {
            $product      = app(\App\Services\Erp\ErpInterface::class)->getProductById($erpId);
            $erpWriteDate = $product['write_date'] ?? null;

            if ($erpWriteDate && $cache->fetched_at) {
                $erpWrittenAt = \Carbon\Carbon::parse($erpWriteDate);
                $fetchedAt    = \Carbon\Carbon::parse($cache->fetched_at);

                if (!$erpWrittenAt->isAfter($fetchedAt)) {
                    return redirect()->route('dashboard.products.show', $erpId)->with('info',
                        "Product #{$erpId} already pushed and unchanged — skipped."
                    );
                }
            }
        }

        $ecomJobClass::dispatchSync($erpId);

        return redirect()->route('dashboard.products.show', $erpId)->with('success',
            "Product #{$erpId} pushed to " . $this->settings->ecomDisplayName() . '.'
        );
    }

    public function refresh(int $erpId)
    {
        return $this->fetchSingle($erpId);
    }

    // ── Private data helpers ──────────────────────────────────────────────────

    private function getErpToEcomProducts(?string $search, string $status, int $perPage)
    {
        $query = ProductCache::query()->orderByDesc('fetched_at');

        if ($search) {
            $query->search($search);
        }

        if (in_array($status, ['sent', 'failed', 'pending'])) {
            $query->ecomStatus($status);
        } elseif ($status === 'updated') {
            // pending records where updated_at > fetched_at (re-fetched/edited after initial sync)
            $query->where('ecom_status', ProductCache::STATUS_PENDING)
                  ->whereColumn('updated_at', '>', 'fetched_at');
        }

        return $query->paginate($perPage)->withQueryString();
    }

    private function getEcomToErpProducts(?string $search, string $status, int $perPage)
    {
        $query = SyncMapping::where('sync_mappings.entity_type', 'product')
            ->whereIn('sync_mappings.last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->orderByDesc('sync_mappings.last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sync_mappings.ecom_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.erp_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.ecom_handle', 'like', "%{$search}%");
            });
        }

        $query->leftJoin('product_cache', function ($join) {
            // Ecom → ERP cache rows are keyed by ecom_product_id (the fetch job
            // never sets erp_id on them), so join on the ecom id — the stable
            // shared key in this direction. Joining on erp_id silently misses
            // and the view falls back to the handle, showing a stale name.
            $join->on('product_cache.ecom_product_id', '=', 'sync_mappings.ecom_id');
        })->select([
            'sync_mappings.*',
            'product_cache.name as product_name',
            'product_cache.default_code as sku',
        ]);

        if ($status !== 'all') {
            $query->whereExists(function ($q) use ($status) {
                $q->select(DB::raw(1))
                  ->from('sync_logs')
                  ->whereColumn('sync_logs.entity_id', 'sync_mappings.ecom_id')
                  ->where('sync_logs.entity_type', 'product')
                  ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                  ->where('sync_logs.status', $status)
                  ->limit(1);
            });
        }

        $results = $query->paginate($perPage)->withQueryString();

        $results->getCollection()->transform(function ($product) {
            $latestLog = SyncLog::where('entity_id', $product->ecom_id)
                ->where('entity_type', 'product')
                ->whereIn('direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                ->latest()
                ->first();

            $product->latest_log_status = $latestLog?->status ?? 'pending';
            return $product;
        });

        return $results;
    }

    private function getBidirectionalProducts(?string $search, string $status, int $perPage, string $direction)
    {
        return $direction === 'ecom_to_erp'
            ? $this->getEcomToErpProducts($search, $status, $perPage)
            : $this->getErpToEcomProducts($search, $status, $perPage);
    }

    private function getErpToEcomStats(): array
    {
        return [
            'total'   => ProductCache::count(),
            'sent'    => ProductCache::countEcomStatus('sent'),
            'failed'  => ProductCache::countEcomStatus('failed'),
            'updated' => ProductCache::where('ecom_status', ProductCache::STATUS_PENDING)
                             ->whereColumn('updated_at', '>', 'fetched_at')
                             ->count(),
            'pending' => ProductCache::where('ecom_status', ProductCache::STATUS_PENDING)
                             ->where(function ($q) {
                                 $q->whereNull('fetched_at')
                                   ->orWhereColumn('updated_at', '<=', 'fetched_at');
                             })
                             ->count(),
        ];
    }

    private function getEcomToErpStats(): array
    {
        $total = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->count();

        $success = DB::table('sync_mappings')
            ->join('sync_logs', function ($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'product')
                     ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2
                         WHERE sl2.entity_id = sync_mappings.ecom_id
                         AND sl2.entity_type = "product"
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'success')
            ->count();

        $failed = DB::table('sync_mappings')
            ->join('sync_logs', function ($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'product')
                     ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2
                         WHERE sl2.entity_id = sync_mappings.ecom_id
                         AND sl2.entity_type = "product"
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'failed')
            ->count();

        return [
            'total'   => $total,
            'success' => $success,
            'failed'  => $failed,
            'pending' => max(0, $total - $success - $failed),
        ];
    }

    private function getBidirectionalStats(): array
    {
        return [
            'erp_to_ecom' => $this->getErpToEcomStats(),
            'ecom_to_erp' => $this->getEcomToErpStats(),
            'total'       => ProductCache::count(),
        ];
    }

    // ── Pull single product from Ecom → store as pending (ecom_to_erp) ────
    public function pullSingle(string $ecomId)
    {
        try {
            $ecom    = app(\App\Services\Ecom\EcomInterface::class);
            $product = $ecom->getProduct($ecomId);

            if (empty($product)) {
                return redirect()->route('dashboard.products')->with('error', "Product #{$ecomId} not found in " . $this->settings->ecomDisplayName() . '.');
            }

            $updatedAt = $product['updated_at'] ?? null;

            // Skip if already stored and unchanged
            $existing = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $ecomId)
                ->first();

            if ($existing) {
                // Already in Odoo — skip entirely, don't reset to pending
                if ($existing->erp_id && $existing->erp_id !== '0') {
                    return redirect()->route('dashboard.products')->with('info', "Product #{$ecomId} already synced to " . $this->settings->erpDisplayName() . " — no action needed.");
                }
                $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                $prevUpdatedAt = $prevMeta['updated_at'] ?? null;
                if ($prevUpdatedAt && $updatedAt && $prevUpdatedAt === $updatedAt && $existing->ecom_status === 'pending') {
                    return redirect()->route('dashboard.products')->with('info', "Product #{$ecomId} already fetched and pending push.");
                }
            }

            // ── Save full product JSON to storage (mirrors erp_to_ecom cache) ──
            $cacheData = [
                'fetched_at'  => now()->toISOString(),
                'ecom_id'     => (string) $ecomId,
                'ecom_driver' => $this->settings->ecomDriver(),
                'product'     => $product,
            ];
            $filePath = 'ecom_products/' . $ecomId . '.json';
            Storage::disk('local')->put($filePath, json_encode($cacheData, JSON_PRETTY_PRINT));

            // ── ProductCache row so info page can read the file ──
            ProductCache::updateOrCreate(
                ['ecom_product_id' => (string) $ecomId],
                [
                    'name'           => $product['title'] ?? $product['name'] ?? null,
                    'default_code'   => $product['variants'][0]['sku'] ?? null,
                    'ecom_status'    => ProductCache::STATUS_PENDING,
                    'shopify_status' => ProductCache::STATUS_PENDING,
                    'file_path'      => $filePath,
                    'raw_data'       => $cacheData,
                    'fetched_at'     => now(),
                ]
            );

            SyncMapping::updateOrCreate(
                ['entity_type' => 'product', 'ecom_id' => (string) $ecomId, 'ecom_driver' => $this->settings->ecomDriver()],
                [
                    'ecom_handle'         => $product['handle'] ?? null,
                    'last_sync_direction' => 'ecom_to_erp',
                    'ecom_status'         => 'pending',
                    'metadata'            => $product,
                    'last_synced_at'      => now(),
                ]
            );

            // ── SyncLog (fetch record) so info page has a log entry immediately ──
            SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                'entity_type'     => 'product',
                'entity_id'       => (string) $ecomId,
                'action'          => 'fetch',
                'status'          => SyncLog::STATUS_SUCCESS,
                'request_payload' => json_encode($product),
                'synced_at'       => now(),
            ]);

            return redirect()->route('dashboard.products')->with('success', "Product #{$ecomId} fetched. Click Push to " . $this->settings->erpDisplayName() . ' to create it.');
        } catch (\Throwable $e) {
            return redirect()->route('dashboard.products')->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    // ── Push single product from local → ERP (ecom_to_erp) ────────────────
    public function pushSingleToErp(string $ecomId)
    {
        try {
            $mapping = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $ecomId)
                ->whereNotNull('metadata')
                ->first();

            if (!$mapping) {
                return redirect()->route('dashboard.products')->with('error', "No data for product #{$ecomId}. Run Fetch first.");
            }

            // Already in ERP — block re-push entirely
            if ($mapping->erp_id && $mapping->erp_id !== '0') {
                $ecomProduct = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
                $erp         = app(\App\Services\Erp\ErpInterface::class);

                $log = SyncLog::create([
                    'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                    'entity_type'     => 'product',
                    'entity_id'       => (string) $ecomId,
                    'action'          => 'update',
                    'status'          => SyncLog::STATUS_PROCESSING,
                    'request_payload' => json_encode($ecomProduct),
                ]);

                $erp->upsertProduct(array_merge($ecomProduct, ['id' => (int) $mapping->erp_id]));
                $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
                $log->markSuccess(json_encode(['erp_id' => $mapping->erp_id]));

                return redirect()->route('dashboard.products')->with('success',
                    "Product #{$ecomId} updated in " . $this->settings->erpDisplayName() . " (ID: #{$mapping->erp_id})."
                );
            }
        } catch (\Throwable $e) {
            if (isset($log)) $log->markFailed($e->getMessage());
            return redirect()->route('dashboard.products')->with('error', 'Push failed: ' . $e->getMessage());
        }
    }

}