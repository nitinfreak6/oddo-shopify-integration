<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmazonFeedJob extends Model
{
    protected $fillable = [
        'feed_id',
        'feed_type',
        'odoo_entity_type',
        'odoo_entity_id',
        'status',
        'result_document_id',
        'processing_summary',
        'poll_attempts',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    const STATUS_SUBMITTED    = 'submitted';
    const STATUS_IN_PROGRESS  = 'in_progress';
    const STATUS_CANCELLED    = 'cancelled';
    const STATUS_DONE         = 'done';
    const STATUS_FATAL        = 'fatal';

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FATAL, self::STATUS_CANCELLED]);
    }
}
