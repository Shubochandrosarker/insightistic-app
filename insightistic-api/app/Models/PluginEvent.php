<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'license_id', 'license_activation_id', 'event_type', 'payload_json', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'created_at'   => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public static function record(?License $license, ?LicenseActivation $activation, string $type, array $payload = []): void
    {
        static::create([
            'license_id'            => $license?->id,
            'license_activation_id' => $activation?->id,
            'event_type'            => $type,
            'payload_json'          => $payload ?: null,
            'created_at'            => now(),
        ]);
    }
}
