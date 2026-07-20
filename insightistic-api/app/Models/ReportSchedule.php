<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSchedule extends Model
{
    protected $fillable = [
        'site_id', 'frequency', 'recipients', 'send_day', 'send_time', 'timezone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return string[] */
    public function recipientList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->recipients))));
    }
}
