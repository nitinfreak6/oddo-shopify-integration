<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\MappingService;
use App\Services\Odoo\OdooInventoryService;
use App\Services\Shopify\ShopifyInventoryService;
use Illuminate\Support\Facades\Log;

class InventorySyncService
{
    public function __construct(
        private readonly OdooInventoryService    $odooInventory,
        private readonly ShopifyInventoryService $shopifyInventory,
        private readonly MappingService          $mappings,
    ) {}

    /**
     * Sync a stock quant record to Shopify inventory.
     */
    public function syncQuant(array $quant): bool
    {
        $odooProductId = is_array($quant['product_id']) ? $quant['product_id'][0] : $quant['product_id'];
        $odooLocationId = is_array($quant['location_id']) ? $quant['location_id'][0] : $quant['location_id'];

        // Resolve Shopify inventory_item_id via variant mapping
        $variantMapping = $this->mappings->findByOdooId(
            SyncMapping::TYPE_PRODUCT_VARIANT,
            (string) $odooProductId
        );

        if (!$variantMapping || !$variantMapping->shopify_secondary_id) {
            $log = SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
                'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
                'entity_id'       => (string) $odooProductId,
                'action'          => 'update',
                'status'          => SyncLog::STATUS_SKIPPED,
                'request_payload' => json_encode([
                    'odoo_product_id' => $odooProductId,
                    'odoo_location_id' => $odooLocationId,
                    'reason'           => 'missing_variant_mapping',
                ]),
            ]);

            $log->markSkipped('No variant mapping for this Odoo product variant.', [
                'odoo_product_id'  => (string) $odooProductId,
                'odoo_location_id' => (string) $odooLocationId,
            ]);

            Log::debug("No variant mapping for Odoo product #{$odooProductId}, skipping inventory sync");
            return false;
        }

        // Resolve Shopify location ID
        $locationMap      = config('odoo.location_map', []);
        $shopifyLocationId = $locationMap[(string) $odooLocationId] ?? null;

        if (!$shopifyLocationId) {
            $log = SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
                'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
                'entity_id'       => (string) $odooProductId,
                'action'          => 'update',
                'status'          => SyncLog::STATUS_SKIPPED,
                'request_payload' => json_encode([
                    'odoo_product_id' => $odooProductId,
                    'odoo_location_id' => $odooLocationId,
                    'shopify_location_id' => null,
                    'reason' => 'missing_shopify_location_map',
                    'location_map_keys' => array_keys($locationMap),
                ]),
            ]);

            $log->markSkipped('No Shopify location mapped for this Odoo internal location.', [
                'odoo_product_id' => (string) $odooProductId,
                'odoo_location_id' => (string) $odooLocationId,
                'location_map_keys' => array_keys($locationMap),
            ]);

            Log::debug("No Shopify location mapped for Odoo location #{$odooLocationId}");
            return false;
        }

        $available = $this->odooInventory->availableQty($quant);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
            'entity_id'       => (string) $odooProductId,
            'action'          => 'update',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode([
                'inventory_item_id' => $variantMapping->shopify_secondary_id,
                'location_id'       => $shopifyLocationId,
                'odoo_location_id'  => (string) $odooLocationId,
                'available'         => $available,
            ]),
        ]);

        try {
            $this->shopifyInventory->setLevel(
                $variantMapping->shopify_secondary_id,
                $shopifyLocationId,
                $available
            );

            $log->markSuccess("Set to {$available}");

            Log::info("Inventory synced: Odoo product #{$odooProductId} qty={$available}");

            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}
