<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/UnscheduledDayFixture.php';

/**
 * Otto logs contract-site work by himself (owner, 2026-10-07): Larch on 2026-10-05 as if it were a
 * Vancouver Management building on contract CTR-0005. Logged once (idempotent), covered by the contract
 * (out of every unbilled list), refused when it could double-bill, undoable — and a non-contract
 * property still asks.
 */
class OttoContractLogTest extends TestCase
{
    private const D = UnscheduledDayFixture::DATE;
    private $log;

    protected function setUp(): void
    {
        $this->log = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string)$this->log);
    }

    private function db(bool $handInvoice = false, string $cycle = 'monthly'): PDO
    {
        $db = UnscheduledDayFixture::pdo();
        foreach ([
            'ALTER TABLE job_plans ADD COLUMN contract_id INT', 'ALTER TABLE job_plans ADD COLUMN default_crew_id INT',
            'ALTER TABLE job_plans ADD COLUMN recurrence_day_of_week INT',
            'ALTER TABLE job_visits ADD COLUMN status_changed_at TEXT', 'ALTER TABLE job_visits ADD COLUMN started_at TEXT',
            'ALTER TABLE job_visits ADD COLUMN scheduled_time_start TEXT', 'ALTER TABLE job_visits ADD COLUMN scheduled_time_end TEXT',
            'ALTER TABLE job_visits ADD COLUMN actual_duration_minutes INT', 'ALTER TABLE job_visits ADD COLUMN actual_crew_count INT',
            'ALTER TABLE job_visits ADD COLUMN completion_notes TEXT', 'ALTER TABLE job_visits ADD COLUMN is_invoiced INT DEFAULT 0',
            'ALTER TABLE job_visits ADD COLUMN invoice_id INT',
            'CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT, title TEXT, status TEXT, billing_cycle TEXT, property_id INT, start_date TEXT, end_date TEXT)',
            'CREATE TABLE otto_auto_visits (id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INT, day TEXT, kind TEXT, source_visit_id INT NOT NULL DEFAULT 0,
                moved_from TEXT, contract_id INT, plan_id INT, visit_id INT,
                invoice_id INT, start_time TEXT, end_time TEXT, minutes INT, status TEXT, reason TEXT, evidence TEXT, created_at TEXT, undone_by INT, undone_at TEXT,
                UNIQUE (property_id, day, kind, source_visit_id))',
        ] as $q) $db->exec($q);
        $db->exec("INSERT INTO contracts VALUES (5, 'CTR-0005', 'Building grounds', 'active', '{$cycle}', 441, '2026-03-01', NULL)");
        $db->exec("UPDATE job_plans SET contract_id = 5, default_crew_id = 7 WHERE id = 82");
        $db->exec("DELETE FROM invoices WHERE id = 438");
        if ($handInvoice) $db->exec("INSERT INTO invoices (id, property_id, invoice_number, issue_date, total, status) VALUES (438, 441, 'INV-2026-0438', '2026-10-06', 540.75, 'sent')");
        // The contract's monthly invoice for October (issued by contract_billing on the 1st).
        $db->exec("INSERT INTO invoices (id, property_id, invoice_number, issue_date, total, status, contract_id) VALUES (500, 441, 'INV-2026-0401', '2026-10-01', 2100, 'sent', 5)");
        return $db;
    }

    private function uw(PDO $db): UnscheduledWorkService
    {
        return new class($db, '2026-10-07') extends UnscheduledWorkService {
            public function cacheReady(): bool { return true; }
            protected function captureCosts(int $visitId, int $actorId): void {}
            public function moveVisitDate(int $visitId, string $toDate): bool
            {
                $u = $this->db->prepare("UPDATE job_visits SET scheduled_date = ? WHERE id = ? AND status <> 'cancelled' AND scheduled_date <> ?");
                $u->execute([$toDate, $visitId, $toDate]);
                return $u->rowCount() > 0;
            }
            public function addVisitFor(int $planId, string $date, int $crewId): array
            {
                $this->db->prepare("INSERT INTO job_visits (plan_id, scheduled_date, status) VALUES (?, ?, 'scheduled')")->execute([$planId, $date]);
                return ["success" => true, "visit_id" => (int)$this->db->lastInsertId()];
            }
        };
    }

    private function itemsFor(UnscheduledWorkService $uw, int $pid): array
    {
        return array_values(array_filter($uw->items(true), fn($i) => $i['for_date'] === self::D && $i['subject_id'] === $pid));
    }

    public function test_contract_site_is_logged_not_asked_once_and_out_of_unbilled_lists(): void
    {
        $db = $this->db();
        $uw = $this->uw($db);
        $this->assertSame([], $this->itemsFor($uw, 441), 'contract site: Otto does not ask');

        $row = $db->query("SELECT * FROM otto_auto_visits")->fetch();
        $this->assertSame(['logged', 441, self::D, 5, 82, 500, '08:10:00', '11:40:00', 210],
            [$row['status'], (int)$row['property_id'], $row['day'], (int)$row['contract_id'], (int)$row['plan_id'], (int)$row['invoice_id'], $row['start_time'], $row['end_time'], (int)$row['minutes']]);
        $v = $db->query("SELECT * FROM job_visits WHERE id = " . (int)$row['visit_id'])->fetch();
        $this->assertSame(['completed', 1, 500, 210], [$v['status'], (int)$v['is_invoiced'], (int)$v['invoice_id'], (int)$v['actual_duration_minutes']]);
        $this->assertStringContainsString('Covered by contract CTR-0005 — not billed per visit', $v['completion_notes']);

        // Out of every unbilled list: the dashboard's (is_invoiced = 0 OR invoice_id IS NULL) and Freedom's (AND).
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE status = 'completed' AND plan_id = 82 AND (is_invoiced = 0 OR invoice_id IS NULL)")->fetchColumn());
        // …and the plan is contract-billed, so InvoiceFromVisitService / pow-actions refuse a per-visit invoice.
        require_once __DIR__ . '/../../../app/Modules/Contracts/Services/ContractService.php';
        $this->assertTrue((new ContractService($db))->isPlanContractBilled(82));

        // Idempotent: another card load and the daily pass add nothing.
        $this->assertSame([], $this->itemsFor($uw, 441));
        $pass = (new OttoContractLogService($db, $uw, '2026-10-07'))->dailyPass(14);
        $this->assertSame(0, $pass['logged']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE plan_id = 82")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM otto_auto_visits")->fetchColumn());

        $s = (new OttoContractLogService($db, $uw, '2026-10-06'))->summary();
        $this->assertSame('Logged 1 contract visit yesterday (3 h 30 min)', $s['line']);
    }

    public function test_a_hand_made_invoice_means_it_could_double_bill_so_otto_asks(): void
    {
        $db = $this->db(true);
        $items = $this->itemsFor($this->uw($db), 441);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Contract site, but invoice INV-2026-0438 already exists for this property — could double-bill, asking', $items[0]['detail']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM otto_auto_visits")->fetchColumn());
    }

    public function test_a_per_visit_contract_is_not_contract_billed_so_otto_asks(): void
    {
        $db = $this->db(false, 'per_visit');
        $items = $this->itemsFor($this->uw($db), 441);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('bills per visit', $items[0]['detail']);
    }

    public function test_non_contract_property_still_asks(): void
    {
        $db = $this->db();
        $db->exec("UPDATE contracts SET status = 'cancelled'");
        $items = $this->itemsFor($this->uw($db), 441);
        $this->assertCount(1, $items);
        $this->assertSame('unscheduled', $items[0]['kind']);
        $this->assertStringNotContainsString('Contract site', $items[0]['detail']);
    }

    public function test_undo_cancels_the_visit_and_otto_asks_instead_without_logging_again(): void
    {
        $db = $this->db();
        $uw = $this->uw($db);
        $this->itemsFor($uw, 441);
        $auto = new OttoContractLogService($db, $uw, '2026-10-07');
        $id = (int)$db->query("SELECT id FROM otto_auto_visits")->fetchColumn();
        $r = $auto->undo($id, 1);
        $this->assertTrue($r['ok']);
        $v = $db->query("SELECT status, is_invoiced, invoice_id FROM job_visits WHERE plan_id = 82")->fetch();
        $this->assertSame(['cancelled', 0, null], [$v['status'], (int)$v['is_invoiced'], $v['invoice_id']]);
        $items = $this->itemsFor($uw, 441);
        $this->assertCount(1, $items, 'after undo Otto asks');
        $this->assertStringContainsString('logged before and undone', $items[0]['detail']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE plan_id = 82")->fetchColumn());
        $this->assertFalse($auto->undo($id, 1)['ok']);
    }

    public function test_plan_choice_prefers_the_plan_on_this_property_that_runs_that_weekday(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO job_plans (id, property_id, plan_number, title, service_type, is_recurring, estimated_duration_minutes, status, contract_id, recurrence_day_of_week)
                   VALUES (84, 441, 'PLN-2026-0090', 'Monday grounds', 'Lawn Maintenance', 1, 180, 'active', 5, 1)");
        $auto = new OttoContractLogService($db, $this->uw($db), '2026-10-07');
        $this->assertSame(84, (int)$auto->choosePlan(5, 441, self::D, 210)['id'], 'Mon 2026-10-05 → the Monday plan');
        $this->assertSame(82, (int)$auto->choosePlan(5, 441, '2026-10-06', 120)['id'], 'Tue → closest length');
        $this->assertSame(82, (int)$auto->choosePlan(5, 999, self::D, 60)['id'], 'not on this property → the contract\'s main plan');
    }

    public function test_a_contract_visit_timed_on_the_wrong_day_is_moved_not_duplicated_and_undo_moves_it_back(): void
    {
        $db = $this->db();
        // The contract's visit was scheduled Tue Oct 6; the crew timed it Mon Oct 5 (8:15–11:35).
        $db->exec("UPDATE job_plans SET estimated_duration_minutes = 200 WHERE id = 82");
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status, visit_number) VALUES (2160, 82, '2026-10-06', 'scheduled', 'PLN-2026-0068-V002')");
        $db->exec("INSERT INTO job_time_entries (user_id, visit_id, start_time, end_time, status) VALUES (7, 2160, '" . self::D . " 08:15:00', '" . self::D . " 11:35:00', 'completed')");
        $uw = $this->uw($db);
        $this->assertSame([], $this->itemsFor($uw, 441), 'contract site: nothing to ask');
        $this->assertSame([], array_values(array_filter($uw->items(true), fn($i) => $i['kind'] === 'visit_date')), 'moved, not asked');
        $this->assertSame('2026-10-05', $db->query("SELECT scheduled_date FROM job_visits WHERE id = 2160")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE plan_id = 82")->fetchColumn(), 'no duplicate visit');
        $row = $db->query("SELECT * FROM otto_auto_visits")->fetch();
        $this->assertSame(['move', 2160, '2026-10-06', 'logged'], [$row['kind'], (int)$row['source_visit_id'], $row['moved_from'], $row['status']]);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM otto_auto_visits")->fetchColumn());

        $r = (new OttoContractLogService($db, $uw, '2026-10-07'))->undo((int)$row['id'], 1);
        $this->assertTrue($r['ok']);
        $this->assertSame('2026-10-06', $db->query("SELECT scheduled_date FROM job_visits WHERE id = 2160")->fetchColumn());
        // After undo Otto asks instead of moving it again.
        $ask = array_values(array_filter($uw->items(true), fn($i) => $i['kind'] === 'visit_date'));
        $this->assertSame(['otto:vdate:2160:' . self::D], array_column($ask, 'key'));
    }
}
