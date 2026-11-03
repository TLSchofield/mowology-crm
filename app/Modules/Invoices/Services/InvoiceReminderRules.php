<?php
declare(strict_types=1);

/**
 * InvoiceReminderRules — which reminder recipients must be sent the invoice PDF (Tim, 2026-10-28; asked three times).
 *
 * A property manager's accounts-payable system (LeapAP / Condoworks, an ap@ or invoices@ mailbox) treats ANY email it receives as an
 * invoice submission and rejects one with no PDF or image attached. Payment reminders went out link-only, so on 2026-10-28 06:00 LeapAP
 * rejected the reminder for INV-2026-0431 ("No PDF or image attachments found"), and every earlier reminder to that inbox did the same.
 * Reminders to PEOPLE stay link-only (Tim's choice, 2026-10-06); reminders to accounts-payable addresses carry the PDF, exactly like the
 * invoice email itself.
 *
 * Pure. No namespace / no autoloader: require_once and call statically.
 */
final class InvoiceReminderRules
{
    /** Domains that are invoice-intake systems rather than a person's mailbox. */
    public const AP_DOMAINS = '/(condoworks|leapap|coupa|tipalti|bill\.com|paymode|avidxchange|ariba|yardi|stampli|basware)/i';
    /** Mailbox names that mean "send invoices here". */
    public const AP_LOCAL = '/^(ap|ar|accounts?|accounting|payables?|invoices?|invoicing|inv|billing|bills?)([._+\-0-9]|$)/i';
    public const AP_ROLES = ['accounting'];

    public static function needsPdf(string $email, string $role = ''): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || strpos($email, '@') === false) return false;
        if (in_array(strtolower($role), self::AP_ROLES, true)) return true;
        [$local, $domain] = explode('@', $email, 2);
        return (bool)(preg_match(self::AP_DOMAINS, $domain) || preg_match(self::AP_LOCAL, $local));
    }
}
