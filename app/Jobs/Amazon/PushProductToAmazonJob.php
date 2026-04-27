<?php

namespace App\Jobs\Amazon;

use App\Exceptions\AmazonApiException;
use App\Services\Sync\AmazonProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushProductToAmazonJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [120, 600, 1800]; // Amazon throttles harder — longer backoff
    public int $timeout = 120;

    public function __construct(private readonly array $odooTemplate)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'amazon_product_' . $this->odooTemplate['id'];
    }

    public function handle(AmazonProductSyncService $syncService): void
    {
        $result = $syncService->syncProduct($this->odooTemplate);

        if (!empty($result['failed'])) {
            Log::warning('PushProductToAmazonJob: some SKUs failed', [
                'odoo_id' => $this->odooTemplate['id'],
                'failed'  => $result['failed'],
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushProductToAmazonJob permanently failed', [
            'odoo_id' => $this->odooTemplate['id'],
            'error'   => $e->getMessage(),
        ]);
    }

    /**
     * Determine retry delay — respect Amazon Retry-After header when throttled.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
