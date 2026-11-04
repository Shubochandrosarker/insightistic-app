<?php

namespace App\Services\Crawler;

use App\Models\Alert;
use App\Models\CrawlChange;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\SeoPageDaily;
use App\Models\Site;
use App\Services\Automation\AutomationDispatcher;
use Illuminate\Support\Carbon;

/**
 * Change monitoring (Phase 8, docs/roadmap-2026/12-SITE-CRAWLER.md
 * §Change monitoring): diffs every crawl_page of a finished run against
 * the same site's PREVIOUS completed run, keyed on url_hash, and logs one
 * CrawlChange row per changed (page, field).
 *
 * Called from CrawlRunExecutor::finalizeRun AFTER IssueEngine::finalize —
 * deliberately a separate service: issues are site-scoped lifecycle rows
 * (first/last seen), changes are per-run historical observations.
 *
 * Field semantics:
 * - title/meta_description/canonical/h1/content  compared only between two
 *                  INSPECTED (2xx) observations — a 4xx/5xx response stores
 *                  no content and its nulls are artifacts of the error, not
 *                  edits, so a 200→404 logs exactly ONE change
 * - indexability   flip between two inspected pages — noindex loss is
 *                  critical, regaining indexability is informational
 * - status_code    class change; 2xx→4xx/5xx is critical (spec: a heavily
 *                  indexed page going dark is the worst single event the
 *                  crawler can observe), any other change is low
 * - h1_count       any change, low
 * - content        content_hash differs; severity escalates to medium
 *                  when word_count moved by more than 50%
 *
 * GSC impact: every row freezes the page's 28-day Search Console
 * impressions AT DETECTION TIME (0 when the path is unknown), so the
 * traffic weight of a change never gets rewritten by later GSC data.
 *
 * Noise control: an identical transition (same page, field, old, new) is
 * skipped when the LATEST logged row for that page_hash+field already
 * records exactly that pair — flip-flopping A→B→A→B stays visible, while
 * re-running detection or re-finalizing the same transition logs nothing.
 *
 * CRITICAL changes raise ONE alert per run (type critical_site_change),
 * mirroring the Alert row shape AlertDetector writes. Alerts live here
 * rather than in AlertDetector::scan() on purpose: that rule set is
 * state-threshold checks with open-alert dedupe + auto-clear semantics —
 * a per-run EVENT (no "condition clears", one alert per run, changed URLs
 * in the message) does not fit it, and scan() has no run context anyway.
 */
class ChangeDetection
{
    public const ALERT_TYPE = 'critical_site_change';

    /** Max URLs quoted in the critical-change alert message. */
    public const ALERT_URL_LIMIT = 10;

    public function __construct(private readonly AutomationDispatcher $dispatcher) {}

    /**
     * Diff one finished run against the previous completed run of its site.
     *
     * @return array<int, CrawlChange> the rows written this call
     */
    public function detect(CrawlRun $run, Site $site): array
    {
        $previous = CrawlRun::query()
            ->where('site_id', $site->id)
            ->where('status', 'completed')
            ->where('id', '<', $run->id)
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return []; // first completed run — nothing to diff against
        }

        $current = CrawlPage::query()
            ->where('run_id', $run->id)
            ->get()
            ->keyBy('url_hash');

        if ($current->isEmpty()) {
            return [];
        }

        $prior = CrawlPage::query()
            ->where('run_id', $previous->id)
            ->where('site_id', $site->id)
            ->whereIn('url_hash', $current->keys())
            ->get()
            ->keyBy('url_hash');

        $candidates = [];

        foreach ($prior as $hash => $before) {
            $after = $current->get($hash);

            if ($after === null) {
                continue;
            }

            foreach ($this->diff($before, $after) as [$field, $severity, $oldValue, $newValue]) {
                $candidates[$hash.'|'.$field] = [
                    'site_id'     => $site->id,
                    'run_id'      => $run->id,
                    'page_hash'   => $hash,
                    'url'         => $after->url,
                    'field'       => $field,
                    'severity'    => $severity,
                    'old_value'   => self::clip($oldValue),
                    'new_value'   => self::clip($newValue),
                ];
            }
        }

