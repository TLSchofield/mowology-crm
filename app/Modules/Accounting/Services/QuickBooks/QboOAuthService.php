<?php
/**
 * QboOAuthService — the OAuth 2.0 dance with Intuit, nothing else (2026-10-07).
 *
 * Facts this code rests on (developer.intuit.com, "Set up OAuth 2.0" + "Authorization FAQ",
 * read 2026-10-07 — see docs/crm/quickbooks.md for the citations):
 *   - authorize: GET https://appcenter.intuit.com/connect/oauth2?client_id&scope&redirect_uri&response_type=code&state
 *     state is REQUIRED and must be checked on return (CSRF).
 *   - callback carries code, state and realmId (the company id used in every API URL).
 *   - token endpoint: POST form grant_type=authorization_code&code&redirect_uri, Authorization: Basic
 *     base64(client_id:client_secret). Exchange a code ONCE; a second exchange invalidates tokens.
 *   - response: access_token (expires_in 3600 s), refresh_token (x_refresh_token_expires_in,
 *     100-day rolling), optional x_refresh_token_hard_expires_in (5-year hard limit) when the
 *     request carries the header x-include-refresh-token-hard-expires-in: true.
 *   - refresh: POST grant_type=refresh_token&refresh_token=… ; Intuit rotates the refresh token
 *     (a new value at least every 24 h) — ALWAYS store the latest one. Refresh one at a time.
 *   - revoke: POST https://developer.api.intuit.com/v2/oauth2/tokens/revoke {"token": refresh_token}.
 *
 * Pure parts (parseTokenResponse, authorizeUrl) are unit-tested; the network goes through QboTransport.
 */
declare(strict_types=1);

require_once __DIR__ . '/QboConfig.php';
require_once __DIR__ . '/QboTransport.php';

class QboOAuthService
{
    private QboConfig $config;
    private QboTransport $transport;

    public function __construct(QboConfig $config, QboTransport $transport)
    {
        $this->config    = $config;
        $this->transport = $transport;
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(20));
    }

    /** Where to send the admin's browser. */
    public function authorizeUrl(string $state): string
    {
        if (!$this->config->isConfigured()) {
            throw new RuntimeException('QuickBooks is not configured: add ' . implode(', ', $this->config->missing()) . ' to secrets.php.');
        }
        return QboConfig::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->config->clientId(),
            'scope'         => QboConfig::SCOPE,
            'redirect_uri'  => $this->config->redirectUri(),
            'response_type' => 'code',
            'state'         => $state,
        ]);
    }

    /** Exchange the one-time code. Returns the parsed token set (see parseTokenResponse). */
    public function exchangeCode(string $code): array
    {
        return $this->tokenCall([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->config->redirectUri(),
        ]);
    }

    /** Refresh with the LATEST stored refresh token; the returned set carries the new one. */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenCall([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /** Revoke the connection at Intuit (disconnect). True on 200. */
    public function revoke(string $refreshToken): bool
    {
        $r = $this->transport->send('POST', QboConfig::REVOKE_URL, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $this->config->basicAuthHeader(),
        ], json_encode(['token' => $refreshToken]));
        return $r['status'] === 200;
    }

    private function tokenCall(array $form): array
    {
        $r = $this->transport->send('POST', QboConfig::TOKEN_URL, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: ' . $this->config->basicAuthHeader(),
            'x-include-refresh-token-hard-expires-in: true',
        ], http_build_query($form));

        $json = json_decode((string)$r['body'], true);
        if ($r['status'] !== 200 || !is_array($json) || empty($json['access_token'])) {
            $why = is_array($json) ? (($json['error'] ?? '') . ' ' . ($json['error_description'] ?? '')) : substr((string)$r['body'], 0, 200);
            throw new RuntimeException('Intuit token request failed (HTTP ' . $r['status'] . '): ' . trim($why));
        }
        return self::parseTokenResponse($json, time());
    }

    /**
     * Intuit's token JSON → what we store. Pure.
     * @return array{access_token:string,refresh_token:string,access_expires_at:string,refresh_expires_at:string,refresh_hard_expires_at:?string,token_type:string}
     */
    public static function parseTokenResponse(array $json, int $now): array
    {
        $accessIn  = (int)($json['expires_in'] ?? 3600);
        $refreshIn = (int)($json['x_refresh_token_expires_in'] ?? 100 * 86400);
        $hardIn    = isset($json['x_refresh_token_hard_expires_in']) ? (int)$json['x_refresh_token_hard_expires_in'] : null;
        return [
            'access_token'            => (string)($json['access_token'] ?? ''),
            'refresh_token'           => (string)($json['refresh_token'] ?? ''),
            'access_expires_at'       => gmdate('Y-m-d H:i:s', $now + $accessIn),
            'refresh_expires_at'      => gmdate('Y-m-d H:i:s', $now + $refreshIn),
            'refresh_hard_expires_at' => $hardIn !== null ? gmdate('Y-m-d H:i:s', $now + $hardIn) : null,
            'token_type'              => (string)($json['token_type'] ?? 'bearer'),
        ];
    }
}
