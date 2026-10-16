<?php

namespace App\Jobs;

use App\Models\DataSourceCredential;
use App\Models\SeoBackfillState;
use App\Models\Site;
use App\Services\Integrations\GscClient;
use App\Services\Integrations\IntegrationFailure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Historical Search Console backfill into the daily-grain SEO tables, for
 * one site and one dimension ('queries'|'pages'|'query_page').
 *
 * Runs in 30-day chunks and advances `seo_backfill_state.covered_to` after
 * every successful chunk (doc 08: idempotent writes make replay safe, but
 * quota isn't free) — so a crash or provider error resumes at covered_to+1
 * instead of starting over. Failed chunks mark the state row 'failed' with
 * the error, then rethrow: the queue layer retries (tries/backoff below),
 * and the next attempt picks up where the coverage cursor stopped.
 */
class BackfillGscHistory implements ShouldQueue
{
    use Queueable;

    /** Backfills are patient: fewer, well-spaced retries beat hot-looping. */
    public int $tries = 3;

    public array $backoff = [300, 900];

    /** A 'running' state untouched this long is crash debris and may be reclaimed. */
    private const RUNNING_STALE_MINUTES = 60;

    public function __construct(
        public Site $site,
        public string $dimension,
        public string $from,
        public string $to,
        public bool $force = false,
    ) {
    }

    /**
     * Early dispatches used the singular GSC dimension names ('query'/'page')
     * and blew up in the client's match with "Unknown SEO daily dimension".
     * Normalizing here (not just in the constructor) also fixes jobs that were
     * already serialized into failed_jobs — a `queue:retry` replays them with
     * the old property values, and unserialize never re-runs the constructor.
     */
    private function dimension(): string
    {
        return match ($this->dimension) {
            'query' => 'queries',
            'page'  => 'pages',
            default => $this->dimension,
        };
    }

    public function handle(GscClient $gsc): void
    {
        $dimension = $this->dimension();

        $state = SeoBackfillState::firstOrCreate(
            ['site_id' => $this->site->id, 'dimension' => $dimension],
            ['status' => 'pending'],
        );

        // Never overlap a live run for the same grain.
        if ($state->status === 'running' && $state->updated_at?->gt(now()->subMinutes(self::RUNNING_STALE_MINUTES))) {
            return;
        }

        // Done work stays done: a re-dispatch of a covered range is a no-op
        // unless the caller explicitly forces a refresh.
        if ($state->status === 'done' && ! $this->force) {
            return;
        }

        $credential = DataSourceCredential::withoutGlobalScope('organization')
            ->where('site_id', $this->site->id)
            ->where('source', 'gsc')
            ->first();

        if (! $credential) {
            $state->forceFill([
                'status'     => 'failed',
                'last_error' => 'No Search Console credential is connected for this site.',
            ])->save();

            throw new IntegrationFailure('not_connected', 'No Search Console credential is connected for this site.');
        }

        $from = CarbonImmutable::parse($this->from)->startOfDay();
        $to = CarbonImmutable::parse($this->to)->startOfDay();

        // Search Console exposes a rolling 16-month window. A serialized
        // backfill can outlive that provider window before an operator
        // retries it, so clamp stale jobs before the first API request.
        $providerFloor = CarbonImmutable::today(
            $this->site->timezone ?: config('app.timezone', 'UTC')
        )->subMonths(16);
        if ($from->lt($providerFloor)) {
            $from = $providerFloor;
        }

        if ($this->force) {
            // A forced refresh re-walks the whole requested range.
            $state->forceFill(['covered_from' => null, 'covered_to' => null])->save();
        } elseif ($state->covered_to !== null) {
            // Resume: skip dates that already landed in an earlier attempt.
            $resume = $state->covered_to->addDay();
            if ($resume->gt($from)) {
                $from = $resume;
            }
        }

        if ($from->gt($to)) {
            // Everything in the requested range was already written.
            $state->forceFill(['status' => 'done', 'last_error' => null])->save();

            return;
        }

        $state->forceFill([
            'status'       => 'running',
            'attempts'     => $state->attempts + 1,
            // Keep the original range start on resume so covered_from always
            // describes the full span this cursor has walked.
            'covered_from' => $state->covered_from ?? $from->toDateString(),
            'last_error'   => null,
        ])->save();

        try {
            $cursor = $from;
            do {
                $chunkEnd = $cursor->addDays(29)->min($to);

                $this->fetchChunk($gsc, $credential, $cursor, $chunkEnd, $dimension);

                // Advance the coverage cursor only after the chunk fully
                // landed, so a crash mid-window replays that whole window.
                $state->forceFill(['covered_to' => $chunkEnd->toDateString()])->save();
                $cursor = $chunkEnd->addDay();
            } while ($cursor->lte($to));

            $state->forceFill(['status' => 'done'])->save();
        } catch (\Throwable $e) {
            // covered_from/covered_to keep their values: the next attempt
            // (or a manual re-dispatch) resumes from covered_to + 1 day.
            $state->forceFill([
                'status'     => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            throw $e;
        }
    }

    /**
     * Search Console can reject a valid local window when its reporting data
     * currently ends before the requested end date. Split that window into
     * days so available dates still land and only provider-unavailable days
     * are skipped instead of retrying the entire backfill forever.
     */
    private function fetchChunk(
        GscClient $gsc,
        DataSourceCredential $credential,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $dimension,
    ): void {
        try {
            $gsc->fetchDailyWindow(
                $this->site,
                $credential,
                $from->toDateString(),
                $to->toDateString(),
                $dimension,
            );

            return;
        } catch (IntegrationFailure $e) {
            if (! $this->isProviderDateRangeFailure($e)) {
                throw $e;
            }
        }

        $skipped = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            try {
                $gsc->fetchDailyWindow(
                    $this->site,
                    $credential,
                    $day->toDateString(),
                    $day->toDateString(),
                    $dimension,
                );
            } catch (IntegrationFailure $e) {
                if (! $this->isProviderDateRangeFailure($e)) {
                    throw $e;
                }

                $skipped[] = $day->toDateString();
            }
        }

        if ($skipped !== []) {
            logger()->warning('Search Console omitted provider-unavailable backfill dates.', [
                'site_id'   => $this->site->id,
                'dimension' => $dimension,
                'dates'     => $skipped,
            ]);
        }
    }

    private function isProviderDateRangeFailure(IntegrationFailure $e): bool
    {
        return str_contains(
            strtolower($e->getMessage()),
            'invalid date range defined by startdate and enddate',
        );
    }
}