        if ($candidates === []) {
            return [];
        }

        $candidates = $this->skipRepeats($site->id, $candidates);

        if ($candidates === []) {
            return [];
        }

        $impressions = $this->impressionsByPath($site, $candidates);

        $written = [];
        $now = Carbon::now();

        foreach ($candidates as $candidate) {
            $written[] = CrawlChange::query()->create($candidate + [
                'impressions' => $impressions[self::pathOf($candidate['url'])] ?? 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $this->raiseCriticalAlert($run, $site, $written);

        return $written;
    }

    /** Listing helper backing the changes endpoint (site-scoped, newest first). */
    public function historyFor(Site $site, ?int $runId = null)
    {
        return CrawlChange::query()
            ->where('site_id', $site->id)
            ->when($runId !== null, fn ($query) => $query->where('run_id', $runId))
            ->orderByDesc('id');
    }

    /**
     * Field-by-field comparison of one page across two runs.
     *
     * Content-bearing fields (title/meta/canonical/h1/indexability/content)
     * are only compared between two INSPECTED (2xx) observations: a 4xx/5xx
     * response stores no content, so its nulls are artifacts of the error
     * response rather than edits — the transition itself is captured by the
     * status_code row below. This keeps a 200→404 at exactly ONE change.
     *
     * @return array<int, array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    private function diff(CrawlPage $before, CrawlPage $after): array
    {
        $changes = [];

        $beforeStatus = (int) ($before->status_code ?? 0);
        $afterStatus = (int) ($after->status_code ?? 0);

        $bothInspected = $beforeStatus >= 200 && $beforeStatus < 300
            && $afterStatus >= 200 && $afterStatus < 300;

        if ($bothInspected) {
            $textFields = [
                // field, old, new, severity
                [CrawlChange::FIELD_TITLE, $before->title, $after->title, 'medium'],
                [CrawlChange::FIELD_META_DESCRIPTION, $before->meta_description, $after->meta_description, 'low'],
                [CrawlChange::FIELD_CANONICAL, $before->canonical_url, $after->canonical_url, 'medium'],
            ];

            foreach ($textFields as [$field, $old, $new, $severity]) {
                if (self::norm($old) !== self::norm($new)) {
                    $changes[] = [$field, $severity, self::norm($old), self::norm($new)];
                }
            }

            // An indexability flip between inspected pages: losing it is
            // critical (spec: 200→noindex), regaining it is informational.
            if ((bool) $before->indexable !== (bool) $after->indexable) {
                $lost = (bool) $before->indexable; // true → false is the loss
                $changes[] = [
                    CrawlChange::FIELD_INDEXABILITY,
                    $lost ? 'critical' : 'low',
                    $lost ? 'indexable' : 'noindex',
                    $lost ? 'noindex' : 'indexable',
                ];
            }

            if ((int) $before->h1_count !== (int) $after->h1_count) {
                $changes[] = [
                    CrawlChange::FIELD_H1_COUNT,
                    'low',
                    (string) $before->h1_count,
                    (string) $after->h1_count,
                ];
            }

            if ($before->content_hash !== null && $after->content_hash !== null && $before->content_hash !== $after->content_hash) {
                $beforeWords = (int) $before->word_count;
                $afterWords = (int) $after->word_count;
                $shift = abs($afterWords - $beforeWords) / max($beforeWords, 1);

                $changes[] = [
                    CrawlChange::FIELD_CONTENT,
                    $shift > 0.5 ? 'medium' : 'low',
                    (string) $before->content_hash,
                    (string) $after->content_hash,
                ];
            }
        }

        if ($beforeStatus !== $afterStatus) {
            $critical = $beforeStatus >= 200 && $beforeStatus < 300 && $afterStatus >= 400;
            $changes[] = [
                CrawlChange::FIELD_STATUS_CODE,
                $critical ? 'critical' : 'low',
                (string) $beforeStatus,
                (string) $afterStatus,
            ];
        }

        return $changes;
    }

    /**
     * Identical-transition dedupe: when the newest logged row for a
     * page_hash+field records the exact same old→new pair, this run saw
     * nothing new — skip.
     *
     * @param  array<string, array<string, mixed>> $candidates keyed page_hash|field
     * @return array<string, array<string, mixed>>
     */
    private function skipRepeats(int $siteId, array $candidates): array
    {
        $latest = [];

        CrawlChange::query()
            ->where('site_id', $siteId)
            ->whereIn('page_hash', collect($candidates)->pluck('page_hash')->unique()->values())
            ->orderByDesc('id')
            ->get(['page_hash', 'field', 'old_value', 'new_value'])
            ->each(function (CrawlChange $row) use (&$latest) {
                $key = $row->page_hash.'|'.$row->field;
                $latest[$key] ??= [self::norm($row->old_value), self::norm($row->new_value)];
            });

        return array_filter($candidates, function (array $candidate, string $key) use ($latest) {
            $seen = $latest[$key] ?? null;

            return $seen === null || $seen !== [self::norm($candidate['old_value']), self::norm($candidate['new_value'])];
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * GSC 28-day impressions per page path (sum of seo_page_daily since
     * today-28d); 0 for paths Search Console never reported.
     *
     * @param  array<string, array<string, mixed>> $candidates
     * @return array<string, int>
     */
    private function impressionsByPath(Site $site, array $candidates): array
    {
        $paths = collect($candidates)
            ->pluck('url')
            ->map(self::pathOf(...))
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            return [];
        }

        return SeoPageDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays(28)->startOfDay())
            ->whereIn('page_path', $paths)
            ->groupBy('page_path')
            ->selectRaw('page_path, sum(impressions) as total')
            ->pluck('total', 'page_path')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * One alert per run for critical changes, URLs joined into the message
     * (the alerts table has no JSON column). Per-run dedupe keys off the
     * "run #N" marker in the message so a retried finalize never duplicates.
     *
     * @param array<int, CrawlChange> $written
     */
    private function raiseCriticalAlert(CrawlRun $run, Site $site, array $written): void
    {
        $critical = array_values(array_filter(
            $written,
            fn (CrawlChange $change) => $change->severity === 'critical',
        ));

        if ($critical === []) {
            return;
        }

        $marker = 'run #'.$run->id;

        $exists = Alert::query()
            ->where('site_id', $site->id)
            ->where('type', self::ALERT_TYPE)
            ->where('message', 'like', '%'.$marker.'%')
            ->exists();

        if ($exists) {
            return;
        }

        $urls = array_values(array_unique(array_map(
            fn (CrawlChange $change) => self::pathOf($change->url),
            $critical,
        )));

        $quoted = array_slice($urls, 0, self::ALERT_URL_LIMIT);
        $overflow = count($urls) - count($quoted);

        $message = sprintf(
            'Detected %d critical change(s) in %s affecting: %s%s',
            count($critical),
            $marker,
            implode(', ', $quoted),
            $overflow > 0 ? sprintf(' and %d more.', $overflow) : '.',
        );

        $alert = Alert::query()->create([
            'site_id'     => $site->id,
            'type'        => self::ALERT_TYPE,
            'title'       => 'Critical site changes detected',
            'message'     => $message,
            'severity'    => 'critical',
            'is_resolved' => false,
        ]);

        // Same automation hook AlertDetector.raise() fires for its rules —
        // Automation Studio flows must not distinguish alert origins.
        $this->dispatcher->dispatch('alert_created', $site, [
            'alert_type' => self::ALERT_TYPE,
            'alert_id'   => $alert->id,
        ]);
    }

    /** Empty strings and nulls are the same "absent" everywhere. */
    private static function norm(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /** Path-only form of a crawl URL — the key seo_page_daily reports by. */
    private static function pathOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }

    /** crawl_changes.old_value/new_value are varchar(500); long meta descriptions overflowed. */
    public const MAX_VALUE_LENGTH = 500;

    private static function clip(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr((string) $value, 0, self::MAX_VALUE_LENGTH);
    }
}
