<?php
declare(strict_types=1);

/**
 * app/Services/Push/FcmService.php
 *
 * Firebase Cloud Messaging HTTP v1 API — sends push notifications to Android
 * devices. Mirrors ApnsService.php's structure/conventions (hand-rolled
 * curl + openssl, no external SDK — this codebase has no Google API client
 * or JWT library in composer.json).
 *
 * Requires two constants defined in secrets.php:
 *   FCM_SERVICE_ACCOUNT_JSON — full contents of a Firebase service-account
 *                              JSON key file (Project Settings → Service
 *                              accounts → Generate new private key)
 *   FCM_PROJECT_ID           — the Firebase project id (also present inside
 *                              the service-account JSON as "project_id", kept
 *                              as a separate constant so the send URL doesn't
 *                              need to re-parse the JSON on every call)
 *
 * Add to secrets.php:
 *   define('FCM_SERVICE_ACCOUNT_JSON', '{"type":"service_account", ...}');
 *   define('FCM_PROJECT_ID', 'your-firebase-project-id');
 *
 * ┌─ PRODUCTION SAFETY ──────────────────────────────────────────────────────┐
 * │  1. ACCESS TOKEN CACHING. Google OAuth2 access tokens are valid ~1 hour. │
 * │     $cachedAccessToken stores the current token in memory for reuse      │
 * │     within a single cron run — do NOT persist to the database.           │
 * │                                                                           │
 * │  2. REMOVE UNREGISTERED TOKENS. When FCM returns UNREGISTERED/NOT_FOUND, │
 * │     the device token is permanently invalid. The caller MUST deactivate  │
 * │     it in device_tokens (same contract as ApnsService's 410 handling).   │
 * │                                                                           │
 * │  3. FCM v1's "data" payload values MUST all be strings — unlike APNs,    │
 * │     the API rejects non-string values outright. This service stringifies│
 * │     every value before sending.                                          │
 * └───────────────────────────────────────────────────────────────────────────┘
 */

class FcmService
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const SEND_ENDPOINT  = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const SCOPE          = 'https://www.googleapis.com/auth/firebase.messaging';

    // Access token valid for ~1 hour; cache it in memory for the cron run duration
    private static ?string $cachedAccessToken = null;
    private static int     $tokenIssuedAt     = 0;

    // MARK: - Public API

    /**
     * Send a push notification to a single device token.
     *
     * @param  string $deviceToken  FCM registration token
     * @param  string $title        Notification title
     * @param  string $body         Notification body
     * @param  array  $data         Extra key-value pairs in the payload data dict — all
     *                              values are stringified, FCM v1 rejects non-string data.
     * @param  int    $badge        App badge count (Android honors this via notification
     *                              channel config, not a wire-level field like APNs)
     * @return array ['success'=>bool, 'http_code'=>int, 'error'=>string|null, 'unregistered'=>bool]
     */
    public static function send(
        string $deviceToken,
        string $title,
        string $body,
        array  $data  = [],
        int    $badge = 1
    ): array {
        if (!self::isConfigured()) {
            return ['success' => false, 'http_code' => 0, 'error' => 'FCM not configured', 'unregistered' => false];
        }

        $accessToken = self::getOrCreateAccessToken();
        if ($accessToken === null) {
            return ['success' => false, 'http_code' => 0, 'error' => 'FCM OAuth2 token exchange failed', 'unregistered' => false];
        }

        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
        }

        $message = [
            'message' => [
                'token'        => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                'android'      => ['priority' => 'high'],
            ],
        ];
        if (!empty($stringData)) {
            $message['message']['data'] = $stringData;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => sprintf(self::SEND_ENDPOINT, FCM_PROJECT_ID),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($message),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlError) {
            error_log('[FcmService] curl error: ' . $curlError);
            return ['success' => false, 'http_code' => 0, 'error' => $curlError, 'unregistered' => false];
        }

        $fcmError     = null;
        $unregistered = false;

        if ($httpCode !== 200) {
            $decoded  = json_decode((string)$responseBody, true);
            $status   = $decoded['error']['status'] ?? null;
            $errCode  = self::extractFcmErrorCode($decoded);
            $fcmError = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            // FCM's device-gone signal — caller must deactivate the token, same
            // contract as ApnsService's 410 handling.
            $unregistered = ($status === 'NOT_FOUND' || $errCode === 'UNREGISTERED');
            error_log("[FcmService] push failed [{$httpCode}] {$fcmError} for token " . substr($deviceToken, 0, 12) . '...');
        }

        return [
            'success'      => $httpCode === 200,
            'http_code'    => $httpCode,
            'error'        => $fcmError,
            'unregistered' => $unregistered,
        ];
    }

    // MARK: - Private: OAuth2 access token

    /**
     * Returns a cached access token or exchanges a fresh service-account JWT for one.
     * Google access tokens are valid for 1 hour; we refresh at 55 minutes.
     */
    private static function getOrCreateAccessToken(): ?string
    {
        $now = time();
        if (self::$cachedAccessToken !== null && ($now - self::$tokenIssuedAt) < 3300) {
            return self::$cachedAccessToken;
        }

        $token = self::exchangeJwtForAccessToken($now);
        if ($token === null) {
            return null;
        }

        self::$cachedAccessToken = $token;
        self::$tokenIssuedAt     = $now;
        return $token;
    }

    /**
     * Builds a service-account JWT (RS256) and exchanges it for an OAuth2
     * access token via Google's token endpoint.
     */
    private static function exchangeJwtForAccessToken(int $iat): ?string
    {
        $account = json_decode(FCM_SERVICE_ACCOUNT_JSON, true);
        if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            error_log('[FcmService] FCM_SERVICE_ACCOUNT_JSON is not valid service-account JSON');
            return null;
        }

        $header  = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::base64url(json_encode([
            'iss'   => $account['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $iat,
            'exp'   => $iat + 3600,
        ]));
        $message = $header . '.' . $payload;

        $privateKey = openssl_pkey_get_private($account['private_key']);
        if ($privateKey === false) {
            error_log('[FcmService] Failed to load private_key from service-account JSON');
            return null;
        }
        openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $assertion = $message . '.' . self::base64url($signature);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::TOKEN_ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            error_log('[FcmService] OAuth2 token exchange failed: ' . ($curlError ?: "HTTP {$httpCode}: {$responseBody}"));
            return null;
        }

        $decoded = json_decode((string)$responseBody, true);
        return $decoded['access_token'] ?? null;
    }

    /**
     * Pulls the FCM-specific error code (e.g. "UNREGISTERED") out of the
     * error.details[] array in an FCM v1 error response, if present.
     */
    private static function extractFcmErrorCode(?array $decoded): ?string
    {
        $details = $decoded['error']['details'] ?? [];
        foreach ($details as $detail) {
            if (!empty($detail['errorCode'])) {
                return $detail['errorCode'];
            }
        }
        return null;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Returns true if both required FCM constants are defined in secrets.php.
     * Logs a clear warning on first use if not configured.
     */
    public static function isConfigured(): bool
    {
        $ok = defined('FCM_SERVICE_ACCOUNT_JSON') && defined('FCM_PROJECT_ID')
            && FCM_SERVICE_ACCOUNT_JSON !== '' && FCM_PROJECT_ID !== '';

        if (!$ok) {
            static $warned = false;
            if (!$warned) {
                error_log('[FcmService] Not configured. Add FCM_SERVICE_ACCOUNT_JSON, FCM_PROJECT_ID to secrets.php');
                $warned = true;
            }
        }
        return $ok;
    }
}
