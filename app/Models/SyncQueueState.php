<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncQueueState extends Model
{
    protected $table = 'sync_queue_state';

    protected $fillable = [
        'sync_type',
        'last_poll_at',
        'last_odoo_write_date',
        'is_running',
        'run_started_at',
        'notes',
    ];

    protected $casts = [
        'last_poll_at'   => 'datetime',
        'run_started_at' => 'datetime',
        'is_running'     => 'boolean',
    ];

    public static function forType(string $syncType): self
    {
        return static::firstOrCreate(
            ['sync_type' => $syncType],
            ['is_running' => false]
        );
    }

    public function markRunning(): void
    {
        $this->update([
            'is_running'     => true,
            'run_started_at' => now(),
        ]);
    }

    public function markComplete(string $writeDate): void
    {
        $this->update([
            'is_running'            => false,
            'last_poll_at'          => now(),
            'last_odoo_write_date'  => $writeDate,
            'run_started_at'        => null,
        ]);
    }
}
