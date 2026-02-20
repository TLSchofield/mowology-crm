<?php
declare(strict_types=1);

/**
 * /app/security/recaptcha_verify.php
 *
 * Standalone reCAPTCHA v3/v2 verification service.
 *
 * Responsibilities:
 *  - Rate-limit verification attempts per IP (DB-backed, cPanel-safe — no APCu/Redis needed)
 *  - Verify v3 token against Google siteverify with action + score check
 *  - Verify v2 token as checkbox fallback
 *  - Log every failure to activity_log for audit trail
 *  - Fail closed: any network/parse error → reject
 *
 * Usage (from any POST handler that already loaded config.php):
 *
 *   require_once APP_ROOT . '/security/recaptcha_verify.php';
 *
 *   $result = RecaptchaVerify::checkV3(
 *       token:          $_POST['recaptcha_v3_token'] ?? '',
 *       expectedAction: 'quote_submit',
 *       ip:             $_SERVER['REMOTE_ADDR'] ?? null
 *   );
 *   if (!$result->passed) {
 *       if ($result->needsV2) { // show v2 checkbox }
 *       // else: reject silently
 *   }
 *
 *   $result = RecaptchaVerify::checkV2(
 *       token: $_POST['recaptcha_v2_token'] ?? '',
 *       ip:    $_SERVER['REMOTE_ADDR'] ?? null
 *   );
 *   if (!$result->passed) { ... reject ... }
 *
 * Rate-limit table (auto-created on first use):
 *   recaptcha_rate_limit (ip VARCHAR(45), window_start DATETIME, attempt_count INT)
 *
 * Requires: getDB() and logActivityExtended() loaded via config.php / CrmFunctions.php
 */

// ── Score threshold ────────────────────────────────────────────────────────
if (!defined('RECAPTCHA_V3_SCORE_THRESHOLD')) {
    define('RECAPTCHA_V3_SCORE_THRESHOLD', 0.5);
}

// ── Rate limit constants ───────────────────────────────────────────────────
// Max verification attempts per IP within the window (covers both v2 and v3).
// A genuine user submitting a form once triggers 1 attempt. 20/15min is generous
// for legitimate users and tight enough to slow credential-stuffing bots.
define('RECAPTCHA_RATE_LIMIT_MAX',     20);   // max attempts
define('RECAPTCHA_RATE_LIMIT_WINDOW',  900);  // seconds (15 minutes)


// ── Result value object ────────────────────────────────────────────────────

final class RecaptchaResult
{
    public function __construct(
        public readonly bool    $passed,
        public readonly bool    $needsV2,
        public readonly ?float  $score,
        public readonly ?string $error,
        public readonly bool    $rateLimited = false
    ) {}
}


// ── Service ────────────────────────────────────────────────────────────────

final class RecaptchaVerify
{
    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Verify a reCAPTCHA v3 token.
     *
     * @param string      $token          Token from grecaptcha.execute()
     * @param string      $expectedAction Action string used in JS (e.g. 'quote_submit')
     * @param string|null $ip             Client IP for rate limiting + Google's extra signal
     * @return RecaptchaResult
     */
    public static function checkV3(
        string  $token,
        string  $expectedAction,
        ?string $ip = null
    ): RecaptchaResult {
        // 1. Rate limit check
        if (!self::allowRequest($ip)) {
            self::auditLog('recaptcha_rate_limited', null, [
                'type'   => 'v3',
                'action' => $expectedAction,
            ], $ip);
            return new RecaptchaResult(
                passed:      false,
                needsV2:     false,
                score:       null,
                error:       'rate_limited',
                rateLimited: true
            );
        }

        // 2. Token format sanity (avoid a pointless round-trip to Google)
        if (!self::isValidTokenFormat($token)) {
            self::auditLog('recaptcha_invalid_token', null, [
                'type'   => 'v3',
                'action' => $expectedAction,
                'reason' => 'bad_format',
            ], $ip);
            // Treat as suspicious but give v2 chance rather than hard-blocking
            return new RecaptchaResult(
                passed:  false,
                needsV2: true,
                score:   null,
                error:   'invalid_token_format'
            );
        }

        // 3. Key configured?
        if (!self::v3Configured()) {
            error_log('[RecaptchaVerify] v3 secret key not configured — falling back to v2');
            return new RecaptchaResult(
                passed:  false,
                needsV2: true,
                score:   null,
                error:   'v3_not_configured'
            );
        }

        // 4. Verify with Google
        $raw = self::callSiteverify($token, RECAPTCHA_V3_SECRET_KEY, $ip);

        if ($raw === null) {
            // Network/parse failure — fail closed
            self::auditLog('recaptcha_verify_error', null, [
                'type'   => 'v3',
                'action' => $expectedAction,
                'reason' => 'network_failure',
            ], $ip);
            return new RecaptchaResult(
                passed:  false,
                needsV2: false,
                score:   null,
                error:   'network_failure'
            );
        }

        // 5. Check success flag
        if (empty($raw['success'])) {
            $codes = implode(',', $raw['error-codes'] ?? []);
            self::auditLog('recaptcha_failed', null, [
                'type'        => 'v3',
                'action'      => $expectedAction,
                'error_codes' => $codes,
            ], $ip);
            return new RecaptchaResult(
                passed:  false,
                needsV2: true,
                score:   null,
                error:   "v3_failed:{$codes}"
            );
        }

        // 6. Action mismatch — possible token reuse across forms
        $returnedAction = $raw['action'] ?? '';
        if ($returnedAction !== $expectedAction) {
            self::auditLog('recaptcha_action_mismatch', null, [
                'type'            => 'v3',
                'expected_action' => $expectedAction,
                'returned_action' => $returnedAction,
            ], $ip);
            return new RecaptchaResult(
                passed:  false,
                needsV2: true,
                score:   (float)($raw['score'] ?? 0.0),
                error:   'action_mismatch'
            );
        }

        // 7. Score check
        $score = (float)($raw['score'] ?? 0.0);

        error_log(sprintf(
            '[RecaptchaVerify] v3 result: success=true, action=%s, score=%.2f',
            $expectedAction, $score
        ));

        if ($score >= RECAPTCHA_V3_SCORE_THRESHOLD) {
            return new RecaptchaResult(
                passed:  true,
                needsV2: false,
                score:   $score,
                error:   null
            );
        }

        // Score below threshold — escalate to v2 challenge
        self::auditLog('recaptcha_low_score', null, [
            'type'   => 'v3',
            'action' => $expectedAction,
            'score'  => $score,
        ], $ip);

        return new RecaptchaResult(
            passed:  false,
            needsV2: true,
            score:   $score,
            error:   'low_score'
        );
    }

