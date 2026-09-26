<?php
/**
 * SocialAccountHealth — is each connected social account still usable?
 *
 * Why this exists: Facebook and Instagram publishing failed from 2026-06-18 to
 * 2026-09-25 and nothing said so. The credentials were intact in the table but
 * could not be decrypted after a key rotation, the Social Accounts page kept
 * showing a stored "Verified" badge from connect time, and the only trace was a
 * `post_failed` row in an audit log at the bottom of a settings page. Three
 * months, ~2-4 lost posts a week, discovered by accident.
 *
 * So: check the credential on a schedule, write the answer where the badge reads
 * from, and email the office the first time an account goes bad. A check that
 * only writes to a log would have changed nothing.
 *
 * Runs from the social_publisher cron (every 5 min, already scheduled in cPanel)
 * rather than a cron of its own, because a new cron entry needs a human to
 * schedule it and would have sat dormant — the same way social_metrics_sync did.
 * Rate-limited to one check per account per hour, so it costs ~24 Graph calls a
 * day per account.
 *
 * Health lives in social_accounts.meta_json['health'] — no migration needed, so
 * this is live the moment the file lands.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialAccountHealth
{
    /** Don't re-check an account more often than this. */
    public const CHECK_EVERY_MINUTES = 60;

    /**
     * Check every active account that is due, persist the result, alert on a
     * fresh failure.
     *
     * Never throws: this runs at the top of the publisher cron and must not be
     * able to stop posts going out.
     *
     * @return array{checked:int,ok:int,failed:int,alerted:int,notes:string[]}
     */
    public static function sweep(PDO $db, bool $force = false): array
    {
        $out = ['checked' => 0, 'ok' => 0, 'failed' => 0, 'alerted' => 0, 'notes' => []];

        try {
            $accounts = $db->query("
                SELECT id, platform, account_name, account_id_external, access_token_enc,
                       refresh_token_enc, token_expires_at, meta_json, is_verified
                FROM social_accounts
                WHERE is_active = 1
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $out['notes'][] = 'could not list accounts: ' . $e->getMessage();
            return $out;
        }

        foreach ($accounts as $account) {
            $meta   = json_decode($account['meta_json'] ?? '{}', true) ?: [];
            $health = is_array($meta['health'] ?? null) ? $meta['health'] : [];

            if (!$force && !self::isDue($health)) {
                continue;
            }

            $result = self::check($account);
            $out['checked']++;
            $result['ok'] ? $out['ok']++ : $out['failed']++;

            $wasFailing = ($health['status'] ?? 'unknown') === 'error';
            $alerted    = (bool)($health['alerted'] ?? false);

            // Alert on the TRANSITION into failure, and once only — a message
            // every 5 minutes is a message nobody reads.
            $shouldAlert = !$result['ok'] && (!$wasFailing || !$alerted);

            if ($shouldAlert) {
                $alerted = self::alert($db, $account, $result);
                if ($alerted) {
                    $out['alerted']++;
                }
            }

            if ($result['ok']) {
                $alerted = false;   // armed again for the next failure
            }

            self::persist($db, (int)$account['id'], $meta, $result, $alerted);

            $out['notes'][] = sprintf(
                '%s #%d %s%s',
                $account['platform'],
                $account['id'],
                $result['ok'] ? 'ok' : ('FAILED (' . $result['reason'] . ')'),
                $shouldAlert ? ' — office emailed' : ''
            );
        }

        return $out;
    }

    /** Last stored health for an account, or null if never checked. */
    public static function statusFor(array $account): ?array
    {
        $meta = json_decode($account['meta_json'] ?? '{}', true) ?: [];
        return is_array($meta['health'] ?? null) ? $meta['health'] : null;
    }

    // ── Internals ────────────────────────────────────────────────────

    private static function isDue(array $health): bool
    {
        $checkedAt = $health['checked_at'] ?? null;
        if (!$checkedAt) {
            return true;
        }

        $ts = strtotime((string)$checkedAt);
        if ($ts === false) {
            return true;
        }

        return $ts <= time() - (self::CHECK_EVERY_MINUTES * 60);
    }

    /** @return array{ok:bool,reason:string,detail:string} */
    private static function check(array $account): array
    {
        $platform = (string)$account['platform'];

        try {
            if ($platform === 'facebook' || $platform === 'instagram') {
                return MetaService::checkTokenHealth($account);
            }

            // Google Business Profile: verify the stored credential is readable
            // and not expired without a refresh token. Deliberately no API call
            // — GoogleBusinessService::ensureFreshToken() would spend a refresh.
            SocialEncryption::requireToken($account['access_token_enc'] ?? '', 'Google access token');

            $expiresAt = $account['token_expires_at'] ?? null;
            $hasRefresh = trim((string)($account['refresh_token_enc'] ?? '')) !== '';

            if ($expiresAt && strtotime((string)$expiresAt) < time() && !$hasRefresh) {
                return [
                    'ok'     => false,
                    'reason' => 'no_token',
                    'detail' => 'Access token expired on ' . $expiresAt . ' and no refresh token is stored.',
                ];
            }

            return ['ok' => true, 'reason' => 'ok', 'detail' => 'Credential decrypts; not checked against Google.'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            return [
                'ok'     => false,
                'reason' => str_contains($msg, 'could not be decrypted') ? 'decrypt_failed' : 'no_token',
                'detail' => $msg,
            ];
        }
    }

    private static function persist(PDO $db, int $accountId, array $meta, array $result, bool $alerted): void
    {
        $meta['health'] = [
            'status'     => $result['ok'] ? 'ok' : 'error',
            'reason'     => $result['reason'],
            'detail'     => mb_substr($result['detail'], 0, 500),
            'checked_at' => date('Y-m-d H:i:s'),
            'alerted'    => $alerted,
        ];

        try {
            // is_verified drives the badge on the Social Accounts page. It used
            // to be written once at connect time and never revisited, which is
            // how two dead accounts displayed "Verified" for three months.
            $db->prepare("UPDATE social_accounts SET meta_json = ?, is_verified = ? WHERE id = ?")
               ->execute([json_encode($meta), $result['ok'] ? 1 : 0, $accountId]);
        } catch (\Throwable $e) {
            error_log('SocialAccountHealth::persist failed for account #' . $accountId . ': ' . $e->getMessage());
        }
    }

    /** @return bool true if an email actually went out. */
    private static function alert(PDO $db, array $account, array $result): bool
    {
        try {
            $biz = [];
            try {
                $biz = $db->query("SELECT * FROM business_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                // Tenant identity is read from business_settings, never hardcoded.
            }

            $to     = trim((string)($biz['company_email'] ?? ''));
            $sender = trim((string)($biz['company_name'] ?? '')) ?: 'Mowology';
            $phone  = trim((string)($biz['company_phone'] ?? $biz['phone'] ?? ''));

            if ($to === '') {
                error_log('SocialAccountHealth: account #' . $account['id'] . ' is unhealthy but business_settings.company_email is empty — no alert sent.');
                return false;
            }

            if (!function_exists('sendCrmEmail')) {
                error_log('SocialAccountHealth: sendCrmEmail() unavailable — no alert sent.');
                return false;
            }

            $platform = ucfirst((string)$account['platform']);
            $name     = (string)$account['account_name'];
            $detail   = htmlspecialchars($result['detail'], ENT_QUOTES, 'UTF-8');

            $advice = $result['reason'] === 'decrypt_failed'
                ? '<p><strong>This is a key problem, not a Meta problem.</strong> The stored token is still in the '
                  . 'database but cannot be decrypted — SOCIAL_ENCRYPTION_KEY no longer matches the key it was '
                  . 'encrypted with. Reconnecting the account re-encrypts it under the current key.</p>'
                : '<p>Reconnect the account at <em>CRM &rarr; Social Posts &rarr; Social Accounts</em>.</p>';

            $html = '<p>Scheduled posts to <strong>' . htmlspecialchars($platform . ' — ' . $name, ENT_QUOTES, 'UTF-8')
                  . '</strong> will fail until this is fixed.</p>'
                  . '<p><strong>What the check found:</strong><br>' . $detail . '</p>'
                  . $advice
                  . '<p style="color:#666;font-size:12px">Sent once per outage by the social publisher\'s hourly '
                  . 'connection check. You will get another only after the account recovers and fails again.'
                  . ($phone !== '' ? ' Office: ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : '')
                  . '</p>';

            $sent = sendCrmEmail($to, "Social posting is down: {$platform} ({$name})", $html, null, $sender);

            if (!$sent) {
                error_log('SocialAccountHealth: alert email to ' . $to . ' failed for account #' . $account['id']);
            }

            return (bool)$sent;
        } catch (\Throwable $e) {
            error_log('SocialAccountHealth::alert error: ' . $e->getMessage());
            return false;
        }
    }
}
