<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day's median/MAD anomaly finding for one metric (spec: Anomaly Engine, P7). See docs/ANOMALY-ENGINE.md. */
class Anomaly extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'site_id', 'metric_id', 'date', 'value', 'baseline_median',
        'mad', 'score', 'direction', 'severity', 'is_acknowledged', 'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'float',
            'baseline_median' => 'float',
            'mad' => 'float',
            'score' => 'float',
            'is_acknowledged' => 'boolean',
            'detected_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
