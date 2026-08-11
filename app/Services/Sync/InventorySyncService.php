<?php

namespace App\Services\Sync;

use App\Models\ProductFieldConfig;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ChannelMappingService;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\Config\NestedFieldResolver;
use App\Services\FieldMappingService;
use App\Services\MappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * Inventory sync — field-config driven via UniversalSyncService.
 */
class InventorySyncService
{
    public function __construct(
        private readonly ErpInterface         $erp,
        private readonly EcomInterface        $ecom,
        private readonly UniversalSyncService $universalSync,
        private readonly MappingService       $mappings,
        private readonly SettingsService      $settings,
        private readonly ChannelMappingService $channelMappings,
        private readonly FieldMappingService $fieldMapping,
        private readonly NestedFieldResolver $fields,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->isInventorySyncEnabled();
    }

    /**
     * Build the payload stored on fetch and passed to inventory field configs on post.
     * Values are written only at each active ecom_to_erp config's ecom_field path.
     *
     * @param  array<string, mixed>  $shopifyLevel  Row from EcomInterface::getInventoryLevels()
     * @param  array<string, mixed>  $context       Optional fallbacks keyed by exact ecom_field paths
     * @return array<string, mixed>
     */
    public function buildMappingSourcePayload(
        array $shopifyLevel,
        string $shopifyLocationId,
        array $context = []
    ): array {
        $configs = $this->fieldMapping->getInventoryEcomToErpConfigs();
        $source  = $this->buildEcomFetchSource($shopifyLevel);
        $payload = [];

        foreach ($configs as $config) {
            if ($config->field_type === 'custom' && empty(trim($config->erp_field ?? ''))) {
                continue;
            }

            $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($path === '') {
                continue;
            }

            $value = $this->resolveEcomFetchValue($config, $source, $shopifyLocationId, $context);
            if ($this->isStorableFetchValue($value)) {
                $this->fields->set($payload, $path, $this->normalizeFetchScalar($value));
            }
        }

        if (!empty($shopifyLevel['_ecom_graphql_raw']) && is_array($shopifyLevel['_ecom_graphql_raw'])) {
            $payload['_ecom_graphql_raw'] = $shopifyLevel['_ecom_graphql_raw'];
        }

        if (!empty($shopifyLevel['_sync_entity_id'])) {
            $payload['_sync_entity_id'] = (string) $shopifyLevel['_sync_entity_id'];
        }

        return $payload;
    }

    /**
     * Sync entity key for inventory rows (SyncMapping.ecom_id) — internal adapter field, not a business mapping path.
     */
    public function resolveSyncEntityEcomId(array $level, ?SyncMapping $mapping = null): string
    {
        if ($mapping?->ecom_id) {
            return (string) $mapping->ecom_id;
        }

        $syncId = $level['_sync_entity_id'] ?? '';
        if ($syncId !== '') {
            return (string) $syncId;
        }

        return '';
    }

    /** Qty from stored fetch payload using active ecom_to_erp field config ecom_field paths. */
    public function qtyFromStoredPayload(array $stored): int
    {
        foreach ($this->fieldMapping->getInventoryEcomToErpConfigs() as $config) {
            if ($this->isInventoryLocationConfig($config)
                || $this->isInventoryWireOnlyConfig($config)
                || $this->isInventoryProductIdentityConfig($config)) {
                continue;
            }

            if (empty(trim($config->erp_field ?? ''))) {
                continue;
            }

            $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($path === '') {
                continue;
            }

            $value = $this->resolveEcomFieldFromSource($stored, $path);
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return (int) $value;
            }

            if (array_key_exists($path, $stored) && is_numeric($stored[$path])) {
                return (int) $stored[$path];
            }
        }

