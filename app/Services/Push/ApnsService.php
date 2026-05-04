<?php
declare(strict_types=1);

/**
 * app/Services/Push/ApnsService.php
 *
 * APNs HTTP/2 Provider API — sends push notifications directly to Apple's servers.
 *
 * Uses token-based authentication (JWT with ES256) rather than certificate-based
 * auth. Requires three constants defined in secrets.php:
 *   APNS_TEAM_ID    — 10-character Apple Developer Team ID   (e.g. "AB12CD34EF")
 *   APNS_KEY_ID     — 10-character Key ID from developer.apple.com (e.g. "ABCDE12345")
 *   APNS_PRIVATE_KEY — Full content of the .p8 file including -----BEGIN/END----- lines
 *
 * Add to secrets.php:
 *   define('APNS_TEAM_ID',     'your_team_id');
 *   define('APNS_KEY_ID',      'your_key_id');
 *   define('APNS_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----");
 *
 * ┌─ PRODUCTION SAFETY ──────────────────────────────────────────────────────┐
 * │  1. HTTP/2 ONLY. APNs no longer accepts HTTP/1.1. If curl on the host   │
 * │     lacks HTTP/2 support, all sends will fail. Check with:               │
 * │     curl --version | grep HTTP2                                           │
 * │     If not listed, contact CanadianWebHosting to enable nghttp2.         │
 * │                                                                           │
 * │  2. JWT CACHING. APNs JWTs are valid for 1 hour. The $jwtCache stores   │
 * │     the current JWT in memory for reuse within a single cron run.        │
 * │     Do NOT persist the JWT to the database — APNs rate-limits new JWT   │
 * │     generation to once per minute; multiple processes regenerating       │
 * │     simultaneously will trigger a JWT_PROVIDER_TOKEN_TOO_FREQUENT 429.  │
 * │                                                                           │
 * │  3. REMOVE 410 TOKENS. When APNs returns 410 (Unregistered), the device │
 * │     token is permanently invalid. The caller MUST deactivate it in       │
 * │     device_tokens. Keeping dead tokens wastes quota and can eventually   │
 * │     trigger APNs rate-limiting.                                           │
 * │                                                                           │
 * │  4. SANDBOX vs PRODUCTION. APNS_SANDBOX constant controls the endpoint.  │
 * │     Tokens registered via TestFlight or Xcode builds go to sandbox;     │
 * │     App Store builds go to production. Mixing them causes 400 errors.   │
 * └───────────────────────────────────────────────────────────────────────────┘
 */

class ApnsService
{
    // APNs endpoints
    private const ENDPOINT_PROD    = 'https://api.push.apple.com/3/device/';
    private const ENDPOINT_SANDBOX = 'https://api.sandbox.push.apple.com/3/device/';

    // JWT valid for 1 hour; cache it in memory for the cron run duration
    private static ?string $cachedJwt   = null;
    private static int     $jwtIssuedAt = 0;

    // MARK: - Public API

    /**
     * Send a push notification to a single device token.
     *
     * @param  string       $deviceToken  Hex APNs device token
     * @param  string       $title        Notification title
     * @param  string       $body         Notification body
     * @param  array        $data         Extra key-value pairs in the payload data dict
     * @param  int          $badge        App badge count (0 = clear)
     * @param  string|null  $apnsTopic    Override bundle ID (uses ca.mowology.crm-field if null)
     * @return array ['success'=>bool, 'http_code'=>int, 'error'=>string|null, 'unregistered'=>bool]
     */
    public static function send(
        string $deviceToken,
        string $title,
        string $body,
        array  $data        = [],
        int    $badge       = 1,
        ?string $apnsTopic  = null
    ): array {
        if (!self::isConfigured()) {
            return ['success' => false, 'http_code' => 0, 'error' => 'APNs not configured', 'unregistered' => false];
        }

        $jwt   = self::getOrCreateJwt();
        $topic = $apnsTopic ?? 'ca.mowology.crm-field';

        $payload = [
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'badge' => $badge,
                'sound' => 'default',
            ],
        ];
        if (!empty($data)) {
            $payload['data'] = $data;
        }

