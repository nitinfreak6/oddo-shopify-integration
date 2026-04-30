<?php

namespace App\Services\Odoo;

class OdooProductService
{
    private const TEMPLATE_FIELDS = [
		'id',
		'name',
		'default_code',
		'barcode',
		'list_price',
		'standard_price',
		'weight',
		'categ_id',
		// Shopify payload fields (used in ShopifyProductService::buildPayload)
		'website_published',
		'description_sale',
		'website_meta_keywords',
		'image_1920',
		'attribute_line_ids',
		'qty_available',
		'virtual_available',
		'sale_ok',
		'active',
		'write_date',
	];

    private const VARIANT_FIELDS = [
		'id',
		'name',
		'default_code',
		'barcode',
		'lst_price',
		'standard_price',
		'weight',
		'product_tmpl_id',
		'active',
		'write_date',
	];

    public function __construct(private readonly OdooService $odoo) {}

    /**
     * Get all products modified since a given write_date.
     */
    public function getModifiedSince(string $writeDate): array
    {
        return $this->odoo->searchRead(
            'product.template',
            [['write_date', '>', $writeDate], ['active', '=', true], ['sale_ok', '=', true]],
            self::TEMPLATE_FIELDS,
            ['order' => 'write_date asc', 'limit' => 500]
        );
    }

    /**
     * Get all active, saleable products (for full sync).
     */
    public function getAllActive(int $offset = 0, int $limit = 100): array
    {
        return $this->odoo->searchRead(
            'product.template',
            [['active', '=', true], ['sale_ok', '=', true]],
            self::TEMPLATE_FIELDS,
            ['order' => 'id asc', 'offset' => $offset, 'limit' => $limit]
        );
    }
	
	/**
 * Get attribute values for a product template as a key=>value map.
 * Returns e.g. ['color' => 'White', 'material' => 'Leather', 'gender' => 'Unisex']
 */
public function getProductAttributes(int $templateId): array
{
    $lines = $this->odoo->searchRead(
        'product.template.attribute.line',
        [['product_tmpl_id', '=', $templateId]],
        ['attribute_id', 'value_ids']
    );

    if (empty($lines)) {
        return [];
    }

    $allValueIds = array_merge(...array_column($lines, 'value_ids'));

    if (empty($allValueIds)) {
        return [];
    }

    $values = $this->odoo->read(
        'product.attribute.value',
        $allValueIds,
        ['id', 'name', 'attribute_id']
    );

    // Index values by id
    $valuesById = [];
    foreach ($values as $v) {
        $valuesById[$v['id']] = $v['name'];
    }

    // Build attribute name => value map
    $result = [];
    foreach ($lines as $line) {
        $attrName = strtolower($line['attribute_id'][1]);
        $firstValueId = $line['value_ids'][0] ?? null;
        if ($firstValueId && isset($valuesById[$firstValueId])) {
            $result[$attrName] = $valuesById[$firstValueId];
        }
    }

    return $result;
}

    /**
     * Get variants for a list of template IDs.
     */
    public function getVariantsForTemplates(array $templateIds): array
    {
        return $this->odoo->searchRead(
            'product.product',
            [['product_tmpl_id', 'in', $templateIds], ['active', '=', true]],
            self::VARIANT_FIELDS
        );
    }

    /**
     * Get attribute values for variants.
     */
    public function getAttributeValues(array $valueIds): array
    {
        return $this->odoo->read(
            'product.template.attribute.value',
            $valueIds,
            ['id', 'name', 'attribute_id', 'product_attribute_value_id']
        );
    }

    /**
     * Get product categories.
     */
    public function getCategory(int $categId): ?array
    {
        $result = $this->odoo->read('product.category', [$categId], ['id', 'name', 'complete_name']);

        return $result[0] ?? null;
    }
}
