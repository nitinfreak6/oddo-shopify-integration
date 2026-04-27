<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

use App\Services\Shopify\ShopifyService;
use App\Services\Odoo\OdooOrderService;
use App\Services\Odoo\OdooCustomerService;

echo "Testing Shopify order import...\n\n";

try {
    $shopify = new ShopifyService();
    
    // Test connection
    $shopResponse = $shopify->get('shop.json');
    $shopName = $shopResponse['shop']['name'] ?? 'Unknown';
    echo "Connected to shop: {$shopName}\n";
    
    // Get orders
    $response = $shopify->get('orders.json', ['limit' => 5]);
    $orders = $response['orders'] ?? [];
    
    echo "Found " . count($orders) . " orders:\n";
    
    foreach ($orders as $order) {
        echo "- Order #" . $order['name'] . " (ID: " . $order['id'] . ")\n";
        echo "  Email: " . ($order['email'] ?? 'N/A') . "\n";
        echo "  Total: $" . $order['total_price'] . "\n";
        echo "  Status: " . $order['financial_status'] . "\n";
        echo "  Created: " . $order['created_at'] . "\n\n";
    }
    
    echo "\nOrders fetched successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
