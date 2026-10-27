<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/** Logging that can never throw (e.g. an unwritable log file mid-SSE-stream). */
final class SafeLog
{
    public static function warning(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            // swallow: diagnostics must not break user-facing requests
        }
    }
}
