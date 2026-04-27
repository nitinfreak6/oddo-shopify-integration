<?php

namespace App\Services\Amazon;

use Illuminate\Support\Facades\Log;

class AmazonInventoryService
{
    private const LISTINGS_VERSION = '2021-08-01';

    public function __construct(
        private readonly AmazonService     $amazon,
        private readonly AmazonListingService $listings,
    ) {}

    /**
     * Update quantity for a FBM listing via Listings Items PATCH.
     * For FBA, Amazon manages inventory — do not call this.
     */
    public function updateQuantity(string $sku, int $quantity): array
    {
        if (config('amazon.fulfillment_channel') === 'FBA') {
            Log::debug("Amazon FBA mode — skipping inventory push for SKU {$sku}");
            return [];
        }

        $sellerId      = $this->amazon->getSellerId();
        $marketplaceId = $this->amazon->getMarketplaceId();

        $path = "/listings/{$this->LISTINGS_VERSION}/items/{$sellerId}/" . rawurlencode($sku);

        $body = [
            'productType' => 'PRODUCT',
            'patches'     => [
                [
                    'op'    => 'replace',
                    'path'  => '/attributes/fulfillment_availability',
                    'value' => [
                        [
                            'fulfillment_channel_code' => 'DEFAULT',
                            'quantity'                 => $quantity,
                            'marketplace_id'           => $marketplaceId,
                        ],
                    ],
                ],
            ],
        ];

        // SP-API uses PATCH for partial updates
        $token   = $this->amazon->getAccessToken();
        $client  = new \GuzzleHttp\Client([
            'base_uri' => rtrim(config('amazon.endpoint'), '/') . '/',
            'timeout'  => config('amazon.timeout', 30),
        ]);

        try {
            $response = $client->patch(ltrim($path, '/'), [
                'headers' => [
                    'x-amz-access-token' => $token,
                    'x-amz-date'         => gmdate('Ymd\THis\Z'),
                    'Content-Type'       => 'application/json',
                ],
                'query' => ['marketplaceIds' => $marketplaceId],
                'json'  => $body,
            ]);

            $result = json_decode((string) $response->getBody(), true) ?? [];

            Log::info("Amazon inventory updated: SKU={$sku} qty={$quantity}");

            return $result;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status   = $e->getResponse()->getStatusCode();
            $respBody = json_decode((string) $e->getResponse()->getBody(), true);

            throw new \App\Exceptions\AmazonApiException(
                "Amazon inventory PATCH failed for SKU {$sku}: HTTP {$status}",
                $status,
                $path,
                $respBody['errors'] ?? null,
                $e
            );
        }
    }

    /**
     * Get FBA inventory summaries (read-only — for reconciliation).
     */
    public function getFbaInventory(array $skus = []): array
    {
        $params = [
            'granularityType' => 'Marketplace',
            'granularityId'   => $this->amazon->getMarketplaceId(),
            'marketplaceIds'  => $this->amazon->getMarketplaceId(),
        ];

        if ($skus) {
            $params['sellerSkus'] = implode(',', $skus);
        }

        $response = $this->amazon->get('/fba/inventory/v1/summaries', $params);

        return $response['payload']['inventorySummaries'] ?? [];
    }
}
