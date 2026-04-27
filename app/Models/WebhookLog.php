<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'source',
        'topic',
        'shopify_webhook_id',
        'shop_domain',
        'payload',
        'hmac_valid',
        'processed',
        'processing_error',
        'job_dispatched_at',
    ];

    protected $casts = [
        'hmac_valid'         => 'boolean',
        'processed'          => 'boolean',
        'job_dispatched_at'  => 'datetime',
    ];

    public function getDecodedPayload(): array
    {
        return json_decode($this->payload, true) ?? [];
    }

    public function markProcessed(): void
    {
        $this->update(['processed' => true]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['processing_error' => $error]);
    }
}
