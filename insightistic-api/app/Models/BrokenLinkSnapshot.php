<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokenLinkSnapshot extends Model
{
    protected $fillable = [
        'site_id', 'path', 'first_seen_at', 'last_seen_at', 'hit_count',
        'referrer_hosts', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at'  => 'datetime',
            'last_seen_at'   => 'datetime',
            'referrer_hosts' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
