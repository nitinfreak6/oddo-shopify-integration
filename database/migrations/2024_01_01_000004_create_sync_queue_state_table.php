<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_queue_state', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type', 100)->unique(); // products, inventory, orders, customers
            $table->timestamp('last_poll_at')->nullable();
            $table->string('last_odoo_write_date', 30)->nullable(); // Odoo write_date cursor
            $table->boolean('is_running')->default(false);
            $table->timestamp('run_started_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_queue_state');
    }
};
