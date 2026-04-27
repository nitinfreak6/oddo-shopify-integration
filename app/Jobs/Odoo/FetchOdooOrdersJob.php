<?php

namespace App\Jobs\Odoo;

use App\Jobs\Shopify\PushCancellationToShopifyJob;
use App\Jobs\Shopify\PushFulfillmentToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Odoo\OdooOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOdooOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    //public int $uniqueFor = 3600; // 1 hr lock

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(OdooOrderService $odooOrders): void
{
    $state = SyncQueueState::forType('orders');

    // 🔥 Self-healing lock (5 min timeout)
    if ($state->is_running && $state->updated_at->gt(now()->subMinutes(5))) {
        Log::warning('FetchOdooOrdersJob: another run still active, skipping.');
        return;
    }

    // If stuck for more than 5 min, auto-unlock
    if ($state->is_running) {
        Log::warning('FetchOdooOrdersJob: stale lock detected, auto-releasing.');
    }

    $state->update(['is_running' => true]);

    try {
        $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

        $orders = $odooOrders->getModifiedSince($writeDate);

        $latestWriteDate = $writeDate;

       foreach ($orders as $order) {

			// ✅ SAVE TO YOUR DATABASE
			Order::updateOrCreate(
				['odoo_id' => $order['id']],
				[
					'order_number' => $order['name'],
					'status' => $order['state'],
					'write_date' => $order['write_date'],
				]
			);

			// existing logic
			if ($order['state'] === 'cancel') {
				PushCancellationToShopifyJob::dispatch((string) $order['id']);
			} else {
				PushFulfillmentToShopifyJob::dispatch($order);
			}
		}

        $state->update([
            'is_running' => false,
            'last_odoo_write_date' => $latestWriteDate,
        ]);

        Log::info("FetchOdooOrdersJob: processed " . count($orders) . " orders");

    } catch (\Throwable $e) {
        // 🔥 ALWAYS release lock on error
        $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
        throw $e;
    }
}
}
