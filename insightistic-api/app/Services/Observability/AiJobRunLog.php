<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-site accounting for the scheduled AI jobs.
 *
 * Before this existed, insights-generate and ai-visibility could skip every
 * site (or crash) and the only trace was a "DONE" line whose output went to
 * /dev/null. Now every skip carries a site id and a reason, the run ends
 * with one summary line, and the summary is cached so the watchdog and the
 * admin System Health page can show what the last run actually did.
 */
final class AiJobRunLog
{
    /** @var array<string, int> */
    private array $reasons = [];

    private int $eligible = 0;

    private int $written = 0;

    private int $skipped = 0;

    public function __construct(private readonly string $job) {}

    public static function cacheKey(string $job): string
    {
        return 'ai_jobs:last_run:' . $job;
    }

    /** A site the job would normally do work for (counted once per site). */
    public function eligible(): void
    {
        $this->eligible++;
    }

    public function skip(int $siteId, string $reason, array $context = []): void
    {
        $this->skipped++;
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;

        Log::info("ai_job.{$this->job}.skip", ['site_id' => $siteId, 'reason' => $reason] + $context);
    }

    /** One unit of output (an insight row written, a visibility job queued). */
    public function wrote(int $siteId, array $context = []): void
    {
        $this->written++;
    }

    public function written(): int
    {
        return $this->written;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /** @return array<string, mixed> */
    public function finish(bool $dryRun = false): array
    {
        $summary = [
            'job'      => $this->job,
            'at'       => now()->toIso8601String(),
            'dry_run'  => $dryRun,
            'eligible' => $this->eligible,
            'written'  => $this->written,
            'skipped'  => $this->skipped,
            'reasons'  => $this->reasons,
        ];

        Log::info("ai_job.{$this->job}.summary", $summary);

        if (! $dryRun && $this->eligible > 0 && $this->written === 0) {
            Log::warning("ai_job.{$this->job}.zero_output", $summary);
        }

        if (! $dryRun) {
            try {
                Cache::forever(self::cacheKey($this->job), $summary);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $summary;
    }
}
