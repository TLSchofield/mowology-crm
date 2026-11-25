<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MiaInvoiceSuggestion — suggest, learn from taps and results, propose automation.
 * Fixture: invoice 511 shape, 2026-11-01 (hedge trimming, green waste, 14 Day Lawn Cut Service), residential.
 */
class MiaInvoiceSuggestionTest extends TestCase
{
    private PDO $db;
    private const URL = 'https://g.page/r/CQx6tjUVhh6yEAI/review';
    private const SERVICES = ['HEDGE TRIMMING', 'Tarp Green Waste', '14 Day Lawn Cut Service'];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('pdo_sqlite not available');
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE mia_invoice_suggestion_events (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INT DEFAULT 0, property_id INT DEFAULT 0,
                   contact_id INT DEFAULT 0, library_key TEXT, method TEXT, wording_version INT DEFAULT 1, services TEXT DEFAULT '', season_month INT DEFAULT 0,
                   audience TEXT DEFAULT 'home', action TEXT DEFAULT 'shown', outcome TEXT DEFAULT 'none', shown_at TEXT, acted_at TEXT, outcome_at TEXT)");
        $db->exec("CREATE TABLE mia_invoice_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, library_key TEXT UNIQUE, status TEXT DEFAULT 'proposed',
                   shown_at_decision INT DEFAULT 0, evidence_shown INT DEFAULT 0, evidence_inserted INT DEFAULT 0, evidence_converted INT DEFAULT 0,
                   created_at TEXT, decided_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        $this->db = $db;
    }

    private function svc(string $day = '2026-11-01'): MiaInvoiceSuggestion { return new MiaInvoiceSuggestion($this->db, new DateTimeImmutable($day . ' 10:00:00')); }

    private function suggest(?MiaInvoiceSuggestion $s = null, int $invoice = 511, int $contact = 36, array $services = self::SERVICES, string $aud = 'home'): ?array
    {
        return ($s ?? $this->svc())->suggest($services, $aud, $invoice, 39, $contact, 'Susan', self::URL, '(778) 846-9273');
    }

    public function testFirstSuggestionIsTheReviewAskWithTheLink(): void
    {
        $r = $this->suggest();
        $this->assertSame('review_after_work', $r['key']);
        $this->assertStringContainsString('Thanks, Susan.', $r['text']);
        $this->assertStringContainsString(self::URL, $r['text']);
        $this->assertStringNotContainsString('!', $r['text']);
        $this->assertFalse($r['auto']);
    }

    public function testCompanyInvoiceGetsNothing(): void
    {
        $this->assertNull($this->suggest(null, 511, 36, self::SERVICES, 'pm'));
    }

    public function testSeasonFiltersAndInvoiceServicesAreExcluded(): void
    {
        $this->db->exec("INSERT INTO ops_settings VALUES ('mia_invoice_library_struck', 'review_after_work')");
        $r = $this->suggest();   // November, mowing on the invoice
        $this->assertContains($r['key'], ['leaf_cleanup', 'next_year_mowing']);
        // hedge already on the invoice: spring_hedges (Jan/Feb) must not appear in February
        $this->db->exec("UPDATE ops_settings SET setting_value = 'review_after_work,referral_neighbour'");
        $feb = $this->suggest($this->svc('2027-02-10'), 700, 38, ['HEDGE TRIMMING']);
        $this->assertNull($feb);
        $feb2 = $this->suggest($this->svc('2027-02-10'), 701, 38, ['Lawn Cut']);
        $this->assertSame('spring_hedges', $feb2['key']);
    }

    public function testShownIsLoggedOncePerInvoiceAndOncePerDayForANewInvoice(): void
    {
        $this->suggest(); $this->suggest();
        $this->suggest(null, 0); $this->suggest(null, 0);
        $n = (int)$this->db->query("SELECT COUNT(*) FROM mia_invoice_suggestion_events")->fetchColumn();
        $this->assertSame(2, $n);
    }

    public function testDismissedTwiceWithoutInsertingNeverReturns(): void
    {
        foreach ([1, 2] as $i) {
            $r = $this->suggest(null, 520 + $i, 50 + $i);
            $this->assertSame('review_after_work', $r['key']);
            $this->assertTrue($this->svc()->record($r['event_id'], 'dismissed'));
        }
        $this->assertNotSame('review_after_work', $this->suggest(null, 530, 60)['key']);
    }

