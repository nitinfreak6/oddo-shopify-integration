@extends('dashboard.layout')
@section('title', 'Products')
@section('page-title', 'Products Sync')

@section('content')

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="SKU, Odoo ID, Shopify ID…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="all"     {{ $channel === 'all'     ? 'selected' : '' }}>All Channels</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>Shopify</option>
                <option value="amazon"  {{ $channel === 'amazon'  ? 'selected' : '' }}>Amazon</option>
            </select>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700 transition">Filter</button>
        <a href="{{ route('dashboard.products') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
    </form>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-700">{{ $products->total() }} product mappings</span>
        <span class="text-xs text-gray-400">Odoo is source of truth</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Channel</th>
                    <th class="px-4 py-3 text-left font-medium">Odoo ID</th>
                    <th class="px-4 py-3 text-left font-medium">SKU / Reference</th>
                    <th class="px-4 py-3 text-left font-medium">Shopify / Amazon ID</th>
                    <th class="px-4 py-3 text-left font-medium">Handle / SKU</th>
                    <th class="px-4 py-3 text-left font-medium">Variants</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($products as $mapping)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        @if(str_starts_with($mapping->entity_type, 'amazon'))
                            <span class="badge bg-amber-100 text-amber-800">Amazon</span>
                        @else
                            <span class="badge bg-indigo-100 text-indigo-800">Shopify</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">
                        <span class="bg-gray-100 px-1.5 py-0.5 rounded">#{{ $mapping->odoo_id }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-700">
                        {{ $mapping->odoo_reference ?: '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">
                        @if($mapping->shopify_id)
                            @if(str_starts_with($mapping->entity_type, 'amazon'))
                                <span class="text-amber-700">{{ $mapping->shopify_id }}</span>
                            @else
                                <a href="https://admin.shopify.com/products/{{ $mapping->shopify_id }}"
                                   target="_blank"
                                   class="text-indigo-600 hover:underline">{{ $mapping->shopify_id }} ↗</a>
                            @endif
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $mapping->shopify_handle ?: '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="badge bg-gray-100 text-gray-600">
                            {{ $variantCounts[$mapping->shopify_id] ?? 0 }} vars
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        {{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center text-gray-400">
                        No product mappings found. Run <code class="bg-gray-100 px-1 rounded">php artisan sync:products --full</code>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">
        {{ $products->links() }}
    </div>
</div>

{{-- Recent sync logs --}}
<div class="mt-4 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Recent Product Sync Activity</h3>
    @include('dashboard._log-rows', ['logs' => $recentLogs])
</div>

@endsection
