<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'direction',
        'entity_type',
        'entity_id',
        'action',
        'status',
        'job_id',
        'request_payload',
        'response_payload',
        'error_message',
        'error_context',
        'attempts',
        'synced_at',
    ];

    protected $casts = [
        'error_context' => 'array',
        'synced_at'     => 'datetime',
    ];

    const DIRECTION_ODOO_TO_SHOPIFY  = 'odoo_to_shopify';
    const DIRECTION_SHOPIFY_TO_ODOO  = 'shopify_to_odoo';

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS    = 'success';
    const STATUS_FAILED     = 'failed';
    const STATUS_SKIPPED    = 'skipped';

    public function markSuccess(string $response = ''): void
    {
        $this->update([
            'status'           => self::STATUS_SUCCESS,
            'response_payload' => $response,
            'synced_at'        => now(),
        ]);
    }

    public function markFailed(string $error, array $context = []): void
    {
        $this->update([
            'status'        => self::STATUS_FAILED,
            'error_message' => $error,
            'error_context' => $context,
            'attempts'      => $this->attempts + 1,
        ]);
    }
}
