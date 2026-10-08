<?php
/**
 * QboConnectionService — the stored QuickBooks connection: tokens at rest, refresh, status (2026-10-07).
 *
 * One active row per environment in qbo_connections (migration 1240). Tokens are encrypted with
 * SocialEncryption (SOCIAL_ENCRYPTION_KEY, AES-256-CBC, IV + ciphertext) — the same helper the
 * social accounts use, so a key rotation is handled in one place and its failure mode is already
 * known: decrypt() returns null for "stored but undecryptable" (key mismatch) and '' for "nothing
 * stored". Known-Failure-Patterns 2026-09-25: never collapse those two into "not connected".
 *
 * accessToken() hands back a usable token, refreshing first when the stored one is within
 * REFRESH_AHEAD_S of expiry. Every refresh stores the NEW refresh token Intuit returns (it rotates).
 * A failed refresh marks the row 'error' with the reason, so the settings page can say
 * "reconnect" instead of silently failing on every call.
 */
declare(strict_types=1);

require_once __DIR__ . '/QboConfig.php';
require_once __DIR__ . '/QboOAuthService.php';
require_once dirname(__DIR__, 3) . '/Social/Services/SocialEncryption.php';

class QboConnectionService
{
    /** Refresh when the access token has less than this many seconds left. */
    public const REFRESH_AHEAD_S = 300;

    private PDO $db;
    private QboConfig $config;
    private ?QboOAuthService $oauth;

    public function __construct(PDO $db, QboConfig $config, ?QboOAuthService $oauth = null)
    {
        $this->db     = $db;
        $this->config = $config;
        $this->oauth  = $oauth;
    }

    /** The connection for the configured environment, any status; null when never connected. */
    public function current(): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM qbo_connections WHERE environment = ? ORDER BY (status = 'active') DESC, id DESC LIMIT 1");
            $stmt->execute([$this->config->env()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            // Table missing (migration 1240 not run yet) — the page must still render.
            error_log('[qbo] current(): ' . $e->getMessage());
            return null;
        }
    }

    public function active(): ?array
    {
        $c = $this->current();
        return ($c && $c['status'] === 'active') ? $c : null;
    }

    /** Store a fresh token set after the OAuth callback. Returns the connection row. */
    public function connect(string $realmId, array $tokens, int $userId): array
    {
        $env = $this->config->env();
        $stmt = $this->db->prepare(
            "INSERT INTO qbo_connections
                (environment, realm_id, status, access_token_enc, refresh_token_enc, access_expires_at,
                 refresh_expires_at, refresh_hard_expires_at, scopes, connected_by, connected_at, last_refresh_at, last_error, disconnected_at)
             VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE
                status = 'active', access_token_enc = VALUES(access_token_enc), refresh_token_enc = VALUES(refresh_token_enc),
                access_expires_at = VALUES(access_expires_at), refresh_expires_at = VALUES(refresh_expires_at),
                refresh_hard_expires_at = VALUES(refresh_hard_expires_at), scopes = VALUES(scopes),
                connected_by = VALUES(connected_by), connected_at = NOW(), last_refresh_at = NOW(), last_error = NULL, disconnected_at = NULL"
        );
        $stmt->execute([
            $env, $realmId,
            SocialEncryption::encrypt($tokens['access_token']),
            SocialEncryption::encrypt($tokens['refresh_token']),
            $tokens['access_expires_at'], $tokens['refresh_expires_at'], $tokens['refresh_hard_expires_at'],
            QboConfig::SCOPE, $userId,
        ]);
        // Any older row for this environment with a different realm is retired.
        $this->db->prepare("UPDATE qbo_connections SET status = 'disconnected', disconnected_at = NOW() WHERE environment = ? AND realm_id <> ? AND status = 'active'")
                 ->execute([$env, $realmId]);
        return $this->current() ?? [];
    }

    /** Company facts from discovery (CompanyInfo / Preferences) kept on the row for the status card. */
    public function rememberCompany(int $id, array $facts): void
    {
        $stmt = $this->db->prepare(
            "UPDATE qbo_connections SET company_name = ?, country = ?, home_currency = ?, fiscal_year_start = ?, book_close_date = ?, last_used_at = NOW() WHERE id = ?"
        );
        $stmt->execute([
            isset($facts['company_name']) ? substr((string)$facts['company_name'], 0, 255) : null,
            isset($facts['country']) ? substr((string)$facts['country'], 0, 8) : null,
            isset($facts['home_currency']) ? substr((string)$facts['home_currency'], 0, 8) : null,
            isset($facts['fiscal_year_start']) ? substr((string)$facts['fiscal_year_start'], 0, 16) : null,
            $facts['book_close_date'] ?? null,
            $id,
        ]);
    }

    /**
     * A usable access token for the active connection — refreshed first when near expiry.
     * Throws with a message that names the real cause (not configured / not connected /
     * key mismatch / refresh expired / Intuit refused).
     */
    public function accessToken(array &$conn, bool $forceRefresh = false): string
    {
        if (($conn['status'] ?? '') !== 'active') {
            throw new RuntimeException('QuickBooks is not connected. Connect it on Settings → QuickBooks.');
        }
        $expiresAt = isset($conn['access_expires_at']) ? strtotime((string)$conn['access_expires_at'] . ' UTC') : 0;
        $stale = $forceRefresh || $expiresAt === false || $expiresAt - time() < self::REFRESH_AHEAD_S;
        if (!$stale) {
            return SocialEncryption::requireToken((string)($conn['access_token_enc'] ?? ''), 'QuickBooks access token');
        }
        return $this->refresh($conn);
    }

