<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';

/**
 * Phase 3.2 acceptance: completing a job with consented photos produces at most one evaluated opportunity (dedup),
 * and none if a gate fails.
 */
final class MiaEventServiceTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('now');
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        MiaDemo::schema($this->db);
        $this->db->exec("CREATE TABLE ba_pairs (id INTEGER PRIMARY KEY, visit_id INT, service TEXT, area TEXT, status TEXT, consent_state TEXT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, status TEXT, completed_at TEXT, service_type TEXT)");
        $this->db->exec("CREATE TABLE mia_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, event_type TEXT NOT NULL, subject_key TEXT NOT NULL, payload TEXT,
            status TEXT NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, outcome TEXT, action_id INT, created_at TEXT NOT NULL, processed_at TEXT,
            UNIQUE (site_id, event_type, subject_key))");
    }

    private function visit(int $id, string $pairStatus = 'approved', string $consent = 'not_needed', string $visitStatus = 'completed'): void
    {
        $this->db->prepare("INSERT INTO job_visits (id,status,completed_at,service_type) VALUES (?,?,?,'lawn_care')")->execute([$id, $visitStatus, $this->now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $this->db->prepare("INSERT INTO ba_pairs (visit_id,service,area,status,consent_state) VALUES (?,?,?,?,?)")->execute([$id, 'Garden Care', 'front', $pairStatus, $consent]);
    }

    private function svc(): MiaEventService { return new MiaEventService($this->db, 1); }
    private function n(string $sql): int { return (int)$this->db->query($sql)->fetchColumn(); }

    public function testAVisitWithConsentedApprovedPhotosGivesExactlyOneProposalEvenIfDrainedTwice(): void
    {
        $this->visit(10);
        $a = $this->svc()->drain(20, $this->now);
        $b = $this->svc()->drain(20, $this->now);
        $this->assertSame(1, $a['proposed']);
        $this->assertSame(0, $b['processed'], 'the second run finds nothing pending');
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM mia_events"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM mia_actions WHERE opportunity_key = 'visit:10'"));
        $ev = $this->db->query("SELECT * FROM mia_events")->fetch();
        $this->assertSame('done', $ev['status']);
        $this->assertNotNull($ev['action_id']);
    }

    public function testNoProposalWhenAGateFails(): void
    {
        $this->visit(20, 'pending');                       // not approved
        $this->visit(21, 'approved', 'needed');            // consent missing
        $this->visit(22, 'approved', 'not_needed', 'scheduled');   // visit not completed
        $r = $this->svc()->drain(20, $this->now);
        $this->assertSame(0, $r['swept']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM mia_actions"));
    }

    public function testAGateThatFailsBetweenEmitAndDrainSkipsTheEventAndSaysWhy(): void
    {
        $this->visit(30);
        $this->assertTrue($this->svc()->emit('photos.ready', 'visit:30', ['visit_id' => 30])['queued']);
        $this->db->exec("UPDATE ba_pairs SET consent_state = 'needed' WHERE visit_id = 30");      // consent withdrawn
        $r = $this->svc()->drain(20, $this->now);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM mia_actions"));
        $ev = $this->db->query("SELECT * FROM mia_events")->fetch();
        $this->assertSame('skipped', $ev['status']);
        $this->assertStringContainsString('gate failed', $ev['outcome']);
    }

    public function testTheSameThingIsNeverQueuedTwice(): void
    {
        $this->assertTrue($this->svc()->emit('photos.ready', 'visit:1')['queued']);
        $this->assertFalse($this->svc()->emit('photos.ready', 'visit:1')['queued']);
        $this->assertFalse($this->svc()->emit('', 'visit:1')['ok']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM mia_events"));
    }

    public function testARejectedStoryIsNotProposedAgainForTheSameVisit(): void
    {
        $this->visit(40);
        $this->svc()->drain(20, $this->now);
        $id = (int)$this->db->query("SELECT action_id FROM mia_events")->fetchColumn();
        $this->db->exec("INSERT OR IGNORE INTO users VALUES (7)");
        $this->assertTrue((new MiaActionService($this->db, 1))->decide($id, 7, 'reject', 'not_now', null, null, $this->now)['ok']);
        $this->svc()->drain(20, $this->now->modify('+1 day'));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM mia_actions WHERE opportunity_key = 'visit:40'"), 'the event is spent: one visit, one question');
    }

    public function testPausedMiaProposesNothingAndTheEventWaitsUntilShesBack(): void
    {
        $this->visit(50);
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('mia_paused', '1')");
        $r = $this->svc()->drain(20, $this->now);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM mia_actions"));
        $this->assertSame(1, $r['waiting']);
        $this->assertSame('pending', $this->db->query("SELECT status FROM mia_events")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT attempts FROM mia_events")->fetchColumn(), 'waiting costs no attempt');
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key = 'mia_paused'");
        $this->assertSame(1, $this->svc()->drain(20, $this->now)['proposed']);
    }

    public function testTheProposalCarriesNoNamesOrPromises(): void
    {
        $this->visit(60);
        $this->svc()->drain(20, $this->now);
        $a = $this->db->query("SELECT * FROM mia_actions")->fetch();
        $this->assertSame('project_story', $a['type']);
        $this->assertSame('none', $a['side_effect']);
        $this->assertSame([], MiaActionService::benefitProblems($a['title'] . ' ' . $a['why'] . ' ' . $a['expected_benefit']));
        $this->assertStringContainsString('garden care', $a['why']);
    }

    public function testDrainAllCoversEveryVerifiedSiteAndReportsAnEmptyOne(): void
    {
        $this->assertSame('No verified websites to work on', MiaEventService::drainAll($this->db, $this->now)['summary']);
        $this->db->exec("INSERT INTO mia_websites (id, site_id, base_url, host, status) VALUES (1, 1, 'https://mowology.ca', 'mowology.ca', 'verified')");
        $this->visit(70);
        $r = MiaEventService::drainAll($this->db, $this->now);
        $this->assertSame('success', $r['status']);
        $this->assertStringContainsString('1 proposed', $r['summary']);
    }

    public function testAVisitIsStillProposedWhenManyOtherVisitsQualify(): void
    {
        for ($i = 1; $i <= 230; $i++) { $this->visit(1000 + $i); }
        $r = $this->svc()->drain(100, $this->now);
        $this->assertSame(230, $r['swept'], 'the sweep pages past 200');
        $this->assertSame(0, $r['skipped'], 'no event is rejected for falling outside a row limit');
        $this->svc()->drain(100, $this->now);
        $this->svc()->drain(100, $this->now);
        $this->assertSame(230, $this->n("SELECT COUNT(DISTINCT opportunity_key) FROM mia_actions"));
    }

    public function testACrashAfterSavingTheActionDoesNotCauseASecondQuestionLater(): void
    {
        $this->visit(80);
        $this->svc()->drain(20, $this->now);
        $id = (int)$this->db->query("SELECT action_id FROM mia_events")->fetchColumn();
        $this->db->exec("INSERT OR IGNORE INTO users VALUES (7)");
        (new MiaActionService($this->db, 1))->decide($id, 7, 'reject', 'not_now', null, null, $this->now);
        $this->db->exec("UPDATE mia_events SET status = 'pending', action_id = NULL");        // the run died before marking the event
        $r = $this->svc()->drain(20, $this->now);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM mia_actions WHERE opportunity_key = 'visit:80'"));
        $this->assertSame(1, $r['proposed'] + $r['skipped'], 'the event is closed, not repeated');
    }
}
