<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** MiaActionService + MiaMemoryService + MiaAutonomyPolicy: approval enforcement, audit, memory, tenant isolation. */
class MiaActionLedgerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, opportunity_key TEXT, type TEXT,
            side_effect TEXT, status TEXT NOT NULL DEFAULT 'proposed', title TEXT, why TEXT, expected_benefit TEXT, risk TEXT, evidence TEXT,
            score REAL, score_inputs TEXT, evidence_hash TEXT, draft_ref_table TEXT, draft_ref_id INT, draft_hash TEXT, preview_url TEXT,
            autonomy_level INT DEFAULT 1, proposed_by TEXT DEFAULT 'mia', proposed_at TEXT, decided_by INT, decided_at TEXT, decision_note TEXT,
            executed_at TEXT, result TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_action_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, action_id INT, event TEXT, actor TEXT, detail TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, subject_key TEXT, action_type TEXT, evidence_hash TEXT,
            decision TEXT, reason_code TEXT, reason_text TEXT, decided_by INT, decided_at TEXT, suppress_until TEXT)");
        $db->exec("CREATE TABLE mia_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, mem_key TEXT, mem_value TEXT, source TEXT DEFAULT 'owner',
            updated_by INT, updated_at TEXT, UNIQUE(site_id, kind, mem_key))");
        $this->db = $db;
    }

    private function svc(int $site = 1): MiaActionService
    {
        return new MiaActionService($this->db, $site, function (int $id) { return $id > 0 && $id < 1000; });
    }

    private function good(array $over = []): array
    {
        return array_merge([
            'type' => 'seo_meta_update', 'side_effect' => 'external_reversible', 'opportunity_key' => 'page:abc',
            'title' => 'Rewrite the strata landscaping page title', 'why' => 'The page is shown often but rarely clicked.',
            'expected_benefit' => 'Estimate: a few more clicks a month.', 'risk' => 'Changes the title Google currently shows.',
            'evidence' => [['impressions' => 1240, 'clicks' => 11]], 'score' => 71.5,
        ], $over);
    }

    private function propose(array $over = [], int $site = 1): int
    {
        $r = $this->svc($site)->propose($this->good($over));
        $this->assertTrue($r['ok'], (string)$r['error']);
        return (int)$r['id'];
    }

    // ---- propose ----

    public function testProposeStoresActionAndAuditEvent(): void
    {
        $id = $this->propose();
        $a = $this->svc()->get($id);
        $this->assertSame('proposed', $a['status']);
        $this->assertSame(1, (int)$a['site_id']);
        $this->assertSame(1, (int)$a['autonomy_level']);
        $ev = $this->svc()->events($id);
        $this->assertSame(['proposed'], array_column($ev, 'event'));
        $this->assertSame('mia', $ev[0]['actor']);
    }

    /** @dataProvider badInputs */
    public function testBadInputIsRefused(array $over, string $code): void
    {
        $r = $this->svc()->propose($this->good($over));
        $this->assertFalse($r['ok']);
        $this->assertSame($code, $r['code']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn());
    }

    public function badInputs(): array
    {
        return [
            'type'        => [['type' => 'publish_everything'], 'bad_type'],
            'side effect' => [['side_effect' => 'whatever'], 'bad_side_effect'],
            'no title'    => [['title' => ''], 'bad_title'],
            'no why'      => [['why' => ''], 'bad_why'],
            'no risk'     => [['risk' => ''], 'bad_risk'],
            'no key'      => [['opportunity_key' => ''], 'bad_key'],
            'evidence'    => [['evidence' => array_fill(0, 11, 'x')], 'bad_evidence'],
            'promise 1'   => [['expected_benefit' => 'This will rank you first page'], 'promise'],
            'promise 2'   => [['why' => 'Guaranteed to bring leads'], 'promise'],
            'promise 3'   => [['title' => 'Get the #1 spot'], 'promise'],
            'bad table'   => [['draft_ref_table' => 'users', 'draft_ref_id' => 1], 'bad_draft_ref'],
            'ref no id'   => [['draft_ref_table' => 'mia_suggestions'], 'bad_draft_ref'],
            'http preview'=> [['preview_url' => 'http://x.example/p'], 'bad_preview'],
        ];
    }

    public function testWhitelistedDraftRefAndHttpsPreviewAccepted(): void
    {
        $id = $this->propose(['draft_ref_table' => 'mia_content_drafts', 'draft_ref_id' => 5, 'preview_url' => 'https://mowology.ca/p?t=1']);
        $this->assertSame('mia_content_drafts', $this->svc()->get($id)['draft_ref_table']);
    }

    public function testSecondProposalForSameSubjectReturnsTheFirst(): void
    {
        $a = $this->propose();
        $r = $this->svc()->propose($this->good(['title' => 'Different words']));
        $this->assertTrue($r['existing']);
        $this->assertSame($a, $r['id']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn());
    }

    public function testPausedOrObserveOnlyBlocksProposals(): void
    {
        $this->db->exec("INSERT INTO ops_settings VALUES ('mia_paused','1','')");
        $this->assertSame('not_allowed', $this->svc()->propose($this->good())['code']);
        $this->db->exec("UPDATE ops_settings SET setting_value='0' WHERE setting_key='mia_paused'");
        $this->db->exec("INSERT INTO ops_settings VALUES ('mia_autonomy_seo_meta_update','0','')");
        $this->assertSame('not_allowed', $this->svc()->propose($this->good())['code']);
        $this->assertTrue($this->svc()->propose($this->good(['type' => 'page_draft']))['ok'], 'other types unaffected');
    }

    // ---- decide: who may, and what it binds ----

    public function testOnlyARealUserCanDecide(): void
    {
        $id = $this->propose();
        foreach ([0, -1] as $uid) {
            $r = $this->svc()->decide($id, $uid, 'approve', null, null, 'x');
            $this->assertSame('no_user', $r['code']);
        }
        $this->assertSame('proposed', $this->svc()->get($id)['status']);
    }

    public function testApproveRecordsPersonHashAuditAndDecision(): void
    {
        $id = $this->propose();
        $r = $this->svc()->decide($id, 7, 'approve', null, 'Looks right', 'NEW TITLE');
        $this->assertTrue($r['ok']);
        $a = $this->svc()->get($id);
        $this->assertSame('approved', $a['status']);
        $this->assertSame(7, (int)$a['decided_by']);
        $this->assertSame(sha1('NEW TITLE'), $a['draft_hash']);
        $this->assertSame(['proposed', 'approved'], array_column($this->svc()->events($id), 'event'));
        $this->assertSame('user:7', $this->svc()->events($id)[1]['actor']);
        $this->assertSame('approved', $this->db->query("SELECT decision FROM mia_decisions")->fetchColumn());
    }

    public function testCannotDecideTwice(): void
    {
        $id = $this->propose();
        $this->assertTrue($this->svc()->decide($id, 7, 'reject', 'bad_timing')['ok']);
        $this->assertSame('not_open', $this->svc()->decide($id, 7, 'approve', null, null, 'x')['code']);
        $this->assertSame('rejected', $this->svc()->get($id)['status']);
    }

    public function testUnknownDecisionAndMissingActionRefused(): void
    {
        $id = $this->propose();
        $this->assertSame('bad_decision', $this->svc()->decide($id, 7, 'publish')['code']);
        $this->assertSame('not_found', $this->svc()->decide(9999, 7, 'approve', null, null, 'x')['code']);
    }

    // ---- verifyApproval: an edit voids the approval ----

    public function testVerifyApprovalMatchesExactDraftOnly(): void
    {
        $id = $this->propose();
        $this->svc()->decide($id, 7, 'approve', null, null, 'Title A');
        $this->assertTrue($this->svc()->verifyApproval($id, 'Title A')['ok']);
        $r = $this->svc()->verifyApproval($id, 'Title A (edited)');
        $this->assertSame('draft_changed', $r['code']);
        $a = $this->svc()->get($id);
        $this->assertSame('proposed', $a['status'], 'back to the owner');
        $this->assertNull($a['draft_hash']);
        $this->assertContains('reopened', array_column($this->svc()->events($id), 'event'));
        $this->assertSame('not_approved', $this->svc()->verifyApproval($id, 'Title A')['code'], 'old approval is gone');
    }

    public function testVerifyApprovalRefusesUnapprovedAndApprovalWithoutPerson(): void
    {
        $id = $this->propose();
        $this->assertSame('not_approved', $this->svc()->verifyApproval($id, 'x')['code']);
        $this->db->exec("UPDATE mia_actions SET status='approved', draft_hash='" . sha1('x') . "', decided_by=NULL WHERE id=$id");
        $this->assertSame('not_approved', $this->svc()->verifyApproval($id, 'x')['code'], 'status alone is not an approval');
    }

    public function testApprovalWithoutDraftContentIsRefusedOutright(): void
    {
        $id = $this->propose();
        $this->assertSame('draft_required', $this->svc()->decide($id, 7, 'approve')['code']);
        $this->assertSame('', trim((string)$this->svc()->get($id)['draft_hash']));
        $this->assertSame('proposed', $this->svc()->get($id)['status']);
    }

    // ---- memory: rejected stays rejected ----

    public function testRejectedIdeaIsNotProposedAgainWhileEvidenceIsUnchanged(): void
    {
        $id = $this->propose(['evidence_basis' => ['k' => 'h1']]);
        $this->svc()->decide($id, 7, 'reject', 'not_our_voice', 'Too salesy');
        $r = $this->svc()->propose($this->good(['evidence_basis' => ['k' => 'h1']]));
        $this->assertSame('suppressed_rejected', $r['code']);
    }

    public function testRejectedIdeaMayReturnWhenEvidenceMateriallyChanges(): void
    {
        $id = $this->propose(['evidence_basis' => ['k' => 'h1']]);
        $this->svc()->decide($id, 7, 'reject', 'not_our_voice');
        $this->assertTrue($this->svc()->propose($this->good(['evidence_basis' => ['k' => 'h2-new-facts']]))['ok']);
    }

    public function testRejectionExpiresAfterItsQuietPeriod(): void
    {
        $id = $this->propose(['evidence_basis' => ['k' => 'h1']]);
        $this->svc()->decide($id, 7, 'reject', 'bad_timing', null, null, new DateTimeImmutable('2026-01-01'));
        $this->assertSame('suppressed_rejected', $this->svc()->propose($this->good(['evidence_basis' => ['k' => 'h1']]), new DateTimeImmutable('2026-01-20'))['code']);
        $this->assertTrue($this->svc()->propose($this->good(['evidence_basis' => ['k' => 'h1']]), new DateTimeImmutable('2026-02-05'))['ok'], '30 days passed');
    }

    public function testNotOurServiceHoldsLongerThanBadTiming(): void
    {
        $this->assertGreaterThan(MiaMemoryService::SUPPRESS_DAYS['bad_timing'], MiaMemoryService::SUPPRESS_DAYS['not_our_service']);
    }

    public function testLaterSnoozesAndApprovedStaysQuiet(): void
    {
        $id = $this->propose(['opportunity_key' => 'a']);
        $this->svc()->decide($id, 7, 'later', null, null, null, new DateTimeImmutable('2026-03-01'));
        $this->assertSame('expired', $this->svc()->get($id)['status']);
        $this->assertSame('suppressed_later', $this->svc()->propose($this->good(['opportunity_key' => 'a']), new DateTimeImmutable('2026-03-03'))['code']);
        $this->assertTrue($this->svc()->propose($this->good(['opportunity_key' => 'a']), new DateTimeImmutable('2026-03-10'))['ok']);

        $id2 = $this->propose(['opportunity_key' => 'b']);
        $this->svc()->decide($id2, 7, 'approve', null, null, 'x', new DateTimeImmutable('2026-03-01'));
        $again = $this->svc()->propose($this->good(['opportunity_key' => 'b', 'type' => 'seo_meta_update']), new DateTimeImmutable('2026-03-20'));
        $this->assertTrue($again['existing'], 'still approved and open: the same action comes back');
        $this->db->exec("UPDATE mia_actions SET status='done' WHERE id=$id2");
        $this->assertSame('suppressed_already_approved', $this->svc()->propose($this->good(['opportunity_key' => 'b', 'type' => 'seo_meta_update']), new DateTimeImmutable('2026-03-20'))['code']);
    }

    public function testNewestDecisionWins(): void
    {
        $m = new MiaMemoryService($this->db, 1);
        $m->recordDecision('s', 't', 'h', 'rejected', 'other', null, 1, new DateTimeImmutable('2026-01-01'));
        $m->recordDecision('s', 't', 'h', 'later', null, null, 1, new DateTimeImmutable('2026-01-02'));
        $this->assertSame('later', $m->suppression('s', 'h', new DateTimeImmutable('2026-01-03'))['reason']);
    }

    // ---- facts ----

    public function testOwnerFactsAreNotOverwrittenByLearnedOnes(): void
    {
        $m = new MiaMemoryService($this->db, 1);
        $this->assertTrue($m->remember('brand', 'voice', 'Plain and warm', 'owner', 7));
        $this->assertFalse($m->remember('brand', 'voice', 'Salesy', 'learned'));
        $this->assertSame('Plain and warm', $m->recall('brand', 'voice'));
        $this->assertTrue($m->remember('brand', 'voice', 'Plain, warm, short', 'owner', 7));
        $this->assertSame(['voice' => 'Plain, warm, short'], $m->all('brand'));
        $this->assertFalse($m->remember('nonsense', 'k', 'v'));
        $this->assertFalse($m->remember('brand', '', 'v'));
        $this->assertTrue($m->forget('brand', 'voice'));
        $this->assertNull($m->recall('brand', 'voice'));
    }

    // ---- tenant isolation ----

    public function testSitesCannotSeeOrDecideEachOthersActions(): void
    {
        $id1 = $this->propose([], 1);
        $id2 = $this->propose([], 2);                               // same key and type, other site: not a duplicate
        $this->assertNotSame($id1, $id2);
        $this->assertNull($this->svc(2)->get($id1));
        $this->assertCount(1, $this->svc(1)->listOpen());
        $this->assertSame('not_found', $this->svc(2)->decide($id1, 7, 'approve', null, null, 'x')['code']);
        $this->assertSame('proposed', $this->svc(1)->get($id1)['status']);
        $this->assertSame([], $this->svc(2)->events($id1));
        $this->assertSame('not_found', $this->svc(2)->verifyApproval($id1, 'x')['code']);
    }

    public function testDecisionsAndMemoryAreTenantScoped(): void
    {
        $id = $this->propose(['evidence_basis' => ['k' => 'h']], 1);
        $this->svc(1)->decide($id, 7, 'reject', 'other');
        $this->assertTrue($this->svc(2)->propose($this->good(['evidence_basis' => ['k' => 'h']]))['ok'], 'site 1 rejection must not hold site 2');
        (new MiaMemoryService($this->db, 1))->remember('brand', 'k', 'v');
        $this->assertNull((new MiaMemoryService($this->db, 2))->recall('brand', 'k'));
    }

    public function testSiteZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MiaActionService($this->db, 0);
    }

    // ---- policy ----

    public function testPolicyClampsToLevelOneWhateverIsStored(): void
    {
        $p = new MiaAutonomyPolicy($this->db, 1);
        $this->assertSame(1, $p->levelFor('page_draft'));
        foreach (['2', '3', '99'] as $v) {
            $this->db->exec("DELETE FROM ops_settings");
            $this->db->exec("INSERT INTO ops_settings VALUES ('mia_autonomy_default','$v','')");
            $this->assertSame(1, $p->levelFor('page_draft'), $v);
        }
        $this->assertFalse($p->mayRunWithoutApproval('page_draft', 'none'));
        $this->assertFalse($p->mayRunWithoutApproval('seo_meta_update', 'internal'));
    }

    public function testProposedActionRecordsTheLevelInForce(): void
    {
        $this->db->exec("INSERT INTO ops_settings VALUES ('mia_autonomy_default','3','')");
        $id = $this->propose();
        $this->assertSame(1, (int)$this->svc()->get($id)['autonomy_level']);
    }

    // ---- housekeeping ----

    public function testStaleProposalsExpireWithAnAuditEvent(): void
    {
        $id = $this->propose([], 1);
        $this->db->exec("UPDATE mia_actions SET proposed_at='2020-01-01 00:00:00'");
        $this->assertSame(1, $this->svc()->expireStale());
        $this->assertSame('expired', $this->svc()->get($id)['status']);
        $this->assertContains('expired', array_column($this->svc()->events($id), 'event'));
        $this->assertSame(0, $this->svc()->expireStale());
    }

    public function testListOpenIsBestScoreFirst(): void
    {
        $a = $this->propose(['opportunity_key' => 'a', 'score' => 10]);
        $b = $this->propose(['opportunity_key' => 'b', 'score' => 90]);
        $c = $this->propose(['opportunity_key' => 'c', 'score' => null]);
        $this->assertSame([$b, $a, $c], array_map('intval', array_column($this->svc()->listOpen(), 'id')));
    }

    public function testEvidenceHashIgnoresKeyOrderAndTinyFloatNoise(): void
    {
        $this->assertSame(
            MiaActionService::hashEvidence(['a' => 1, 'b' => ['y' => 2, 'x' => 1.00000001]]),
            MiaActionService::hashEvidence(['b' => ['x' => 1.0, 'y' => 2], 'a' => 1])
        );
        $this->assertNotSame(MiaActionService::hashEvidence(['a' => 1]), MiaActionService::hashEvidence(['a' => 2]));
    }

    // ---- Codex round 1 regressions ----

    public function testDefaultUserCheckFailsClosedWithoutAUsersTable(): void
    {
        $svc = new MiaActionService($this->db, 1);                  // default checker; sqlite has no users table
        $id = (int)$svc->propose($this->good())['id'];
        $this->assertSame('no_user', $svc->decide($id, 1, 'approve', null, null, 'x')['code']);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY)");
        $this->db->exec("INSERT INTO users VALUES (7)");
        $this->assertSame('no_user', $svc->decide($id, 8, 'approve', null, null, 'x')['code'], 'unknown id');
        $this->assertTrue($svc->decide($id, 7, 'approve', null, null, 'x')['ok']);
    }

    public function testCallerCannotChooseItsOwnEvidenceHash(): void
    {
        $id = $this->propose(['evidence_basis' => ['k' => 'v']]);
        $this->svc()->decide($id, 7, 'reject', 'other');
        $r = $this->svc()->propose($this->good(['evidence_basis' => ['k' => 'v'], 'evidence_hash' => 'sneaky-new-hash']));
        $this->assertSame('suppressed_rejected', $r['code']);
    }

    public function testRejectionIsPerActionTypeAndNotErasedByAnotherType(): void
    {
        $a = $this->propose(['type' => 'page_draft', 'evidence_basis' => ['k' => 'h1']]);
        $this->svc()->decide($a, 7, 'reject', 'not_our_voice');
        $b = $this->propose(['type' => 'seo_meta_update', 'evidence_basis' => ['k' => 'h2']]);
        $this->svc()->decide($b, 7, 'reject', 'not_our_voice');
        $r = $this->svc()->propose($this->good(['type' => 'page_draft', 'evidence_basis' => ['k' => 'h1']]));
        $this->assertSame('suppressed_rejected', $r['code'], 'the later seo_meta_update decision must not erase the page_draft rejection');
        $this->assertTrue($this->svc()->propose($this->good(['type' => 'internal_link', 'evidence_basis' => ['k' => 'h1']]))['ok'], 'other types unaffected');
    }

    public function testDecideInsideCallersTransactionRollsBackToSavepointOnFailure(): void
    {
        $id = $this->propose();
        $this->db->exec("DROP TABLE mia_decisions");                // memory write will fail
        $this->db->beginTransaction();
        $r = $this->svc()->decide($id, 7, 'approve', null, null, 'x');
        $this->assertSame('db', $r['code']);
        $this->db->commit();                                        // the caller commits regardless
        $this->assertSame('proposed', $this->svc()->get($id)['status'], 'half a decision must not be committed');
        $this->assertSame(['proposed'], array_column($this->svc()->events($id), 'event'));
    }

    public function testProposeIsAllOrNothingWithItsAuditEvent(): void
    {
        $this->db->exec("DROP TABLE mia_action_events");
        $r = $this->svc()->propose($this->good());
        $this->assertSame('db', $r['code']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn(), 'no action without its proposed event');
    }

    public function testVerifyApprovalLogsOneReopenedEventOnly(): void
    {
        $id = $this->propose();
        $this->svc()->decide($id, 7, 'approve', null, null, 'A');
        $this->svc()->verifyApproval($id, 'B');
        $this->svc()->verifyApproval($id, 'B');                     // already reopened: no second event
        $this->assertSame(1, count(array_keys(array_column($this->svc()->events($id), 'event'), 'reopened')));
    }

    public function testActiveRejectionSurvivesALaterDecisionOnDifferentEvidence(): void
    {
        $m = new MiaMemoryService($this->db, 1);
        $m->recordDecision('page:1', 'page_draft', 'H1', 'rejected', 'other', null, 1, new DateTimeImmutable('2026-01-01'));
        $m->recordDecision('page:1', 'page_draft', 'H2', 'later', null, null, 1, new DateTimeImmutable('2026-01-02'));
        // day 9: the "later" has lapsed, evidence is back to H1, the rejection (120 days) is still active
        $this->assertSame('rejected', $m->suppression('page:1', 'H1', new DateTimeImmutable('2026-01-10'), 'page_draft')['reason']);
        $this->assertFalse($m->suppression('page:1', 'H3', new DateTimeImmutable('2026-01-10'), 'page_draft')['suppressed'], 'new evidence passes');
    }

    public function testUnreadableDecisionMemoryFailsClosed(): void
    {
        $id = $this->propose();
        $this->db->exec("DROP TABLE mia_decisions");
        $r = $this->svc()->propose($this->good(['opportunity_key' => 'other']));
        $this->assertSame('db', $r['code']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn());
        $this->assertSame('unreadable', (new MiaMemoryService($this->db, 1))->suppression('x', 'h')['reason']);
    }

    public function testPolicyRefusesSiteZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MiaAutonomyPolicy($this->db, 0);
    }

    public function testPolicySettingsAreSiteScopedWithSharedFallback(): void
    {
        $this->db->exec("INSERT INTO ops_settings VALUES ('mia_s2_paused','1','')");
        $this->assertFalse((new MiaAutonomyPolicy($this->db, 1))->isPaused());
        $this->assertTrue((new MiaAutonomyPolicy($this->db, 2))->isPaused());
        $this->db->exec("INSERT INTO ops_settings VALUES ('mia_paused','1','')");
        $this->assertTrue((new MiaAutonomyPolicy($this->db, 1))->isPaused(), 'shared kill switch stops everyone');
    }
}
