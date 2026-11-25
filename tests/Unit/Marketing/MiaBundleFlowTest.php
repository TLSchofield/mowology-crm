<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';

/** The owner's single decision through the API: preview, confirm, approve and post, undo. And the heart that starts it. */
final class MiaBundleFlowTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;
    private int $id;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-26 14:00:00');
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        MiaDemo::schema($this->db);
        $this->db->exec("INSERT INTO users VALUES (7)");
        $this->db->exec("INSERT INTO mia_websites (id, site_id, base_url, host, status) VALUES (1, 1, 'https://mowology.ca', 'mowology.ca', 'verified')");
        $this->db->exec("CREATE TABLE ba_pairs (id INTEGER PRIMARY KEY, visit_id INT, service TEXT, area TEXT, category TEXT, status TEXT, consent_state TEXT, web_before_path TEXT, web_after_path TEXT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, status TEXT, completed_at TEXT, is_flagged INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE portfolio_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, project_number TEXT UNIQUE, project_name TEXT, description TEXT, location TEXT, status TEXT DEFAULT 'draft',
            featured INT, display_order INT, categories TEXT, tags TEXT, before_image_path TEXT, after_image_path TEXT, gallery_images TEXT, created_by INT, created_at TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE mia_executions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, action_id INT NOT NULL, draft_id INT, website_id INT NOT NULL, connector TEXT, target_ref TEXT,
            page_url TEXT, before_json TEXT, after_json TEXT, status TEXT DEFAULT 'applied', verify_note TEXT, applied_by INT, applied_at TEXT, rolled_back_by INT, rolled_back_at TEXT, error TEXT)");
        $this->db->exec("CREATE TABLE social_accounts (id INTEGER PRIMARY KEY, platform TEXT, account_name TEXT, is_active INT)");
        $this->db->exec("CREATE TABLE social_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, caption TEXT, hashtags TEXT, status TEXT, neighborhood TEXT, service_type TEXT, created_by INT, approved_by INT,
            auto_generated INT, scheduled_at TEXT, created_at TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE social_post_media (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT, media_id INT, sort_order INT)");
        $this->db->exec("CREATE TABLE social_post_platforms (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT, account_id INT, platform TEXT, status TEXT, platform_post_id TEXT, platform_url TEXT, fail_reason TEXT, published_at TEXT)");
        $this->db->exec("CREATE TABLE social_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT, account_id INT, platform TEXT, scheduled_at TEXT, attempts INT DEFAULT 0, max_attempts INT DEFAULT 3, status TEXT DEFAULT 'pending')");
        $this->db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, original_filename TEXT, stored_filename TEXT, file_path TEXT, file_type TEXT, mime_type TEXT, context_type TEXT, status TEXT, created_by INT, created_at TEXT)");
        $this->db->exec("INSERT INTO social_accounts VALUES (15, 'instagram', 'Mowology', 1), (16, 'facebook', 'Mowology', 1)");
        $this->db->exec("INSERT INTO job_visits VALUES (2428, 'completed', '2026-09-28 15:11:35', 1)");
        $this->db->exec("INSERT INTO ba_pairs VALUES (10, 2428, 'Hedge Trimming', 'Point Grey', 'garden', 'approved', 'not_needed', '/_media/variants/b.jpg', '/_media/variants/a.jpg')");
        $r = (new MiaActionService($this->db, 1))->propose(['type' => 'project_story', 'side_effect' => 'none', 'opportunity_key' => 'visit:2428', 'title' => 'Finished job could become a project story',
            'why' => 'A completed visit has approved photos.', 'risk' => 'Nothing is published.', 'evidence' => [['pair_id' => 10]], 'evidence_basis' => ['v' => 2428]], $this->now);
        $this->id = (int)$r['id'];
        $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, title, meta_desc, rationale, input_hash, status, version, created_at)
                            VALUES (1, 1, ?, 'story', 'portfolio:pair:10', 'Hedge trimming in Point Grey', 'Throughout September, the Mowology team was kept busy trimming hedges across Point Grey, Vancouver.', 'r', 'h', 'draft', 1, ?)")
            ->execute([$this->id, $this->now->format('Y-m-d H:i:s')]);
        (new MiaActionService($this->db, 1))->attachDraft($this->id, 'mia_content_drafts', (int)$this->db->lastInsertId(), 'r', $this->now);
        foreach (['publish_mode' => 'bundle', 'publish_channels' => 'facebook,instagram'] as $k => $v) {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?)")->execute(['mia_' . $k, $v]);
        }
    }

    private function call(string $mode, string $method, array $q = [], array $in = []): array { return (new MiaApiHandler($this->db, 7, null, null, 1))->handle($mode, $method, $q, $in, $this->now); }
    private function n(string $sql): int { return (int)$this->db->query($sql)->fetchColumn(); }

    public function testThePreviewShowsEveryChannelsExactWordsAndTheUndoRules(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        $b = $d['item']['bundle'];
        $this->assertSame(['website', 'facebook', 'instagram'], array_keys($b['channels']));
        $this->assertStringContainsString('https://mowology.ca/quote', $b['channels']['facebook']['text']);
        $this->assertTrue($b['channels']['facebook']['undoable_after_posting']);
        $this->assertFalse($b['channels']['instagram']['undoable_after_posting']);
        $this->assertTrue($b['instagram_on']);
        $this->assertTrue($b['can_publish']);
        $this->assertSame(10, $b['delay_minutes']);
        $this->assertArrayNotHasKey('content', $b, 'the bound text stays on the server');
    }

    public function testApproveAndPostNeedsAnExplicitConfirmAndAnExtraOneForInstagram(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        $seen = $d['item']['seen_hash'];
        [$c, $r] = $this->call('action_approve_publish', 'POST', [], ['id' => $this->id, 'seen_hash' => $seen]);
        $this->assertSame(422, $c); $this->assertSame('confirm', $r['code']);
        [$c, $r] = $this->call('action_approve_publish', 'POST', [], ['id' => $this->id, 'seen_hash' => $seen, 'confirm' => true]);
        $this->assertSame(422, $c); $this->assertSame('confirm_instagram', $r['code']);
        $this->assertSame('proposed', $this->db->query("SELECT status FROM mia_actions WHERE id = {$this->id}")->fetchColumn(), 'nothing was approved without both confirms');
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects") + $this->n("SELECT COUNT(*) FROM social_posts"));
    }

    public function testOneTapApprovesPostsAndCanBeUndone(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        [$c, $r] = $this->call('action_approve_publish', 'POST', [], ['id' => $this->id, 'seen_hash' => $d['item']['seen_hash'], 'confirm' => true, 'confirm_instagram' => true]);
        $this->assertSame(200, $c, json_encode($r));
        $this->assertSame(['website', 'facebook', 'instagram'], $r['channels']);
        $this->assertSame('published', $this->db->query("SELECT status FROM portfolio_projects")->fetchColumn());
        $this->assertSame(2, $this->n("SELECT COUNT(*) FROM social_queue WHERE status = 'pending'"));

        $exec = (int)$this->db->query("SELECT id FROM mia_executions WHERE connector = 'instagram'")->fetchColumn();
        [$c, $u] = $this->call('action_rollback', 'POST', [], ['execution_id' => $exec]);
        $this->assertSame(200, $c, json_encode($u));
        $this->assertEqualsCanonicalizing(['facebook', 'instagram'], $u['cancelled']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM social_queue WHERE status = 'pending'"));
    }

    public function testAStaleScreenCannotApprove(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        $seen = $d['item']['seen_hash'];
        $this->db->exec("UPDATE ops_settings SET setting_value = 'facebook' WHERE setting_key = 'mia_publish_channels'");
        [$c, $r] = $this->call('action_approve_publish', 'POST', [], ['id' => $this->id, 'seen_hash' => $seen, 'confirm' => true]);
        $this->assertSame(409, $c); $this->assertSame('changed', $r['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects") + $this->n("SELECT COUNT(*) FROM social_posts"));
    }

    public function testWithTheSwitchOffTheOldTwoStepFlowIsUntouched(): void
    {
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key = 'mia_publish_mode'");
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        $this->assertArrayNotHasKey('bundle', $d['item']);
        [$c, $r] = $this->call('action_approve_publish', 'POST', [], ['id' => $this->id, 'confirm' => true]);
        $this->assertSame(409, $c); $this->assertSame('not_available', $r['code']);
    }

    // ---- the heart ----

    private function heartDb(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS users_role (id INTEGER)");
        $this->db->exec("DROP TABLE users");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)");
        $this->db->exec("INSERT INTO users VALUES (7, 'admin'), (8, 'crew')");
    }

    public function testTheHeartDoesNothingUnlessSingleOperatorModeIsOnAndTheHeartIsTheOwners(): void
    {
        $this->heartDb();
        $ran = 0; $drain = function () use (&$ran) { $ran++; };
        $this->assertSame('off', MiaHeartFlow::run($this->db, 2428, 7, $drain)['reason']);
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('mia_single_operator', '1')");
        $this->assertSame('not_owner', MiaHeartFlow::run($this->db, 2428, 8, $drain)['reason']);
        $this->db->exec("UPDATE job_visits SET is_flagged = 0");
        $this->assertSame('not_endorsed', MiaHeartFlow::run($this->db, 2428, 7, $drain)['reason']);
        $this->assertSame(0, $ran);
    }

    public function testTheOwnersHeartOnAnApprovedPairTellsMiaAtOnce(): void
    {
        $this->heartDb();
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('mia_single_operator', '1')");
        $ran = 0;
        $r = MiaHeartFlow::run($this->db, 2428, 7, function () use (&$ran) { $ran++; });
        $this->assertTrue($r['ran']); $this->assertTrue($r['approved']); $this->assertSame(10, $r['pair_id']);
        $this->assertSame(1, $ran, 'Mia is told now, not at the next sweep');
    }

    public function testAPairThatNeedsTheClientsAgreementStaysInTheQueue(): void
    {
        $this->heartDb();
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('mia_single_operator', '1')");
        $this->db->exec("UPDATE ba_pairs SET status = 'pending', consent_state = 'needed'");
        $ran = 0;
        $r = MiaHeartFlow::run($this->db, 2428, 7, function () use (&$ran) { $ran++; });
        $this->assertSame('consent_or_state', $r['reason']);
        $this->assertFalse($r['approved']);
        $this->assertSame('pending', $this->db->query("SELECT status FROM ba_pairs")->fetchColumn());
        $this->assertSame(0, $ran);
    }

    public function testTheOlderTemplatePostIsOnlySwitchedOffByBundleMode(): void
    {
        $this->assertTrue(MiaHeartFlow::skipsOldDraft($this->db));
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key = 'mia_publish_mode'");
        $this->assertFalse(MiaHeartFlow::skipsOldDraft($this->db));
    }

    public function testTheSafetyNetApprovesOnlyOwnerEndorsedPairsThatNeedNoAgreement(): void
    {
        $this->heartDb();
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('mia_single_operator', '1')");
        $this->db->exec("ALTER TABLE ba_pairs ADD COLUMN queued_by INT");
        $this->db->exec("ALTER TABLE ba_pairs ADD COLUMN before_id INT");
        $this->db->exec("ALTER TABLE ba_pairs ADD COLUMN after_id INT");
        $this->db->exec("UPDATE ba_pairs SET status = 'pending', queued_by = 8, before_id = 1, after_id = 2");          // a crew member's heart
        $this->assertSame(0, MiaHeartFlow::sweep($this->db), 'a crew heart still waits for the owner');
        $this->db->exec("UPDATE ba_pairs SET queued_by = 7, consent_state = 'needed'");
        $this->assertSame(0, MiaHeartFlow::sweep($this->db), 'consent still has to be recorded');
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key = 'mia_single_operator'");
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'not_needed'");
        $this->assertSame(0, MiaHeartFlow::sweep($this->db), 'and nothing at all unless single-operator mode is on');
    }
}
