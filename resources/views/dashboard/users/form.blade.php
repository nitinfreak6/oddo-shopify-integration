@extends('dashboard.layout')
@section('title', $user->exists ? 'Edit User' : 'Add User')
@section('page-title', $user->exists ? 'Edit User' : 'Add User')

@section('content')
<div class="max-w-lg">
    <div class="mb-4">
        <a href="{{ route('dashboard.users.index') }}" class="text-sm text-indigo-600 hover:underline">← Back to users</a>
    </div>

    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        @if($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form method="POST"
              action="{{ $user->exists ? route('dashboard.users.update', $user) : route('dashboard.users.store') }}"
              class="space-y-5">
            @csrf
            @if($user->exists) @method('PUT') @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Password {{ $user->exists ? '(leave blank to keep current)' : '' }}
                </label>
                <input type="password" name="password" {{ $user->exists ? '' : 'required' }}
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            @if($user->exists || true)
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="password_confirmation"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="role" required
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="viewer"  {{ old('role', $user->role) === 'viewer'  ? 'selected' : '' }}>Viewer — read-only access</option>
                    <option value="manager" {{ old('role', $user->role) === 'manager' ? 'selected' : '' }}>Manager — can trigger syncs</option>
                    <option value="admin"   {{ old('role', $user->role) === 'admin'   ? 'selected' : '' }}>Admin — full access including API settings</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" id="is_active" value="1"
                       {{ old('is_active', $user->is_active ?? true) ? 'checked' : '' }}
                       class="h-4 w-4 text-indigo-600 rounded border-gray-300">
                <label for="is_active" class="text-sm text-gray-700">Active (can log in)</label>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                    {{ $user->exists ? 'Update User' : 'Create User' }}
                </button>
                <a href="{{ route('dashboard.users.index') }}"
                   class="border border-gray-200 text-gray-600 text-sm px-5 py-2 rounded-lg hover:bg-gray-50 transition">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
