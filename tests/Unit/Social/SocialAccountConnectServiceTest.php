<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Reconnecting must refresh the existing account, not add a duplicate beside it.
 * (2026-10-07: after a Facebook/Instagram reconnect the dead May rows #13/#14
 * stayed active next to the new #15/#16 until disconnected by hand.)
 */
final class SocialAccountConnectServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE social_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            platform TEXT NOT NULL, account_name TEXT NOT NULL,
            account_id_external TEXT, location_id_external TEXT, location_name_display TEXT,
            access_token_enc TEXT, refresh_token_enc TEXT, token_expires_at TEXT, token_scope TEXT,
            is_active INTEGER NOT NULL DEFAULT 1, is_verified INTEGER NOT NULL DEFAULT 0,
            connected_by INTEGER, connected_at TEXT, last_sync_at TEXT, meta_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE social_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
            platform TEXT NOT NULL, scheduled_at TEXT NOT NULL DEFAULT '2026-10-08 09:00:00',
            status TEXT NOT NULL DEFAULT 'pending', result_payload TEXT)");
        $db->exec("CREATE TABLE social_post_platforms (
            id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
            platform TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', fail_reason TEXT)");
        return $db;
    }

    private function account(PDO $db, int $id, string $platform, ?string $pageId, array $meta = [], int $active = 1, ?string $location = null): void
    {
        $db->prepare("INSERT INTO social_accounts (id, platform, account_name, account_id_external, location_id_external,
                        access_token_enc, refresh_token_enc, is_active, is_verified, connected_at, meta_json)
                      VALUES (?, ?, 'Mowology', ?, ?, 'OLDTOKEN', 'OLDREFRESH', ?, 1, '2026-05-01 10:00:00', ?)")
           ->execute([$id, $platform, $pageId, $location, $active, $meta ? json_encode($meta) : null]);
    }

    private function row(PDO $db, int $id): array
    {
        $s = $db->prepare("SELECT * FROM social_accounts WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function scalar(PDO $db, string $sql): int
    {
        return (int)$db->query($sql)->fetchColumn();
    }

    public function test_first_connect_inserts(): void
    {
        $db = $this->db();
        $r = (new SocialAccountConnectService($db))->connectMeta('facebook', 'Mowology', 'PAGE1', null, 'ENC', null, 7);

        $this->assertSame('inserted', $r['action']);
        $this->assertSame([], $r['retired']);
        $row = $this->row($db, $r['id']);
        $this->assertSame('PAGE1', $row['account_id_external']);
        $this->assertSame('ENC', $row['access_token_enc']);
        $this->assertSame(1, (int)$row['is_active']);
        $this->assertSame(1, (int)$row['is_verified']);
        $this->assertSame('PAGE1', json_decode($row['meta_json'], true)['page_id']);
    }

    public function test_reconnect_same_page_updates_in_place_and_clears_health(): void
    {
        $db = $this->db();
        $this->account($db, 14, 'facebook', 'PAGE1', [
            'page_id' => 'PAGE1', 'page_name' => 'Old', 'ig_user_id' => null,
            'health' => ['status' => 'error', 'reason' => 'decrypt_failed'],
        ], 0);

        $r = (new SocialAccountConnectService($db))->connectMeta('facebook', 'Mowology', 'PAGE1', null, 'NEWTOKEN', null, 7);

        $this->assertSame(['id' => 14, 'action' => 'updated', 'retired' => [], 'repointed' => 0], $r);
        $this->assertSame(1, $this->scalar($db, "SELECT COUNT(*) FROM social_accounts"), 'no duplicate row');
        $row  = $this->row($db, 14);
        $meta = json_decode($row['meta_json'], true);
        $this->assertSame('NEWTOKEN', $row['access_token_enc']);
        $this->assertSame(1, (int)$row['is_active'], 'a paused row is re-activated by reconnecting');
        $this->assertSame(7, (int)$row['connected_by']);
        $this->assertNotSame('2026-05-01 10:00:00', $row['connected_at']);
        $this->assertArrayNotHasKey('health', $meta, 'stale health would keep the red badge up');
        $this->assertSame('Mowology', $meta['page_name']);
    }

    public function test_instagram_matches_by_ig_user_id_even_if_page_id_differs(): void
    {
        $db = $this->db();
        $this->account($db, 13, 'instagram', 'OLDPAGE', ['page_id' => 'OLDPAGE', 'ig_user_id' => '1784']);

        $r = (new SocialAccountConnectService($db))->connectMeta('instagram', 'Mowology', 'PAGE1', '1784', 'NEW', null, 7);

        $this->assertSame('updated', $r['action']);
        $this->assertSame(13, $r['id']);
        $this->assertSame('PAGE1', $this->row($db, 13)['account_id_external']);
    }

    public function test_platforms_do_not_cross_match(): void
    {
        $db = $this->db();
        $this->account($db, 13, 'instagram', 'PAGE1', ['page_id' => 'PAGE1', 'ig_user_id' => '1784']);

        $r = (new SocialAccountConnectService($db))->connectMeta('facebook', 'Mowology', 'PAGE1', null, 'NEW', null, 7);

        $this->assertSame('inserted', $r['action'], 'Facebook must not overwrite the Instagram row for the same page');
        $this->assertSame('OLDTOKEN', $this->row($db, 13)['access_token_enc']);
    }

    public function test_disconnected_rows_are_history_and_never_revived(): void
    {
        $db = $this->db();
        $this->account($db, 14, 'facebook', 'PAGE1', ['page_id' => 'PAGE1'], -1);

        $r = (new SocialAccountConnectService($db))->connectMeta('facebook', 'Mowology', 'PAGE1', null, 'NEW', null, 7);

        $this->assertSame('inserted', $r['action']);
        $this->assertSame(-1, (int)$this->row($db, 14)['is_active']);
        $this->assertSame('OLDTOKEN', $this->row($db, 14)['access_token_enc']);
    }

    public function test_several_matches_keep_newest_retire_rest_and_move_pending_work(): void
    {
        $db = $this->db();
        // The live state on 2026-10-07: May row #14 and today's #16 for the same page.
        $this->account($db, 14, 'facebook', 'PAGE1', ['page_id' => 'PAGE1']);
        $this->account($db, 16, 'facebook', 'PAGE1', ['page_id' => 'PAGE1']);
        // Pending work on the dead row: post 1 only on #14, post 2 on both.
        $db->exec("INSERT INTO social_queue (post_id, account_id, platform) VALUES (1, 14, 'facebook'), (2, 14, 'facebook'), (2, 16, 'facebook')");
        $db->exec("INSERT INTO social_queue (post_id, account_id, platform, status) VALUES (3, 14, 'facebook', 'completed')");
        $db->exec("INSERT INTO social_post_platforms (post_id, account_id, platform) VALUES (1, 14, 'facebook'), (2, 14, 'facebook'), (2, 16, 'facebook')");
        $db->exec("INSERT INTO social_post_platforms (post_id, account_id, platform, status) VALUES (3, 14, 'facebook', 'published')");

        $r = (new SocialAccountConnectService($db))->connectMeta('facebook', 'Mowology', 'PAGE1', null, 'NEW', null, 7);

        $this->assertSame(16, $r['id']);
        $this->assertSame('updated', $r['action']);
        $this->assertSame([14], $r['retired']);
        $this->assertSame(2, $r['repointed'], 'post 1 moved in both tables');
        $this->assertSame(-1, (int)$this->row($db, 14)['is_active'], 'soft-disconnected like Disconnect does');
        $this->assertSame('NEW', $this->row($db, 16)['access_token_enc']);

        // post 1 now targets the kept row
        $this->assertSame(16, $this->scalar($db, "SELECT account_id FROM social_queue WHERE post_id = 1"));
        $this->assertSame(16, $this->scalar($db, "SELECT account_id FROM social_post_platforms WHERE post_id = 1"));
        // post 2 would have published twice — the old copy is closed, not moved
        $this->assertSame(1, $this->scalar($db, "SELECT COUNT(*) FROM social_queue WHERE post_id = 2 AND status = 'pending'"));
        $this->assertSame('failed', $db->query("SELECT status FROM social_queue WHERE post_id = 2 AND account_id = 14")->fetchColumn());
        $this->assertSame('skipped', $db->query("SELECT status FROM social_post_platforms WHERE post_id = 2 AND account_id = 14")->fetchColumn());
        // history stays where it was
        $this->assertSame(14, $this->scalar($db, "SELECT account_id FROM social_queue WHERE post_id = 3"));
        $this->assertSame(14, $this->scalar($db, "SELECT account_id FROM social_post_platforms WHERE post_id = 3"));
        $this->assertSame(1, $this->scalar($db, "SELECT COUNT(*) FROM social_accounts WHERE platform = 'facebook' AND is_active = 1"));
    }

    public function test_gbp_reconnect_by_location_keeps_refresh_token_when_none_sent(): void
    {
        $db = $this->db();
        $this->account($db, 5, 'gbp', 'accounts/1', [], 1, 'locations/99');

        $r = (new SocialAccountConnectService($db))->connectGbp(
            'gbp', 'Mowology', 'accounts/1', 'locations/99', 'Mowology Landscaping',
            'NEWACCESS', null, '2026-10-07 12:00:00', 'scope', 7
        );

        $this->assertSame(['id' => 5, 'action' => 'updated', 'retired' => [], 'repointed' => 0], $r);
        $row = $this->row($db, 5);
        $this->assertSame('NEWACCESS', $row['access_token_enc']);
        $this->assertSame('OLDREFRESH', $row['refresh_token_enc'], 'Google only issues a refresh token on first consent');
        $this->assertSame('Mowology Landscaping', $row['location_name_display']);
    }

    public function test_gbp_different_location_inserts(): void
    {
        $db = $this->db();
        $this->account($db, 5, 'gbp', 'accounts/1', [], 1, 'locations/99');

        $r = (new SocialAccountConnectService($db))->connectGbp(
            'gbp', 'Second', 'accounts/1', 'locations/100', 'Second', 'A', 'R', null, null, 7
        );

        $this->assertSame('inserted', $r['action']);
        $this->assertSame(2, $this->scalar($db, "SELECT COUNT(*) FROM social_accounts WHERE is_active = 1"));
    }

    public function test_missing_page_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SocialAccountConnectService($this->db()))->connectMeta('facebook', 'Mowology', '', null, 'ENC', null, 7);
    }
}
