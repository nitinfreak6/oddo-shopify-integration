<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ProductFieldConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ShopifyProductService
 *
 * ══════════════════════════════════════════════════════════════════════
 * DESIGN PRINCIPLE — config-driven, nothing hardcoded
 * ══════════════════════════════════════════════════════════════════════
 *
 * The shopify_field column in product_field_configs IS the GraphQL key.
 * This service reads those keys and routes/nests values purely by
 * key-pattern rules:
 *
 *   Key pattern                     │ Routed to
 *   ────────────────────────────────┼───────────────────────────────────
 *   'images'                        │ $media param (CreateMediaInput[])
 *   'status'                        │ ProductInput.status (uppercased)
 *   'tags'                          │ ProductInput.tags (string → array)
 *   dot-notation e.g. 'a.b.c'       │ deeply nested via dot-set
 *   option1 / option2 / option3     │ variant optionValues[]
 *   'inventoryPolicy'               │ variant top-level (uppercased enum)
 *   'taxable','requiresShipping'… . │ variant top-level (bool cast)
 *   'price','compareAtPrice'        │ variant top-level (2dp string)
 *   anything else                   │ passthrough as-is
 *
 * Adding a new Shopify field in the dashboard → works immediately.
 * Disabling a field config row → that field is omitted from the payload.
 * Deleting a row → field never sent to Shopify.
 *
 * This service is ERP-agnostic: it only cares about shopify_field keys
 * and the values resolved by resolveValue(). Swapping the ERP means
 * only the Odoo-side resolver changes, not this service.
 *
 * ══════════════════════════════════════════════════════════════════════
 * Routing reference (derived from key name — no lookup table)
 * ══════════════════════════════════════════════════════════════════════
 *
 * Template scope ProductInput keys:
 *   title, descriptionHtml, vendor, productType, tags, status,
 *   handle, templateSuffix, images
 *   → any future key added in the dashboard is passed through as-is
 *
 * Variant scope keys and their nesting:
 *   price, compareAtPrice           → variant (formatted to 2dp string)
 *   taxable                         → variant (bool)
 *   inventoryPolicy                 → variant (uppercased enum: DENY/CONTINUE)
 *   option1/2/3                     → variant.optionValues[]
 *   inventoryItem.*                 → variant.inventoryItem (nested)
 *     .sku                          → inventoryItem.sku
 *     .barcode                      → inventoryItem.barcode
 *     .tracked                      → inventoryItem.tracked (bool)
 *     .requiresShipping             → inventoryItem.requiresShipping (bool)
 *     .measurement.weight.value     → inventoryItem.measurement.weight.value
 *     .measurement.weight.unit      → inventoryItem.measurement.weight.unit (enum)
 *   any other dot key               → nested via dot-set
 *   anything else                   → passthrough
 */
class ShopifyProductService
{
    // Structural keys never sent as simple k→v pairs to ProductInput
    private const STRUCTURAL = ['images', 'variants', 'options'];

    // Variant keys that stay at top level (not nested under inventoryItem)
    private const VARIANT_TOP_LEVEL = ['price', 'compareAtPrice', 'taxable', 'inventoryPolicy'];

    // Weight unit → GraphQL enum
    private const WEIGHT_UNITS = [
        'kg' => 'KILOGRAMS', 'g'  => 'GRAMS', 'lb' => 'POUNDS', 'oz' => 'OUNCES',
        'KILOGRAMS' => 'KILOGRAMS', 'GRAMS' => 'GRAMS', 'POUNDS' => 'POUNDS', 'OUNCES' => 'OUNCES',
    ];

    // Inventory policy → GraphQL enum
    private const INVENTORY_POLICIES = [
        'deny' => 'DENY', 'continue' => 'CONTINUE', 'DENY' => 'DENY', 'CONTINUE' => 'CONTINUE',
    ];
	
	private const INVENTORY_SCHEMA = [
		'sku',
		'tracked',
		'requiresShipping',
		'measurement.weight.value',
		'measurement.weight.unit',
	];
	
	private array $wireLog = [];

    public function __construct(private readonly ShopifyGraphQLService $graphql) {}