    public function testReviewAskIsSkippedForAContactAskedWithin30Days(): void
    {
        $r = $this->suggest(); $this->svc()->record($r['event_id'], 'inserted');
        $again = $this->suggest($this->svc('2026-11-20'), 540, 36);
        $this->assertNotSame('review_after_work', $again['key']);
        $later = $this->suggest($this->svc('2026-12-05'), 541, 36);
        $this->assertSame('review_after_work', $later['key']);
    }

    public function testFiveInsertsOfSixShowingsProposesAutomationAndTheOwnerDecides(): void
    {
        foreach (range(1, 6) as $i) {
            $r = $this->suggest(null, 600 + $i, 100 + $i);
            $this->svc()->record($r['event_id'], $i === 3 ? 'dismissed' : 'inserted');
        }
        $p = $this->svc()->pendingProposals();
        $this->assertCount(1, $p);
        $this->assertSame('review_after_work', $p[0]['library_key']);
        $this->assertSame(5, $p[0]['evidence_inserted']);
        $this->assertTrue($this->svc()->decideRule($p[0]['id'], 'active'));
        $this->assertTrue($this->suggest(null, 700, 200)['auto']);
        $this->assertSame([], $this->svc()->pendingProposals());
    }

    public function testFourOfSixDoesNotProposeAndNotNowWaitsForTenMoreShowings(): void
    {
        foreach (range(1, 6) as $i) {
            $r = $this->suggest(null, 800 + $i, 300 + $i);
            $this->svc()->record($r['event_id'], $i <= 4 ? 'inserted' : 'dismissed');
        }
        $this->assertSame([], $this->svc()->pendingProposals());

        $this->db->exec("DELETE FROM mia_invoice_suggestion_events");
        foreach (range(1, 6) as $i) { $r = $this->suggest(null, 900 + $i, 400 + $i); $this->svc()->record($r['event_id'], 'inserted'); }
        $id = $this->svc()->pendingProposals()[0]['id'];
        $this->svc()->decideRule($id, 'not_now');
        foreach (range(1, 9) as $i) { $r = $this->suggest(null, 950 + $i, 500 + $i); $this->svc()->record($r['event_id'], 'inserted'); }
        $this->assertSame([], $this->svc()->pendingProposals(), 'still waiting: fewer than 10 showings since not now');
        $r = $this->suggest(null, 970, 600); $this->svc()->record($r['event_id'], 'inserted');
        $this->assertCount(1, $this->svc()->pendingProposals());
    }

    public function testNeverStaysNever(): void
    {
        foreach (range(1, 6) as $i) { $r = $this->suggest(null, 1000 + $i, 700 + $i); $this->svc()->record($r['event_id'], 'inserted'); }
        $this->svc()->decideRule($this->svc()->pendingProposals()[0]['id'], 'never');
        foreach (range(1, 12) as $i) { $r = $this->suggest(null, 1100 + $i, 800 + $i); if ($r) $this->svc()->record($r['event_id'], 'inserted'); }
        $this->assertSame([], $this->svc()->pendingProposals());
    }

    public function testOutcomeIsRecordedAndInvalidValuesAreRefused(): void
    {
        $a = $this->suggest(null, 1200, 900);   // review
        $this->assertFalse($this->svc()->record($a['event_id'], 'bogus'));
        $this->assertFalse($this->svc()->setOutcome($a['event_id'], 'bogus'));
        $this->assertTrue($this->svc()->setOutcome($a['event_id'], 'converted'));
        $row = $this->db->query("SELECT outcome FROM mia_invoice_suggestion_events WHERE id = " . (int)$a['event_id'])->fetchColumn();
        $this->assertSame('converted', $row);
    }

    public function testATappedSuggestionIsNotOfferedAgainOnTheSameInvoice(): void
    {
        $a = $this->suggest();
        $this->svc()->record($a['event_id'], 'inserted');
        $b = $this->suggest();
        $this->assertNotSame($a['key'], $b['key']);
        $c = $this->suggest(null, 0, 38);   // a not-yet-saved invoice for another contact
        $this->assertSame('review_after_work', $c['key']);
        $this->svc()->record($c['event_id'], 'dismissed');
        $this->assertNotSame('review_after_work', $this->suggest(null, 0, 38)['key'], 'same contact, same day: handled');
        $this->assertSame('review_after_work', $this->suggest(null, 0, 37)['key'], 'another contact at the same property is not affected');
    }
}
