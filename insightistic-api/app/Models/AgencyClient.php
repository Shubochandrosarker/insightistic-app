<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The optional client/account entity of the agency portfolio (Phase 13,
 * doc 20 "Client grouping"): organization -> clients -> sites.
 *
 * A client is pure agency bookkeeping — a label to group sites by and bill
 * against. It carries no analytics of its own; the portfolio service
 * (AgencyPortfolio) reads per-site data and buckets it by client_id. Sites
 * are attached via the nullable sites.client_id FK, and deleting a client
 * unassigns its sites (client_id nulled) instead of deleting them.
 */
class AgencyClient extends Model
{
    use BelongsToOrganization; // auto tenant-scoped + auto-fills organization_id

    protected $fillable = ['organization_id', 'name', 'contact_email', 'notes'];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'client_id');
    }
}
