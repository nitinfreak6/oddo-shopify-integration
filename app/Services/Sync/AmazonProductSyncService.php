<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Amazon\AmazonListingService;
use App\Services\MappingService;
use App\Services\Odoo\OdooProductService;
use Illuminate\Support\Facades\Log;

class AmazonProductSyncService
{
    // Entity types for Amazon (namespaced to avoid collisions with Shopify mappings)
    const ENTITY_PRODUCT = 'amazon_product';
    const ENTITY_VARIANT = 'amazon_variant';

    public function __construct(
        private readonly OdooProductService  $odooProducts,
        private readonly AmazonListingService $amazonListings,
        private readonly MappingService      $mappings,
    ) {}

    /**
     * Sync a single Odoo product template to Amazon as one or more listings.
     * Each variant becomes a separate listing (identified by SKU).
     *
     * Amazon requires at least one variant with a SKU and either a barcode or ASIN.
     */
    public function syncProduct(array $odooTemplate): array
    {
        $odooId  = (string) $odooTemplate['id'];
        $synced  = [];
        $failed  = [];

        // Fetch variants
        $variants = $this->odooProducts->getVariantsForTemplates([$odooTemplate['id']]);

        if (empty($variants)) {
            Log::warning("Amazon: Odoo product #{$odooId} has no variants, skipping.");
            return ['synced' => [], 'failed' => []];
        }

        foreach ($variants as $variant) {
            $sku = $variant['default_code'] ?? '';

            if (!$sku) {
                Log::warning("Amazon: Odoo variant #{$variant['id']} has no SKU (default_code), skipping.");
                $failed[] = $variant['id'];
                continue;
            }

            $log = SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY, // reuse direction enum
                'entity_type'     => self::ENTITY_VARIANT,
                'entity_id'       => (string) $variant['id'],
                'action'          => $this->mappings->findByOdooId(self::ENTITY_VARIANT, (string) $variant['id'])
                    ? 'update' : 'create',
                'status'          => SyncLog::STATUS_PROCESSING,
            ]);

            try {
                $attributes = $this->amazonListings->buildListingAttributes($odooTemplate, $variant);

                $result = $this->amazonListings->putListing($sku, $attributes);

                $status = $result['status'] ?? 'UNKNOWN';

                // Amazon listing PUT returns 'ACCEPTED' or 'INVALID'
                if ($status === 'INVALID') {
                    $issues = $result['issues'] ?? [];
                    throw new \RuntimeException('Amazon rejected listing: ' . json_encode(array_slice($issues, 0, 3)));
                }

                // Save mapping — use SKU as shopify_id equivalent for Amazon
                $this->mappings->upsert(self::ENTITY_VARIANT, (string) $variant['id'], $sku, [
                    'odoo_reference' => $sku,
                    'last_synced_at' => now(),
                ]);

                // Also save template-level mapping
                $this->mappings->upsert(self::ENTITY_PRODUCT, $odooId, $odooId, [
                    'last_synced_at' => now(),
                ]);

                $log->markSuccess(json_encode(['sku' => $sku, 'status' => $status]));

                Log::info("Amazon listing synced: SKU={$sku}, status={$status}");

                $synced[] = $sku;
            } catch (\Throwable $e) {
                $log->markFailed($e->getMessage());
                Log::error("Amazon listing failed for SKU={$sku}: " . $e->getMessage());
                $failed[] = $sku;
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }
}
