<?php

namespace App\Jobs\Shopify;

use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushProductToShopifyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 300;

    public function __construct(private readonly array $odooTemplate)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'product_' . $this->odooTemplate['id'];
    }

    public function handle(ProductSyncService $syncService): void
    {
        $syncService->syncProduct($this->odooTemplate);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushProductToShopifyJob failed', [
            'odoo_id' => $this->odooTemplate['id'],
            'error'   => $e->getMessage(),
        ]);
    }
}
