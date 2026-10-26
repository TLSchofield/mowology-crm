<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';

/**
 * One decision, every channel. Nothing here talks to Facebook or Instagram: the bundle only fills the existing social queue.
 */
final class MiaBundleServiceTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;
    private int $actionId;
    private const DESC = 'Throughout September, the Mowology team was kept busy trimming and maintaining hedges across Point Grey, Vancouver.';

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
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, status TEXT, completed_at TEXT)");
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
        $this->db->exec("INSERT INTO job_visits VALUES (2428, 'completed', '2026-09-28 15:11:35')");
        $this->db->exec("INSERT INTO ba_pairs VALUES (10, 2428, 'Hedge Trimming', 'Point Grey', 'garden', 'approved', 'not_needed', '/_media/variants/2026/09/b_1280w.jpg', '/_media/variants/2026/09/a_1280w.jpg')");
        $r = $this->actions()->propose(['type' => 'project_story', 'side_effect' => 'none', 'opportunity_key' => 'visit:2428', 'title' => 'Finished job could become a project story',
            'why' => 'A completed visit has approved photos.', 'risk' => 'Nothing is published.', 'evidence' => [['pair_id' => 10]], 'evidence_basis' => ['v' => 2428]], $this->now);
        $this->actionId = (int)$r['id'];
        $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, title, meta_desc, rationale, input_hash, status, version, created_at)
                            VALUES (1, 1, ?, 'story', 'portfolio:pair:10', 'Hedge trimming in Point Grey', ?, 'r', 'h', 'draft', 1, ?)")->execute([$this->actionId, self::DESC, $this->now->format('Y-m-d H:i:s')]);
        $this->actions()->attachDraft($this->actionId, 'mia_content_drafts', (int)$this->db->lastInsertId(), 'r', $this->now);
    }

    private function setting(string $k, string $v): void { $this->db->prepare("INSERT OR REPLACE INTO ops_settings (setting_key, setting_value) VALUES (?, ?)")->execute(['mia_' . $k, $v]); }
    private function actions(): MiaActionService { return new MiaActionService($this->db, 1, function (int $id) { return $id === 7; }); }
    private function bundle(): MiaBundleService { return new MiaBundleService($this->db, 1, $this->actions()); }
    private function n(string $sql): int { return (int)$this->db->query($sql)->fetchColumn(); }

    private function approve(): array
    {
        $p = $this->bundle()->preview($this->actionId);
        $this->assertTrue($p['ok'], json_encode($p));
        $d = $this->actions()->decide($this->actionId, 7, 'approve', null, null, $p['content'], $this->now);
        $this->assertTrue($d['ok'], json_encode($d));
        return $p;
    }

    // ---- switches ----

    public function testItIsOffUntilSwitchedOnAndCreatesNothing(): void
    {
        $this->assertFalse($this->bundle()->isOn());
        $this->assertSame('off', $this->bundle()->publish($this->actionId, 7, $this->now)['code']);
        $this->assertFalse($this->bundle()->preview($this->actionId)['can_publish']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects") + $this->n("SELECT COUNT(*) FROM social_posts"));
    }

    public function testChannelsStartAsWebsiteOnlyAndOnlyKnownOnesCanBeAdded(): void
    {
        $this->assertSame(['website'], $this->bundle()->channels());
        $this->setting('publish_channels', 'facebook, tiktok ,instagram,facebook');
        $this->assertSame(['website', 'facebook', 'instagram'], $this->bundle()->channels());
        $this->setting('publish_delay_minutes', '999');
        $this->assertSame(60, $this->bundle()->delayMinutes());
        $this->setting('publish_delay_minutes', 'abc');
        $this->assertSame(10, $this->bundle()->delayMinutes());
    }

    // ---- the words ----

    public function testCaptionsAreBuiltOnlyFromTheApprovedTextAndPlainFacts(): void
    {
        $c = MiaBundleService::captions(self::DESC, 'Point Grey', 'Hedge Trimming', 'https://mowology.ca/quote');
        $this->assertStringStartsWith(self::DESC, $c['facebook']);
        $this->assertStringContainsString('https://mowology.ca/quote', $c['facebook']);
        $this->assertStringNotContainsString('http', $c['instagram'], 'Instagram captions do not carry links');
        $this->assertStringContainsString('link in our bio', $c['instagram']);
        $this->assertStringContainsString('#PointGrey #HedgeTrimming', $c['instagram']);
        $this->assertSame(['#PointGrey', '#HedgeTrimming'], MiaBundleService::hashtags('Point Grey', 'Hedge Trimming'));
        $this->assertSame([], MiaBundleService::hashtags('', 'x'));
        $this->assertSame(['#Dunbar'], MiaBundleService::hashtags('Dunbar', 'Dunbar'), 'no duplicates');
    }

    // ---- publish ----

    public function testWebsiteOnlyPublishesTheCardAtOnceAndPostsNothingElse(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->approve();
        $r = $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['website'], $r['channels']);
        $this->assertSame('published', $this->db->query("SELECT status FROM portfolio_projects")->fetchColumn());
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM social_posts"));
        $this->assertSame('done', $this->actions()->get($this->actionId)['status']);
    }

    public function testEveryChannelGetsTheExactPreviewedWordsAndPhotosAndIsQueuedForLater(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $p = $this->approve();
        $r = $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['website', 'facebook', 'instagram'], $r['channels']);
        $this->assertSame('2026-10-26 14:10:00', $r['scheduled_at'], 'a 10 minute window to undo');
        $this->assertSame('published', $this->db->query("SELECT status FROM portfolio_projects")->fetchColumn());
        $posts = $this->db->query("SELECT p.caption, p.status, q.platform, q.status qs, q.scheduled_at FROM social_posts p JOIN social_queue q ON q.post_id = p.id ORDER BY q.platform")->fetchAll();
        $this->assertCount(2, $posts);
        $this->assertSame($p['channels']['facebook']['text'], $posts[0]['caption'], 'Facebook posts the words that were shown');
        $this->assertSame($p['channels']['instagram']['text'], $posts[1]['caption']);
        foreach ($posts as $row) { $this->assertSame('scheduled', $row['status']); $this->assertSame('pending', $row['qs']); $this->assertSame('2026-10-26 14:10:00', $row['scheduled_at']); }
        $this->assertSame(['/_media/variants/2026/09/b_1280w.jpg', '/_media/variants/2026/09/a_1280w.jpg'],
            array_column($this->db->query("SELECT m.file_path FROM social_post_media spm JOIN media_assets m ON m.id = spm.media_id WHERE spm.post_id = 1 ORDER BY spm.sort_order")->fetchAll(), 'file_path'), 'before first, then after');
        $this->assertSame(2, $this->n("SELECT COUNT(*) FROM media_assets"), 'the same two photos serve both posts');
        $this->assertSame(['facebook', 'instagram', 'portfolio'], array_column($this->db->query("SELECT connector FROM mia_executions ORDER BY connector")->fetchAll(), 'connector'));
    }

    public function testAnApprovalOfOtherWordsPublishesNothing(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->approve();
        $this->db->exec("UPDATE mia_content_drafts SET meta_desc = 'Words changed after the owner approved them, so they are not what was shown to anyone.'");
        $r = $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->assertSame('draft_changed', $r['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects") + $this->n("SELECT COUNT(*) FROM social_posts"));
    }

    public function testChangingTheChannelsOrDelayAfterApprovalVoidsTheApproval(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->approve();
        $this->setting('publish_channels', 'facebook');
        $this->assertSame('draft_changed', $this->bundle()->publish($this->actionId, 7, $this->now)['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM social_posts"));
    }

    public function testAChannelWithNoConnectedAccountIsSkippedAndSaid(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $this->db->exec("UPDATE social_accounts SET is_active = 0 WHERE platform = 'instagram'");
        $p = $this->approve();
        $this->assertFalse($p['channels']['instagram']['ready']);
        $r = $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->assertTrue($r['ok']);
        $this->assertSame(['instagram'], $r['skipped']);
        $this->assertSame(['website', 'facebook'], $r['channels']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM social_posts"));
        $this->assertStringContainsString('Skipped', $r['note']);
    }

    public function testNothingPublishesWhenConsentIsGoneOrThePhotoIsMissing(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->approve();
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'needed'");
        $this->assertSame('cannot_publish', $this->bundle()->publish($this->actionId, 7, $this->now)['code']);
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'recorded', web_before_path = NULL");
        $this->assertStringContainsString('both a before and an after', (string)$this->bundle()->preview($this->actionId)['why_not']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects") + $this->n("SELECT COUNT(*) FROM social_posts"));
    }

    public function testAFailureWhileQueueingRollsBackTheCardToo(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook');
        $this->approve();
        $this->db->exec("DROP TABLE social_queue");
        $r = $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"), 'no half-published bundle');
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM social_posts"));
        $this->assertSame('approved', $this->actions()->get($this->actionId)['status'], 'the approval is kept so it can be tried again');
    }

    // ---- undo and reconcile ----

    public function testUndoBeforeTheyPostCancelsEveryChannelAndRemovesTheCard(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $this->approve();
        $this->bundle()->publish($this->actionId, 7, $this->now);
        $u = $this->bundle()->undo($this->actionId, 7, $this->now->modify('+3 minutes'));
        $this->assertTrue($u['ok'], json_encode($u));
        $this->assertEqualsCanonicalizing(['facebook', 'instagram'], $u['cancelled']);
        $this->assertSame([], $u['already_posted']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM social_queue WHERE status = 'pending'"));
        $this->assertSame(2, $this->n("SELECT COUNT(*) FROM social_posts WHERE status = 'cancelled'"));
        $this->assertSame('rolled_back', $this->actions()->get($this->actionId)['status']);
    }

    public function testUndoAfterInstagramPostedSaysSoAndStillCancelsWhatIsStillWaiting(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $this->approve();
        $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->db->exec("UPDATE social_queue SET status = 'completed' WHERE platform = 'instagram'");
        $u = $this->bundle()->undo($this->actionId, 7, $this->now->modify('+11 minutes'));
        $this->assertSame(['facebook'], $u['cancelled']);
        $this->assertSame(['instagram'], $u['already_posted']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
    }

    public function testUndoKeepsACardTheOwnerHasEdited(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->approve();
        $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->db->exec("UPDATE portfolio_projects SET description = 'Edited by the owner afterwards.'");
        $u = $this->bundle()->undo($this->actionId, 7, $this->now);
        $this->assertFalse($u['ok']);
        $this->assertSame('kept', $u['website']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
    }

    public function testReconcileMarksQueuedPostsAsPostedOrFailed(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $this->approve();
        $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->db->exec("UPDATE social_post_platforms SET status = 'published', platform_url = 'https://www.facebook.com/123' WHERE platform = 'facebook'");
        $this->db->exec("UPDATE social_post_platforms SET status = 'failed', fail_reason = 'token expired' WHERE platform = 'instagram'");
        $this->assertSame(2, $this->bundle()->reconcile($this->now));
        $x = $this->db->query("SELECT connector, status, verify_note, error FROM mia_executions WHERE connector IN ('facebook','instagram') ORDER BY connector")->fetchAll();
        $this->assertSame('verified', $x[0]['status']);
        $this->assertStringContainsString('https://www.facebook.com/123', $x[0]['verify_note']);
        $this->assertSame('failed', $x[1]['status']);
        $this->assertSame('token expired', $x[1]['error']);
        $this->assertSame(0, $this->bundle()->reconcile($this->now), 'nothing left to change');
    }

    public function testUndoDistinguishesPostedFromJustStartedAndFailed(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $this->approve();
        $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->db->exec("UPDATE social_queue SET status = 'processing' WHERE platform = 'facebook'");
        $this->db->exec("UPDATE social_queue SET status = 'failed' WHERE platform = 'instagram'");
        $u = $this->bundle()->undo($this->actionId, 7, $this->now->modify('+10 minutes'));
        $this->assertSame(['facebook'], $u['in_flight'], 'posting had just started: not reported as posted');
        $this->assertSame([], $u['already_posted']);
        $this->assertSame(['instagram'], $u['cancelled'], 'a failed post never went out');
        $this->assertStringContainsString('just started', (string)$u['error']);
    }

    public function testACancelThatFailsHalfwayChangesNothing(): void
    {
        $this->setting('publish_mode', 'bundle');
        $this->setting('publish_channels', 'facebook,instagram');
        $this->approve();
        $this->bundle()->publish($this->actionId, 7, $this->now);
        $this->db->exec("DROP TABLE social_post_platforms");                          // breaks the second step of the first channel
        $u = $this->bundle()->undo($this->actionId, 7, $this->now);
        $this->assertFalse($u['ok']);
        $this->assertSame(2, $this->n("SELECT COUNT(*) FROM social_queue WHERE status = 'pending'"), 'neither channel was cancelled');
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM portfolio_projects"), 'and the card stays too');
    }

    public function testAnAddressLikePlaceBlocksPublishingAndNeverBecomesAHashtag(): void
    {
        $this->assertTrue(MiaBundleService::isCoarsePlace('Point Grey'));
        $this->assertTrue(MiaBundleService::isCoarsePlace("St. John's"));
        foreach (['123 Cedar Street', '', 'Point Grey 4', '<b>x</b>', str_repeat('a', 80), 'One two three four five'] as $bad) { $this->assertFalse(MiaBundleService::isCoarsePlace($bad), $bad); }
        $this->assertSame([], MiaBundleService::hashtags('123 Cedar Street', '45 Oak'));
        $this->setting('publish_mode', 'bundle');
        $this->approve();
        $this->db->exec("UPDATE ba_pairs SET area = '123 Cedar Street'");
        $p = $this->bundle()->preview($this->actionId);
        $this->assertFalse($p['can_publish']);
        $this->assertStringContainsString('address', (string)$p['why_not']);
        $this->assertSame('cannot_publish', $this->bundle()->publish($this->actionId, 7, $this->now)['code']);
    }
}
