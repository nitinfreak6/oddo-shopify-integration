<?php

return [
    'shop'         => env('SHOPIFY_SHOP'),           // e.g. "mystore" (without .myshopify.com)
    'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    'api_version'  => env('SHOPIFY_API_VERSION', '2024-01'),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
    'callback_url' => env('SHOPIFY_WEBHOOK_CALLBACK_URL'),

    /*
     * When true, Shopify inventory_levels/update webhooks are written back to Odoo.
     * Default false — Odoo is the source of truth for inventory.
     */
    'inventory_writeback' => (bool) env('SHOPIFY_INVENTORY_WRITEBACK', false),
];
