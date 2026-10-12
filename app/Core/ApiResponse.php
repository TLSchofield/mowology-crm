<?php
/**
 * ApiResponse — the small rules every session JSON API shares (2026-10-12, review round 2).
 *
 *   ApiResponse::csrfToken($input)      the token from the JSON body, form field or X-CSRF-Token header
 *   ApiResponse::status($e)             an HTTP status for a caught Throwable: its code only when it is
 *                                       an int 400–599 (PDOException codes are SQLSTATE strings — under
 *                                       strict_types they made http_response_code() throw a TypeError)
 *   ApiResponse::message($e, $context)  what the user may see: a database error or a PHP Error
 *                                       (TypeError, …) is logged and replaced with a generic sentence;
 *                                       an Exception the code threw on purpose keeps its message
 *
 * Pure functions (no session, no output) so tests/Unit/Core/ApiResponseTest.php can pin them.
 */
declare(strict_types=1);

final class ApiResponse
{
    public const GENERIC = 'Something went wrong on the server. Please try again, or tell the office if it keeps happening.';

    public static function csrfToken(array $input = []): string
    {
        $t = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($t) ? $t : '';
    }

    public static function status(Throwable $e): int
    {
        $code = $e->getCode();
        return (is_int($code) && $code >= 400 && $code < 600) ? $code : 500;
    }

    /** True when the message may be shown: an Exception thrown on purpose, not a database or PHP error. */
    public static function isSafe(Throwable $e): bool
    {
        if ($e instanceof PDOException || $e instanceof Error) return false;
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            if ($p instanceof PDOException) return false;
        }
        return stripos($e->getMessage(), 'SQLSTATE[') === false;
    }

    public static function message(Throwable $e, string $context = 'api'): string
    {
        if (self::isSafe($e)) return $e->getMessage();
        error_log($context . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return self::GENERIC;
    }
}
