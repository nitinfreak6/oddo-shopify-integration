<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_feed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('feed_id', 100)->unique();              // Amazon feed ID
            $table->string('feed_type', 100);                      // JSON_LISTINGS_FEED, etc.
            $table->string('odoo_entity_type', 50)->nullable();    // product, inventory
            $table->string('odoo_entity_id', 100)->nullable();     // Odoo record ID
            $table->enum('status', ['submitted', 'in_progress', 'cancelled', 'done', 'fatal'])->default('submitted');
            $table->string('result_document_id', 255)->nullable(); // For downloading results
            $table->text('processing_summary')->nullable();        // Amazon error summary
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['odoo_entity_type', 'odoo_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_feed_jobs');
    }
};
