<?php

namespace App\Console\Commands;

use App\Services\Observability\Telemetry;
use Illuminate\Console\Command;

/**
 * Drops telemetry buckets past the retention window (spec Phase 2.3).
 *
 * Observability data that grows without bound eventually becomes the
 * performance problem it was installed to detect. The default window is short
 * on purpose — these buckets answer "what is happening now" and "what changed
 * this week"; anything older is a question for traces and errors, which live
 * in systems built to age them out.
 */
class PruneTelemetry extends Command
{
    protected $signature = 'insightistic:telemetry-prune {--days= : Override the configured retention window}';

    protected $description = 'Delete request and AI telemetry buckets past the retention window';

    public function handle(Telemetry $telemetry): int
    {
        /*
         * `?:` would be wrong here: `--days=0` is the string "0", which is
         * falsy, so it would silently fall through to the configured default
         * rather than being rejected. Someone typing 0 means something by it
         * — and whatever they mean, quietly doing 30 instead is not it.
         */
        $option = $this->option('days');
        $days = $option === null ? (int) config('otel.retention_days', 30) : (int) $option;

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $deleted = $telemetry->prune($days);

        $this->info("Telemetry: {$deleted} buckets older than {$days} days removed.");

        return self::SUCCESS;
    }
}
