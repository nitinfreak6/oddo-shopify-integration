<?php

namespace App\Services\Erp;

/**
 * ErpInterface — the only contract the sync core knows about.
 *
 * Every ERP adapter (Odoo, SAP, NetSuite, …) implements this interface.
 * The sync services, jobs, and artisan commands depend ONLY on this interface,
 * never on a concrete ERP class.
 *
 * Normalized structures returned by normalize*() methods use a flat,
 * ERP-agnostic key set so ShopifyProductService and friends need zero
 * ERP-specific knowledge.
 */
interface ErpInterface
{
    // ── Products ─────────────────────────────────────────────────────────

    /**
     * Return products modified after $writeDate.
     * Must be ordered by write_date ASC, limited to a reasonable page size.
     */
    public function getProductsModifiedSince(string $writeDate): array;

    /**
     * Return all active, saleable products with pagination.
     */
    public function getAllActiveProducts(int $offset = 0, int $limit = 100): array;

    /**
     * Return a single product template by ERP ID (for cache re-fetch).
     */
    public function getProductById(int $erpId): ?array;
	
	
	/**
	 * Read the COMPLETE record for one product (no field whitelist), for the
	 * product detail/info page. Bulk fetches stay lean; this is on-demand.
	 * Adapters with no concept of a reduced field set may return the same as
	 * getProductById().
	 */
	public function getProductByIdFull(int $erpId): ?array;

    /**
     * Return product variants for a list of template IDs.
     */
    public function getVariantsForProducts(array $productIds): array;

    /**
     * Resolve product.template ID from a product.product (variant) ID.
     */
    public function resolveTemplateIdForVariant(int|string $variantId): ?string;

    /**
     * Return attribute values for a list of attribute-value IDs.
     */
    public function getAttributeValues(array $valueIds): array;

    /**
     * Return product category by ID.
     */
    public function getCategory(int $categoryId): ?array;

    // ── Inventory ────────────────────────────────────────────────────────

    /**
     * Return stock quants modified after $writeDate.
     * Each quant must carry at minimum: product_id, location_id, quantity,
     * reserved_quantity, write_date.
     */
    public function getInventoryModifiedSince(string $writeDate, ?int $locationId = null): array;

    /**
     * Return all quants for the given product IDs.
     */
    public function getInventoryForProducts(array $productIds): array;

    /**
     * Calculate the sellable qty from a raw quant record.
     */
    public function availableQty(array $quant): int;

    /**
     * Update stock level in ERP from a config-mapped payload.
     */
    public function updateInventoryLevel(array $payload): void;

    /**
     * Resolve a storable product ID from SKU / reference code.
     */
    public function resolveProductIdByReference(string $reference): ?int;

    /**
     * Get fulfilled/dispatched orders from ERP.
     * Returns stock.picking records in 'done' state linked to sale orders.
     * Used by Fetch Dispatch and Post Dispatch.
     */
    public function getFulfilledOrders(?string $sinceDate = null): array;

    /**
     * Apply e-commerce fulfillment to an Odoo sale order delivery (validate picking).
     *
     * @param  array<string, mixed>  $mappedPayload
     * @param  array<string, mixed>  $sourceFulfillment
     * @return array{picking_id: int, wire: list<array<string, mixed>>}
     */
    public function applyFulfillmentToSaleOrder(int $saleOrderId, array $mappedPayload, array $sourceFulfillment): array;

    // ── Orders ───────────────────────────────────────────────────────────

    /**
     * Return orders modified after $writeDate.
     */
    /**
     * Get orders modified since a specific date.
     * 
     * @param string $writeDate ISO date
     * @param bool $onlyErpOrigin If true, only fetch orders created in ERP (for ERP→Ecom sync)
     */
    public function getOrdersModifiedSince(string $writeDate, bool $onlyErpOrigin = false): array;

    /**
     * Get a single order by ID.
     * 
     * @param int $orderId
     * @return array|null Order data or null if not found
     */
    public function getOrder(int $orderId): ?array;

    /**
     * Return order line records by their IDs.
     */
    public function getOrderLines(array $lineIds): array;

    /**
     * Return delivery / picking records by their IDs.
     */
    public function getPickings(array $pickingIds): array;

    /**
     * Return stock move records by their IDs.
     */
    public function getMoves(array $moveIds, ?array $fields = null): array;

    /**
     * Create an order in the ERP from a normalized order array.
     * Returns the new ERP order ID.
     */
    public function createOrder(array $orderData, array $sourceOrder = []): int;

    /**
     * Update an existing order in the ERP from a mapped payload.
     */
    public function updateOrder(int $orderId, array $orderData, array $sourceOrder = []): bool;

    /**
     * Confirm / approve an order in the ERP.
     */
    public function confirmOrder(int $orderId): bool;

    /**
     * Cancel an order in the ERP.
     */
    public function cancelOrder(int $orderId): bool;

