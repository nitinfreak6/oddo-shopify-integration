<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        // Seed company-wide Amazon contact
        DB::table('settings')->insert([
            'key'        => 'amazon_company_contact',
            'value'      => json_encode([[
                'contact_name'   => 'Nitin Kapoor',
                'phone_number'   => '+91-8596586586',
                'address_line1'  => 'Address line 1',
                'city'           => 'Mohali',
                'state_or_region'=> 'PB',
                'postal_code'    => '160001',
                'country_code'   => 'IN',
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('settings');
    }
};