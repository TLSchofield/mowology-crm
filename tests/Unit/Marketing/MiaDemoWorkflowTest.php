<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/MiaDemo.php';

/**
 * Phase 1 acceptance: the whole workflow from Part 20 of the brief, with the real classes on a synthetic Mowology.
 * Each test names the acceptance criterion it proves (docs/mia/MIA-IMPLEMENTATION-PLAN.md, Phase 1).
 */
class MiaDemoWorkflowTest extends TestCase
{
    /** @var MiaDemo */
    private static $demo;
    /** @var array */
    private static $o;
    private static string $tz = '';

    public static function setUpBeforeClass(): void
    {
        self::$tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
        self::$demo = new MiaDemo();
        self::$o = self::$demo->run();
    }

    public static function tearDownAfterClass(): void
    {
        date_default_timezone_set(self::$tz);
    }

    // ---- 1. examines the website (criteria 1, 2) ----

    public function testCrawlOnlyAfterTheOwnerProvedOwnershipAndOnlyWithGet(): void
    {
        $o = self::$o;
        $this->assertTrue($o['connect']['ok']);
        $this->assertFalse($o['connect']['existing']);
        $this->assertStringContainsString('ittai-mia-verification', $o['connect']['instructions']['meta']['snippet']);
        $this->assertSame(1, $o['audit']['done']);
        $this->assertStringContainsString('5 pages (done)', $o['audit']['summary']);

        // the same website, not verified: no crawl, no request at all
        $fresh = new MiaDemo();
        $fresh->api()->handle('connect_website', 'POST', [], ['address' => MiaDemo::HOST]);
        $r = $fresh->jobs()->auditWeekly(new DateTimeImmutable('2026-10-26 05:30:00'));
        $this->assertSame('No verified websites to work on', $r['summary']);
        $this->assertSame([], $fresh->requests, 'nothing is fetched before the owner proves the site is theirs');
    }

    public function testRobotsTxtIsObeyedAndOnlyTheVerifiedHostIsTouched(): void
    {
        $reqs = self::$demo->requests;                       // the week-2 crawl
        $this->assertNotEmpty($reqs);
        foreach ($reqs as $r) {
            $this->assertStringStartsWith('GET https://mowology.ca/', $r, 'GET only, verified host only');
            $this->assertStringNotContainsString('/crm/', $r, 'robots.txt disallows /crm/');
        }
        $this->assertSame(self::$o['audit2requests'], $reqs);
    }

    // ---- 2-3. a meaningful opportunity, explained (criterion 3) ----

    public function testMiaFindsAndExplainsTheOpportunityInPlainEnglish(): void
    {
        $it = self::$o['ctrItem'];
        $this->assertNotNull($it, 'the strata page click-rate gap was found');
        $this->assertSame('Page title and description', $it['type_label']);
        $this->assertStringContainsString('/services/strata-landscaping', $it['title']);
        $this->assertStringNotContainsString('CTR', $it['why']);
        $this->assertStringNotContainsString('canonical', strtolower($it['why']), 'no SEO jargon in the headline reason');
        $this->assertStringStartsWith('Estimate:', $it['expected_benefit']);
        $this->assertStringContainsString('not a promise', $it['expected_benefit']);
        $this->assertNotSame('', $it['risk']);
        list(, $d) = self::$demo->api()->handle('action_detail', 'GET', ['id' => $it['id']], []);
        $e = $d['item']['evidence'][0];
        $this->assertSame(750, $e['impressions'], 'the numbers behind it come from Search Console');
        $this->assertSame('strata landscaping vancouver', $e['top_queries'][0]['query']);
        $this->assertArrayHasKey('factors', $d['item']['score_inputs'], 'the score is explainable');
        $this->assertSame(0.5, $d['item']['score_inputs']['factors']['value'], 'commercial value is neutral until profit data is connected');
    }

    // ---- 4-5. a proposed improvement in the queue (criterion 3) ----

