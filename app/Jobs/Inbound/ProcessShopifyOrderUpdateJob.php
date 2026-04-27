<?php

namespace App\Jobs\Inbound;

use App\Models\WebhookLog;
use App\Services\MappingService;
use App\Services\Odoo\OdooOrderService;
use App\Models\SyncMapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessShopifyOrderUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $webhookLogId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(MappingService $mappings, OdooOrderService $odooOrders): void
    {
        $webhookLog = WebhookLog::findOrFail($this->webhookLogId);

        if ($webhookLog->processed) {
            return;
        }

        try {
            $shopifyOrder   = $webhookLog->getDecodedPayload();
            $shopifyOrderId = (string) $shopifyOrder['id'];

            $orderMapping = $mappings->findByShopifyId(SyncMapping::TYPE_ORDER, $shopifyOrderId);

            if (!$orderMapping) {
                // Order not in Odoo yet — skip update
                $webhookLog->markProcessed();
                return;
            }

            // Handle cancellation from Shopify → Odoo
            if (in_array($shopifyOrder['financial_status'] ?? '', ['refunded', 'voided'])) {
                $odooOrders->cancelOrder((int) $orderMapping->odoo_id);
                Log::info("Shopify order #{$shopifyOrderId} refunded/voided — cancelled in Odoo #{$orderMapping->odoo_id}");
            }

            $webhookLog->markProcessed();
        } catch (\Throwable $e) {
            $webhookLog->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessShopifyOrderUpdateJob failed', [
            'webhook_log_id' => $this->webhookLogId,
            'error'          => $e->getMessage(),
        ]);
    }
}
