<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** MiaApiHandler: the approval queue and website modes. SQLite + fake network; the user id is always the session's. */
class MiaApiHandlerTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;
    /** @var string[] TXT records the fake DNS returns */
    private array $txt = [];
    private const TOKEN = 'aabbccddeeff00112233445566778899';
    private const PAGE = 'https://mowology.ca/services/strata-landscaping';

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-26 09:00:00');
        $this->txt = [];
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY)");
        $db->exec("INSERT INTO users VALUES (7), (8)");
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT, mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none',
            capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT, verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200,
            robots_hash TEXT, created_by INT, created_at TEXT, UNIQUE(site_id, host))");
        $db->exec("CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, opportunity_key TEXT, type TEXT, side_effect TEXT, status TEXT NOT NULL DEFAULT 'proposed',
            title TEXT, why TEXT, expected_benefit TEXT, risk TEXT, evidence TEXT, score REAL, score_inputs TEXT, evidence_hash TEXT, draft_ref_table TEXT, draft_ref_id INT, draft_hash TEXT, preview_url TEXT,
            autonomy_level INT DEFAULT 1, proposed_by TEXT DEFAULT 'mia', proposed_at TEXT, decided_by INT, decided_at TEXT, decision_note TEXT, executed_at TEXT, result TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_action_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, action_id INT, event TEXT, actor TEXT, detail TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, subject_key TEXT, action_type TEXT, evidence_hash TEXT, decision TEXT, reason_code TEXT, reason_text TEXT, decided_by INT, decided_at TEXT, suppress_until TEXT)");
        $db->exec("CREATE TABLE mia_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, mem_key TEXT, mem_value TEXT, source TEXT DEFAULT 'owner', updated_by INT, updated_at TEXT, UNIQUE(site_id, kind, mem_key))");
        $db->exec("CREATE TABLE mia_site_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, url_hash TEXT, url TEXT, status_code INT)");
        $db->exec("CREATE TABLE mia_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, kind TEXT, status TEXT, pages INT, findings INT, started_at TEXT, finished_at TEXT, summary TEXT)");
        $db->exec("CREATE TABLE mia_audit_findings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, audit_id INT, page_id INT, rule_key TEXT, severity TEXT, evidence TEXT, fingerprint TEXT, first_seen_audit_id INT, resolved_at TEXT)");
        $db->exec("CREATE TABLE gsc_properties (id INTEGER PRIMARY KEY AUTOINCREMENT, site_url TEXT)");
        $db->exec("CREATE TABLE gsc_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INT, snapshot_date TEXT)");
        $db->exec("CREATE TABLE mia_content_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, action_id INT, kind TEXT, page_url TEXT, current_title TEXT, current_desc TEXT,
            title TEXT, meta_desc TEXT, rationale TEXT, input_hash TEXT, status TEXT DEFAULT 'draft', version INT DEFAULT 1, created_at TEXT)");
        $this->db = $db;
    }

    private function h(int $user = 7, int $site = 1): MiaApiHandler
    {
        $fetcher = new SafeHttpFetcher([], function (string $host) { return ['mowology.ca' => ['93.184.216.34'], 'evil.example' => ['10.0.0.9'], 'other.example' => ['8.8.8.8']][$host] ?? []; },
            function (string $url) { return ['status' => 404, 'headers' => [], 'body' => '']; });
        $ws = new MiaWebsiteService($db = $this->db, $site, $fetcher, function () { return $this->txt; }, function () { return self::TOKEN; });
        return new MiaApiHandler($this->db, $user, $ws, new MiaActionService($this->db, $site), $site);
    }

    /** @return array{0:int,1:array} */
    private function get(string $mode, array $q = [], int $user = 7, int $site = 1): array
    {
        return $this->h($user, $site)->handle($mode, 'GET', $q, [], $this->now);
    }

    /** @return array{0:int,1:array} */
    private function post(string $mode, array $in, int $user = 7, int $site = 1): array
    {
        return $this->h($user, $site)->handle($mode, 'POST', [], $in, $this->now);
    }

    private function proposal(array $over = [], bool $withDraft = true): int
    {
        $svc = new MiaActionService($this->db, 1);
        $r = $svc->propose(array_merge([
            'type' => 'seo_meta_update', 'side_effect' => 'none', 'opportunity_key' => 'page:abc', 'title' => 'Rewrite the title and description of /services/strata-landscaping',
            'why' => 'People saw this page 800 times but only 8 clicked.', 'expected_benefit' => 'Estimate: roughly 20 to 60 extra visits a month. This is an estimate, not a promise.',
            'risk' => 'Changing the title changes what Google shows.', 'evidence' => [['page' => self::PAGE, 'impressions' => 800]], 'evidence_basis' => ['k' => 1], 'score' => 55.5,
        ], $over), $this->now);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $id = (int)$r['id'];
        if ($withDraft) {
            $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, current_title, current_desc, title, meta_desc, rationale, input_hash, status, version, created_at)
                                VALUES (1, 1, ?, 'seo_meta', ?, 'Strata Landscaping', 'Old description.', 'Strata Landscaping and Grounds Care in Vancouver', 'Regular landscaping and grounds care for strata corporations in Vancouver. Ask for a quote today.', 'A clearer title may help.', 'h', 'draft', 1, '2026-10-25')")
                ->execute([$id, self::PAGE]);
            $this->assertTrue($svc->attachDraft($id, 'mia_content_drafts', (int)$this->db->lastInsertId(), 'A clearer title may help.', $this->now)['ok']);
        }
        return $id;
    }

    private function seen(int $id): string
    {
        return $this->get('action_detail', ['id' => $id])[1]['item']['seen_hash'];
    }

    // ---- plumbing ----

    public function testModesAndMethods(): void
    {
        $this->assertTrue(MiaApiHandler::handles('actions'));
        $this->assertTrue(MiaApiHandler::handles('action_decide'));
        $this->assertFalse(MiaApiHandler::handles('decide'), 'the email desk keeps its own decide');
        $this->assertFalse(MiaApiHandler::handles('stats'));
        $this->assertSame(405, $this->get('action_decide')[0]);
        $this->assertSame(405, $this->post('actions', [])[0]);
        $this->assertSame(405, $this->h()->handle('actions', 'DELETE', [], [])[0]);
    }

    public function testNoUserMeansForbiddenAndNothingIsRead(): void
    {
        $this->assertSame(403, $this->get('actions', [], 0)[0]);
        $this->assertSame(403, $this->post('action_decide', ['id' => 1, 'decision' => 'reject'], 0)[0]);
    }

    public function testMissingMigrationsGiveAClearNotReadyAnswer(): void
    {
        $this->db->exec("DROP TABLE mia_content_drafts");
        list($http, $body) = $this->get('actions');
        $this->assertSame(503, $http);
        $this->assertSame('not_ready', $body['code']);
        $this->assertStringContainsString('mia_content_drafts', $body['error']);
    }

    // ---- websites ----

    public function testConnectListVerifyDisconnectFlow(): void
    {
        list($http, $b) = $this->post('connect_website', ['address' => 'Mowology.ca/about']);
        $this->assertSame(200, $http, json_encode($b));
        $this->assertFalse($b['existing']);
        $this->assertStringContainsString(self::TOKEN, $b['instructions']['meta']['snippet']);
        $id = $b['id'];

        list(, $l) = $this->get('websites');
        $this->assertSame('pending', $l['websites'][0]['status']);
        $this->assertFalse($l['websites'][0]['can_crawl']);
        $this->assertArrayHasKey('instructions', $l['websites'][0]);

        list($h2, $v) = $this->post('verify_website', ['id' => $id]);
        $this->assertSame(422, $h2);
        $this->assertFalse($v['verified']);

        $this->txt = ['ittai-mia=' . self::TOKEN];
        $later = (new DateTimeImmutable('2026-10-26 09:05:00'));
        list($h3, $v2) = $this->h()->handle('verify_website', 'POST', [], ['id' => $id], $later);
        $this->assertSame(200, $h3, json_encode($v2));
        $this->assertSame('dns', $v2['method']);
        list(, $l2) = $this->get('websites');
        $this->assertSame('verified', $l2['websites'][0]['status']);
        $this->assertArrayNotHasKey('instructions', $l2['websites'][0], 'the proof is not shown once verified');
        $this->assertStringNotContainsString(self::TOKEN, json_encode($l2));

        $this->assertSame(200, $this->post('disconnect_website', ['id' => $id])[0]);
        $this->assertSame([], $this->get('websites')[1]['websites']);
        $this->assertSame(404, $this->post('disconnect_website', ['id' => 999])[0]);
    }

    public function testVerifyIsRateLimitedPerWebsite(): void
    {
        $id = $this->post('connect_website', ['address' => 'mowology.ca'])[1]['id'];
        $this->assertSame(422, $this->post('verify_website', ['id' => $id])[0]);
        list($http, $b) = $this->post('verify_website', ['id' => $id]);      // same second
        $this->assertSame(429, $http);
        $this->assertSame('too_soon', $b['code']);
        $this->assertSame(422, $this->h()->handle('verify_website', 'POST', [], ['id' => $id], $this->now->modify('+11 seconds'))[0], 'allowed again after the cooldown');
    }

    public function testConnectRefusesBadAddressesAndTooManyWebsites(): void
    {
        foreach (['', 'localhost', '10.0.0.1', 'evil.example', 'not a url'] as $addr) {
            $this->assertSame(422, $this->post('connect_website', ['address' => $addr])[0], $addr);
        }
        $this->assertSame(422, $this->post('connect_website', ['address' => ['x']])[0], 'non-string address');
        for ($i = 0; $i < MiaApiHandler::MAX_WEBSITES; $i++) {
            $this->db->prepare("INSERT INTO mia_websites (site_id, base_url, host, status, verify_token) VALUES (1, ?, ?, 'pending', 't')")->execute(["https://s$i.example", "s$i.example"]);
        }
        list($http, $b) = $this->post('connect_website', ['address' => 'mowology.ca']);
        $this->assertSame(422, $http);
        $this->assertSame('too_many', $b['code']);
    }

    public function testWebsitesOfAnotherSiteAreInvisibleAndUntouchable(): void
    {
        $id = $this->post('connect_website', ['address' => 'mowology.ca'])[1]['id'];
        $this->assertSame([], $this->get('websites', [], 7, 2)[1]['websites']);
        $this->assertSame(404, $this->post('verify_website', ['id' => $id], 7, 2)[0]);
        $this->assertSame(404, $this->post('disconnect_website', ['id' => $id], 7, 2)[0]);
    }

    // ---- the queue ----

    public function testActionsListShowsDraftWordingAndHashes(): void
    {
        $id = $this->proposal();
        $this->proposal(['opportunity_key' => 'audit:1:meta_missing', 'type' => 'fix_technical', 'title' => 'Add a description to 5 pages', 'evidence' => [['rule' => 'meta_missing']], 'score' => 30.0], false);
        list($http, $b) = $this->get('actions');
        $this->assertSame(200, $http);
        $this->assertSame('proposed', $b['status']);
        $this->assertCount(2, $b['items']);
        $this->assertSame($id, $b['items'][0]['id'], 'best score first');
        $it = $b['items'][0];
        $this->assertSame('Page title and description', $it['type_label']);
        $this->assertSame('Strata Landscaping and Grounds Care in Vancouver', $it['draft']['title']);
        $this->assertSame('Strata Landscaping', $it['draft']['current_title']);
        $this->assertSame(sha1("TITLE: Strata Landscaping and Grounds Care in Vancouver\nDESCRIPTION: Regular landscaping and grounds care for strata corporations in Vancouver. Ask for a quote today."), $it['seen_hash']);
        $this->assertNull($b['items'][1]['draft']);
        $this->assertNotSame('', $b['items'][1]['seen_hash']);
        $this->assertSame(2, $b['counts']['proposed']);
        $this->assertArrayNotHasKey('evidence_hash', $it);
        $this->assertArrayNotHasKey('draft_hash', $it);
    }

    public function testStatusFilterAndUnknownStatus(): void
    {
        $id = $this->proposal();
        $this->assertSame(422, $this->get('actions', ['status' => 'bogus'])[0]);
        $this->assertSame(422, $this->get('actions', ['status' => ['x']])[0]);
        $this->assertCount(0, $this->get('actions', ['status' => 'approved'])[1]['items']);
        $this->post('action_decide', ['id' => $id, 'decision' => 'reject', 'reason_code' => 'bad_timing']);
        $this->assertCount(0, $this->get('actions')[1]['items']);
        $this->assertCount(1, $this->get('actions', ['status' => 'rejected'])[1]['items']);
        $this->assertCount(1, $this->get('actions', ['status' => 'all'])[1]['items']);
    }

    public function testActionDetailHasEvidenceEventsAndRefusesForeignOrMissing(): void
    {
        $id = $this->proposal();
        list($http, $b) = $this->get('action_detail', ['id' => $id]);
        $this->assertSame(200, $http);
        $this->assertSame(800, $b['item']['evidence'][0]['impressions']);
        $this->assertContains('proposed', array_column($b['item']['events'], 'event'));
        $this->assertSame(404, $this->get('action_detail', ['id' => 999])[0]);
        $this->assertSame(404, $this->get('action_detail', ['id' => $id], 7, 2)[0], 'another site');
        $this->assertSame(404, $this->get('action_detail', [])[0]);
    }

    // ---- editing and deciding ----

    public function testOwnerEditChangesTheDraftAuditsItAndChangesTheSeenHash(): void
    {
        $id = $this->proposal();
        $old = $this->seen($id);
        list($http, $b) = $this->post('draft_edit', ['id' => $id, 'title' => 'Strata Grounds Care in Vancouver', 'meta_description' => 'Landscaping and grounds care for strata corporations across Vancouver. Request a quote.']);
        $this->assertSame(200, $http, json_encode($b));
        $this->assertSame('Strata Grounds Care in Vancouver', $b['item']['draft']['title']);
        $this->assertNotSame($old, $b['item']['seen_hash']);
        $events = (new MiaActionService($this->db, 1))->events($id);
        $this->assertSame('edited', end($events)['event']);
        $this->assertSame('user:7', end($events)['actor']);
        $this->assertSame(200, $this->post('draft_edit', ['id' => $id, 'title' => 'Strata Grounds Care in Vancouver', 'meta_description' => 'Landscaping and grounds care for strata corporations across Vancouver. Request a quote.'])[1]['unchanged'] ? 200 : 0);
    }

    /** @dataProvider badEdits */
    public function testBadEditsAreRefused(array $in): void
    {
        $id = $this->proposal();
        $before = $this->seen($id);
        list($http, $b) = $this->post('draft_edit', array_merge(['id' => $id], $in));
        $this->assertSame(422, $http, json_encode($b));
        $this->assertSame($before, $this->seen($id));
    }

    public function badEdits(): array
    {
        return [
            'short title' => [['title' => 'Hi', 'meta_description' => str_repeat('d', 60)]],
            'long title'  => [['title' => str_repeat('t', 121), 'meta_description' => str_repeat('d', 60)]],
            'short desc'  => [['title' => 'Fine title here', 'meta_description' => 'short']],
            'long desc'   => [['title' => 'Fine title here', 'meta_description' => str_repeat('d', 321)]],
            'html'        => [['title' => 'Fine <b>title</b> here', 'meta_description' => str_repeat('d', 60)]],
            'control'     => [['title' => "Fine title\x00 here", 'meta_description' => str_repeat('d', 60)]],
            'not strings' => [['title' => ['a'], 'meta_description' => 5]],
            'missing'     => [[]],
        ];
    }

    public function testEditRefusedWhenNoDraftOrAlreadyDecidedOrForeign(): void
    {
        $noDraft = $this->proposal(['opportunity_key' => 'k2'], false);
        $this->assertSame('no_draft', $this->post('draft_edit', ['id' => $noDraft, 'title' => 'Fine title here', 'meta_description' => str_repeat('d', 60)])[1]['code']);
        $id = $this->proposal(['opportunity_key' => 'k3']);
        $this->post('action_decide', ['id' => $id, 'decision' => 'reject']);
        $this->assertSame(409, $this->post('draft_edit', ['id' => $id, 'title' => 'Fine title here', 'meta_description' => str_repeat('d', 60)])[0]);
        $id2 = $this->proposal(['opportunity_key' => 'k4']);
        $this->assertSame(404, $this->post('draft_edit', ['id' => $id2, 'title' => 'Fine title here', 'meta_description' => str_repeat('d', 60)], 7, 2)[0]);
    }

    public function testApproveNeedsTheHashOfWhatWasShown(): void
    {
        $id = $this->proposal();
        foreach ([[], ['seen_hash' => ''], ['seen_hash' => 'deadbeef'], ['seen_hash' => ['x']]] as $extra) {
            list($http, $b) = $this->post('action_decide', array_merge(['id' => $id, 'decision' => 'approve'], $extra));
            $this->assertSame(409, $http);
            $this->assertSame('changed', $b['code']);
            $this->assertSame('proposed', $this->get('action_detail', ['id' => $id])[1]['item']['status']);
        }
    }

    public function testApproveBindsTheDraftTextAndTheSessionUser(): void
    {
        $id = $this->proposal();
        list($http, $b) = $this->post('action_decide', ['id' => $id, 'decision' => 'approve', 'seen_hash' => $this->seen($id), 'decided_by' => 8, 'user_id' => 8], 7);
        $this->assertSame(200, $http, json_encode($b));
        $this->assertSame('approved', $b['item']['status']);
        $this->assertStringContainsString('only records your decision', $b['message']);
        $svc = new MiaActionService($this->db, 1);
        $row = $svc->get($id);
        $this->assertSame(7, (int)$row['decided_by'], 'the user id comes from the session, not the request');
        $draft = $this->db->query("SELECT title, meta_desc FROM mia_content_drafts")->fetch();
        $this->assertTrue($svc->verifyApproval($id, MiaRecommendationWriter::draftText($draft['title'], $draft['meta_desc']))['ok']);
    }

    public function testAnEditInAnotherTabMakesTheOldHashStale(): void
    {
        $id = $this->proposal();
        $stale = $this->seen($id);
        $this->post('draft_edit', ['id' => $id, 'title' => 'Strata Grounds Care in Vancouver', 'meta_description' => 'Landscaping and grounds care for strata corporations across Vancouver. Request a quote.']);
        list($http, $b) = $this->post('action_decide', ['id' => $id, 'decision' => 'approve', 'seen_hash' => $stale]);
        $this->assertSame(409, $http);
        $this->assertSame('Strata Grounds Care in Vancouver', $b['item']['draft']['title'], 'the response carries the current wording to look at again');
        $this->assertSame(200, $this->post('action_decide', ['id' => $id, 'decision' => 'approve', 'seen_hash' => $b['item']['seen_hash']])[0]);
    }

    public function testApprovingAProposalWithoutADraftBindsWhatTheOwnerRead(): void
    {
        $id = $this->proposal(['opportunity_key' => 'audit:1:meta_missing', 'type' => 'fix_technical', 'title' => 'Add a description to 5 pages', 'evidence' => [['rule' => 'x']]], false);
        $seen = $this->seen($id);
        $this->assertSame(200, $this->post('action_decide', ['id' => $id, 'decision' => 'approve', 'seen_hash' => $seen])[0]);
        $svc = new MiaActionService($this->db, 1);
        $a = $svc->get($id);
        $text = "ACTION: {$a['title']}\nWHY: {$a['why']}\nBENEFIT: {$a['expected_benefit']}\nRISK: {$a['risk']}";
        $this->assertTrue($svc->verifyApproval($id, $text)['ok']);
    }

    public function testRejectRemembersWhyAndStopsTheIdeaReturning(): void
    {
        $id = $this->proposal();
        list($http, $b) = $this->post('action_decide', ['id' => $id, 'decision' => 'reject', 'reason_code' => 'not_our_voice', 'reason_text' => 'Too salesy']);
        $this->assertSame(200, $http);
        $this->assertSame('rejected', $b['item']['status']);
        $this->assertSame('Too salesy', $b['item']['decision_note']);
        $again = (new MiaActionService($this->db, 1))->propose(['type' => 'seo_meta_update', 'side_effect' => 'none', 'opportunity_key' => 'page:abc', 'title' => 'Rewrite the title and description',
            'why' => 'x', 'risk' => 'y', 'evidence' => [['page' => self::PAGE, 'impressions' => 800]], 'evidence_basis' => ['k' => 1]], $this->now);
        $this->assertSame('suppressed_rejected', $again['code']);
    }

    public function testLaterSnoozesAndBadInputIsRefused(): void
    {
        $id = $this->proposal();
        $this->assertSame(200, $this->post('action_decide', ['id' => $id, 'decision' => 'later'])[0]);
        $this->assertSame('expired', $this->get('action_detail', ['id' => $id])[1]['item']['status']);
        $id2 = $this->proposal(['opportunity_key' => 'k2']);
        foreach (['publish', '', 5, null] as $d) { $this->assertSame(422, $this->post('action_decide', ['id' => $id2, 'decision' => $d])[0]); }
        $this->assertSame(404, $this->post('action_decide', ['id' => 999, 'decision' => 'reject'])[0]);
        $this->assertSame(404, $this->post('action_decide', ['decision' => 'reject'])[0]);
    }

    public function testCannotDecideTwiceOrAcrossSites(): void
    {
        $id = $this->proposal();
        $this->assertSame(200, $this->post('action_decide', ['id' => $id, 'decision' => 'reject'])[0]);
        $this->assertSame(409, $this->post('action_decide', ['id' => $id, 'decision' => 'reject'])[0]);
        $id2 = $this->proposal(['opportunity_key' => 'k2']);
        $this->assertSame(404, $this->post('action_decide', ['id' => $id2, 'decision' => 'reject'], 7, 2)[0]);
        $this->assertSame('proposed', $this->get('action_detail', ['id' => $id2])[1]['item']['status']);
    }

    public function testAUserIdThatIsNotARealUserCannotApprove(): void
    {
        $id = $this->proposal();
        list($http, $b) = $this->post('action_decide', ['id' => $id, 'decision' => 'reject'], 999);
        $this->assertSame(403, $http);
        $this->assertSame('no_user', $b['code']);
        $this->assertSame('proposed', $this->get('action_detail', ['id' => $id])[1]['item']['status']);
    }

    public function testNothingInTheHandlerCanPublish(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Marketing/Services/MiaApiHandler.php');
        foreach (['curl_', 'mail(', 'sendCrmEmail', 'sendSms', 'file_put_contents', 'CmsConnector'] as $bad) { $this->assertStringNotContainsString($bad, $src, $bad); }
    }

    // ---- Codex round 1 regressions ----

    public function testIdsMustBePlainPositiveIntegers(): void
    {
        $this->assertSame(0, MiaApiHandler::id(['x']));
        $this->assertSame(0, MiaApiHandler::id('1; DROP'));
        $this->assertSame(0, MiaApiHandler::id('-1'));
        $this->assertSame(0, MiaApiHandler::id(1.5));
        $this->assertSame(0, MiaApiHandler::id('01'));
        $this->assertSame(0, MiaApiHandler::id(null));
        $this->assertSame(7, MiaApiHandler::id('7'));
        $this->assertSame(7, MiaApiHandler::id(7));
        $id = $this->proposal();                                  // action id 1
        $this->assertSame(404, $this->post('action_decide', ['id' => ['not-an-id'], 'decision' => 'reject'])[0], 'an array must not become id 1');
        $this->assertSame(404, $this->get('action_detail', ['id' => ['x']])[0]);
        $this->post('connect_website', ['address' => 'mowology.ca']);
        $this->assertSame(404, $this->post('disconnect_website', ['id' => ['x']])[0]);
        $this->assertSame(1, count($this->get('websites')[1]['websites']), 'website 1 was not disconnected by a malformed id');
        $this->assertSame('proposed', $this->get('action_detail', ['id' => $id])[1]['item']['status']);
    }

    public function testVerifyAttemptCanOnlyBeClaimedOncePerCooldown(): void
    {
        $id = $this->post('connect_website', ['address' => 'mowology.ca'])[1]['id'];
        $ws = new MiaWebsiteService($this->db, 1);
        $this->assertTrue($ws->claimVerifyAttempt($id, $this->now, 10));
        $this->assertFalse($ws->claimVerifyAttempt($id, $this->now, 10), 'the second of two parallel requests loses');
        $this->assertFalse($ws->claimVerifyAttempt($id, $this->now->modify('+9 seconds'), 10));
        $this->assertTrue($ws->claimVerifyAttempt($id, $this->now->modify('+10 seconds'), 10));
        $this->assertFalse((new MiaWebsiteService($this->db, 2))->claimVerifyAttempt($id, $this->now->modify('+1 hour'), 10), 'another site');
    }

    public function testApprovalIsRefusedIfTheWordingChangesInsideTheDecisionTransaction(): void
    {
        $id = $this->proposal();
        $svc = new MiaActionService($this->db, 1);
        $shown = MiaRecommendationWriter::draftText('Strata Landscaping and Grounds Care in Vancouver', 'Regular landscaping and grounds care for strata corporations in Vancouver. Ask for a quote today.');
        // simulate the race: between the browser's check and the stored approval, another request edits the draft
        $r = $svc->decide($id, 7, 'approve', null, null, $shown, $this->now, function (array $row) {
            return MiaRecommendationWriter::draftText('Edited title in another tab', 'Edited description in another tab that is long enough.');
        });
        $this->assertFalse($r['ok']);
        $this->assertSame('changed', $r['code']);
        $this->assertSame('proposed', $svc->get($id)['status']);
        $this->assertSame([], array_filter($svc->events($id), function ($e) { return $e['event'] === 'approved'; }));
        $ok = $svc->decide($id, 7, 'approve', null, null, $shown, $this->now, function (array $row) use ($shown) { return $shown; });
        $this->assertTrue($ok['ok']);
    }

    public function testHandlerPassesTheFreshReadIntoTheApproval(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Marketing/Services/MiaApiHandler.php');
        $this->assertStringContainsString('$fresh', $src);
        $this->assertStringContainsString('lockForEdit', $src, 'the edit takes the same lock the approval holds');
        $this->assertNotNull((new MiaActionService($this->db, 1))->lockForEdit($this->proposal()));
        $this->assertNull((new MiaActionService($this->db, 2))->lockForEdit(1));
    }

    // ---- health (Presence tab) ----

    public function testHealthSummarisesCrawlFindingsAndSearchConsole(): void
    {
        $id = $this->post('connect_website', ['address' => 'mowology.ca'])[1]['id'];
        $this->db->exec("UPDATE mia_websites SET status = 'verified', verified_at = '" . $this->now->format('Y-m-d H:i:s') . "', verified_via = 'dns'");
        $this->db->exec("INSERT INTO mia_site_pages (site_id, website_id, url_hash, url, status_code) VALUES (1, $id, 'a', 'u1', 200), (1, $id, 'b', 'u2', 200), (1, $id, 'c', 'u3', 404), (2, $id, 'd', 'u4', 200)");
        $this->db->exec("INSERT INTO mia_audits (site_id, website_id, kind, status, started_at, finished_at, summary) VALUES (1, $id, 'crawl', 'done', '2026-10-26 05:30:00', '2026-10-26 05:34:00', '2 pages fetched'), (1, $id, 'rules', 'done', '2026-10-26 05:35:00', '2026-10-26 05:35:01', 'x')");
        foreach ([['title_missing', 'high', 0], ['meta_missing', 'medium', 0], ['meta_missing', 'medium', 0], ['title_long', 'low', 0], ['site_stale', 'info', 0], ['meta_missing', 'medium', 1]] as $i => $f) {
            $this->db->exec("INSERT INTO mia_audit_findings (site_id, website_id, rule_key, severity, evidence, fingerprint, resolved_at) VALUES (1, $id, '{$f[0]}', '{$f[1]}', 'e', 'f$i', " . ($f[2] ? "'2026-10-01'" : 'NULL') . ")");
        }
        $this->db->exec("INSERT INTO gsc_properties (site_url) VALUES ('sc-domain:mowology.ca'), ('sc-domain:other.ca')");
        $this->db->exec("INSERT INTO gsc_snapshots (property_id, snapshot_date) VALUES (1, '2026-10-20'), (1, '2026-10-24'), (2, '2026-10-25')");
        list($http, $b) = $this->get('health');
        $this->assertSame(200, $http);
        $w = $b['websites'][0];
        $this->assertSame(2, $w['pages'], 'only healthy pages of this site');
        $this->assertSame(['status' => 'done', 'at' => '2026-10-26 05:34:00', 'summary' => '2 pages fetched'], $w['last_crawl']);
        $this->assertSame(['high' => 1, 'medium' => 2, 'low' => 1], $w['findings'], 'resolved and info findings do not count');
        $this->assertSame('title_missing', $w['problems'][0]['rule'], 'worst first');
        $this->assertSame('A page has no title', $w['problems'][0]['label']);
        $this->assertSame(2, $w['problems'][1]['pages']);
        $this->assertTrue($w['search_console']['connected']);
        $this->assertSame('2026-10-24', $w['search_console']['last_data']);
        $this->assertTrue($w['can_crawl']);
    }

    public function testHealthForANewWebsiteIsEmptyNotAnError(): void
    {
        $this->post('connect_website', ['address' => 'mowology.ca']);
        list($http, $b) = $this->get('health');
        $this->assertSame(200, $http);
        $this->assertSame(0, $b['websites'][0]['pages']);
        $this->assertNull($b['websites'][0]['last_crawl']);
        $this->assertFalse($b['websites'][0]['search_console']['connected']);
        $this->assertFalse($b['websites'][0]['can_crawl']);
    }

    public function testHealthSurvivesMissingLaterTablesAndHidesOtherSites(): void
    {
        $this->post('connect_website', ['address' => 'mowology.ca']);
        $this->db->exec("DROP TABLE mia_audits");
        $this->db->exec("DROP TABLE gsc_properties");
        $this->assertSame(200, $this->get('health')[0]);
        $this->assertSame([], $this->get('health', [], 7, 2)[1]['websites']);
    }
}
