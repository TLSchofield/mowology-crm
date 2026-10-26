<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';

/** An approved story becomes an UNPUBLISHED portfolio card, and Undo removes it only while it is untouched. */
final class MiaStoryPublisherTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-26 10:00:00');
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
        $this->db->exec("INSERT INTO job_visits VALUES (2428, 'completed', '2026-09-28 15:11:35')");
        $this->db->exec("INSERT INTO ba_pairs VALUES (10, 2428, 'Hedge Trimming', 'Point Grey', 'garden', 'approved', 'not_needed', '/_media/variants/2026/09/b_1280w.jpg', '/_media/variants/2026/09/a_1280w.jpg')");
    }

    private function actions(): MiaActionService { return new MiaActionService($this->db, 1, function (int $id) { return $id === 7; }); }
    private function pub(): MiaStoryPublisher { return new MiaStoryPublisher($this->db, 1, $this->actions()); }
    private function n(string $sql): int { return (int)$this->db->query($sql)->fetchColumn(); }

    /** @return int action id; approved unless $approve is false */
    private function story(bool $approve = true, string $title = 'Hedge trimming in Point Grey'): int
    {
        $r = $this->actions()->propose(['type' => 'project_story', 'side_effect' => 'none', 'opportunity_key' => 'visit:2428', 'title' => 'Finished job could become a project story',
            'why' => 'A completed visit has approved photos.', 'risk' => 'Nothing is published.', 'evidence' => [['pair_id' => 10]], 'evidence_basis' => ['v' => 2428]], $this->now);
        $id = (int)$r['id'];
        $desc = 'A tidy hedge trim in Point Grey, finished in September by the Mowology crew.';
        $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, title, meta_desc, rationale, input_hash, status, version, created_at)
                            VALUES (1, 1, ?, 'story', 'portfolio:pair:10', ?, ?, 'r', 'h', 'draft', 1, ?)")->execute([$id, $title, $desc, $this->now->format('Y-m-d H:i:s')]);
        $did = (int)$this->db->lastInsertId();
        $this->assertTrue($this->actions()->attachDraft($id, 'mia_content_drafts', $did, 'r', $this->now)['ok']);
        if ($approve) {
            $d = $this->actions()->decide($id, 7, 'approve', null, null, MiaRecommendationWriter::draftText($title, $desc), $this->now);
            $this->assertTrue($d['ok'], json_encode($d));
        }
        return $id;
    }

    public function testApplyingCreatesOneUnpublishedCardWithThePhotosAndNothingElse(): void
    {
        $id = $this->story();
        $r = $this->pub()->apply($id, 7, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $p = $this->db->query("SELECT * FROM portfolio_projects")->fetch();
        $this->assertSame('draft', $p['status'], 'never public');
        $this->assertSame('PORT-2026-0001', $p['project_number']);
        $this->assertSame('Hedge trimming in Point Grey', $p['project_name']);
        $this->assertSame('Point Grey', $p['location']);
        $this->assertSame('["Garden"]', $p['categories']);
        $this->assertSame(['/_media/variants/2026/09/a_1280w.jpg', '/_media/variants/2026/09/b_1280w.jpg'], json_decode($p['gallery_images'], true));
        $this->assertSame('done', $this->actions()->get($id)['status']);
        $x = $this->db->query("SELECT * FROM mia_executions")->fetch();
        $this->assertSame('portfolio', $x['connector']);
        $this->assertSame('portfolio_projects:' . $p['id'], $x['target_ref']);
    }

    public function testNothingIsCreatedUnlessApproved(): void
    {
        $id = $this->story(false);
        $r = $this->pub()->apply($id, 7, $this->now);
        $this->assertSame('not_approved', $r['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
    }

    public function testEditedWordingAfterApprovalVoidsTheApproval(): void
    {
        $id = $this->story();
        $this->db->exec("UPDATE mia_content_drafts SET meta_desc = 'A different description written after approval, mentioning nothing in particular.'");
        $r = $this->pub()->apply($id, 7, $this->now);
        $this->assertSame('draft_changed', $r['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
        $this->assertSame('proposed', $this->actions()->get($id)['status']);
    }

    public function testNoCardWhenConsentIsWithdrawnOrThereIsNoWebPhoto(): void
    {
        $id = $this->story();
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'needed'");
        $this->assertSame('cannot_apply', $this->pub()->apply($id, 7, $this->now)['code']);
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'recorded', web_after_path = NULL");
        $this->assertStringContainsString('web photo', (string)$this->pub()->preview($id)['why_not']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
    }

    public function testImagePathsMustBeSiteMediaOnly(): void
    {
        foreach (['/_media/variants/a.jpg', '/assets/img/projects/x.png'] as $ok) { $this->assertTrue(MiaStoryPublisher::safeImage($ok), $ok); }
        foreach (['https://evil.com/a.jpg', '/_media/../etc/passwd.jpg', '//evil.com/a.jpg', '/_media/a.php', '', 'javascript:x'] as $bad) { $this->assertFalse(MiaStoryPublisher::safeImage($bad), $bad); }
    }

    public function testUndoRemovesAnUntouchedDraftCardAndRecordsIt(): void
    {
        $id = $this->story();
        $r = $this->pub()->apply($id, 7, $this->now);
        $u = $this->pub()->rollback($r['execution_id'], 7, $this->now);
        $this->assertTrue($u['ok'], json_encode($u));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
        $this->assertSame('rolled_back', $this->actions()->get($id)['status']);
        $this->assertSame('rolled_back', $this->db->query("SELECT status FROM mia_executions")->fetchColumn());
        $this->assertSame('not_undoable', $this->pub()->rollback($r['execution_id'], 7, $this->now)['code'], 'twice is refused');
    }

    public function testUndoRefusesOnceTheOwnerPublishedOrEditedTheCard(): void
    {
        $id = $this->story();
        $r = $this->pub()->apply($id, 7, $this->now);
        $this->db->exec("UPDATE portfolio_projects SET status = 'published'");
        $a = $this->pub()->rollback($r['execution_id'], 7, $this->now);
        $this->assertSame('conflict', $a['code']);
        $this->assertStringContainsString('published', $a['error']);
        $this->db->exec("UPDATE portfolio_projects SET status = 'draft', description = 'Edited by the owner.'");
        $b = $this->pub()->rollback($r['execution_id'], 7, $this->now);
        $this->assertSame('conflict', $b['code']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
        $this->assertSame('done', $this->actions()->get($id)['status']);
    }

    public function testNumbersContinueFromTheHighestExistingOne(): void
    {
        $this->db->exec("INSERT INTO portfolio_projects (project_number, project_name, status) VALUES ('PORT-2026-0007', 'x', 'published')");
        $this->pub()->apply($this->story(), 7, $this->now);
        $this->assertSame('PORT-2026-0008', $this->db->query("SELECT project_number FROM portfolio_projects ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testOtherActionTypesAreRefused(): void
    {
        $r = $this->actions()->propose(['type' => 'gbp_post', 'side_effect' => 'none', 'opportunity_key' => 'k', 'title' => 'T', 'why' => 'w', 'risk' => 'r', 'evidence' => [], 'evidence_basis' => ['a' => 1]], $this->now);
        $this->assertSame('not_supported', $this->pub()->apply((int)$r['id'], 7, $this->now)['code']);
    }

    public function testUndoRefusesWhenAnyFieldWasChangedNotJustTheText(): void
    {
        foreach ([["featured = 1"], ["gallery_images = '[\"/_media/other.jpg\"]'"], ["categories = '[\"Lawn\"]'"], ["display_order = 5"], ["tags = '[\"x\"]'"]] as $i => [$set]) {
            $this->setUp();
            $id = $this->story();
            $r = $this->pub()->apply($id, 7, $this->now);
            $this->db->exec("UPDATE portfolio_projects SET $set");
            $this->assertSame('conflict', $this->pub()->rollback($r['execution_id'], 7, $this->now)['code'], $set);
            $this->assertSame(1, $this->n("SELECT COUNT(*) FROM portfolio_projects"), $set);
        }
    }

    public function testNothingIsCreatedIfConsentIsWithdrawnBetweenTheChecks(): void
    {
        $id = $this->story();
        $pub = $this->pub();
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'needed'");
        $this->assertSame('cannot_apply', $pub->apply($id, 7, $this->now)['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM portfolio_projects"));
    }

    public function testTheOwnerGetsLinksToTheCardInThePortfolioPagesUntilItIsGone(): void
    {
        $id = $this->story();
        $r = $this->pub()->apply($id, 7, $this->now);
        $pid = (int)$this->db->query("SELECT id FROM portfolio_projects")->fetchColumn();
        $x = $this->pub()->executionsFor($id)[0];
        $this->assertSame('/crm/portfolio/view.php?id=' . $pid, $x['open_url']);
        $this->assertSame('/crm/portfolio/edit.php?id=' . $pid, $x['edit_url']);
        $this->assertArrayNotHasKey('target_ref', $x);
        $this->pub()->rollback($r['execution_id'], 7, $this->now);
        $this->assertNull($this->pub()->executionsFor($id)[0]['open_url']);
    }
}
