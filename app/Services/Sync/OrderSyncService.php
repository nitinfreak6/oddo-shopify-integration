<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\MappingService;
use App\Services\Odoo\OdooCustomerService;
use App\Services\Odoo\OdooOrderService;
use Illuminate\Support\Facades\Log;

class OrderSyncService
{
    private $odooOrders;
    private $odooCustomers;
    private $mappings;

    public function __construct(
        OdooOrderService    $odooOrders,
        OdooCustomerService $odooCustomers,
        MappingService      $mappings
    ) {
        $this->odooOrders = $odooOrders;
        $this->odooCustomers = $odooCustomers;
        $this->mappings = $mappings;
    }

    /**
     * Create an Odoo sale.order from a Shopify order payload.
     */
    public function createInOdoo(array $shopifyOrder): int
    {
        $shopifyOrderId = (string) $shopifyOrder['id'];

        // Idempotency: skip if already synced
        if ($this->mappings->findByShopifyId(SyncMapping::TYPE_ORDER, $shopifyOrderId)) {
            Log::info("Shopify order #{$shopifyOrderId} already in Odoo, skipping.");
            return 0;
        }

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_SHOPIFY_TO_ODOO,
            'entity_type'     => SyncMapping::TYPE_ORDER,
            'entity_id'       => $shopifyOrderId,
            'action'          => 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($shopifyOrder),
        ]);

        try {
            // Resolve or create partner
            $partnerId = $this->resolveOrCreatePartner($shopifyOrder);

            // Build order lines
            $orderLines = $this->buildOrderLines($shopifyOrder['line_items'] ?? []);

            // Add shipping line if present
            if (!empty($shopifyOrder['shipping_lines'][0]['price'])) {
                $orderLines[] = $this->buildShippingLine($shopifyOrder['shipping_lines'][0]);
            }

            $orderData = [
                'client_order_ref' => $shopifyOrder['name'],         // e.g. #1001
                'origin'           => 'Shopify #' . $shopifyOrder['name'],
                'partner_id'       => $partnerId,
                'partner_invoice_id' => $partnerId,
                'partner_shipping_id' => $partnerId,
                'order_line'       => $orderLines,
                'note'             => $shopifyOrder['note'] ?? '',
                'date_order'       => date('Y-m-d H:i:s', strtotime($shopifyOrder['created_at'])),
            ];

            $odooOrderId = $this->odooOrders->createFromShopify($orderData);

            // Confirm if paid
            if (in_array($shopifyOrder['financial_status'] ?? '', ['paid', 'partially_paid'])) {
                $this->odooOrders->confirmOrder($odooOrderId);
            }

            // Save mapping
            $this->mappings->upsert(
                SyncMapping::TYPE_ORDER,
                (string) $odooOrderId,
                $shopifyOrderId,
                [
                    'shopify_handle' => $shopifyOrder['name'],
                    'last_synced_at' => now(),
                ]
            );

            $log->markSuccess(json_encode(['odoo_order_id' => $odooOrderId]));

            Log::info("Shopify order #{$shopifyOrderId} → Odoo #{$odooOrderId}");

            return $odooOrderId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    private function resolveOrCreatePartner(array $shopifyOrder): int
    {
        $email = $shopifyOrder['email'] ?? '';

        if ($email) {
            $existing = $this->odooCustomers->findByEmail($email);
            if ($existing) {
                return $existing['id'];
            }
        }

        // Create from billing address
        $billing = $shopifyOrder['billing_address'] ?? $shopifyOrder['shipping_address'] ?? [];

        $countryId = null;
        $stateId   = null;

        if (!empty($billing['country_code'])) {
            $countryId = $this->odooCustomers->resolveCountry($billing['country_code']);

            if ($countryId && !empty($billing['province_code'])) {
                $stateId = $this->odooCustomers->resolveState($countryId, $billing['province_code']);
            }
        }

        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        $partnerData = [
            'name'          => $name ?: ($email ?: 'Shopify Customer'),
            'email'         => $email,
            'phone'         => $billing['phone'] ?? '',
            'street'        => $billing['address1'] ?? '',
            'street2'       => $billing['address2'] ?? '',
            'city'          => $billing['city'] ?? '',
            'zip'           => $billing['zip'] ?? '',
            'customer_rank' => 1,
        ];

        if ($countryId) {
            $partnerData['country_id'] = $countryId;
        }
        if ($stateId) {
            $partnerData['state_id'] = $stateId;
        }

        return $this->odooCustomers->create($partnerData);
    }

    private function buildOrderLines(array $lineItems): array
    {
        $lines = [];

        foreach ($lineItems as $item) {
            $variantId = (string) ($item['variant_id'] ?? '');

            // Try to find Odoo product from mapping
            $variantMapping = $variantId
                ? $this->mappings->findByShopifyId(SyncMapping::TYPE_PRODUCT_VARIANT, $variantId)
                : null;

            $line = [
                0, 0, [
                    'name'             => $item['title'] . (!empty($item['variant_title']) ? ' - ' . $item['variant_title'] : ''),
                    'product_uom_qty'  => (float) $item['quantity'],
                    'price_unit'       => (float) $item['price'],
                ]
            ];

            if ($variantMapping) {
                $line[2]['product_id'] = (int) $variantMapping->odoo_id;
            } else {
                // Create a placeholder product for missing mapping
                // This allows order to be imported without failing
                $line[2]['name'] = $line[2]['name'] . ' [MISSING PRODUCT]';
                // Don't set product_id - Odoo will handle as service/product
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function buildShippingLine(array $shippingLine): array
    {
        return [
            0, 0, [
                'name'            => 'Shipping: ' . ($shippingLine['title'] ?? 'Standard'),
                'product_uom_qty' => 1,
                'price_unit'      => (float) $shippingLine['price'],
            ]
        ];
    }
}