    public function testTheProposedWordingIsCheckedAndShownBeforeAndAfter(): void
    {
        $d = self::$o['ctrItem']['draft'];
        $this->assertNotNull($d);
        $this->assertSame('Strata', $d['current_title']);
        $this->assertSame('Strata Landscaping and Grounds Care in Vancouver', $d['title']);
        $this->assertGreaterThanOrEqual(70, mb_strlen($d['meta_description']));
        $this->assertSame([], MiaRecommendationWriter::lint(['title' => $d['title'], 'meta_description' => $d['meta_description'], 'explanation' => $d['rationale']],
            ['current_title' => $d['current_title'], 'current_desc' => $d['current_description'], 'allowed_numbers' => ['750', '6'], 'public_facts' => 'Mowology Vancouver strata', 'prohibited' => [], 'known_facts' => '']));
    }

    public function testTheQueueIsBestFirstAndAppearsInCharliesBrief(): void
    {
        $items = self::$o['queue']['items'];
        $this->assertCount(5, $items);
        $scores = array_column($items, 'score');
        $sorted = $scores; rsort($sorted);
        $this->assertSame($sorted, $scores);
        $this->assertSame(self::$o['ctrItem']['id'], $items[0]['id']);
        $brief = self::$o['brief'];
        $this->assertCount(2, $brief, 'two lines, no more');
        $this->assertStringContainsString('Rewrite the title and description of /services/strata-landscaping', $brief[0]['text']);
        $this->assertStringContainsString('4 more website suggestions', $brief[1]['text']);
    }

    // ---- 6-8. the owner decides, Mia records and tracks it (criterion 4) ----

    public function testApprovalAndRejectionAreRecordedWithWhoAndWhen(): void
    {
        $this->assertSame(200, self::$o['approve'][0]);
        $this->assertSame(200, self::$o['reject'][0]);
        $ctr = self::$o['events'][self::$o['ctrItem']['id']];
        $meta = self::$o['events'][self::$o['metaItem']['id']];
        $this->assertSame('approved', $ctr['status']);
        $this->assertSame('rejected', $meta['status']);
        $this->assertSame('We write those ourselves', $meta['decision_note']);
        $this->assertNotNull($ctr['decided_at']);
        $ev = array_column($ctr['events'], 'actor', 'event');
        $this->assertSame('user:7', $ev['approved']);
        $this->assertSame('mia', $ev['proposed']);
        $this->assertContains('drafted', array_column($ctr['events'], 'event'));
        $decisions = array_column(self::$o['decisions'], 'decision', 'subject_key');
        $this->assertContains('approved', $decisions);
        $this->assertContains('rejected', $decisions);
    }

    public function testTheApprovalIsBoundToTheExactWordingShown(): void
    {
        $id = self::$o['ctrItem']['id'];
        $svc = new MiaActionService(self::$demo->db, 1, function (int $u) { return $u === MiaDemo::USER_ID; });
        $d = self::$demo->db->query("SELECT title, meta_desc FROM mia_content_drafts WHERE action_id = $id")->fetch();
        $this->assertTrue($svc->verifyApproval($id, MiaRecommendationWriter::draftText($d['title'], $d['meta_desc']))['ok']);
        $this->assertFalse($svc->verifyApproval($id, MiaRecommendationWriter::draftText($d['title'] . ' (edited)', $d['meta_desc']))['ok']);
    }

    // ---- 9. she uses the decision next time (criterion 4, 7) ----

