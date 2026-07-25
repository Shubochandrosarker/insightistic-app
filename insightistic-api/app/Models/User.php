<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * SECURITY: `is_super_admin` is deliberately NOT mass-assignable. Every
     * promotion goes through an explicit forceFill/property write in the
     * audited paths (Admin\UserController, the insightistic:super-admin:*
     * commands) — never through request-shaped input.
     */
    protected $fillable = [
        'name', 'email', 'password', 'status',
        'provider', 'provider_id', 'avatar_url', 'email_verified_at', 'last_login_at',
        'archived_at',
    ];

    /**
     * MFA material is hidden as well as encrypted: an accidental toArray()
     * must not be able to put a TOTP secret or a recovery code on the wire.
     */
    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'last_login_at'      => 'datetime',
            'password'           => 'hashed',
            'is_super_admin'     => 'boolean',
            'archived_at'        => 'datetime',
            'mfa_secret'         => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at'   => 'datetime',
        ];
    }

    /**
     * MFA is active only once a generated code has been verified — a secret
     * on its own means enrolment was started and never finished.
     */
    public function hasMfa(): bool
    {
        return $this->mfa_secret !== null && $this->mfa_confirmed_at !== null;
    }

    /**
     * Always store email lowercased, regardless of write path (registration,
     * invites, OAuth, admin-created accounts) — login looks up by exact
     * match, so any casing drift here means a correct password stops working.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : strtolower(trim($value)),
        );
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Role of this user within a given organization, or null. */
    public function roleIn(Organization $organization): ?string
    {
        $pivot = $this->organizations()
            ->where('organizations.id', $organization->id)
            ->first()?->pivot;

        return $pivot?->role;
    }

    public function ownsOrganizations()
    {
        return $this->hasMany(Organization::class, 'owner_user_id');
    }
}