    /** Refresh now, store the rotated refresh token, return the new access token. */
    public function refresh(array &$conn): string
    {
        if ($this->oauth === null) {
            throw new RuntimeException('QuickBooks token refresh needs the OAuth service.');
        }
        $refreshToken = SocialEncryption::requireToken((string)($conn['refresh_token_enc'] ?? ''), 'QuickBooks refresh token');
        $hardStop = isset($conn['refresh_expires_at']) ? strtotime((string)$conn['refresh_expires_at'] . ' UTC') : false;
        if ($hardStop !== false && $hardStop < time()) {
            $this->markError((int)$conn['id'], 'The refresh token expired (100 days unused). Reconnect QuickBooks.');
            throw new RuntimeException('The QuickBooks refresh token has expired — reconnect on Settings → QuickBooks.');
        }
        try {
            $tokens = $this->oauth->refresh($refreshToken);
        } catch (Throwable $e) {
            $this->markError((int)$conn['id'], 'Refresh refused: ' . $e->getMessage());
            throw new RuntimeException('QuickBooks refused the token refresh — reconnect on Settings → QuickBooks. (' . $e->getMessage() . ')');
        }
        $stmt = $this->db->prepare(
            "UPDATE qbo_connections SET access_token_enc = ?, refresh_token_enc = ?, access_expires_at = ?, refresh_expires_at = ?,
                refresh_hard_expires_at = COALESCE(?, refresh_hard_expires_at), last_refresh_at = NOW(), last_error = NULL, status = 'active'
             WHERE id = ?"
        );
        $stmt->execute([
            SocialEncryption::encrypt($tokens['access_token']),
            SocialEncryption::encrypt($tokens['refresh_token']),
            $tokens['access_expires_at'], $tokens['refresh_expires_at'], $tokens['refresh_hard_expires_at'],
            (int)$conn['id'],
        ]);
        $conn['access_token_enc']   = SocialEncryption::encrypt($tokens['access_token']);
        $conn['refresh_token_enc']  = SocialEncryption::encrypt($tokens['refresh_token']);
        $conn['access_expires_at']  = $tokens['access_expires_at'];
        $conn['refresh_expires_at'] = $tokens['refresh_expires_at'];
        return $tokens['access_token'];
    }

    public function touch(int $id): void
    {
        try {
            $this->db->prepare("UPDATE qbo_connections SET last_used_at = NOW() WHERE id = ?")->execute([$id]);
        } catch (Throwable $e) {
            // cosmetic
        }
    }

    public function markError(int $id, string $why): void
    {
        $this->db->prepare("UPDATE qbo_connections SET status = 'error', last_error = ? WHERE id = ?")
                 ->execute([substr($why, 0, 500), $id]);
    }

    /** Disconnect: revoke at Intuit (best effort), forget the tokens, keep the row for history. */
    public function disconnect(array $conn): array
    {
        $revoked = false;
        if ($this->oauth !== null) {
            $rt = SocialEncryption::decrypt((string)($conn['refresh_token_enc'] ?? ''));
            if (is_string($rt) && $rt !== '') {
                try {
                    $revoked = $this->oauth->revoke($rt);
                } catch (Throwable $e) {
                    error_log('[qbo] revoke failed: ' . $e->getMessage());
                }
            }
        }
        $this->db->prepare("UPDATE qbo_connections SET status = 'disconnected', access_token_enc = NULL, refresh_token_enc = NULL, disconnected_at = NOW() WHERE id = ?")
                 ->execute([(int)$conn['id']]);
        return ['revoked' => $revoked];
    }

    /**
     * What the settings card shows. Pure given the row + now; tells the truth about the two
     * credential failure modes.
     */
    public static function describe(?array $conn, QboConfig $config, int $now): array
    {
        $out = [
            'configured'  => $config->isConfigured(),
            'missing'     => $config->missing(),
            'environment' => $config->env(),
            'connected'   => false,
            'status'      => $conn['status'] ?? 'never',
            'realm_id'    => $conn['realm_id'] ?? null,
            'company_name'=> $conn['company_name'] ?? null,
            'country'     => $conn['country'] ?? null,
            'home_currency' => $conn['home_currency'] ?? null,
            'fiscal_year_start' => $conn['fiscal_year_start'] ?? null,
            'book_close_date' => $conn['book_close_date'] ?? null,
            'connected_at'=> $conn['connected_at'] ?? null,
            'last_refresh_at' => $conn['last_refresh_at'] ?? null,
            'last_used_at'=> $conn['last_used_at'] ?? null,
            'last_error'  => $conn['last_error'] ?? null,
            'refresh_days_left' => null,
            'hard_days_left'    => null,
            'credential_problem' => null,
        ];
        if (!$conn) return $out;
        if ($conn['status'] === 'active') {
            $out['connected'] = true;
            if (!empty($conn['refresh_expires_at'])) {
                $t = strtotime((string)$conn['refresh_expires_at'] . ' UTC');
                if ($t !== false) $out['refresh_days_left'] = (int)floor(($t - $now) / 86400);
            }
            if (!empty($conn['refresh_hard_expires_at'])) {
                $t = strtotime((string)$conn['refresh_hard_expires_at'] . ' UTC');
                if ($t !== false) $out['hard_days_left'] = (int)floor(($t - $now) / 86400);
            }
            if (!empty($conn['refresh_token_enc']) && SocialEncryption::decrypt((string)$conn['refresh_token_enc']) === null) {
                $out['credential_problem'] = 'The stored QuickBooks tokens will not decrypt under the current SOCIAL_ENCRYPTION_KEY (key mismatch, not a revoked connection). Reconnect to re-encrypt them.';
            }
        }
        return $out;
    }
}
