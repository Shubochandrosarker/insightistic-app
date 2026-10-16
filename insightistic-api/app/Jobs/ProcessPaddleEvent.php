<?php

namespace App\Jobs;

use App\Domain\Billing\Exceptions\UnresolvableOrganizationException;
use App\Domain\Billing\PaddleEventProcessor;
use App\Models\BillingEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Project one accepted Paddle webhook event onto local state. The webhook
 * endpoint only records the event id (its 200 means "received", never
 * "processed") — all real work happens here, off the request path.
 *
 * Retries with backoff like SyncDataSource: transient projector/database
 * failures recover on a later attempt, and every projector converges, so a
 * replayed attempt is harmless. config/queue.php defines no dedicated
 * billing queue, so this rides the default queue like every other job.
 */
class ProcessPaddleEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Exponential backoff, in seconds, between the three attempts. */
    public array $backoff = [60, 300];

    public function __construct(
        public BillingEvent $event,
        public array $payload,
    ) {}

    public function handle(PaddleEventProcessor $processor): void
    {
        try {
            $processor->process($this->event->event_type, $this->payload);

            $this->event->update([
                'processing_status' => 'processed',
                'processed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Missing tenant metadata is terminal: retrying cannot infer the
            // correct organization and must never duplicate billing effects.
            $terminal = $e instanceof UnresolvableOrganizationException;

            $this->event->update([
                'attempts'          => $this->event->attempts + 1,
                'last_error'        => mb_substr($e->getMessage(), 0, 4000),
                'processing_status' => $terminal || $this->attempts() >= $this->tries ? 'failed' : 'pending',
            ]);

            if ($terminal) {
                logger()->warning('Paddle event marked terminal after organization resolution failed.', [
                    'event_id'          => $this->event->id,
                    'provider_event_id' => $this->event->provider_event_id,
                ]);

                return;
            }

            throw $e; // Rethrow so Laravel retries/backoffs normally.
        }
    }
}
