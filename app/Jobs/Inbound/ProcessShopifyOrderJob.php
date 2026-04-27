<?php

namespace App\Jobs\Inbound;

use App\Models\WebhookLog;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessShopifyOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 60;

    public function __construct(private readonly int $webhookLogId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(OrderSyncService $orderSync): void
    {
        $webhookLog = WebhookLog::findOrFail($this->webhookLogId);

        if ($webhookLog->processed) {
            return;
        }

        try {
            $shopifyOrder = $webhookLog->getDecodedPayload();

            $orderSync->createInOdoo($shopifyOrder);

            $webhookLog->markProcessed();
        } catch (\Throwable $e) {
            $webhookLog->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessShopifyOrderJob failed', [
            'webhook_log_id' => $this->webhookLogId,
            'error'          => $e->getMessage(),
        ]);
    }
}
