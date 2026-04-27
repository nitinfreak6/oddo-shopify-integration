<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Amazon\AmazonOrderService;
use App\Services\MappingService;
use App\Services\Odoo\OdooCustomerService;
use App\Services\Odoo\OdooOrderService;
use Illuminate\Support\Facades\Log;

class AmazonOrderSyncService
{
    const ENTITY_ORDER = 'amazon_order';

    public function __construct(
        private readonly AmazonOrderService  $amazonOrders,
        private readonly OdooOrderService    $odooOrders,
        private readonly OdooCustomerService $odooCustomers,
        private readonly MappingService      $mappings,
    ) {}

    /**
     * Create an Odoo sale.order from an Amazon order.
     */
    public function createInOdoo(array $amazonOrder, array $orderItems): int
    {
        $amazonOrderId = $amazonOrder['AmazonOrderId'];

        // Idempotency
        if ($this->mappings->findByShopifyId(self::ENTITY_ORDER, $amazonOrderId)) {
            Log::info("Amazon order {$amazonOrderId} already in Odoo, skipping.");
            return 0;
        }

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_SHOPIFY_TO_ODOO,
            'entity_type'     => self::ENTITY_ORDER,
            'entity_id'       => $amazonOrderId,
            'action'          => 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode(['order' => $amazonOrder, 'items' => $orderItems]),
        ]);

        try {
            $partnerId  = $this->resolveOrCreatePartner($amazonOrder);
            $orderLines = $this->buildOrderLines($orderItems);

            // Add shipping if present
            $shippingPrice = (float) ($amazonOrder['OrderTotal']['Amount'] ?? 0)
                           - array_sum(array_map(fn($i) => (float) ($i['ItemPrice']['Amount'] ?? 0), $orderItems));

            if ($shippingPrice > 0) {
                $orderLines[] = [0, 0, [
                    'name'            => 'Amazon Shipping',
                    'product_uom_qty' => 1,
                    'price_unit'      => round($shippingPrice, 2),
                ]];
            }

            $orderData = [
                'client_order_ref' => $amazonOrderId,
                'origin'           => 'Amazon #' . $amazonOrderId,
                'partner_id'       => $partnerId,
                'partner_invoice_id'  => $partnerId,
                'partner_shipping_id' => $partnerId,
                'order_line'       => $orderLines,
                'note'             => 'Amazon channel: ' . ($amazonOrder['SalesChannel'] ?? ''),
                'date_order'       => date('Y-m-d H:i:s', strtotime($amazonOrder['PurchaseDate'])),
            ];

            $odooOrderId = $this->odooOrders->createFromShopify($orderData); // reuse same method

            // Auto-confirm if payment confirmed
            if (in_array($amazonOrder['OrderStatus'] ?? '', ['Unshipped', 'PartiallyShipped', 'Shipped'])) {
                $this->odooOrders->confirmOrder($odooOrderId);
            }

            $this->mappings->upsert(self::ENTITY_ORDER, (string) $odooOrderId, $amazonOrderId, [
                'shopify_handle' => $amazonOrderId,
                'last_synced_at' => now(),
            ]);

            $log->markSuccess(json_encode(['odoo_order_id' => $odooOrderId]));

            Log::info("Amazon order {$amazonOrderId} → Odoo #{$odooOrderId}");

            return $odooOrderId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    private function resolveOrCreatePartner(array $amazonOrder): int
    {
        $address = $amazonOrder['ShippingAddress'] ?? [];
        $email   = $amazonOrder['BuyerInfo']['BuyerEmail'] ?? '';

        // Try lookup by email first
        if ($email && !str_contains($email, 'marketplace.amazon')) {
            $existing = $this->odooCustomers->findByEmail($email);
            if ($existing) {
                return $existing['id'];
            }
        }

        $countryId = null;
        $stateId   = null;

        if (!empty($address['CountryCode'])) {
            $countryId = $this->odooCustomers->resolveCountry($address['CountryCode']);

            if ($countryId && !empty($address['StateOrRegion'])) {
                $stateId = $this->odooCustomers->resolveState($countryId, $address['StateOrRegion']);
            }
        }

        $partnerData = [
            'name'          => $address['Name'] ?? ($amazonOrder['BuyerInfo']['BuyerName'] ?? 'Amazon Customer'),
            'email'         => (!empty($email) && !str_contains($email, 'marketplace.amazon')) ? $email : '',
            'street'        => $address['AddressLine1'] ?? '',
            'street2'       => $address['AddressLine2'] ?? '',
            'city'          => $address['City'] ?? '',
            'zip'           => $address['PostalCode'] ?? '',
            'phone'         => $address['Phone'] ?? '',
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

    private function buildOrderLines(array $orderItems): array
    {
        $lines = [];

        foreach ($orderItems as $item) {
            $sku = $item['SellerSKU'] ?? '';

            // Try to resolve Odoo product from Amazon variant mapping
            $variantMapping = $sku
                ? $this->mappings->findByShopifyId(AmazonProductSyncService::ENTITY_VARIANT, $sku)
                : null;

            $price = (float) ($item['ItemPrice']['Amount'] ?? 0);
            $qty   = (int) ($item['QuantityOrdered'] ?? 1);

            $line = [0, 0, [
                'name'            => $item['Title'] ?? ($sku ?: 'Amazon Product'),
                'product_uom_qty' => $qty,
                'price_unit'      => $qty > 0 ? round($price / $qty, 4) : $price,
            ]];

            if ($variantMapping) {
                $line[2]['product_id'] = (int) $variantMapping->odoo_id;
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
