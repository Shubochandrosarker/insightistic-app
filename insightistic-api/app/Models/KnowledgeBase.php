<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    use BelongsToOrganization;

    public const SCOPES = ['organization', 'site', 'global'];

    protected $fillable = [
        'organization_id', 'site_id', 'name', 'description', 'scope', 'status', 'settings_json',
    ];

    protected function casts(): array
    {
        return ['settings_json' => 'array'];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
