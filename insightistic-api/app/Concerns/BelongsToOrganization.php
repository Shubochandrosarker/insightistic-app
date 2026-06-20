<?php

namespace App\Concerns;

use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to any model that has an organization_id column.
 *
 *   1. Adds a global scope so every query is automatically filtered to the
 *      current tenant. A site (or insight, report, etc.) from another org is
 *      simply invisible — not a 403, it does not exist for this request.
 *   2. Auto-fills organization_id on create from the current tenant.
 *
 * To bypass intentionally (e.g. Stripe webhook, admin tooling, console):
 *   Model::withoutGlobalScope('organization')->...
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $tenancy = app(Tenancy::class);
            if ($tenancy->check()) {
                $model = $builder->getModel();
                $builder->where($model->getTable() . '.organization_id', $tenancy->id());
            }
        });

        static::creating(function ($model) {
            $tenancy = app(Tenancy::class);
            if ($tenancy->check() && empty($model->organization_id)) {
                $model->organization_id = $tenancy->id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
