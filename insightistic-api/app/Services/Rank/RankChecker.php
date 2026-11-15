<?php

namespace App\Services\Rank;

use App\Domain\Billing\CreditWallet;

use App\Models\Alert;
use App\Models\RankKeyword;
use App\Models\RankObservation;
use App\Models\Site;
use App\Services\Automation\AutomationDispatcher;
use App\Services\Integrations\IntegrationFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rank check orchestrator (Phase 10, doc 13): loads a site's active tracked
 * keywords, hands a batch to the RankProvider, persists one append-only
 * rank_observations row per keyword and raises position-change alerts.
 *
 * Guarantees (doc 13 acceptance criteria):
 * - a provider failure throws BEFORE anything is written — observations and
 *   last_checked_at only advance on a fully successful provider call, so the
 *   last known position is never overwritten by an outage;
 * - repeat-state suppression: a keyword already checked "today" (site
 *   timezone) is skipped, so duplicated/overlapping scheduled jobs cannot
 *   duplicate observations or double-bill the provider;
 * - every persisted observation carries its share of the batch's billable
 *   cost_units.
 *
 * Alerts (types prefixed rank_) mirror the ChangeDetection precedent, not
 * AlertDetector::scan(): these are per-keyword EVENT rules (one alert per
 * movement) with no open-alert lifecycle, so each raises its own Alert row
 * and fires the same AutomationDispatcher 'alert_created' hook. Noise
 * control: minimum absolute movement MIN_MOVEMENT for drift outside the top
 * 10, and at most ONE alert per (keyword, type, day) — deduped off the
 * "keyword #N" marker in the message, same trick as ChangeDetection's
 * "run #N" marker.
 */
class RankChecker
{
    public const ALERT_ENTERED_TOP10 = 'rank_entered_top10';
    public const ALERT_DROPPED = 'rank_dropped';
    public const ALERT_URL_CHANGED = 'rank_position_changed_url';
    public const ALERT_NOT_FOUND = 'rank_not_found';

    /** Minimum absolute movement that counts as a drift alert (noise control). */
    public const MIN_MOVEMENT = 3;

    /** The top-10 boundary alerts key on. */
    public const TOP_N = 10;

    private readonly RankProvider $provider;

    private readonly AutomationDispatcher $dispatcher;

    private readonly CreditWallet $wallet;

    public function __construct(?RankProvider $provider = null, ?AutomationDispatcher $dispatcher = null, ?CreditWallet $wallet = null)
    {
        // RankProvider is bound in AppServiceProvider through
        // RankProviderFactory (RANK_PROVIDER, default dataforseo). Tests pass
        // a fake directly or rebind the interface.
        $this->provider = $provider ?? app(RankProvider::class);
        $this->dispatcher = $dispatcher ?? app(AutomationDispatcher::class);
        $this->wallet = $wallet ?? app(CreditWallet::class);
    }

