<?php

namespace App\Services\Sync;

use App\Models\ProductFieldConfig;
use App\Services\ChannelMappingService;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * OrderSyncService - Orchestrates order sync using UniversalSyncService
 */
class OrderSyncService
{
    public function __construct(
        private readonly ErpInterface           $erp,
        private readonly EcomInterface          $ecom,
        private readonly UniversalSyncService   $universalSync,
        private readonly MappingService         $mappings,
        private readonly ChannelMappingService  $channelMappings,
        private readonly SettingsService        $settings,
    ) {}

    public function isErpToEcom(): bool
    {
        return $this->settings->orderSyncMode() === 'erp_to_ecom';
    }

    public function isEcomToErp(): bool
    {
        return $this->settings->orderSyncMode() === 'ecom_to_erp';
    }

    /**
     * Sync ERP order/sales_order to ecommerce
     */
    /**
     * Load a full ERP order with enriched order lines ready for field mapping.
     */
    public function prepareErpOrderForSync(int|string $erpId, ?array $fallback = null): array
    {
        $order = (is_array($fallback) && $fallback !== []) ? $fallback : [];

        if ((string) $erpId !== '') {
            $fresh = $this->erp->getOrder((int) $erpId);
            if (is_array($fresh) && $fresh !== []) {
                $order = $fresh;
            }
        }

        return $this->enrichErpOrderLines($order);
    }

    public function syncErpOrderToEcom(array $erpOrder): string
    {
        if ($this->isEcomToErp()) {
            throw new \LogicException('syncErpOrderToEcom() is for ERP → Ecom direction.');
        }

        $erpId    = (string) ($erpOrder['id'] ?? '');
        $erpOrder = $this->prepareErpOrderForSync($erpId, $erpOrder);

        try {
            $result = $this->universalSync->syncFromErpToEcom(
                entityType: 'sales_order',
                erpData: $erpOrder,
                scope: 'header'
            );

            $ecomId = $result['id'] ?? $result['ecom_id'] ?? null;
            Log::info("OrderSyncService: synced ERP order #{$erpId} → ecommerce #{$ecomId}");
            return (string) $ecomId;
        } catch (\Throwable $e) {
            Log::error("OrderSyncService: ERP→Ecom sync failed for #{$erpId}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Odoo list views often store order_line as IDs only — fetch full line records before mapping.
     */
    private function enrichErpOrderLines(array $erpOrder): array
    {
        $lines = $erpOrder['order_line'] ?? [];

        if ($lines === [] || !is_array($lines)) {
            return $erpOrder;
        }

        $first = reset($lines);
        if (is_array($first)) {
            return $erpOrder;
        }

        $lineIds = array_values(array_filter(
            $lines,
            fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v))
        ));

        if ($lineIds === []) {
            return $erpOrder;
        }

        $erpOrder['order_line'] = $this->erp->getOrderLines($lineIds);

        return $erpOrder;
    }

    /**
     * Sync ecommerce order to ERP (create draft order in ERP)
     */
    public function syncEcomOrderToErp(array $ecomOrder): int
    {
        if ($this->isErpToEcom()) {
            throw new \LogicException('syncEcomOrderToErp() is for Ecom → ERP direction.');
        }

        try {
            $result = $this->universalSync->syncFromEcomToErp(
                entityType: 'sales_order',
                ecomData: $ecomOrder,
                scope: 'header'
            );

            $erpId = $result['id'] ?? $result['erp_id'] ?? null;
            Log::info("OrderSyncService: synced ecommerce order → ERP #{$erpId}");
            return (int) $erpId;
        } catch (\Throwable $e) {
            Log::error("OrderSyncService: Ecom→ERP sync failed", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync order items (line items)
     */
    public function syncOrderItems(array $order, string $direction = 'erp_to_ecom'): array
    {
        $syncedItems = [];

        foreach ($order['items'] ?? [] as $item) {
            try {
                if ($direction === 'erp_to_ecom') {
                    $result = $this->universalSync->syncFromErpToEcom(
                        entityType: 'sales_order_line',
                        erpData: $item,
                        scope: 'line'
                    );
                } else {
                    $result = $this->universalSync->syncFromEcomToErp(
                        entityType: 'sales_order_line',
                        ecomData: $item,
                        scope: 'line'
                    );
                }

                $syncedItems[] = $result;
            } catch (\Throwable $e) {
                Log::warning("OrderSyncService: failed to sync order item", [
                    'error' => $e->getMessage(),
                    'item_id' => $item['id'] ?? null,
                ]);
            }
        }

        return $syncedItems;
    }

    public function getFieldConfigs(string $entityType, string $ecomDriver, string $erpDriver)
    {
        return ProductFieldConfig::query()
            ->where('entity_type', $entityType)
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->where('is_active', true)
            ->ordered()
            ->get();
    }
}