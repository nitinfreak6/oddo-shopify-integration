<?php

namespace App\Services\Shopify;

class ShopifyOrderService
{
    public function __construct(private readonly ShopifyService $shopify) {}

    /**
     * Get a single order by ID.
     */
    public function get(string $orderId): ?array
    {
        try {
            $response = $this->shopify->get("orders/{$orderId}.json");

            return $response['order'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
	
	public function list(array $params = []): array
{
    return $this->shopify->get('orders.json', $params);
}

    /**
     * Get orders created after a given timestamp.
     */
    public function getCreatedAfter(string $isoDatetime, int $limit = 250): array
    {
        $orders = [];

        foreach ($this->shopify->paginate('orders.json', [
            'status'       => 'any',
            'created_at_min' => $isoDatetime,
        ], $limit) as $page) {
            $orders = array_merge($orders, $page['orders'] ?? []);
        }

        return $orders;
    }

    /**
     * Cancel an order in Shopify.
     */
    public function cancel(string $orderId, string $reason = 'other'): array
    {
        return $this->shopify->post("orders/{$orderId}/cancel.json", [
            'reason' => $reason,
        ]);
    }
}
