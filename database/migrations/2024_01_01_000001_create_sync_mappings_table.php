<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);          // product, product_variant, customer, order, inventory_item
            $table->string('odoo_id', 100);
            $table->string('shopify_id', 100);
            $table->string('shopify_secondary_id', 100)->nullable(); // e.g. inventory_item_id for variants
            $table->string('odoo_reference', 255)->nullable();       // internal ref / SKU
            $table->string('shopify_handle', 255)->nullable();       // product handle or order name
            $table->json('metadata')->nullable();                    // flexible overflow data
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'odoo_id']);
            $table->unique(['entity_type', 'shopify_id']);
            $table->index(['entity_type', 'last_synced_at']);
            $table->index('odoo_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_mappings');
    }
};
