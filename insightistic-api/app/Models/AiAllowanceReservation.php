<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** One AI action's credit hold, from reserve to commit/release. */
class AiAllowanceReservation extends Model
{
    use BelongsToOrganization;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'organization_id', 'action', 'run_reference', 'cost',
        'status', 'committed_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'integer',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
