<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiThread extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'site_id', 'user_id', 'title', 'context_json',
        'archived_at', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'context_json'    => 'array',
            'archived_at'     => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