    /**
     * Check one site's due active keywords (or a subset of them) and persist
     * the observations. Returns how many keywords were actually checked.
     *
     * @param  array<int, int>|null  $keywordIds  null = all active keywords of the site
     *
     * @throws IntegrationFailure 'rank_provider_not_configured' when no credentials,
     *                            or whatever the provider throws on a failed call
     */
    public function checkSite(Site $site, ?array $keywordIds = null): int
    {
        $timezone = $site->timezone ?: config('app.timezone', 'UTC');
        // "Today" for dedupe and alert windows, expressed in UTC because
        // that is how both columns store timestamps.
        $todayStartUtc = Carbon::today($timezone)->timezone('UTC');

        $keywords = RankKeyword::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->when($keywordIds !== null, fn ($query) => $query->whereIn('id', $keywordIds))
            // Same-day repeat suppression (doc 13: duplicate scheduled jobs
            // do not duplicate observations).
            ->where(function ($query) use ($todayStartUtc) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $todayStartUtc);
            })
            // Deterministic provider task order regardless of DB plan.
            ->orderBy('id')
            ->get();

        if ($keywords->isEmpty()) {
            return 0;
        }

        if (! $this->provider->available()) {
            throw new IntegrationFailure(
                'rank_provider_not_configured',
                'Rank tracking provider is not configured (missing DATAFORSEO credentials).',
            );
        }

        // Preflight before making a provider request. A failed balance check
        // must not still spend DataForSEO budget. Local searches carry the
        // slightly higher tariff because they use a market-specific SERP.
        $plannedCredits = $keywords->sum(fn (RankKeyword $keyword) => $this->creditCost($keyword));
        $availableCredits = $this->wallet->available($site->organization);
        if ($plannedCredits > $availableCredits) {
            throw new \App\Domain\Billing\Exceptions\InsufficientCreditsException($plannedCredits, $availableCredits);
        }

        $results = $this->provider->checkBatch($keywords->all());

        $now = Carbon::now();
        $successful = $keywords->filter(fn (RankKeyword $keyword) => ($results[$keyword->id] ?? null) !== null)->values();
        if ($successful->isEmpty()) {
            return 0; // Provider lost every keyword — all remain due.
        }

        $actualCredits = $successful->sum(fn (RankKeyword $keyword) => $this->creditCost($keyword));
        $reference = 'rank-check:'.$site->id.':'.Carbon::today($timezone)->format('Y-m-d').':'
            .sha1($successful->pluck('id')->implode(','));

        // Keep the wallet deduction and append-only observations atomic. If
        // persistence fails, the customer is not charged for that attempt.
        DB::transaction(function () use ($site, $successful, $results, $now, $actualCredits, $reference, $todayStartUtc): void {
            if ($actualCredits > 0) {
                $this->wallet->consume($site->organization, $actualCredits, 'rank_keyword_check', $reference);
            }

            foreach ($successful as $keyword) {
                $result = $results[$keyword->id];
                $previous = RankObservation::query()
                    ->where('keyword_id', $keyword->id)
                    ->orderByDesc('checked_at')
                    ->orderByDesc('id')
                    ->first();

                RankObservation::query()->create([
                    'keyword_id'          => $keyword->id,
                    'site_id'             => $site->id,
                    'checked_at'          => $now,
                    'position'            => $result['position'],
                    'ranking_url'         => $result['ranking_url'],
                    'serp_features_json'  => $result['serp_features'],
                    'provider'            => $this->provider->name(),
                    'provider_request_id' => $result['provider_request_id'],
                    'cost_units'          => $result['cost_units'],
                    'created_at'          => $now,
                ]);

                $keyword->forceFill(['last_checked_at' => $now])->save();
                $this->evaluateAlerts($site, $keyword, $previous, $result, $todayStartUtc);
            }
        });

        return $successful->count();
    }

    private function creditCost(RankKeyword $keyword): int
    {
        $costs = config('commercial.usage_credit_costs', []);

        return (int) ($costs[$keyword->location ? 'local_rank_keyword_check' : 'rank_keyword_check'] ?? 10);
    }

    /**
     * Compare this observation against the keyword's previous one and raise
     * the matching rank_* alerts.
     *
     * @param array{position: ?int, ranking_url: ?string, serp_features: ?array} $result
     */
    private function evaluateAlerts(
        Site $site,
        RankKeyword $keyword,
        ?RankObservation $previous,
        array $result,
        Carbon $todayStartUtc,
    ): void {
        $prev = $previous?->position;
        $new = $result['position'];
        $newUrl = $result['ranking_url'] ?? null;
        $label = '"'.$keyword->keyword.'"';

        $candidates = [];

        // Entered the top 10: previously absent/outside, now inside.
        if ($new !== null && $new >= 1 && $new <= self::TOP_N
            && ($prev === null || $prev > self::TOP_N)) {
            $candidates[] = [
                self::ALERT_ENTERED_TOP10, 'low', 'Keyword entered the top 10',
                sprintf('%s is now at position %d in the top 10 (was %s).', $label, $new, $prev !== null ? (string) $prev : 'not ranking'),
            ];
        }

        if ($prev !== null) {
            // Out of the top 10 while still ranking (a drop to null is owned
            // by rank_not_found below — one event, one alert).
            $fellOutOfTop10 = $prev <= self::TOP_N && $new !== null && $new > self::TOP_N;
            // Slid at least MIN_MOVEMENT places deeper while both stay
            // outside the top 10 — smaller wobbles are noise (doc 13
            // §Noise control: require minimum absolute movement).
            $driftedDown = $new !== null && $prev > self::TOP_N && $new > self::TOP_N
                && ($new - $prev) >= self::MIN_MOVEMENT;

            if ($fellOutOfTop10 || $driftedDown) {
                $candidates[] = [
                    self::ALERT_DROPPED, 'medium', 'Keyword dropped in rankings',
                    sprintf('%s fell from position %d to %s.', $label, $prev, $new !== null ? (string) $new : 'not found'),
                ];
            }
        }

        // Same position (±1) but a different page took the spot.
        if ($prev !== null && $new !== null && abs($prev - $new) <= 1
            && self::urlKey($previous->ranking_url) !== self::urlKey($newUrl)) {
            $candidates[] = [
                self::ALERT_URL_CHANGED, 'low', 'Ranking URL changed',
                sprintf('%s holds position %d but the ranking URL changed (%s → %s).', $label, $new, $previous->ranking_url ?: 'none', $newUrl ?: 'none'),
            ];
        }

        // Ranked before, gone now.
        if ($prev !== null && $new === null) {
            $candidates[] = [
                self::ALERT_NOT_FOUND, 'high', 'Keyword no longer found',
                sprintf('%s was at position %d and is no longer found in the top 100.', $label, $prev),
            ];
        }

        foreach ($candidates as [$type, $severity, $title, $message]) {
            $this->raiseAlert($site, $keyword, $type, $title, $message, $severity, $todayStartUtc);
        }
    }

    /**
     * One Alert per (keyword, type, day), deduped off the "keyword #N"
     * marker embedded in the message — a re-run of the same day's movement
     * stays silent (mirrors ChangeDetection's "run #N" dedupe).
     */
    private function raiseAlert(
        Site $site,
        RankKeyword $keyword,
        string $type,
        string $title,
        string $message,
        string $severity,
        Carbon $todayStartUtc,
    ): void {
        $marker = 'keyword #'.$keyword->id;

        $exists = Alert::query()
            ->where('site_id', $site->id)
            ->where('type', $type)
            ->where('message', 'like', '%'.$marker.'%')
            ->where('created_at', '>=', $todayStartUtc)
            ->exists();

        if ($exists) {
            return;
        }

        $alert = Alert::query()->create([
            'site_id'     => $site->id,
            'type'        => $type,
            'title'       => $title,
            'message'     => sprintf('%s (%s)', $message, $marker),
            'severity'    => $severity,
            'is_resolved' => false,
        ]);

        // Same automation hook AlertDetector.raise() and ChangeDetection
        // fire — Automation Studio flows must not distinguish alert origins.
        $this->dispatcher->dispatch('alert_created', $site, [
            'alert_type' => $type,
            'alert_id'   => $alert->id,
        ]);
    }

    /** Host+path identity of a ranking URL (query/fragment noise ignored). */
    private static function urlKey(?string $url): ?string
    {
        $value = trim((string) $url);

        if ($value === '') {
            return null;
        }

        $host = mb_strtolower((string) (parse_url($value, PHP_URL_HOST) ?: ''));
        $path = (string) (parse_url($value, PHP_URL_PATH) ?: '/');

        return $host !== '' ? $host.$path : $value;
    }
}