    /**
     * Permanently remove an order from the ERP when allowed (draft/cancelled).
     */
    public function deleteOrder(int $orderId): bool;

    /**
     * Remove a delivery picking / dispatch record from the ERP.
     */
    public function deleteDispatch(int $pickingId): bool;

    /**
     * Remove a product template from the ERP.
     */
    public function deleteProduct(int $productId): bool;

    /**
     * Remove a customer/partner from the ERP.
     */
    public function deleteCustomer(int $customerId): bool;

    // ── Customers ────────────────────────────────────────────────────────

    /**
     * Return customers modified after $writeDate.
     */
    public function getCustomersModifiedSince(string $writeDate): array;

    /**
     * Read one customer/partner by ERP id — full record from the ERP (all model fields).
     */
    public function getCustomer(int $erpId): ?array;

    /**
     * Find a customer by email. Returns null if not found.
     */
    public function findCustomerByEmail(string $email): ?array;

    /**
     * Create a customer in the ERP. Returns the new ERP ID.
     */
    public function createProduct(array $data): int;

    public function createCustomer(array $data): int;

    /**
     * Update a customer in the ERP.
     */
    public function updateCustomer(int $customerId, array $data): bool;

    /**
     * Resolve a country ID from an ISO-2 country code.
     */
    public function resolveCountry(string $iso2): ?int;

    /**
     * Resolve a state / province ID from a country ID and state code.
     */
    public function resolveState(int $countryId, string $code): ?int;

    /**
     * Resolve res.country id from ISO2, numeric id, or country name (field-config transform).
     */
    public function resolveCountryReference(mixed $value): ?int;

    /**
     * Resolve res.country.state id from code/name; $countryReference is ISO2, name, or Odoo country id.
     */
    public function resolveStateReference(mixed $stateValue, mixed $countryReference = null): ?int;

    /**
     * ERP country relation → ISO-2 code for ecom (field-config transform).
     */
    public function resolveCountryCode(mixed $countryReference): ?string;

    /**
     * ERP state relation → province/state code for ecom (field-config transform).
     */
    public function resolveStateCode(mixed $stateReference, mixed $countryReference = null): ?string;

    /**
     * Normalize one product write value for the active ERP (many2one IDs, etc.).
     * Driver-specific — FieldMappingService calls this instead of Odoo-only helpers.
     */
    public function prepareProductWriteValue(string $field, mixed $value): mixed;

    /**
     * Extract a relation/foreign-key ID from mixed ecom or channel-map values.
     */
    public function extractRelationId(mixed $value): int|string|null;

    /**
     * Resolve a partner reference (supplier, customer, …) from a label or external value.
     *
     * @param  string  $role  e.g. supplier, customer
     */
    public function resolvePartnerReference(string $role, mixed $value): int|string|null;

    // ── Normalisation ────────────────────────────────────────────────────

    /**
     * Convert a raw ERP product template into the canonical structure
     * expected by ProductSyncService / ShopifyProductService::buildPayload().
     *
     * Required keys in the returned array:
     *   erp_id, name, sku, barcode, price, cost, weight,
     *   category_id, category_name, description, is_published,
     *   meta_keywords, image_base64, write_date, active, sale_ok
     */
    public function normalizeProduct(array $raw): array;

    /**
     * Convert a raw ERP variant into the canonical variant structure.
     *
     * Required keys: erp_id, name, sku, barcode, price, cost, weight,
     *   template_erp_id, active, write_date,
     *   product_template_attribute_value_ids
     */
    public function normalizeVariant(array $raw): array;

    /**
     * Convert a raw ERP customer into the canonical customer structure.
     *
     * Required keys: erp_id, name, email, phone, street, street2, city,
     *   zip, country_code, state_code, is_company, write_date
     */
    public function normalizeCustomer(array $raw): array;

    /**
     * Convert a raw ERP order into the canonical order structure.
     *
     * Required keys: erp_id, name, state, origin, partner_id,
     *   order_lines, picking_ids, date_order, write_date
     */
    public function normalizeOrder(array $raw): array;

    // ── Field discovery (powers the field-config / mapping menus) ─────────

    /**
     * Return the list of fields this ERP exposes for the given entity type,
     * so the dashboard mapping menus can populate their source dropdowns for
     * ANY driver without driver-specific code in the controllers.
     *
     * Each element: ['key' => string, 'label' => string,
     *                'type' => string (optional), 'scope' => 'header'|'line'].
     *
     * Implementations should catch their own discovery errors and return []
     * rather than throwing, so a connection hiccup degrades the menu instead
     * of breaking it.
     *
     * @return array<int, array{key:string,label:string,type?:string,scope:string}>
     */
    public function getAvailableFields(string $entityType): array;

    // ── Meta ─────────────────────────────────────────────────────────────

    /**
     * A short identifier for this adapter, e.g. "odoo", "sap", "netsuite".
     * Used in log messages and sync_log direction strings.
     */
    public function driverName(): string;
}