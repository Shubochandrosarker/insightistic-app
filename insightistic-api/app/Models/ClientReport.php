<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientReport extends Model
{
    use BelongsToOrganization; // tenant-scoped + auto organization_id

    protected $fillable = [
        'organization_id', 'site_id', 'title', 'report_type',
        'period_start', 'period_end', 'html_url', 'pdf_url', 'sent_to', 'sent_at',
    ];

    // pdf_url / html_url hold RELATIVE storage paths; expose absolute links in JSON.
    protected $appends = ['pdf_link', 'html_link'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end'   => 'date',
            'sent_at'      => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    private function disk(): string
    {
        return config('insightistic.reports.disk', 'public');
    }

    public function getPdfLinkAttribute(): ?string
    {
        return $this->pdf_url ? Storage::disk($this->disk())->url($this->pdf_url) : null;
    }

    public function getHtmlLinkAttribute(): ?string
    {
        return $this->html_url ? Storage::disk($this->disk())->url($this->html_url) : null;
    }
}
