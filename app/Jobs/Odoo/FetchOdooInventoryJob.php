<?php

namespace App\Jobs\Odoo;

use App\Jobs\Amazon\PushInventoryToAmazonJob;
use App\Jobs\Shopify\PushInventoryToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Odoo\OdooInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOdooInventoryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300; // 5 min lock

    public function __construct(private readonly ?int $locationId = null)
    {
        $this->onQueue('sync');
    }

    public function handle(OdooInventoryService $odooInventory): void
    {
        $state = SyncQueueState::forType('inventory');

        if ($state->is_running) {
            Log::warning('FetchOdooInventoryJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

            $quants = $odooInventory->getModifiedSince($writeDate, $this->locationId);

            $latestWriteDate = $writeDate;

            foreach ($quants as $quant) {
                PushInventoryToShopifyJob::dispatch($quant);
                PushInventoryToAmazonJob::dispatch($quant);

                if ($quant['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $quant['write_date'];
                }
            }

            $state->markComplete($latestWriteDate);

            Log::info("FetchOdooInventoryJob: dispatched " . count($quants) . " inventory jobs");
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
