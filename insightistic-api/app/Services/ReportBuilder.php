<?php

namespace App\Services;

use App\Models\ClientReport;
use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportBuilder
{
    private bool $lastAiSummaryGenerated = false;

    public function __construct(
        private MetricsAssembler $metrics,
        private InsightGenerator $insights,
        private PdfRenderer $pdf,
    ) {}

    /**
     * Build a branded weekly/monthly report: HTML + PDF stored to disk.
     * The AI executive summary is generated inline (not metered as a separate
     * insight — the report itself is the metered unit).
     */
    public function generate(Site $site, string $type, bool $includeAi = true): ClientReport
    {
        $this->lastAiSummaryGenerated = false;
        $range = $this->rangeForType($type, $site);
        $org   = $site->organization;

        $bundle = $this->metrics->bundle($site, $range);
        $series = $this->metrics->dailySeries($site->id, $range);
        $ai     = $includeAi ? $this->insights->summarize($site, $range) : null;
        $this->lastAiSummaryGenerated = is_array($ai);

        $brand = $this->brand($org);
        $currency = $site->currency ?: 'USD';

        $title = ucfirst($type) . ' Report — ' . $site->name;

        $html = view('reports.standard', [
            'report'   => ['title' => $title, 'type' => $type],
            'brand'    => $brand,
            'site'     => $site,
            'currency' => $currency,
            'period'   => $bundle['period'],
            'metrics'  => $bundle['metrics'],
            'deltas'   => $bundle['deltas'],
            'series'   => $series,
            'topProducts'  => $bundle['top_products'],
            'topCustomers' => $bundle['top_customers'],
            'ai'       => $ai,
        ])->render();

        $disk = config('insightistic.reports.disk', 'public');
        $slug = Str::uuid()->toString();
        $base = "reports/{$org->id}/{$slug}";

        Storage::disk($disk)->put("{$base}.html", $html);
        Storage::disk($disk)->put("{$base}.pdf", $this->pdf->fromHtml($html));

        return ClientReport::create([
            'site_id'      => $site->id, // organization_id auto-filled by tenant scope
            'title'        => $title,
            'report_type'  => $type,
            'period_start' => $range->dateFrom(),
            'period_end'   => $range->dateTo(),
            'html_url'     => "{$base}.html", // stored as relative path; URL via accessor
            'pdf_url'      => "{$base}.pdf",
        ]);
    }

    /** Whether the most recent build actually included a provider summary. */
    public function aiSummaryGenerated(): bool
    {
        return $this->lastAiSummaryGenerated;
    }

    /** Remove an unbillable report after a concurrent wallet settlement fails. */
    public function discard(ClientReport $report): void
    {
        $disk = config('insightistic.reports.disk', 'public');
        Storage::disk($disk)->delete(array_values(array_filter([
            $report->getRawOriginal('html_url'),
            $report->getRawOriginal('pdf_url'),
        ])));
        $report->delete();
    }

    private function rangeForType(string $type, Site $site): DateRange
    {
        $period = $type === 'monthly' ? 'last_30_days' : 'last_7_days';
        return DateRange::fromRequest($period, null, null, $site->timezone ?: config('app.timezone', 'UTC'));
    }

    private function brand($org): array
    {
        $b = $org->brandSettings;
        return [
            'logo_url'      => $b->logo_url ?? null,
            'primary_color' => $b->primary_color ?? '#2563EB',
            'accent_color'  => $b->accent_color ?? '#10B981',
            'footer_text'   => $b->report_footer_text ?? ($org->name . ' · Powered by Insightistic'),
            'name'          => $org->name,
        ];
    }
}
