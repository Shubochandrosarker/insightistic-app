<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\EngagementDaily;
use App\Models\PerformanceRun;
use App\Models\SeoDaily;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\TrafficDimensionSnapshot;
use App\Support\DateRange;

/**
 * Assembles authorized, server-side evidence for contextual AI insights
 * (spec §11.1). One assembler, one method per context type — matching this
 * codebase's existing preference (see MetricsAssembler) for a single
 * cohesive service over many near-empty classes.
 *
 * Every method returns the same shape:
 *   ['has_data' => bool, 'evidence' => [{label,value,source,date_range}], 'raw' => [...]]
 * `evidence` is what gets stored alongside the generated insight for audit
 * (spec §11.2: "Store source evidence with the insight so it remains
 * auditable"); `raw` is the fuller numeric context handed to the AI
 * provider as its only source of truth — the browser never supplies
 * trusted metrics (spec §11.1: "Do not accept arbitrary raw metrics from
 * the browser as trusted AI evidence").
 */
class ContextAssembler
{
    public const TYPES = [
        'executive', 'traffic', 'seo', 'performance', 'commerce',
        'products', 'customers', 'content', 'alerts', 'report',
    ];

    public function __construct(private MetricsAssembler $commerce) {}

    public function assemble(string $type, Site $site, DateRange $range, ?string $focus = null): array
    {
        return match ($type) {
            'traffic'     => $this->traffic($site, $range),
            'seo'         => $this->seo($site, $range),
            'performance' => $this->performance($site, $focus),
            'commerce'    => $this->commerceCtx($site, $range),
            'products'    => $this->products($site, $range),
            'customers'   => $this->customers($site),
            'content'     => $this->content($site, $range),
            'alerts'      => $this->alerts($site),
            'executive', 'report' => $this->executive($site, $range),
            default => ['has_data' => false, 'evidence' => [], 'raw' => []],
        };
    }

    private function traffic(Site $site, DateRange $range): array
    {
        $rows = TrafficDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->get(['sessions', 'users', 'conversions', 'engaged_sessions']);

        $sessions = (int) $rows->sum('sessions');
        $dateRange = "{$range->dateFrom()} to {$range->dateTo()}";

        $topChannel = TrafficDimensionSnapshot::where('site_id', $site->id)
            ->where('dimension_type', 'channel')
            ->where('period_start', '>=', $range->dateFrom())
            ->orderByDesc('sessions')
            ->first(['dimension_value', 'sessions']);

        $evidence = [
            ['label' => 'Sessions', 'value' => $sessions, 'source' => 'ga4', 'date_range' => $dateRange],
            ['label' => 'Users', 'value' => (int) $rows->sum('users'), 'source' => 'ga4', 'date_range' => $dateRange],
        ];
        if ($topChannel) {
            $evidence[] = ['label' => 'Top channel', 'value' => "{$topChannel->dimension_value} ({$topChannel->sessions} sessions)", 'source' => 'ga4', 'date_range' => $dateRange];
        }

        return [
            'has_data' => $sessions > 0,
            'evidence' => $evidence,
            'raw' => ['sessions' => $sessions, 'users' => (int) $rows->sum('users'), 'conversions' => (int) $rows->sum('conversions'), 'top_channel' => $topChannel?->dimension_value],
        ];
    }

    private function seo(Site $site, DateRange $range): array
    {
        $rows = SeoDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->get(['clicks', 'impressions']);

        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');
        $dateRange = "{$range->dateFrom()} to {$range->dateTo()}";

        $opportunities = SeoQuerySnapshot::where('site_id', $site->id)
            ->where('period_start', '>=', $range->dateFrom())
            ->whereNotNull('opportunity')
            ->orderByDesc('impressions')
            ->limit(3)
            ->get(['query', 'opportunity', 'impressions']);

        $evidence = [
            ['label' => 'Clicks', 'value' => $clicks, 'source' => 'gsc', 'date_range' => $dateRange],
            ['label' => 'Impressions', 'value' => $impressions, 'source' => 'gsc', 'date_range' => $dateRange],
        ];
        foreach ($opportunities as $o) {
            $evidence[] = ['label' => 'Query opportunity', 'value' => "\"{$o->query}\" — {$o->opportunity}", 'source' => 'gsc', 'date_range' => $dateRange];
        }

        return [
            'has_data' => $clicks > 0 || $impressions > 0,
            'evidence' => $evidence,
            'raw' => ['clicks' => $clicks, 'impressions' => $impressions, 'opportunities' => $opportunities->pluck('query')->all()],
        ];
    }

    private function performance(Site $site, ?string $focus): array
    {
        $strategy = in_array($focus, ['mobile', 'desktop'], true) ? $focus : 'mobile';

        $run = PerformanceRun::where('site_id', $site->id)
            ->where('strategy', $strategy)
            ->where('status', 'complete')
            ->orderByDesc('run_at')
            ->first();

        if (! $run) {
            return ['has_data' => false, 'evidence' => [], 'raw' => []];
        }

        $dateRange = optional($run->run_at)->toDateString();

        return [
            'has_data' => true,
            'evidence' => [
                ['label' => 'Performance score', 'value' => $run->performance_score, 'source' => 'pagespeed', 'date_range' => $dateRange],
                ['label' => 'LCP', 'value' => $run->lcp_ms . 'ms (' . $run->lcp_status . ')', 'source' => 'pagespeed', 'date_range' => $dateRange],
                ['label' => 'INP', 'value' => $run->inp_ms . 'ms (' . $run->inp_status . ')', 'source' => 'pagespeed', 'date_range' => $dateRange],
                ['label' => 'CLS', 'value' => $run->cls . ' (' . $run->cls_status . ')', 'source' => 'pagespeed', 'date_range' => $dateRange],
            ],
            'raw' => $run->only(['performance_score', 'accessibility_score', 'lcp_ms', 'lcp_status', 'inp_ms', 'inp_status', 'cls', 'cls_status', 'strategy']),
        ];
    }

