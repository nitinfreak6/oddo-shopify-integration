<?php

namespace App\Jobs\Shopify;

use App\Services\Sync\FulfillmentSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushCancellationToShopifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly string $odooOrderId)
    {
        $this->onQueue('sync');
    }

    public function handle(FulfillmentSyncService $syncService): void
    {
        $syncService->syncCancellation($this->odooOrderId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushCancellationToShopifyJob failed', [
            'odoo_order_id' => $this->odooOrderId,
            'error'         => $e->getMessage(),
        ]);
    }
}
