<?php
/**
 * SocialConnectionState — one plain answer per platform: connected, reconnect, or not connected.
 *
 * Why: from 2026-06-18 the Facebook/Instagram page tokens could not be decrypted (a
 * SOCIAL_ENCRYPTION_KEY re-key) and every surface said "no page token", which named the wrong
 * cause. SocialAccountHealth now records the real reason in social_accounts.meta_json['health']
 * (decrypt_failed / no_token / api_error / instagram_unlinked); this turns that into the one
 * thing Tim needs to see — "Reconnect Facebook" — on the Social page and on Mia's card.
 *
 * Reconnecting (re-running the Facebook OAuth connect) re-encrypts the tokens under the current
 * key; the old ciphertext is never "fixed" in place.
 *
 * Pure apart from load(). No namespace / no autoloader in production.
 */
class SocialConnectionState
{
    public const RECONNECT_URL = '/crm/api/social/accounts.php?action=oauth-init&platform=facebook';
    public const GBP_CONNECT_URL = '/crm/api/social/accounts.php?action=oauth-init&platform=gbp';

    private const REASONS = [
        'decrypt_failed'     => "the saved login can't be read any more (the encryption key changed). Nothing is posting until you reconnect.",
        'no_token'           => 'no login is saved for it. Nothing is posting until you reconnect.',
        'api_error'          => 'Facebook turned the saved login down (expired or revoked). Nothing is posting until you reconnect.',
        'instagram_unlinked' => 'Instagram is no longer linked to the Facebook Page. Reconnect and tick the Instagram account.',
    ];

    /** @return array<int, array> social_accounts rows (no tokens selected beyond what health needs) */
    public static function load(PDO $db): array
    {
        try {
            return $db->query("SELECT id, platform, account_name, is_active, is_verified, meta_json, location_id_external, token_expires_at
                               FROM social_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<int, array> $accounts social_accounts rows
     * @return array{facebook: array, instagram: array, gbp: array, needs_reconnect: bool, message: string, action_url: string}
     *   each platform: [status: connected|reconnect|not_connected|paused, reason: string, label: string, account: string]
     */
    public static function fromAccounts(array $accounts): array
    {
        $out = [];
        foreach (['facebook', 'instagram', 'gbp'] as $p) {
            $out[$p] = self::platform($p, array_values(array_filter($accounts, fn($a) => ($a['platform'] ?? '') === $p)));
        }
        $broken = [];
        foreach (['facebook', 'instagram'] as $p) {
            if ($out[$p]['status'] === 'reconnect') $broken[] = $p;
        }
        $msg = '';
        if ($broken) {
            $first = $out[$broken[0]];
            $names = implode(' and ', array_map('ucfirst', $broken));
            $msg = 'Reconnect Facebook — ' . $names . ': ' . $first['reason'];
        }
        return $out + ['needs_reconnect' => (bool)$broken, 'message' => $msg, 'action_url' => self::RECONNECT_URL];
    }

    private static function platform(string $p, array $rows): array
    {
        $label = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'gbp' => 'Google Business Profile'][$p];
        if (!$rows) {
            return ['status' => 'not_connected', 'reason' => 'not connected yet', 'label' => $label, 'account' => ''];
        }
        $active = array_values(array_filter($rows, fn($r) => (int)($r['is_active'] ?? 0) === 1));
        if (!$active) {
            return ['status' => 'paused', 'reason' => 'paused in Social Accounts', 'label' => $label, 'account' => (string)($rows[0]['account_name'] ?? '')];
        }
        foreach ($active as $r) {
            $meta = json_decode((string)($r['meta_json'] ?? ''), true) ?: [];
            $h = is_array($meta['health'] ?? null) ? $meta['health'] : null;
            if ($h && ($h['status'] ?? '') === 'error') {
                return ['status' => 'reconnect', 'reason' => self::REASONS[$h['reason'] ?? ''] ?? 'the last connection check failed. Reconnect to start posting again.',
                        'label' => $label, 'account' => (string)($r['account_name'] ?? ''), 'code' => (string)($h['reason'] ?? '')];
            }
        }
        $r = $active[0];
        return ['status' => 'connected', 'reason' => '', 'label' => $label, 'account' => (string)($r['account_name'] ?? '')];
    }
}