    public function testNextWeekRespectsTheDecisionsAndShowsWhatGotFixed(): void
    {
        $o = self::$o;
        $this->assertStringContainsString('1 resolved', $o['audit2']['summary'], 'the About page heading the owner fixed');
        $this->assertStringContainsString('1 held back', $o['search2']['summary'], 'the rejected idea is not proposed again');
        $this->assertStringContainsString('closed because the problem is fixed', $o['search2']['summary']);
        $titles = array_column($o['queue2']['items'], 'title');
        $this->assertNotContains('Add a description to 1 page', $titles, 'rejected');
        $this->assertNotContains('Add a main heading to 1 page', $titles, 'fixed on the site');
        $this->assertNotContains($o['ctrItem']['title'], $titles, 'approved, no longer waiting');
        $byTitle = array_column($o['results']['items'], 'status', 'title');
        $this->assertSame('approved', $byTitle[$o['ctrItem']['title']]);
        $this->assertSame('rejected', $byTitle['Add a description to 1 page']);
        $this->assertSame('expired', $byTitle['Add a main heading to 1 page']);
        $this->assertSame(1, (int)self::$demo->db->query("SELECT COUNT(*) FROM mia_actions WHERE opportunity_key LIKE 'audit:%meta_missing'")->fetchColumn(), 'no duplicate of the rejected idea');
    }

    // ---- cross-cutting acceptance criteria ----

    public function testNothingCanBeWrittenToAWebsite(): void
    {
        foreach (self::$demo->requests as $r) { $this->assertStringStartsWith('GET ', $r); }
        $dir = __DIR__ . '/../../../app/Modules/Marketing/Services';
        foreach (array_merge(glob($dir . '/Mia*.php'), glob($dir . '/Site/*.php')) as $f) {
            if (basename($f) === 'MiaLlmGateway.php') { continue; }                        // talks to the AI provider, never to a website
            $code = (string)file_get_contents($f);
            foreach (['CURLOPT_POST', 'CURLOPT_PUT', 'CURLOPT_CUSTOMREQUEST', 'file_put_contents', 'ftp_put', 'sendCrmEmail', 'sendSms'] as $bad) {
                $this->assertStringNotContainsString($bad, $code, basename($f) . ' must not contain ' . $bad);
            }
        }
        $this->assertSame(0, (int)self::$demo->db->query("SELECT COUNT(*) FROM mia_actions WHERE status IN ('executing','done','failed','rolled_back')")->fetchColumn());
        $this->assertSame(0, (int)self::$demo->db->query("SELECT COUNT(*) FROM mia_actions WHERE executed_at IS NOT NULL")->fetchColumn());
    }

    public function testAiCostIsMeasuredAndCapped(): void
    {
        $rows = self::$demo->db->query("SELECT task, cost_usd, ok FROM mia_llm_usage")->fetchAll();
        $this->assertCount(1, $rows, 'one model call in two weeks: the second week reused the saved draft');
        $this->assertSame('rec_write', $rows[0]['task']);
        $this->assertGreaterThan(0.0, (float)$rows[0]['cost_usd']);
        $this->assertSame(1, (int)$rows[0]['ok']);
        $this->assertLessThan(MiaLlmGateway::DEFAULT_MONTHLY_CAP_USD, (float)$rows[0]['cost_usd']);
    }

    public function testRunningTheWeekTwiceDoesNothingTheSecondTime(): void
    {
        $before = (int)self::$demo->db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn();
        $again = self::$demo->jobs()->searchWeekly(new DateTimeImmutable('2026-11-03 05:55:00'));        // same ISO week as week 2
        $this->assertSame(1, $again['skipped']);
        $this->assertSame($before, (int)self::$demo->db->query("SELECT COUNT(*) FROM mia_actions")->fetchColumn());
    }

    public function testEverythingIsScopedToSiteOne(): void
    {
        foreach (['mia_websites', 'mia_site_pages', 'mia_audits', 'mia_audit_findings', 'mia_actions', 'mia_action_events', 'mia_decisions', 'mia_llm_usage', 'mia_content_drafts'] as $t) {
            $this->assertSame(0, (int)self::$demo->db->query("SELECT COUNT(*) FROM $t WHERE site_id <> 1")->fetchColumn(), $t);
        }
        $other = new MiaApiHandler(self::$demo->db, MiaDemo::USER_ID, self::$demo->websiteService(2), new MiaActionService(self::$demo->db, 2), 2);
        $this->assertSame([], $other->handle('actions', 'GET', ['status' => 'all'], [])[1]['items']);
        $this->assertSame([], $other->handle('websites', 'GET', [], [])[1]['websites']);
    }
}
