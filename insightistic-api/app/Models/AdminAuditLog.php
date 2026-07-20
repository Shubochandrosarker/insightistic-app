<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id', 'action', 'target_type', 'target_id',
        'before_json', 'after_json', 'reason', 'ip_address', 'user_agent',
        'correlation_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json'  => 'array',
            'created_at'  => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