        $endpoint = defined('APNS_SANDBOX') && APNS_SANDBOX
            ? self::ENDPOINT_SANDBOX
            : self::ENDPOINT_PROD;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint . $deviceToken,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: bearer ' . $jwt,
                'apns-topic: '   . $topic,
                'apns-push-type: alert',
                'apns-priority: 10',
                'Content-Type: application/json',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlError) {
            error_log('[ApnsService] curl error: ' . $curlError);
            return ['success' => false, 'http_code' => 0, 'error' => $curlError, 'unregistered' => false];
        }

        $apnsError   = null;
        $unregistered = false;

        if ($httpCode !== 200) {
            $decoded = json_decode((string)$responseBody, true);
            $apnsError = $decoded['reason'] ?? "HTTP {$httpCode}";
            // 410 = device no longer registered — caller must deactivate the token
            $unregistered = ($httpCode === 410);
            error_log("[ApnsService] push failed [{$httpCode}] {$apnsError} for token " . substr($deviceToken, 0, 8) . '...');
        }

        return [
            'success'      => $httpCode === 200,
            'http_code'    => $httpCode,
            'error'        => $apnsError,
            'unregistered' => $unregistered,
        ];
    }

    // MARK: - Private: JWT Generation

    /**
     * Returns a cached JWT or generates a new one.
     * APNs JWTs are valid for 1 hour; we refresh at 50 minutes to avoid edge cases.
     */
    private static function getOrCreateJwt(): string
    {
        $now = time();
        if (self::$cachedJwt !== null && ($now - self::$jwtIssuedAt) < 3000) {
            return self::$cachedJwt;
        }

        self::$cachedJwt   = self::buildJwt($now);
        self::$jwtIssuedAt = $now;
        return self::$cachedJwt;
    }

    /**
     * Builds an APNs JWT signed with ES256.
     *
     * APNs JWT header: {"alg":"ES256","kid":"<key_id>"}
     * APNs JWT payload: {"iss":"<team_id>","iat":<timestamp>}
     *
     * The signature must be in IEEE P1363 format (raw R||S, 64 bytes),
     * NOT the DER format that openssl_sign() produces. This method converts.
     */
    private static function buildJwt(int $iat): string
    {
        $header  = self::base64url(json_encode(['alg' => 'ES256', 'kid' => APNS_KEY_ID]));
        $payload = self::base64url(json_encode(['iss' => APNS_TEAM_ID, 'iat' => $iat]));
        $message = $header . '.' . $payload;

        $privateKey = openssl_pkey_get_private(APNS_PRIVATE_KEY);
        openssl_sign($message, $derSig, $privateKey, OPENSSL_ALGO_SHA256);

        return $message . '.' . self::base64url(self::derToP1363($derSig));
    }

    /**
     * Converts a DER-encoded ECDSA signature to IEEE P1363 (raw 64-byte R||S).
     *
     * DER structure: 30 [len] 02 [r_len] [r_bytes] 02 [s_len] [s_bytes]
     * P1363 structure: [r, zero-padded to 32 bytes] || [s, zero-padded to 32 bytes]
     */
    private static function derToP1363(string $der): string
    {
        // Skip 30 [total_len] 02 [r_len]
        $offset = 3;
        $rLen   = ord($der[$offset++]);

        // Skip leading zero byte if r is padded to preserve sign bit
        if (ord($der[$offset]) === 0x00) { $offset++; $rLen--; }
        $r = str_pad(substr($der, $offset, $rLen), 32, "\x00", STR_PAD_LEFT);
        $offset += $rLen;

        // Skip 02 [s_len]
        $offset++;
        $sLen = ord($der[$offset++]);
        if (ord($der[$offset]) === 0x00) { $offset++; $sLen--; }
        $s = str_pad(substr($der, $offset, $sLen), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Returns true if all three required APNs constants are defined in secrets.php.
     * Logs a clear warning on first use if not configured.
     */
    public static function isConfigured(): bool
    {
        $ok = defined('APNS_TEAM_ID') && defined('APNS_KEY_ID') && defined('APNS_PRIVATE_KEY')
            && APNS_TEAM_ID !== '' && APNS_KEY_ID !== '' && APNS_PRIVATE_KEY !== '';

        if (!$ok) {
            static $warned = false;
            if (!$warned) {
                error_log('[ApnsService] Not configured. Add APNS_TEAM_ID, APNS_KEY_ID, APNS_PRIVATE_KEY to secrets.php');
                $warned = true;
            }
        }
        return $ok;
    }
}
