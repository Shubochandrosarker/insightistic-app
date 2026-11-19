<?php

namespace App\Services\Performance;

use App\Models\PerformanceIssue;
use App\Models\PerformanceRun;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Runs PageSpeed Insights from the SaaS backend (spec Phase 3.3).
 *
 * The tables and the read endpoints already existed, but nothing here ever
 * called Google — runs only arrived if the WordPress plugin pushed them,
 * which meant a site with the plugin disabled had no performance data at
 * all and nothing could be scheduled centrally.
 *
 * Field data (CrUX) is preferred over lab data for Core Web Vitals, because
 * a lab run on Google's hardware is not what the site's actual visitors
 * experience. When CrUX has no sample for the URL — which is normal for low
 * traffic — the lab figures are stored and `field_data_available` records
 * which one the numbers came from, so the dashboard can say so rather than
 * implying real-user measurement.
 */
class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public const STRATEGIES = ['mobile', 'desktop'];

    /** Audits worth surfacing; everything else is noise at this level. */
    private const OPPORTUNITY_CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];

    /**
     * Metric audits, excluded from the issues list.
     *
     * These score below the pass threshold on any slow page, but they are
     * measurements rather than things to fix — and each is already stored as
     * a first-class column on the run. Listing them as "issues" would
     * restate the Core Web Vitals as if they were actions, burying the
     * opportunities that actually tell someone what to change.
     */
    private const METRIC_AUDITS = [
        'largest-contentful-paint',
        'cumulative-layout-shift',
        'first-contentful-paint',
        'total-blocking-time',
        'speed-index',
        'server-response-time',
        'interactive',
        'max-potential-fid',
        'first-meaningful-paint',
    ];

    /**
     * Execute one run and persist it.
     *
     * @throws PageSpeedFailure
     */
    public function run(Site $site, string $strategy, ?string $url = null): PerformanceRun
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new PageSpeedFailure('invalid_strategy', "Unknown strategy: {$strategy}.");
        }

        $target = $url ?: $this->siteUrl($site);

        $run = PerformanceRun::create([
            'site_id'  => $site->id,
            'url'      => $target,
            'strategy' => $strategy,
            'run_at'   => now(),
            'status'   => 'running',
        ]);

        try {
            $payload = $this->request($target, $strategy);
            $this->store($run, $payload);
        } catch (PageSpeedFailure $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            throw $e;
        }

        return $run->fresh();
    }

    private function request(string $url, string $strategy): array
    {
        $key = config('insightistic.pagespeed.api_key');

        $query = [
            'url'      => $url,
            'strategy' => $strategy,
            // Ask for every category so one run populates all four scores.
        ];

        if ($key) {
            $query['key'] = $key;
        }

        $response = Http::timeout(config('insightistic.pagespeed.timeout'))
            ->get(self::ENDPOINT.'?'.http_build_query($query).'&'.implode('&', array_map(static fn (string $c): string => 'category='.urlencode($c), self::OPPORTUNITY_CATEGORIES)));

        if (! $response->successful()) {
            $message = (string) ($response->json()['error']['message'] ?? 'PageSpeed Insights request failed.');

            // 429 is the documented signal that the (keyless or keyed) quota
            // is spent; it is worth retrying later, unlike a bad URL.
            throw new PageSpeedFailure(
                $response->status() === 429 ? 'quota_exceeded' : 'pagespeed_failed',
                $message,
                retryable: $response->status() === 429 || $response->status() >= 500,
            );
        }

        return $response->json() ?? [];
    }

    private function store(PerformanceRun $run, array $payload): void
    {
        $lighthouse = $payload['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];
        $audits = $lighthouse['audits'] ?? [];
        $field = $payload['loadingExperience']['metrics'] ?? [];

        $hasField = $field !== [];

        DB::transaction(function () use ($run, $lighthouse, $categories, $audits, $field, $hasField) {
            $run->update([
                'lighthouse_version'   => $lighthouse['lighthouseVersion'] ?? null,
                'fetch_time'           => isset($lighthouse['fetchTime']) ? \Illuminate\Support\Carbon::parse($lighthouse['fetchTime']) : null,

                'performance_score'    => $this->score($categories, 'performance'),
                'accessibility_score'  => $this->score($categories, 'accessibility'),
                'best_practices_score' => $this->score($categories, 'best-practices'),
                'seo_score'            => $this->score($categories, 'seo'),

                // Core Web Vitals: real-user data when Google has it.
                'lcp_ms'      => $hasField
                    ? $this->fieldMs($field, 'LARGEST_CONTENTFUL_PAINT_MS')
                    : $this->labMs($audits, 'largest-contentful-paint'),
                'lcp_status'  => $hasField ? $this->fieldStatus($field, 'LARGEST_CONTENTFUL_PAINT_MS') : null,
                'inp_ms'      => $hasField ? $this->fieldMs($field, 'INTERACTION_TO_NEXT_PAINT') : null,
                'inp_status'  => $hasField ? $this->fieldStatus($field, 'INTERACTION_TO_NEXT_PAINT') : null,
                'cls'         => $hasField
                    // CrUX reports CLS scaled by 100; the column stores the real value.
                    ? $this->fieldValue($field, 'CUMULATIVE_LAYOUT_SHIFT_SCORE') / 100
                    : $this->labValue($audits, 'cumulative-layout-shift'),
                'cls_status'  => $hasField ? $this->fieldStatus($field, 'CUMULATIVE_LAYOUT_SHIFT_SCORE') : null,

                'fcp_ms'         => $this->labMs($audits, 'first-contentful-paint'),
                'tbt_ms'         => $this->labMs($audits, 'total-blocking-time'),
                'speed_index_ms' => $this->labMs($audits, 'speed-index'),
                'ttfb_ms'        => $this->labMs($audits, 'server-response-time'),

                'field_data_available' => $hasField,
                'lab_data_available'   => $audits !== [],

                'status'        => 'complete',
                'error_message' => null,
            ]);

            $this->storeOpportunities($run, $audits, $categories);
        });
    }

    /** Persist the failing/near-miss audits worth acting on. */
    private function storeOpportunities(PerformanceRun $run, array $audits, array $categories): void
    {
        PerformanceIssue::where('performance_run_id', $run->id)->delete();

        // Map audit id => category so an issue can be filtered by section.
        $categoryOf = [];
        foreach ($categories as $key => $category) {
            foreach ($category['auditRefs'] ?? [] as $ref) {
                if (isset($ref['id'])) {
                    $categoryOf[$ref['id']] = $key;
                }
            }
        }

        foreach ($audits as $key => $audit) {
            $score = $audit['score'] ?? null;

            // null score = informational/not-applicable audit. 1 = passing.
            if ($score === null || $score >= 0.9) {
                continue;
            }

            if (in_array($key, self::METRIC_AUDITS, true)) {
                continue;
            }

            $category = $categoryOf[$key] ?? 'performance';
            if (! in_array($category, self::OPPORTUNITY_CATEGORIES, true)) {
                continue;
            }

            PerformanceIssue::create([
                'performance_run_id' => $run->id,
                'audit_key'          => $key,
                'category'           => $category,
                'title'              => mb_substr((string) ($audit['title'] ?? $key), 0, 255),
                'severity'           => $score < 0.5 ? 'high' : ($score < 0.75 ? 'medium' : 'low'),
                'savings_ms'         => isset($audit['details']['overallSavingsMs'])
                    ? (int) round((float) $audit['details']['overallSavingsMs'])
                    : null,
                'savings_bytes'      => isset($audit['details']['overallSavingsBytes'])
                    ? (int) $audit['details']['overallSavingsBytes']
                    : null,
                'display_value'      => isset($audit['displayValue']) ? mb_substr((string) $audit['displayValue'], 0, 255) : null,
                'description'        => $audit['description'] ?? null,
                'created_at'         => now(),
            ]);
        }
    }

    private function score(array $categories, string $key): ?int
    {
        $score = $categories[$key]['score'] ?? null;

        // Lighthouse reports 0..1; the columns store whole percentages.
        return $score === null ? null : (int) round((float) $score * 100);
    }

    private function fieldValue(array $field, string $key): float
    {
        return (float) ($field[$key]['percentile'] ?? 0);
    }

    private function fieldMs(array $field, string $key): ?int
    {
        return isset($field[$key]['percentile']) ? (int) $field[$key]['percentile'] : null;
    }

    /** CrUX category → the good/needs_improvement/poor vocabulary we store. */
    private function fieldStatus(array $field, string $key): ?string
    {
        return match ($field[$key]['category'] ?? null) {
            'FAST', 'GOOD' => 'good',
            'AVERAGE', 'NEEDS_IMPROVEMENT' => 'needs_improvement',
            'SLOW', 'POOR' => 'poor',
            default => null,
        };
    }

    private function labMs(array $audits, string $key): ?int
    {
        $value = $audits[$key]['numericValue'] ?? null;

        return $value === null ? null : (int) round((float) $value);
    }

    private function labValue(array $audits, string $key): ?float
    {
        $value = $audits[$key]['numericValue'] ?? null;

        return $value === null ? null : round((float) $value, 3);
    }

    private function siteUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);

        return str_starts_with($domain, 'http') ? $domain : "https://{$domain}";
    }
}
