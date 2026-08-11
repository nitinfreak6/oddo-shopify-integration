{{-- Expects $settings as a Collection of ConnectorSetting models --}}
@foreach($settings->sortBy('sort_order') as $setting)
@php
    $fieldType    = $setting->field_type ?? 'text';
    $currentValue = $setting->getDecryptedValue() ?? $setting->default_value ?? '';
@endphp
<div class="settings-row">
    <div>
        <div class="settings-label">{{ $setting->label }}</div>
        @if($setting->description)
            <div class="settings-desc">{{ $setting->description }}</div>
        @endif
        @if($setting->is_secret)
            <div class="secret-badge">
                <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Secret
            </div>
        @endif
    </div>

    <div>
        @if($setting->key === 'erp_driver')
        <div x-data="{ selected: '{{ $currentValue ?: 'odoo' }}' }" style="padding-top:4px">
            <input type="hidden" name="{{ $setting->key }}" :value="selected">
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @foreach(['odoo' => '🔗 Odoo', 'sap' => '⚡ SAP', 'netsuite' => '☁️ NetSuite'] as $val => $lbl)
                <button type="button"
                        @click="selected = '{{ $val }}'"
                        :class="selected === '{{ $val }}' ? 'selected' : ''"
                        class="erp-pill">
                    {{ $lbl }}
                </button>
                @endforeach
            </div>
            <p style="font-size:11px;color:#9ca3af;margin-top:6px">
                Active ERP adapter. Change the <strong>ERP Display Name</strong> on Global Settings to match.
            </p>
        </div>

        @elseif($fieldType === 'toggle')
        <div x-data="{ on: {{ in_array($currentValue, ['1','true','yes','on']) ? 'true' : 'false' }} }" class="toggle-wrap">
            <div class="toggle-track" :class="on ? 'on' : ''" @click="on = !on">
                <div class="toggle-thumb"></div>
            </div>
            <span class="toggle-label" x-text="on ? 'Enabled' : 'Disabled'"></span>
            <template x-if="on"><input type="hidden" name="{{ $setting->key }}" value="1"></template>
            <template x-if="!on"><input type="hidden" name="{{ $setting->key }}" value="0"></template>
        </div>

        @elseif($fieldType === 'textarea')
        <textarea name="{{ $setting->key }}"
                  rows="3"
                  placeholder="{{ $setting->default_value }}"
                  class="settings-input">{{ $currentValue }}</textarea>

        @elseif($fieldType === 'number')
        <input type="number"
               name="{{ $setting->key }}"
               value="{{ $currentValue }}"
               placeholder="{{ $setting->default_value }}"
               style="width:120px"
               class="settings-input">

        @elseif($setting->is_secret)
        <div x-data="{ revealed: false, loading: false, val: '', dirty: false, markDirty() { this.dirty = true; } }" class="secret-wrap">
            <template x-if="dirty && val.trim() !== ''">
                <input type="hidden" name="{{ $setting->key }}" :value="val">
            </template>
            <input :type="revealed ? 'text' : 'password'"
                   x-model="val"
                   @keydown="markDirty()"
                   @paste="markDirty()"
                   readonly
                   @focus="$el.removeAttribute('readonly')"
                   autocomplete="off"
                   autocapitalize="off"
                   spellcheck="false"
                   data-lpignore="true"
                   data-1p-ignore
                   data-form-type="other"
                   placeholder="Leave blank to keep existing value"
                   class="settings-input">
            @if($setting->getDecryptedValue())
            <span style="font-size:11px;color:#6b7280;margin-top:4px;display:block">
                Current: {{ $setting->getMaskedValue() }}
            </span>
            @endif
            @if(auth()->user()->can('reveal-secrets'))
            <button type="button" class="reveal-btn"
                    :disabled="revealed || loading"
                    @click="loading=true; fetch('{{ route('dashboard.settings.reveal', $setting) }}',{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(r=>r.json()).then(d=>{val=d.value||'';revealed=true;dirty=false;loading=false}).catch(()=>loading=false)">
                <span x-show="!loading && !revealed">Reveal</span>
                <span x-show="loading">…</span>
                <span x-show="revealed">✓ Shown</span>
            </button>
            @endif
        </div>
        <div style="font-size:11px;color:#9ca3af;margin-top:4px">Leave blank to keep existing value. Only type here if you want to change this secret.</div>

        @else
        <input type="text"
               name="{{ $setting->key }}"
               value="{{ $currentValue }}"
               placeholder="{{ $setting->default_value }}"
               class="settings-input"
               @if($setting->key === 'app_name') style="font-weight:600" @endif>
        @if($setting->key === 'app_name')
            <p style="font-size:11px;color:#9ca3af;margin-top:4px">
                Used in the browser tab and page header across the whole app.
            </p>
        @endif
        @if($setting->key === 'erp_display_name')
            <p style="font-size:11px;color:#9ca3af;margin-top:4px">
                Replaces the ERP label wherever it appears — field config columns, product pages, log messages.
            </p>
        @endif
        @if($setting->key === 'ecom_display_name' || $setting->key === 'shopify_display_name')
            <p style="font-size:11px;color:#9ca3af;margin-top:4px">
                Replaces the ecommerce label wherever it appears in the dashboard.
            </p>
        @endif
        @endif
    </div>
</div>
@endforeach