    // ══════════════════════════════════════════════════════════════════════
    // LIST / FETCH PRODUCTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * List products from Shopify with optional filters
     * 
     * @param array $filters ['limit' => 50, 'updated_at_min' => '2024-01-01', 'status' => 'active']
     * @return array Array of products
     */
    public function list(array $filters = []): array
    {
        $limit = $filters['limit'] ?? 250;
        $updatedAtMin = $filters['updated_at_min'] ?? null;
        $status = $filters['status'] ?? null;
        
        // Build query string for filtering
        $queryParts = [];
        if ($updatedAtMin) {
            $queryParts[] = "updated_at:>'{$updatedAtMin}'";
        }
        if ($status) {
            $queryParts[] = "status:{$status}";
        }
        $queryString = !empty($queryParts) ? implode(' AND ', $queryParts) : null;

        // Use different query based on whether we have filters
        if ($queryString) {
            $query = $this->getProductsQueryWithFilter();
        } else {
            $query = $this->getProductsQueryNoFilter();
        }

        Log::info('Fetching products from Shopify', [
            'limit' => $limit,
            'query_string' => $queryString,
            'filters' => $filters,
        ]);

        $products = [];
        $hasNextPage = true;
        $cursor = null;
        $fetchedCount = 0;

        while ($hasNextPage && $fetchedCount < $limit) {
            if ($queryString) {
                $variables = [
                    'first' => min(50, $limit - $fetchedCount),
                    'query' => $queryString,
                    'after' => $cursor,
                ];
            } else {
                $variables = [
                    'first' => min(50, $limit - $fetchedCount),
                    'after' => $cursor,
                ];
            }

            $response = $this->graphql->query($query, $variables);
            
            Log::debug('Shopify GraphQL response', [
                'has_products' => isset($response['products']),
                'edges_count' => count($response['products']['edges'] ?? []),
            ]);
            
            if (!isset($response['products'])) {
                Log::error('Shopify product list query failed', ['response' => $response]);
                break;
            }

            $edges = $response['products']['edges'] ?? [];
            
            foreach ($edges as $edge) {
                $product = $this->normalizeProduct($edge['node']);
                $products[] = $product;
                $fetchedCount++;
                
                if ($fetchedCount >= $limit) {
                    break;
                }
            }

            $pageInfo = $response['products']['pageInfo'] ?? [];
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;
        }

        return $products;
    }

