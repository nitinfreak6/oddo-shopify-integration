<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Services\Sync\InventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInventoryToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $quant)
    {
        $this->onQueue('sync');
    }

    public function handle(InventorySyncService $inventorySync): void
    {
        $erpProductId = (string) ($this->quant['product_id'][0] ?? '');

        if (!$erpProductId) {
            Log::warning('PushInventoryToEcomJob: quant has no product_id, skipping.');
            return;
        }

        $inventoryMapping = SyncMapping::where('entity_type', 'inventory')
            ->where('erp_id', $erpProductId)
            ->first();

        $inventorySync->syncInventoryToEcom($this->quant, $inventoryMapping);
    }
}
