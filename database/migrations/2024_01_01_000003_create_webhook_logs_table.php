<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['shopify'])->default('shopify');
            $table->string('topic', 100);
            $table->string('shopify_webhook_id', 100)->nullable()->unique(); // idempotency key
            $table->string('shop_domain', 255)->nullable();
            $table->longText('payload');
            $table->boolean('hmac_valid')->default(false);
            $table->boolean('processed')->default(false);
            $table->text('processing_error')->nullable();
            $table->timestamp('job_dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['topic', 'processed', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
