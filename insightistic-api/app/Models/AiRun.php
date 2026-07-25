<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'ai_message_id', 'provider', 'model', 'prompt_version',
        'latency_ms', 'tokens_in', 'tokens_out', 'cost_usd', 'status', 'error_message',
    ];
}
