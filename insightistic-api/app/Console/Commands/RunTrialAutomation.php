<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Organization;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use Illuminate\Console\Command;

/**
 * Trial lifecycle sweep (spec §12 Trial Ending / Trial Expired). Runs hourly.
 * Every send is deduped through email_logs, so re-runs are always safe.
 */
class RunTrialAutomation extends Command
{
    protected $signature = 'insightistic:trials';

    protected $description = 'Send trial reminders, enforce trial expiry, and run the win-back sweep.';

    public function handle(LicenseService $licenses, LifecycleMailer $mailer): int
    {
        $reminded = $expired = $winback = 0;

        // Orgs still marked trialing with a trial end date and no live subscription.
        $orgs = Organization::with(['plan', 'owner'])
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->get();

        foreach ($orgs as $org) {
            $endsAt = $org->trial_ends_at;

            if ($endsAt->isFuture()) {
                $daysLeft = (int) ceil(now()->diffInHours($endsAt, false) / 24);

                if ($daysLeft <= 2 && $daysLeft > 1) {
                    if ($mailer->send($org, 'trial_ending_2d')) {
                        $reminded++;
                        $this->notify($org, 'trial_ending', 'Your trial ends in 2 days',
                            'Keep advanced analytics active by choosing a plan.', '/dashboard/billing');
                    }
                } elseif ($daysLeft <= 1) {
                    if ($mailer->send($org, 'trial_ending_today')) {
                        $reminded++;
                    }
                } elseif ($daysLeft <= 6 && ! $this->hasActivation($org)) {
                    // Day-1 nudge: dashboard exists but no site connected yet.
                    if ($mailer->send($org, 'plugin_install_guide')) {
                        $reminded++;
                    }
                }

                continue;
            }

            // Trial has lapsed → project license status (expired), lock advanced
            // features, keep the free plugin working, tell the user once.
            app(\App\Domain\Billing\TrialService::class)->expire($org);
            $licenses->syncForOrganization($org->fresh());

            if ($mailer->send($org, 'trial_expired')) {
                $expired++;
                $this->notify($org, 'trial_expired', 'Your trial has ended',
                    'Advanced features are paused. Reactivate to continue.', '/dashboard/billing');
            }

            // Win-back one week after expiry, still unconverted.
            if ($endsAt->copy()->addDays(7)->isPast() && $mailer->send($org, 'trial_winback')) {
                $winback++;
            }
        }

        $this->info("Trial sweep: {$reminded} reminder(s), {$expired} expiry email(s), {$winback} win-back(s).");

        return self::SUCCESS;
    }

    private function hasActivation(Organization $org): bool
    {
        return \App\Models\License::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->whereHas('activations', fn ($q) => $q->where('status', 'active'))
            ->exists();
    }

    private function notify(Organization $org, string $type, string $title, string $message, string $url): void
    {
        Notification::withoutGlobalScope('organization')->firstOrCreate(
            ['organization_id' => $org->id, 'type' => $type, 'status' => 'unread'],
            ['title' => $title, 'message' => $message, 'action_url' => $url, 'created_at' => now()],
        );
    }
}
