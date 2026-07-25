<?php

namespace App\Services\Auth;

use App\Mail\LifecycleMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * "New sign-in to your Insightistic account" notifications (spec Phase 1.4).
 *
 * Only fires when the device fingerprint changes, so a user signing in from
 * their usual laptop every morning is never emailed — which is what keeps
 * the notification meaningful enough to act on.
 *
 * The fingerprint is a salted hash of (IP, user agent) and the raw values are
 * never stored. That answers "is this a new device?" without turning the
 * users table into a log of where people sign in from.
 *
 * The very first tracked login records the fingerprint silently: everyone's
 * next sign-in after this ships would otherwise look new and generate a
 * pointless alert for the whole user base.
 */
class LoginNotifier
{
    public function handle(Request $request, User $user): void
    {
        $fingerprint = $this->fingerprint($request);
        $previous = $user->last_login_fingerprint;

        $user->forceFill([
            'last_login_at'          => now(),
            'last_login_fingerprint' => $fingerprint,
        ])->save();

        if ($previous === null || $previous === $fingerprint) {
            return;
        }

        $this->notify($user, $request);
    }

    private function notify(User $user, Request $request): void
    {
        $subject = 'New sign-in to your Insightistic account';

        // LifecycleMail renders the shared template and takes a structured
        // body (heading, lines[], panel?, cta?, footnote?) — not a string.
        $body = [
            'preview' => 'A device we have not seen before signed in to your account.',
            'heading' => 'New sign-in to your account',
            'lines'   => [
                'We noticed a sign-in to your Insightistic account from a device we have not seen before.',
                'If this was you, there is nothing to do.',
                'If it was not, change your password now and sign out every other device from Settings → Sessions. '
                    . 'Turning on two-factor authentication stops a stolen password being enough on its own.',
            ],
            'panel' => [
                'label' => 'Signed in at',
                'value' => now()->toDayDateTimeString() . ' UTC',
            ],
            'cta' => [
                'label' => 'Review your sessions',
                'url'   => rtrim((string) config('insightistic.app_url'), '/') . '/dashboard/settings',
            ],
            'footnote' => 'We send this only when the device is new to us, so it stays worth reading.',
        ];

        $log = EmailLog::create([
            'organization_id' => $user->organizations()->first()?->id,
            'user_id'         => $user->id,
            'email_type'      => 'security_new_login',
            // Per-device: re-signing in from the same new device won't re-alert.
            'period_key'      => substr((string) $user->last_login_fingerprint, 0, 40),
            'recipient'       => $user->email,
            'subject'         => $subject,
            'status'          => 'queued',
            'created_at'      => now(),
        ]);

        try {
            Mail::to($user->email)->send(new LifecycleMail($subject, $body));
            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            // A mail outage must never block a legitimate sign-in.
            report($e);
            $log->update(['status' => 'failed']);
        }
    }

    /**
     * Salted with APP_KEY so fingerprints are not comparable across
     * deployments and cannot be brute-forced back to an IP from a dump alone.
     */
    private function fingerprint(Request $request): string
    {
        return hash_hmac(
            'sha256',
            $request->ip() . '|' . substr((string) $request->userAgent(), 0, 500),
            (string) config('app.key'),
        );
    }
}
