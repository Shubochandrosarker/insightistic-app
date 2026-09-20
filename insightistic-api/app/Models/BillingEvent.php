<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per provider billing event id we have accepted for processing.
 *
 * Not tenant-scoped: webhooks arrive with no authenticated organization, and
 * the ledger is a platform-level record rather than customer data.
 */
class BillingEvent extends Model
{
    protected $fillable = [
        'provider', 'provider_event_id', 'provider_notification_id',
        'event_type', 'occurred_at', 'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at'  => 'datetime',
            'processed_at' => 'datetime',
            'attempts'     => 'integer',
        ];
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereIn('processing_status', ['pending', 'processing']);
    }
}
