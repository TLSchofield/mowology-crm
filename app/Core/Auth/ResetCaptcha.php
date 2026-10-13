<?php
declare(strict_types=1);

/**
 * app/Core/Auth/ResetCaptcha.php — reCAPTCHA v3 on the two forgot-password pages (#9, Fable audit
 * 2026-10-12). Reuses the quote form's helper (public/jobFlow/recaptcha-helpers.php) and the existing
 * RECAPTCHA_V3_* keys from secrets.php — no new keys. When v3 isn't configured the check is skipped
 * (the per-email/per-IP throttle in AttemptThrottle still applies).
 */
final class ResetCaptcha
{
    public const ACTION = 'password_reset';

    private static function load(): bool
    {
        if (!function_exists('is_recaptcha_v3_configured')) {
            $f = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 3) . '/public') . '/jobFlow/recaptcha-helpers.php';
            if (!is_file($f)) return false;
            require_once $f;
        }
        return function_exists('is_recaptcha_v3_configured');
    }

    public static function enabled(): bool
    {
        return self::load() && is_recaptcha_v3_configured();
    }

    /** True when the request may continue. */
    public static function passes(string $token, ?string $ip): bool
    {
        if (!self::enabled()) return true;
        if ($token === '') return false;
        $r = verify_recaptcha_v3_audited($token, self::ACTION, $ip);
        return !empty($r['passed']);
    }

    /** <script> tags for the page <head>; '' when disabled. */
    public static function headTags(): string
    {
        if (!self::enabled()) return '';
        $key = htmlspecialchars((string)RECAPTCHA_V3_SITE_KEY, ENT_QUOTES, 'UTF-8');
        return '<script src="https://www.google.com/recaptcha/api.js?render=' . $key . '"></script>';
    }

    /**
     * Hidden field + submit hook for the form with id $formId; '' when disabled. The form posts once a
     * token is in the field (a blocked Google script still submits; the server then refuses).
     */
    public static function formTags(string $formId): string
    {
        if (!self::enabled()) return '';
        $key = json_encode((string)RECAPTCHA_V3_SITE_KEY);
        $fid = json_encode($formId);
        $act = json_encode(self::ACTION);
        return '<input type="hidden" name="recaptcha_v3_token" id="recaptcha_v3_token" value="">'
            . "<script>(function(){var f=document.getElementById($fid);if(!f)return;var done=false;"
            . "f.addEventListener('submit',function(e){if(done)return;e.preventDefault();"
            . "function go(){done=true;f.submit();}"
            . "if(typeof grecaptcha==='undefined'){go();return;}"
            . "grecaptcha.ready(function(){grecaptcha.execute($key,{action:$act}).then(function(t){"
            . "document.getElementById('recaptcha_v3_token').value=t;go();},go);});});})();</script>";
    }
}
