<?php
/**
 * TrackimoService — REST client for Trackimo GPS tracker API.
 *
 * Auth flow (headless server-side):
 *   1. POST /api/internal/v2/user/login with username/password → JSESSIONID cookie
 *   2. GET  /api/v3/oauth2/auth with cookie + follow_redirects=false
 *      → captures the 302 Location header, extracts ?code=XXX
 *   3. POST /api/v3/oauth2/token with the code + client_id/secret
 *      → returns access_token (+ optional refresh_token, expires_in)
 *
 * The redirect_uri registered with Trackimo is never actually navigated to;
 * we only read the code out of the 302 response headers.
 *
 * Access tokens are cached to STORAGE_ROOT/cache/trackimo_token.json so we
 * don't re-auth on every cron tick. On a 401 we drop the cache and retry once.
 *
 * Configured via secrets.php constants:
 *   TRACKIMO_CLIENT_ID, TRACKIMO_CLIENT_SECRET, TRACKIMO_REDIRECT_URI,
 *   TRACKIMO_USERNAME, TRACKIMO_PASSWORD
 *
 * Public surface used by callers:
 *   - authenticate()              → returns access_token (cached)
 *   - getAccountId()              → /api/v3/user
 *   - getDevices()                → /api/v4/accounts/{id}/descendants
 *   - getLastLocation($deviceId)  → /api/v3/accounts/{id}/locations/filter
 *   - forceLocationPush($id)      → /api/v3/accounts/{id}/devices/ops/getLocation
 *
 * Throws RuntimeException on auth or transport failure; callers catch and log.
 */
declare(strict_types=1);

class TrackimoService
{
    private string $apiBase = 'https://app.trackimo.com';
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $username;
    private string $password;
    private string $tokenCachePath;

    private ?array $token = null;
    private ?int $accountId = null;

