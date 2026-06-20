<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'period',
        'ai_insights_used', 'reports_generated', 'sites_connected',
    ];

    /** Get-or-create the counter row for the org's current month. */
    public static function currentFor(Organization $org): self
    {
        return static::withoutGlobalScope('organization')->firstOrCreate(
            ['organization_id' => $org->id, 'period' => now()->format('Y-m')],
        );
    }
}
