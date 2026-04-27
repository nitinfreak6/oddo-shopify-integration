<?php

namespace App\Jobs\Amazon;

use App\Models\SyncQueueState;
use App\Services\Amazon\AmazonOrderService;
use App\Services\Sync\AmazonOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchAmazonOrdersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 900; // 15 min lock

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(AmazonOrderService $amazonOrders, AmazonOrderSyncService $syncService): void
    {
        $state = SyncQueueState::forType('amazon_orders');

        if ($state->is_running) {
            Log::warning('FetchAmazonOrdersJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            // Default cursor: 30 days ago on first run
            $since = $state->last_odoo_write_date
                ?? now()->subDays(30)->toIso8601String();

            $orders = $amazonOrders->getOrdersUpdatedAfter($since);

            $latestDate = $since;
            $created    = 0;
            $skipped    = 0;

            foreach ($orders as $order) {
                $amazonOrderId = $order['AmazonOrderId'];

                try {
                    // Fetch order items (separate API call per order)
                    usleep(200_000); // 0.2s delay between calls
                    $items = $amazonOrders->getOrderItems($amazonOrderId);

                    $odooId = $syncService->createInOdoo($order, $items);

                    if ($odooId) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    Log::error("FetchAmazonOrdersJob: failed on order {$amazonOrderId}: " . $e->getMessage());
                }

                $purchaseDate = $order['LastUpdateDate'] ?? $order['PurchaseDate'] ?? '';
                if ($purchaseDate > $latestDate) {
                    $latestDate = $purchaseDate;
                }
            }

            $state->markComplete($latestDate);

            Log::info("FetchAmazonOrdersJob: created={$created} skipped={$skipped} total=" . count($orders));
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
