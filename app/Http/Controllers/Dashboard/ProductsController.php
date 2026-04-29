<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
    public function index(Request $request)
    {
        $search    = $request->input('search');
        $channel   = $request->input('channel', 'all'); // all, shopify, amazon

        // Product-level mappings
        $entityTypes = match ($channel) {
            'shopify' => ['product'],
            'amazon'  => ['amazon_product'],
            default   => ['product', 'amazon_product'],
        };

        $query = SyncMapping::whereIn('entity_type', $entityTypes)
            ->orderByDesc('last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('odoo_id', 'like', "%{$search}%")
                  ->orWhere('shopify_id', 'like', "%{$search}%")
                  ->orWhere('odoo_reference', 'like', "%{$search}%")
                  ->orWhere('shopify_handle', 'like', "%{$search}%");
            });
        }

        $products = $query->paginate(50)->withQueryString();

        // Get variant counts per product
        $shopifyProductIds = SyncMapping::where('entity_type', 'product')
            ->pluck('odoo_id')->toArray();

        $variantCounts = SyncMapping::where('entity_type', 'product_variant')
		->selectRaw('COUNT(*) as count, MIN(odoo_id) as sample_odoo, shopify_id')
		->groupBy('shopify_id')
		->pluck('count', 'shopify_id');

        // Recent product sync logs
        $recentLogs = SyncLog::whereIn('entity_type', ['product', 'amazon_product', 'amazon_variant'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('dashboard.products', compact('products', 'search', 'channel', 'variantCounts', 'recentLogs'));
    }
}
