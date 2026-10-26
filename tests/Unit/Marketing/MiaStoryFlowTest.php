<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';

/** The owner's path through the workspace API for a project story: read, edit, approve, save the card, undo. */
final class MiaStoryFlowTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;
    private int $id;

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
        $this->db->exec("INSERT INTO ba_pairs VALUES (10, 2428, 'Hedge Trimming', 'Point Grey', 'garden', 'approved', 'not_needed', '/_media/variants/b.jpg', '/_media/variants/a.jpg')");
        $r = (new MiaActionService($this->db, 1))->propose(['type' => 'project_story', 'side_effect' => 'none', 'opportunity_key' => 'visit:2428', 'title' => 'Finished job could become a project story',
            'why' => 'A completed visit has approved photos.', 'risk' => 'Nothing is published.', 'evidence' => [['pair_id' => 10]], 'evidence_basis' => ['v' => 2428]], $this->now);
        $this->id = (int)$r['id'];
        $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, title, meta_desc, rationale, input_hash, status, version, created_at)
                            VALUES (1, 1, ?, 'story', 'portfolio:pair:10', 'Hedge trimming in Point Grey', 'A tidy hedge trim in Point Grey, finished in September by the Mowology crew.', 'r', 'h', 'draft', 1, ?)")
            ->execute([$this->id, $this->now->format('Y-m-d H:i:s')]);
        (new MiaActionService($this->db, 1))->attachDraft($this->id, 'mia_content_drafts', (int)$this->db->lastInsertId(), 'r', $this->now);
    }

    private function h(): MiaApiHandler { return new MiaApiHandler($this->db, 7, null, null, 1); }
    private function call(string $mode, string $method, array $q = [], array $in = []): array { return $this->h()->handle($mode, $method, $q, $in, $this->now); }

    public function testTheWholePath(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        $this->assertSame(200, $c);
        $this->assertSame('Hedge trimming in Point Grey', $d['item']['draft']['title']);

        [$c, $e] = $this->call('draft_edit', 'POST', [], ['id' => $this->id, 'title' => 'Hedge trimming in Point Grey', 'meta_description' => 'A neat hedge trim in Point Grey, finished in September by the Mowology crew.']);
        $this->assertSame(200, $c, json_encode($e));
        $seen = $e['item']['seen_hash'];

        [$c, $a] = $this->call('action_decide', 'POST', [], ['id' => $this->id, 'decision' => 'approve', 'seen_hash' => $seen]);
        $this->assertSame(200, $c, json_encode($a));

        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->id]);
        $this->assertTrue($d['item']['apply']['can_apply'], json_encode($d['item']['apply']));

        [$c, $ap] = $this->call('action_apply', 'POST', [], ['id' => $this->id]);
        $this->assertSame(200, $c, json_encode($ap));
        $this->assertSame('draft', $this->db->query("SELECT status FROM portfolio_projects")->fetchColumn());
        $this->assertStringContainsString('neat hedge trim', (string)$this->db->query("SELECT description FROM portfolio_projects")->fetchColumn(), 'the edited wording is what was saved');

        $execId = (int)$this->db->query("SELECT id FROM mia_executions")->fetchColumn();
        [$c, $rb] = $this->call('action_rollback', 'POST', [], ['execution_id' => $execId]);
        $this->assertSame(200, $c, json_encode($rb));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM portfolio_projects")->fetchColumn());
    }

    public function testApplyBeforeApprovalCreatesNothing(): void
    {
        [$c, $r] = $this->call('action_apply', 'POST', [], ['id' => $this->id]);
        $this->assertNotSame(200, $c);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM portfolio_projects")->fetchColumn());
    }
}
