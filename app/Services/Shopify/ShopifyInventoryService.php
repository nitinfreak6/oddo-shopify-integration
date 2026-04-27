<?php

namespace App\Services\Shopify;

class ShopifyInventoryService
{
    public function __construct(private readonly ShopifyService $shopify) {}

    /**
     * Set inventory level for a specific item at a location.
     */
    public function setLevel(string $inventoryItemId, string $shopifyLocationId, int $available): array
    {
        return $this->shopify->post('inventory_levels/set.json', [
            'location_id'       => (int) $shopifyLocationId,
            'inventory_item_id' => (int) $inventoryItemId,
            'available'         => $available,
        ]);
    }

    /**
     * Get inventory levels for a list of inventory item IDs.
     */
    public function getLevels(array $inventoryItemIds, string $shopifyLocationId): array
    {
        return $this->shopify->get('inventory_levels.json', [
            'inventory_item_ids' => implode(',', $inventoryItemIds),
            'location_ids'       => $shopifyLocationId,
        ])['inventory_levels'] ?? [];
    }

    /**
     * Get locations registered in Shopify.
     */
    public function getLocations(): array
    {
        return $this->shopify->get('locations.json')['locations'] ?? [];
    }
}
