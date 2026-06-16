@extends('dashboard.layout')
@section('title', 'Stock Info')
@section('page-title', 'Stock Info')

@section('content')
@php
    $meta        = $mapping ? (is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true)) : [];
    $lastLog     = $logs->first();
    $pushOk      = $lastLog && $lastLog->status === 'success';
    $pushFailed  = $lastLog && $lastLog->status === 'failed';
    $wasPushed   = !is_null($lastLog);
    $productName = $cache?->name ?? 'Inventory Item #' . $erpId;
    $sku         = $cache?->default_code ?? $meta['sku'] ?? null;
    $qty         = $meta['available'] ?? $meta['quantity'] ?? $meta['qty'] ?? null;

    $reqPayload  = ($lastLog && $lastLog->request_payload)
        ? (json_decode($lastLog->request_payload, true) ?? [])
        : [];
    $respPayload = ($lastLog && $lastLog->response_payload)
        ? (json_decode($lastLog->response_payload, true) ?? [])
        : [];

    $erpHost = rtrim(config('odoo.url', config('erp.url', '')), '/');
@endphp

<div class="max-w-5xl">

    <div class="mb-4">
        <a href="{{ route('dashboard.inventory') }}" class="text-sm text-indigo-600 hover:underline">← Back to Inventory</a>
    </div>

    @foreach(['success','error','warning','info'] as $type)
        @if(session($type))
            <div class="mb-4 px-4 py-3 rounded-lg text-sm border
                {{ $type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' :
                   ($type === 'error'  ? 'bg-red-50 border-red-200 text-red-700' :
                   ($type === 'warning'? 'bg-amber-50 border-amber-200 text-amber-700' :
                                         'bg-blue-50 border-blue-200 text-blue-700')) }}">
                {!! session($type) !!}
            </div>
        @endif
    @endforeach

    {{-- ── Header card ── --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                        {{ $erpDisplayName }} → {{ $ecomDisplayName }}
                    </span>
                    <span class="font-mono text-sm text-gray-700">#{{ $erpId }}</span>
                    <span class="text-sm font-semibold text-gray-800">{{ $productName }}</span>

                    @if($wasPushed)
                        @if($pushOk)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">✓ Pushed to {{ $ecomDisplayName }}</span>
                        @elseif($pushFailed)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">✗ Push failed</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">{{ $lastLog->status }}</span>
                        @endif
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Not pushed yet</span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs text-gray-400">
                    @if($sku)
                        <span>SKU: <strong class="text-gray-600">{{ $sku }}</strong></span>
                        <span>·</span>
                    @endif
                    @if($qty !== null)
                        <span>Qty on hand: <strong class="text-gray-600">{{ $qty }}</strong></span>
                        <span>·</span>
                    @endif
                    @if($mapping?->last_synced_at)
                        <span>Last synced: <strong class="text-gray-600">{{ $mapping->last_synced_at->format('Y-m-d H:i:s') }}</strong></span>
                    @endif
                </div>
            </div>

            <div class="flex gap-2 shrink-0">
                <form method="POST" action="{{ route('dashboard.inventory.fetch-stock-single', $erpId) }}">
                    @csrf
                    <button class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">↓ Fetch Stock</button>
                </form>
                <form method="POST" action="{{ route('dashboard.inventory.post-stock-single', $erpId) }}">
                    @csrf
                    <button class="px-3 py-1.5 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">↑ Post Stock</button>
                </form>
            </div>
        </div>

        {{-- Meta pills --}}
        <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-50 text-xs text-gray-500">
            @if($mapping?->ecom_id)
                <span>{{ $ecomDisplayName }} Inventory Item: <strong class="text-gray-700 font-mono">{{ $mapping->ecom_id }}</strong></span>
            @endif
            @if($meta['product_ecom_id'] ?? null)
                <span>· {{ $ecomDisplayName }} Product ID: <strong class="text-gray-700 font-mono">{{ $meta['product_ecom_id'] }}</strong></span>
            @endif
            @if($meta['shopify_location_id'] ?? null)
                <span>· Location: <strong class="text-gray-700 font-mono">{{ $meta['shopify_location_id'] }}</strong></span>
            @endif
            <span>· Status: <strong class="text-gray-700">{{ $mapping?->ecom_status ?? '—' }}</strong></span>
        </div>
    </div>

    {{-- ── 3-tab panel (same design as products-detail) ── --}}
    <div x-data="{ tab: '{{ $wasPushed ? 'target' : 'source' }}' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

        <div class="flex border-b border-gray-100 bg-gray-50">
            <button @click="tab = 'source'"
                    :class="tab === 'source' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                📦 Stored Payload
            </button>
            <button @click="tab = 'target'"
                    :class="tab === 'target' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition flex items-center gap-2">
                🛍 {{ $ecomDisplayName }} Sync
                @if($pushOk)
                    <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                @elseif($pushFailed)
                    <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
                @endif
            </button>
            <button @click="tab = 'history'"
                    :class="tab === 'history' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                📋 Sync History
                @if($logs->isNotEmpty())
                    <span class="ml-1 text-xs text-gray-400">({{ $logs->count() }})</span>
                @endif
            </button>
        </div>

        {{-- ── TAB 1: Stored Payload (from ERP) ── --}}
        <div x-show="tab === 'source'" class="p-5">
            @if($erpHost)
                <div class="mb-4 p-3 rounded-lg bg-indigo-50 border border-indigo-100 text-xs flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold text-indigo-800">{{ $erpDisplayName }} API</span>
                    <span class="text-indigo-300">·</span>
                    <span class="text-indigo-600 font-medium">POST</span>
                    <code class="bg-white border border-indigo-100 px-2 py-0.5 rounded text-indigo-800 break-all">{{ $erpHost }}/web/dataset/call_kw</code>
                    <span class="text-indigo-300">·</span>
                    <span class="text-indigo-600">model: <code class="bg-white border border-indigo-100 px-1 rounded">stock.quant</code></span>
                    <span class="text-indigo-300">·</span>
                    <span class="text-indigo-600">product_id: <code class="bg-white border border-indigo-100 px-1 rounded">{{ $erpId }}</code></span>
                </div>
            @endif

            <p class="text-xs text-gray-400 mb-3">Inventory data fetched from {{ $erpDisplayName }} — this is what gets sent to {{ $ecomDisplayName }} on Post Stock.</p>

            @if($mapping && $mapping->metadata)
                <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                     style="max-height:72vh">{{ json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                <div class="py-16 text-center">
                    <div class="text-5xl mb-4">📦</div>
                    <p class="text-sm font-semibold text-gray-500 mb-1">No payload stored yet</p>
                    <p class="text-xs text-gray-400">Click <strong>Fetch Stock</strong> to pull from {{ $erpDisplayName }}.</p>
                </div>
            @endif
        </div>

        {{-- ── TAB 2: Ecom Sync (request + response) ── --}}
        <div x-show="tab === 'target'" class="p-5">
            @if(!$wasPushed)
                <div class="py-16 text-center">
                    <div class="text-5xl mb-4">🛍</div>
                    <p class="text-sm font-semibold text-gray-500 mb-1">Not pushed to {{ $ecomDisplayName }} yet</p>
                    <p class="text-xs text-gray-400">Use <strong>Fetch Stock</strong> then <strong>Post Stock</strong>.<br>
                    The outgoing payload and {{ $ecomDisplayName }} response will appear here after the first push.</p>
                </div>
            @else
                {{-- Status row --}}
                <div class="flex flex-wrap items-center gap-3 mb-5 pb-4 border-b border-gray-100 text-xs">
                    @if($pushOk)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">✓ Push succeeded</span>
                    @elseif($pushFailed)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">✗ Push failed</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700">{{ $lastLog->status }}</span>
                    @endif
                    <span class="text-gray-500">Action: <strong>{{ $lastLog->action ?? '—' }}</strong></span>
                    <span class="text-gray-500">At: <strong>{{ ($lastLog->synced_at ?? $lastLog->created_at)?->format('Y-m-d H:i:s') }}</strong></span>
                    @if($lastLog->attempts ?? null)
                        <span class="text-gray-500">Attempts: <strong>{{ $lastLog->attempts }}</strong></span>
                    @endif
                </div>

                {{-- Error box --}}
                @if($pushFailed && $lastLog->error_message)
                    <div class="mb-5 bg-red-50 border border-red-200 rounded-lg p-4">
                        <p class="text-sm font-semibold text-red-700 mb-1">Error</p>
                        <p class="text-sm text-red-600">{{ $lastLog->error_message }}</p>
                    </div>
                @endif

                {{-- Payload + Response card --}}
                <div class="rounded-lg border border-gray-200 overflow-hidden">

                    {{-- Outgoing --}}
                    <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">↑ Outgoing Payload</span>
                        <span class="text-xs text-gray-400">sent to {{ $ecomDisplayName }}</span>
                        <span class="text-xs text-gray-400 ml-auto">Mutation: <strong>inventorySetQuantities</strong></span>
                    </div>

                    @if(!empty($reqPayload))
                        <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                             style="max-height:50vh">{{ json_encode($reqPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        <div class="p-4 text-xs text-gray-400 italic">Payload not stored for this log entry.</div>
                    @endif

                    {{-- Response --}}
                    <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">↓ {{ $ecomDisplayName }} Response</span>
                    </div>

                    @if(!empty($respPayload))
                        <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                             style="max-height:50vh">{{ json_encode($respPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        <div class="p-4 text-xs text-gray-400 italic">No response body stored for this log entry.</div>
                    @endif

                </div>
            @endif
        </div>

        {{-- ── TAB 3: Sync History ── --}}
        <div x-show="tab === 'history'" class="p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">Stock Sync History</h3>
                <span class="text-xs text-gray-400">{{ $logs->count() }} record(s)</span>
            </div>

            @if($logs->isEmpty())
                <div class="text-center py-10 text-gray-400 text-sm">
                    No sync history yet.
                    <div class="text-xs mt-1">Use <strong>Fetch Stock</strong> then <strong>Post Stock</strong> to sync.</div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-2 text-left">Date</th>
                                <th class="px-4 py-2 text-left">Action</th>
                                <th class="px-4 py-2 text-left">Status</th>
                                <th class="px-4 py-2 text-left">Qty</th>
                                <th class="px-4 py-2 text-left">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($logs as $log)
                            @php
                                $sc  = ['success' => 'emerald', 'failed' => 'red', 'processing' => 'blue', 'pending' => 'amber'][$log->status] ?? 'gray';
                                $rp  = is_string($log->request_payload) ? json_decode($log->request_payload, true) : ($log->request_payload ?? []);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $log->action ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $sc }}-100 text-{{ $sc }}-700">
                                        {{ ucfirst($log->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-700">{{ $rp['qty'] ?? $rp['quantity'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs text-gray-400 max-w-xs truncate" title="{{ $log->error_message ?? '' }}">
                                    {{ $log->error_message ?? '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    </div>
</div>
@endsection