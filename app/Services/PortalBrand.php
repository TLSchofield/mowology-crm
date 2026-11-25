<?php
/**
 * PortalBrand — who a client sees on the customer pages (invoice, quote, contract, portal, gallery, reports).
 *
 * Built on BusinessIdentity, so each business shows its own name and contact details. Blank stays blank: a
 * business with no phone gets no "call us", never someone else's number. The original business keeps its logo.
 *
 * Methods ending in Html return escaped markup. The others return plain text: escape it where it is printed.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/BusinessIdentity.php';

final class PortalBrand
{
    public const NO_NAME = 'Your service provider';

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    /** "Mowology": how people say it. */
    public static function name(): string
    {
        $i = BusinessIdentity::get();
        return $i['name'] !== '' ? $i['name'] : ($i['display_name'] !== '' ? $i['display_name'] : self::NO_NAME);
    }

    /** "Mowology Landscaping": titles, footers and agreements. */
    public static function displayName(): string
    {
        $i = BusinessIdentity::get();
        return $i['display_name'] !== '' ? $i['display_name'] : self::name();
    }

    public static function phone(): string
    {
        return BusinessIdentity::get()['phone'];
    }

    public static function email(): string
    {
        return BusinessIdentity::get()['email'];
    }

    /** The inside of the text logo in the page header. */
    public static function logoHtml(): string
    {
        return Tenant::isDefault() ? 'Mowo<span>logy</span>' : self::e(self::displayName());
    }

    /** The image logo where a page has one; the business's name as text when it has no logo. */
    public static function logoImgHtml(?string $logoPath = null): string
    {
        if (Tenant::isDefault()) return '<img src="/assets/img/logo/mowology-logo.jpg" alt="Mowology" class="portal-logo-img">';
        $path = trim((string)($logoPath ?? self::logoPath()));
        if ($path !== '' && $path[0] === '/' && strpos($path, '//') === false && strpos($path, '..') === false) {
            return '<img src="' . self::e($path) . '" alt="' . self::e(self::displayName()) . '" class="portal-logo-img">';
        }
        return '<div class="portal-logo-text">' . self::e(self::displayName()) . '</div>';
    }

    public static function phoneLinkHtml(): string
    {
        $phone = self::phone();
        if ($phone === '') return '';
        $d = (string)preg_replace('/\D+/', '', $phone);
        return '<a href="tel:' . (strlen($d) === 10 ? '+1' : '') . $d . '">' . self::e($phone) . '</a>';
    }

    public static function emailLinkHtml(): string
    {
        $email = self::email();
        return $email === '' ? '' : '<a href="mailto:' . self::e($email) . '">' . self::e($email) . '</a>';
    }

    /** Phone and email links, whichever exist, or ''. */
    public static function contactHtml(): string
    {
        return implode(' or ', array_filter([self::phoneLinkHtml(), self::emailLinkHtml()]));
    }

    /** "Questions? Call … or email …", or '' when the business has neither. */
    public static function questionsHtml(): string
    {
        $phone = self::phoneLinkHtml();
        $email = self::emailLinkHtml();
        if ($phone !== '' && $email !== '') return 'Questions? Call ' . $phone . ' or email ' . $email;
        if ($phone !== '') return 'Questions? Call ' . $phone;
        return $email !== '' ? 'Questions? Email ' . $email : '';
    }

    /** Plain text for the middle of a sentence: "call us at …", "email us at …", or "contact us". */
    public static function callPhrase(): string
    {
        if (self::phone() !== '') return 'call us at ' . self::phone();
        return self::email() !== '' ? 'email us at ' . self::email() : 'contact us';
    }

    /** Plain text: "Please contact Mowology at (778) 846-9273." */
    public static function contactSentence(): string
    {
        $how = self::phone() !== '' ? self::phone() : self::email();
        return 'Please contact ' . self::name() . ($how !== '' ? ' at ' . $how : '') . '.';
    }

    /** The business's public website, or its Ittai address when it has none. */
    public static function homeUrl(): string
    {
        $i = BusinessIdentity::get();
        $site = $i['website'];
        if ($site === '') return self::appUrl();
        return preg_match('#^https?://#i', $site) ? rtrim($site, '/') : 'https://' . rtrim($site, '/');
    }

    /** Where this business's CRM and client portal live. */
    public static function appUrl(): string
    {
        $url = BusinessIdentity::get()['app_url'];
        if ($url !== '') return $url;
        if (Tenant::isDefault()) return defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
        return Tenant::ownAddress();
    }

    /** Text for a "Return to …" link. */
    public static function websiteLabel(): string
    {
        $i = BusinessIdentity::get();
        $site = trim((string)preg_replace('#^https?://#i', '', $i['website']), '/');
        return $site !== '' ? $site : self::name();
    }

    private static function logoPath(): string
    {
        try {
            $v = getDB()->query('SELECT logo_path FROM business_settings ORDER BY id LIMIT 1')->fetchColumn();
            return $v === false ? '' : (string)$v;
        } catch (Throwable $e) {
            return '';
        }
    }
}
