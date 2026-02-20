<?php
/**
 * reCAPTCHA Verification Helpers
 *
 * Provides v3 (invisible) and v2 (checkbox fallback) verification.
 * Keys are loaded from secrets.php via the config bootstrap.
 *
 * Flow:
 * 1. Frontend loads reCAPTCHA v3 JS and executes on form submit.
 * 2. Backend verifies v3 token and checks the score.
 * 3. If score >= threshold -> accept submission.
 * 4. If score < threshold  -> respond with "needs_v2_challenge".
 * 5. Frontend shows v2 checkbox; user completes it and resubmits.
 * 6. Backend verifies v2 token -> accept or reject.
 */

// Score threshold for v3 — scores below this trigger v2 fallback.
// Range: 0.0 (likely bot) to 1.0 (likely human). 0.5 is Google's recommended default.
if (!defined('RECAPTCHA_V3_SCORE_THRESHOLD')) {
    define('RECAPTCHA_V3_SCORE_THRESHOLD', 0.5);
}

/**
 * Verify a reCAPTCHA token (v2 or v3) with Google's siteverify endpoint.
 *
 * @param string      $token     The g-recaptcha-response token from the client.
 * @param string      $secretKey The secret key (v2 or v3) to use for verification.
 * @param string|null $remoteIp  Optional client IP for additional validation.
 * @return array      Full decoded response from Google (includes 'success', 'score', 'action', etc.)
 *                    Returns ['success' => false, 'error' => '...'] on network/parse failure.
 */
function verify_recaptcha(string $token, string $secretKey, ?string $remoteIp = null): array {
    if ($token === '' || $secretKey === '') {
        return ['success' => false, 'error' => 'empty_token_or_key'];
    }

    $endpoint = 'https://www.google.com/recaptcha/api/siteverify';
    $postData = [
        'secret'   => $secretKey,
        'response' => $token,
    ];
    if ($remoteIp !== null && $remoteIp !== '') {
        $postData['remoteip'] = $remoteIp;
    }
    $postFields = http_build_query($postData);

    // Prefer cURL, fall back to stream context
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("reCAPTCHA verify cURL error: " . $curlError);
            return ['success' => false, 'error' => 'curl_failure'];
        }
    } else {
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'timeout' => 8,
            ],
        ];
        $context  = stream_context_create($opts);
        $response = @file_get_contents($endpoint, false, $context);

        if ($response === false) {
            error_log("reCAPTCHA verify stream error");
            return ['success' => false, 'error' => 'stream_failure'];
        }
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        error_log("reCAPTCHA verify: invalid JSON response");
        return ['success' => false, 'error' => 'invalid_json'];
    }

    return $json;
}

/**
 * Verify a reCAPTCHA v3 token. Checks success, action, and score.
 *
 * @param string      $token          The recaptcha_v3_token from the client.
 * @param string      $expectedAction The expected action string (e.g., 'quote_submit').
 * @param string|null $remoteIp       Optional client IP.
 * @return array ['passed' => bool, 'score' => float|null, 'needs_v2' => bool, 'error' => string|null]
 */
function verify_recaptcha_v3(string $token, string $expectedAction, ?string $remoteIp = null): array {
    if (!defined('RECAPTCHA_V3_SECRET_KEY') || RECAPTCHA_V3_SECRET_KEY === '' || RECAPTCHA_V3_SECRET_KEY === 'REPLACE_WITH_RECAPTCHA_V3_SECRET_KEY') {
        // v3 not configured — fall through to v2
        error_log("reCAPTCHA v3: secret key not configured, falling back to v2");
        return ['passed' => false, 'score' => null, 'needs_v2' => true, 'error' => 'v3_not_configured'];
    }

    $result = verify_recaptcha($token, RECAPTCHA_V3_SECRET_KEY, $remoteIp);

    // Log for diagnostics (no PII — only score, action, success status)
    error_log(sprintf(
        "reCAPTCHA v3: success=%s, score=%s, action=%s, expected_action=%s",
        var_export($result['success'] ?? false, true),
        $result['score'] ?? 'null',
        $result['action'] ?? 'null',
        $expectedAction
    ));

    if (empty($result['success'])) {
        // Verification failed entirely — require v2 challenge (fail closed)
        return ['passed' => false, 'score' => null, 'needs_v2' => true, 'error' => 'v3_verification_failed'];
    }

    // Validate action matches to prevent token reuse across forms
    if (($result['action'] ?? '') !== $expectedAction) {
        error_log("reCAPTCHA v3: action mismatch");
        return ['passed' => false, 'score' => $result['score'] ?? null, 'needs_v2' => true, 'error' => 'action_mismatch'];
    }

    $score = (float)($result['score'] ?? 0.0);

    if ($score >= RECAPTCHA_V3_SCORE_THRESHOLD) {
        // High confidence human — accept
        return ['passed' => true, 'score' => $score, 'needs_v2' => false, 'error' => null];
    }

    // Low score — require v2 checkbox challenge
    return ['passed' => false, 'score' => $score, 'needs_v2' => true, 'error' => null];
}

