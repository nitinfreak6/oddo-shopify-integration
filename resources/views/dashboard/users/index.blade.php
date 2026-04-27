@extends('dashboard.layout')
@section('title', 'Users')
@section('page-title', 'User Management')

@section('content')

<div class="flex justify-end mb-4">
    <a href="{{ route('dashboard.users.create') }}"
       class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Add User
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-5 py-3 text-left font-medium">Name</th>
                    <th class="px-5 py-3 text-left font-medium">Email</th>
                    <th class="px-5 py-3 text-left font-medium">Role</th>
                    <th class="px-5 py-3 text-left font-medium">Status</th>
                    <th class="px-5 py-3 text-left font-medium">Joined</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($users as $user)
                <tr class="hover:bg-gray-50 {{ !$user->is_active ? 'opacity-50' : '' }}">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-700 font-bold text-sm shrink-0">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-medium text-gray-800">{{ $user->name }}</div>
                                @if($user->id === auth()->id())
                                <div class="text-xs text-indigo-500">You</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3 text-gray-600">{{ $user->email }}</td>
                    <td class="px-5 py-3">
                        <span class="badge {{ $user->roleBadgeColor() }}">{{ $user->role }}</span>
                    </td>
                    <td class="px-5 py-3">
                        @if($user->is_active)
                            <span class="badge bg-green-100 text-green-700">Active</span>
                        @else
                            <span class="badge bg-gray-100 text-gray-500">Inactive</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-400">
                        {{ $user->created_at->format('M j, Y') }}
                    </td>
                    <td class="px-5 py-3 flex items-center gap-3 justify-end">
                        <a href="{{ route('dashboard.users.edit', $user) }}"
                           class="text-xs text-indigo-600 hover:underline">Edit</a>
                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('dashboard.users.destroy', $user) }}"
                              onsubmit="return confirm('Delete {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-500 hover:underline">Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-5 py-12 text-center text-gray-400">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $users->links() }}</div>
</div>

{{-- Permission matrix --}}
<div class="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <h3 class="text-sm font-semibold text-gray-700 mb-4">Role Permissions</h3>
    <div class="overflow-x-auto">
        <table class="text-sm w-full">
            <thead>
                <tr class="text-xs text-gray-500 border-b border-gray-100">
                    <th class="text-left py-2 font-medium">Permission</th>
                    <th class="text-center py-2 font-medium px-4"><span class="badge bg-red-100 text-red-800">Admin</span></th>
                    <th class="text-center py-2 font-medium px-4"><span class="badge bg-blue-100 text-blue-800">Manager</span></th>
                    <th class="text-center py-2 font-medium px-4"><span class="badge bg-gray-100 text-gray-700">Viewer</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach([
                    ['label' => 'View Dashboard & Sync Data', 'admin' => true, 'manager' => true, 'viewer' => true],
                    ['label' => 'View Webhook Logs',          'admin' => true, 'manager' => true, 'viewer' => false],
                    ['label' => 'Trigger Manual Sync',        'admin' => true, 'manager' => true, 'viewer' => false],
                    ['label' => 'Retry Failed Jobs',          'admin' => true, 'manager' => true, 'viewer' => false],
                    ['label' => 'Edit API Settings',          'admin' => true, 'manager' => false,'viewer' => false],
                    ['label' => 'Reveal Secret Keys',         'admin' => true, 'manager' => false,'viewer' => false],
                    ['label' => 'Manage Users',               'admin' => true, 'manager' => false,'viewer' => false],
                ] as $perm)
                <tr>
                    <td class="py-2.5 text-gray-700">{{ $perm['label'] }}</td>
                    @foreach(['admin', 'manager', 'viewer'] as $role)
                    <td class="py-2.5 text-center px-4">
                        @if($perm[$role])
                            <span class="text-green-600">✓</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
