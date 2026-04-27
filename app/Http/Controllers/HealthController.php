<?php

namespace App\Http\Controllers;

use App\Models\SyncQueueState;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $data = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ];

        // Queue depth
        try {
            $data['queue_depth'] = [
                'sync'     => DB::table('jobs')->where('queue', 'sync')->count(),
                'webhooks' => DB::table('jobs')->where('queue', 'webhooks')->count(),
                'failed'   => DB::table('failed_jobs')->count(),
            ];
        } catch (\Throwable) {
            $data['queue_depth'] = 'unavailable';
        }

        // Sync state
        try {
            $syncStates = SyncQueueState::all(['sync_type', 'last_poll_at', 'is_running', 'last_odoo_write_date']);
            $data['sync_state'] = $syncStates->toArray();
        } catch (\Throwable) {
            $data['sync_state'] = 'unavailable';
        }

        // Cache / Redis ping
        try {
            Cache::put('health_ping', 1, 5);
            $data['cache'] = 'ok';
        } catch (\Throwable) {
            $data['cache'] = 'error';
            $data['status'] = 'degraded';
        }

        return response()->json($data);
    }
}
