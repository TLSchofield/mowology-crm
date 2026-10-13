<?php
/**
 * SMS message text builders — pure functions, no sending.
 *
 * Carrier email-to-SMS gateways silently drop anything that looks like a link,
 * so these texts carry NO URLs or bare domains, stay ≤160 chars, point the
 * customer to their email, and give (778) 846-9273 as a fallback (CLAUDE.md rule 11).
 *
 * Used by MessagingService.php (the legacy SmsGateway.php copy was deleted
 * 2026-10-12 — it was never loaded and its prod copy still built a URL).
 */

declare(strict_types=1);

if (!function_exists('buildQuoteNotificationSmsText')) {
    function buildQuoteNotificationSmsText(string $quoteNumber, string $companyName = 'Mowology'): string
    {
        // A dot in the company name ("Mowology Inc.") could read as a domain to a gateway filter
        $company = trim(str_replace('.', '', $companyName)) ?: 'Mowology';

        $message = "{$company}: Your quote {$quoteNumber} is ready. Check your email to view and accept it. Questions? Call (778) 846-9273.";

        if (strlen($message) > 160) {
            $message = "{$company}: Quote {$quoteNumber} is ready. Check your email. Call (778) 846-9273.";
        }
        if (strlen($message) > 160) {
            $message = "Mowology: Quote {$quoteNumber} is ready. Check your email. Call (778) 846-9273.";
        }

        return $message;
    }
}