    public function __construct(?string $tokenCachePath = null)
    {
        foreach (['TRACKIMO_CLIENT_ID', 'TRACKIMO_CLIENT_SECRET', 'TRACKIMO_REDIRECT_URI',
                  'TRACKIMO_USERNAME', 'TRACKIMO_PASSWORD'] as $const) {
            if (!defined($const) || constant($const) === '') {
                throw new RuntimeException("Missing required Trackimo constant: {$const}");
            }
        }
        $this->clientId       = TRACKIMO_CLIENT_ID;
        $this->clientSecret   = TRACKIMO_CLIENT_SECRET;
        $this->redirectUri    = TRACKIMO_REDIRECT_URI;
        $this->username       = TRACKIMO_USERNAME;
        $this->password       = TRACKIMO_PASSWORD;

        if ($tokenCachePath === null) {
            $base = defined('STORAGE_ROOT') ? STORAGE_ROOT : sys_get_temp_dir();
            $tokenCachePath = $base . '/cache/trackimo_token.json';
        }
        $this->tokenCachePath = $tokenCachePath;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  AUTH
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return a valid access token. Uses the on-disk cache when fresh, refreshes
     * via login when expired or missing.
     */
    public function authenticate(): string
    {
        if ($this->token !== null && $this->token['expires_at'] > time() + 60) {
            return $this->token['access_token'];
        }

        $cached = $this->loadCachedToken();
        if ($cached !== null && $cached['expires_at'] > time() + 60) {
            $this->token = $cached;
            return $cached['access_token'];
        }

        $fresh = $this->login();
        $this->saveCachedToken($fresh);
        $this->token = $fresh;
        return $fresh['access_token'];
    }

    /**
     * Drop the cached token (used after a 401 to force a fresh login on retry).
     */
    public function invalidateToken(): void
    {
        $this->token = null;
        if (is_file($this->tokenCachePath)) {
            @unlink($this->tokenCachePath);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  API CALLS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Get the authenticated user's account_id. Cached on the instance.
     */
    public function getAccountId(): int
    {
        if ($this->accountId !== null) return $this->accountId;

        $resp = $this->apiCall('GET', '/api/v3/user');
        if (empty($resp['account_id'])) {
            throw new RuntimeException('Trackimo /api/v3/user returned no account_id');
        }
        $this->accountId = (int)$resp['account_id'];
        return $this->accountId;
    }

    /**
     * List all devices on the account (including sub-accounts).
     * Returns an array of { device_id, nickname, settings_id, ... } rows.
     */
    public function getDevices(): array
    {
        $accountId = $this->getAccountId();
        $resp = $this->apiCall('GET', "/api/v4/accounts/{$accountId}/descendants");
        return $resp['devices'] ?? [];
    }

    /**
     * Get the most recent location for a device. Returns null if none reported.
     *
     * Response shape (normalized): [
     *   'lat' => float, 'lng' => float, 'speed_kph' => ?float,
     *   'heading' => ?int, 'battery_pct' => ?int, 'recorded_at' => 'Y-m-d H:i:s'
     * ]
     */
    public function getLastLocation(string $deviceId): ?array
    {
        $accountId = $this->getAccountId();
        $resp = $this->apiCall(
            'POST',
            "/api/v3/accounts/{$accountId}/locations/filter?limit=1",
            ['device_ids' => [(int)$deviceId]]
        );

        $list = is_array($resp) ? $resp : ($resp['locations'] ?? []);
        if (!$list) return null;

        $row = is_array($list[0] ?? null) ? $list[0] : null;
        if (!$row) return null;

        return $this->normalizeLocation($row);
    }

    /**
     * Ask the device to wake up and send a fresh location ping.
     * Useful when the last known position is stale. Fire-and-forget.
     */
    public function forceLocationPush(string $deviceId): void
    {
        $accountId = $this->getAccountId();
        $this->apiCall(
            'POST',
            "/api/v3/accounts/{$accountId}/devices/ops/getLocation",
            ['devices' => [(int)$deviceId], 'forceGpsRead' => true, 'sendGsmBeforeLock' => true]
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  INTERNALS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Issue an authenticated API call with one automatic retry on 401.
     */
    private function apiCall(string $method, string $path, array $body = []): array
    {
        $token = $this->authenticate();
        $resp = $this->httpRequest($method, $this->apiBase . $path, [
            'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            'json' => $method === 'GET' ? null : $body,
        ]);

        if ($resp['status'] === 401) {
            $this->invalidateToken();
            $token = $this->authenticate();
            $resp = $this->httpRequest($method, $this->apiBase . $path, [
                'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                'json' => $method === 'GET' ? null : $body,
            ]);
        }

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException(
                "Trackimo API {$method} {$path} failed: HTTP {$resp['status']}: " .
                substr((string)$resp['body'], 0, 300)
            );
        }
        return $resp['json'] ?? [];
    }

    /**
     * Three-step OAuth dance: login → auth (intercept 302) → token exchange.
     */
    private function login(): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'trackimo_');
        if ($cookieJar === false) {
            throw new RuntimeException('Failed to create cookie jar');
        }

        try {
            // Step 1: session login
            $loginResp = $this->httpRequest('POST', $this->apiBase . '/api/internal/v2/user/login', [
                'json' => ['username' => $this->username, 'password' => $this->password],
                'cookie_jar' => $cookieJar,
            ]);
            if ($loginResp['status'] !== 200) {
                throw new RuntimeException("Trackimo login failed: HTTP {$loginResp['status']}");
            }

            // Step 2: OAuth code request (intercept the 302)
            $authQuery = http_build_query([
                'client_id'     => $this->clientId,
                'redirect_uri'  => $this->redirectUri,
                'response_type' => 'code',
                'scope'         => 'locations,devices,accounts,geozones',
            ]);
            $authResp = $this->httpRequest('GET', $this->apiBase . '/api/v3/oauth2/auth?' . $authQuery, [
                'cookie_jar' => $cookieJar,
                'follow_redirects' => false,
            ]);
            if ($authResp['status'] !== 302) {
                throw new RuntimeException("Trackimo auth code request failed: HTTP {$authResp['status']}");
            }
            $location = $this->findHeader($authResp['headers'], 'location');
            if (!$location || !preg_match('/[?&]code=([^&]+)/', $location, $m)) {
                throw new RuntimeException('Trackimo did not return auth code');
            }
            $code = urldecode($m[1]);

            // Step 3: exchange code for tokens
            $tokenResp = $this->httpRequest('POST', $this->apiBase . '/api/v3/oauth2/token', [
                'json' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code'          => $code,
                ],
                'cookie_jar' => $cookieJar,
            ]);
            if ($tokenResp['status'] !== 200 || empty($tokenResp['json']['access_token'])) {
                throw new RuntimeException("Trackimo token exchange failed: HTTP {$tokenResp['status']}");
            }

            $expiresIn = (int)($tokenResp['json']['expires_in'] ?? 3600);
            return [
                'access_token'  => (string)$tokenResp['json']['access_token'],
                'refresh_token' => (string)($tokenResp['json']['refresh_token'] ?? ''),
                'expires_at'    => time() + max(60, $expiresIn),
            ];
        } finally {
            @unlink($cookieJar);
        }
    }

    private function loadCachedToken(): ?array
    {
        if (!is_file($this->tokenCachePath)) return null;
        $raw = @file_get_contents($this->tokenCachePath);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) {
            return null;
        }
        return $data;
    }

