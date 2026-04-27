<?php

namespace App\Services\Odoo;

class OdooOrderService
{
    private const ORDER_FIELDS = [
        'id', 'name', 'client_order_ref', 'partner_id', 'partner_invoice_id',
        'partner_shipping_id', 'order_line', 'state', 'date_order', 'currency_id',
        'amount_total', 'amount_tax', 'write_date', 'origin', 'note', 'tag_ids',
        'picking_ids',
    ];

    private const LINE_FIELDS = [
        'id', 'product_id', 'product_uom_qty', 'qty_delivered', 'price_unit',
        'price_subtotal', 'name', 'discount',
    ];

    private const PICKING_FIELDS = [
        'id', 'name', 'state', 'carrier_tracking_ref', 'carrier_id',
        'date_done', 'move_ids', 'origin', 'sale_id',
    ];

    public function __construct(private readonly OdooService $odoo) {}

    /**
     * Get confirmed/done Odoo orders modified since write_date.
     */
    public function getModifiedSince(string $writeDate): array
    {
        return $this->odoo->searchRead(
            'sale.order',
            [
                ['write_date', '>', $writeDate],
                ['state', 'in', ['sale', 'done', 'cancel']],
                ['origin', 'like', 'Shopify'],
            ],
            self::ORDER_FIELDS,
            ['order' => 'write_date asc', 'limit' => 200]
        );
    }

    /**
     * Get order lines for a list of order IDs.
     */
    public function getOrderLines(array $lineIds): array
    {
        return $this->odoo->read('sale.order.line', $lineIds, self::LINE_FIELDS);
    }

    /**
     * Get picking/delivery records.
     */
    public function getPickings(array $pickingIds): array
    {
        return $this->odoo->read('stock.picking', $pickingIds, self::PICKING_FIELDS);
    }

    /**
     * Get stock moves for a picking.
     */
    public function getMoves(array $moveIds): array
    {
        return $this->odoo->read('stock.move', $moveIds, [
            'id', 'product_id', 'product_uom_qty', 'quantity_done', 'state',
        ]);
    }

    /**
     * Create a sale order from a Shopify order.
     */
    public function createFromShopify(array $orderData): int
    {
        return $this->odoo->create('sale.order', $orderData);
    }

    /**
     * Confirm a sale order (moves it to 'sale' state).
     */
    public function confirmOrder(int $orderId): bool
    {
        return (bool) $this->odoo->executeKw('sale.order', 'action_confirm', [[$orderId]]);
    }

    /**
     * Cancel a sale order.
     */
    public function cancelOrder(int $orderId): bool
    {
        return (bool) $this->odoo->executeKw('sale.order', 'action_cancel', [[$orderId]]);
    }
}
