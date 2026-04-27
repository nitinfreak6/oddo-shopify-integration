<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use Illuminate\Http\Request;

class WebhooksController extends Controller
{
    public function index(Request $request)
    {
        $topic     = $request->input('topic');
        $processed = $request->input('processed'); // '' | '0' | '1'
        $search    = $request->input('search');
        $dateFrom  = $request->input('date_from');

        $query = WebhookLog::orderByDesc('created_at');

        if ($topic)     $query->where('topic', $topic);
        if ($dateFrom)  $query->whereDate('created_at', '>=', $dateFrom);
        if ($processed !== null && $processed !== '') {
            $query->where('processed', (bool) $processed);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('shopify_webhook_id', 'like', "%{$search}%")
                  ->orWhere('shop_domain', 'like', "%{$search}%")
                  ->orWhere('processing_error', 'like', "%{$search}%");
            });
        }

        $webhooks = $query->paginate(50)->withQueryString();

        $topics  = WebhookLog::distinct()->pluck('topic')->sort()->values();
        $summary = [
            'total'     => WebhookLog::count(),
            'processed' => WebhookLog::where('processed', true)->count(),
            'pending'   => WebhookLog::where('processed', false)->count(),
            'errors'    => WebhookLog::whereNotNull('processing_error')->count(),
        ];

        return view('dashboard.webhooks', compact('webhooks', 'topic', 'processed', 'search', 'topics', 'summary'));
    }
}
