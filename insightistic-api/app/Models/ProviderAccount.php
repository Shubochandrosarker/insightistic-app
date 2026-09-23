<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE provider authorization shared by every site in the organization
 * (spec §5). One Google consent → GSC + GA4 for all websites; an agency
 * never re-authorizes per site.
 *
 * SECURITY: tokens are encrypted at rest and hidden on the model. Nothing
 * but ProviderAccountManager / GoogleOAuthService ever reads them, and no
 * controller ever serialises this model.
 */
class ProviderAccount extends Model
{
    use BelongsToOrganization;

    public const PROVIDERS = ['google', 'bing', 'cloudflare', 'shopify'];

    protected $fillable = [
        'organization_id', 'provider', 'external_account_id', 'email', 'display_name',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes_json',
        'metadata_json', 'connected_by_user_id', 'status',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token'    => 'encrypted',
            'refresh_token'   => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes_json'     => 'array',
            'metadata_json'   => 'array',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DataSourceCredential::class);
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
            'provider'      => $this->provider,
            'account_email' => $this->email,
            'account_id'    => $this->external_account_id,
            'scopes'        => $this->scopes_json ?? [],
            'status'        => $this->status,
            'connected_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
