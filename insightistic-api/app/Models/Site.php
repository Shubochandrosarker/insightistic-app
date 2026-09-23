<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    use BelongsToOrganization; // auto tenant-scoped + auto-fills organization_id

    protected $fillable = [
        'organization_id', 'name', 'domain', 'normalized_domain', 'canonical_url',
        'platform', 'detected_platform', 'platform_override', 'country', 'language', 'status',
        'connector_key_id', 'connector_secret', 'connection_status', 'last_sync_at',
        'timezone', 'currency', 'wp_version', 'wc_version', 'plugin_version',
        'cloudflare_zone_id', 'cloudflare_account_id', 'cloudflare_api_token',
        'archived_at', 'client_id', // optional agency portfolio grouping (Phase 13, doc 20)
    ];

    // Never expose credentials in API responses.
    protected $hidden = ['connector_key_id', 'connector_secret', 'cloudflare_api_token'];

    protected function casts(): array
    {
        return [
            'last_sync_at'          => 'datetime',
            'connector_secret'      => 'encrypted', // AES at rest via APP_KEY
            'cloudflare_api_token'  => 'encrypted', // AES at rest via APP_KEY
            'archived_at'           => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WcOrder::class);
    }

    /** The optional agency portfolio client this site is grouped under (null = unassigned). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(AgencyClient::class, 'client_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(WcProduct::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(WcCustomer::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    /**
     * Generate fresh HMAC connector credentials and return the ONE-TIME setup
     * token the user pastes into the plugin: "<key_id>.<secret>".
     *
     * - key_id : public, stored plain, indexed, sent on every request.
     * - secret : private, stored AES-encrypted, used only to sign/verify.
     */
    public function issueConnectorToken(): string
    {
        $keyId  = 'ik_' . Str::random(24);
        $secret = 'sk_' . Str::random(48);

        $this->connector_key_id = $keyId;
        $this->connector_secret = $secret; // encrypted by cast on save
        $this->save();

        return $keyId . '.' . $secret;
    }

    public static function findByKeyId(string $keyId): ?self
    {
        return static::withoutGlobalScope('organization')
            ->where('connector_key_id', $keyId)
            ->first();
    }

    /** Whether this site has a Cloudflare zone linked (the hosted Traffic Insights path). */
    public function hasCloudflareLinked(): bool
    {
        return ! empty($this->cloudflare_zone_id) && ! empty($this->cloudflare_api_token);
    }

    /**
     * The platform this site runs on, as far as we know: an explicit user
     * override beats detection beats the legacy column. Platform is
     * METADATA (copy, connector suggestions) — never a feature gate.
     */
    public function resolvedPlatform(): string
    {
        return $this->platform_override
            ?? $this->detected_platform
            ?? $this->platform
            ?? 'generic';
    }

    /** True when this site is (or plausibly runs) WordPress/WooCommerce. */
    public function isWordPressFamily(): bool
    {
        return in_array($this->resolvedPlatform(), ['wordpress', 'woocommerce'], true);
    }
}
