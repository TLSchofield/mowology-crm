<?php
/**
 * CaslRules — the pure rules every commercial email path shares (Canada's anti-spam law).
 *
 * The gate itself is ConsentLedgerService::allows() (asked through canSendMarketing() with a
 * contact id). This file holds what has no database: which automated messages are commercial,
 * the finished email with sender + postal address + unsubscribe (or null — never send without
 * them), and which links must never go through the click tracker.
 *
 * Fable review 2026-10-12: automation rules, referral invites and review requests sent
 * commercial email with neither the gate nor the footer. They all come through here now.
 *
 * Pure. No namespace / no autoloader.
 */
final class CaslRules
{
    /**
     * Automation triggers whose email is NOT commercial: a quote the person asked for
     * (CASL s.6(6)(a)), or a bill they owe (s.6(6)(c)). Everything else an automation sends —
     * re-engagement, tag, season, product, post-job — is commercial and needs consent + footer.
     * Unknown triggers default to commercial.
     */
    public const TRANSACTIONAL_TRIGGERS = ['quote_created', 'quote_viewed', 'quote_followup', 'quote_accepted', 'invoice_overdue'];

    public static function isCommercialTrigger(string $trigger): bool
    {
        return !in_array($trigger, self::TRANSACTIONAL_TRIGGERS, true);
    }

    /**
     * The finished commercial email: HTML body in the branded wrapper, which names the sender
     * (company, postal address, phone) and carries the unsubscribe link. Null when any of those
     * would be missing — the caller must not send.
     *
     * @param callable|null $wrap fn(string $html, string $unsubUrl): string — defaults to wrapInBrandedEmail()
     */
    public static function composeHtml(string $bodyHtml, string $unsubscribeUrl, array $company, ?callable $wrap = null): ?string
    {
        if (!preg_match('~^https://\S+unsubscribe\S*token=~', $unsubscribeUrl)) return null;
        if (trim((string)($company['name'] ?? '')) === '' || trim((string)($company['address'] ?? '')) === '') return null;
        if (trim(strip_tags($bodyHtml)) === '') return null;
        if ($wrap === null) {
            if (!function_exists('wrapInBrandedEmail')) return null;
            $wrap = static fn(string $h, string $u): string => wrapInBrandedEmail($h, $u);
        }
        $html = (string)$wrap($bodyHtml, $unsubscribeUrl);
        if (strpos($html, htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8')) === false) return null;
        if (strpos($html, htmlspecialchars(trim((string)$company['address']), ENT_QUOTES, 'UTF-8')) === false) return null;
        return $html;
    }

    /** An unsubscribe link is never rewritten through the click tracker (it is not a click). */
    public static function isUnsubscribeLink(string $url): bool
    {
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        return $path !== '' && strpos($path, 'unsubscribe') !== false;
    }
}
