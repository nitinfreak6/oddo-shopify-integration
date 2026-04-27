<?php

namespace App\Jobs\Shopify;

use App\Services\Sync\InventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInventoryToShopifyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 60;

    public function __construct(private readonly array $quant)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        $productId  = is_array($this->quant['product_id']) ? $this->quant['product_id'][0] : $this->quant['product_id'];
        $locationId = is_array($this->quant['location_id']) ? $this->quant['location_id'][0] : $this->quant['location_id'];

        return "inventory_{$productId}_{$locationId}";
    }

    public function handle(InventorySyncService $syncService): void
    {
        $syncService->syncQuant($this->quant);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushInventoryToShopifyJob failed', [
            'quant'  => $this->quant,
            'error'  => $e->getMessage(),
        ]);
    }
}
