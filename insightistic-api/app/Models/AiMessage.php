<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiMessage extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'ai_thread_id', 'user_id', 'role', 'content',
        'answer_json', 'status', 'error_code',
    ];

    protected function casts(): array
    {
        return ['answer_json' => 'array'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'ai_thread_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(AiCitation::class);
    }

    public function run(): HasOne
    {
        return $this->hasOne(AiRun::class);
    }
}
