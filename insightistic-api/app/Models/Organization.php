<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    protected $fillable = [
        'name', 'slug', 'owner_user_id', 'plan_id', 'status', 'trial_ends_at',
        'archived_at', 'internal_notes', 'first_touch_json', 'last_touch_json',
        'marketing_consent', 'marketing_consent_at',
        'trial_started_at', 'trial_plan_slug', 'paddle_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at'          => 'datetime',
            'trial_started_at'       => 'datetime',
            'archived_at'            => 'datetime',
            'deletion_requested_at'  => 'datetime',
            'deletion_scheduled_for' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
            'first_touch_json'       => 'array',
            'last_touch_json'        => 'array',
            'marketing_consent'      => 'boolean',
            'marketing_consent_at'   => 'datetime',
        ];
    }

    /** A deletion has been requested and the grace window has not elapsed. */
    public function isPendingDeletion(): bool
    {
        return $this->deletion_scheduled_for !== null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function brandSettings(): HasOne
    {
        return $this->hasOne(BrandSettings::class);
    }

    public function currentUsage(): HasOne
    {
        return $this->hasOne(UsageCounter::class)
            ->where('period', now()->format('Y-m'));
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }
}
