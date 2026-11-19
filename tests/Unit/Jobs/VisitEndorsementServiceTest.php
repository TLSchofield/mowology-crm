<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VisitEndorsementServiceTest extends TestCase
{
    private function db(bool $withTable): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn () => '2026-09-29 12:00:00', 0);
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, is_flagged INT DEFAULT 0)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $db->exec("INSERT INTO users VALUES (1, 'Trevor James'), (2, 'Nigel Cass')");
        $db->exec("INSERT INTO job_visits (id, is_flagged) VALUES (10, 0)");
        if ($withTable) {
            $db->exec("CREATE TABLE visit_endorsements (id INTEGER PRIMARY KEY, visit_id INT, user_id INT, created_at TEXT, UNIQUE (visit_id, user_id))");
        }
        return $db;
    }

    private function flag(PDO $db): int
    {
        return (int)$db->query("SELECT is_flagged FROM job_visits WHERE id = 10")->fetchColumn();
    }

    public function testEachCrewMemberHasTheirOwnEndorsement(): void
    {
        $db  = $this->db(true);
        $svc = new VisitEndorsementService($db);

        $first = $svc->toggle(10, 1);
        $this->assertTrue($first['mine']);
        $this->assertTrue($first['newly_endorsed']);

        $second = $svc->toggle(10, 2);
        $this->assertTrue($second['mine']);
        $this->assertFalse($second['newly_endorsed'], 'downstream hooks fire once per visit');
        $this->assertSame(['Trevor James', 'Nigel Cass'], $second['endorsed_by']);
        $this->assertSame(1, $this->flag($db));
    }

    public function testOnePersonWithdrawingDoesNotEraseTheOther(): void
    {
        $db  = $this->db(true);
        $svc = new VisitEndorsementService($db);
        $svc->toggle(10, 1);
        $svc->toggle(10, 2);

        $result = $svc->toggle(10, 2);

        $this->assertFalse($result['mine']);
        $this->assertTrue($result['endorsed']);
        $this->assertSame(['Trevor James'], $result['endorsed_by']);
        $this->assertSame(1, $this->flag($db));

        $last = $svc->toggle(10, 1);
        $this->assertFalse($last['endorsed']);
        $this->assertSame(0, $this->flag($db));
    }

    public function testForVisitsReportsWhoEndorsed(): void
    {
        $db  = $this->db(true);
        $svc = new VisitEndorsementService($db);
        $svc->toggle(10, 2);

        $this->assertSame([10 => ['user_ids' => [2], 'names' => ['Nigel Cass']]], $svc->forVisits([10, 11]));
    }

    public function testBeforeTheMigrationCrewCanEndorseButNotUnEndorse(): void
    {
        $db  = $this->db(false);
        $svc = new VisitEndorsementService($db);
        $this->assertFalse($svc->perCrewEnabled());

        $this->assertTrue($svc->toggle(10, 1)['endorsed']);

        $again = $svc->toggle(10, 2);
        $this->assertTrue($again['endorsed'], 'a second tap must not erase the endorsement');
        $this->assertFalse($again['changed']);
        $this->assertSame(1, $this->flag($db));

        $this->assertFalse($svc->toggle(10, 99, true)['endorsed'], 'an admin can still clear it');
        $this->assertSame([], $svc->forVisits([10]));
    }

    public function testUnknownVisitThrows(): void
    {
        $this->expectExceptionMessage('Visit not found');
        (new VisitEndorsementService($this->db(true)))->toggle(999, 1);
    }
}
