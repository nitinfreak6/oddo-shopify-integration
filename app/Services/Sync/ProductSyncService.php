<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\MappingService;
use App\Services\Odoo\OdooProductService;
use App\Services\Shopify\ShopifyProductService;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    public function __construct(
        private readonly OdooProductService  $odooProducts,
        private readonly ShopifyProductService $shopifyProducts,
        private readonly MappingService $mappings,
    ) {}

    /**
     * Sync a single Odoo product template to Shopify.
     * Returns the Shopify product ID.
     */
    public function syncProduct(array $odooTemplate): string
    {
        $odooId    = (string) $odooTemplate['id'];
        $variantIds = $odooTemplate['product_variant_ids'] ?? [];

        // Fetch variants
        $variants = $this->odooProducts->getVariantsForTemplates([$odooTemplate['id']]);

        // Collect attribute value IDs
        $avIds = [];
        foreach ($variants as $v) {
            $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
        }
        $attributeValues = $avIds ? $this->odooProducts->getAttributeValues(array_unique($avIds)) : [];

        // Build payload
        $payload = $this->shopifyProducts->buildPayload($odooTemplate, $variants, $attributeValues);

        // Check if already mapped
        $mapping = $this->mappings->findByOdooId(SyncMapping::TYPE_PRODUCT, $odooId);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_PRODUCT,
            'entity_id'       => $odooId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            if ($mapping) {
                $shopifyProduct = $this->shopifyProducts->update($mapping->shopify_id, $payload);
                $action = 'update';
            } else {
                $shopifyProduct = $this->shopifyProducts->create($payload);
                $action = 'create';
            }

            $shopifyProductId = (string) $shopifyProduct['id'];

            // Upsert main product mapping
            $this->mappings->upsert(SyncMapping::TYPE_PRODUCT, $odooId, $shopifyProductId, [
                'shopify_handle'  => $shopifyProduct['handle'] ?? null,
                'last_synced_at'  => now(),
            ]);

            // Upsert variant mappings (for inventory tracking)
            $this->syncVariantMappings($variants, $shopifyProduct['variants'] ?? []);

            $log->markSuccess(json_encode(['shopify_product_id' => $shopifyProductId]));

            Log::info("Product synced: Odoo #{$odooId} → Shopify #{$shopifyProductId}", ['action' => $action]);

            return $shopifyProductId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    /**
     * Match Odoo variants to Shopify variants by SKU and save mappings.
     */
    private function syncVariantMappings(array $odooVariants, array $shopifyVariants): void
    {
        // Index Shopify variants by SKU
        $shopifyBySku = [];
        foreach ($shopifyVariants as $sv) {
            if (!empty($sv['sku'])) {
                $shopifyBySku[$sv['sku']] = $sv;
            }
        }

        foreach ($odooVariants as $odooVariant) {
            $sku = $odooVariant['default_code'] ?? '';

            if (!$sku || !isset($shopifyBySku[$sku])) {
                continue;
            }

            $sv = $shopifyBySku[$sku];

            $this->mappings->upsert(
                SyncMapping::TYPE_PRODUCT_VARIANT,
                (string) $odooVariant['id'],
                (string) $sv['id'],
                [
                    'shopify_secondary_id' => (string) ($sv['inventory_item_id'] ?? ''),
                    'odoo_reference'       => $sku,
                    'last_synced_at'       => now(),
                ]
            );
        }
    }
}
