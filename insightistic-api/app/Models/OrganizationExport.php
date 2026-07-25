<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested export of an organization's data.
 *
 * The archive itself lives on a disk; this row is the handle plus the
 * expiry that stops a full tenant dump lingering on storage forever.
 */
class OrganizationExport extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'requested_by_user_id', 'status',
        'disk', 'storage_path', 'size_bytes', 'error_message',
        'completed_at', 'expires_at',
    ];

    /** Never serialise where the archive lives — it is fetched by id only. */
    protected $hidden = ['storage_path', 'disk'];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready'
            && $this->storage_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function toApiArray(): array
    {
        return [
            'id'           => $this->id,
            'status'       => $this->status,
            'size_bytes'   => $this->size_bytes,
            'error'        => $this->error_message,
            'requested_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at'   => $this->expires_at?->toIso8601String(),
            'downloadable' => $this->isDownloadable(),
        ];
    }
}
