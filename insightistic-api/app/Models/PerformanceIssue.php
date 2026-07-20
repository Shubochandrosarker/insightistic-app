<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceIssue extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'performance_run_id', 'audit_key', 'category', 'title',
        'severity', 'savings_ms', 'savings_bytes', 'display_value', 'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PerformanceRun::class, 'performance_run_id');
    }
}
