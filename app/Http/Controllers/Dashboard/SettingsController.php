<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ConnectorSetting;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index()
    {
        $groups = ConnectorSetting::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        return view('dashboard.settings', compact('groups'));
    }

    public function update(Request $request)
    {
        $inputs = $request->except(['_token', '_method', 'group']);
        $group  = $request->input('group');

        foreach ($inputs as $key => $value) {
            $setting = ConnectorSetting::where('key', $key)->where('group', $group)->first();

            if (!$setting) continue;

            // Skip secrets that were left blank (keep existing)
            if ($setting->is_secret && ($value === '' || $value === null)) {
                continue;
            }

            if ($setting->is_secret && $value !== '' && $value !== null) {
				$setting->value = Crypt::encryptString($value);
				$setting->saveQuietly();
			} else {
				$setting->update(['value' => $value]);
			}
        }

        $this->settings->clearCache();

        return redirect()->route('dashboard.settings')
            ->with('success', ucfirst($group) . ' settings saved successfully.');
    }

    /**
     * Reveal a secret value — admin only, logged.
     */
    public function reveal(Request $request, ConnectorSetting $setting)
    {
        abort_unless(auth()->user()->can('reveal-secrets'), 403);

        $value = $setting->getDecryptedValue();

        return response()->json(['value' => $value]);
    }

    /**
     * Trigger a manual sync via Artisan.
     */
    public function triggerSync(Request $request)
    {
        abort_unless(auth()->user()->can('trigger-sync'), 403);

        $type = $request->input('type');

        $commandMap = [
            'products'         => 'sync:products',
            'inventory'        => 'sync:inventory',
            'orders'           => 'sync:orders',
            'customers'        => 'sync:customers',
            'amazon_products'  => 'sync:amazon-products',
            'amazon_orders'    => 'sync:amazon-orders',
            'amazon_inventory' => 'sync:amazon-inventory',
        ];

        if (!isset($commandMap[$type])) {
            return back()->with('error', 'Unknown sync type.');
        }

        try {
            Artisan::queue($commandMap[$type]);
            return back()->with('success', "Sync '{$type}' queued successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to queue sync: ' . $e->getMessage());
        }
    }
}
