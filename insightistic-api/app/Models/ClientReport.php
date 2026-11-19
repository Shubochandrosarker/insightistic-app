<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class ClientReport extends Model
{
    use BelongsToOrganization; // tenant-scoped + auto organization_id

    protected $fillable = [
        'organization_id', 'site_id', 'title', 'report_type',
        'period_start', 'period_end', 'html_url', 'pdf_url', 'sent_to', 'sent_at',
    ];

    // pdf_url / html_url hold RELATIVE storage paths; expose signed, expiring
    // download links in JSON instead — never the raw public-disk URL. A raw
    // `Storage::url()` link is permanent and guessable (sequential/UUID path
    // on a public disk) and breaks outright if `APP_URL` doesn't match the
    // disk's configured base — which is exactly what caused reports to be
    // unopenable. ReportController::downloadPdf/downloadHtml verify the
    // signature and stream the file directly, so these links work regardless
    // of `APP_URL` drift or a missing `public/storage` symlink.
    protected $appends = ['pdf_link', 'html_link', 'html_download_link'];

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

    public function getPdfLinkAttribute(): ?string
    {
        return $this->pdf_url ? $this->signedLink('reports.download.pdf') : null;
    }

    public function getHtmlLinkAttribute(): ?string
    {
        return $this->html_url ? $this->signedLink('reports.download.html') : null;
    }

    public function getHtmlDownloadLinkAttribute(): ?string
    {
        return $this->html_url ? $this->signedLink('reports.download.html', ['download' => 1]) : null;
    }

    private function signedLink(string $routeName, array $options = []): string
    {
        // Sign the route and query before adding the public origin. A proxy's
        // host, scheme or internal port cannot affect the signature.
        $configured = config(app()->isProduction() ? 'insightistic.app_url' : 'app.url');
        $origin = parse_url((string) $configured);
        $scheme = strtolower($origin['scheme'] ?? 'https');
        $host = $origin['host'] ?? '';
        $port = $origin['port'] ?? null;
        $suffix = $port && ! in_array($port, [80, 443], true) ? ':'.$port : '';
        $path = URL::temporarySignedRoute($routeName, now()->addHour(), ['report' => $this->id] + $options, absolute: false);

        return $host !== '' ? $scheme.'://'.$host.$suffix.$path : $path;
    }
}
