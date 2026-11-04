<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'user_id', 'email_type', 'period_key', 'recipient',
        'subject', 'status', 'error', 'provider_message_id', 'sent_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** True if this (org, type, period) email has already been queued or sent. */
    public static function alreadySent(int $organizationId, string $type, string $periodKey = 'once'): bool
    {
        return static::where('organization_id', $organizationId)
            ->where('email_type', $type)
            ->where('period_key', $periodKey)
            ->whereIn('status', ['queued', 'sent', 'skipped'])
            ->exists();
    }

    /** Delivery confirmed (the mailer returned without throwing). */
    public static function markSent(?int $id): void
    {
        if ($id) {
            static::whereKey($id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        }
    }

    /** Delivery failed for good; never downgrades a row that was already sent. */
    public static function markFailed(?int $id, ?\Throwable $e): void
    {
        if ($id) {
            static::whereKey($id)->where('status', '!=', 'sent')
                ->update(['status' => 'failed', 'error' => static::sanitizeError($e)]);
        }
    }

    /** Short, single-line error with credentials, tokens and addresses redacted. */
    public static function sanitizeError(?\Throwable $e): ?string
    {
        if (! $e) {
            return null;
        }
        $msg = class_basename($e) . ': ' . $e->getMessage();
        $msg = preg_replace('#(\w+://)[^\s/@]+@#', '$1***@', $msg);                       // user:pass@ in DSNs
        $msg = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|auth\w*)\b\s*[=:]\s*\S+/i', '$1=***', $msg);
        $msg = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $msg);
        $msg = preg_replace('/\b(sk|pk|rk|ghp|gho|github_pat|xox[a-z])_[A-Za-z0-9_]{8,}/', '***', $msg);
        $msg = preg_replace('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '***@$1', $msg); // mask local part
        $msg = trim(preg_replace('/\s+/', ' ', $msg));

        return mb_strimwidth($msg, 0, 500, '…');
    }
}
