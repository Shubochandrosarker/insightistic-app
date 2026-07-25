<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AiFeedback extends Model
{
    use BelongsToOrganization;

    protected $table = 'ai_feedback';

    protected $fillable = [
        'organization_id', 'ai_message_id', 'user_id', 'rating', 'comment',
    ];
}
