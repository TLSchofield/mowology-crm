<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MiaFeedsTest extends TestCase
{
    private PDO $db;
    private MiaFeeds $feeds;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE ba_pairs (id INTEGER PRIMARY KEY, visit_id INT, service TEXT, area TEXT, status TEXT, consent_state TEXT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, status TEXT, completed_at TEXT, service_type TEXT)");
        $this->db->exec("CREATE TABLE visit_margin_snapshots (id INTEGER PRIMARY KEY, service_type TEXT, quoted_amount REAL, margin_pct REAL, visit_date TEXT)");
        $this->db->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY, status TEXT, service_types TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE client_feedback (id INTEGER PRIMARY KEY, rating INT, comment TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE gbp_reviews (id INTEGER PRIMARY KEY, rating INT, comment TEXT, replied_at TEXT, created_at TEXT)");
        $this->feeds = new MiaFeeds($this->db);
    }

    private function now(int $daysAgo = 0): string { return date('Y-m-d H:i:s', time() - $daysAgo * 86400); }

    public function testOttoSeesOnlyApprovedPairsWithUsableConsentOnCompletedRecentVisits(): void
    {
        $v = $this->db->prepare("INSERT INTO job_visits (id,status,completed_at,service_type) VALUES (?,?,?,?)");
        foreach ([[1,'completed',$this->now(2),'lawn_care'],[2,'completed',$this->now(2),'lawn_care'],[3,'scheduled',$this->now(2),'lawn_care'],
                  [4,'completed',$this->now(90),'lawn_care'],[5,'completed',$this->now(1),'lawn_care']] as $r) { $v->execute($r); }
        $p = $this->db->prepare("INSERT INTO ba_pairs (visit_id,service,area,status,consent_state) VALUES (?,?,?,?,?)");
        foreach ([[1,'Lawn Cut','front','approved','not_needed'],   // ok
                  [2,'Lawn Cut','front','approved','needed'],       // consent missing
                  [3,'Lawn Cut','front','approved','not_needed'],   // visit not completed
                  [4,'Lawn Cut','front','approved','recorded'],     // too old
                  [5,'Lawn Cut','back','pending','not_needed']] as $r) { $p->execute($r); }
        $f = $this->feeds->completedVisitsWithApprovedPhotos(30);
        $this->assertCount(1, $f['visits']);
        $this->assertSame(1, $f['visits'][0]['visit_id']);
        $this->assertSame('recurring_upkeep', $f['visits'][0]['service_key']);
        $this->assertSame([], $f['gaps']);
    }

    public function testOttoSaysSoWhenNothingQualifies(): void
    {
        $this->assertNotEmpty($this->feeds->completedVisitsWithApprovedPhotos()['gaps']);
    }

    public function testPennyGroupsSpellingsAndNeverAveragesUnknownMargins(): void
    {
        $i = $this->db->prepare("INSERT INTO visit_margin_snapshots (service_type,quoted_amount,margin_pct,visit_date) VALUES (?,?,?,?)");
        $d = date('Y-m-d', time() - 86400);
        foreach ([['lawn_care',50,60.0],['Lawn Cut',50,40.0],['7 Day Lawn Cut Service',50,null],['GARDENING',80,null]] as $r) { $i->execute([$r[0],$r[1],$r[2],$d]); }
        $m = $this->feeds->marginByService();
        $this->assertSame(['visits' => 3, 'with_margin' => 2, 'unknown' => 1, 'avg_margin_pct' => 50.0], $m['services']['recurring_upkeep']);
        $this->assertNull($m['services']['garden_care']['avg_margin_pct']);
        $this->assertNotEmpty($m['gaps'], 'thin samples are flagged');
    }

    public function testSamFunnelUsesTheWonRuleAndReportsRequestsWithNoService(): void
    {
        $i = $this->db->prepare("INSERT INTO quote_requests (status,service_types,created_at) VALUES (?,?,?)");
        foreach ([['accepted','lawn_care, GARDENING'],['quoted','lawn_care'],['declined','lawn_care'],['converted',''],['new','snow_removal']] as $r) { $i->execute([$r[0],$r[1],$this->now(3)]); }
        $f = $this->feeds->funnel();
        $this->assertSame(['total' => 5, 'won' => 2, 'lost' => 1, 'open' => 2, 'win_rate' => 66.7], $f['funnel']);
        $this->assertSame(1, $f['by_service']['recurring_upkeep']['won']);
        $this->assertSame(3, $f['by_service']['recurring_upkeep']['total']);
        $this->assertSame(1, $f['by_service']['garden_care']['won']);
        $this->assertCount(1, $f['gaps']);
    }

    public function testYuiGetsRatingsOnlyAndAGapWhenThereAreNone(): void
    {
        $this->assertCount(2, $this->feeds->feedbackSummary()['gaps']);
        $this->db->exec("INSERT INTO gbp_reviews (rating,comment,replied_at,created_at) VALUES (5,'Great work by Jane',NULL,'" . $this->now(1) . "'),(3,'ok','2026-01-01','" . $this->now(1) . "')");
        $r = $this->feeds->feedbackSummary();
        $this->assertSame(['n' => 2, 'avg' => 4.0, 'unreplied' => 1], $r['ratings']['google_reviews']);
        $this->assertStringNotContainsString('Jane', json_encode($r));
    }

    public function testTheFeedsOnlyEverRead(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Marketing/Services/MiaFeeds.php');
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP)\s/i', preg_replace('#/\*.*?\*/#s', '', $src));
    }
}