    private function saveCachedToken(array $token): void
    {
        $dir = dirname($this->tokenCachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $this->tokenCachePath . '.tmp';
        if (@file_put_contents($tmp, json_encode($token, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $this->tokenCachePath);
        }
    }

    private function findHeader(array $headers, string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string)$k) === $needle) return is_array($v) ? ($v[0] ?? null) : (string)$v;
        }
        return null;
    }

    /**
     * Normalize a Trackimo location row into the shape we persist.
     * Trackimo field names vary slightly across endpoints — handle both
     * lat/lng and latitude/longitude, and tolerate ms epoch timestamps.
     */
    private function normalizeLocation(array $row): array
    {
        $lat = $row['lat'] ?? $row['latitude'] ?? null;
        $lng = $row['lng'] ?? $row['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            throw new RuntimeException('Trackimo location row missing lat/lng');
        }

        // Trackimo emits epoch milliseconds in `updated` or ISO in `time`.
        $recordedAt = null;
        if (!empty($row['updated']) && is_numeric($row['updated'])) {
            $recordedAt = date('Y-m-d H:i:s', (int)($row['updated'] / 1000));
        } elseif (!empty($row['time'])) {
            $ts = strtotime((string)$row['time']);
            if ($ts !== false) $recordedAt = date('Y-m-d H:i:s', $ts);
        }
        if ($recordedAt === null) {
            $recordedAt = date('Y-m-d H:i:s');
        }

        return [
            'lat'         => (float)$lat,
            'lng'         => (float)$lng,
            'speed_kph'   => isset($row['speed']) ? (float)$row['speed'] : null,
            'heading'     => isset($row['course']) ? (int)$row['course']
                              : (isset($row['heading']) ? (int)$row['heading'] : null),
            'battery_pct' => isset($row['battery']) ? (int)$row['battery']
                              : (isset($row['batt']) ? (int)$row['batt'] : null),
            'recorded_at' => $recordedAt,
        ];
    }

    /**
     * Low-level cURL wrapper. Marked protected so tests can subclass and mock.
     *
     * $opts keys:
     *   headers          string[]   Extra request headers
     *   json             ?array     JSON body (also sets Content-Type if no headers given)
     *   cookie_jar       ?string    Path to a cookie jar file
     *   follow_redirects bool       Default true
     *
     * Returns: ['status' => int, 'headers' => array, 'body' => string, 'json' => ?array]
     */
    protected function httpRequest(string $method, string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('curl_init failed');

        $headers = $opts['headers'] ?? [];
        $body = null;
        if (isset($opts['json']) && $opts['json'] !== null) {
            $body = json_encode($opts['json']);
            if (!array_filter($headers, fn($h) => stripos((string)$h, 'content-type') === 0)) {
                $headers[] = 'Content-Type: application/json';
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => $opts['follow_redirects'] ?? true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ]);

        if (!empty($opts['cookie_jar'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['cookie_jar']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['cookie_jar']);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Trackimo HTTP error: {$err}");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = (string)substr((string)$raw, 0, $headerSize);
        $body = (string)substr((string)$raw, $headerSize);
        $parsedHeaders = $this->parseHeaders($rawHeaders);
        $json = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) $json = $decoded;
        }
        return ['status' => $status, 'headers' => $parsedHeaders, 'body' => $body, 'json' => $json];
    }

    private function parseHeaders(string $raw): array
    {
        $headers = [];
        // Use the LAST response block (in case redirects chain headers)
        $blocks = preg_split("/\r\n\r\n/", trim($raw)) ?: [];
        $last = end($blocks) ?: '';
        foreach (preg_split("/\r\n/", $last) ?: [] as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
        return $headers;
    }
}
