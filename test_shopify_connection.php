<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Shopify\ShopifyService;
use App\Services\Shopify\ShopifyOrderService;

echo "Testing Shopify connection...\n\n";

try {
    $shopify = new ShopifyService();
    $orderService = new ShopifyOrderService($shopify);
    
    echo "1. Testing basic connection...\n";
    $shop = config('shopify.shop');
    echo "   Shop: {$shop}\n";
    echo "   API Version: " . config('shopify.api_version') . "\n";
    echo "   Access Token: " . (config('shopify.access_token') ? 'SET' : 'NOT SET') . "\n\n";
    
    echo "2. Testing API call...\n";
    $response = $shopify->get('shop.json');
    echo "   Shop Name: " . ($response['shop']['name'] ?? 'N/A') . "\n\n";
    
    echo "3. Testing orders endpoint...\n";
    $orders = $shopify->get('orders.json', ['limit' => 5]);
    $orderCount = count($orders['orders'] ?? []);
    echo "   Found {$orderCount} orders\n\n";
    
    if ($orderCount > 0) {
        echo "4. Sample order data:\n";
        $order = $orders['orders'][0];
        echo "   Order ID: " . $order['id'] . "\n";
        echo "   Order Name: " . $order['name'] . "\n";
        echo "   Created: " . $order['created_at'] . "\n";
        echo "   Financial Status: " . $order['financial_status'] . "\n\n";
    }
    
    echo "✅ Shopify connection working!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nChecking database for existing orders...\n";
try {
    $orderCount = \App\Models\SyncMapping::where('entity_type', 'order')->count();
    echo "Orders in database: {$orderCount}\n";
    
    if ($orderCount > 0) {
        $orders = \App\Models\SyncMapping::where('entity_type', 'order')
            ->orderByDesc('last_synced_at')
            ->limit(3)
            ->get(['odoo_id', 'shopify_id', 'shopify_handle', 'last_synced_at']);
            
        echo "Recent orders:\n";
        foreach ($orders as $order) {
            echo "  Odoo ID: {$order->odoo_id}, Shopify ID: {$order->shopify_id}, Name: {$order->shopify_handle}, Synced: {$order->last_synced_at}\n";
        }
    }
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
