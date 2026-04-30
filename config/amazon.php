<?php

return [
    /*
     * Login with Amazon (LWA) OAuth credentials.
     * Generate from: https://sellercentral.amazon.com/apps/authorize/consent
     */
    'client_id'     => env('AMAZON_LWA_CLIENT_ID'),
    'client_secret' => env('AMAZON_LWA_CLIENT_SECRET'),
    'refresh_token' => env('AMAZON_LWA_REFRESH_TOKEN'),

    /*
     * Seller / marketplace identifiers.
     * Marketplace IDs: US=ATVPDKIKX0DER, UK=A1F83G8C2ARO7P, DE=A1PA6795UKMFR9, etc.
     */
    'seller_id'      => env('AMAZON_SELLER_ID'),
    'marketplace_id' => env('AMAZON_MARKETPLACE_ID', 'ATVPDKIKX0DER'),

    /*
     * SP-API regional endpoint.
     * NA: sellingpartnerapi-na.amazon.com
     * EU: sellingpartnerapi-eu.amazon.com
     * FE: sellingpartnerapi-fe.amazon.com
     */
    'endpoint' => env('AMAZON_SP_API_ENDPOINT', 'https://sellingpartnerapi-na.amazon.com'),

    /*
     * LWA token endpoint (do not change).
     */
    'lwa_token_url' => 'https://api.amazon.com/auth/o2/token',

    /*
     * Fulfillment channel: FBM (merchant) or FBA (Amazon).
     * FBA: Amazon manages inventory — we do not push stock levels.
     * FBM: We push inventory levels via Listings API.
     */
    'fulfillment_channel' => env('AMAZON_FULFILLMENT_CHANNEL', 'FBM'),

    /*
     * Feed poll interval in seconds.
     * How long to wait between feed status checks.
     */
    'feed_poll_seconds' => (int) env('AMAZON_FEED_POLL_SECONDS', 300),

    /*
     * Default product condition for new listings.
     */
    'condition' => env('AMAZON_PRODUCT_CONDITION', 'new_new'),

    /*
     * Request timeout in seconds.
     */
    'timeout' => (int) env('AMAZON_TIMEOUT', 30),
    'product_type'                 => env('AMAZON_PRODUCT_TYPE', 'SPORTING_GOODS'),
    'manufacturer'                 => env('AMAZON_MANUFACTURER', ''),
    'country_of_origin'            => env('AMAZON_COUNTRY_OF_ORIGIN', ''),
    'item_weight_unit'             => env('AMAZON_ITEM_WEIGHT_UNIT', 'kilograms'),
    'default_item_weight'          => (float) env('AMAZON_DEFAULT_ITEM_WEIGHT', 0.5),
    'item_type_name'               => env('AMAZON_ITEM_TYPE_NAME', ''),
    'department'                   => env('AMAZON_DEPARTMENT', ''),
    'material'                     => env('AMAZON_MATERIAL', ''),
    'color'                        => env('AMAZON_COLOR', ''),
    'merchant_suggested_asin'      => env('AMAZON_MERCHANT_SUGGESTED_ASIN', ''),
    'external_product_information' => env('AMAZON_EXTERNAL_PRODUCT_INFORMATION', ''),
    'packer_contact_information'   => env('AMAZON_PACKER_CONTACT_INFORMATION', ''),
    'importer_contact_information' => env('AMAZON_IMPORTER_CONTACT_INFORMATION', ''),
];
