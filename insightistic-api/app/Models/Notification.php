<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'user_id', 'type', 'title', 'message',
        'action_url', 'status', 'created_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'read_at'    => 'datetime',
        ];
    }
}