    private function commerceCtx(Site $site, DateRange $range): array
    {
        $bundle = $this->commerce->bundle($site, $range);
        $dateRange = "{$range->dateFrom()} to {$range->dateTo()}";

        return [
            'has_data' => ($bundle['metrics']['orders'] ?? 0) > 0,
            'evidence' => [
                ['label' => 'Revenue', 'value' => $bundle['metrics']['revenue'] ?? 0, 'source' => 'woocommerce', 'date_range' => $dateRange],
                ['label' => 'Orders', 'value' => $bundle['metrics']['orders'] ?? 0, 'source' => 'woocommerce', 'date_range' => $dateRange],
            ],
            'raw' => $bundle,
        ];
    }

    private function products(Site $site, DateRange $range): array
    {
        $top = $this->commerce->topProducts($site, $range, 5);
        $dateRange = "{$range->dateFrom()} to {$range->dateTo()}";

        return [
            'has_data' => $top->isNotEmpty(),
            'evidence' => $top->map(fn ($p) => [
                'label' => $p->name, 'value' => "{$p->units} units / {$p->revenue}", 'source' => 'woocommerce', 'date_range' => $dateRange,
            ])->all(),
            'raw' => ['top_products' => $top->map(fn ($p) => ['name' => $p->name, 'units' => (int) $p->units, 'revenue' => (float) $p->revenue])->all()],
        ];
    }

    private function customers(Site $site): array
    {
        $top = $this->commerce->topCustomers($site, 5);

        return [
            'has_data' => $top->isNotEmpty(),
            'evidence' => $top->map(fn ($c) => [
                'label' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ('Customer #' . $c->external_customer_id),
                'value' => "{$c->order_count} orders / {$c->total_spent}", 'source' => 'woocommerce', 'date_range' => 'all time',
            ])->all(),
            'raw' => ['top_customers' => $top->map(fn ($c) => ['orders' => (int) $c->order_count, 'spent' => (float) $c->total_spent])->all()],
        ];
    }

    private function content(Site $site, DateRange $range): array
    {
        $pages = TrafficDimensionSnapshot::where('site_id', $site->id)
            ->where('dimension_type', 'landing_page')
            ->where('period_start', '>=', $range->dateFrom())
            ->orderByDesc('sessions')
            ->limit(5)
            ->get(['title', 'url', 'sessions', 'conversions']);

        $events = EngagementDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->selectRaw('event_name, SUM(count) as total')
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $dateRange = "{$range->dateFrom()} to {$range->dateTo()}";

        return [
            'has_data' => $pages->isNotEmpty() || $events->isNotEmpty(),
            'evidence' => $pages->map(fn ($p) => [
                'label' => $p->title ?: $p->url, 'value' => "{$p->sessions} sessions", 'source' => 'ga4', 'date_range' => $dateRange,
            ])->all(),
            'raw' => [
                'top_landing_pages' => $pages->map(fn ($p) => ['title' => $p->title, 'url' => $p->url, 'sessions' => (int) $p->sessions])->all(),
                'top_events'        => $events->map(fn ($e) => ['event' => $e->event_name, 'count' => (int) $e->total])->all(),
            ],
        ];
    }

    private function alerts(Site $site): array
    {
        $rows = Alert::where('site_id', $site->id)
            ->where('is_resolved', false)
            ->latest()
            ->limit(10)
            ->get(['type', 'title', 'message', 'severity', 'created_at']);

        return [
            'has_data' => $rows->isNotEmpty(),
            'evidence' => $rows->map(fn ($a) => [
                'label' => $a->title, 'value' => $a->severity, 'source' => 'alerts', 'date_range' => optional($a->created_at)->toDateString(),
            ])->all(),
            'raw' => ['open_alerts' => $rows->map(fn ($a) => ['type' => $a->type, 'title' => $a->title, 'severity' => $a->severity])->all()],
        ];
    }

    /** Cross-source highlights for the Executive Overview / branded reports. */
    private function executive(Site $site, DateRange $range): array
    {
        $commerce = $this->commerceCtx($site, $range);
        $traffic  = $this->traffic($site, $range);
        $seo      = $this->seo($site, $range);
        $alerts   = $this->alerts($site);

        return [
            'has_data' => $commerce['has_data'] || $traffic['has_data'] || $seo['has_data'],
            'evidence' => array_merge($commerce['evidence'], $traffic['evidence'], $seo['evidence'], $alerts['evidence']),
            'raw' => [
                'commerce' => $commerce['raw'],
                'traffic'  => $traffic['raw'],
                'seo'      => $seo['raw'],
                'alerts'   => $alerts['raw'],
            ],
        ];
    }
}