    /**
     * Verify a reCAPTCHA v2 checkbox token.
     *
     * @param string      $token Token from g-recaptcha-response
     * @param string|null $ip    Client IP
     * @return RecaptchaResult
     */
    public static function checkV2(
        string  $token,
        ?string $ip = null
    ): RecaptchaResult {
        // 1. Rate limit (shared window with v3)
        if (!self::allowRequest($ip)) {
            self::auditLog('recaptcha_rate_limited', null, [
                'type' => 'v2',
            ], $ip);
            return new RecaptchaResult(
                passed:      false,
                needsV2:     false,
                score:       null,
                error:       'rate_limited',
                rateLimited: true
            );
        }

        // 2. Token format
        if (!self::isValidTokenFormat($token)) {
            self::auditLog('recaptcha_invalid_token', null, [
                'type'   => 'v2',
                'reason' => 'bad_format',
            ], $ip);
            return new RecaptchaResult(
                passed:  false,
                needsV2: false,
                score:   null,
                error:   'invalid_token_format'
            );
        }

        // 3. Key configured?
        if (!defined('RECAPTCHA_V2_SECRET_KEY') || RECAPTCHA_V2_SECRET_KEY === '') {
            error_log('[RecaptchaVerify] v2 secret key not configured');
            return new RecaptchaResult(
                passed:  false,
                needsV2: false,
                score:   null,
                error:   'v2_not_configured'
            );
        }

        // 4. Verify with Google
        $raw = self::callSiteverify($token, RECAPTCHA_V2_SECRET_KEY, $ip);

        if ($raw === null) {
            self::auditLog('recaptcha_verify_error', null, [
                'type'   => 'v2',
                'reason' => 'network_failure',
            ], $ip);
            return new RecaptchaResult(
                passed:  false,
                needsV2: false,
                score:   null,
                error:   'network_failure'
            );
        }

        $passed = !empty($raw['success']);

        if (!$passed) {
            $codes = implode(',', $raw['error-codes'] ?? []);
            self::auditLog('recaptcha_failed', null, [
                'type'        => 'v2',
                'error_codes' => $codes,
            ], $ip);
        }

        error_log(sprintf('[RecaptchaVerify] v2 result: success=%s', $passed ? 'true' : 'false'));

        return new RecaptchaResult(
            passed:  $passed,
            needsV2: false,
            score:   null,
            error:   $passed ? null : 'v2_failed'
        );
    }


    // ── Rate limiting ──────────────────────────────────────────────────────

    /**
     * Check and record a verification attempt for the given IP.
     * Returns true if the request is within limits, false if rate-limited.
     *
     * Uses a DB table (recaptcha_rate_limit) as a sliding-window counter.
     * cPanel shared hosting has no APCu/Redis, so DB is the pragmatic choice.
     * The table is tiny and rows are auto-pruned on each check.
     */
    private static function allowRequest(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            // No IP to rate-limit — allow but log
            error_log('[RecaptchaVerify] no IP address available for rate limiting');
            return true;
        }

