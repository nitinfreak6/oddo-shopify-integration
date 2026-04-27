<?php

namespace App\Services\Amazon;

use Illuminate\Support\Facades\Log;

class AmazonOrderService
{
    private const ORDERS_VERSION = '2013-09-01';

    public function __construct(private readonly AmazonService $amazon) {}

    /**
     * Fetch orders updated after a given ISO8601 datetime.
     * Handles pagination via NextToken.
     */
    public function getOrdersUpdatedAfter(string $isoDatetime): array
    {
        $marketplaceId = $this->amazon->getMarketplaceId();
        $orders        = [];
        $nextToken     = null;

        do {
            $params = $nextToken
                ? ['NextToken' => $nextToken]
                : [
                    'MarketplaceIds'  => $marketplaceId,
                    'LastUpdatedAfter' => $isoDatetime,
                    'OrderStatuses'   => implode(',', ['Unshipped', 'PartiallyShipped', 'Shipped', 'Canceled']),
                ];

            $response  = $this->amazon->get("/orders/{$this->ORDERS_VERSION}/orders", $params);
            $pageOrders = $response['payload']['Orders'] ?? [];
            $nextToken  = $response['payload']['NextToken'] ?? null;

            $orders = array_merge($orders, $pageOrders);

            // Respect Amazon's 1 req/sec rate limit for orders
            if ($nextToken) {
                usleep(1_100_000);
            }
        } while ($nextToken);

        Log::info("Amazon: fetched " . count($orders) . " orders updated after {$isoDatetime}");

        return $orders;
    }

    /**
     * Get order line items (order items) for a given AmazonOrderId.
     */
    public function getOrderItems(string $amazonOrderId): array
    {
        $response = $this->amazon->get("/orders/{$this->ORDERS_VERSION}/orders/{$amazonOrderId}/orderItems");

        return $response['payload']['OrderItems'] ?? [];
    }

    /**
     * Get full order details by Amazon order ID.
     */
    public function getOrder(string $amazonOrderId): ?array
    {
        try {
            $response = $this->amazon->get("/orders/{$this->ORDERS_VERSION}/orders/{$amazonOrderId}");

            return $response['payload'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Submit a shipment confirmation (FBM only).
     * Tells Amazon we fulfilled the order ourselves.
     */
    public function confirmShipment(string $amazonOrderId, array $shipmentData): array
    {
        return $this->amazon->post(
            "/orders/{$this->ORDERS_VERSION}/orders/{$amazonOrderId}/shipmentConfirmation",
            $shipmentData
        );
    }

    /**
     * Build Odoo sale.order data from an Amazon order + its items.
     */
    public function buildOdooOrderData(array $amazonOrder, array $orderItems): array
    {
        $address = $amazonOrder['ShippingAddress'] ?? [];

        return [
            'amazon_order'   => $amazonOrder,
            'amazon_items'   => $orderItems,
            'shipping_address' => $address,
        ];
    }
}
