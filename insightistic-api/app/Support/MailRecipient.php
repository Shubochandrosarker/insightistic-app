<?php

namespace App\Support;

/**
 * Recipient guard for outbound mail. Addresses on domains reserved by
 * RFC 2606 / RFC 6761 (example.com, *.test, *.invalid, ...) can never
 * receive mail; the SMTP relay rejects them with 554 5.7.1, which turns
 * every queued send into a failed job. Test and E2E accounts created on
 * production use these domains, so the guard skips them before queueing.
 */
final class MailRecipient
{
    /** Second-level domains reserved for documentation (RFC 2606 §3). */
    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** Reserved TLDs (RFC 2606 §2, RFC 6761 §6). */
    private const RESERVED_TLDS = ['test', 'example', 'invalid', 'localhost'];

    public static function isDeliverable(?string $email): bool
    {
        $email = trim((string) $email);

        if (! config('insightistic.skip_reserved_mail_domains', true)) {
            return $email !== '';
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower(rtrim(substr($email, strrpos($email, '@') + 1), '.'));

        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return false;
            }
        }

        $tld = substr($domain, (int) strrpos($domain, '.') + (str_contains($domain, '.') ? 1 : 0));

        return ! in_array($tld, self::RESERVED_TLDS, true);
    }
}