        try {
            $db = getDB();
            self::ensureRateLimitTable($db);

            $windowStart = date('Y-m-d H:i:s', time() - RECAPTCHA_RATE_LIMIT_WINDOW);

            // Prune stale rows first (keep table small)
            $db->prepare("DELETE FROM recaptcha_rate_limit WHERE window_start < ?")
               ->execute([$windowStart]);

            // Get current count for this IP within the window
            $stmt = $db->prepare(
                "SELECT attempt_count FROM recaptcha_rate_limit
                  WHERE ip = ? AND window_start >= ?
                  LIMIT 1"
            );
            $stmt->execute([$ip, $windowStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                // First attempt in this window — insert
                $db->prepare(
                    "INSERT INTO recaptcha_rate_limit (ip, window_start, attempt_count)
                     VALUES (?, NOW(), 1)"
                )->execute([$ip]);
                return true;
            }

            $count = (int)$row['attempt_count'];

            if ($count >= RECAPTCHA_RATE_LIMIT_MAX) {
                error_log(sprintf(
                    '[RecaptchaVerify] rate limit exceeded for IP %s (%d attempts)',
                    $ip, $count
                ));
                return false;
            }

            // Increment counter
            $db->prepare(
                "UPDATE recaptcha_rate_limit SET attempt_count = attempt_count + 1
                  WHERE ip = ? AND window_start >= ?"
            )->execute([$ip, $windowStart]);

            return true;

        } catch (PDOException $e) {
            // DB error — fail open (don't block real users due to a DB hiccup)
            error_log('[RecaptchaVerify] rate limit DB error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Create the rate limit table if it doesn't exist.
     * Called once per request (cheap — CREATE TABLE IF NOT EXISTS hits cache).
     */
    private static function ensureRateLimitTable(\PDO $db): void
    {
        // Run outside any active transaction — DDL auto-commits in MySQL
        $db->exec("
            CREATE TABLE IF NOT EXISTS recaptcha_rate_limit (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip            VARCHAR(45)  NOT NULL,
                window_start  DATETIME     NOT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
                INDEX idx_ip_window (ip, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }


    // ── Audit logging ──────────────────────────────────────────────────────

    /**
     * Write a security event to the activity_log table.
     * Silently swallows errors — audit failure must never break the user flow.
     *
     * @param string      $action  Action label (e.g. 'recaptcha_failed')
     * @param int|null    $userId  Always null for public form events
     * @param array       $details Structured data serialised as JSON
     * @param string|null $ip      Client IP
     */
    private static function auditLog(
        string  $action,
        ?int    $userId,
        array   $details,
        ?string $ip
    ): void {
        try {
            $db = getDB();
            $db->prepare(
                "INSERT INTO activity_log (user_id, action, details, ip_address)
                 VALUES (?, ?, ?, ?)"
            )->execute([
                $userId,
                $action,
                json_encode($details, JSON_UNESCAPED_SLASHES),
                $ip,
            ]);
        } catch (\Throwable $e) {
            error_log('[RecaptchaVerify] audit log error: ' . $e->getMessage());
        }
    }


    // ── Google siteverify call ─────────────────────────────────────────────

    /**
     * POST to Google siteverify and return the decoded JSON array.
     * Returns null on any network or parse failure (caller must treat as rejection).
     */
    private static function callSiteverify(
        string  $token,
        string  $secretKey,
        ?string $ip
    ): ?array {
        $postData = ['secret' => $secretKey, 'response' => $token];
        if ($ip !== null && $ip !== '') {
            $postData['remoteip'] = $ip;
        }
        $postFields = http_build_query($postData);

        if (function_exists('curl_init')) {
            $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mowology-CRM/1.0',
            ]);
            $response  = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                error_log(sprintf(
                    '[RecaptchaVerify] cURL error: %s (HTTP %d)',
                    $curlError, $httpCode
                ));
                return null;
            }
        } else {
            // Fallback: stream context (no cURL on some hosts)
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postFields,
                    'timeout' => 8,
                ],
            ]);
            $response = @file_get_contents(
                'https://www.google.com/recaptcha/api/siteverify',
                false,
                $ctx
            );
            if ($response === false) {
                error_log('[RecaptchaVerify] stream context error');
                return null;
            }
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            error_log('[RecaptchaVerify] invalid JSON from siteverify: ' . substr($response, 0, 200));
            return null;
        }

        return $json;
    }


    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Sanity-check token format before wasting a round-trip to Google.
     * reCAPTCHA tokens are base64url strings, typically 400-2000 chars.
     */
    private static function isValidTokenFormat(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $len = strlen($token);
        if ($len < 20 || $len > 4096) {
            return false;
        }
        // Only base64url characters plus padding
        return (bool)preg_match('/^[A-Za-z0-9\-_+\/=]+$/', $token);
    }

    private static function v3Configured(): bool
    {
        return defined('RECAPTCHA_V3_SECRET_KEY')
            && RECAPTCHA_V3_SECRET_KEY !== ''
            && RECAPTCHA_V3_SECRET_KEY !== 'REPLACE_WITH_RECAPTCHA_V3_SECRET_KEY';
    }
}
