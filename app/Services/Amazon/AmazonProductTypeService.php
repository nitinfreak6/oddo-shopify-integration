<?php

namespace App\Services\Amazon;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AmazonProductTypeService
{
    public function __construct(private readonly AmazonService $amazon) {}

    /**
     * Get required fields for a product type from Amazon schema.
     * Cached for 24 hours.
     */
    public function getRequiredFields(string $productType, string $marketplaceId): array
    {
        return Cache::remember("amazon_required_fields_{$productType}_{$marketplaceId}", now()->addHours(24), function () use ($productType, $marketplaceId) {
            try {
                $token = $this->amazon->getAccessToken();
                $resp  = Http::withHeaders(['x-amz-access-token' => $token])
                    ->get("https://sellingpartnerapi-eu.amazon.com/definitions/2020-09-01/productTypes/{$productType}", [
                        'marketplaceIds' => $marketplaceId,
                        'requirements'   => 'LISTING',
                    ]);

                $schemaUrl = $resp->json()['schema']['link']['resource'] ?? null;
                if (!$schemaUrl) return [];

                $schema = Http::get($schemaUrl)->json();
                return $schema['required'] ?? [];
            } catch (\Throwable $e) {
                Log::warning("Could not fetch Amazon schema for {$productType}: " . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Get all property names for a product type grouped by section.
     */
    public function getAllFields(string $productType, string $marketplaceId): array
    {
        return Cache::remember("amazon_all_fields_{$productType}_{$marketplaceId}", now()->addHours(24), function () use ($productType, $marketplaceId) {
            try {
                $token = $this->amazon->getAccessToken();
                $resp  = Http::withHeaders(['x-amz-access-token' => $token])
                    ->get("https://sellingpartnerapi-eu.amazon.com/definitions/2020-09-01/productTypes/{$productType}", [
                        'marketplaceIds' => $marketplaceId,
                        'requirements'   => 'LISTING',
                    ]);

                $groups = $resp->json()['propertyGroups'] ?? [];
                $all = [];
                foreach ($groups as $group) {
                    $all = array_merge($all, $group['propertyNames'] ?? []);
                }
                return $all;
            } catch (\Throwable $e) {
                Log::warning("Could not fetch Amazon fields for {$productType}: " . $e->getMessage());
                return [];
            }
        });
    }
}