    private function getProductsQueryNoFilter(): string
    {
        return <<<'GRAPHQL'
        query($first: Int!, $after: String) {
          products(first: $first, after: $after) {
            edges {
              node {
                ...ProductFields
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        fragment ProductFields on Product {
          id
          title
          descriptionHtml
          handle
          status
          vendor
          productType
          tags
          templateSuffix
          publishedAt
          onlineStoreUrl
          createdAt
          updatedAt
          seo {
            title
            description
          }
          options {
            id
            name
            position
            values
          }
          variants(first: 100) {
            edges {
              node {
                id
                title
                sku
                barcode
                price
                compareAtPrice
                inventoryQuantity
                taxable
                inventoryPolicy
                position
                selectedOptions {
                  name
                  value
                }
                inventoryItem {
                  id
                  tracked
                  requiresShipping
                  harmonizedSystemCode
                  countryCodeOfOrigin
                  measurement {
                    weight {
                      value
                      unit
                    }
                  }
                }
              }
            }
          }
          images(first: 10) {
            edges {
              node {
                id
                url
                altText
                width
                height
              }
            }
          }
          metafields(first: 20) {
            edges {
              node {
                id
                namespace
                key
                value
                type
              }
            }
          }
          collections(first: 10) {
            edges {
              node {
                id
                title
                handle
              }
            }
          }
        }
        GRAPHQL;
    }

    private function getProductsQueryWithFilter(): string
    {
        return <<<'GRAPHQL'
        query($first: Int!, $query: String!, $after: String) {
          products(first: $first, query: $query, after: $after) {
            edges {
              node {
                ...ProductFields
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        fragment ProductFields on Product {
          id
          title
          descriptionHtml
          handle
          status
          vendor
          productType
          tags
          templateSuffix
          publishedAt
          onlineStoreUrl
          createdAt
          updatedAt
          seo {
            title
            description
          }
          options {
            id
            name
            position
            values
          }
          variants(first: 100) {
            edges {
              node {
                id
                title
                sku
                barcode
                price
                compareAtPrice
                inventoryQuantity
                taxable
                inventoryPolicy
                position
                selectedOptions {
                  name
                  value
                }
                inventoryItem {
                  id
                  tracked
                  requiresShipping
                  harmonizedSystemCode
                  countryCodeOfOrigin
                  measurement {
                    weight {
                      value
                      unit
                    }
                  }
                }
              }
            }
          }
          images(first: 10) {
            edges {
              node {
                id
                url
                altText
                width
                height
              }
            }
          }
          metafields(first: 20) {
            edges {
              node {
                id
                namespace
                key
                value
                type
              }
            }
          }
          collections(first: 10) {
            edges {
              node {
                id
                title
                handle
              }
            }
          }
        }
        GRAPHQL;
    }

    /**
     * Normalize GraphQL product response to simpler array structure
     * Uses the existing normalizeProduct at the end of the class
     */

    // ══════════════════════════════════════════════════════════════════════
    // CREATE / UPDATE
    // ══════════════════════════════════════════════════════════════════════


    // ─────────────────────────────────────────────────────────────────────
    // Public API  (same signatures as old REST service — callers unchanged)
    // ─────────────────────────────────────────────────────────────────────

    public function create(array $productData): array
    {
        [$input, $media] = $this->toGraphQLInput($productData);
		
		
		$query = $this->productCreateMutation();
		$this->recordWire('productCreate', $query, ['product' => $input, 'media' => $media ?: null]);
		$data   = $this->graphql->query($query, ['product' => $input, 'media' => $media ?: null]);

        $errors = $this->graphql->extractUserErrors($data, 'productCreate');

        if (!empty($errors)) {
            throw new ShopifyApiException('Shopify productCreate errors: ' . implode('; ', $errors), 422, 'productCreate');
        }
		
		$product = $data['productCreate']['product'];
		$productId = $this->fromGid($product['id']);

		$this->syncVariants($product['id'], $productData['variants'] ?? []);

		return $this->normalizeProduct($product);

        //return $this->normalizeProduct($data['productCreate']['product']);
    }

    public function update(string $shopifyProductId, array $productData): array
    {
        [$input, $media] = $this->toGraphQLInput($productData);
        $input['id']     = $this->toGid('Product', $shopifyProductId);
		
		$query = $this->productUpdateMutation();
		$this->recordWire('productUpdate', $query, ['input' => $input, 'media' => $media ?: null]);

		$data = $this->graphql->query($query, ['input' => $input, 'media' => $media ?: null]);

        $errors = $this->graphql->extractUserErrors($data, 'productUpdate');

        if (!empty($errors)) {
            $notFound = array_filter($errors, fn($e) =>
                stripos($e, 'not found') !== false || stripos($e, 'does not exist') !== false
            );
            throw new ShopifyApiException(
                'Shopify productUpdate errors: ' . implode('; ', $errors),
                !empty($notFound) ? 404 : 422,
                'productUpdate'
            );
        }
		
		$product = $data['productUpdate']['product'];

		$this->syncVariants($product['id'], $productData['variants'] ?? []);

		return $this->normalizeProduct($product);

        //return $this->normalizeProduct($data['productUpdate']['product']);
    }
	
	private function getExistingVariants(string $productGid): array
	{
		$query = <<<GQL
		query(\$id: ID!) {
		  product(id: \$id) {
			variants(first: 10) {
			  edges {
				node { id title }
			  }
			}
		  }
		}
		GQL;

		$data = $this->graphql->query($query, ['id' => $productGid]);

		return array_map(
			fn($e) => $e['node'],
			$data['product']['variants']['edges'] ?? []
		);
	}
	
	private function replaceDefaultVariant(string $productGid, string $variantGid, array $payload): void
	{
		$mutation = <<<GQL
		mutation(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
		  productVariantsBulkUpdate(
			productId: \$productId,
			variants: \$variants
		  ) {
			userErrors { field message }
		  }
		}
		GQL;


		$input = $this->toGraphQLVariantInput($payload);
		$input['id'] = $variantGid;
		
		$this->recordWire('productVariantsBulkUpdate', $mutation, ['productId' => $productGid, 'variants' => [$input]]);

		$this->graphql->query($mutation, [
			'productId' => $productGid,
			'variants'  => [$input],
		]);
	}
	
	private function syncVariants(string $productGid, array $variants): void
	{
		if (empty($variants)) return;
		
		$existing = $this->getExistingVariants($productGid);

		if (count($existing) === 1 && $existing[0]['title'] === 'Default Title') {
			$this->replaceDefaultVariant(
				$productGid,
				$existing[0]['id'],
				$variants[0]
			);
			return;
		}

		$mutation = <<<GQL
		mutation bulkCreateVariants(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
			productVariantsBulkCreate(productId: \$productId, variants: \$variants) {
				productVariants { id }
				userErrors { field message }
			}
		}
		GQL;

		$variantsInput = array_map(
			fn($v) => $this->toGraphQLVariantInput($v),
			$variants
		);
		
		$this->recordWire('productVariantsBulkCreate', $mutation, ['productId' => $productGid, 'variants' => $variantsInput]);

		$data = $this->graphql->query($mutation, [
			'productId' => $productGid,
			'variants'  => $variantsInput,
		]);

		$errors = $this->graphql->extractUserErrors($data, 'productVariantsBulkCreate');
		if (!empty($errors)) {
			throw new ShopifyApiException(
				'Variant sync failed: ' . implode('; ', $errors),
				422,
				'productVariantsBulkCreate'
			);
		}
	}

    public function get(string $shopifyProductId): ?array
    {
        try {
            $query = <<<'GQL'
            query getProduct($id: ID!) {
                product(id: $id) {
                    id
                    title
                    descriptionHtml
                    handle
                    status
                    vendor
                    productType
                    tags
                    templateSuffix
                    publishedAt
                    onlineStoreUrl
                    createdAt
                    updatedAt
                    seo {
                        title
                        description
                    }
                    options {
                        id
                        name
                        position
                        values
                    }
                    variants(first: 100) {
                        edges {
                            node {
                                id
                                title
                                sku
                                barcode
                                price
                                compareAtPrice
                                inventoryQuantity
                                taxable
                                inventoryPolicy
                                position
                                selectedOptions {
                                    name
                                    value
                                }
                                inventoryItem {
                                    id
                                    tracked
                                    requiresShipping
                                    harmonizedSystemCode
                                    countryCodeOfOrigin
                                    measurement {
                                        weight {
                                            value
                                            unit
                                        }
                                    }
                                }
                            }
                        }
                    }
                    images(first: 10) {
                        edges {
                            node {
                                id
                                url
                                altText
                                width
                                height
                            }
                        }
                    }
                    metafields(first: 20) {
                        edges {
                            node {
                                id
                                namespace
                                key
                                value
                                type
                            }
                        }
                    }
                    collections(first: 10) {
                        edges {
                            node {
                                id
                                title
                                handle
                            }
                        }
                    }
                }
            }
            GQL;

            $data    = $this->graphql->query($query, ['id' => $this->toGid('Product', $shopifyProductId)]);
            $product = $data['product'] ?? null;

            return $product ? $this->normalizeProduct($product) : null;
        } catch (\Throwable $e) {
            Log::warning("ShopifyProductService::get failed #{$shopifyProductId}: " . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // buildPayload — Odoo data → intermediate payload
    //
    // Keys in this array ARE the shopify_field values from product_field_configs.
    // toGraphQLInput() then routes them into the correct GraphQL structure.
    // ─────────────────────────────────────────────────────────────────────

    public function buildPayload(array $erpTemplate, array $variants, array $attributeValues): array
    {
        $configs         = $this->getFieldConfigs();
        $templateConfigs = array_filter($configs, fn($c) => $c['scope'] === 'template');
        $variantConfigs  = array_filter($configs, fn($c) => $c['scope'] === 'variant');

        $payload = [];

        foreach ($templateConfigs as $config) {
            $key = $config['shopify_field'];

            if (!$config['is_active']) {
                continue;
            }

            $value = $this->resolveValue($erpTemplate, $config);
            if ($value === null || $value === '') continue;

            $payload[$key] = $value;
        }

        // status must always be present
        if (!isset($payload['status'])) {
            $payload['status'] = 'draft';
        }

        // Variants
        $shopifyVariants = array_map(
            fn($v) => $this->buildVariantPayload($v, $attributeValues, $variantConfigs),
            $variants
        );
        $payload['variants'] = $shopifyVariants;

        // Options
        if (!empty($erpTemplate['attribute_line_ids'])) {
            $options = $this->buildOptions($attributeValues, $shopifyVariants);
            if (!empty($options)) {
                $payload['options'] = $options;
            }
        }

        return $payload;
    }

    // ─────────────────────────────────────────────────────────────────────
    // toGraphQLInput — routes shopify_field keys into GraphQL ProductInput
    //
    // No field name lookup tables. Routing is by key-pattern rules only.
    // ─────────────────────────────────────────────────────────────────────

    private function toGraphQLInput(array $payload): array
    {
        $input = [];
        $media = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::STRUCTURAL, true)) continue;
			if (str_starts_with($key, 'metafield:')) continue;

            $this->applyTemplateKey($input, $key, $value);
        }
		
		
		$metafields = [];
		foreach ($payload as $key => $value) {
			if (!str_starts_with($key, 'metafield:')) continue;
			if ($value === null || $value === '') continue;

			// metafield:custom.material:single_line_text_field
			$spec = substr($key, strlen('metafield:'));
			[$nsKey, $type] = array_pad(explode(':', $spec, 2), 2, 'single_line_text_field');
			[$namespace, $mkey] = array_pad(explode('.', $nsKey, 2), 2, null);
			if (!$namespace || !$mkey) continue;

			$metafields[] = [
				'namespace' => $namespace,
				'key'       => $mkey,
				'type'      => $type,
				'value'     => is_array($value) ? json_encode($value) : (string) $value,
			];
		}
		if ($metafields) {
			$input['metafields'] = $metafields;
		}

        // images → CreateMediaInput[]
        if (!empty($payload['images'])) {
            foreach ((array)$payload['images'] as $img) {
				if (!empty($img['attachment'])) {
					$media[] = [
						'mediaContentType' => 'IMAGE',
						'originalSource'   => $this->makePublicImageUrl($img['attachment']),
					];
				} elseif (!empty($img['src'])) {
					$media[] = [
						'mediaContentType' => 'IMAGE',
						'originalSource'   => $img['src'],
					];
				}
			}
        }

        // options → productOptions [{name, values:[{name}]}]
        if (!empty($payload['options'])) {
            $input['productOptions'] = array_map(fn($o) => [
                'name'   => $o['name'],
                'values' => array_map(fn($v) => ['name' => $v], (array)($o['values'] ?? [])),
            ], $payload['options']);
        }

        // variants
        // if (!empty($payload['variants'])) {
            // $input['variants'] = array_map(fn($v) => $this->toGraphQLVariantInput($v), $payload['variants']);
        // }

        return [$input, $media];
    }
	
	private function makePublicImageUrl(string $base64): string
	{
		// Already URL
		if (filter_var($base64, FILTER_VALIDATE_URL)) {
			return $base64;
		}

		// Raw base64 from Odoo
		$path = 'shopify_images/' . uniqid() . '.jpg';

		\Storage::disk('public')->put($path, base64_decode($base64));

		return asset('storage/' . $path);
	}

    /**
     * Route one template-scope key into ProductInput.
     *
     * Rules (checked in order):
     *  1. 'status'       → strtoupper  (enum: ACTIVE / DRAFT / ARCHIVED)
     *  2. 'tags'         → string → array
     *  3. dot.notation   → deeply nested via dotSet()
     *  4. everything else → passthrough (title, descriptionHtml, vendor, etc.)
     */
    private function applyTemplateKey(array &$input, string $key, mixed $value): void
    {
        switch ($key) {
            case 'status':
                $input['status'] = strtoupper((string)$value);
                return;

            case 'tags':
                $input['tags'] = is_array($value)
                    ? $value
                    : array_values(array_filter(array_map('trim', explode(',', (string)$value))));
                return;
        }

        if (str_contains($key, '.')) {
            $this->dotSet($input, $key, $value);
            return;
        }

        $input[$key] = $value;
    }

    /**
     * Route one variant payload row into GraphQL ProductVariantInput.
     *
     * Rules:
     *  1. option1/2/3           → optionValues[]
     *  2. inventoryPolicy       → top-level, uppercased enum
     *  3. price / compareAtPrice→ top-level, formatted to 2dp string
     *  4. taxable               → top-level, bool
     *  5. inventoryItem.*       → nested under inventoryItem
     *  6. dot-notation          → nested via dotSet()
     *  7. everything else       → passthrough
     */
    private function toGraphQLVariantInput(array $variantPayload): array
    {
        $variant       = [];
        $inventoryItem = [];
        $optionValues  = [];

        // Pre-extract _option_name_N keys before the loop
        $optionNameMap = [];
        foreach ($variantPayload as $k => $v) {
            if (str_starts_with($k, '_option_name_')) {
                $pos = (int) substr($k, strlen('_option_name_'));
                $optionNameMap[$pos] = (string) $v;
            }
        }

        foreach ($variantPayload as $key => $value) {
            if ($value === null || $value === '') continue;

            // Skip internal _option_name_N keys — not sent to Shopify
            if (str_starts_with($key, '_option_name_')) continue;

            // ── option1/2/3 ──────────────────────────────────────────────
            if (in_array($key, ['option1', 'option2', 'option3'], true)) {
                $pos  = (int) substr($key, -1);
                // Use real attribute name (e.g. 'Color') not generic 'Option 1'
                $name = $optionNameMap[$pos] ?? 'Option ' . $pos;
                $optionValues[$pos] = ['name' => $name, 'value' => (string) $value];
                continue;
            }

            // ── inventoryPolicy enum ─────────────────────────────────────
            if ($key === 'inventoryPolicy') {
                $variant['inventoryPolicy'] =
                    self::INVENTORY_POLICIES[strtoupper((string)$value)]
                    ?? self::INVENTORY_POLICIES[strtolower((string)$value)]
                    ?? 'DENY';
                continue;
            }

            // ── price / compareAtPrice ───────────────────────────────────
            if (in_array($key, ['price', 'compareAtPrice'], true)) {
                $variant[$key] = number_format((float)$value, 2, '.', '');
                continue;
            }

            // ── taxable ──────────────────────────────────────────────────
            if ($key === 'taxable') {
                $variant['taxable'] = (bool)$value;
                continue;
            }

            // ── inventoryItem.* ──────────────────────────────────────────
            if (str_starts_with($key, 'inventoryItem.')) {
				$subKey = substr($key, strlen('inventoryItem.'));

				if ($this->isValidInventoryKey($subKey)) {
					$this->applyInventorySubKey($inventoryItem, $subKey, $value);
				} else {
					// auto-promote to variant level (no hardcoding)
					$variant[$subKey] = $value;
				}
				continue;
			}

            // ── other dot-notation (future deep keys) ────────────────────
            if (str_contains($key, '.')) {
                $this->dotSet($variant, $key, $value);
                continue;
            }

            // ── passthrough (future top-level variant fields) ────────────
            $variant[$key] = $value;
        }
		
		// ── Shopify requires full weight object if measurement.weight is present ──
		if (
			isset($inventoryItem['measurement']['weight']['value']) &&
			!isset($inventoryItem['measurement']['weight']['unit'])
		) {
			// Default from config mindset — safe fallback
			$inventoryItem['measurement']['weight']['unit'] = 'KILOGRAMS';
		}

		if (
			isset($inventoryItem['measurement']['weight']['unit']) &&
			!isset($inventoryItem['measurement']['weight']['value'])
		) {
			unset($inventoryItem['measurement']['weight']); // drop incomplete object
		}

        if (!empty($inventoryItem)) {
            $variant['inventoryItem'] = $inventoryItem;
        }

        if (!empty($optionValues)) {
            ksort($optionValues);
            $variant['optionValues'] = array_values($optionValues);
        }

        return $variant;
    }
	
	private function isValidInventoryKey(string $subKey): bool
	{
		return in_array($subKey, self::INVENTORY_SCHEMA, true);
	}

    /**
     * Apply an inventoryItem sub-key (after stripping 'inventoryItem.' prefix).
     *
     * Sub-keys and their handling:
     *   tracked                  → bool
     *   requiresShipping         → bool
     *   measurement.weight.unit  → enum via WEIGHT_UNITS
     *   measurement.weight.value → float
     *   sku, barcode             → passthrough string
     *   any dot sub-key          → nested via dotSet()
     *   anything else            → passthrough
     */
    private function applyInventorySubKey(array &$inventoryItem, string $subKey, mixed $value): void
    {
        switch ($subKey) {
            case 'tracked':
                // Also accepts legacy 'shopify' string from old REST configs
                $inventoryItem['tracked'] = is_bool($value)
                    ? $value
                    : strtolower((string)$value) === 'shopify' || (bool)$value;
                return;

            case 'requiresShipping':
                $inventoryItem['requiresShipping'] = (bool)$value;
                return;

            case 'measurement.weight.unit':
                $unit = self::WEIGHT_UNITS[strtoupper((string)$value)]
                     ?? self::WEIGHT_UNITS[strtolower((string)$value)]
                     ?? 'KILOGRAMS';
                $this->dotSet($inventoryItem, 'measurement.weight.unit', $unit);
                return;

            case 'measurement.weight.value':
                $this->dotSet($inventoryItem, 'measurement.weight.value', (float)$value);
                return;
        }

        // dot sub-nesting or passthrough (sku, barcode, etc.)
        if (str_contains($subKey, '.')) {
            $this->dotSet($inventoryItem, $subKey, $value);
        } else {
            $inventoryItem[$subKey] = $value;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Variant payload builder
    // ─────────────────────────────────────────────────────────────────────

    private function buildVariantPayload(array $variant, array $attributeValues, array $variantConfigs): array
    {
        $avMap = array_column($attributeValues, null, 'id');
        $avIds = $variant['product_template_attribute_value_ids'] ?? [];
        $out   = [];

        foreach ($variantConfigs as $config) {
            if (!$config['is_active']) continue; // inactive → omit

            $value = $this->resolveValue($variant, $config);
            if ($value === null) continue;

            $out[$config['shopify_field']] = $value;
        }

        // Attribute values → option1/2/3
        // Store both the option value AND the attribute name so buildOptions()
        // can use the real attribute name (e.g. 'Color') not generic 'Option 1'
        foreach (array_slice($avIds, 0, 3) as $index => $avId) {
            $av = $avMap[$avId] ?? null;
            if ($av) {
                $pos = $index + 1;
                $out['option' . $pos] = $av['_mapped_name'] ?? $av['name'];
                // Store attribute name for buildOptions() — e.g. 'Color', 'Size'
                $out['_option_name_' . $pos] = is_array($av['attribute_id'])
                    ? $av['attribute_id'][1]
                    : ($av['attribute_id'] ?? 'Option ' . $pos);
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Options builder
    // ─────────────────────────────────────────────────────────────────────

    private function buildOptions(array $attributeValues, array $variants): array
    {
        $options = []; $seen = [];

        foreach ($variants as $variant) {
            foreach (['option1', 'option2', 'option3'] as $i => $k) {
                if (!empty($variant[$k]) && !isset($seen[$i])) {
                    $seen[$i] = true;
                    // Use real attribute name stored by buildVariantPayload
                    $attrName  = $variant['_option_name_' . ($i + 1)] ?? ('Option ' . ($i + 1));
                    $options[$i] = ['name' => $attrName, 'values' => []];
                }
            }
        }

        foreach ($variants as $variant) {
            foreach (['option1', 'option2', 'option3'] as $i => $k) {
                if (isset($options[$i]) && !empty($variant[$k])
                    && !in_array($variant[$k], $options[$i]['values'])) {
                    $options[$i]['values'][] = $variant[$k];
                }
            }
        }

        return array_values(array_filter($options, fn($o) => !empty($o['values'])));
    }

    // ─────────────────────────────────────────────────────────────────────
    // GQL mutation strings
    // ─────────────────────────────────────────────────────────────────────

    private function productCreateMutation(): string
	{
		return <<<'GQL'
		mutation productCreate($product: ProductCreateInput!, $media: [CreateMediaInput!]) {
			productCreate(product: $product, media: $media) {
				product {
					id title handle status
					variants(first: 100) {
						edges { node { id sku price compareAtPrice inventoryItem { id } } }
					}
				}
				userErrors { field message }
			}
		}
		GQL;
	}

	private function productUpdateMutation(): string
	{
		return <<<'GQL'
		mutation productUpdate($input: ProductInput!, $media: [CreateMediaInput!]) {
			productUpdate(input: $input, media: $media) {
				product {
					id title handle status
					variants(first: 100) {
						edges { node { id sku price compareAtPrice inventoryItem { id } } }
					}
				}
				userErrors { field message }
			}
		}
		GQL;
	}

    // ─────────────────────────────────────────────────────────────────────
    // Response normalizer — GQL response → REST-like shape for callers
    // ─────────────────────────────────────────────────────────────────────

    private function normalizeProduct(array $p): array
    {
        $variants = array_map(function ($edge) {
            $v = $edge['node'];
            $inventoryItem = $v['inventoryItem'] ?? [];
            $weight = $inventoryItem['measurement']['weight'] ?? null;
            return [
                'id'                     => $this->fromGid($v['id']),
                'title'                  => $v['title']             ?? null,
                'sku'                    => $v['sku']               ?? '',
                'barcode'                => $v['barcode']           ?? null,
                'price'                  => $v['price']             ?? '0.00',
                'compare_at_price'       => $v['compareAtPrice']    ?? null,
                'inventory_quantity'     => $v['inventoryQuantity'] ?? null,
                'taxable'                => $v['taxable']           ?? null,
                'inventory_policy'       => $v['inventoryPolicy']   ?? null,
                'position'               => $v['position']          ?? null,
                'selected_options'       => $v['selectedOptions']   ?? [],
                'inventory_item_id'      => isset($inventoryItem['id'])
                    ? $this->fromGid($inventoryItem['id']) : null,
                'inventory_tracked'      => $inventoryItem['tracked']              ?? null,
                'requires_shipping'      => $inventoryItem['requiresShipping']     ?? null,
                'harmonized_system_code' => $inventoryItem['harmonizedSystemCode'] ?? null,
                'country_code_of_origin' => $inventoryItem['countryCodeOfOrigin']  ?? null,
                'weight'                 => $weight['value'] ?? null,
                'weight_unit'            => $weight['unit']  ?? null,
            ];
        }, $p['variants']['edges'] ?? []);

        $images = array_map(function ($edge) {
            $img = $edge['node'];
            return [
                'id'       => isset($img['id']) ? $this->fromGid($img['id']) : null,
                'url'      => $img['url']     ?? $img['src'] ?? null,
                'alt_text' => $img['altText'] ?? null,
                'width'    => $img['width']   ?? null,
                'height'   => $img['height']  ?? null,
            ];
        }, $p['images']['edges'] ?? []);

        $metafields = array_map(function ($edge) {
            $m = $edge['node'];
            return [
                'id'        => isset($m['id']) ? $this->fromGid($m['id']) : null,
                'namespace' => $m['namespace'] ?? null,
                'key'       => $m['key']       ?? null,
                'value'     => $m['value']     ?? null,
                'type'      => $m['type']      ?? null,
            ];
        }, $p['metafields']['edges'] ?? []);

        $collections = array_map(function ($edge) {
            $c = $edge['node'];
            return [
                'id'     => isset($c['id']) ? $this->fromGid($c['id']) : null,
                'title'  => $c['title']  ?? null,
                'handle' => $c['handle'] ?? null,
            ];
        }, $p['collections']['edges'] ?? []);

        $options = array_map(function ($opt) {
            return [
                'id'       => isset($opt['id']) ? $this->fromGid($opt['id']) : null,
                'name'     => $opt['name']     ?? null,
                'position' => $opt['position'] ?? null,
                'values'   => $opt['values']   ?? [],
            ];
        }, $p['options'] ?? []);

        return [
            'id'               => $this->fromGid($p['id']),
            'title'            => $p['title']            ?? '',
            'handle'           => $p['handle']           ?? '',
            'status'           => strtolower($p['status'] ?? 'draft'),
            'description_html' => $p['descriptionHtml']  ?? null,
            'vendor'           => $p['vendor']           ?? null,
            'product_type'     => $p['productType']      ?? null,
            'tags'             => $p['tags']             ?? [],
            'template_suffix'  => $p['templateSuffix']   ?? null,
            'published_at'     => $p['publishedAt']      ?? null,
            'online_store_url' => $p['onlineStoreUrl']   ?? null,
            'created_at'       => $p['createdAt']        ?? null,
            'updated_at'       => $p['updatedAt']        ?? null,
            'seo'              => [
                'title'       => $p['seo']['title']       ?? null,
                'description' => $p['seo']['description'] ?? null,
            ],
            'options'     => $options,
            'variants'    => $variants,
            'images'      => $images,
            'metafields'  => $metafields,
            'collections' => $collections,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // GID helpers
    // ─────────────────────────────────────────────────────────────────────

    public function toGid(string $type, string $id): string
    {
        if (str_starts_with($id, 'gid://')) return $id;
        return "gid://shopify/{$type}/{$id}";
    }

    public function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dot-notation setter
    // dotSet($arr, 'a.b.c', 1)  →  $arr['a']['b']['c'] = 1
    // ─────────────────────────────────────────────────────────────────────

    private function dotSet(array &$target, string $key, mixed $value): void
    {
        $parts   = explode('.', $key);
        $current = &$target;
        foreach ($parts as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        $current = $value;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Field config loader
    // ─────────────────────────────────────────────────────────────────────

    private function getFieldConfigs(): array
    {
        $settings   = app(\App\Services\SettingsService::class);
        $ecomDriver = $settings->ecomDriver();  // 'shopify'
        $erpDriver  = $settings->erpDriver();   // 'odoo', 'sap', etc.
        $cacheKey   = "product_field_configs_{$ecomDriver}_{$erpDriver}";

        return Cache::remember($cacheKey, 60, function () use ($ecomDriver, $erpDriver) {
            return ProductFieldConfig::where('ecom_driver', $ecomDriver)
                ->where('erp_driver', $erpDriver)
                ->where('is_active', true)
                // Only the erp→ecom config set. Existing rows are NULL/'erp_to_ecom'
                // and stay included; only the new 'ecom_to_erp' set is excluded.
                ->where(function ($q) {
                    $q->whereNull('direction')
                      ->orWhere('direction', '!=', 'ecom_to_erp');
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn($c) => [
                    // ecom_field is the GraphQL/REST key (renamed from shopify_field)
                    'ecom_field'        => $c->ecom_field     ?? $c->shopify_field,
                    'shopify_field'     => $c->ecom_field     ?? $c->shopify_field, // alias kept for routing rules
                    'field_type'        => $c->field_type,
                    'erp_field'         => $c->erp_field      ?? $c->odoo_field,
                    'odoo_field'        => $c->erp_field      ?? $c->odoo_field,   // alias kept for resolveValue
                    'erp_field_2'       => $c->erp_field_2    ?? $c->odoo_field_2,
                    'odoo_field_2'      => $c->erp_field_2    ?? $c->odoo_field_2, // alias
                    'combine_separator' => $c->combine_separator ?? ' ',
                    'scope'             => $c->scope,
                    'default_value'     => $c->default_value,
                    'transform'         => $c->transform,
                    'min_length'        => $c->min_length,
                    'max_length'        => $c->max_length,
                    'is_active'         => (bool) $c->is_active,
                ])
                ->toArray();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Value resolvers (Odoo → intermediate value)
    // ─────────────────────────────────────────────────────────────────────

    private function resolveValue(array $erpData, array $config): mixed
    {
        if ($config['field_type'] === 'custom') {
            return $config['default_value'] ?? null;
        }

        if ($config['field_type'] === 'combine') {
            $val1  = $this->readErpField($erpData, $config['odoo_field']   ?? '');
            $val2  = $this->readErpField($erpData, $config['odoo_field_2'] ?? '');
            $val1  = ($val1 === false) ? '' : (string)($val1 ?? '');
            $val2  = ($val2 === false) ? '' : (string)($val2 ?? '');
            $sep   = $config['combine_separator'] ?? ' ';
            $value = trim($val1 . ($val1 && $val2 ? $sep : '') . $val2);
            if ($value === '') $value = $config['default_value'] ?? null;
            return $this->applyLengthConstraints($value, $config);
        }

        $raw = $this->readErpField($erpData, $config['odoo_field'] ?? '');
        if ($raw === false) $raw = null;

        $value = $this->applyTransform($raw, $config['transform'], $erpData);
        if ($value === null || $value === false || $value === '') {
            $value = $config['default_value'] ?? null;
        }

        return $this->applyLengthConstraints($value, $config);
    }

    private function readErpField(array $data, string $key): mixed
    {
        if ($key === '') return null;

        if (str_contains($key, '.')) {
            [$parent, $index] = explode('.', $key, 2);
            $parent = $data[$parent] ?? null;
            return is_array($parent) ? ($parent[(int)$index] ?? null) : null;
        }

        return $data[$key] ?? null;
    }
	
	

    private function applyTransform(mixed $value, ?string $transform, array $context = []): mixed
	{
		// Value translation through ChannelMapping: "channel_map:category", "channel_map:warehouse", etc.
		if (is_string($transform) && str_starts_with($transform, 'channel_map:')) {
			$type   = substr($transform, 12);
			$odooId = is_array($value) ? ($value[0] ?? null) : $value;   // categ_id is [2,"Expenses"] → 2
			if ($odooId === null || $odooId === false || $odooId === '') return null;

			return \App\Models\ChannelMapping::query()
				->where('type', $type)
				->whereIn('channel', [
					\App\Models\ChannelMapping::CHANNEL_SHOPIFY,
					\App\Models\ChannelMapping::CHANNEL_BOTH,
				])
				->where('odoo_id', $odooId)
				->where('is_active', true)
				->value('external_id');   // the GID, or null
		}

		return match ($transform) {
			'number_format'          => number_format((float)($value ?? 0), 2, '.', ''),
			'number_format_nullable' => ($value > 0) ? number_format((float)$value, 2, '.', '') : null,
			'boolean_status'         => (!empty($value) || !empty($context['website_published']) || !empty($context['is_published'])) ? 'active' : 'draft',
			'array_second'           => is_array($value) ? ($value[1] ?? null) : $value,
			'base64_image'           => !empty($value) ? [['attachment' => $value]] : null,
			default                  => $value,
		};
	}

    private function applyLengthConstraints(mixed $value, array $config): mixed
    {
        if (!is_string($value)) return $value;
        if ($config['min_length'] && strlen($value) < $config['min_length']) return null;
        if ($config['max_length'] && strlen($value) > $config['max_length']) {
            $value = substr($value, 0, $config['max_length']);
        }
        return $value;
    }
	
	private function recordWire(string $action, string $query, array $variables): void
	{
		$this->wireLog[] = ['action' => $action, 'query' => $query, 'variables' => $variables];
	}

	public function takeWireLog(): array
	{
		$log = $this->wireLog;
		$this->wireLog = [];
		return $log;
	}
}