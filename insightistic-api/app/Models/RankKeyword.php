<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tracked keyword of one site (Phase 10, doc 13 §Domain model).
 *
 * The check identity is the full configuration tuple (keyword, engine,
 * country, language, device, location), not just the keyword string: the
 * same phrase tracked for desktop+mobile or in+us are SEPARATE rows. That
 * identity is hashed into config_hash and UNIQUE'd per site — use
 * makeConfigHash() when creating rows so the DB uniqueness and the
 * controller's duplicate check always agree.
 */
class RankKeyword extends Model
{
    public const ENGINE_GOOGLE = 'google';

    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_MOBILE = 'mobile';

    public const ENGINES = [self::ENGINE_GOOGLE];
    public const DEVICES = [self::DEVICE_DESKTOP, self::DEVICE_MOBILE];

    protected $fillable = [
        'site_id', 'keyword', 'engine', 'country', 'language', 'device',
        'location', 'active', 'last_checked_at', 'config_hash',
    ];

    protected function casts(): array
    {
        return [
            'active'          => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RankObservation::class, 'keyword_id');
    }

    /**
     * Canonical identity hash of one check configuration — shared with the
     * API layer so a POST that computes this hash can rely on the UNIQUE
     * (site_id, config_hash) constraint to reject exact duplicates.
     *
     * Normalization: every component is trimmed and lowercased before the
     * join (keyword casing is irrelevant to a search engine, and 'US' vs
     * 'us' must not create a second row for the same market).
     */
    public static function makeConfigHash(
        string $keyword,
        string $engine,
        ?string $country,
        ?string $language,
        string $device,
        ?string $location,
    ): string {
        $parts = [$keyword, $engine, $country ?? '', $language ?? '', $device, $location ?? ''];

        return sha1(implode('|', array_map(
            static fn (string $part): string => mb_strtolower(trim($part)),
            $parts,
        )));
    }
}
