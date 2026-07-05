<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    use BelongsToOrganization;

    public const STATUSES = [
        'active', 'trialing', 'expired', 'cancelled', 'suspended', 'payment_failed', 'revoked',
    ];

    /** Statuses under which advanced features stay unlocked. */
    public const ENTITLED_STATUSES = ['active', 'trialing', 'payment_failed'];

    protected $fillable = [
        'organization_id', 'plan_id', 'subscription_id',
        'license_key_hash', 'license_key_last4', 'status',
        'activation_limit', 'expires_at', 'last_validated_at', 'revoked_at',
    ];

    // The hash must never leave the API.
    protected $hidden = ['license_key_hash'];

    protected function casts(): array
    {
        return [
            'expires_at'        => 'datetime',
            'last_validated_at' => 'datetime',
            'revoked_at'        => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function activeActivations(): HasMany
    {
        return $this->activations()->where('status', 'active');
    }

    /** Production activations count against the plan limit; staging/local don't. */
    public function countedActivations(): int
    {
        return $this->activeActivations()->where('environment', 'production')->count();
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, self::ENTITLED_STATUSES, true);
    }

    /** Masked display form, e.g. "insightistic_ ************ 7f3a". */
    public function maskedKey(): string
    {
        return 'insightistic_••••••••••••••••••••••' . $this->license_key_last4;
    }
}
