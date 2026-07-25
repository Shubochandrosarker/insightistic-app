<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInsight extends Model
{
    use BelongsToOrganization; // tenant-scoped + auto organization_id

    protected $fillable = [
        'organization_id', 'site_id', 'type', 'title', 'summary', 'recommendation',
        'severity', 'priority_score', 'source_data_json', 'ai_model', 'status',
        'saved_at', 'dismissed_at', 'expires_at', 'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'source_data_json' => 'array',
            'saved_at'         => 'datetime',
            'dismissed_at'     => 'datetime',
            'expires_at'       => 'datetime',
            'actioned_at'      => 'datetime',
        ];
    }

    /**
     * Past its freshness window.
     *
     * Stale insights are still readable — the analysis was true when it was
     * made — but the UI marks them so nobody acts on a week-old reading of a
     * fast-moving metric as if it were current.
     */
    public function isStale(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The recommended actions, as a plain list.
     *
     * Contextual insights store them in the contract; the legacy generator
     * only has a single `recommendation` string, so fall back to that rather
     * than returning an empty list for older rows.
     */
    public function actions(): array
    {
        $actions = $this->source_data_json['actions'] ?? null;

        if (is_array($actions) && $actions !== []) {
            return array_values(array_filter(array_map('strval', $actions)));
        }

        return $this->recommendation ? [$this->recommendation] : [];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
