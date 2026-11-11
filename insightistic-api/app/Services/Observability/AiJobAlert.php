<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Operator alert for scheduled AI jobs that crash or write nothing.
 *
 * Always logs at CRITICAL. When INSIGHTISTIC_OPS_ALERT_EMAIL is set it also
 * sends one plain-text email per alert key per day, so a broken job that
 * keeps failing every morning does not flood the inbox.
 */
final class AiJobAlert
{
    public function notify(string $key, string $subject, array $context = []): void
    {
        Log::critical('ai_job.alert: ' . $subject, ['alert' => $key] + $context);

        $to = config('insightistic.ops.alert_email');
        if (! is_string($to) || trim($to) === '') {
            return;
        }

        try {
            if (! Cache::add('ai_jobs:alert_sent:' . $key . ':' . now()->toDateString(), true, now()->addDay())) {
                return; // already emailed today
            }

            $body = $subject . "\n\n"
                . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
                . 'Release: ' . config('insightistic.release.sha', 'unknown') . "\n"
                . 'Sent by insightistic-api (' . app()->environment() . ").\n";

            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to(array_map('trim', explode(',', $to)))
                    ->subject('[Insightistic alert] ' . $subject);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
