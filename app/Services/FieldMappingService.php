<?php

namespace App\Services;

use App\Models\ProductFieldConfig;
use App\Models\ChannelMapping;
use App\Services\ChannelMappingService;
use App\Services\Config\NestedFieldResolver;
use App\Services\Config\ValueConditionMapper;
use App\Services\Erp\ErpInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Field Mapping Service - Driver-Agnostic
 * 
 * Handles field mappings between any ERP ↔ Ecom driver pair.
 * 
 * Example usage:
 * 
 * // Get mappings for Shopify ↔ Odoo
 * $mappings = $service->getProductMappings('shopify', 'odoo');
 * 
 * // Get mappings for WooCommerce ↔ NetSuite
 * $mappings = $service->getProductMappings('woocommerce', 'netsuite');
 * 
 * // Map values with conditions in product_field_configs (e.g. 1:ACTIVE, 0:DRAFT)
 */
class FieldMappingService
{
    /** System-only transform markers (seeded rows — not shown in UI). */
    private const SYSTEM_TRANSFORMS = [
        'line_container',
        'skip',
        'synced_customer',
        'image_url_to_base64',
        'resolve_product_by_sku',
        'resolve_fulfillment_line_item_id',
        'resolve_fulfillment_order_id',
        'resolve_country_id',
        'resolve_country_code',
        'array_second',
    ];

    /** Canonical erp_field keys for inventory ecom→erp mapped payload slots. */
    public const INVENTORY_QTY_ERP_FIELDS = ['quantity', 'qty', 'available_quantity'];
    public const INVENTORY_PRODUCT_ERP_FIELDS = ['product_id', 'id'];
    public const INVENTORY_LOCATION_ERP_FIELDS = ['location_id'];

