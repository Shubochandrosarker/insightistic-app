<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_user_id', 'target_user_id', 'personal_access_token_id',
        'reason', 'elevated', 'ip_address', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'elevated'   => 'boolean',
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
