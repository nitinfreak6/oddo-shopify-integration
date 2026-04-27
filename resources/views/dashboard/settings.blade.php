@extends('dashboard.layout')
@section('title', 'API Settings')
@section('page-title', 'API Settings')

@section('content')

<div x-data="{ activeTab: '{{ $groups->keys()->first() }}' }">

    {{-- Tab nav --}}
    <div class="flex gap-1 mb-6 bg-white rounded-xl border border-gray-100 shadow-sm p-1 w-fit">
        @foreach($groups->keys() as $group)
        <button @click="activeTab = '{{ $group }}'"
                :class="activeTab === '{{ $group }}' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-lg text-sm font-medium transition capitalize">
            {{ $group }}
        </button>
        @endforeach
    </div>

    {{-- Tab panels --}}
    @foreach($groups as $group => $settings)
    <div x-show="activeTab === '{{ $group }}'" x-cloak>
        <form method="POST" action="{{ route('dashboard.settings.update') }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="group" value="{{ $group }}">

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm divide-y divide-gray-50">

                @foreach($settings as $setting)
                <div class="px-6 py-5 grid grid-cols-3 gap-6 items-start">
                    <div>
                        <label class="block text-sm font-medium text-gray-800">{{ $setting->label }}</label>
                        @if($setting->description)
                        <p class="text-xs text-gray-400 mt-1">{{ $setting->description }}</p>
                        @endif
                        @if($setting->is_secret)
                        <span class="badge bg-red-100 text-red-700 text-xs mt-2">Secret</span>
                        @endif
                    </div>
                    <div class="col-span-2">
                        @if($setting->is_secret)
                        {{-- Secret field with reveal button --}}
                        <div class="flex gap-2" x-data="{ revealed: false, loading: false, val: '' }">
                            <div class="flex-1 relative">
                                <input :type="revealed ? 'text' : 'password'"
                                       name="{{ $setting->key }}"
                                       :value="revealed ? val : ''"
                                       :placeholder="revealed ? '' : '{{ $setting->getMaskedValue() ?: 'Not set' }}'"
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                            </div>
                            @if(auth()->user()->can('reveal-secrets'))
                            <button type="button"
                                    @click="loading = true; fetch('{{ route('dashboard.settings.reveal', $setting) }}', {headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(r=>r.json()).then(d=>{val=d.value;revealed=true;loading=false}).catch(()=>loading=false)"
                                    :disabled="revealed || loading"
                                    class="px-3 py-2 text-xs border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap transition">
                                <span x-show="!loading && !revealed">Reveal</span>
                                <span x-show="loading">…</span>
                                <span x-show="revealed && !loading">Shown</span>
                            </button>
                            @endif
                        </div>
                        <p class="text-xs text-gray-400 mt-1">Leave blank to keep existing value.</p>
                        @else
                        <input type="text"
                               name="{{ $setting->key }}"
                               value="{{ $setting->getDecryptedValue() ?? $setting->default_value }}"
                               placeholder="{{ $setting->default_value }}"
                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                        @endif
                    </div>
                </div>
                @endforeach

                <div class="px-6 py-4 bg-gray-50 flex justify-end">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                        Save {{ ucfirst($group) }} Settings
                    </button>
                </div>
            </div>
        </form>

        {{-- Manual sync triggers (manager+) --}}
        @if($group === 'general' && auth()->user()->can('trigger-sync'))
        <div class="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Manual Sync Triggers</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach([
                    'products'         => ['label' => 'Shopify Products', 'color' => 'indigo'],
                    'inventory'        => ['label' => 'Shopify Inventory', 'color' => 'indigo'],
                    'orders'           => ['label' => 'Shopify Orders',   'color' => 'indigo'],
                    'customers'        => ['label' => 'Customers',        'color' => 'indigo'],
                    'amazon_products'  => ['label' => 'Amazon Products',  'color' => 'amber'],
                    'amazon_orders'    => ['label' => 'Amazon Orders',    'color' => 'amber'],
                    'amazon_inventory' => ['label' => 'Amazon Inventory', 'color' => 'amber'],
                ] as $type => $info)
                <form method="POST" action="{{ route('dashboard.sync.trigger') }}">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <button type="submit"
                            class="w-full border border-{{ $info['color'] }}-200 bg-{{ $info['color'] }}-50 hover:bg-{{ $info['color'] }}-100 text-{{ $info['color'] }}-700 text-xs font-medium py-2 px-3 rounded-lg transition">
                        ↺ {{ $info['label'] }}
                    </button>
                </form>
                @endforeach
            </div>
        </div>
        @endif
    </div>
    @endforeach
</div>
@endsection
