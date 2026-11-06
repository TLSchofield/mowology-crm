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
