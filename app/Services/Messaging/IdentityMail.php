<?php
/**
 * IdentityMail — the sender identity for outgoing mail and SMS, read from BusinessIdentity
 * (the business's own settings) instead of hard-coded. Blank stays blank: a business never
 * sends as someone else.
 *
 *   require_once APP_ROOT . '/Services/Messaging/IdentityMail.php';
 */

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}
require_once APP_ROOT . '/Services/BusinessIdentity.php';

if (!function_exists('_idFromName')) {
    /** Display name for From: the caller's own name if given, else the business's short name. */
    function _idFromName(string $given = ''): string
    {
        $given = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $given));   // header injection (Codex 2026-10-23)
        return $given !== '' ? $given : (BusinessIdentity::get()['mail_from_name'] ?: 'Ittai');
    }
}
if (!function_exists('_idFromEmail')) {
    function _idFromEmail(): string
    {
        return BusinessIdentity::get()['mail_from'];
    }
}
if (!function_exists('_idReplyTo')) {
    /** Reply-To: the business's own email, falling back to the From address. */
    function _idReplyTo(): string
    {
        $b = BusinessIdentity::get();
        return $b['reply_to'] !== '' ? $b['reply_to'] : $b['mail_from'];
    }
}
if (!function_exists('_idPhone')) {
    function _idPhone(): string
    {
        return BusinessIdentity::get()['phone'];
    }
}
if (!function_exists('_idSmsTail')) {
    /** " Call (778) 846-9273." — or "" when the business has no phone on file. */
    function _idSmsTail(string $lead = 'Call'): string
    {
        $p = _idPhone();
        return $p !== '' ? ' ' . $lead . ' ' . $p . '.' : '';
    }
}
if (!function_exists('_idSiteUrl')) {
    /** The business's own site, https-prefixed ('' when none is on file). */
    function _idSiteUrl(): string
    {
        // The CRM / client portal's own address (install, request host, else website) — never a hostless link.
        return BusinessIdentity::get()['app_url'];
    }
}
if (!function_exists('_idCompanyInfo')) {
    /** Same shape as EmailWrapper::getCompanyInfo(), from BusinessIdentity. */
    function _idCompanyInfo(): array
    {
        $b = BusinessIdentity::get();
        return [
            'company_name'    => $b['display_name'],
            'company_phone'   => $b['phone'],
            'company_email'   => $b['email'],
            'company_website' => _idSiteUrl(),
        ];
    }
}
if (!function_exists('_idBrand')) {
    /**
     * HTML-escaped identity bits for templates and heredocs: name (short), full (display name), phone,
     * site (https URL), siteLabel (host only), email, callLine (" or call us at <strong>phone</strong>" or "").
     */
    function _idBrand(): array
    {
        $b = BusinessIdentity::get();
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $site = _idSiteUrl();
        return [
            'name'      => $h(_idFromName()),
            'full'      => $h($b['display_name']),
            'phone'     => $h($b['phone']),
            'site'      => $h($site),
            'siteLabel' => $h(preg_replace('#^https?://#', '', $site)),
            'email'     => $h($b['email']),
            'callLine'  => $b['phone'] !== '' ? ' or call us at <strong>' . $h($b['phone']) . '</strong>' : '',
        ];
    }
}

if (!function_exists('_idSmsName')) {
    /** The business name as it may appear in a text: no dots, @ or slashes (carriers drop link-like texts), ≤ 30 chars. */
    function _idSmsName(): string
    {
        return BusinessIdentity::get()['sms_name'] ?: 'us';
    }
}
