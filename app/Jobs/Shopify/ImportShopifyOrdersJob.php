<?php

namespace App\Jobs\Shopify;

use App\Services\Shopify\ShopifyOrderService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportShopifyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];
    public $timeout = 120;

    private $limit;
    private $dryRun;

    public function __construct(int $limit, bool $dryRun = false)
    {
        $this->limit = $limit;
        $this->dryRun = $dryRun;
        $this->onQueue('sync');
    }

    public function handle(ShopifyOrderService $shopifyOrders, OrderSyncService $orderSync): void
    {
        try {
            // Fetch orders from Shopify
            $params = [
                'limit' => $this->limit,
                // Use all statuses; "open" often returns 0 for stores with archived/closed orders.
                'status' => 'any',
            ];

            Log::info('ImportShopifyOrdersJob request params', $params);

            $response = $shopifyOrders->list($params);
			//echo '<pre>'; print_r($response); echo '</pre>'; die;
            $orders = $response['orders'] ?? [];

            Log::info("ImportShopifyOrdersJob: Found " . count($orders) . " orders");

            $imported = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($orders as $order) {
                $orderId = $order['id'];
                $orderName = $order['name'] ?? "#{$orderId}";

                try {
                    // Check if already imported
                    if ($this->isAlreadyImported($orderId)) {
                        Log::info("Order #{$orderId} already imported, skipping");
                        $skipped++;
                        continue;
                    }

                    if ($this->dryRun) {
                        Log::info("[DRY RUN] Would import order #{$orderId}");
                        $imported++;
                        continue;
                    }

                    // Import order
                    $odooOrderId = $orderSync->createInOdoo($order);
                    
                    if ($odooOrderId) {
                        Log::info("Imported: Shopify #{$orderId} → Odoo #{$odooOrderId}");
                        $imported++;
                    } else {
                        Log::warning("Skipped: Order #{$orderId}");
                        $skipped++;
                    }

                } catch (\Exception $e) {
                    Log::error("Failed to import order #{$orderId}: " . $e->getMessage());
                    $failed++;
                }
            }

            Log::info("ImportShopifyOrdersJob completed: imported={$imported}, skipped={$skipped}, failed={$failed}");

        } catch (\Exception $e) {
            Log::error("ImportShopifyOrdersJob failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function isAlreadyImported(string $shopifyOrderId): bool
    {
        return \App\Models\SyncMapping::where('entity_type', 'order')
            ->where('shopify_id', $shopifyOrderId)
            ->exists();
    }
}
