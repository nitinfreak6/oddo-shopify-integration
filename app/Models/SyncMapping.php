<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMapping extends Model
{
    protected $fillable = [
        'entity_type',
        'odoo_id',
        'shopify_id',
        'shopify_secondary_id',
        'odoo_reference',
        'shopify_handle',
        'metadata',
        'last_synced_at',
    ];

    protected $casts = [
        'metadata'       => 'array',
        'last_synced_at' => 'datetime',
    ];

    // Entity type constants
    const TYPE_PRODUCT          = 'product';
    const TYPE_PRODUCT_VARIANT  = 'product_variant';
    const TYPE_CUSTOMER         = 'customer';
    const TYPE_ORDER            = 'order';
    const TYPE_INVENTORY_ITEM   = 'inventory_item';

    public function scopeOfType($query, string $type)
    {
        return $query->where('entity_type', $type);
    }
}
