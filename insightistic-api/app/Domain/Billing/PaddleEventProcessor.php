<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\Log;

/**
 * Routes one decoded Paddle webhook event to the right projector. Deliberately
 * thin: all state lives in the projectors, and every projector converges to
 * the same state for the same input, so running an event twice (webhook
 * redelivery, admin replay, reconciler repair) changes nothing the second time.
 *
 * A payment failure only records financial state — it never cancels a
 * subscription. Paddle's own subscription.* events are the only thing that
 * moves subscription status.
 */
final class PaddleEventProcessor
{
    private const SUBSCRIPTION_EVENTS = [
        'subscription.created',
        'subscription.updated',
        'subscription.canceled',
        'subscription.paused',
        'subscription.resumed',
    ];

    public function __construct(
        private PaddleSubscriptionProjector $subscriptions,
        private PaddleTransactionProjector $transactions,
    ) {}

    /**
     * @param  string  $eventType  e.g. 'subscription.updated', 'transaction.completed'
     * @param  array  $data  the event's `data` node (the provider entity)
     */
    public function process(string $eventType, array $data): void
    {
        if (in_array($eventType, self::SUBSCRIPTION_EVENTS, true)) {
            $this->subscriptions->project($data);

            return;
        }

        switch ($eventType) {
            case 'transaction.completed':
            case 'transaction.past_due':
                $this->transactions->project($data);
                break;

            case 'transaction.payment_failed':
                // Financial record only. Subscription status stays put —
                // Paddle emits subscription.* events for that.
                $this->transactions->project($data, 'failed');
                break;

            case 'adjustment.created':
            case 'adjustment.updated':
                // Refunds and chargebacks are adjustment entities. Pending or
                // partial adjustments do not revoke a lifetime grant; the
                // transaction projector handles approved full adjustments.
                $this->transactions->processAdjustment($data);
                break;

            case 'customer.created':
            case 'customer.updated':
                // Customer profile lives at the provider; nothing to project.
                Log::debug('Paddle customer event acknowledged without projection.', [
                    'event_type' => $eventType,
                ]);
                break;

            default:
                // Unknown types must never fail the queue: Paddle adds event
                // types over time and a crash-looping job would wedge the
                // whole billing queue. Log so an operator notices.
                Log::info('Unhandled Paddle event type acknowledged.', [
                    'event_type' => $eventType,
                ]);
        }
    }
}
