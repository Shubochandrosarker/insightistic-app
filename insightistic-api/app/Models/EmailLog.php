<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'user_id', 'email_type', 'period_key', 'recipient',
        'subject', 'status', 'provider_message_id', 'sent_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** True if this (org, type, period) email has already been queued or sent. */
    public static function alreadySent(int $organizationId, string $type, string $periodKey = 'once'): bool
    {
        return static::where('organization_id', $organizationId)
            ->where('email_type', $type)
            ->where('period_key', $periodKey)
            ->whereIn('status', ['queued', 'sent', 'skipped'])
            ->exists();
    }
}
