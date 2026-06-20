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
    ];

    protected function casts(): array
    {
        return ['source_data_json' => 'array'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
