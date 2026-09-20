<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudflareBotDaily extends Model
{
    // Singular table name (see CloudflareDaily).
    protected $table = 'cloudflare_bot_daily';

    protected $fillable = [
        'site_id', 'date', 'bot_name', 'classification', 'requests', 'blocked',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
