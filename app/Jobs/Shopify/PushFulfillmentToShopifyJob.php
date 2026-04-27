<?php

namespace App\Jobs\Shopify;

use App\Services\Sync\FulfillmentSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushFulfillmentToShopifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 120;

    public function __construct(private readonly array $odooOrder)
    {
        $this->onQueue('sync');
    }

    public function handle(FulfillmentSyncService $syncService): void
    {
        $syncService->syncFulfillment($this->odooOrder);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushFulfillmentToShopifyJob failed', [
            'odoo_order_id' => $this->odooOrder['id'],
            'error'         => $e->getMessage(),
        ]);
    }
}
