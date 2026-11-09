<?php

declare(strict_types=1);

final class PublicFormSecurity
{
    public const MAX_SIGNATURE_LENGTH = 300000;

    public static function isValidSignatureData(string $signatureData): bool
    {
        return strlen($signatureData) <= self::MAX_SIGNATURE_LENGTH
            && preg_match('#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$#', $signatureData) === 1;
    }

    /**
     * The site address the reCAPTCHA hostname is checked against. Pages that load only
     * app_config/config.php (the jobFlow quote form, password reset) never define SITE_URL, and
     * with an empty address every check failed: quote form and reset were dead from the 2026-11-06
     * deploy until 2026-11-07. Falls back to the same default as public/includes/bootstrap.php.
     * Never derived from the request's Host header, which the visitor controls.
     */
    public static function recaptchaSiteUrl(?string $siteUrl): string
    {
        $siteUrl = trim((string)$siteUrl);
        return ($siteUrl !== '' && (string)parse_url($siteUrl, PHP_URL_HOST) !== '') ? $siteUrl : 'https://mowology.ca';
    }

    public static function isAllowedRecaptchaHostname(string $hostname, string $siteUrl, string $requestHost): bool
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $siteHost = strtolower((string)parse_url($siteUrl, PHP_URL_HOST));
        $requestHost = strtolower(preg_replace('/:\d+$/', '', trim($requestHost)) ?? '');

        if ($hostname === '' || $siteHost === '') {
            return false;
        }

        $siteHostWithoutWww = strpos($siteHost, 'www.') === 0 ? substr($siteHost, 4) : $siteHost;
        if ($hostname === $siteHostWithoutWww || $hostname === 'www.' . $siteHostWithoutWww) {
            return true;
        }

        return in_array($requestHost, ['localhost', '127.0.0.1'], true)
            && in_array($hostname, ['localhost', '127.0.0.1'], true);
    }
}
