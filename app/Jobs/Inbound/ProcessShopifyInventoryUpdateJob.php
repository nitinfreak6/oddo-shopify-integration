<?php

namespace App\Jobs\Inbound;

use App\Models\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessShopifyInventoryUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $webhookLogId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $webhookLog = WebhookLog::findOrFail($this->webhookLogId);

        if ($webhookLog->processed) {
            return;
        }

        // Odoo is the source of truth for inventory.
        // We log the event but do NOT write back unless explicitly configured.
        if (!config('shopify.inventory_writeback')) {
            Log::debug('Shopify inventory webhook received but writeback is disabled — Odoo is source of truth.');
            $webhookLog->markProcessed();
            return;
        }

        // TODO: Implement Odoo inventory writeback if SHOPIFY_INVENTORY_WRITEBACK=true
        // This would involve updating stock.quant in Odoo via XML-RPC.
        Log::warning('Shopify inventory writeback is enabled but not yet implemented.');
        $webhookLog->markProcessed();
    }
}
