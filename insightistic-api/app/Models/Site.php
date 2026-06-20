<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    use BelongsToOrganization; // auto tenant-scoped + auto-fills organization_id

    protected $fillable = [
        'organization_id', 'name', 'domain', 'platform',
        'connector_key_id', 'connector_secret', 'connection_status', 'last_sync_at',
        'timezone', 'currency', 'wp_version', 'wc_version', 'plugin_version',
    ];

    // Never expose credentials in API responses.
    protected $hidden = ['connector_key_id', 'connector_secret'];

    protected function casts(): array
    {
        return [
            'last_sync_at'     => 'datetime',
            'connector_secret' => 'encrypted', // AES at rest via APP_KEY
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WcOrder::class);
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
}
