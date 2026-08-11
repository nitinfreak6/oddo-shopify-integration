<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assign Shopify GraphQL transforms to common field configs so enum/bool
 * casting lives in field config, not ShopifyProductService routing code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'inventoryPolicy'                      => 'shopify_inventory_policy',
            'inventoryItem.measurement.weight.unit'  => 'shopify_weight_unit',
            'inventoryItem.tracked'                => 'shopify_tracked',
            'inventoryItem.requiresShipping'       => 'shopify_bool',
            'taxable'                              => 'shopify_bool',
            'status'                               => 'shopify_status',
            'tags'                                 => 'tags_csv',
        ];

        foreach ($map as $field => $transform) {
            DB::table('product_field_configs')
                ->where('entity_type', 'product')
                ->where(function ($q) {
                    $q->whereNull('direction')->orWhere('direction', 'erp_to_ecom');
                })
                ->where(function ($q) use ($field) {
                    $q->where('ecom_field', $field);
                    if (Schema::hasColumn('product_field_configs', 'shopify_field')) {
                        $q->orWhere('shopify_field', $field);
                    }
                })
                ->where(function ($q) {
                    $q->whereNull('transform')->orWhere('transform', '');
                })
                ->update(['transform' => $transform, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Non-destructive — leave transforms in place on rollback
    }
};
