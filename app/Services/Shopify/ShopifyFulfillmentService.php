<?php

namespace App\Services\Shopify;

class ShopifyFulfillmentService
{
    public function __construct(private readonly ShopifyService $shopify) {}

    /**
     * Create a fulfillment for an order.
     */
    public function create(string $orderId, array $fulfillmentData): array
    {
        $response = $this->shopify->post(
            "orders/{$orderId}/fulfillments.json",
            ['fulfillment' => $fulfillmentData]
        );

        return $response['fulfillment'] ?? [];
    }

    /**
     * Get fulfillments for an order.
     */
    public function getForOrder(string $orderId): array
    {
        return $this->shopify->get("orders/{$orderId}/fulfillments.json")['fulfillments'] ?? [];
    }

    /**
     * Build fulfillment payload from Odoo picking data.
     */
    public function buildPayload(array $picking, array $moves, array $shopifyLineItems): array
    {
        $lineItems = [];

        foreach ($moves as $move) {
            $productOdooId = is_array($move['product_id']) ? $move['product_id'][0] : $move['product_id'];

            foreach ($shopifyLineItems as $lineItem) {
                if (isset($lineItem['_odoo_product_id']) && $lineItem['_odoo_product_id'] == $productOdooId) {
                    $lineItems[] = [
                        'id'       => $lineItem['id'],
                        'quantity' => (int) $move['quantity_done'],
                    ];
                    break;
                }
            }
        }

        $payload = [
            'line_items'       => $lineItems,
            'notify_customer'  => true,
        ];

        if (!empty($picking['carrier_tracking_ref'])) {
            $payload['tracking_number']  = $picking['carrier_tracking_ref'];
            $payload['tracking_company'] = is_array($picking['carrier_id'])
                ? $picking['carrier_id'][1]
                : '';
        }

        return $payload;
    }
}
