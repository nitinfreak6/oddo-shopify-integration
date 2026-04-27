<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    const ROLE_ADMIN   = 'admin';
    const ROLE_MANAGER = 'manager';
    const ROLE_VIEWER  = 'viewer';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // ── Role helpers ──────────────────────────────────────────
    public function isAdmin(): bool   { return $this->role === self::ROLE_ADMIN; }
    public function isManager(): bool { return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MANAGER]); }
    public function isViewer(): bool  { return true; } // all roles can view

    public function hasPermission(string $permission): bool
	{
        if (in_array($permission, ['manage-settings', 'manage-users', 'reveal-secrets'], true)) {
            return $this->isAdmin();
        }

        if (in_array($permission, [
            'trigger-sync',
            'view-products',
            'view-orders',
            'view-inventory',
            'view-logs',
            'view-webhooks',
            'sync-inventory',
            'sync-products',
        ], true)) {
            return $this->isManager();
        }

        if ($permission === 'view-dashboard') {
            return $this->isViewer();
        }

        return false;
	}

    public function roleBadgeColor(): string
    {
        return match ($this->role) {
            'admin'   => 'bg-red-100 text-red-800',
            'manager' => 'bg-blue-100 text-blue-800',
            default   => 'bg-gray-100 text-gray-700',
        };
    }
}
