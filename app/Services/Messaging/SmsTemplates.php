<?php
/**
 * SMS message text builders — pure functions, no sending.
 *
 * Carrier email-to-SMS gateways silently drop anything that looks like a link,
 * so these texts carry NO URLs or bare domains, stay ≤160 chars, point the
 * customer to their email, and give the business phone as a fallback (CLAUDE.md rule 11).
 *
 * Used by MessagingService.php (the legacy SmsGateway.php copy was deleted
 * 2026-10-12 — it was never loaded and its prod copy still built a URL).
 */

declare(strict_types=1);

require_once __DIR__ . '/IdentityMail.php';

if (!function_exists('buildQuoteNotificationSmsText')) {
    function buildQuoteNotificationSmsText(string $quoteNumber, string $companyName = ''): string
    {
        $fallbackName = _idSmsName();
        // A dot in the company name ("Mowology Inc.") could read as a domain to a gateway filter
        $company = trim(str_replace('.', '', $companyName)) ?: $fallbackName;
        $call    = _idSmsTail('Call');

        $message = "{$company}: Your quote {$quoteNumber} is ready. Check your email to view and accept it." . ($call !== '' ? " Questions?{$call}" : '');

        if (strlen($message) > 160) {
            $message = "{$company}: Quote {$quoteNumber} is ready. Check your email.{$call}";
        }
        if (strlen($message) > 160) {
            $message = "{$fallbackName}: Quote {$quoteNumber} is ready. Check your email.{$call}";
        }

        return $message;
    }
}
