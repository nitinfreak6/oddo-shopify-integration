<?php

namespace Database\Seeders;

use App\Models\ConnectorSetting;
use Illuminate\Database\Seeder;

class ConnectorSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ── Odoo ─────────────────────────────────────────────
            ['group' => 'odoo', 'key' => 'odoo_url',      'label' => 'Odoo URL',          'is_secret' => false, 'sort_order' => 1,  'description' => 'Full URL of your Odoo instance, e.g. https://mycompany.odoo.com'],
            ['group' => 'odoo', 'key' => 'odoo_db',       'label' => 'Odoo Database',     'is_secret' => false, 'sort_order' => 2,  'description' => 'Odoo database name'],
            ['group' => 'odoo', 'key' => 'odoo_username',  'label' => 'Odoo Username',    'is_secret' => false, 'sort_order' => 3,  'description' => 'Odoo login email'],
            ['group' => 'odoo', 'key' => 'odoo_api_key',   'label' => 'Odoo API Key',     'is_secret' => true,  'sort_order' => 4,  'description' => 'Generate from Odoo → Settings → Users → Your User → API Keys'],
            ['group' => 'odoo', 'key' => 'odoo_timeout',   'label' => 'Request Timeout',  'is_secret' => false, 'sort_order' => 5,  'description' => 'XML-RPC timeout in seconds', 'default_value' => '30'],
            ['group' => 'odoo', 'key' => 'odoo_location_map', 'label' => 'Location Map', 'is_secret' => false, 'sort_order' => 6,  'description' => 'JSON map: Odoo location ID → Shopify location ID, e.g. {"1":"65432100001"}', 'default_value' => '{}'],

            // ── Shopify ───────────────────────────────────────────
            ['group' => 'shopify', 'key' => 'shopify_shop',            'label' => 'Shop Name',          'is_secret' => false, 'sort_order' => 1,  'description' => 'Your store handle (without .myshopify.com)'],
            ['group' => 'shopify', 'key' => 'shopify_access_token',    'label' => 'Access Token',       'is_secret' => true,  'sort_order' => 2,  'description' => 'Admin API access token from Shopify Partners / App setup'],
            ['group' => 'shopify', 'key' => 'shopify_api_version',     'label' => 'API Version',        'is_secret' => false, 'sort_order' => 3,  'description' => 'Shopify API version, e.g. 2024-01', 'default_value' => '2024-01'],
            ['group' => 'shopify', 'key' => 'shopify_webhook_secret',  'label' => 'Webhook Secret',     'is_secret' => true,  'sort_order' => 4,  'description' => 'HMAC secret for verifying Shopify webhook payloads'],
            ['group' => 'shopify', 'key' => 'shopify_callback_url',    'label' => 'Webhook Callback URL','is_secret' => false, 'sort_order' => 5, 'description' => 'Public HTTPS URL this app is reachable at (for webhook registration)'],
            ['group' => 'shopify', 'key' => 'shopify_inventory_writeback', 'label' => 'Inventory Writeback', 'is_secret' => false, 'sort_order' => 6, 'description' => 'Allow Shopify inventory updates to overwrite Odoo stock (default: false)', 'default_value' => 'false'],

            // ── Amazon ────────────────────────────────────────────
            ['group' => 'amazon', 'key' => 'amazon_client_id',       'label' => 'LWA Client ID',       'is_secret' => true,  'sort_order' => 1,  'description' => 'Login with Amazon app client ID'],
            ['group' => 'amazon', 'key' => 'amazon_client_secret',   'label' => 'LWA Client Secret',   'is_secret' => true,  'sort_order' => 2,  'description' => 'Login with Amazon app client secret'],
            ['group' => 'amazon', 'key' => 'amazon_refresh_token',   'label' => 'LWA Refresh Token',   'is_secret' => true,  'sort_order' => 3,  'description' => 'Refresh token from seller authorization'],
            ['group' => 'amazon', 'key' => 'amazon_seller_id',       'label' => 'Seller ID',           'is_secret' => false, 'sort_order' => 4,  'description' => 'Your Amazon Seller ID (Merchant Token)'],
            ['group' => 'amazon', 'key' => 'amazon_marketplace_id',  'label' => 'Marketplace ID',      'is_secret' => false, 'sort_order' => 5,  'description' => 'e.g. ATVPDKIKX0DER (US), A1F83G8C2ARO7P (UK)', 'default_value' => 'ATVPDKIKX0DER'],
            ['group' => 'amazon', 'key' => 'amazon_sp_endpoint',     'label' => 'SP-API Endpoint',     'is_secret' => false, 'sort_order' => 6,  'description' => 'sellingpartnerapi-na/eu/fe.amazon.com', 'default_value' => 'https://sellingpartnerapi-na.amazon.com'],
            ['group' => 'amazon', 'key' => 'amazon_fulfillment_channel', 'label' => 'Fulfillment Channel', 'is_secret' => false, 'sort_order' => 7, 'description' => 'FBM (you fulfill) or FBA (Amazon fulfills)', 'default_value' => 'FBM'],

            // ── General ───────────────────────────────────────────
            ['group' => 'general', 'key' => 'sync_products_enabled',   'label' => 'Enable Product Sync',  'is_secret' => false, 'sort_order' => 1, 'description' => 'Master switch for product sync to all channels', 'default_value' => 'true'],
            ['group' => 'general', 'key' => 'sync_inventory_enabled',  'label' => 'Enable Inventory Sync','is_secret' => false, 'sort_order' => 2, 'description' => 'Master switch for inventory sync', 'default_value' => 'true'],
            ['group' => 'general', 'key' => 'sync_orders_enabled',     'label' => 'Enable Order Sync',    'is_secret' => false, 'sort_order' => 3, 'description' => 'Create Odoo orders from Shopify/Amazon', 'default_value' => 'true'],
            ['group' => 'general', 'key' => 'sync_customers_enabled',  'label' => 'Enable Customer Sync', 'is_secret' => false, 'sort_order' => 4, 'description' => 'Sync Odoo customers to Shopify', 'default_value' => 'true'],
            ['group' => 'general', 'key' => 'shopify_channel_enabled', 'label' => 'Shopify Channel Active','is_secret' => false, 'sort_order' => 5, 'description' => 'Toggle entire Shopify integration on/off', 'default_value' => 'true'],
            ['group' => 'general', 'key' => 'amazon_channel_enabled',  'label' => 'Amazon Channel Active', 'is_secret' => false, 'sort_order' => 6, 'description' => 'Toggle entire Amazon integration on/off', 'default_value' => 'true'],
        ];

        foreach ($settings as $data) {
            ConnectorSetting::updateOrCreate(
                ['key' => $data['key']],
                array_merge([
                    'value'         => null,
                    'default_value' => null,
                    'description'   => null,
                    'is_active'     => true,
                ], $data)
            );
        }
    }
}
