<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Performance\PageSpeedClient;
use App\Services\Performance\PageSpeedFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One PageSpeed run for one (site, strategy), off the request cycle —
 * a Lighthouse run routinely takes 20-60s, well past any HTTP timeout.
 *
 * Quota protection matters here in a way it doesn't for most jobs: the
 * PageSpeed API is rate limited per key (and harshly so without one), and a
 * nightly sweep across every site would burn the allowance in minutes. Two
 * guards apply — a global throttle across all sites, and a per-site daily
 * cap so one noisy site cannot starve the rest.
 */
class RunPageSpeedTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [120, 600];

    public function __construct(
        public int $siteId,
        public string $strategy = 'mobile',
        public ?string $url = null,
    ) {
    }

    /** One in-flight run per (site, strategy); a queued duplicate is dropped. */
    public function uniqueId(): string
    {
        return "pagespeed:{$this->siteId}:{$this->strategy}";
    }

    public function handle(PageSpeedClient $client): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);

        if (! $site) {
            return;
        }

        if (! $this->withinDailyCap()) {
            logger()->info('PageSpeed daily cap reached for site; skipping run.', ['site_id' => $this->siteId]);

            return;
        }

        // Global throttle: hold the job rather than dropping it, so a busy
        // window delays runs instead of losing them.
        $executed = RateLimiter::attempt(
            'pagespeed-global',
            (int) config('insightistic.pagespeed.max_per_minute'),
            fn () => true,
            60,
        );

        if (! $executed) {
            $this->release(30);

            return;
        }

        try {
            $client->run($site, $this->strategy, $this->url);
            $this->countRun();
        } catch (PageSpeedFailure $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 600);

                return;
            }

            // Terminal: the failure is already recorded on the run row, which
            // is what the dashboard reads. Don't fail the job over a bad URL.
            logger()->warning('PageSpeed run failed terminally.', [
                'site_id'  => $this->siteId,
                'strategy' => $this->strategy,
                'code'     => $e->errorCode,
            ]);
        }
    }

    private function dailyCapKey(): string
    {
        return "pagespeed-daily:{$this->siteId}:" . now()->toDateString();
    }

    private function withinDailyCap(): bool
    {
        $cap = (int) config('insightistic.pagespeed.max_per_site_per_day');

        return $cap <= 0 || (int) Cache::get($this->dailyCapKey(), 0) < $cap;
    }

    /** Counted only after a successful run, so failures don't burn quota. */
    private function countRun(): void
    {
        $key = $this->dailyCapKey();

        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->endOfDay());
    }
}