/**
 * Verify a reCAPTCHA v2 checkbox token.
 *
 * @param string      $token    The g-recaptcha-response from the v2 checkbox.
 * @param string|null $remoteIp Optional client IP.
 * @return bool       True if verification succeeded.
 */
function verify_recaptcha_v2_token(string $token, ?string $remoteIp = null): bool {
    if (!defined('RECAPTCHA_V2_SECRET_KEY') || RECAPTCHA_V2_SECRET_KEY === '') {
        error_log("reCAPTCHA v2: secret key not configured");
        return false;
    }

    $result = verify_recaptcha($token, RECAPTCHA_V2_SECRET_KEY, $remoteIp);

    error_log(sprintf(
        "reCAPTCHA v2: success=%s",
        var_export($result['success'] ?? false, true)
    ));

    return !empty($result['success']);
}

/**
 * Check whether reCAPTCHA v3 is properly configured (site key is a real key, not placeholder).
 *
 * @return bool True if v3 keys appear to be configured.
 */
function is_recaptcha_v3_configured(): bool {
    return defined('RECAPTCHA_V3_SITE_KEY')
        && RECAPTCHA_V3_SITE_KEY !== ''
        && RECAPTCHA_V3_SITE_KEY !== 'REPLACE_WITH_RECAPTCHA_V3_SITE_KEY'
        && defined('RECAPTCHA_V3_SECRET_KEY')
        && RECAPTCHA_V3_SECRET_KEY !== ''
        && RECAPTCHA_V3_SECRET_KEY !== 'REPLACE_WITH_RECAPTCHA_V3_SECRET_KEY';
}

/**
 * Write a reCAPTCHA security event to activity_log.
 *
 * Called when a verification attempt fails (bad token format, low score,
 * network error, action mismatch, rate limit hit). Never throws — audit
 * failure must not break the user flow.
 *
 * @param string      $action  Event label, e.g. 'recaptcha_failed'
 * @param array       $details Structured context (no PII — scores, error codes, action names only)
 * @param string|null $ip      Client IP address
 */
function recaptcha_audit_log(string $action, array $details, ?string $ip): void
{
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO activity_log (user_id, action, details, ip_address)
             VALUES (NULL, ?, ?, ?)"
        )->execute([
            $action,
            json_encode($details, JSON_UNESCAPED_SLASHES),
            $ip,
        ]);
    } catch (\Throwable $e) {
        error_log('[recaptcha_audit_log] DB write error: ' . $e->getMessage());
    }
}

/**
 * Verify a reCAPTCHA v3 token with full audit logging on failure.
 *
 * Drop-in replacement for verify_recaptcha_v3() that also writes failed
 * attempts to activity_log. Identical return shape.
 *
 * @param string      $token          The recaptcha_v3_token from the client.
 * @param string      $expectedAction Expected action string (e.g. 'quote_submit').
 * @param string|null $remoteIp       Optional client IP.
 * @return array ['passed' => bool, 'score' => float|null, 'needs_v2' => bool, 'error' => string|null]
 */
function verify_recaptcha_v3_audited(string $token, string $expectedAction, ?string $remoteIp = null): array
{
    $result = verify_recaptcha_v3($token, $expectedAction, $remoteIp);

    if (!$result['passed']) {
        recaptcha_audit_log('recaptcha_v3_' . ($result['error'] ?? 'failed'), [
            'action'    => $expectedAction,
            'score'     => $result['score'],
            'needs_v2'  => $result['needs_v2'],
            'error'     => $result['error'],
        ], $remoteIp);
    }

    return $result;
}

/**
 * Verify a reCAPTCHA v2 checkbox token with audit logging on failure.
 *
 * Drop-in replacement for verify_recaptcha_v2_token() with audit logging.
 *
 * @param string      $token    The g-recaptcha-response from the v2 checkbox.
 * @param string|null $remoteIp Optional client IP.
 * @return bool True if verification succeeded.
 */
function verify_recaptcha_v2_audited(string $token, ?string $remoteIp = null): bool
{
    $passed = verify_recaptcha_v2_token($token, $remoteIp);

    if (!$passed) {
        recaptcha_audit_log('recaptcha_v2_failed', [
            'token_length' => strlen($token),
        ], $remoteIp);
    }

    return $passed;
}
