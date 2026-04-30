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
        $productType   = $attributes['productType'] ?? config('amazon.product_type', 'SPORTING_GOODS');
        unset($attributes['productType']);

        $path = "/listings/" . self::LISTINGS_VERSION . "/items/{$sellerId}/" . rawurlencode($sku) . "?marketplaceIds=" . rawurlencode($marketplaceId);

        $body = [
            'productType' => $productType,
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

        $path = "/listings/" . self::LISTINGS_VERSION . "/items/{$sellerId}/" . rawurlencode($sku)
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
                "/listings/" . self::LISTINGS_VERSION . "/items/{$sellerId}/" . rawurlencode($sku),
                ['marketplaceIds' => [$marketplaceId]]
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
        $docResponse = $this->amazon->post("/feeds/" . self::FEEDS_VERSION . "/documents", [
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
        $feedResponse = $this->amazon->post("/feeds/" . self::FEEDS_VERSION . "/feeds", [
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
        $response = $this->amazon->get("/feeds/" . self::FEEDS_VERSION . "/feeds/{$feedJob->feed_id}");

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
    public function buildListingAttributes(array $odooTemplate, array $odooVariant, array $productAttributes = []): array

    {
        $condition     = config('amazon.condition', 'new_new');
        $marketplaceId = $this->amazon->getMarketplaceId();

        $attributes = [
            'productType' => $productAttributes['amazon_product_type'] ?? config('amazon.product_type', 'SPORTING_GOODS'),
            'item_name' => [
                ['value' => $odooTemplate['name'], 'marketplace_id' => $marketplaceId],
            ],
            'brand' => [
				['value' => !empty($odooTemplate['website_meta_keywords']) ? $odooTemplate['website_meta_keywords'] : config('amazon.manufacturer', 'Generic'), 'marketplace_id' => $marketplaceId],
			],
            'product_description' => [
                ['value' => strip_tags($odooTemplate['description_sale'] ?? ''), 'marketplace_id' => $marketplaceId],
            ],
            'condition_type' => [
                ['value' => $condition, 'marketplace_id' => $marketplaceId],
            ],
            
            'externally_assigned_product_identifier' => !empty($odooVariant['barcode']) ? [
                [
                    'type'  => 'EAN',
                    'value' => $odooVariant['barcode'],
                    'marketplace_id' => $marketplaceId,
                ],
            ] : [],
            'fulfillment_availability' => [
				[
					'fulfillment_channel_code' => 'DEFAULT',
					'quantity'                 => 0,
					'marketplace_id'           => $marketplaceId,
				],
			],
			
			// --- Price ---
			$price = (float) ($odooVariant['lst_price'] ?? 0);
			if ($price > 0) {
				$attributes['purchasable_offer'] = [[
					'currency'       => 'INR',
					'our_price'      => [[
						'schedule' => [[
							'value_with_tax' => $price,
						]],
					]],
					'marketplace_id' => $marketplaceId,
				]];
			}
		];

        // --- Weight from Odoo product ---
		// Odoo stores weight in grams, Amazon needs kilograms
		$weight = (float) ($odooVariant['weight'] ?? $odooTemplate['weight'] ?? 0);
		$weight = $weight > 10 ? round($weight / 1000, 3) : $weight; // convert g→kg if >10
		if ($weight > 0) {
			$attributes['item_weight'] = [[
				'unit'           => config('amazon.item_weight_unit', 'kilograms'),
				'value'          => $weight,
				'marketplace_id' => $marketplaceId,
			]];
		}

		// --- Dynamic attributes from Odoo product.attribute ---
		$attrMap = [
			'color'                              => 'color',
			'material'                           => 'material',
			'fabric_type'                        => 'fabric_type',
			'target_gender'                      => 'target_gender',
			'gender'                             => 'department',
			'style'                              => 'style',
			'number_of_items'                    => 'number_of_items',
			'included_components'                => 'included_components',
			'warranty_description'               => 'warranty_description',
			'supplier_declared_dg_hz_regulation' => 'supplier_declared_dg_hz_regulation',
			'item_type_name'                     => 'item_type_name',
			'care_instructions'                  => 'care_instructions',
			'age_range_description'              => 'age_range_description',
			'special_size_type'                  => 'special_size_type',
			'part_number'                        => 'part_number',
		];

		foreach ($attrMap as $odooAttr => $amazonAttr) {
			$value = $productAttributes[$odooAttr] ?? null;
			if ($value) {
				$attributes[$amazonAttr] = [[
					'value'          => $value,
					'marketplace_id' => $marketplaceId,
				]];
			}
		}
		
		// number_of_items needs integer format
		if (isset($attributes['number_of_items'])) {
			$attributes['number_of_items'] = [[
				'value'          => (int) ($productAttributes['number_of_items'] ?? 1),
				'marketplace_id' => $marketplaceId,
			]];
		}

		// --- Product type for conditional logic ---
		$productType = $productAttributes['amazon_product_type'] ?? config('amazon.product_type', 'SPORTING_GOODS');


		// warranty_description not supported for HOSIERY
		$noWarrantyTypes = ['HOSIERY', 'DRESS', 'SHIRT'];
		if (in_array($productType, $noWarrantyTypes) && isset($attributes['warranty_description'])) {
			unset($attributes['warranty_description']);
		}
		// Fix included_components — only for product types that support it
		$supportedForComponents = ['SPORTING_GOODS', 'TOYS', 'LUGGAGE'];
		if (isset($attributes['included_components']) && !in_array($productType, $supportedForComponents)) {
			unset($attributes['included_components']);
		}
		
		// --- Item dimensions from Odoo attribute ---
		$dimensionValue = $productAttributes['item_dimensions'] ?? null;
		if ($dimensionValue) {
			// Parse "22x22x22 cm" format
			preg_match('/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*(cm|in|mm)?/i', $dimensionValue, $matches);
			if (count($matches) >= 4) {
				$unit = strtolower($matches[4] ?? 'centimeters');
				$unit = $unit === 'cm' ? 'centimeters' : ($unit === 'in' ? 'inches' : 'centimeters');
				$attributes['item_dimensions'] = [[
					'length'         => ['value' => (float) $matches[1], 'unit' => $unit],
					'width'          => ['value' => (float) $matches[2], 'unit' => $unit],
					'height'         => ['value' => (float) $matches[3], 'unit' => $unit],
					'marketplace_id' => $marketplaceId,
				]];
			}
		}

		// --- Model name from product name ---
		$attributes['model_name'] = [[
			'value'          => $odooTemplate['name'],
			'marketplace_id' => $marketplaceId,
		]];
		
		// --- Unit count — not supported for all product types ---
		$noUnitCountTypes = ['HOSIERY', 'DRESS', 'SHIRT'];
		if (!in_array($productType, $noUnitCountTypes)) {
			$unitCount = $productAttributes['unit_count'] ?? '1';
			$attributes['unit_count'] = [[
				'value'          => (float) $unitCount,
				'type'           => [
					'value'        => 'Count',
					'language_tag' => 'en_IN',
				],
				'marketplace_id' => $marketplaceId,
			]];
		}
		

		

		// --- Company-wide contact info from DB settings (simple string format) ---
		$contactStr = \DB::table('settings')->where('key', 'amazon_company_contact_string')->value('value');
		if (!empty($contactStr)) {
			$contactFormat = [[
				'value'          => $contactStr,
				'language_tag'   => 'en_IN',
				'marketplace_id' => $marketplaceId,
			]];
			$attributes['packer_contact_information']            = $contactFormat;
			$attributes['importer_contact_information']          = $contactFormat;
			$attributes['rtip_manufacturer_contact_information'] = $contactFormat;
		}

		// Required for some India product types (commonly HSN/external product info).
		// --- HSN Code for India (required) — from Odoo product attribute ---
		$hsnCode = $productAttributes['hsn_code'] ?? null;
		if ($hsnCode) {
			$attributes['external_product_information'] = [[
				'entity'         => 'HSN Code',
				'value'          => (string) $hsnCode,
				'marketplace_id' => $marketplaceId,
			]];
		}
		// --- Manufacturer, country of origin from config ---
		if (config('amazon.manufacturer')) {
			$attributes['manufacturer'] = [[
				'value'          => config('amazon.manufacturer'),
				'marketplace_id' => $marketplaceId,
			]];
		}
		if (config('amazon.country_of_origin')) {
			$attributes['country_of_origin'] = [[
				'value'          => config('amazon.country_of_origin'),
				'marketplace_id' => $marketplaceId,
			]];
		}

		// --- Merchant suggested ASIN from config ---
		if (config('amazon.merchant_suggested_asin')) {
			$attributes['merchant_suggested_asin'] = [[
				'value'          => config('amazon.merchant_suggested_asin'),
				'marketplace_id' => $marketplaceId,
			]];
		}

		// --- Bullet points from Odoo description ---
		$description = strip_tags($odooTemplate['description_sale'] ?? $odooTemplate['name']);
		$bullets = array_filter(array_map('trim', explode('.', $description)));
		$bullets = array_slice(array_values($bullets), 0, 5);
		if (empty($bullets)) {
			$bullets = [$odooTemplate['name']];
		}
		$attributes['bullet_point'] = array_map(
			fn($b) => ['value' => $b, 'marketplace_id' => $marketplaceId],
			$bullets
		);
		
		// --- Remove attributes not supported by this product type ---
		$productTypeSvc = app(AmazonProductTypeService::class);
		$supportedFields = $productTypeSvc->getAllFields($productType, $marketplaceId);

		if (!empty($supportedFields)) {
			foreach (array_keys($attributes) as $key) {
				if ($key === 'productType') continue;
				if (!in_array($key, $supportedFields)) {
					Log::debug("Amazon: removing unsupported field '{$key}' for product type '{$productType}'");
					unset($attributes[$key]);
				}
			}
		}

		

		return $attributes;
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

	private function normalizeContactInformation(mixed $contact, string $marketplaceId): array
	{
		if (!is_array($contact) || empty($contact)) {
			return [];
		}

		// Accept either a single contact object or a list of contact objects.
		$isAssoc = array_keys($contact) !== range(0, count($contact) - 1);
		$contactEntries = $isAssoc ? [$contact] : $contact;

		$normalized = [];
		foreach ($contactEntries as $entry) {
			if (!is_array($entry) || empty($entry)) {
				continue;
			}

			$normalized[] = [
				'value'          => $entry,
				'marketplace_id' => $marketplaceId,
			];
		}

		return $normalized;
	}

    private function downloadAndLogResult(AmazonFeedJob $feedJob, string $resultDocumentId): void
    {
        try {
            $docInfo = $this->amazon->get("/feeds/" . self::FEEDS_VERSION . "/documents/{$resultDocumentId}");
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
