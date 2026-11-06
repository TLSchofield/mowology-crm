<?php
declare(strict_types=1);

require_once __DIR__ . '/ResetCaptcha.php';

/**
 * app/Core/Auth/LoginCaptcha.php — reCAPTCHA v3 on the two web sign-in forms (security review 2026-11-06, sign-in plan
 * step 1). Same key and helper as ResetCaptcha (the key lists mowology.ca and ittai.ca since 2026-11-06), action 'login'.
 *
 * Starts in WATCH mode: every sign-in is scored and logged, nobody is refused — crew truck tablets sign in through an
 * Android WebView, which can score low, so a week of real scores decides the threshold before ENFORCE is switched on
 * (ops_settings login_captcha_mode = 'enforce'). Enforce refuses a missing token or a low score; keys not configured
 * still let people in, so a missing secret can never lock the office out.
 */
final class LoginCaptcha
{
    public const ACTION = 'login';
    public const SETTING = 'login_captcha_mode';

    public static function mode(?PDO $db = null): string
    {
        try {
            $db = $db ?? getDB();
            $s = $db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::SETTING]);
            return $s->fetchColumn() === 'enforce' ? 'enforce' : 'watch';
        } catch (Throwable $e) {
            return 'watch';
        }
    }

    /**
     * Pure (tested): may this sign-in go on? $result is verify_recaptcha_v3()'s answer, null when there was no token.
     * @param array{passed?:bool,error?:?string}|null $result
     */
    public static function allows(string $mode, ?array $result): bool
    {
        if ($mode !== 'enforce') return true;
        if ($result === null) return false;
        if (($result['error'] ?? null) === 'v3_not_configured') return true;
        return !empty($result['passed']);
    }

    /** Score this sign-in, log it, and say whether it may go on. */
    public static function check(string $token, ?string $ip, ?PDO $db = null): bool
    {
        if (!ResetCaptcha::enabled()) return true;
        $mode = self::mode($db);
        $result = $token === '' ? null : verify_recaptcha_v3($token, self::ACTION, $ip);
        $allowed = self::allows($mode, $result);
        try {
            ($db ?? getDB())->prepare("INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (NULL, 'login_captcha', ?, ?)")
                ->execute([json_encode(['mode' => $mode, 'score' => $result['score'] ?? null, 'error' => $result === null ? 'no_token' : ($result['error'] ?? null),
                    'allowed' => $allowed, 'host' => substr((string)($_SERVER['HTTP_HOST'] ?? ''), 0, 80)]), $ip]);
        } catch (Throwable $e) { /* the log must never block a sign-in */ }
        return $allowed;
    }

    public static function headTags(): string { return ResetCaptcha::headTags(); }

    public static function formTags(string $formId): string { return ResetCaptcha::formTags($formId, self::ACTION); }
}
