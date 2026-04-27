<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\MappingService;
use App\Services\Odoo\OdooCustomerService;
use App\Services\Shopify\ShopifyCustomerService;
use Illuminate\Support\Facades\Log;

class CustomerSyncService
{
    public function __construct(
        private readonly OdooCustomerService    $odooCustomers,
        private readonly ShopifyCustomerService $shopifyCustomers,
        private readonly MappingService         $mappings,
    ) {}

    /**
     * Sync a single Odoo partner to Shopify.
     */
    public function syncCustomer(array $odooPartner): string
    {
        $odooId  = (string) $odooPartner['id'];
        $mapping = $this->mappings->findByOdooId(SyncMapping::TYPE_CUSTOMER, $odooId);

        $payload = $this->shopifyCustomers->buildPayload($odooPartner);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_CUSTOMER,
            'entity_id'       => $odooId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            if ($mapping) {
                $shopifyCustomer = $this->shopifyCustomers->update($mapping->shopify_id, $payload);
            } else {
                // Check if customer already exists in Shopify by email
                $email    = $odooPartner['email'] ?? '';
                $existing = $email ? $this->shopifyCustomers->findByEmail($email) : null;

                if ($existing) {
                    $shopifyCustomer = $this->shopifyCustomers->update((string) $existing['id'], $payload);
                } else {
                    $shopifyCustomer = $this->shopifyCustomers->create($payload);
                }
            }

            $shopifyCustomerId = (string) $shopifyCustomer['id'];

            $this->mappings->upsert(SyncMapping::TYPE_CUSTOMER, $odooId, $shopifyCustomerId, [
                'last_synced_at' => now(),
            ]);

            $log->markSuccess(json_encode(['shopify_customer_id' => $shopifyCustomerId]));

            Log::info("Customer synced: Odoo #{$odooId} → Shopify #{$shopifyCustomerId}");

            return $shopifyCustomerId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}
