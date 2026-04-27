<?php

return [
    'url'      => env('ODOO_URL'),
    'db'       => env('ODOO_DB'),
    'username' => env('ODOO_USERNAME'),
    'api_key'  => env('ODOO_API_KEY'),
    'timeout'  => (int) env('ODOO_TIMEOUT', 30),

    /*
     * Maps Odoo stock location IDs to Shopify location IDs.
     * JSON encoded: e.g. {"1": "65432100001"}
     */
    'location_map' => json_decode(env('ODOO_LOCATION_SHOPIFY_LOCATION_MAP', '{}'), true) ?? [],
];
