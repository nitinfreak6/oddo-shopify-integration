<?php

namespace App\Jobs\Odoo;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Odoo\OdooProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOdooProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600; // 10 min lock

    public function __construct(private readonly bool $fullSync = false)
    {
        $this->onQueue('sync');
    }

    public function handle(OdooProductService $odooProducts): void
    {
        $state = SyncQueueState::forType('products');

        if ($state->is_running) {
            Log::warning('FetchOdooProductsJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            $writeDate   = ($this->fullSync || !$state->last_odoo_write_date)
                ? '2000-01-01 00:00:00'
                : $state->last_odoo_write_date;

            $latestWriteDate = $writeDate;
            $offset          = 0;

            do {
                $products = $this->fullSync
                    ? $odooProducts->getAllActive($offset, 100)
                    : $odooProducts->getModifiedSince($writeDate);

                foreach ($products as $product) {
                    PushProductToShopifyJob::dispatch($product);
                    PushProductToAmazonJob::dispatch($product);

                    if ($product['write_date'] > $latestWriteDate) {
                        $latestWriteDate = $product['write_date'];
                    }
                }

                $offset += count($products);
            } while ($this->fullSync && count($products) === 100);

            $state->markComplete($latestWriteDate);

            Log::info("FetchOdooProductsJob: dispatched jobs for products up to {$latestWriteDate}");
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
