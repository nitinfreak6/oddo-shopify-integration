<?php

namespace App\Services\Amazon;

use App\Exceptions\AmazonApiException;
use App\Models\AmazonFeedJob;
use Illuminate\Support\Facades\Log;

class AmazonListingService
{
    /*
     * SP-API version for Listings Items
     */
    private const LISTINGS_VERSION = '2021-08-01';

    /*
     * SP-API version for Feeds
     */
    private const FEEDS_VERSION = '2021-06-30';

    public function __construct(private readonly AmazonService $amazon) {}

    /**
     * Create or update a single listing using the Listings Items API (synchronous).
     * Returns the submission result status.
     *
     * @param string $sku      Seller SKU (must match Odoo default_code)
     * @param array  $attributes  Product attributes in SP-API format
     */
    public function putListing(string $sku, array $attributes): array
    {
        $sellerId      = $this->amazon->getSellerId();
        $marketplaceId = $this->amazon->getMarketplaceId();

        $path = "/listings/{$this->LISTINGS_VERSION}/items/{$sellerId}/" . rawurlencode($sku);

        $body = [
            'productType' => $attributes['productType'] ?? 'PRODUCT',
            'requirements' => 'LISTING',
            'attributes'   => $attributes,
        ];

        try {
            $response = $this->amazon->put($path, $body);

            Log::info("Amazon listing PUT for SKU {$sku}: " . ($response['status'] ?? 'unknown'));

            return $response;
        } catch (AmazonApiException $e) {
            // 400 with issues means the listing has validation problems
            if ($e->getHttpStatus() === 400) {
                Log::warning("Amazon listing validation issues for SKU {$sku}", [
                    'errors' => $e->getErrors(),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Delete a listing from Amazon.
     */
    public function deleteListing(string $sku): array
    {
        $sellerId      = $this->amazon->getSellerId();
        $marketplaceId = $this->amazon->getMarketplaceId();

        $path = "/listings/{$this->LISTINGS_VERSION}/items/{$sellerId}/" . rawurlencode($sku)
              . "?marketplaceIds={$marketplaceId}";

        return $this->amazon->delete($path);
    }

    /**
     * Get a listing by SKU.
     */
    public function getListing(string $sku): ?array
    {
        $sellerId      = $this->amazon->getSellerId();
        $marketplaceId = $this->amazon->getMarketplaceId();

        try {
            return $this->amazon->get(
                "/listings/{$this->LISTINGS_VERSION}/items/{$sellerId}/" . rawurlencode($sku),
                ['marketplaceIds' => $marketplaceId]
            );
        } catch (AmazonApiException $e) {
            if ($e->getHttpStatus() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Submit a bulk JSON_LISTINGS_FEED for multiple products.
     * Returns Amazon feedId — track status via pollFeed().
     *
     * Amazon Feeds flow:
     *  1. Create upload document → get presigned URL + documentId
     *  2. PUT content to presigned URL
     *  3. Submit feed referencing documentId → get feedId
     *  4. Poll GET /feeds/{feedId} until DONE
     *  5. Download result document to see per-record errors
     */
    public function submitBulkListingsFeed(array $listingsPayload, ?string $odooEntityId = null): AmazonFeedJob
    {
        $marketplaceId = $this->amazon->getMarketplaceId();
        $content       = json_encode($listingsPayload);

        // Step 1: Create feed document (get upload URL)
        $docResponse = $this->amazon->post("/feeds/{$this->FEEDS_VERSION}/documents", [
            'contentType' => 'application/json; charset=UTF-8',
        ]);

        $uploadUrl  = $docResponse['url'] ?? null;
        $documentId = $docResponse['feedDocumentId'] ?? null;

        if (!$uploadUrl || !$documentId) {
            throw new AmazonApiException('Amazon feed document creation returned no URL or documentId', 0, 'feeds');
        }

        // Step 2: Upload feed content
        $this->amazon->uploadDocument($uploadUrl, $content, 'application/json; charset=UTF-8');

        // Step 3: Submit feed
        $feedResponse = $this->amazon->post("/feeds/{$this->FEEDS_VERSION}/feeds", [
            'feedType'          => 'JSON_LISTINGS_FEED',
            'marketplaceIds'    => [$marketplaceId],
            'inputFeedDocumentId' => $documentId,
        ]);

        $feedId = $feedResponse['feedId'] ?? null;

        if (!$feedId) {
            throw new AmazonApiException('Amazon feed submission returned no feedId', 0, 'feeds');
        }

        // Persist for polling
        $feedJob = AmazonFeedJob::create([
            'feed_id'           => $feedId,
            'feed_type'         => 'JSON_LISTINGS_FEED',
            'odoo_entity_type'  => 'product',
            'odoo_entity_id'    => $odooEntityId,
            'status'            => AmazonFeedJob::STATUS_SUBMITTED,
            'submitted_at'      => now(),
        ]);

        Log::info("Amazon feed submitted: feedId={$feedId}", ['odoo_entity_id' => $odooEntityId]);

        return $feedJob;
    }

    /**
     * Poll feed status and update the AmazonFeedJob record.
     * Returns true if feed is complete (done or fatal).
     */
    public function pollFeed(AmazonFeedJob $feedJob): bool
    {
        $response = $this->amazon->get("/feeds/{$this->FEEDS_VERSION}/feeds/{$feedJob->feed_id}");

        $status             = strtolower($response['processingStatus'] ?? 'in_progress');
        $resultDocumentId   = $response['resultFeedDocumentId'] ?? null;

        $feedJob->update([
            'status'             => $this->mapFeedStatus($status),
            'result_document_id' => $resultDocumentId,
            'poll_attempts'      => $feedJob->poll_attempts + 1,
        ]);

        if ($feedJob->isTerminal()) {
            $feedJob->update(['completed_at' => now()]);

            if ($resultDocumentId) {
                $this->downloadAndLogResult($feedJob, $resultDocumentId);
            }

            return true;
        }

        return false;
    }

    /**
     * Build the Listings Items API attributes payload from Odoo product data.
     */
    public function buildListingAttributes(array $odooTemplate, array $odooVariant): array
    {
        $condition     = config('amazon.condition', 'new_new');
        $marketplaceId = $this->amazon->getMarketplaceId();

        return [
            'productType' => 'PRODUCT',
            'item_name' => [
                ['value' => $odooTemplate['name'], 'marketplace_id' => $marketplaceId],
            ],
            'brand' => [
                ['value' => $odooTemplate['website_meta_keywords'] ?? 'Generic', 'marketplace_id' => $marketplaceId],
            ],
            'product_description' => [
                ['value' => strip_tags($odooTemplate['description_sale'] ?? ''), 'marketplace_id' => $marketplaceId],
            ],
            'condition_type' => [
                ['value' => $condition, 'marketplace_id' => $marketplaceId],
            ],
            'list_price' => [
                [
                    'currency' => 'USD',
                    'value'    => (float) ($odooVariant['lst_price'] ?? 0),
                    'marketplace_id' => $marketplaceId,
                ],
            ],
            'externally_assigned_product_identifier' => !empty($odooVariant['barcode']) ? [
                [
                    'type'  => 'UPC',
                    'value' => $odooVariant['barcode'],
                    'marketplace_id' => $marketplaceId,
                ],
            ] : [],
            'fulfillment_availability' => [
                [
                    'fulfillment_channel_code' => config('amazon.fulfillment_channel', 'DEFAULT'),
                    'quantity'                 => 0, // set separately via inventory sync
                    'marketplace_id'           => $marketplaceId,
                ],
            ],
        ];
    }

    private function mapFeedStatus(string $amazonStatus): string
    {
        return match ($amazonStatus) {
            'in_queue', 'in_progress' => AmazonFeedJob::STATUS_IN_PROGRESS,
            'cancelled'               => AmazonFeedJob::STATUS_CANCELLED,
            'done'                    => AmazonFeedJob::STATUS_DONE,
            'fatal'                   => AmazonFeedJob::STATUS_FATAL,
            default                   => AmazonFeedJob::STATUS_IN_PROGRESS,
        };
    }

    private function downloadAndLogResult(AmazonFeedJob $feedJob, string $resultDocumentId): void
    {
        try {
            $docInfo = $this->amazon->get("/feeds/{$this->FEEDS_VERSION}/documents/{$resultDocumentId}");
            $url     = $docInfo['url'] ?? null;

            if (!$url) {
                return;
            }

            $content = $this->amazon->downloadDocument($url);
            $result  = json_decode($content, true);

            $summary = '';
            if (!empty($result['issues'])) {
                $summary = json_encode(array_slice($result['issues'], 0, 20));
            }

            $feedJob->update(['processing_summary' => $summary ?: 'No issues.']);

            Log::info("Amazon feed {$feedJob->feed_id} result downloaded.", [
                'status'  => $feedJob->status,
                'summary' => $summary ?: 'OK',
            ]);
        } catch (\Throwable $e) {
            Log::warning("Could not download Amazon feed result: " . $e->getMessage());
        }
    }
}
