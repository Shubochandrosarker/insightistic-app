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

    public function getPdfLinkAttribute(): ?string
    {
        return $this->pdf_url ? $this->signedLink('reports.download.pdf') : null;
    }

    public function getHtmlLinkAttribute(): ?string
    {
        return $this->html_url ? $this->signedLink('reports.download.html') : null;
    }

    private function signedLink(string $routeName): string
    {
        $base = $this->canonicalOrigin(
            (string) config('insightistic.reports.public_url', config('app.url')),
        );
        $scheme = parse_url($base, PHP_URL_SCHEME);

        if ($base !== '' && is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
            // Generate and sign the URL against the configured canonical
            // origin, never against X-Forwarded-Port from an edge proxy.
            // The signature is calculated after this override, so removing a
            // stray :80 cannot turn a valid link into a 403.
            URL::forceRootUrl($base);
            URL::forceScheme(strtolower($scheme));
        }

        return URL::temporarySignedRoute($routeName, now()->addHour(), ['report' => $this->id]);
    }

    /**
     * Normalize the configured public origin before signing. In particular,
     * HTTPS with an accidentally forwarded :80 must become the canonical
     * HTTPS origin so the browser URL and the signature use the same route.
     */
    private function canonicalOrigin(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === '' || ! preg_match('#^https?://#i', $configured)) {
            return rtrim($configured, '/');
        }

        $parts = parse_url($configured);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($configured, '/');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        // 80/443 are default ports, regardless of whether a stale proxy
        // header or an old environment variable put the wrong one there.
        $portSuffix = $port !== null && ! in_array($port, [80, 443], true)
            ? ':'.$port
            : '';

        return $scheme.'://'.$host.$portSuffix;
    }
}
