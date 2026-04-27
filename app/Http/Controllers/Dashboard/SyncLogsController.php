<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use Illuminate\Http\Request;

class SyncLogsController extends Controller
{
    public function index(Request $request)
    {
        $direction   = $request->input('direction');
        $entityType  = $request->input('entity_type');
        $status      = $request->input('status');
        $search      = $request->input('search');
        $dateFrom    = $request->input('date_from');
        $dateTo      = $request->input('date_to');

        $query = SyncLog::orderByDesc('created_at');

        if ($direction)  $query->where('direction', $direction);
        if ($entityType) $query->where('entity_type', $entityType);
        if ($status)     $query->where('status', $status);
        if ($dateFrom)   $query->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo)     $query->whereDate('created_at', '<=', $dateTo);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('entity_id', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%")
                  ->orWhere('job_id', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(60)->withQueryString();

        // Filter options
        $entityTypes = SyncLog::distinct()->pluck('entity_type')->sort()->values();

        // Status summary
        $summary = SyncLog::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('dashboard.logs', compact(
            'logs', 'direction', 'entityType', 'status', 'search',
            'dateFrom', 'dateTo', 'entityTypes', 'summary'
        ));
    }

    public function show(SyncLog $log)
    {
        return view('dashboard.log-detail', compact('log'));
    }
}
