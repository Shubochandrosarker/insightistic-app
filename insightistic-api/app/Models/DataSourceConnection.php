<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceConnection extends Model
{
    use BelongsToOrganization; // tenant-scoped + auto organization_id

    public const SOURCES = ['wordpress', 'woocommerce', 'ga4', 'gsc', 'pagespeed', 'uptime', 'cloudflare', 'engagement', 'email', 'bing'];

    /** Sources delivered by Insightistic itself; no plugin or credential is required. */
    public const BUILT_IN_SOURCES = ['pagespeed', 'uptime'];

    public const STATES = ['connected', 'not_configured', 'syncing', 'degraded', 'error', 'disabled'];

    protected $fillable = [
        'organization_id', 'site_id', 'source', 'state', 'connection_method', 'label',
        'last_attempted_at', 'last_successful_at', 'latest_data_at', 'freshness',
        'last_error_code', 'last_error_message', 'metadata_json',
    ];

    public const CONNECTION_METHODS = [
        'oauth', 'api_key', 'api_token', 'wordpress_plugin', 'shopify_app',
        'php_sdk', 'node_sdk', 'generic_api', 'manual',
    ];

    protected function casts(): array
    {
        return [
            'last_attempted_at'  => 'datetime',
            'last_successful_at' => 'datetime',
            'latest_data_at'     => 'datetime',
            'metadata_json'      => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Upsert the connection state for a (site, source) pair after a sync attempt. */
    public static function recordAttempt(int $orgId, int $siteId, string $source, array $attrs): self
    {
        return static::withoutGlobalScope('organization')->updateOrCreate(
            ['site_id' => $siteId, 'source' => $source],
            array_merge(['organization_id' => $orgId], $attrs),
        );
    }
}
