<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** MiaScheduledJobs: once per ISO week, time budget, one failure never stops the rest, causes are named. */
class MiaScheduledJobsTest extends TestCase
{
    private PDO $db;
    private float $t = 0.0;
    private array $calls = [];

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, host TEXT, status TEXT DEFAULT 'verified')");
        $this->db = $db;
        $this->t = 0.0;
        $this->calls = [];
    }

    private function site(string $host, string $status = 'verified', int $site = 1): int
    {
        $this->db->prepare("INSERT INTO mia_websites (site_id, host, status) VALUES (?, ?, ?)")->execute([$site, $host, $status]);
        return (int)$this->db->lastInsertId();
    }

    private function jobs(array $over = []): MiaScheduledJobs
    {
        $f = array_merge([
            'crawl'   => function (int $s, int $w, int $max) { $this->calls[] = ['crawl', $s, $w, $max]; $this->t += 30; return ['ok' => true, 'pages' => 12, 'status' => 'done', 'code' => null, 'error' => null]; },
            'audit'   => function (int $s, int $w, DateTimeImmutable $n) { $this->calls[] = ['audit', $s, $w]; return ['ok' => true, 'total' => 7, 'new' => 3, 'resolved' => 1, 'by_severity' => ['high' => 2], 'code' => null, 'error' => null]; },
            'propose' => function (int $s, int $w, DateTimeImmutable $n) { $this->calls[] = ['propose', $s, $w]; $this->t += 5; return ['proposed' => 4, 'existing' => 1, 'held_back' => 2, 'failed' => 0, 'ids' => []]; },
            'draft'   => function (int $s, int $w, DateTimeImmutable $n) { $this->calls[] = ['draft', $s, $w]; return ['tried' => 2, 'drafted' => 2, 'reused' => 0, 'failed' => 0, 'stopped' => null]; },
            'expire'  => function (int $s, DateTimeImmutable $n) { $this->calls[] = ['expire', $s]; return 3; },
        ], $over);
        return new MiaScheduledJobs($this->db, $f, function () { return $this->t; });
    }

    private function mon(string $d = '2026-10-26 05:30:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($d);
    }

    // ---- audit ----

    public function testAuditWeeklyCrawlsThenChecksEachVerifiedWebsiteOnly(): void
    {
        $a = $this->site('mowology.ca');
        $this->site('pending.example', 'pending');
        $this->site('gone.example', 'disconnected');
        $r = $this->jobs()->auditWeekly($this->mon());
        $this->assertSame('success', $r['status']);
        $this->assertSame(1, $r['websites']);
        $this->assertSame([['crawl', 1, $a, 240], ['audit', 1, $a]], $this->calls);
        $this->assertStringContainsString('mowology.ca: 12 pages (done), 7 findings (2 high), 3 new, 1 resolved', $r['summary']);
    }

    public function testNoVerifiedWebsitesIsASuccessNotAnError(): void
    {
        $this->site('pending.example', 'pending');
        $r = $this->jobs()->auditWeekly($this->mon());
        $this->assertSame('success', $r['status']);
        $this->assertSame('No verified websites to work on', $r['summary']);
        $this->assertSame([], $this->calls);
    }

    public function testEachWebsiteIsDoneOncePerIsoWeekUnlessForced(): void
    {
        $this->site('mowology.ca');
        $this->jobs()->auditWeekly($this->mon());
        $this->calls = [];
        $r = $this->jobs()->auditWeekly($this->mon('2026-10-27 05:30:00'));          // same ISO week
        $this->assertSame([], $this->calls);
        $this->assertSame(1, $r['skipped']);
        $this->assertStringContainsString('1 already done this week', $r['summary']);
        $this->assertSame('success', $r['status']);

        $this->jobs()->auditWeekly($this->mon('2026-10-27 05:30:00'), true);          // forced
        $this->assertNotEmpty($this->calls);

        $this->calls = [];
        $this->jobs()->auditWeekly($this->mon('2026-11-02 05:30:00'));               // next ISO week
        $this->assertNotEmpty($this->calls);
    }

    public function testISOWeekAcrossTheYearBoundaryUsesTheIsoYear(): void
    {
        $this->site('mowology.ca');
        $this->jobs()->auditWeekly(new DateTimeImmutable('2026-12-31 05:30:00'));          // ISO week 2026-W53
        $this->assertSame('2026-W53', $this->db->query("SELECT setting_value FROM ops_settings")->fetchColumn());
        $this->calls = [];
        $this->jobs()->auditWeekly(new DateTimeImmutable('2027-01-01 05:30:00'));          // still 2026-W53
        $this->assertSame([], $this->calls);
    }

    public function testAFailingCrawlIsNotMarkedDoneAndNamesItsReason(): void
    {
        $this->site('mowology.ca');
        $j = $this->jobs(['crawl' => function () { return ['ok' => false, 'code' => 'not_verified', 'error' => 'x', 'pages' => 0, 'status' => null]; }]);
        $r = $j->auditWeekly($this->mon());
        $this->assertSame('error', $r['status'], 'every website failed');
        $this->assertStringContainsString('needs to be verified again', $r['summary']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM ops_settings")->fetchColumn(), 'retry next time');
    }

    public function testOneWebsiteFailingNeverStopsTheOthers(): void
    {
        $a = $this->site('broken.example');
        $b = $this->site('fine.example');
        $j = $this->jobs(['crawl' => function (int $s, int $w, int $max) use ($a) {
            $this->calls[] = ['crawl', $s, $w];
            if ($w === $a) { throw new RuntimeException('connection reset'); }
            return ['ok' => true, 'pages' => 5, 'status' => 'done', 'code' => null, 'error' => null];
        }]);
        $r = $j->auditWeekly($this->mon());
        $this->assertSame('warning', $r['status']);
        $this->assertSame(1, $r['done']);
        $this->assertSame(1, $r['failed']);
        $this->assertStringContainsString('broken.example: RuntimeException: connection reset at MiaScheduledJobsTest.php:', $r['summary']);
        $this->assertSame('mia_audit_week_1_' . $b, $this->db->query("SELECT setting_key FROM ops_settings")->fetchColumn());
    }

    public function testRuleFailureAfterAGoodCrawlIsReportedAndRetried(): void
    {
        $this->site('mowology.ca');
        $r = $this->jobs(['audit' => function () { return ['ok' => false, 'code' => 'error', 'error' => 'The checks could not be completed']; }])->auditWeekly($this->mon());
        $this->assertStringContainsString('checks failed', $r['summary']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM ops_settings")->fetchColumn());
    }

    public function testTimeBudgetDefersTheRestInsteadOfRunningLong(): void
    {
        $ids = [];
        foreach (['a.example', 'b.example', 'c.example', 'd.example', 'e.example'] as $h) { $ids[] = $this->site($h); }
        $j = $this->jobs(['crawl' => function (int $s, int $w, int $max) { $this->calls[] = ['crawl', $s, $w, $max]; $this->t += 100; return ['ok' => true, 'pages' => 1, 'status' => 'done', 'code' => null, 'error' => null]; }]);
        $r = $j->auditWeekly($this->mon());
        $this->assertSame(3, $r['done'], '270 s budget: 100 + 100 + 100 would leave under 60 s');
        $this->assertSame(2, $r['deferred']);
        $this->assertSame('warning', $r['status']);
        $this->assertStringContainsString('2 left for next run', $r['summary']);
        $crawls = array_values(array_filter($this->calls, function ($c) { return $c[0] === 'crawl'; }));
        $this->assertSame([240, 150, 50], array_map(function ($c) { return $c[3]; }, $crawls), 'each crawl is capped to what is left');
        // next run picks up the deferred ones, skips the finished
        $this->calls = [];
        $this->t = 0.0;
        $r2 = $j->auditWeekly($this->mon('2026-10-27 05:30:00'));
        $this->assertSame(2, $r2['done']);
        $this->assertSame(3, $r2['skipped']);
    }

    public function testWebsitesOfSeveralSitesAreEachHandledUnderTheirOwnSite(): void
    {
        $a = $this->site('a.example', 'verified', 1);
        $b = $this->site('b.example', 'verified', 2);
        $this->jobs()->auditWeekly($this->mon());
        $crawls = array_values(array_filter($this->calls, function ($c) { return $c[0] === 'crawl'; }));
        $this->assertSame([[1, $a], [2, $b]], array_map(function ($c) { return [$c[1], $c[2]]; }, $crawls));
    }

    // ---- search ----

    public function testSearchWeeklyProposesDraftsAndExpiresOncePerSite(): void
    {
        $a = $this->site('a.example');
        $b = $this->site('b.example');
        $r = $this->jobs()->searchWeekly($this->mon('2026-10-26 05:55:00'));
        $this->assertSame('success', $r['status']);
        $this->assertSame(2, $r['done']);
        $this->assertSame(['propose', 'draft', 'expire', 'propose', 'draft'], array_map(function ($c) { return $c[0]; }, $this->calls), 'expire runs once per site, after the first website of that site');
        $this->assertStringContainsString('a.example: 4 new proposals (1 already open, 2 held back), 2 drafts written', $r['summary']);
        $this->assertStringContainsString('3 old proposals expired', $r['summary']);
    }

    public function testAiLimitReachedIsAWarningNotAnErrorAndProposalsStillStand(): void
    {
        $this->site('a.example');
        $r = $this->jobs(['draft' => function () { return ['tried' => 1, 'drafted' => 0, 'reused' => 0, 'failed' => 1, 'stopped' => 'cap_month']; }])->searchWeekly($this->mon());
        $this->assertSame('warning', $r['status']);
        $this->assertSame(1, $r['done']);
        $this->assertStringContainsString('AI limit reached (cap_month), plain wording kept', $r['summary']);
    }

    public function testSearchFailureToWorkOutOpportunitiesIsReported(): void
    {
        $this->site('a.example');
        $r = $this->jobs(['propose' => function () { return ['proposed' => 0, 'existing' => 0, 'held_back' => 0, 'failed' => 1, 'ids' => []]; }])->searchWeekly($this->mon());
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('could not be worked out', $r['summary']);
        $this->assertSame([], array_filter($this->calls, function ($c) { return $c[0] === 'draft'; }), 'no drafting when scoring failed');
    }

    public function testSearchAndAuditHaveIndependentWeekMarkers(): void
    {
        $this->site('a.example');
        $this->jobs()->auditWeekly($this->mon());
        $this->calls = [];
        $this->jobs()->searchWeekly($this->mon('2026-10-26 05:55:00'));
        $this->assertNotEmpty($this->calls);
        $keys = $this->db->query("SELECT setting_key FROM ops_settings ORDER BY setting_key")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['mia_audit_week_1_1', 'mia_search_week_1_1'], $keys);
    }

    // ---- helpers ----

    public function testMissingTablesAreReportedPortably(): void
    {
        $j = $this->jobs();
        $this->assertSame(['mia_site_pages', 'mia_site_links', 'mia_audits', 'mia_audit_findings'], $j->missingTables(MiaScheduledJobs::TABLES_AUDIT));
        $this->assertContains('ops_settings', MiaScheduledJobs::TABLES_AUDIT, 'the week marker lives there');
        $this->assertSame([], $j->missingTables(['ops_settings']));
    }

    public function testCauseNamesClassMessageFileAndLine(): void
    {
        $c = MiaScheduledJobs::cause(new LogicException('boom'));
        $this->assertMatchesRegularExpression('/^LogicException: boom at MiaScheduledJobsTest\.php:\d+$/', $c);
    }

    // ---- the real services wired together (no factories) ----

    public function testSearchWeeklyWithTheRealServicesFillsTheQueueEvenWithoutAnAiKey(): void
    {
        $db = $this->db;
        $db->exec("DROP TABLE mia_websites");
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT, mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none',
            capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT, verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200, robots_hash TEXT, created_by INT, created_at TEXT)");
        $db->exec("INSERT INTO mia_websites (site_id, base_url, host, status, verify_token, verified_via, verified_at) VALUES (1, 'https://mowology.ca', 'mowology.ca', 'verified', 'tok', 'dns', '" . date('Y-m-d H:i:s') . "')");
        $db->exec("CREATE TABLE mia_site_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, url_hash TEXT, url TEXT, title TEXT, meta_desc TEXT, h1 TEXT, last_seen_at TEXT)");
        $db->exec("INSERT INTO mia_site_pages (site_id, website_id, url_hash, url, last_seen_at) VALUES (1, 1, 'h', 'https://mowology.ca/a', '" . date('Y-m-d H:i:s') . "')");
        $db->exec("CREATE TABLE mia_audit_findings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, audit_id INT, page_id INT, rule_key TEXT, severity TEXT, evidence TEXT, fingerprint TEXT, first_seen_audit_id INT, resolved_at TEXT)");
        $db->exec("INSERT INTO mia_audit_findings (site_id, website_id, rule_key, severity, evidence, fingerprint, page_id) VALUES (1, 1, 'title_missing', 'high', 'No title', 'f1', 1)");
        $db->exec("CREATE TABLE mia_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, mem_key TEXT, mem_value TEXT, source TEXT DEFAULT 'owner', updated_by INT, updated_at TEXT, UNIQUE(site_id, kind, mem_key))");
        $db->exec("CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, opportunity_key TEXT, type TEXT, side_effect TEXT, status TEXT NOT NULL DEFAULT 'proposed', title TEXT, why TEXT,
            expected_benefit TEXT, risk TEXT, evidence TEXT, score REAL, score_inputs TEXT, evidence_hash TEXT, draft_ref_table TEXT, draft_ref_id INT, draft_hash TEXT, preview_url TEXT, autonomy_level INT DEFAULT 1,
            proposed_by TEXT DEFAULT 'mia', proposed_at TEXT, decided_by INT, decided_at TEXT, decision_note TEXT, executed_at TEXT, result TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_action_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, action_id INT, event TEXT, actor TEXT, detail TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, subject_key TEXT, action_type TEXT, evidence_hash TEXT, decision TEXT, reason_code TEXT, reason_text TEXT, decided_by INT, decided_at TEXT, suppress_until TEXT)");
        $db->exec("CREATE TABLE mia_llm_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, task TEXT, model TEXT, input_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0, cost_usd REAL DEFAULT 0, ok INT DEFAULT 1, error TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_content_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, action_id INT, kind TEXT, page_url TEXT, current_title TEXT, current_desc TEXT,
            title TEXT, meta_desc TEXT, rationale TEXT, input_hash TEXT, status TEXT DEFAULT 'draft', version INT DEFAULT 1, created_at TEXT)");
        $db->exec("CREATE TABLE gsc_properties (id INTEGER PRIMARY KEY AUTOINCREMENT, site_url TEXT)");
        $db->exec("CREATE TABLE gsc_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INT, snapshot_date TEXT)");
        $db->exec("CREATE TABLE gsc_query_page_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id INT, query TEXT, page TEXT, clicks INT, impressions INT, ctr REAL, position REAL)");

        $r = (new MiaScheduledJobs($db))->searchWeekly(new DateTimeImmutable('2026-10-26 05:55:00'));
        $this->assertContains($r['status'], ['success', 'warning'], $r['summary']);
        $this->assertSame(1, $r['done'], $r['summary']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM mia_actions WHERE status = 'proposed'")->fetchColumn(), 'the audit finding became a proposal');
        $this->assertSame('audit:1:title_missing', $db->query("SELECT opportunity_key FROM mia_actions")->fetchColumn());
        $this->assertSame('mia_search_week_1_1', $db->query("SELECT setting_key FROM ops_settings")->fetchColumn());
        $again = (new MiaScheduledJobs($db))->searchWeekly(new DateTimeImmutable('2026-10-27 05:55:00'));
        $this->assertSame(1, $again['skipped']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn());
    }

    // ---- Codex round 1 regressions ----

    public function testAllAttemptedWebsitesFailingIsAnErrorEvenWhenOthersWereSkipped(): void
    {
        $this->site('done.example');
        $this->site('bad.example');
        $this->jobs()->auditWeekly($this->mon());
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key LIKE '%\_1\_2' ESCAPE '\\'");     // only the second site is due again
        $r = $this->jobs(['crawl' => function () { throw new RuntimeException('down'); }])->auditWeekly($this->mon('2026-10-27 05:30:00'));
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame('error', $r['status']);
    }

    public function testPartiallySavedProposalsAreRetriedNotMarkedDone(): void
    {
        $this->site('a.example');
        $r = $this->jobs(['propose' => function () { return ['proposed' => 1, 'existing' => 0, 'held_back' => 0, 'failed' => 3, 'ids' => [1]]; }])->searchWeekly($this->mon());
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('3 proposals could not be saved', $r['summary']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM ops_settings")->fetchColumn());
    }

    public function testWeekMarkerSurvivesAnInsertRace(): void
    {
        $this->site('a.example');
        // another process wins the INSERT between our check and our insert
        $this->db->exec("CREATE TRIGGER race BEFORE INSERT ON ops_settings WHEN NEW.setting_key = 'mia_audit_week_1_1' BEGIN SELECT RAISE(IGNORE); END");
        $r = $this->jobs()->auditWeekly($this->mon());
        $this->assertSame(1, $r['done'], 'a lost race on the marker must not turn finished work into a failure');
        $this->assertSame('success', $r['status']);
    }
}
