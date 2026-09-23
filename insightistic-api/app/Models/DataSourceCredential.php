<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OAuth grant / API token for one (site, source) pair.
 *
 * SECURITY: this model is never returned from a controller. Only
 * `publicSummary()` is safe to serialise — it exposes which account is
 * connected and what resource is selected, and no token material at all.
 */
class DataSourceCredential extends Model
{
    use BelongsToOrganization;

    /** Sources that are connected directly by the SaaS rather than the plugin. */
    public const DIRECT_SOURCES = ['ga4', 'gsc', 'cloudflare', 'bing'];

    public const PROVIDER_FOR_SOURCE = [
        'ga4'        => 'google',
        'gsc'        => 'google',
        'cloudflare' => 'cloudflare',
        'bing'       => 'bing',
    ];

    protected $fillable = [
        'organization_id', 'provider_account_id', 'site_id', 'source', 'provider',
        'access_token', 'refresh_token', 'token_expires_at',
        'scopes_json', 'external_account_id', 'resource_json',
        'connected_by_user_id',
    ];

    /**
     * Tokens are hidden as a second line of defence: even an accidental
     * `->toArray()` on this model cannot put a refresh token on the wire.
     */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token'     => 'encrypted',
            'refresh_token'    => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes_json'      => 'array',
            'resource_json'    => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The shared org-level grant this credential draws tokens from (spec §5). */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /** True when the access token is missing or within 60s of expiring. */
    public function needsRefresh(): bool
    {
        if (! $this->access_token) {
            return true;
        }

        return $this->token_expires_at !== null
            && $this->token_expires_at->subSeconds(60)->isPast();
    }

    /** The only shape of this record that may ever reach the browser. */
    public function publicSummary(): array
    {
        return [
            'connected_account' => $this->external_account_id,
            'resource'          => $this->resource_json,
            'scopes'            => $this->scopes_json ?? [],
            'connected_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
