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

    public function handle(GscClient $gsc): void
    {
        $state = SeoBackfillState::firstOrCreate(
            ['site_id' => $this->site->id, 'dimension' => $this->dimension],
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

                $gsc->fetchDailyWindow($this->site, $credential, $cursor->toDateString(), $chunkEnd->toDateString(), $this->dimension);

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
}
