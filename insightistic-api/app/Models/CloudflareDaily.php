<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudflareDaily extends Model
{
    // Singular table name: Eloquent's default guess would be
    // "cloudflare_dailies" (same Doctrine Inflector trap as TrafficDaily).
    protected $table = 'cloudflare_daily';

    protected $fillable = [
        'site_id', 'date',
        'requests', 'bytes', 'cached_requests', 'cached_bytes', 'encrypted_requests',
        'pageviews', 'threats', 'client_4xx', 'client_5xx',
        'countries_json', 'status_breakdown_json', 'firewall_json',
    ];

    protected function casts(): array
    {
        return [
            'date'                  => 'date',
            'countries_json'        => 'array',
            'status_breakdown_json' => 'array',
            'firewall_json'         => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
