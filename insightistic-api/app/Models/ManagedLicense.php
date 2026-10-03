<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ManagedLicense extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'user_id', 'site_id', 'product_name', 'license_type',
        'provider', 'plan_name', 'license_key_encrypted', 'license_key_last4',
        'status', 'starts_at', 'expires_at', 'notes', 'metadata_json', 'archived_at',
    ];

    protected $hidden = ['license_key_encrypted'];

    protected function casts(): array
    {
        return [
            'license_key_encrypted' => 'encrypted',
            'metadata_json' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withoutGlobalScope('organization');
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->organization?->only(['id', 'name', 'slug']),
            'user' => $this->user?->only(['id', 'name', 'email']),
            'site' => $this->site?->only(['id', 'name', 'domain']),
            'product_name' => $this->product_name,
            'license_type' => $this->license_type,
            'provider' => $this->provider,
            'plan_name' => $this->plan_name,
            'masked_key' => $this->license_key_last4 ? '•••• ' . $this->license_key_last4 : null,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
