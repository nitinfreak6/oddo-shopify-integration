<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\MappingService;
use App\Services\Odoo\OdooOrderService;
use App\Services\Shopify\ShopifyFulfillmentService;
use App\Services\Shopify\ShopifyOrderService;
use Illuminate\Support\Facades\Log;

class FulfillmentSyncService
{
    public function __construct(
        private readonly OdooOrderService         $odooOrders,
        private readonly ShopifyFulfillmentService $shopifyFulfillments,
        private readonly ShopifyOrderService       $shopifyOrders,
        private readonly MappingService            $mappings,
    ) {}

    /**
     * Push fulfillment from Odoo delivery to Shopify.
     */
    public function syncFulfillment(array $odooOrder): bool
    {
        $odooOrderId = (string) $odooOrder['id'];

        $orderMapping = $this->mappings->findByOdooId(SyncMapping::TYPE_ORDER, $odooOrderId);

        if (!$orderMapping) {
            Log::debug("No Shopify mapping for Odoo order #{$odooOrderId}, skipping fulfillment.");
            return false;
        }

        $shopifyOrderId = $orderMapping->shopify_id;

        // Get pickings
        $pickingIds = $odooOrder['picking_ids'] ?? [];
        if (empty($pickingIds)) {
            return false;
        }

        $pickings = $this->odooOrders->getPickings($pickingIds);
        $donePicking = null;

        foreach ($pickings as $picking) {
            if ($picking['state'] === 'done') {
                $donePicking = $picking;
                break;
            }
        }

        if (!$donePicking) {
            return false;
        }

        $moveIds = $donePicking['move_ids'] ?? [];
        $moves   = $moveIds ? $this->odooOrders->getMoves($moveIds) : [];

        // Get Shopify order to access line items
        $shopifyOrder = $this->shopifyOrders->get($shopifyOrderId);
        if (!$shopifyOrder) {
            return false;
        }

        // Enrich line items with odoo product ids via mapping
        $shopifyLineItems = array_map(function ($item) {
            $variantId = (string) ($item['variant_id'] ?? '');
            $variantMapping = $variantId
                ? $this->mappings->findByShopifyId(SyncMapping::TYPE_PRODUCT_VARIANT, $variantId)
                : null;

            $item['_odoo_product_id'] = $variantMapping ? (int) $variantMapping->odoo_id : null;
            return $item;
        }, $shopifyOrder['line_items'] ?? []);

        $payload = $this->shopifyFulfillments->buildPayload($donePicking, $moves, $shopifyLineItems);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => 'fulfillment',
            'entity_id'       => $odooOrderId,
            'action'          => 'fulfill',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            $fulfillment = $this->shopifyFulfillments->create($shopifyOrderId, $payload);
            $log->markSuccess(json_encode(['fulfillment_id' => $fulfillment['id'] ?? null]));

            Log::info("Fulfillment synced: Odoo order #{$odooOrderId} → Shopify #{$shopifyOrderId}");

            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Push cancellation from Odoo to Shopify.
     */
    public function syncCancellation(string $odooOrderId): bool
    {
        $orderMapping = $this->mappings->findByOdooId(SyncMapping::TYPE_ORDER, $odooOrderId);

        if (!$orderMapping) {
            return false;
        }

        $log = SyncLog::create([
            'direction'   => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type' => SyncMapping::TYPE_ORDER,
            'entity_id'   => $odooOrderId,
            'action'      => 'cancel',
            'status'      => SyncLog::STATUS_PROCESSING,
        ]);

        try {
            $this->shopifyOrders->cancel($orderMapping->shopify_id);
            $log->markSuccess('Cancelled');

            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}
