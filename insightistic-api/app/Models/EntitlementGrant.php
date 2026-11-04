<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EntitlementGrant extends Model
{
    use BelongsToOrganization;

    /** One-time lifetime purchases, one source per billing provider. */
    public const LIFETIME_SOURCES = ['paddle_lifetime', 'stripe_lifetime'];

    public static function isLifetimeSource(?string $source): bool
    {
        return in_array($source, self::LIFETIME_SOURCES, true);
    }

    protected $fillable = [
        'organization_id', 'plan_id', 'source', 'source_reference',
        'features_json', 'limits_json', 'starts_at', 'ends_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'features_json' => 'array',
            'limits_json'   => 'array',
            'starts_at'     => 'datetime',
            'ends_at'       => 'datetime',
            'revoked_at'    => 'datetime',
        ];
    }

    /** Open-ended unless bounded: null bounds never exclude, revocation does. */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
