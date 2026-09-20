<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingReconciler;
use App\Services\Billing\PaddleClient;
use Illuminate\Console\Command;

/**
 * Nightly billing reconciliation (roadmap 23-STRIPE-TO-PADDLE-MIGRATION).
 * Re-pulls every Paddle subscription and re-runs one-time-purchase
 * fulfilment for the recent window, repairing any drift webhooks missed.
 * Runbook: docs/roadmap-2026/23-STRIPE-TO-PADDLE-MIGRATION.md §4.3.
 */
class BillingReconcileCommand extends Command
{
    protected $signature = 'insightistic:billing-reconcile
                            {--organization= : Limit reconciliation to one organization id}
                            {--provider=paddle : Billing provider to reconcile (paddle only for now)}
                            {--recent-transactions=7 : Fulfilment re-check window in days}';

    protected $description = 'Reconcile local billing state against the active provider (repairs drift, never deletes)';

    public function handle(BillingReconciler $reconciler, PaddleClient $client): int
    {
        $provider = (string) $this->option('provider');

        if ($provider !== 'paddle') {
            $this->info("No reconciler for provider [{$provider}] yet — nothing to do.");

            return self::SUCCESS;
        }

        // Graceful no-op until Paddle is switched on in an environment; the
        // schedule stays registered so flipping the env var is all it takes.
        if (! $client->configured()) {
            $this->info('PADDLE_API_KEY not configured — skipping billing reconciliation.');

            return self::SUCCESS;
        }

        $subscriptions = $reconciler->reconcileSubscriptions(
            $this->option('organization') !== null ? (int) $this->option('organization') : null,
        );

        $days = max(1, (int) $this->option('recent-transactions'));
        $transactions = $reconciler->reconcileUnfulfilledTransactions($days);

        $this->info('Subscriptions: checked ' . $subscriptions['checked']
            . ', projected ' . $subscriptions['projected']
            . ', not found ' . $subscriptions['missing']
            . ', errors ' . $subscriptions['errors']);

        $this->info('Recent transactions (' . $days . 'd): checked ' . $transactions['checked']
            . ', grants repaired ' . $transactions['repaired_grants']
            . ', credits repaired ' . $transactions['repaired_credits']
            . ', unfulfillable credit packs ' . $transactions['missing_credits']
            . ', errors ' . $transactions['errors']);

        // Errors are already logged with full context; a partially repaired
        // night is still worth having happened, so this is never a FAILURE.
        return self::SUCCESS;
    }
}
