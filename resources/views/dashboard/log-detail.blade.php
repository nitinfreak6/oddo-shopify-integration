@extends('dashboard.layout')
@section('title', 'Log Detail')
@section('page-title', 'Sync Log Detail')

@section('content')
<div class="max-w-4xl">
    <div class="mb-4">
        <a href="{{ route('dashboard.logs') }}" class="text-sm text-indigo-600 hover:underline">← Back to logs</a>
    </div>

    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="badge bg-gray-100 text-gray-700">{{ $log->entity_type }}</span>
                    <span class="font-mono text-sm text-gray-700">#{{ $log->entity_id }}</span>
                    @php $colors = ['success'=>'green','failed'=>'red','pending'=>'yellow','processing'=>'blue','skipped'=>'gray']; $c = $colors[$log->status] ?? 'gray'; @endphp
                    <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700">{{ $log->status }}</span>
                </div>
                <p class="text-xs text-gray-400">{{ $log->created_at->format('Y-m-d H:i:s') }}</p>
            </div>
            <div class="text-right text-sm text-gray-500">
                <div>Attempts: <strong>{{ $log->attempts }}</strong></div>
                <div>Action: <strong>{{ $log->action }}</strong></div>
            </div>
        </div>

        {{-- Error --}}
        @if($log->error_message)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-red-700 mb-1">Error</h4>
            <p class="text-sm text-red-600">{{ $log->error_message }}</p>
        </div>
        @endif

        {{-- Error context --}}
        @if($log->error_context)
        <div>
            <h4 class="text-sm font-semibold text-gray-700 mb-2">Error Context</h4>
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap">{{ json_encode($log->error_context, JSON_PRETTY_PRINT) }}</pre>
        </div>
        @endif

        {{-- Request payload --}}
        @if($log->request_payload)
        <div>
            <h4 class="text-sm font-semibold text-gray-700 mb-2">Request Payload</h4>
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap max-h-96">{{ json_encode(json_decode($log->request_payload), JSON_PRETTY_PRINT) ?: $log->request_payload }}</pre>
        </div>
        @endif

        {{-- Response payload --}}
        @if($log->response_payload)
        <div>
            <h4 class="text-sm font-semibold text-gray-700 mb-2">Response Payload</h4>
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap max-h-96">{{ json_encode(json_decode($log->response_payload), JSON_PRETTY_PRINT) ?: $log->response_payload }}</pre>
        </div>
        @endif

    </div>
</div>
@endsection
