<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\SyncEntityState;
use App\Services\Erp\ErpInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push one ERP sales order to e-commerce using field-config-driven mapping.
 */
class PushOrderToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $erpOrderId)
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, OrderSyncService $orderSync): void
    {
        $mapping = SyncMapping::whereIn('entity_type', ['sales_order', 'order'])
            ->where('erp_id', (string) $this->erpOrderId)
            ->first();

        if ($mapping && $mapping->ecom_id && !SyncEntityState::needsPush($mapping)) {
            Log::debug("PushOrderToEcomJob: ERP order #{$this->erpOrderId} already synced to {$mapping->ecom_driver} #{$mapping->ecom_id} — skipping.");
            return;
        }

        $erpOrder = $erp->getOrder($this->erpOrderId);

        if (!$erpOrder) {
            Log::warning("PushOrderToEcomJob: ERP order #{$this->erpOrderId} not found in {$erp->driverName()}");
            return;
        }

        $orderSync->syncErpOrderToEcom($erpOrder);
    }
}
