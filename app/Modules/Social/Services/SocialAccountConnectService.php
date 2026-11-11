<?php
/**
 * SocialAccountConnectService — connect or RECONNECT a social account.
 *
 * Why this exists: the 'connect' action always INSERTed a new social_accounts
 * row. On 2026-10-07 Tim reconnected Facebook + Instagram and the old May rows
 * (#13 IG, #14 FB — tokens undecryptable after the key change) stayed active
 * next to the new ones (#15, #16) until they were disconnected by hand. Every
 * post fanned out to the dead pair too and failed.
 *
 * So a connect is an upsert keyed on the thing being connected:
 *   - facebook  → the Page id (account_id_external)
 *   - instagram → the Page id, or the ig_user_id stored in meta_json
 *   - gbp       → the location resource name (location_id_external)
 * The newest matching row is refreshed in place (new token, meta, active,
 * verified, connected now, health cleared). Any older matches are soft-
 * disconnected (is_active = -1, exactly what 'disconnect' does) and their
 * still-pending queue / post-platform rows are moved onto the kept row.
 *
 * Rows already disconnected (is_active = -1) are never matched — they are
 * history, referenced by published posts.
 *
 * Tokens arrive here ALREADY encrypted: this class only touches the database,
 * so it is testable without the encryption key.
 *
 * Portable SQL only (runs on MySQL 5.7/8 and on SQLite in unit tests):
 * CURRENT_TIMESTAMP rather than NOW(), JSON handled in PHP.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialAccountConnectService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Ids of live (not removed) rows that represent the same Meta account, newest first.
     *
     * @return int[]
     */
    public function findMetaMatches(string $platform, string $pageId, ?string $igUserId): array
    {
        $ids = [];
        foreach ($this->liveRows($platform) as $row) {
            $meta = $this->decodeMeta($row['meta_json'] ?? null);
            $rowPageId = (string)($row['account_id_external'] ?? '');
            if ($rowPageId === '') {
                $rowPageId = (string)($meta['page_id'] ?? '');
            }
            $match = $pageId !== '' && $rowPageId === $pageId;
            if (!$match && $platform === 'instagram' && $igUserId) {
                $match = (string)($meta['ig_user_id'] ?? '') === $igUserId;
            }
            if ($match) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Ids of live rows for the same GBP location, newest first.
     *
     * @return int[]
     */
    public function findGbpMatches(string $platform, string $locationName): array
    {
        if ($locationName === '') {
            return [];
        }
        $ids = [];
        foreach ($this->liveRows($platform) as $row) {
            if ((string)($row['location_id_external'] ?? '') === $locationName) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Connect (or reconnect) a Facebook Page / Instagram Business account.
     *
     * @return array{id:int, action:string, retired:int[], repointed:int}
     */
    public function connectMeta(
        string $platform,
        string $accountName,
        string $pageId,
        ?string $igUserId,
        string $pageTokenEnc,
        ?string $scope,
        ?int $userId
    ): array {
        if (!in_array($platform, ['facebook', 'instagram'], true)) {
            throw new InvalidArgumentException('connectMeta() is for facebook/instagram only');
        }
        if ($pageId === '' || $pageTokenEnc === '' || $accountName === '') {
            throw new InvalidArgumentException('page_id, page token and account name are required');
        }

        $this->db->beginTransaction();
        try {
            $matches = $this->findMetaMatches($platform, $pageId, $igUserId);

            if ($matches) {
                $keepId = $matches[0];
                $meta = $this->decodeMeta($this->metaJsonFor($keepId));
                unset($meta['health']); // a fresh token deserves a fresh check
                $meta['page_id']    = $pageId;
                $meta['page_name']  = $accountName;
                $meta['ig_user_id'] = $igUserId;

                $this->db->prepare("
                    UPDATE social_accounts
                       SET account_name = ?, account_id_external = ?,
                           access_token_enc = ?, refresh_token_enc = NULL,
                           token_expires_at = NULL, token_scope = ?,
                           meta_json = ?, is_active = 1, is_verified = 1,
                           connected_by = ?, connected_at = CURRENT_TIMESTAMP,
                           updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?
                ")->execute([
                    $accountName, $pageId, $pageTokenEnc, $scope,
                    json_encode($meta), $userId, $keepId,
                ]);
                $action = 'updated';
            } else {
                $this->db->prepare("
                    INSERT INTO social_accounts
                        (platform, account_name, account_id_external,
                         access_token_enc, refresh_token_enc, token_expires_at, token_scope,
                         meta_json, is_active, is_verified, connected_by, connected_at)
                    VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, 1, 1, ?, CURRENT_TIMESTAMP)
                ")->execute([
                    $platform, $accountName, $pageId, $pageTokenEnc, $scope,
                    json_encode(['page_id' => $pageId, 'page_name' => $accountName, 'ig_user_id' => $igUserId]),
                    $userId,
                ]);
                $keepId = (int)$this->db->lastInsertId();
                $action = 'inserted';
            }

            $retired   = array_slice($matches, 1);
            $repointed = $this->retire($retired, $keepId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $keepId, 'action' => $action, 'retired' => $retired, 'repointed' => $repointed];
    }

    /**
     * Connect (or reconnect) a Google Business Profile location.
     *
     * $refreshTokenEnc null = Google sent no refresh token this time; an existing
     * row keeps the one it has (Google only issues it on first consent).
     *
     * @return array{id:int, action:string, retired:int[], repointed:int}
     */
    public function connectGbp(
        string $platform,
        string $accountName,
        ?string $accountIdExternal,
        string $locationName,
        string $locationDisplay,
        string $accessTokenEnc,
        ?string $refreshTokenEnc,
        ?string $expiresAt,
        ?string $scope,
        ?int $userId
    ): array {
        if ($accountName === '' || $accessTokenEnc === '') {
            throw new InvalidArgumentException('account name and access token are required');
        }

        $this->db->beginTransaction();
        try {
            $matches = $this->findGbpMatches($platform, $locationName);

            if ($matches) {
                $keepId = $matches[0];
                $meta = $this->decodeMeta($this->metaJsonFor($keepId));
                unset($meta['health']);

                $this->db->prepare("
                    UPDATE social_accounts
                       SET account_name = ?,
                           account_id_external = COALESCE(?, account_id_external),
                           location_name_display = ?,
                           access_token_enc = ?,
                           refresh_token_enc = COALESCE(?, refresh_token_enc),
                           token_expires_at = ?, token_scope = ?,
                           meta_json = ?, is_active = 1, is_verified = 1,
                           connected_by = ?, connected_at = CURRENT_TIMESTAMP,
                           updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?
                ")->execute([
                    $accountName, $accountIdExternal ?: null, $locationDisplay ?: $accountName,
                    $accessTokenEnc, $refreshTokenEnc, $expiresAt, $scope,
                    $meta ? json_encode($meta) : null, $userId, $keepId,
                ]);
                $action = 'updated';
            } else {
                $this->db->prepare("
                    INSERT INTO social_accounts
                        (platform, account_name, account_id_external, location_id_external,
                         location_name_display, access_token_enc, refresh_token_enc,
                         token_expires_at, token_scope, is_active, is_verified,
                         connected_by, connected_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, CURRENT_TIMESTAMP)
                ")->execute([
                    $platform, $accountName, $accountIdExternal ?: null, $locationName ?: null,
                    $locationDisplay ?: $accountName, $accessTokenEnc, $refreshTokenEnc,
                    $expiresAt, $scope, $userId,
                ]);
                $keepId = (int)$this->db->lastInsertId();
                $action = 'inserted';
            }

            $retired   = array_slice($matches, 1);
            $repointed = $this->retire($retired, $keepId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $keepId, 'action' => $action, 'retired' => $retired, 'repointed' => $repointed];
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> live rows for a platform, newest first */
    private function liveRows(string $platform): array
    {
        $stmt = $this->db->prepare("
            SELECT id, account_id_external, location_id_external, meta_json
              FROM social_accounts
             WHERE platform = ? AND is_active <> -1
             ORDER BY id DESC
        ");
        $stmt->execute([$platform]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function metaJsonFor(int $id): ?string
    {
        $stmt = $this->db->prepare("SELECT meta_json FROM social_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    private function decodeMeta($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Soft-disconnect duplicate rows and move their pending work onto $keepId.
     * A pending row whose post already has a row for $keepId would publish twice,
     * so that one is closed instead of moved.
     *
     * @param int[] $ids
     * @return int number of pending rows moved
     */
    private function retire(array $ids, int $keepId): int
    {
        $moved = 0;
        foreach ($ids as $oldId) {
            $this->db->prepare("UPDATE social_accounts SET is_active = -1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                     ->execute([$oldId]);

            // social_queue
            $rows = $this->db->prepare("SELECT id, post_id, platform FROM social_queue WHERE account_id = ? AND status = 'pending'");
            $rows->execute([$oldId]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $dup = $this->db->prepare("SELECT COUNT(*) FROM social_queue WHERE account_id = ? AND post_id = ? AND platform = ? AND status IN ('pending','processing','completed')");
                $dup->execute([$keepId, $q['post_id'], $q['platform']]);
                if ((int)$dup->fetchColumn() > 0) {
                    $this->db->prepare("UPDATE social_queue SET status = 'failed', result_payload = ? WHERE id = ?")
                             ->execute(['Superseded: account #' . $oldId . ' replaced by #' . $keepId . ' on reconnect', $q['id']]);
                } else {
                    $this->db->prepare("UPDATE social_queue SET account_id = ? WHERE id = ?")->execute([$keepId, $q['id']]);
                    $moved++;
                }
            }

            // social_post_platforms
            $rows = $this->db->prepare("SELECT id, post_id, platform FROM social_post_platforms WHERE account_id = ? AND status = 'pending'");
            $rows->execute([$oldId]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $pp) {
                $dup = $this->db->prepare("SELECT COUNT(*) FROM social_post_platforms WHERE account_id = ? AND post_id = ? AND platform = ?");
                $dup->execute([$keepId, $pp['post_id'], $pp['platform']]);
                if ((int)$dup->fetchColumn() > 0) {
                    $this->db->prepare("UPDATE social_post_platforms SET status = 'skipped', fail_reason = ? WHERE id = ?")
                             ->execute(['Superseded: account #' . $oldId . ' replaced by #' . $keepId . ' on reconnect', $pp['id']]);
                } else {
                    $this->db->prepare("UPDATE social_post_platforms SET account_id = ? WHERE id = ?")->execute([$keepId, $pp['id']]);
                    $moved++;
                }
            }
        }
        return $moved;
    }
}
