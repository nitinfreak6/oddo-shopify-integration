<?php

namespace App\Console\Commands;

use App\Jobs\Amazon\PushInventoryToAmazonJob;
use App\Models\SyncMapping;
use App\Services\Amazon\AmazonInventoryService;
use App\Services\MappingService;
use App\Services\Odoo\OdooInventoryService;
use App\Services\Sync\AmazonProductSyncService;
use Illuminate\Console\Command;

class SyncAmazonInventory extends Command
{
    protected $signature = 'sync:amazon-inventory
                            {--sku= : Push inventory for a specific SKU only}
                            {--dry-run : Show what would be pushed}';

    protected $description = 'Sync Odoo inventory → Amazon listings (FBM only)';

    public function handle(
        MappingService $mappings,
        OdooInventoryService $odooInventory,
        AmazonInventoryService $amazonInventory
    ): int {
        if (config('amazon.fulfillment_channel') === 'FBA') {
            $this->warn('AMAZON_FULFILLMENT_CHANNEL=FBA — Amazon manages inventory. Nothing to sync.');
            return self::SUCCESS;
        }

        $filterSku = $this->option('sku');
        $dryRun    = $this->option('dry-run');

        $this->info('Amazon inventory sync...' . ($dryRun ? ' [DRY RUN]' : ''));

        // Get all Amazon variant mappings
        $query = SyncMapping::where('entity_type', AmazonProductSyncService::ENTITY_VARIANT);

        if ($filterSku) {
            $query->where('odoo_reference', $filterSku);
        }

        $variantMappings = $query->get();

        if ($variantMappings->isEmpty()) {
            $this->warn('No Amazon variant mappings found. Run sync:amazon-products first.');
            return self::SUCCESS;
        }

        $odooProductIds = $variantMappings->pluck('odoo_id')->toArray();

        // Fetch current quants for these products
        $quants = $odooInventory->getAllForProducts($odooProductIds);

        if (empty($quants)) {
            $this->info('No stock quants found for mapped products.');
            return self::SUCCESS;
        }

        foreach ($quants as $quant) {
            $productId = is_array($quant['product_id']) ? $quant['product_id'][0] : $quant['product_id'];
            $qty       = $odooInventory->availableQty($quant);

            $variantMapping = $variantMappings->firstWhere('odoo_id', (string) $productId);

            if (!$variantMapping) {
                continue;
            }

            $sku = $variantMapping->odoo_reference;

            if ($dryRun) {
                $this->line("  Would set SKU={$sku} qty={$qty}");
                continue;
            }

            try {
                $amazonInventory->updateQuantity($sku, $qty);
                $this->line("  ✔ SKU={$sku} → qty={$qty}");
            } catch (\Throwable $e) {
                $this->error("  ✘ SKU={$sku}: " . $e->getMessage());
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
