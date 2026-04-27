<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->input('search');
        $channel = $request->input('channel', 'all');
        $status  = $request->input('status');

        $entityTypes = match ($channel) {
            'shopify' => ['inventory_item'],
            'amazon'  => ['amazon_inventory'],
            default   => ['inventory_item', 'amazon_inventory'],
        };

        // Get variant mappings (these hold inventory_item_id for Shopify)
        $variantQuery = SyncMapping::where('entity_type', 'product_variant')
            ->whereNotNull('shopify_secondary_id')
            ->orderByDesc('last_synced_at');

        if ($search) {
            $variantQuery->where(function ($q) use ($search) {
                $q->where('odoo_reference', 'like', "%{$search}%")
                  ->orWhere('odoo_id', 'like', "%{$search}%")
                  ->orWhere('shopify_secondary_id', 'like', "%{$search}%");
            });
        }

        $variants = $variantQuery->paginate(50)->withQueryString();

        // Recent inventory sync logs
        $logsQuery = SyncLog::whereIn('entity_type', ['inventory_item', 'amazon_inventory'])
            ->orderByDesc('created_at');

        if ($status) {
            $logsQuery->where('status', $status);
        }

        $recentLogs = $logsQuery->limit(30)->get();

        $syncState = [
            'inventory'        => SyncQueueState::forType('inventory'),
            'amazon_inventory' => SyncQueueState::forType('amazon_orders'),
        ];

        // Stats
        $stats = [
            'synced_today'  => SyncLog::whereIn('entity_type', ['inventory_item', 'amazon_inventory'])
                                ->where('status', 'success')
                                ->whereDate('created_at', today())->count(),
            'failed_today'  => SyncLog::whereIn('entity_type', ['inventory_item', 'amazon_inventory'])
                                ->where('status', 'failed')
                                ->whereDate('created_at', today())->count(),
            'total_skus'    => SyncMapping::where('entity_type', 'product_variant')->count(),
        ];

        return view('dashboard.inventory', compact('variants', 'search', 'channel', 'recentLogs', 'syncState', 'stats'));
    }
}