    public static function isInventoryQuantityErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_QTY_ERP_FIELDS, true);
    }

    public static function isInventoryProductErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_PRODUCT_ERP_FIELDS, true);
    }

    public static function isInventoryLocationErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_LOCATION_ERP_FIELDS, true);
    }

    public function __construct(
        private readonly NestedFieldResolver $fields,
        private readonly ValueConditionMapper $conditions,
    ) {}
    /**
     * Get field mappings for a specific driver pair
     * 
     * @param string $entityType 'product', 'order', 'customer', 'inventory'
     * @param string $ecomDriver 'shopify', 'woocommerce', 'magento'
     * @param string $erpDriver 'odoo', 'netsuite', 'sap'
     * @param string|null $scope 'template', 'variant', or null for all
     * @return \Illuminate\Support\Collection
     */
    public function getMappings(
        string $entityType,
        string $ecomDriver,
        string $erpDriver,
        ?string $scope = null,
        ?string $direction = null
    ): \Illuminate\Support\Collection {
        $cacheKey = ProductFieldConfig::cacheKey($entityType, $ecomDriver, $erpDriver);
        
        if ($scope) {
            $cacheKey .= "_{$scope}";
        }
        if ($direction) {
            $cacheKey .= "_{$direction}";
        }
        
        return Cache::rememberForever($cacheKey, function () use ($entityType, $ecomDriver, $erpDriver, $scope, $direction) {
            $query = ProductFieldConfig::active()
                ->forEntity($entityType)
                ->forDriverPair($ecomDriver, $erpDriver)
                ->ordered();
            
            if ($scope) {
                $query->where('scope', $scope);
            }

            if ($direction === 'erp_to_ecom') {
                $query->where(function ($q) {
                    $q->whereNull('direction')->orWhere('direction', '!=', 'ecom_to_erp');
                });
            } elseif ($direction === 'ecom_to_erp') {
                $query->where(function ($q) {
                    $q->where('direction', 'ecom_to_erp')->orWhereNull('direction');
                });
            } elseif ($direction) {
                $query->where('direction', $direction);
            }
            
            return $query->get();
        });
    }

    /**
     * Get template-level mappings (for products, customers)
     */
    public function getTemplateMappings(string $entityType, string $ecomDriver, string $erpDriver): \Illuminate\Support\Collection
    {
        return $this->getMappings($entityType, $ecomDriver, $erpDriver, 'template');
    }

    /**
     * Get variant-level mappings (for product variants)
     */
    public function getVariantMappings(string $ecomDriver, string $erpDriver): \Illuminate\Support\Collection
    {
        return $this->getMappings('product', $ecomDriver, $erpDriver, 'variant');
    }

    /**
     * Build Shopify/ecom product payload from ERP template + variants — 100% field config.
     *
     * ecom_field dot paths nest into GraphQL input (metafields.0.key, inventoryItem.sku, …).
     * No hardcoded metafield: prefixes or field-name routing tables.
     *
     * @param  array<string, mixed>  $erpTemplate
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<int|string, mixed>  $attributeValues
     * @param  array<string, mixed>  $related  Extra Odoo reads kept outside template (e.g. vendors from product.supplierinfo)
     * @return array<string, mixed>
     */
    public function buildErpToEcomProductPayload(
        array $erpTemplate,
        array $variants,
        array $attributeValues,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        array $related = []
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $configs = $this->getMappings('product', $ecomDriver, $erpDriver, null, 'erp_to_ecom');
        if ($configs->isEmpty()) {
            return [];
        }

        $mappingRoot = $this->erpProductMappingRoot($erpTemplate, $related);

        $templateConfigs = $configs->filter(fn ($c) => ($c->scope ?? '') === 'template');
        $variantConfigs  = $configs->filter(fn ($c) => ($c->scope ?? '') === 'variant');

        $payload = [];
        foreach ($templateConfigs as $config) {
            $this->applyErpToEcomConfig($payload, $erpTemplate, $mappingRoot, $config, $ecomDriver);
        }

        $shopifyVariants = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $variantContext = array_merge($variant, ['_attribute_values' => $attributeValues]);
            $variantPayload = [];

            foreach ($variantConfigs as $config) {
                if ($this->isVariantInventoryLocationFieldConfig($config)) {
                    continue;
                }

                $this->applyErpToEcomConfig($variantPayload, $variantContext, $mappingRoot, $config, $ecomDriver);
            }

            if ($variantPayload !== []) {
                $shopifyVariants[] = $variantPayload;
            }
        }

        if ($shopifyVariants !== []) {
            $payload['variants'] = $shopifyVariants;

            $options = $this->aggregateProductOptionsFromFieldConfig($shopifyVariants, $variantConfigs);
            if ($options !== []) {
                $payload['options'] = $options;
            }
        }

        return $payload;
    }

    /**
     * Location for variant stock comes from Channel Mapping → Warehouse, not product field configs.
     */
    private function isVariantInventoryLocationFieldConfig(ProductFieldConfig $config): bool
    {
        $field = strtolower(trim($config->ecom_field ?? $config->shopify_field ?? ''));

        return in_array($field, [
            'inventoryquantities.locationid',
            'inventory_quantities.location_id',
        ], true);
    }

    /**
     * Lookup context for erp_field dot paths — template stays pure Odoo; related data merged only at map time.
     *
     * @param  array<string, mixed>  $related  e.g. ['vendors' => [...]] from product.supplierinfo
     * @return array<string, mixed>
     */
    private function erpProductMappingRoot(array $erpTemplate, array $related): array
    {
        $root = $erpTemplate;

        if (!empty($related['vendors']) && is_array($related['vendors'])) {
            $root['vendors'] = $related['vendors'];
        }

        return $root;
    }

    /**
     * Variant option slots parsed from field-config ecom_field paths ({prefix}.{index}.{leaf}).
     * At each slot, lower sort_order = label leaf, next = value leaf (your two config rows).
     *
     * @return array<int, array{prefix: string, index: int, labelLeaf: string, valueLeaf: string}>
     */
    public function getVariantOptionSlotSpecs(?string $ecomDriver = null, ?string $erpDriver = null): array
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $cacheKey   = "{$ecomDriver}:{$erpDriver}";

        if (isset($this->variantOptionSlotSpecsCache[$cacheKey])) {
            return $this->variantOptionSlotSpecsCache[$cacheKey];
        }

        $variantConfigs = $this->getMappings('product', $ecomDriver, $erpDriver, 'variant', 'erp_to_ecom');
        $this->variantOptionSlotSpecsCache[$cacheKey] = $this->parseVariantOptionSlotSpecs($variantConfigs);

        return $this->variantOptionSlotSpecsCache[$cacheKey];
    }

    /** @var array<string, array<int, array{prefix: string, index: int, labelLeaf: string, valueLeaf: string}>> */
    private array $variantOptionSlotSpecsCache = [];

    /**
     * Build productOptions for productOptionsCreate from variant payloads + field config paths.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    public function aggregateProductOptionsFromFieldConfig(
        array $variants,
        \Illuminate\Support\Collection $variantConfigs
    ): array {
        $specs   = $this->parseVariantOptionSlotSpecs($variantConfigs);
        $options = [];

        foreach ($specs as $spec) {
            $path = "{$spec['prefix']}.{$spec['index']}";

            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $block = $this->fields->get($variant, $path);
                if (!is_array($block)) {
                    continue;
                }

                $optionName = trim((string) ($block[$spec['labelLeaf']] ?? ''));
                $valueName  = trim((string) ($block[$spec['valueLeaf']] ?? ''));

                if ($optionName === '' || $valueName === '') {
                    continue;
                }

                $options[$spec['index']]['name'] ??= $optionName;
                $options[$spec['index']]['values'] ??= [];
                if (!in_array($valueName, $options[$spec['index']]['values'], true)) {
                    $options[$spec['index']]['values'][] = $valueName;
                }
            }
        }

        ksort($options);

        return array_values(array_filter(
            $options,
            fn ($o) => !empty($o['name']) && !empty($o['values'])
        ));
    }

    /**
     * @return array<int, array{prefix: string, index: int, labelLeaf: string, valueLeaf: string}>
     */
    private function parseVariantOptionSlotSpecs(\Illuminate\Support\Collection $variantConfigs): array
    {
        /** @var array<string, list<array{leaf: string, sort_order: int, id: int}>> $slots */
        $slots = [];

        foreach ($variantConfigs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $field = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if (!preg_match('/^(.+)\.(\d+)\.([^.]+)$/', $field, $m)) {
                continue;
            }

            $slotKey = $m[1] . '.' . $m[2];
            $slots[$slotKey][] = [
                'leaf'       => $m[3],
                'sort_order' => (int) ($config->sort_order ?? 0),
                'id'         => (int) $config->id,
            ];
        }

        $specs = [];

        foreach ($slots as $slotKey => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            usort($entries, function (array $a, array $b): int {
                return $a['sort_order'] <=> $b['sort_order'] ?: $a['id'] <=> $b['id'];
            });

            if (!preg_match('/^(.+)\.(\d+)$/', $slotKey, $sm)) {
                continue;
            }

            $specs[] = [
                'prefix'    => $sm[1],
                'index'     => (int) $sm[2],
                'labelLeaf' => $entries[0]['leaf'],
                'valueLeaf' => $entries[1]['leaf'],
            ];
        }

        usort($specs, fn ($a, $b) => $a['index'] <=> $b['index']);

        return $specs;
    }

    /**
     * @return list<string> Unique option container prefixes from field config (e.g. optionValues).
     */
    public function getVariantOptionPrefixes(?string $ecomDriver = null, ?string $erpDriver = null): array
    {
        $prefixes = [];
        foreach ($this->getVariantOptionSlotSpecs($ecomDriver, $erpDriver) as $spec) {
            $prefixes[$spec['prefix']] = true;
        }

        return array_keys($prefixes);
    }

    /**
     * @param  array<string, mixed>  $payload  Flat dot-path keys (ecom_field from config)
     * @param  array<string, mixed>  $source   Row being mapped (template or variant)
     * @param  array<string, mixed>  $root     Full ERP product (fallback context for transforms)
     */
    private function applyErpToEcomConfig(
        array &$payload,
        array $source,
        array $root,
        ProductFieldConfig $config,
        string $ecomDriver
    ): void {
        if (!$config->is_active) {
            return;
        }

        $writePath = $this->resolveEcomWritePath($config);
        if ($writePath === '') {
            return;
        }

        $value = $this->resolveErpToEcomConfigValue($source, $root, $config, $ecomDriver);

        if ($config->field_type === 'custom' && !$this->shouldIncludeMappedValue($value)) {
            if ($this->customFieldBlankSendsExplicitNull($config)) {
                $this->fields->set($payload, $writePath, null);
            }

            return;
        }

        if (!$this->shouldIncludeMappedValue($value)) {
            return;
        }

        $this->fields->set($payload, $writePath, $value);
    }

    /**
     * Blank custom (no transform) → explicit null in payload — required for Shopify 2026-04
     * changeFromQuantity and any other wire field where omit ≠ null.
     */
    private function customFieldBlankSendsExplicitNull(ProductFieldConfig $config): bool
    {
        if ($config->field_type !== 'custom' || trim($config->transform ?? '') !== '') {
            return false;
        }

        $default = trim((string) ($config->default_value ?? ''));

        return $default === ''
            || in_array(strtolower($default), ['empty', 'null', 'none'], true);
    }

    /**
     * Payload path from field config — exactly what you enter in the ecom_field form.
     * Product template scope: bare names become product.{name}. Inventory/default: path used as-is.
     */
    public function resolveConfigWritePath(ProductFieldConfig $config): string
    {
        return $this->resolveEcomWritePath($config);
    }

    private function resolveEcomWritePath(ProductFieldConfig $config): string
    {
        $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
        if ($path === '') {
            return '';
        }

        if (str_contains($path, '.')) {
            return $path;
        }

        // Shopify productCreate: images/variants/options are payload-root keys;
        // images become the separate `media` mutation variable, not product.* fields.
        if (in_array($path, ['images', 'variants', 'options'], true)) {
            return $path;
        }

        if (($config->scope ?? '') === 'template') {
            return 'product.' . $path;
        }

        return $path;
    }

    /** @return 'ecom_to_erp'|'erp_to_ecom' */
    public function configDirection(ProductFieldConfig $config, ?string $override = null): string
    {
        if ($override === 'ecom_to_erp' || $override === 'erp_to_ecom') {
            return $override;
        }

        return ($config->direction ?? '') === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom';
    }

    /**
     * Source field keys for combine rows, direction-aware.
     *
     * @return array{0: string, 1: string}
     */
    public function combineSourceFieldKeys(ProductFieldConfig $config, ?string $direction = null): array
    {
        $direction = $this->configDirection($config, $direction);

        if ($direction === 'ecom_to_erp') {
            return [
                trim($config->ecom_field ?: $config->shopify_field ?: ''),
                trim($config->ecom_field_2 ?: $config->erp_field_2 ?: $config->odoo_field_2 ?: ''),
            ];
        }

        return [
            trim($config->erp_field ?: $config->odoo_field ?: ''),
            trim($config->erp_field_2 ?: $config->odoo_field_2 ?: ''),
        ];
    }

    public function mergeCombinedParts(mixed $val1, mixed $val2, string $separator, ?string $default = null): string
    {
        $s1 = ($val1 === false || $val1 === null) ? '' : trim((string) $val1);
        $s2 = ($val2 === false || $val2 === null) ? '' : trim((string) $val2);
        $sep = $separator !== '' ? $separator : ' ';
        $combined = trim($s1 . ($s1 !== '' && $s2 !== '' ? $sep : '') . $s2);

        if ($combined === '' && $default !== null && $default !== '') {
            return (string) $default;
        }

        return $combined;
    }

    /**
     * Resolve a combine field config value for either sync direction.
     */
    public function resolveCombineValue(
        ProductFieldConfig $config,
        array $source,
        array $root,
        ?string $direction = null
    ): string {
        $direction = $this->configDirection($config, $direction);
        [$field1, $field2] = $this->combineSourceFieldKeys($config, $direction);
        $sep = (string) ($config->combine_separator ?? ' ');

        if ($direction === 'ecom_to_erp') {
            $val1 = $this->readEcomField($source, $root, $field1);
            $val2 = $this->readEcomField($source, $root, $field2);
        } else {
            $val1 = $this->readSourceField($source, $root, $field1);
            $val2 = $this->readSourceField($source, $root, $field2);
        }

        return $this->mergeCombinedParts($val1, $val2, $sep, $config->default_value);
    }

    /** Target ERP field for combine/custom rows (ecom→erp). */
    public function combineErpTargetField(ProductFieldConfig $config): string
    {
        return trim($config->erp_field ?: $config->odoo_field ?: '');
    }

    private function resolveErpToEcomConfigValue(
        array $source,
        array $root,
        ProductFieldConfig $config,
        string $ecomDriver
    ): mixed {
        if ($config->field_type === 'custom') {
            $transform = trim($config->transform ?? '');
            if ($transform !== '' && $transform !== 'skip') {
                $value = $this->applySystemTransform(
                    null,
                    $config->transform,
                    array_merge($root, $source),
                    $ecomDriver,
                    'erp_to_ecom'
                );

                if ($config->default_value === '__NULL__') {
                    return null;
                }

                if ($this->shouldIncludeMappedValue($value)) {
                    return $this->applyLengthConstraints($value, $config);
                }

                // Transform configured but returned nothing — never fall back to placeholder text.
                return null;
            }

            return $this->resolveCustomDefaultValue($config);
        }

        if ($config->field_type === 'combine') {
            $value = $this->resolveCombineValue($config, $source, $root, 'erp_to_ecom');
            $value = $this->conditions->apply($value, $config->conditions);
            $value = $this->applySystemTransform(
                $value,
                $config->transform,
                array_merge($root, $source),
                $ecomDriver,
                'erp_to_ecom'
            );
            $value = $this->shapeEcomOutput($value, $config);
            if ($value === null || $value === '' || $value === false) {
                $value = $config->default_value;
            }

            return $this->applyLengthConstraints($value, $config);
        }

        $erpField = $config->erp_field ?? $config->odoo_field ?? '';
        $raw      = $this->readSourceField($source, $root, $erpField);

        $raw = $this->conditions->apply($raw, $config->conditions);

        $value = $this->applySystemTransform(
            $raw,
            $config->transform,
            array_merge($root, $source),
            $ecomDriver,
            'erp_to_ecom'
        );

        $value = $this->shapeEcomOutput($value, $config);

        if ($value === null || $value === '' || $value === false) {
            $value = $config->default_value;
        }

        return $this->applyLengthConstraints($value, $config);
    }

    /** Read erp_field dot path from scope row, then root product. */
    private function readSourceField(array $source, array $root, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        $value = $this->fields->get($source, $key);
        if ($value !== null || $source === $root) {
            return $value;
        }

        return $this->fields->get($root, $key);
    }

    /**
     * Build ecommerce payload from ERP data
     * 
     * @param array $erpData Normalized ERP data
     * @param string $ecomDriver Target ecommerce platform
     * @param string $erpDriver Source ERP system
     * @param string $scope 'template' or 'variant'
     * @return array Ecommerce-ready payload
     */
    public function buildEcomPayload(
        array $erpData,
        string $ecomDriver,
        string $erpDriver,
        string $scope = 'template',
        string $entityType = 'product',
        ?array $rootOverride = null
    ): array {
        $mappings = $this->getMappings($entityType, $ecomDriver, $erpDriver, $scope, 'erp_to_ecom');
        $payload  = [];
        $root     = $rootOverride ?? $erpData;

        foreach ($mappings as $mapping) {
            $this->applyErpToEcomConfig($payload, $erpData, $root, $mapping, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Extract ERP data from ecommerce payload
     * 
     * @param array $ecomData Ecommerce platform data
     * @param string $ecomDriver Source ecommerce platform
     * @param string $erpDriver Target ERP system
     * @param string $scope 'template' or 'variant'
     * @return array ERP-ready data
     */
    public function extractErpData(
        array $ecomData,
        string $ecomDriver,
        string $erpDriver,
        string $scope = 'template'
    ): array {
        $mappings = $this->getMappings('product', $ecomDriver, $erpDriver, $scope);
        $erpData = [];
        
        foreach ($mappings as $mapping) {
            $ecomField = $mapping->ecom_field;
            $erpField = $mapping->erp_field;
            
            // Skip if no ERP field (ecom-only field)
            if (empty($erpField)) {
                continue;
            }
            
            // Get value from ecom data
            $value = $ecomData[$ecomField] ?? null;

            if ($value !== null && !empty($mapping->conditions)) {
                $value = $this->conditions->apply($value, $mapping->conditions);
            }

            if ($value !== null && self::effectiveSystemTransform($mapping->transform, $mapping->reverse_transform)) {
                $value = $this->applySystemTransform(
                    $value,
                    self::effectiveSystemTransform($mapping->transform, $mapping->reverse_transform),
                    $ecomData,
                    $ecomDriver,
                    'ecom_to_erp'
                );
            }

            $value = $this->shapeErpOutput($value, $mapping);
            
            $erpData[$erpField] = $value;
        }
        
        return $erpData;
    }

    /**
     * Build a complete ERP payload from a raw ecom entity, driven entirely by
     * product_field_configs rows where direction = 'ecom_to_erp'.
     *
     * Mirror of ShopifyProductService::buildPayload() + resolveValue():
     * reads ecom_field (dot paths, arrays), applies conditions, writes erp_field.
     */
    public function buildErpProductPayload(
        array $ecomProduct,
        string $ecomDriver,
        string $erpDriver,
        string $entityType = 'product'
    ): array {
        $configs = $this->getMappings($entityType, $ecomDriver, $erpDriver, null, 'ecom_to_erp');

        if ($configs->isEmpty()) {
            return [];
        }

        $erp           = $this->resolveErpAdapter($erpDriver);
        $firstVariant  = $this->firstScopedLine($ecomProduct);
        $templateConfigs = $configs->filter(fn ($c) => $c->scope === 'template');
        $variantConfigs  = $configs->filter(fn ($c) => $c->scope === 'variant');
        $otherConfigs    = $configs->filter(fn ($c) => !in_array($c->scope, ['template', 'variant'], true));

        $payload = [];

        foreach ($templateConfigs as $config) {
            $this->applyEcomToErpConfig($payload, $ecomProduct, $ecomProduct, $config, $erp, $ecomDriver);
        }

        foreach ($variantConfigs as $config) {
            $this->applyEcomToErpConfig($payload, $firstVariant, $ecomProduct, $config, $erp, $ecomDriver);
        }

        foreach ($otherConfigs as $config) {
            $source = ($config->scope === 'variant') ? $firstVariant : $ecomProduct;
            $this->applyEcomToErpConfig($payload, $source, $ecomProduct, $config, $erp, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Ecom → ERP customer payload from product_field_configs (scope=default).
     */
    public function buildErpCustomerPayload(
        array $ecomCustomer,
        string $ecomDriver,
        string $erpDriver
    ): array {
        $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, 'default', 'ecom_to_erp');

        if ($configs->isEmpty()) {
            $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, 'header', 'ecom_to_erp');
        }

        if ($configs->isEmpty()) {
            return [];
        }

        $erp     = $this->resolveErpAdapter($erpDriver);
        $payload = [];

        foreach ($configs as $config) {
            $this->applyEcomToErpConfig($payload, $ecomCustomer, $ecomCustomer, $config, $erp, $ecomDriver);
        }

        return $this->enrichCustomerErpPayload($payload, $ecomCustomer, $configs);
    }

    /**
     * Build Ecom→ERP payload from an arbitrary config collection (orders, dispatch, etc.).
     *
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $configs
     * @return array<string, mixed>
     */
    public function buildGenericEcomToErpPayload(
        \Illuminate\Support\Collection $configs,
        array $source,
        array $rootEntity,
        string $ecomDriver,
        string $erpDriver
    ): array {
        if ($configs->isEmpty()) {
            return [];
        }

        $erp     = $this->resolveErpAdapter($erpDriver);
        $payload = [];

        foreach ($configs as $config) {
            $this->applyEcomToErpConfig($payload, $source, $rootEntity, $config, $erp, $ecomDriver);
        }

        return $payload;
    }

    /**
     * ERP → Ecom inventory payload — same rules as product: ecom_field path, erp_field source.
     *
     * @param  array<string, mixed>  $quant  Normalized Odoo stock.quant row
     * @return array<string, mixed>
     */
    public function buildErpToEcomInventoryPayload(
        array $quant,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        array $pushContext = []
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $root = array_merge($quant, $pushContext);

        return $this->buildEcomPayload($quant, $ecomDriver, $erpDriver, 'default', 'inventory', $root);
    }

    /**
     * Active inventory erp→ecom field configs for GraphQL wire building.
     *
     * @return \Illuminate\Support\Collection<int, ProductFieldConfig>
     */
    public function getInventoryErpToEcomConfigs(?string $ecomDriver = null, ?string $erpDriver = null): \Illuminate\Support\Collection
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        return $this->getMappings('inventory', $ecomDriver, $erpDriver, 'default', 'erp_to_ecom');
    }

    /**
     * Build a synthetic Odoo quant row for non-inventory callers (e.g. product variant sync).
     * Values are assigned only via active inventory field configs (erp_field + transform).
     *
     * @return array<string, mixed>
     */
    public function buildSyntheticInventoryQuant(int $quantity, string $locationNumericId): array
    {
        $quant = [];

        foreach ($this->getInventoryErpToEcomConfigs() as $config) {
            $erpField = trim($config->erp_field ?? '');
            if ($erpField === '' || $config->field_type === 'custom') {
                continue;
            }

            $transform = self::effectiveSystemTransform($config->transform, null);
            if ($transform === 'channel_map:warehouse') {
                $quant[$erpField] = $locationNumericId;
                continue;
            }

            if (!array_key_exists($erpField, $quant)) {
                $quant[$erpField] = $quantity;
            }
        }

        return $quant;
    }

    /**
     * Ecom → ERP inventory payload — flat erp_field keys (product_id, quantity, location_id, …).
     * Warehouse mapping via field config transform (e.g. channel_map:warehouse).
     *
     * @param  array<string, mixed>  $level  Normalized Shopify inventory level row
     * @return array<string, mixed>
     */
    public function buildEcomToErpInventoryPayload(
        array $level,
        ?string $ecomDriver = null,
        ?string $erpDriver = null
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $erp        = app(ErpInterface::class);

        $configs = $this->getMappings('inventory', $ecomDriver, $erpDriver, 'default', 'ecom_to_erp');

        if ($configs->isEmpty()) {
            return [];
        }

        $payload = [];

        foreach ($configs as $config) {
            if (empty($config->erp_field)) {
                continue;
            }

            $this->applyEcomToErpConfig($payload, $level, $level, $config, $erp, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Active inventory ecom→erp field configs for Odoo wire building.
     *
     * @return \Illuminate\Support\Collection<int, ProductFieldConfig>
     */
    public function getInventoryEcomToErpConfigs(?string $ecomDriver = null, ?string $erpDriver = null): \Illuminate\Support\Collection
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        return $this->getMappings('inventory', $ecomDriver, $erpDriver, 'default', 'ecom_to_erp');
    }

    /**
     * ERP → Ecom customer payload — keys match Shopify GraphQL CustomerInput paths.
     */
    public function buildErpToEcomCustomerPayload(
        array $erpPartner,
        ?string $ecomDriver = null,
        ?string $erpDriver = null
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, 'default', 'erp_to_ecom');

        if ($configs->isEmpty()) {
            $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, 'header', 'erp_to_ecom');
        }

        if ($configs->isEmpty()) {
            return [];
        }

        $payload = [];

        foreach ($configs as $config) {
            $this->applyErpToEcomConfig($payload, $erpPartner, $erpPartner, $config, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Fill gaps only for ERP fields that active configs map — no hardcoded defaults.
     *
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $configs
     */
    public function enrichCustomerErpPayload(
        array $payload,
        array $ecomData,
        \Illuminate\Support\Collection $configs
    ): array {
        $mappedErpFields = $configs
            ->map(fn ($config) => $config->erp_field ?: $config->odoo_field ?: '')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (in_array('name', $mappedErpFields, true) && empty($payload['name'])) {
            $first = $this->readEcomField($ecomData, $ecomData, 'firstName')
                ?? $this->readEcomField($ecomData, $ecomData, 'first_name');
            $last  = $this->readEcomField($ecomData, $ecomData, 'lastName')
                ?? $this->readEcomField($ecomData, $ecomData, 'last_name');
            $name  = trim(trim((string) ($first ?? '')) . ' ' . trim((string) ($last ?? '')));

            if ($name !== '') {
                $payload['name'] = $name;
            } elseif (!empty($ecomData['email'])) {
                $payload['name'] = (string) $ecomData['email'];
            }
        }

        if (in_array('email', $mappedErpFields, true) && empty($payload['email'])) {
            $email = $this->readEcomField($ecomData, $ecomData, 'email');
            if ($email !== null && $email !== '') {
                $payload['email'] = (string) $email;
            }
        }

        return $payload;
    }

    private function resolveErpAdapter(string $erpDriver): ErpInterface
    {
        $active = app(SettingsService::class)->erpDriver();
        if ($erpDriver === $active) {
            return app(ErpInterface::class);
        }

        $class = app(ConnectorRegistry::class)->adapterClass($erpDriver);
        if (!$class || !is_subclass_of($class, ErpInterface::class)) {
            throw new \RuntimeException("No ERP adapter registered for driver [{$erpDriver}].");
        }

        return app($class);
    }

    /** First line/variant row — common container keys across ecom drivers. */
    private function firstScopedLine(array $entity): array
    {
        foreach (['variants', 'items', 'lines', 'skus'] as $key) {
            $line = $entity[$key][0] ?? null;
            if (is_array($line)) {
                return $line;
            }
        }

        return [];
    }

    private function applyEcomToErpConfig(
        array &$payload,
        array $source,
        array $rootEntity,
        ProductFieldConfig $config,
        ErpInterface $erp,
        string $ecomDriver
    ): void {
        if (!$config->is_active) {
            return;
        }

        $rawErpField = $config->erp_field ?: $config->odoo_field;
        if (empty($rawErpField)) {
            return;
        }

        $value = $this->resolveEcomConfigValue($source, $rootEntity, $config, $erp, $ecomDriver);

        if ($config->field_type === 'custom' && !$this->shouldIncludeMappedValue($value)) {
            if ($this->customFieldBlankSendsExplicitNull($config)) {
                $this->assignErpPayloadField($payload, $rawErpField, null);
            }

            return;
        }

        if (!$this->shouldIncludeMappedValue($value)) {
            return;
        }

        $writeKey = preg_match('/^(.+)\.(\d+)$/', $rawErpField, $m) ? $m[1] : $rawErpField;

        $value = $erp->prepareProductWriteValue($writeKey, $value);
        if ($value === null) {
            return;
        }

        $this->assignErpPayloadField($payload, $rawErpField, $value);
    }

    private function resolveEcomConfigValue(
        array $source,
        array $rootEntity,
        ProductFieldConfig $config,
        ErpInterface $erp,
        string $ecomDriver
    ): mixed {
        if ($config->field_type === 'custom') {
            return $config->default_value;
        }

        if ($config->field_type === 'combine') {
            $value = $this->resolveCombineValue($config, $source, $rootEntity, 'ecom_to_erp');
            $value = $this->conditions->apply($value, $config->conditions);
            $value = $this->applySystemTransform(
                $value,
                self::effectiveSystemTransform($config->transform, $config->reverse_transform),
                $rootEntity,
                $ecomDriver,
                'ecom_to_erp',
                $erp
            );
            $value = $this->shapeErpOutput($value, $config);
            $value = $this->resolveMany2OneFromEcomLabel(
                $config->erp_field ?: $config->odoo_field ?: '',
                $value,
                $ecomDriver
            );
            if ($value === null || $value === '' || $value === false) {
                $value = $config->default_value;
            }

            return $this->applyLengthConstraints($this->normalizeScalarEcomValue($value, $config), $config);
        }

        $ecomField = $config->ecom_field ?: $config->shopify_field ?: '';
        $raw       = $this->readEcomField($source, $rootEntity, $ecomField);
        if ($raw === false) {
            $raw = null;
        }

        $raw = $this->conditions->apply($raw, $config->conditions);

        $value = $this->applySystemTransform(
            $raw,
            self::effectiveSystemTransform($config->transform, $config->reverse_transform),
            $rootEntity,
            $ecomDriver,
            'ecom_to_erp',
            $erp
        );

        $erpField = trim($config->erp_field ?: $config->odoo_field ?: '');
        if ($erpField === 'location_id' && ($value === null || $value === '' || $this->looksLikeShopifyLocationId($value))) {
            $shopifyLoc = $this->warehouseChannelMapLookupValue($raw ?? $value);
            if ($shopifyLoc !== '') {
                $resolved = app(ChannelMappingService::class)->resolveWarehouseOdooIdForShopifyLocation($shopifyLoc);
                if ($resolved !== null && $resolved !== '') {
                    $value = is_numeric($resolved) ? (int) $resolved : $resolved;
                }
            }
        }

        $value = $this->shapeErpOutput($value, $config);

        $value = $this->resolveMany2OneFromEcomLabel(
            $config->erp_field ?: $config->odoo_field ?: '',
            $value,
            $ecomDriver
        );

        if ($value === null || $value === '' || $value === false) {
            $value = $config->default_value;
        }

        return $this->applyLengthConstraints($this->normalizeScalarEcomValue($value, $config), $config);
    }

    /**
     * Ecom list fields (tags, etc.) → string for scalar ERP fields.
     * Multi-relation fields (*_ids) keep arrays for the ERP adapter to validate.
     */
    private function normalizeScalarEcomValue(mixed $value, ProductFieldConfig $config): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $erpField = $config->erp_field ?: $config->odoo_field ?: '';
        if ($this->looksLikeMultiRelationField($erpField)) {
            return $value;
        }

        $parts = array_values(array_filter(array_map(
            fn ($v) => trim(is_scalar($v) ? (string) $v : ''),
            $value
        )));

        return implode(', ', $parts);
    }

    /** ERP convention for many-relation fields (Odoo *_ids, etc.). */
    private function looksLikeMultiRelationField(string $field): bool
    {
        return str_ends_with($field, '_ids');
    }

    /**
     * Read an ecom field from scope source.
     *
     * Fallback order:
     * 1. Current scope (template row → product root, variant row → variants[0])
     * 2. Opposite container (variant scope → product root for shared fields)
     * 3. First variant line when reading from product root (Shopify sku/price/barcode live on variants)
     */
    private function readEcomField(array $source, array $rootEntity, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        if (preg_match('/^metafields\.([^.]+)\.([^.]+)$/', $key, $m)) {
            $byKey = $this->readMetafieldValue($rootEntity, $m[1], $m[2]);
            if ($byKey !== null) {
                return $byKey;
            }
        }

        foreach ($this->ecomFieldLookupKeys($key) as $lookupKey) {
            $raw = $this->readNestedField($source, $lookupKey);
            if ($raw !== null) {
                return $raw;
            }

            if ($source !== $rootEntity) {
                $raw = $this->readNestedField($rootEntity, $lookupKey);
                if ($raw !== null) {
                    return $raw;
                }
            }

            $line = $this->firstScopedLine($rootEntity);
            if ($line !== []) {
                $fromLine = $this->readNestedField($line, $lookupKey);
                if ($fromLine !== null) {
                    return $fromLine;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $rootEntity */
    private function readMetafieldValue(array $rootEntity, string $namespace, string $metaKey): mixed
    {
        $items = $rootEntity['metafields'] ?? null;

        if (!is_array($items) || $items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['namespace'] ?? '') === $namespace && ($item['key'] ?? '') === $metaKey) {
                $value = $item['value'] ?? null;

                return ($value === false) ? null : $value;
            }
        }

        return null;
    }

    /**
     * Paths from product_field_configs.ecom_field — exact path first, then generic
     * snake_case ↔ camelCase per segment (legacy rows only). No entity-specific aliases.
     *
     * @return list<string>
     */
    private function ecomFieldLookupKeys(string $key): array
    {
        if ($key === '') {
            return [];
        }

        $variants = [$key];

        if (str_contains($key, '.')) {
            $variants[] = $this->ecomPathWithSegmentCasing($key, 'camel');
            $variants[] = $this->ecomPathWithSegmentCasing($key, 'snake');
        } else {
            $variants[] = $this->segmentToCamelCase($key);
            $variants[] = $this->segmentToSnakeCase($key);
        }

        foreach ($this->shopifyEcomFieldAliases($key) as $alias) {
            $variants[] = $alias;
            if (str_contains($alias, '.')) {
                $variants[] = $this->ecomPathWithSegmentCasing($alias, 'camel');
                $variants[] = $this->ecomPathWithSegmentCasing($alias, 'snake');
            }
        }

        return array_values(array_unique(array_filter($variants, fn ($v) => $v !== '')));
    }

    /**
     * Shopify GraphQL read paths that differ from common field-config labels.
     *
     * @return list<string>
     */
    private function shopifyEcomFieldAliases(string $key): array
    {
        $aliases = [];

        $segmentMap = [
            '.countryCode'   => ['.countryCodeV2', '.country_code'],
            '.country_code'  => ['.countryCodeV2', '.countryCode'],
            '.countryCodeV2' => ['.countryCode', '.country_code'],
            '.provinceCode'  => ['.province_code', '.province'],
            '.province_code' => ['.provinceCode', '.province'],
        ];

        foreach ($segmentMap as $from => $replacements) {
            if (!str_contains($key, $from)) {
                continue;
            }

            foreach ($replacements as $to) {
                $aliases[] = str_replace($from, $to, $key);
            }
        }

        if (str_starts_with($key, 'addresses.')) {
            $aliases[] = 'defaultAddress.' . substr($key, strlen('addresses.'));
        }

        return $aliases;
    }

    /** @return list<string> */
    private function ecomPathWithSegmentCasing(string $path, string $mode): string
    {
        $segments = explode('.', $path);

        return implode('.', array_map(function (string $segment) use ($mode) {
            if ($segment === '' || ctype_digit($segment)) {
                return $segment;
            }

            return $mode === 'camel'
                ? $this->segmentToCamelCase($segment)
                : $this->segmentToSnakeCase($segment);
        }, $segments));
    }

    private function segmentToCamelCase(string $segment): string
    {
        if ($segment === '' || !str_contains($segment, '_')) {
            return $segment;
        }

        return lcfirst(str_replace('_', '', ucwords($segment, '_')));
    }

    private function segmentToSnakeCase(string $segment): string
    {
        if ($segment === '' || str_contains($segment, '_')) {
            return $segment;
        }

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $segment) ?? $segment);
    }

    /**
     * Resolve ecom labels (category fullName, etc.) to ERP many2one IDs via channel_mappings
     * when no explicit channel_map transform is configured on the row.
     */
    private function resolveMany2OneFromEcomLabel(string $erpField, mixed $value, string $ecomDriver): mixed
    {
        $writeKey = preg_match('/^(.+)\.(\d+)$/', $erpField, $m) ? $m[1] : $erpField;
        if (!$writeKey || !str_ends_with($writeKey, '_id') || str_ends_with($writeKey, '_ids')) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '' || is_numeric(trim($value))) {
            return $value;
        }

        $mapType = match ($writeKey) {
            'categ_id' => 'category',
            default    => null,
        };

        if ($mapType === null) {
            return $value;
        }

        $mapped = $this->applyChannelMapToErp($mapType, $value, $ecomDriver);

        return ($mapped !== null && $mapped !== '') ? $mapped : $value;
    }

    /**
     * Read nested ecom values — dot paths only (metafields.0.value, seo.title, …).
     */
    private function readNestedField(array $data, string $key): mixed
    {
        return $this->fields->get($data, $key);
    }

    /**
     * ERP many2one read paths like categ_id.1 are for display; writes use the base field.
     */
    private function assignErpPayloadField(array &$payload, string $erpField, mixed $value): void
    {
        $writeKey = preg_match('/^(.+)\.(\d+)$/', $erpField, $m) ? $m[1] : $erpField;
        if ($writeKey === '') {
            return;
        }
        $payload[$writeKey] = $value;
    }

    private function shouldIncludeMappedValue(mixed $value): bool
    {
        if ($value === 0 || $value === 0.0 || $value === '0') {
            return true;
        }

        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        if (is_string($value) && in_array(strtolower(trim($value)), ['empty', 'null', 'none'], true)) {
            return false;
        }

        return true;
    }

    private function resolveCustomDefaultValue(ProductFieldConfig $config): mixed
    {
        if ($config->default_value === '__NULL__') {
            return null;
        }

        $default = trim((string) ($config->default_value ?? ''));
        if ($default === '' || in_array(strtolower($default), ['empty', 'null', 'none'], true)) {
            return null;
        }

        return $this->applyLengthConstraints($default, $config);
    }

    private function applyLengthConstraints(mixed $value, ProductFieldConfig $config): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($config->min_length && strlen($value) < $config->min_length) {
            return null;
        }
        if ($config->max_length && strlen($value) > $config->max_length) {
            $value = substr($value, 0, $config->max_length);
        }
        return $value;
    }

    /**
     * System-only transforms (channel maps, line_container, partner resolution, …).
     * Value mapping uses the conditions column instead.
     *
     * @param  array<string, mixed>  $context
     */
    public function applySystemTransform(
        mixed $value,
        ?string $transform,
        array $context = [],
        ?string $ecomDriver = null,
        string $direction = 'erp_to_ecom',
        ?ErpInterface $erp = null,
    ): mixed {
        $transform = trim($transform ?? '');
        if ($transform === '') {
            return $value;
        }

        if ($transform === 'skip') {
            return null;
        }

        if ($transform === 'line_container') {
            return $value;
        }

        $ecomDriver = $ecomDriver ?? app(SettingsService::class)->ecomDriver();
        $erp        = $erp ?? app(ErpInterface::class);

        if (str_starts_with($transform, 'channel_map:')) {
            $type = strtolower(substr($transform, 12));

            return $direction === 'erp_to_ecom'
                ? $this->applyChannelMapToEcom($type, $value, $ecomDriver)
                : $this->applyChannelMapToErp($type, $value, $ecomDriver);
        }

        if (str_starts_with($transform, 'resolve_partner:')) {
            return $this->resolvePartnerForErp(substr($transform, 16), $value, $erp, $ecomDriver);
        }

        if ($transform === 'image_url_to_base64') {
            return $this->transformImageUrlToBase64($value);
        }

        if ($transform === 'resolve_product_by_sku') {
            return $erp->resolveProductIdByReference((string) $value);
        }

        if ($transform === 'resolve_fulfillment_line_item_id') {
            $orderId = (string) ($context['_push']['ecom_order_id'] ?? $context['_ecom_order_id'] ?? '');
            if ($orderId === '') {
                return null;
            }

            $productRef = $value ?? ($context['product_id'] ?? null);

            return app(\App\Services\Shopify\ShopifyFulfillmentService::class)
                ->resolveFulfillmentOrderLineItemId($orderId, $productRef);
        }

        if ($transform === 'resolve_fulfillment_order_id') {
            $orderId = (string) ($value ?? $context['_push']['ecom_order_id'] ?? $context['_ecom_order_id'] ?? '');
            if ($orderId === '') {
                return null;
            }

            return app(\App\Services\Shopify\ShopifyFulfillmentService::class)
                ->resolveFulfillmentOrderId($orderId);
        }

        if ($transform === 'resolve_inventory_item_id') {
            return $this->resolveInventoryItemIdForPush($context, $ecomDriver);
        }

        if (str_starts_with($transform, 'sync_mapping:')) {
            $entityType = trim(substr($transform, strlen('sync_mapping:')));

            return $this->resolveSyncMappingTransform($entityType, $context, $direction, $ecomDriver, $value);
        }

        if ($transform === 'resolve_country_id') {
            return $erp->resolveCountryReference($value);
        }

        if (str_starts_with($transform, 'resolve_state_id')) {
            $countryPath = str_contains($transform, ':')
                ? trim(substr($transform, strlen('resolve_state_id:')))
                : '';

            $countryRef = $countryPath !== ''
                ? $this->readEcomField($context, $context, $countryPath)
                : null;

            return $erp->resolveStateReference($value, $countryRef);
        }

        if ($transform === 'resolve_country_code') {
            return $erp->resolveCountryCode($value);
        }

        if (str_starts_with($transform, 'resolve_state_code')) {
            $countryPath = str_contains($transform, ':')
                ? trim(substr($transform, strlen('resolve_state_code:')))
                : '';

            $countryRef = $countryPath !== ''
                ? $this->readSourceField($context, $context, $countryPath)
                : null;

            return $erp->resolveStateCode($value, $countryRef);
        }

        if ($transform === 'array_second') {
            if (is_array($value) && array_key_exists(1, $value)) {
                return $value[1];
            }

            return $value;
        }

        return $value;
    }

    public static function isSystemTransform(?string $transform): bool
    {
        $transform = trim($transform ?? '');
        if ($transform === '') {
            return false;
        }

        if (in_array($transform, self::SYSTEM_TRANSFORMS, true)) {
            return true;
        }

        return str_starts_with($transform, 'channel_map:')
            || str_starts_with($transform, 'resolve_partner:')
            || str_starts_with($transform, 'resolve_state_id')
            || str_starts_with($transform, 'resolve_state_code')
            || in_array($transform, ['resolve_country_id', 'resolve_country_code', 'array_second', 'resolve_fulfillment_line_item_id', 'resolve_fulfillment_order_id'], true);
    }

    public static function effectiveSystemTransform(?string $transform, ?string $reverseTransform = null): ?string
    {
        foreach ([trim($reverseTransform ?? ''), trim($transform ?? '')] as $candidate) {
            if ($candidate !== '' && self::isSystemTransform($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Post-condition shaping for ERP → Ecom (tags array, base64 images, …). */
    public function shapeEcomOutput(mixed $value, ProductFieldConfig $config): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $field = strtolower($config->ecom_field ?? $config->shopify_field ?? '');

        if (str_contains($field, 'tags') && is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        if ((str_contains($field, 'image') || str_contains($field, 'media'))
            && is_string($value)
            && $value !== ''
            && !filter_var($value, FILTER_VALIDATE_URL)
        ) {
            return [['attachment' => $value, 'alt' => 'Product Image']];
        }

        return $value;
    }

    /** Post-condition shaping for Ecom → ERP (many2one id, image URL fetch, …). */
    public function shapeErpOutput(mixed $value, ProductFieldConfig $config): mixed
    {
        $erpField = $config->erp_field ?? $config->odoo_field ?? '';

        if (str_ends_with($erpField, '_id') && is_array($value) && array_key_exists(0, $value)) {
            $id = $value[0];

            return is_numeric($id) ? (int) $id : $id;
        }

        if (preg_match('/^image_\d+$/', $erpField)
            && is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL)
        ) {
            return $this->transformImageUrlToBase64($value);
        }

        return $value;
    }

    /**
     * Channel map — ecom external value → ERP ID (from channel_mappings table).
     */
    private function resolveInventoryItemIdForPush(array $context, string $ecomDriver): ?string
    {
        $mappedId = $this->resolveSyncMappingTransform('inventory', $context, 'erp_to_ecom', $ecomDriver);
        if ($mappedId !== null && $mappedId !== '') {
            return (string) $mappedId;
        }

        $productEcomId = (string) (
            $context['_push']['product_ecom_id']
            ?? $context['product_ecom_id']
            ?? ''
        );

        if ($productEcomId === '') {
            $erpProductId = (string) ($context['product_id'][0] ?? $context['product_id'] ?? $context['_push']['erp_product_id'] ?? '');
            if ($erpProductId !== '') {
                $productMapping = app(MappingService::class)->findByErpId('product', $erpProductId)
                    ?? app(MappingService::class)->findByErpId('product_variant', $erpProductId);
                $productEcomId = (string) ($productMapping?->ecom_id ?? '');
            }
        }

        if ($productEcomId === '') {
            return null;
        }

        $ids = app(\App\Services\Shopify\ShopifyInventoryService::class)->resolveInventoryItemIdsForProduct($productEcomId);

        return $ids[0] ?? null;
    }

    /** @param  array<string, mixed>  $context */
    private function resolveSyncMappingTransform(
        string $entityType,
        array $context,
        string $direction,
        string $ecomDriver,
        mixed $sourceValue = null,
    ): mixed {
        if ($entityType === '') {
            return null;
        }

        if ($direction === 'erp_to_ecom') {
            $erpId = (string) (
                $context['_push']['erp_product_id']
                ?? $context['product_id'][0]
                ?? $context['product_id']
                ?? $context['erp_id']
                ?? ''
            );

            if ($erpId === '') {
                return null;
            }

            $mapping = app(MappingService::class)->findByErpId($entityType, $erpId);

            return $mapping?->ecom_id;
        }

        $ecomId = (string) ($sourceValue ?? $context['inventory_item_id'] ?? $context['ecom_id'] ?? $context['id'] ?? '');
        if ($ecomId === '') {
            return null;
        }

        $mapping = app(MappingService::class)->findByEcomId($entityType, $ecomId, $ecomDriver);

        return $mapping?->erp_id;
    }

    /**
     * Channel map — ecom external value → ERP ID (from channel_mappings table).
     */
    private function applyChannelMapToErp(string $type, mixed $value, string $ecomDriver): mixed
    {
        $type = strtolower(trim($type));

        if ($type === 'warehouse') {
            $lookup = $this->warehouseChannelMapLookupValue($value);
            if ($lookup === '') {
                return null;
            }

            $mapped = app(ChannelMappingService::class)->resolveWarehouseOdooIdForShopifyLocation($lookup)
                ?? app(ChannelMappingService::class)->odooWarehouse($lookup, $ecomDriver)
                ?? app(ChannelMappingService::class)->odooWarehouse($lookup, null);
            if ($mapped !== null && $mapped !== '') {
                return is_numeric($mapped) ? (int) $mapped : $mapped;
            }

            return null;
        }

        $candidates = $this->channelMapErpCandidates($type, $value);

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $mapped = ChannelMapping::query()
                ->ofType($type)
                ->forChannel($ecomDriver)
                ->active()
                ->where(function ($q) use ($candidate) {
                    $q->where('external_id', $candidate)
                      ->orWhere('external_label', $candidate);
                })
                ->value('odoo_id');

            if ($mapped !== null && $mapped !== '') {
                return is_numeric($mapped) ? (int) $mapped : $mapped;
            }
        }

        return null;
    }

    private function warehouseChannelMapLookupValue(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['id', 'external_id', 0, 1] as $key) {
                if (!array_key_exists($key, $value)) {
                    continue;
                }

                $candidate = trim(is_scalar($value[$key]) ? (string) $value[$key] : '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return '';
        }

        return ($value !== null && $value !== '' && $value !== false)
            ? trim((string) $value)
            : '';
    }

    private function looksLikeShopifyLocationId(mixed $value): bool
    {
        if ($value === null || $value === '' || is_array($value)) {
            return false;
        }

        $str = trim((string) $value);

        if (str_starts_with($str, 'gid://shopify/Location/')) {
            return true;
        }

        return ctype_digit($str) && strlen($str) >= 5;
    }

    /**
     * Build lookup keys for channel_map (ecom side → Odoo id).
     * Supports Shopify TaxonomyCategory objects and "A > B > C" fullName paths.
     */
    private function channelMapErpCandidates(string $type, mixed $value): array
    {
        if (is_array($value)) {
            $raw = array_values(array_filter([
                $value[0] ?? null,
                $value[1] ?? null,
                $value['id'] ?? null,
                $value['external_id'] ?? null,
                $value['fullName'] ?? null,
                $value['name'] ?? null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false));
        } else {
            $raw = ($value !== null && $value !== '' && $value !== false) ? [(string) $value] : [];
        }

        $candidates = [];
        foreach ($raw as $item) {
            $candidates[] = (string) $item;
            if ($type === 'warehouse') {
                if (str_starts_with((string) $item, 'gid://')) {
                    $numeric = (string) last(explode('/', (string) $item));
                    if ($numeric !== '') {
                        $candidates[] = $numeric;
                    }
                } elseif (ctype_digit((string) $item)) {
                    $candidates[] = "gid://shopify/Location/{$item}";
                }
            }
            if ($type === 'category' && str_contains((string) $item, '>')) {
                $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/', (string) $item) ?: [])));
                if (!empty($parts)) {
                    $candidates[] = end($parts);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * resolve_partner:{role} — channel map first, then ERP adapter lookup/create.
     */
    private function resolvePartnerForErp(string $role, mixed $value, ErpInterface $erp, string $ecomDriver): mixed
    {
        $label = trim(is_array($value) ? (string) ($value[1] ?? $value[0] ?? '') : (string) $value);
        if ($label === '') {
            return null;
        }

        $mapped = $this->applyChannelMapToErp('vendor', $label, $ecomDriver);
        if ($mapped !== null && $mapped !== '') {
            return $erp->extractRelationId($mapped) ?? $mapped;
        }

        return $erp->resolvePartnerReference($role, $label);
    }

    /** ERP ID → ecom external id (e.g. Odoo categ → Shopify Taxonomy GID). */
    private function applyChannelMapToEcom(string $type, mixed $value, string $ecomDriver): mixed
    {
        $erpId = is_array($value) ? ($value[0] ?? null) : $value;
        if ($erpId === null || $erpId === false || $erpId === '') {
            return null;
        }

        $mapped = ChannelMapping::query()
            ->where('type', $type)
            ->whereIn('channel', [
                ChannelMapping::CHANNEL_SHOPIFY,
                ChannelMapping::CHANNEL_BOTH,
                $ecomDriver,
            ])
            ->where('odoo_id', (string) $erpId)
            ->where('is_active', true)
            ->value('external_id');

        if (($mapped === null || $mapped === '') && $type === 'warehouse') {
            $mapped = app(ChannelMappingService::class)->shopifyWarehouse((string) $erpId);
        }

        if (($mapped === null || $mapped === '') && $type === 'warehouse') {
            $mapped = $this->passThroughShopifyWarehouseExternalId((string) $erpId, $ecomDriver);
        }

        return $mapped;
    }

    /**
     * Product variant sync may already supply a Shopify location id — not an Odoo location id.
     */
    private function passThroughShopifyWarehouseExternalId(string $value, string $ecomDriver): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $candidates = [$value];
        if (str_starts_with($value, 'gid://')) {
            $numeric = (string) last(explode('/', $value));
            if ($numeric !== '') {
                $candidates[] = $numeric;
            }
        } else {
            $candidates[] = "gid://shopify/Location/{$value}";
        }

        $externalId = ChannelMapping::query()
            ->where('type', 'warehouse')
            ->whereIn('channel', [
                ChannelMapping::CHANNEL_SHOPIFY,
                ChannelMapping::CHANNEL_BOTH,
                $ecomDriver,
            ])
            ->where('is_active', true)
            ->whereIn('external_id', $candidates)
            ->value('external_id');

        if ($externalId === null || $externalId === '') {
            return null;
        }

        return str_starts_with((string) $externalId, 'gid://')
            ? (string) last(explode('/', (string) $externalId))
            : (string) $externalId;
    }

    /**
     * Download a Shopify (or other) image URL and encode for Odoo image_1920 writes.
     */
    private function transformImageUrlToBase64(mixed $value): ?string
    {
        $url = $this->resolveImageSourceUrl($value);
        if ($url === null) {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return is_string($value) && $value !== '' ? $value : null;
        }

        try {
            $response = Http::timeout(30)->get($url);
            if (!$response->successful()) {
                Log::warning("image_url_to_base64: HTTP {$response->status()} for {$url}");
                return null;
            }

            $body = $response->body();
            if ($body === '') {
                return null;
            }

            return base64_encode($body);
        } catch (\Throwable $e) {
            Log::warning("image_url_to_base64: {$e->getMessage()}");
            return null;
        }
    }

    /** @param mixed $value URL string, image node array, or images list */
    private function resolveImageSourceUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['url']) && is_string($value['url']) && $value['url'] !== '') {
            return $value['url'];
        }

        if (isset($value[0])) {
            return $this->resolveImageSourceUrl($value[0]);
        }

        return null;
    }

    /**
     * Set nested value in array using dot notation
     */
    private function setNestedValue(array &$array, string $path, $value): void
    {
        $this->fields->set($array, $path, $value);
    }

    /**
     * Check if a driver pair has any mappings configured
     */
    public function hasMappings(string $entityType, string $ecomDriver, string $erpDriver): bool
    {
        return $this->getMappings($entityType, $ecomDriver, $erpDriver)->isNotEmpty();
    }

    /**
     * Clear all cached mappings
     */
    public function clearCache(): void
    {
        Cache::flush(); // Or use specific cache tags if available
    }
}