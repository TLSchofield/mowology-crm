<?php
/**
 * QboOAuth — Intuit's OAuth 2.0 for QuickBooks Online: the sign-in link, the code exchange, the refresh
 * and the revoke. Pure builders and parsers are static and unit tested; HTTP goes through a callable so
 * tests never call Intuit.
 *
 * Facts this rests on (Intuit docs, checked 2026-10-20):
 *   - scope com.intuit.quickbooks.accounting; access tokens last an hour (expires_in);
 *   - refresh tokens renew on use and have a 5-year maximum; each refresh may return a NEW refresh
 *     token, which must replace the old one (x_refresh_token_expires_in says how long it lasts);
 *   - the sign-in returns ?code=…&state=…&realmId=… to the redirect URI, which must match the app's
 *     setting exactly.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class QboOAuth
{
    public const AUTH_URL   = 'https://appcenter.intuit.com/connect/oauth2';
    public const TOKEN_URL  = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    public const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
    public const SCOPE      = 'com.intuit.quickbooks.accounting';

    private string $clientId;
    private string $clientSecret;
    /** @var callable(string $url, array $headers, string $body): array{status:int, body:string} */
    private $http;

    public function __construct(string $clientId, string $clientSecret, ?callable $http = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->http = $http ?? [self::class, 'curlPost'];
    }

    /** Pure (tested). */
    public static function authUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Pure (tested): Intuit's token JSON → what we store. Null when it isn't a token answer.
     * @return array{access:string, refresh:string, access_expires_at:string, refresh_expires_at:?string}|null
     */
    public static function parseTokens(string $json, int $now): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || empty($d['access_token']) || empty($d['refresh_token'])) return null;
        $in = (int)($d['expires_in'] ?? 3600);
        // A minute's margin, so a token is never used in its last seconds.
        $accessAt = date('Y-m-d H:i:s', $now + max(60, $in) - 60);
        $refreshIn = (int)($d['x_refresh_token_expires_in'] ?? 0);
        return [
            'access'             => (string)$d['access_token'],
            'refresh'            => (string)$d['refresh_token'],
            'access_expires_at'  => $accessAt,
            'refresh_expires_at' => $refreshIn > 0 ? date('Y-m-d H:i:s', $now + $refreshIn) : null,
        ];
    }

    /** Pure (tested): the error Intuit gave, in a line a person can act on. */
    public static function errorLine(int $status, string $body): string
    {
        $d = json_decode($body, true);
        $code = is_array($d) ? (string)($d['error'] ?? '') : '';
        if ($code === 'invalid_grant') return 'QuickBooks says the sign-in has expired or was revoked. Connect again.';
        if ($code === 'invalid_client') return 'QuickBooks did not accept the app keys (QBO_CLIENT_ID / QBO_CLIENT_SECRET). Check secrets.php.';
        if ($status === 0) return 'QuickBooks could not be reached. Try again in a minute.';
        return 'QuickBooks refused the request (' . $status . ($code !== '' ? ', ' . $code : '') . ').';
    }

    /** @return array{ok:bool, tokens?:array, error?:string} */
    public function exchangeCode(string $code, string $redirectUri, int $now): array
    {
        return $this->tokenCall(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri], $now);
    }

    /** @return array{ok:bool, tokens?:array, error?:string} */
    public function refresh(string $refreshToken, int $now): array
    {
        return $this->tokenCall(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $now);
    }

    public function revoke(string $token): bool
    {
        $r = ($this->http)(self::REVOKE_URL, $this->headers('application/json'), (string)json_encode(['token' => $token]));
        return $r['status'] === 200;
    }

    private function tokenCall(array $form, int $now): array
    {
        $r = ($this->http)(self::TOKEN_URL, $this->headers('application/x-www-form-urlencoded'), http_build_query($form));
        $t = $r['status'] === 200 ? self::parseTokens($r['body'], $now) : null;
        return $t ? ['ok' => true, 'tokens' => $t] : ['ok' => false, 'error' => self::errorLine($r['status'], $r['body'])];
    }

    private function headers(string $type): array
    {
        return [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Accept: application/json',
            'Content-Type: ' . $type,
        ];
    }

    /** @return array{status:int, body:string} */
    public static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $out === false ? '' : (string)$out];
    }
}
