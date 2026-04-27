<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['odoo_to_shopify', 'shopify_to_odoo']);
            $table->string('entity_type', 50);
            $table->string('entity_id', 100);
            $table->string('action', 50);    // create, update, delete, skip, fulfill, cancel
            $table->enum('status', ['pending', 'processing', 'success', 'failed', 'skipped'])->default('pending');
            $table->string('job_id', 100)->nullable();
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['direction', 'entity_type', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['status', 'created_at']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