        return 0;
    }

    /** Shopify location id from stored fetch payload (warehouse config ecom_field path). */
    public function shopifyLocationFromStoredPayload(array $stored): ?string
    {
        return $this->extractShopifyLocationFromStoredLevel(
            $this->stripInternalInventoryKeys($stored)
        );
    }

    /**
     * Exact payload object used by buildEcomToErpInventoryPayload() on post (internal keys removed).
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function payloadForFieldConfig(array $stored): array
    {
        return $this->stripInternalInventoryKeys(
            $this->prepareLevelForPost($stored)
        );
    }

    /**
     * @param  array<string, mixed>  $level
     * @return array<string, mixed>
     */
    public function prepareLevelForPost(array $level): array
    {
        return $this->ensureStoredLevelHasWarehouseLocation($level);
    }

    /**
     * Ensure warehouse ecom_field path is populated before mapping (re-fetch or legacy payloads).
     *
     * @param  array<string, mixed>  $level
     * @return array<string, mixed>
     */
    private function ensureStoredLevelHasWarehouseLocation(array $level): array
    {
        if ($this->extractShopifyLocationFromStoredLevel($level) !== null) {
            return $level;
        }

        $warehouseConfig = $this->fieldMapping->getInventoryEcomToErpConfigs()
            ->first(fn ($c) => $this->isInventoryLocationConfig($c));

        $path = trim($warehouseConfig?->ecom_field ?? $warehouseConfig?->shopify_field ?? '');
        if ($path === '') {
            return $level;
        }

        $defaultLocation = $this->channelMappings->defaultShopifyWarehouseLocationId();
        if ($defaultLocation === null || $defaultLocation === '') {
            return $level;
        }

        $this->fields->set($level, $path, $defaultLocation);

        return $level;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stripInternalInventoryKeys(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Manual Fetch Stock (erp→ecom): load quants for products already synced to e-commerce.
     * Uses mapped warehouse only; bootstraps qty 0 when no stock.quant exists yet (new products).
     *
     * @return list<array<string, mixed>>
     */
    public function collectQuantsForSyncedErpProducts(?string $onlyTemplateErpId = null): array
    {
        $erpDriver = $this->settings->erpDriver();

        $productMappings = SyncMapping::query()
            ->where('entity_type', 'product')
            ->where('erp_driver', $erpDriver)
            ->whereNotNull('erp_id')
            ->whereNotNull('ecom_id')
            ->when($onlyTemplateErpId !== null, fn ($q) => $q->where('erp_id', (string) $onlyTemplateErpId))
            ->get();

        if ($productMappings->isEmpty()) {
            return [];
        }

        $templateToEcom = $productMappings->mapWithKeys(
            fn (SyncMapping $m) => [(string) $m->erp_id => (string) $m->ecom_id]
        );

        $templateIds = $templateToEcom->keys()
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($templateIds === []) {
            return [];
        }

        $variants = $this->erp->getVariantsForProducts($templateIds);

        if ($variants === []) {
            Log::warning('InventorySyncService: no product.product variants for synced templates', [
                'template_ids' => $templateIds,
            ]);

            return [];
        }

        $variantIds        = [];
        $variantToTemplate = [];

        foreach ($variants as $variant) {
            $variantId = (int) ($variant['id'] ?? 0);
            if ($variantId <= 0) {
                continue;
            }

            $templateId = $variant['product_tmpl_id'][0] ?? $variant['product_tmpl_id'] ?? null;
            $variantIds[] = $variantId;
            $variantToTemplate[(string) $variantId] = $templateId !== null ? (string) $templateId : null;
        }

        if ($variantIds === []) {
            return [];
        }

        $warehouseLocationId = $this->channelMappings->defaultWarehouseOdooId();
        $locationInt         = $warehouseLocationId !== null && $warehouseLocationId !== ''
            ? (int) $warehouseLocationId
            : null;

        $mappedLocationIds = $this->channelMappings->activeWarehouseOdooIds();

        $quants = $this->erp->getInventoryForProducts($variantIds);

        if ($locationInt !== null) {
            $quants = $this->filterQuantsByOdooLocation($quants, [$locationInt]);
        } elseif ($mappedLocationIds !== []) {
            $quants = $this->filterQuantsByOdooLocation(
                $quants,
                array_map('intval', $mappedLocationIds)
            );
        }

        $quantByVariant = [];
        foreach ($quants as $quant) {
            $pid = (string) ($quant['product_id'][0] ?? $quant['product_id'] ?? '');
            if ($pid !== '') {
                $quantByVariant[$pid] = $quant;
            }
        }

        $result = [];

        foreach ($variantIds as $variantId) {
            $variantKey = (string) $variantId;
            $templateId = $variantToTemplate[$variantKey] ?? null;

            if (isset($quantByVariant[$variantKey])) {
                $row = $quantByVariant[$variantKey];
                $row['template_erp_id'] = $templateId;
                $row['product_ecom_id'] = $templateId !== null
                    ? ($templateToEcom[$templateId] ?? null)
                    : null;
                $result[] = $this->enrichInventoryQuantData($row);
                continue;
            }

            if ($locationInt === null) {
                continue;
            }

            $result[] = $this->enrichInventoryQuantData([
                'product_id'          => [$variantId, ''],
                'location_id'         => [$locationInt, ''],
                'quantity'            => 0,
                'reserved_quantity'   => 0,
                'available_quantity'  => 0,
                'write_date'          => now()->format('Y-m-d H:i:s'),
                'template_erp_id'     => $templateId,
                'product_ecom_id'     => $templateId !== null
                    ? ($templateToEcom[$templateId] ?? null)
                    : null,
                '_synthetic'          => true,
            ]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $quants
     * @param  list<int>  $locationIds
     * @return list<array<string, mixed>>
     */
    private function filterQuantsByOdooLocation(array $quants, array $locationIds): array
    {
        if ($locationIds === []) {
            return $quants;
        }

        return array_values(array_filter($quants, function (array $quant) use ($locationIds) {
            $loc = $quant['location_id'][0] ?? $quant['location_id'] ?? null;

            return $loc !== null && in_array((int) $loc, $locationIds, true);
        }));
    }

    /** @param array<string, mixed> $quant */
    private function enrichInventoryQuantData(array $quant): array
    {
        $available = (int) max(
            0,
            (float) ($quant['quantity'] ?? 0) - (float) ($quant['reserved_quantity'] ?? 0)
        );

        foreach (['available_quantity', 'qty_available', 'available'] as $alias) {
            if (!array_key_exists($alias, $quant) || $quant[$alias] === null || $quant[$alias] === '') {
                $quant[$alias] = $available;
            }
        }

        return $quant;
    }

    /**
     * Pick the Odoo stock.quant row for a product — prefer active warehouse mappings.
     *
     * @param  list<array<string, mixed>>  $quants
     * @return array<string, mixed>|null
     */
    public function resolveQuantForErpProduct(array $quants, int|string $erpProductId): ?array
    {
        $productQuants = collect($quants)->filter(function (array $q) use ($erpProductId) {
            $pid = $q['product_id'][0] ?? $q['product_id'] ?? null;

            if ($pid !== null && (string) $pid === (string) $erpProductId) {
                return true;
            }

            $templateId = $q['template_erp_id'] ?? null;

            return $templateId !== null && (string) $templateId === (string) $erpProductId;
        });

        if ($productQuants->isEmpty()) {
            return null;
        }

        $mappedOdooLocations = $this->channelMappings->activeWarehouseOdooIds($this->settings->ecomDriver());

        if ($mappedOdooLocations !== []) {
            $mapped = $productQuants->filter(function (array $q) use ($mappedOdooLocations) {
                $loc = $q['location_id'][0] ?? $q['location_id'] ?? null;

                return $loc !== null && in_array((string) $loc, $mappedOdooLocations, true);
            });

            if ($mapped->isNotEmpty()) {
                $productQuants = $mapped;
            }
        }

        return $productQuants
            ->sortByDesc(fn (array $q) => (string) ($q['write_date'] ?? ''))
            ->first();
    }

    /**
     * Shopify location for inventory read/write — uses warehouse channel mapping for the Odoo quant.
     */
    public function resolveShopifyLocationForInventory(?array $quant = null): ?string
    {
        $odooLocationId = null;
        if (is_array($quant)) {
            $odooLocationId = $quant['location_id'][0] ?? $quant['location_id'] ?? null;
            $odooLocationId = $odooLocationId !== null ? (string) $odooLocationId : null;
        }

        return $this->channelMappings->defaultShopifyWarehouseLocationId($odooLocationId);
    }

    /**
     * ERP quant → E-commerce stock level (erp_to_ecom).
     */
    public function syncInventoryToEcom(array $quant, ?SyncMapping $inventoryMapping = null): array
    {
        $erpProductId = (string) ($quant['product_id'][0] ?? $quant['product_id'] ?? '');
        $log = null;

        try {
            if (!$this->isEnabled()) {
                return [];
            }

            $configs = $this->requireFieldConfigs('inventory', 'erp_to_ecom');

            if (!$erpProductId) {
                throw new \InvalidArgumentException('Inventory quant missing product_id');
            }

            $productMapping = $this->resolveProductEcomMapping($erpProductId, $quant);
            if (!$productMapping?->ecom_id) {
                throw new \RuntimeException("No e-commerce product mapping for ERP product #{$erpProductId}");
            }

            $payload = $this->universalSync->buildEcomPayloadForEntity('inventory', $quant, 'default', [
                'product_ecom_id' => $quant['product_ecom_id'] ?? $productMapping->ecom_id,
                'erp_product_id'  => $erpProductId,
                'template_erp_id' => $quant['template_erp_id'] ?? $productMapping->erp_id,
            ]);

            if ($payload === []) {
                throw new \RuntimeException(
                    'Inventory push aborted: empty mapped payload. '
                    . 'Add active erp→ecom inventory field configs and re-run Fetch Stock.'
                );
            }

            $payloadForLog = $payload;

            $log = SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ERP_TO_ECOM,
                'entity_type'     => 'inventory',
                'entity_id'       => $erpProductId,
                'action'          => 'update',
                'status'          => SyncLog::STATUS_PROCESSING,
                'request_payload' => json_encode(
                    $payloadForLog,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);

            $this->ecom->updateInventory($productMapping->ecom_id, 0, null, $payloadForLog);
            $wire = method_exists($this->ecom, 'takeWireLog') ? $this->ecom->takeWireLog() : [];
            $this->persistInventoryWireLog($log, $wire, [
                'driver'  => $this->ecom->driverName(),
                'ecom_id' => $productMapping->ecom_id,
            ], successMessage: 'Inventory pushed via field-config payload', mappedPayload: $payloadForLog);

            $this->persistInventoryPushMetadata($inventoryMapping, $quant, $payloadForLog, $erpProductId);

            $this->markInventorySynced(
                $inventoryMapping,
                $erpProductId,
                null,
                'erp_to_ecom',
                $quant['write_date'] ?? null
            );

            Log::info("InventorySyncService: ERP #{$erpProductId} → ecom #{$productMapping->ecom_id}", [
                'payload' => $payloadForLog,
            ]);

            return ['ecom_id' => $productMapping->ecom_id, 'payload' => $payloadForLog];
        } catch (\Throwable $e) {
            $wire = method_exists($this->ecom, 'takeWireLog') ? $this->ecom->takeWireLog() : [];
            $this->recordInventoryPushFailure(
                $log,
                $erpProductId ?: null,
                null,
                $inventoryMapping,
                SyncLog::DIRECTION_ERP_TO_ECOM,
                $e,
                $wire,
                'update'
            );
            throw $e;
        }
    }

    /**
     * E-commerce inventory level → ERP stock.quant (ecom_to_erp).
     */
    public function syncInventoryToErp(array $level, ?SyncMapping $inventoryMapping = null): array
    {
        $inventoryItemId = $this->resolveSyncEntityEcomId($level, $inventoryMapping);
        $log = null;
        $erpPayload = null;

        try {
            if (!$this->isEnabled()) {
                return [];
            }

            $configs = $this->requireFieldConfigs('inventory', 'ecom_to_erp');
            $this->assertValidInventoryEcomToErpConfigs($configs);

            $level = $this->prepareLevelForPost($level);

            $erpPayload = $this->universalSync->buildErpPayloadForEntity('inventory', $this->payloadForFieldConfig($level), 'default');
            $erpPayload = $this->ensureErpPayloadHasQuantity($erpPayload, $level, $configs);

            $productField = $this->inventoryProductFieldName($configs);
            if (empty($erpPayload[$productField])) {
                throw new \RuntimeException(
                    "Inventory push aborted: {$productField} not in mapped payload. "
                    . 'Enable an inventory ecom → erp field config that maps to product_id (e.g. resolve_product_by_sku).'
                );
            }

            $hasQty = $this->payloadHasMappedQty($erpPayload, $configs, 'ecom_to_erp');

            if (!$hasQty) {
                throw new \RuntimeException(
                    'Inventory push aborted: quantity not in mapped payload. '
                    . 'Enable an inventory ecom → erp field config that maps quantity to the ERP qty field.'
                );
            }

            $odooLocationId = $this->resolveMappedOdooLocationId($erpPayload, $configs, $level);

            if (!$odooLocationId) {
                throw new \RuntimeException(
                    'Inventory push aborted: no Odoo location in mapped payload. '
                    . 'Add an inventory ecom → erp field config with transform channel_map:warehouse on the location field.'
                );
            }

            $locationField = trim($configs->first(fn ($c) => $this->isInventoryLocationConfig($c))?->erp_field ?? 'location_id');
            if ($locationField !== '' && (!array_key_exists($locationField, $erpPayload) || $erpPayload[$locationField] === null || $erpPayload[$locationField] === '' || (string) $erpPayload[$locationField] === '0')) {
                $erpPayload[$locationField] = (int) $odooLocationId;
            }

            $payloadForLog = $erpPayload;

            $log = SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                'entity_type'     => 'inventory',
                'entity_id'       => $inventoryItemId ?: (string) ($erpPayload['product_id'] ?? 'unknown'),
                'action'          => 'update_stock',
                'status'          => SyncLog::STATUS_PROCESSING,
                'request_payload' => json_encode(
                    $payloadForLog,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);

            $this->erp->updateInventoryLevel($erpPayload);
            $wire = method_exists($this->erp, 'takeWireLog') ? $this->erp->takeWireLog() : [];
            $this->persistInventoryWireLog($log, $wire, [
                'driver'     => $this->settings->erpDriver(),
                'product_id' => $erpPayload[$productField],
            ], successMessage: json_encode(['product_id' => $erpPayload[$productField], 'location_id' => $odooLocationId, 'payload' => $erpPayload]), mappedPayload: $payloadForLog);

            $this->markInventorySynced(
                $inventoryMapping,
                null,
                $inventoryItemId ?: null,
                'ecom_to_erp',
                $level['updated_at'] ?? $level['updatedAt'] ?? null
            );

            Log::info("InventorySyncService: ecom inventory → ERP product#{$erpPayload[$productField]}");

            return ['erp_id' => $erpPayload[$productField]];
        } catch (\Throwable $e) {
            $wire = method_exists($this->erp, 'takeWireLog') ? $this->erp->takeWireLog() : [];
            $productField = isset($configs) ? $this->inventoryProductFieldName($configs) : 'product_id';
            $this->recordInventoryPushFailure(
                $log,
                isset($erpPayload[$productField]) ? (string) $erpPayload[$productField] : null,
                $inventoryItemId ?: null,
                $inventoryMapping,
                SyncLog::DIRECTION_ECOM_TO_ERP,
                $e,
                $wire,
                'update_stock'
            );
            throw $e;
        }
    }

    public function syncBatch(array $records, string $direction = 'erp_to_ecom'): array
    {
        $results = [];

        foreach ($records as $record) {
            try {
                $results[] = $direction === 'erp_to_ecom'
                    ? $this->syncInventoryToEcom($record)
                    : $this->syncInventoryToErp($record);
            } catch (\Throwable $e) {
                Log::warning('InventorySyncService: batch item failed', [
                    'error' => $e->getMessage(),
                    'direction' => $direction,
                ]);
            }
        }

        return $results;
    }

    public function getFieldConfigs(string $entityType, string $direction)
    {
        return ProductFieldConfig::query()
            ->where('entity_type', $entityType)
            ->where('ecom_driver', $this->settings->ecomDriver())
            ->where('erp_driver', $this->settings->erpDriver())
            ->where('is_active', true)
            ->when($direction === 'ecom_to_erp', fn ($q) => $q->where(function ($q) {
                $q->where('direction', 'ecom_to_erp')->orWhereNull('direction');
            }))
            ->when($direction === 'erp_to_ecom', fn ($q) => $q->where(function ($q) {
                $q->whereNull('direction')->orWhere('direction', '!=', 'ecom_to_erp');
            }))
            ->ordered()
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, ProductFieldConfig> */
    private function requireFieldConfigs(string $entityType, string $direction): \Illuminate\Support\Collection
    {
        $configs = $this->getFieldConfigs($entityType, $direction);

        if ($configs->isEmpty()) {
            $arrow = $direction === 'ecom_to_erp' ? 'ecom → erp' : 'erp → ecom';
            throw new \RuntimeException(
                "Inventory push aborted: no active field configs for {$arrow}. "
                . 'Enable inventory rows in Field Configuration for this sync direction.'
            );
        }

        return $configs;
    }

    /**
     * Quantity comes ONLY from mapped payload paths defined in active field configs.
     */
    private function ensureErpPayloadHasQuantity(
        array $erpPayload,
        array $level,
        \Illuminate\Support\Collection $configs
    ): array {
        $source = $this->payloadForFieldConfig($level);

        foreach ($configs as $config) {
            if (!$this->isInventoryQuantityConfig($config)) {
                continue;
            }

            $erpField = trim($config->erp_field ?? '');
            if ($erpField === '') {
                continue;
            }

            if (array_key_exists($erpField, $erpPayload) && is_numeric($erpPayload[$erpField])) {
                continue;
            }

            $ecomPath = trim($config->ecom_field ?? $config->shopify_field ?? '');
            $raw      = $ecomPath !== '' ? $this->resolveEcomFieldFromSource($level, $ecomPath) : null;

            if ($raw === null || $raw === '') {
                $raw = $ecomPath !== '' ? $this->resolveEcomFieldFromSource($source, $ecomPath) : null;
            }

            $erpPayload[$erpField] = ($raw !== null && $raw !== '' && is_numeric($raw))
                ? (int) $raw
                : 0;
        }

        return $erpPayload;
    }

    /**
     * Quantity comes ONLY from mapped payload paths defined in active field configs.
     */
    private function resolveMappedQty(array $payload, \Illuminate\Support\Collection $configs, string $direction): int
    {
        foreach ($configs as $config) {
            if (!$this->isInventoryQuantityConfig($config)) {
                continue;
            }

            $path = $this->inventoryPayloadPath($config, $direction);
            if ($path === '') {
                continue;
            }

            if (array_key_exists($path, $payload) && is_numeric($payload[$path])) {
                return (int) $payload[$path];
            }

            $value = $this->fields->get($payload, $path);
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return (int) $value;
            }
        }

        $mappedPaths = $configs
            ->filter(fn ($c) => $this->isInventoryQuantityConfig($c))
            ->map(fn ($c) => $this->inventoryPayloadPath($c, $direction))
            ->filter()
            ->values()
            ->all();

        throw new \RuntimeException(
            'Inventory push aborted: quantity not resolved from field config. '
            . 'Mapped qty path(s) missing or empty in payload: '
            . implode(', ', $mappedPaths ?: ['none'])
            . '. Re-run Fetch Stock after verifying the Odoo source field on each mapping.'
        );
    }

    private function payloadHasMappedQty(array $payload, \Illuminate\Support\Collection $configs, string $direction): bool
    {
        try {
            $this->resolveMappedQty($payload, $configs, $direction);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function isInventoryWireOnlyConfig(ProductFieldConfig $config): bool
    {
        return $config->field_type === 'custom' && empty(trim($config->erp_field ?? ''));
    }

    private function isInventoryLocationConfig(ProductFieldConfig $config): bool
    {
        $transform = strtolower(FieldMappingService::effectiveSystemTransform($config->transform, null) ?? '');

        return $transform === 'channel_map:warehouse';
    }

    private function isInventoryProductIdentityConfig(ProductFieldConfig $config): bool
    {
        $erpField = strtolower(trim($config->erp_field ?? ''));

        if (in_array($erpField, ['product_id', 'id'], true)) {
            return true;
        }

        $transform = strtolower(FieldMappingService::effectiveSystemTransform($config->transform, null) ?? '');

        return in_array($transform, ['resolve_product_by_sku', 'resolve_product_by_reference'], true);
    }

    private function isInventoryQuantityConfig(ProductFieldConfig $config): bool
    {
        if ($this->isInventoryLocationConfig($config)
            || $this->isInventoryWireOnlyConfig($config)
            || $this->isInventoryProductIdentityConfig($config)) {
            return false;
        }

        return FieldMappingService::isInventoryQuantityErpField(trim($config->erp_field ?? ''));
    }

    /**
     * Inventory erp_field names label mapped-payload slots — they are not arbitrary Odoo column names.
     * Odoo stock.quant writes always use inventory_quantity internally.
     */
    private function assertValidInventoryEcomToErpConfigs(\Illuminate\Support\Collection $configs): void
    {
        foreach ($configs as $config) {
            $erpField = trim($config->erp_field ?? '');
            if ($erpField === '') {
                continue;
            }

            if ($this->isInventoryProductIdentityConfig($config)) {
                if (!FieldMappingService::isInventoryProductErpField($erpField)) {
                    throw new \RuntimeException(
                        "Inventory SKU mapping must use erp_field product_id (got \"{$erpField}\")."
                    );
                }
                continue;
            }

            if ($this->isInventoryLocationConfig($config)) {
                if (!FieldMappingService::isInventoryLocationErpField($erpField)) {
                    throw new \RuntimeException(
                        "Inventory warehouse mapping must use erp_field location_id (got \"{$erpField}\")."
                    );
                }
                continue;
            }

            if ($this->isInventoryWireOnlyConfig($config)) {
                continue;
            }

            if (!FieldMappingService::isInventoryQuantityErpField($erpField)) {
                throw new \RuntimeException(
                    "Inventory quantity mapping must use erp_field quantity (got \"{$erpField}\"). "
                    . 'This names the qty slot in the mapped payload; Odoo is updated via inventory_quantity.'
                );
            }
        }

        if (!$configs->contains(fn ($c) => $this->isInventoryQuantityConfig($c))) {
            throw new \RuntimeException(
                'Inventory push requires an active quantity mapping with erp_field quantity '
                . '(e.g. quantities.0.quantity → quantity).'
            );
        }
    }

    private function inventoryProductFieldName(\Illuminate\Support\Collection $configs): string
    {
        $config = $configs->first(fn ($c) => $this->isInventoryProductIdentityConfig($c));

        return trim($config?->erp_field ?? '') ?: 'product_id';
    }

    private function inventoryPayloadPath(ProductFieldConfig $config, string $direction): string
    {
        if ($direction === 'erp_to_ecom') {
            return $this->fieldMapping->resolveConfigWritePath($config);
        }

        return trim($config->erp_field ?? '');
    }

    /**
     * Merge adapter row + flattened GraphQL paths for config-driven value lookup on fetch.
     *
     * @param  array<string, mixed>  $shopifyLevel
     * @return array<string, mixed>
     */
    private function buildEcomFetchSource(array $shopifyLevel): array
    {
        $source = $shopifyLevel;

        $raw = $shopifyLevel['_ecom_graphql_raw'] ?? null;
        if (!is_array($raw)) {
            return $source;
        }

        foreach ($this->flattenNestedArray($raw) as $path => $value) {
            if (!$this->isStorableFetchValue($value)) {
                continue;
            }

            foreach ($this->inventoryFetchPathAliases($path) as $alias) {
                $existing = $this->fields->get($source, $alias);
                if ($existing === null || $existing === '') {
                    $this->fields->set($source, $alias, $value);
                }
            }
        }

        return $source;
    }

    /**
     * GraphQL fetch paths (inventoryLevel.quantities.0.quantity) → field-config paths (quantities.0.quantity).
     *
     * @return list<string>
     */
    private function inventoryFetchPathAliases(string $flatPath): array
    {
        $aliases = [$flatPath];

        if (str_starts_with($flatPath, 'inventoryLevel.')) {
            $aliases[] = substr($flatPath, strlen('inventoryLevel.'));
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Read an ecom_field path from stored/adapter payload, including GraphQL fetch fallbacks.
     */
    private function resolveEcomFieldFromSource(array $source, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $value = $this->fields->get($source, $path);
        if ($this->isStorableFetchValue($value)) {
            return $value;
        }

        $raw = $source['_ecom_graphql_raw'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        foreach ($this->flattenNestedArray($raw) as $flatPath => $flatValue) {
            if (!$this->isStorableFetchValue($flatValue)) {
                continue;
            }

            foreach ($this->inventoryFetchPathAliases($flatPath) as $alias) {
                if ($alias === $path) {
                    return $flatValue;
                }
            }

            if (str_ends_with($flatPath, '.' . $path)) {
                return $flatValue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveEcomFetchValue(
        ProductFieldConfig $config,
        array $source,
        string $shopifyLocationId,
        array $context
    ): mixed {
        $path = trim($config->ecom_field ?? $config->shopify_field ?? '');

        if ($this->isInventoryLocationConfig($config) && $shopifyLocationId !== '') {
            return $shopifyLocationId;
        }

        $value = $this->resolveEcomFieldFromSource($source, $path);
        if ($this->isStorableFetchValue($value)) {
            return $value;
        }

        if ($path !== '' && array_key_exists($path, $context) && $this->isStorableFetchValue($context[$path])) {
            return $context[$path];
        }

        return null;
    }

    private function isStorableFetchValue(mixed $value): bool
    {
        if ($value === 0 || $value === 0.0 || $value === '0') {
            return true;
        }

        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function normalizeFetchScalar(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, 'gid://')) {
            return (string) last(explode('/', $value));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenNestedArray(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && !$this->isListArray($value)) {
                $out = array_merge($out, $this->flattenNestedArray($value, $path));
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    if (is_array($item)) {
                        $out = array_merge($out, $this->flattenNestedArray($item, "{$path}.{$index}"));
                    } else {
                        $out["{$path}.{$index}"] = $item;
                    }
                }

                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }

    private function isListArray(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function resolveProductEcomMapping(string $erpProductId, ?array $quant = null): ?SyncMapping
    {
        $direct = $this->mappings->findByErpId('product_variant', $erpProductId)
            ?? SyncMapping::where('entity_type', 'product')
                ->where('erp_id', $erpProductId)
                ->first();

        if ($direct !== null) {
            return $direct;
        }

        $templateId = $quant['template_erp_id'] ?? null;
        if ($templateId !== null && $templateId !== '') {
            return SyncMapping::where('entity_type', 'product')
                ->where('erp_id', (string) $templateId)
                ->first();
        }

        return $this->resolveProductEcomMappingByVariantId($erpProductId);
    }

    private function resolveProductEcomMappingByVariantId(string $variantProductId): ?SyncMapping
    {
        $templateId = $this->erp->resolveTemplateIdForVariant($variantProductId);
        if ($templateId === null || $templateId === '') {
            return null;
        }

        return SyncMapping::where('entity_type', 'product')
            ->where('erp_id', $templateId)
            ->first();
    }

    /**
     * Resolve Shopify location ID from mapped payload (field config + channel_map:warehouse transform).
     */
    private function resolveMappedLocationId(
        array $payload,
        \Illuminate\Support\Collection $configs,
        array $quant
    ): ?string {
        foreach ($configs as $config) {
            if (!$this->isInventoryLocationConfig($config)) {
                continue;
            }

            $path = $this->inventoryPayloadPath($config, 'erp_to_ecom');
            if ($path !== '') {
                $value = $this->fields->get($payload, $path);
                if ($value !== null && $value !== '' && !is_array($value)) {
                    $strVal = (string) $value;
                    if ($strVal !== '' && $strVal !== '0') {
                        return $strVal;
                    }
                }
            }

            if ($config->field_type === 'custom' && $config->default_value) {
                return (string) $config->default_value;
            }
        }

        $odooLocationId = $this->extractOdooLocationId($quant, $payload);

        if ($odooLocationId !== null) {
            throw new \RuntimeException(
                "Inventory push aborted: no warehouse mapping for Odoo location #{$odooLocationId}. "
                . 'Add Channel Mapping → Warehouse and enable channel_map:warehouse on the location field config.'
            );
        }

        return null;
    }

    private function extractOdooLocationId(array $quant, array $payload): ?string
    {
        foreach ($this->fieldMapping->getInventoryErpToEcomConfigs() as $config) {
            if (!$this->isInventoryLocationConfig($config)) {
                continue;
            }

            $path = trim($config->erp_field ?? '');
            if ($path === '') {
                continue;
            }

            foreach ([$payload, $quant] as $source) {
                $value = $this->fields->get($source, $path);

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_array($value)) {
                    $id = $value[0] ?? null;
                    if ($id !== null && $id !== '' && (string) $id !== '0') {
                        return (string) $id;
                    }

                    continue;
                }

                $strVal = (string) $value;
                if ($strVal !== '' && $strVal !== '0') {
                    return $strVal;
                }
            }
        }

        return null;
    }

    /**
     * Extract Shopify location ID from stored fetch payload at the warehouse config ecom_field path.
     */
    private function extractShopifyLocationFromStoredLevel(array $level): ?string
    {
        foreach ($this->fieldMapping->getInventoryEcomToErpConfigs() as $config) {
            if (!$this->isInventoryLocationConfig($config)) {
                continue;
            }

            $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($path === '') {
                continue;
            }

            $value = $this->fields->get($level, $path);
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $strVal = (string) $this->normalizeFetchScalar($value);

            if ($strVal !== '' && $strVal !== '0') {
                return $strVal;
            }
        }

        return null;
    }

    /**
     * Resolve Odoo location ID from mapped payload (field config + channel_map:warehouse transform).
     */
    private function resolveMappedOdooLocationId(
        array $payload,
        \Illuminate\Support\Collection $configs,
        array $level
    ): ?string {
        foreach ($configs as $config) {
            if (!$this->isInventoryLocationConfig($config)) {
                continue;
            }

            $key = $config->erp_field;
            if ($key && array_key_exists($key, $payload)) {
                $value = $payload[$key];
                if (is_array($value)) {
                    $id = $value[0] ?? null;
                    if ($id !== null && $id !== '' && (string) $id !== '0') {
                        return (string) $id;
                    }

                    continue;
                }

                if ($value !== null && $value !== '' && (string) $value !== '0') {
                    return (string) $value;
                }
            }

            if ($config->field_type === 'custom' && $config->default_value) {
                return (string) $config->default_value;
            }
        }

        $warehouseConfig = $configs->first(fn ($c) => $this->isInventoryLocationConfig($c))
            ?? $this->fieldMapping->getInventoryEcomToErpConfigs()->first(fn ($c) => $this->isInventoryLocationConfig($c));
        $ecomPath          = trim($warehouseConfig?->ecom_field ?? $warehouseConfig?->shopify_field ?? '');
        $shopifyLocationId = $this->extractShopifyLocationFromStoredLevel($level);

        if ($shopifyLocationId !== null) {
            $odooId = $this->channelMappings->resolveWarehouseOdooIdForShopifyLocation($shopifyLocationId)
                ?? $this->channelMappings->odooWarehouse($shopifyLocationId, null);

            if ($odooId !== null && $odooId !== '' && (string) $odooId !== '0') {
                return (string) $odooId;
            }

            $diagnostic = $this->channelMappings->warehouseLookupDiagnostic($shopifyLocationId);
            $pathHint   = $ecomPath !== '' ? "ecom_field \"{$ecomPath}\"" : 'the warehouse location ecom_field';

            throw new \RuntimeException(
                "Inventory push aborted: no warehouse mapping for Shopify location #{$shopifyLocationId}. "
                . "{$diagnostic} Add Mappings → Warehouse with external id {$shopifyLocationId} "
                . "(or gid://shopify/Location/{$shopifyLocationId}) → Odoo stock.location id, "
                . "and set inventory {$pathHint} transform to channel_map:warehouse."
            );
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $wire */
    private function recordInventoryPushFailure(
        ?SyncLog $log,
        ?string $erpId,
        ?string $ecomId,
        ?SyncMapping $mapping,
        string $direction,
        \Throwable $e,
        array $wire = [],
        string $action = 'update'
    ): void {
        if (!$log) {
            SyncLog::create([
                'direction'       => $direction,
                'entity_type'     => 'inventory',
                'entity_id'       => $erpId ?? $ecomId ?? 'unknown',
                'action'          => $action,
                'status'          => SyncLog::STATUS_FAILED,
                'error_message'   => $e->getMessage(),
                'request_payload' => json_encode([
                    'error' => $e->getMessage(),
                    'phase' => 'pre_push',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'synced_at'       => now(),
            ]);
        } else {
            $log->markFailed($e->getMessage());
            if ($wire !== []) {
                $driver = $direction === SyncLog::DIRECTION_ERP_TO_ECOM
                    ? $this->ecom->driverName()
                    : $this->settings->erpDriver();

                $this->persistInventoryWireLog($log, $wire, [
                    'driver' => $driver,
                    'error'  => $e->getMessage(),
                ], failed: true, mappedPayload: $this->extractMappedPayloadFromLog($log));
            }
        }

        $this->markInventoryFailed($mapping, $erpId, $ecomId, $e->getMessage());
    }

    /** @param array<int, array<string, mixed>> $wire */
    private function persistInventoryWireLog(
        SyncLog $log,
        array $wire,
        array $meta,
        ?string $successMessage = null,
        bool $failed = false,
        ?array $mappedPayload = null,
    ): void {
        $mappedPayload ??= $this->extractMappedPayloadFromLog($log);

        if ($wire === []) {
            if ($mappedPayload !== null) {
                $log->update([
                    'request_payload' => json_encode(
                        $mappedPayload,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }
            if ($successMessage !== null && !$failed) {
                $log->markSuccess($successMessage);
            }
            return;
        }

        $apiCalls = array_map(fn ($w) => [
            'action'    => $w['action'] ?? null,
            'query'     => $w['query'] ?? null,
            'variables' => $w['variables'] ?? null,
            'endpoint'  => $w['endpoint'] ?? null,
            'model'     => $w['model'] ?? null,
            'method'    => $w['method'] ?? null,
            'args'      => $w['args'] ?? null,
            'kwargs'    => $w['kwargs'] ?? null,
            'response'  => $w['response'] ?? $w['result'] ?? null,
        ], $wire);

        $responses = array_map(fn ($w) => [
            'action'   => $w['action'] ?? ($w['model'] ?? null),
            'response' => $w['response'] ?? $w['result'] ?? null,
        ], $wire);

        $log->update([
            'status'           => $failed ? SyncLog::STATUS_FAILED : SyncLog::STATUS_SUCCESS,
            'request_payload'  => json_encode(
                array_filter([
                    'mapped_payload' => $mappedPayload,
                    'wire_input'     => $this->extractWireInputFromApiCalls($apiCalls),
                    'api_calls'      => $apiCalls,
                ], fn ($v) => $v !== null && $v !== []),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'response_payload' => json_encode(
                array_merge($meta, [
                    'mutations'  => $responses,
                    'api_calls'  => $apiCalls,
                ]),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'synced_at'        => $failed ? $log->synced_at : now(),
            'error_message'    => $failed ? ($meta['error'] ?? $log->error_message) : null,
        ]);
    }

    /** @param array<int, array<string, mixed>> $apiCalls */
    private function extractWireInputFromApiCalls(array $apiCalls): ?array
    {
        foreach (array_reverse($apiCalls) as $call) {
            if (!empty($call['wire_input']) && is_array($call['wire_input'])) {
                return $call['wire_input'];
            }
        }

        return null;
    }

    private function extractMappedPayloadFromLog(SyncLog $log): ?array
    {
        $existing = json_decode($log->request_payload ?? '', true);
        if (!is_array($existing)) {
            return null;
        }

        if (isset($existing['mapped_payload']) && is_array($existing['mapped_payload'])) {
            return $existing['mapped_payload'];
        }

        if ($this->looksLikeInventoryWireLog($existing)) {
            return null;
        }

        return $existing;
    }

    private function looksLikeInventoryWireLog(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        $first = $payload[array_key_first($payload)] ?? null;

        return is_array($first)
            && (isset($first['query']) || isset($first['model']) || isset($first['action']));
    }

    private function markInventorySynced(
        ?SyncMapping $mapping,
        ?string $erpId,
        ?string $ecomId,
        string $direction,
        ?string $updatedAt = null
    ): void {
        if ($mapping) {
            SyncEntityState::markSynced('inventory', $this->inventoryKeysFromMapping($mapping), $updatedAt);
            return;
        }

        $keys = array_filter([
            'erp_id'      => $erpId,
            'ecom_id'     => $ecomId,
            'erp_driver'  => $direction === 'erp_to_ecom' ? $this->settings->erpDriver() : null,
            'ecom_driver' => $direction === 'ecom_to_erp' ? $this->settings->ecomDriver() : null,
        ]);

        if (count($keys) >= 2) {
            SyncEntityState::markSynced('inventory', $keys, $updatedAt);
        }
    }

    private function markInventoryFailed(?SyncMapping $mapping, ?string $erpId, ?string $ecomId, ?string $message = null): void
    {
        if ($mapping) {
            SyncEntityState::markFailed('inventory', $this->inventoryKeysFromMapping($mapping), $message);
            return;
        }

        $keys = array_filter([
            'erp_id'      => $erpId,
            'ecom_id'     => $ecomId,
            'erp_driver'  => $erpId ? $this->settings->erpDriver() : null,
            'ecom_driver' => $ecomId ? $this->settings->ecomDriver() : null,
        ]);

        if (!empty($keys)) {
            SyncEntityState::markFailed('inventory', $keys, $message);
        }
    }

    /** @return array<string, string> */
    private function inventoryKeysFromMapping(SyncMapping $mapping): array
    {
        return array_filter([
            'erp_id'      => $mapping->erp_id,
            'ecom_id'     => $mapping->ecom_id,
            'erp_driver'  => $mapping->erp_driver,
            'ecom_driver' => $mapping->ecom_driver,
        ]);
    }

    /**
     * Keep stored ERP quant metadata aligned with the Shopify location that was actually pushed.
     *
     * @param  array<string, mixed>  $quant
     * @param  array<string, mixed>  $payload
     */
    private function persistInventoryPushMetadata(
        ?SyncMapping $mapping,
        array $quant,
        array $payload,
        string $erpProductId
    ): void {
        $shopifyLocationId = $this->extractShopifyLocationFromPayload($payload);
        if ($shopifyLocationId === null) {
            return;
        }

        $warehouseConfig = $this->fieldMapping->getInventoryErpToEcomConfigs()
            ->first(fn ($c) => $this->isInventoryLocationConfig($c));
        $erpPath = trim($warehouseConfig?->erp_field ?? '');

        $stored = $quant;
        if ($erpPath !== '') {
            $this->fields->set($stored, $erpPath, $shopifyLocationId);
        }

        SyncPayloadStore::put('inventory', 'erp', $erpProductId, $stored);

        if (!$mapping) {
            return;
        }

        $label = \App\Models\ChannelMapping::ofType(\App\Models\ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel($this->settings->ecomDriver())
            ->active()
            ->where(function ($q) use ($shopifyLocationId) {
                $q->where('external_id', $shopifyLocationId)
                    ->orWhere('external_id', "gid://shopify/Location/{$shopifyLocationId}");
            })
            ->value('external_label');

        if ($label) {
            $stored['shopify_location_label'] = $label;
            SyncPayloadStore::put('inventory', 'erp', $erpProductId, $stored);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function extractShopifyLocationFromPayload(array $payload): ?string
    {
        foreach ($this->fieldMapping->getInventoryErpToEcomConfigs() as $config) {
            if (!$this->isInventoryLocationConfig($config)) {
                continue;
            }

            $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($path === '') {
                continue;
            }

            $value = $this->fields->get($payload, $path);
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $str = (string) $value;

            return str_starts_with($str, 'gid://')
                ? (string) last(explode('/', $str))
                : $str;
        }

        return null;
    }
}
