<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Comms/Services/InboundRouteService.php';

/** Yui (D3, 2026-10-15): an address is learned from one move, a whole domain only from three running; rules fade. */
class YuiRouteLearningTest extends TestCase
{
    private PDO $db;
    private InboundRouteService $svc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, head TEXT, subject_kind TEXT, subject_key TEXT,
                   field TEXT, said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE inbound_route_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_key TEXT, topic TEXT, head TEXT, hits INT,
                   created_by INT, created_at TEXT, updated_at TEXT)");
        $this->svc = new InboundRouteService($this->db);
    }

    private function teach(string $from, string $head): void
    {
        $m = new ReflectionMethod(InboundRouteService::class, 'teach');
        $m->setAccessible(true);
        $m->invoke($this->svc, $from, 'billing', $head, 1, date('Y-m-d H:i:s'), 'sam', 5);
    }

    private function rules(): array
    {
        return $this->db->query("SELECT sender_key, head FROM inbound_route_rules ORDER BY sender_key")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testOneMoveTeachesTheAddressNotTheCompany(): void
    {
        $this->teach('Ann <ann@acme-strata.ca>', 'penny');
        $this->assertSame(['ann@acme-strata.ca' => 'penny'], $this->rules());
    }

    public function testThreeMovesRunningTeachTheDomainAndADifferentOneTakesItAway(): void
    {
        $this->teach('ann@acme-strata.ca', 'penny');
        $this->teach('bob@acme-strata.ca', 'penny');
        $this->assertArrayNotHasKey('@acme-strata.ca', $this->rules());
        $this->teach('cy@acme-strata.ca', 'penny');
        $this->assertSame('penny', $this->rules()['@acme-strata.ca']);
        $this->assertSame('penny', $this->svc->learnedRules()['@acme-strata.ca|billing']);

        $this->teach('dee@acme-strata.ca', 'otto');
        $this->assertArrayNotHasKey('@acme-strata.ca', $this->rules(), 'a newer different move resets the domain');
        $this->assertSame('otto', $this->rules()['dee@acme-strata.ca']);
    }

    public function testFreeMailIsNeverADomainAndOldRulesFade(): void
    {
        $this->teach('someone@gmail.com', 'otto');
        $this->assertSame(['someone@gmail.com' => 'otto'], $this->rules());
        $this->db->exec("UPDATE inbound_route_rules SET updated_at = '2024-01-01 00:00:00'");
        $fresh = new InboundRouteService($this->db);
        $this->assertSame([], $fresh->learnedRules());
    }
}
