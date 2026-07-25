<?php

namespace App\Jobs;

use App\Models\KnowledgeIngestionJob;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\KnowledgeIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued wrapper around KnowledgeIngestor. Carries only ids — models are
 * reloaded in handle() because no tenant is bound inside a queue worker
 * (same convention as RebuildSiteSnapshots).
 */
class IngestKnowledgeSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] exponential-ish backoff, seconds */
    public array $backoff = [10, 60, 300];

    public int $timeout = 600;

    public function __construct(
        public int $sourceId,
        public int $ingestionJobId,
    ) {}

    public function handle(KnowledgeIngestor $ingestor): void
    {
        $source = KnowledgeSource::withoutGlobalScope('organization')->find($this->sourceId);
        $job    = KnowledgeIngestionJob::withoutGlobalScope('organization')->find($this->ingestionJobId);

        if (! $source || ! $job) {
            return; // deleted while queued — nothing to do
        }

        if ($source->status === 'disabled') {
            $job->update(['status' => 'completed', 'finished_at' => now()]);

            return;
        }

        $ingestor->ingest($source, $job);
    }

    /** Final failure after all retries: leave an honest failure record. */
    public function failed(?\Throwable $e): void
    {
        KnowledgeSource::withoutGlobalScope('organization')
            ->where('id', $this->sourceId)
            ->update([
                'status'        => 'failed',
                'error_code'    => 'worker_failed',
                'error_message' => mb_substr((string) $e?->getMessage(), 0, 1000),
            ]);

        KnowledgeIngestionJob::withoutGlobalScope('organization')
            ->where('id', $this->ingestionJobId)
            ->update([
                'status'        => 'failed',
                'error_code'    => 'worker_failed',
                'error_message' => mb_substr((string) $e?->getMessage(), 0, 1000),
                'finished_at'   => now(),
            ]);
    }
}
