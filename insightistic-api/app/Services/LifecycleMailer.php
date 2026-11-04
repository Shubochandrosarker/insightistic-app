<?php

namespace App\Services;

use App\Mail\LifecycleMail;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Support\MailRecipient;
use Illuminate\Support\Facades\Mail;

/**
 * Lifecycle email dispatcher (spec §11/§12). Every send is recorded in
 * email_logs; the (organization, email_type, period_key) unique key makes
 * every automation idempotent — re-running a sweep never double-sends.
 *
 * Tone: premium, calm, helpful. No hype.
 */
class LifecycleMailer
{
    /**
     * @param array  $data      Type-specific values consumed by copyFor().
     * @param string $periodKey 'once' for one-shot emails; a period string
     *                          (e.g. '2026-07') for recurring types.
     */
    public function send(Organization $org, string $type, array $data = [], string $periodKey = 'once'): bool
    {
        $recipient = $org->owner?->email;
        if (! $recipient) {
            return false;
        }

        if (EmailLog::alreadySent($org->id, $type, $periodKey)) {
            return false;
        }

        // Reserved/undeliverable domains (test and E2E accounts): record the
        // skip so the sweep stays idempotent, and never queue a send the
        // relay is certain to reject (554 5.7.1 → failed job).
        if (! MailRecipient::isDeliverable($recipient)) {
            EmailLog::updateOrCreate(
                ['organization_id' => $org->id, 'email_type' => $type, 'period_key' => $periodKey],
                [
                    'user_id'    => $org->owner_user_id,
                    'recipient'  => $recipient,
                    'subject'    => '',
                    'status'     => 'skipped',
                    'created_at' => now(),
                ],
            );

            return false;
        }

        $copy = $this->copyFor($type, $org, $data);
        if ($copy === null) {
            return false;
        }

        // updateOrCreate: a previous 'failed' row for the same key must not
        // turn the retry into a unique-key violation.
        $log = EmailLog::updateOrCreate(
            ['organization_id' => $org->id, 'email_type' => $type, 'period_key' => $periodKey],
            [
                'user_id'    => $org->owner_user_id,
                'recipient'  => $recipient,
                'subject'    => $copy['subject'],
                'status'     => 'queued',
                'created_at' => now(),
            ],
        );

        try {
            Mail::to($recipient)->send(new LifecycleMail($copy['subject'], $copy['body']));
            $log->update(['status' => 'sent', 'sent_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'failed']);

            return false;
        }
    }

    /** @return array{subject:string,body:array}|null */
    private function copyFor(string $type, Organization $org, array $data): ?array
    {
        $app  = rtrim(config('insightistic.app_url'), '/');
        $name = $org->owner?->name ?: 'there';
        $plan = $org->plan?->name ?: 'Starter';

        return match ($type) {
            'trial_started' => [
                'subject' => 'Your Insightistic trial is live — here\'s your license key',
                'body' => [
                    'preview' => 'Connect your WordPress plugin and see your first insights today.',
                    'heading' => 'Your ' . config('insightistic.trial_days', 7) . '-day trial has started',
                    'lines'   => [
                        "Hi {$name}, your <strong>{$plan}</strong> trial is active. One key unlocks everything — paste it into the Insightistic WordPress plugin and your site connects automatically.",
                    ],
                    'panel'   => isset($data['license_key'])
                        ? ['label' => 'Your license key (shown once — keep it safe)', 'value' => $data['license_key']]
                        : null,
                    'steps'   => [
                        'Install the free Insightistic plugin on your WordPress site.',
                        'Open <strong>WP Admin → Insightistic → License</strong>.',
                        'Paste your license key and click <strong>Activate</strong>.',
                        'Your site appears in the dashboard and the first sync starts.',
                    ],
                    'cta'      => ['label' => 'Open your dashboard', 'url' => $app . '/dashboard/licenses'],
                    'footnote' => 'You can view a masked copy of your key or regenerate it any time from the Licenses tab.',
                ],
            ],
            'plugin_install_guide' => [
                'subject' => 'Connect your WordPress site in under 3 minutes',
                'body' => [
                    'preview' => 'Your dashboard is waiting for its first data.',
                    'heading' => 'Your dashboard is ready — it just needs a site',
                    'lines'   => [
                        "Hi {$name}, you haven't connected a WordPress site yet. It takes about three minutes and your first performance snapshot is generated automatically.",
                    ],
                    'steps'   => [
                        'Install the free Insightistic plugin.',
                        'Paste your license key under <strong>Insightistic → License</strong>.',
                        'Watch your analytics fill in.',
                    ],
                    'cta' => ['label' => 'Get your license key', 'url' => $app . '/dashboard/licenses'],
                ],
            ],
            'trial_ending_2d' => [
                'subject' => 'Your Insightistic trial ends in 2 days',
                'body' => [
                    'preview' => 'Keep your advanced analytics running without interruption.',
                    'heading' => 'Two days left on your trial',
                    'lines'   => [
                        "Hi {$name}, your trial ends on <strong>" . $org->trial_ends_at?->format('M j, Y') . '</strong>. Choose a plan to keep WooCommerce analytics, SEO analysis, automated reports and AI insights active.',
                        'Your data and settings are safe either way — the free plugin features keep working no matter what.',
                    ],
                    'cta' => ['label' => 'Choose your plan', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'trial_ending_today' => [
                'subject' => 'Your Insightistic trial ends today',
                'body' => [
                    'preview' => 'Pick a plan to keep advanced features active.',
                    'heading' => 'Last day of your trial',
                    'lines'   => [
                        "Hi {$name}, this is the final day of your trial. After today, advanced modules pause until you pick a plan — your basic analytics and all collected data stay intact.",
                    ],
                    'cta' => ['label' => 'Keep my features active', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'trial_expired' => [
                'subject' => 'Your trial has ended — your data is safe',
                'body' => [
                    'preview' => 'Reactivate any time. Everything is exactly where you left it.',
                    'heading' => 'Your trial has ended',
                    'lines'   => [
                        "Hi {$name}, your trial is over and advanced features are paused. Nothing was deleted — your connected sites, history and reports are all preserved.",
                        'Reactivate in one click whenever you\'re ready.',
                    ],
                    'cta' => ['label' => 'Reactivate advanced features', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'trial_winback' => [
                'subject' => 'Still tracking your WordPress growth?',
                'body' => [
                    'preview' => 'Your Insightistic workspace is waiting.',
                    'heading' => 'Your workspace is still here',
                    'lines'   => [
                        "Hi {$name}, a week ago your Insightistic trial ended. If the timing wasn't right, no problem — your workspace, sites and history are untouched.",
                        'When you\'re ready to see revenue, SEO and customer insights in one place again, it\'s one click away.',
                    ],
                    'cta' => ['label' => 'Pick up where you left off', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'site_connected' => [
                'subject' => ($data['site_name'] ?? 'Your site') . ' is now connected to Insightistic',
                'body' => [
                    'preview' => 'First sync is running — your dashboard is filling in.',
                    'heading' => 'Site connected 🎉',
                    'lines'   => [
                        "<strong>" . ($data['site_url'] ?? 'Your WordPress site') . '</strong> just activated its license and the first data sync is underway.',
                        'Within a few minutes you\'ll see traffic, revenue and health data in your dashboard.',
                    ],
                    'cta' => ['label' => 'View your site', 'url' => $app . '/dashboard'],
                ],
            ],
            'license_regenerated' => [
                'subject' => 'Your Insightistic license key was regenerated',
                'body' => [
                    'preview' => 'The old key no longer works.',
                    'heading' => 'License key regenerated',
                    'lines'   => [
                        "Hi {$name}, a new license key was generated for your account and the previous key stopped working immediately.",
                        'Connected sites keep syncing — only <em>new</em> activations need the new key. If you didn\'t do this, please reset your password and contact support.',
                    ],
                    'cta' => ['label' => 'View your licenses', 'url' => $app . '/dashboard/licenses'],
                ],
            ],
            'license_limit_reached' => [
                'subject' => 'Website limit reached on your plan',
                'body' => [
                    'preview' => 'Upgrade to connect more sites.',
                    'heading' => 'You\'ve used all your website slots',
                    'lines'   => [
                        "Hi {$name}, a site tried to activate your license but your <strong>{$plan}</strong> plan allows " . ($org->plan?->site_limit ?? 1) . ' connected website(s), and all slots are in use.',
                        'Upgrade for more slots, or deactivate a site you no longer need from the Licenses tab.',
                    ],
                    'cta' => ['label' => 'Manage websites', 'url' => $app . '/dashboard/licenses'],
                ],
            ],
            'subscription_started' => [
                'subject' => 'Welcome to Insightistic ' . $plan,
                'body' => [
                    'preview' => 'Your subscription is active.',
                    'heading' => "You're on {$plan} now",
                    'lines'   => [
                        "Hi {$name}, thanks for subscribing. Everything in the <strong>{$plan}</strong> plan is unlocked and your connected sites pick up the new access automatically within a day (or instantly with \"Refresh License\" in the plugin).",
                    ],
                    'cta' => ['label' => 'Open your dashboard', 'url' => $app . '/dashboard'],
                ],
            ],
            'plan_changed' => [
                'subject' => 'Your Insightistic plan is now ' . $plan,
                'body' => [
                    'preview' => 'Your feature access has been updated.',
                    'heading' => "Plan updated: {$plan}",
                    'lines'   => [
                        "Hi {$name}, your plan change is live. Feature access and website limits were updated, and connected plugins refresh automatically on their next daily check-in.",
                        'Need it now? Click <em>Refresh License</em> inside the WordPress plugin.',
                    ],
                    'cta' => ['label' => 'Review your plan', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'payment_failed' => [
                'subject' => 'Payment issue on your Insightistic subscription',
                'body' => [
                    'preview' => 'Your features stay active while we retry.',
                    'heading' => 'We couldn\'t process your payment',
                    'lines'   => [
                        "Hi {$name}, your latest payment didn't go through. Your advanced features remain active for " . config('insightistic.license.payment_grace_days', 7) . ' days while we retry.',
                        'Updating your card usually fixes this in under a minute.',
                    ],
                    'cta' => ['label' => 'Update payment method', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'subscription_cancelled' => [
                'subject' => 'Your Insightistic subscription was cancelled',
                'body' => [
                    'preview' => 'Access continues until the end of your billing period.',
                    'heading' => 'Cancellation confirmed',
                    'lines'   => [
                        "Hi {$name}, your subscription is cancelled" .
                        (isset($data['period_end']) ? ' and access continues until <strong>' . $data['period_end'] . '</strong>.' : '.'),
                        'Your data stays safe, and the free plugin features keep working. Changed your mind? Reactivate any time.',
                    ],
                    'cta' => ['label' => 'Reactivate', 'url' => $app . '/dashboard/billing'],
                ],
            ],
            'subscription_reactivated' => [
                'subject' => 'Welcome back — your subscription is active again',
                'body' => [
                    'preview' => 'All advanced features are unlocked.',
                    'heading' => 'Subscription reactivated',
                    'lines'   => [
                        "Hi {$name}, your subscription is active again and every advanced feature is unlocked. Connected sites refresh automatically.",
                    ],
                    'cta' => ['label' => 'Open your dashboard', 'url' => $app . '/dashboard'],
                ],
            ],
            default => null,
        };
    }
}
