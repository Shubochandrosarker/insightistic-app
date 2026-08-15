<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A read-only, multi-metric diagnostic run (spec: WPAgentistic Investigations, P8). See docs/WPAGENTISTIC-INVESTIGATIONS.md. */
class Investigation extends Model
{
    use BelongsToOrganization;

    public const TRIGGER_SOURCES = ['manual', 'alert', 'anomaly'];

    protected $fillable = [
        'organization_id', 'site_id', 'requested_by_user_id', 'trigger_source', 'trigger_ref_id',
        'subject', 'status', 'findings_json', 'narrative_json',
    ];

    protected function casts(): array
    {
        return [
            'findings_json' => 'array',
            'narrative_json' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'completed';
    }
}
