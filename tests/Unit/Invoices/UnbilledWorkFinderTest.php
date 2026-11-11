<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UnbilledWorkFinder — other unbilled work at the address being invoiced.
 *
 * Fixture mirrors the real case (2026-10-08, TIM LOUIS, property 29):
 *   plan 24 "7 Day Lawn Cut Service" $74.95 per visit
 *   #2112 scheduled Fri Oct 2, SKIPPED, but a 22-min job timer ran Tue Sep 29 09:59–10:20
 *   #2412 Core Aeration, completed Thu Sep 24 priced $0
 *   #2500 today's cut (the visit being invoiced — excluded)
 * Runs on in-memory SQLite so the SQL is exercised, not mocked.
 */
class UnbilledWorkFinderTest extends TestCase
{
    private PDO $db;
    private const TODAY = '2026-10-08';
    private array $moved = [];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, company_id INT, contract_id INT,
                   title TEXT, service_type TEXT, price_per_visit REAL, estimated_amount REAL, pricing_model TEXT DEFAULT 'per_visit')");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, visit_number TEXT, plan_id INT, stop_id INT, status TEXT,
                   scheduled_date TEXT, status_changed_at TEXT, started_at TEXT, completed_at TEXT, actual_duration_minutes INT,
                   completion_notes TEXT, actual_amount REAL, is_invoiced INT DEFAULT 0, invoice_id INT, assigned_crew_id INT,
                   sequence_index INT DEFAULT 1, UNIQUE (plan_id, scheduled_date, sequence_index))");
        $db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, status TEXT, billing_cycle TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, property_id INT, visit_id INT, status TEXT,
                   invoice_date TEXT, subtotal REAL DEFAULT 0, tax_rate REAL DEFAULT 0.05, tax_amount REAL, total_amount REAL,
                   total REAL, balance_due REAL, pdf_path TEXT)");
        $db->exec("CREATE TABLE invoice_line_items (id INTEGER PRIMARY KEY, invoice_id INT, description TEXT, quantity REAL,
                   unit_price REAL, line_total REAL, visit_id INT, service_date TEXT, sort_order INT)");
        $db->exec("CREATE TABLE job_time_entries (id INTEGER PRIMARY KEY, visit_id INT, user_id INT, start_time TEXT, end_time TEXT,
                   duration_minutes INT, status TEXT DEFAULT 'completed')");
        $db->exec("CREATE TABLE plan_line_items (id INTEGER PRIMARY KEY, plan_id INT, line_total REAL)");
        $db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, base_price REAL)");
        $db->exec("CREATE TABLE otto_suggestions (id INTEGER PRIMARY KEY, kind TEXT, subject_type TEXT, subject_id INT,
                   for_date TEXT, suggestion_json TEXT, status TEXT)");
        $db->exec("CREATE TABLE invoice_unbilled_claims (id INTEGER PRIMARY KEY, invoice_id INT, visit_id INT, kind TEXT,
                   previous_status TEXT, scheduled_date TEXT, service_date TEXT, amount REAL, evidence TEXT, claimed_by INT, claimed_at TEXT)");
        $this->db = $db;

        $this->plan(24, 29, 'Lawn cut', 'Lawn Maintenance', 74.95);
        $this->plan(31, 29, 'Core Aeration', 'Core Aeration', 0);
        $this->visit(2112, 24, 'skipped', '2026-10-02');
        $this->timer(2112, '2026-09-29 09:59:00', '2026-09-29 10:20:00', 22);
        $this->visit(2412, 31, 'completed', '2026-09-24', '2026-09-24 11:00:00');
        $this->visit(2500, 24, 'in_progress', self::TODAY);      // today's cut, being invoiced
        $this->db->exec("INSERT INTO products (name, base_price) VALUES ('Core Aeration', 120.00)");
        // Today's invoice for #2500 (the one the extras go on)
        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, visit_id, status, invoice_date, tax_rate)
                         VALUES (439, 'INV-2026-0439', 29, 2500, 'draft', '" . self::TODAY . "', 0.05)");
        $this->db->exec("INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total, visit_id, service_date, sort_order)
                         VALUES (439, 'Lawn cut — Thu Oct 8', 1, 74.95, 74.95, 2500, '" . self::TODAY . "', 0)");
    }

    private function plan(int $id, int $property, string $title, string $type, float $price, ?int $contract = null, string $model = 'per_visit', int $company = 7): void
    {
        $this->db->prepare("INSERT INTO job_plans (id, property_id, company_id, contract_id, title, service_type, price_per_visit, pricing_model) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$id, $property, $company, $contract, $title, $type, $price, $model]);
    }

    private function visit(int $id, int $plan, string $status, string $date, ?string $completedAt = null, ?int $invoiceId = null, int $seq = 1): void
    {
        $this->db->prepare("INSERT INTO job_visits (id, visit_number, plan_id, status, scheduled_date, completed_at, invoice_id, is_invoiced, stop_id, assigned_crew_id, sequence_index) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, 'V' . $id, $plan, $status, $date, $completedAt, $invoiceId, $invoiceId ? 1 : 0, 900 + $id, 3, $seq]);
    }

    private function timer(int $visit, string $start, ?string $end, ?int $min, string $status = 'completed'): void
    {
        $this->db->prepare("INSERT INTO job_time_entries (visit_id, user_id, start_time, end_time, duration_minutes, status) VALUES (?,?,?,?,?,?)")
            ->execute([$visit, 3, $start, $end, $min, $status]);
    }

    private function finder(): UnbilledWorkFinder
    {
        return new UnbilledWorkFinder($this->db, self::TODAY, function (int $vid, int $pid, string $day, ?int $crew, ?int $old) {
            $this->moved[] = [$vid, $pid, $day, $crew, $old];
        });
    }

    private function find(array $opts = []): array
    {
        $r = $this->finder()->find(29, $opts + ['exclude_visit_ids' => [2500], 'invoice_id' => 439]);
        $by = [];
        foreach ($r['items'] as $it) $by[$it['visit_id']] = $it;
        return $by;
    }

    private function visitRow(int $id): array
    {
        $s = $this->db->prepare("SELECT * FROM job_visits WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch();
    }

    // ── find ────────────────────────────────────────────────────────────────

    public function testTheRealCaseSkippedVisitWithATimerIsPossiblyDoneOnTheTimedDay(): void
    {
        $it = $this->find()[2112];
        $this->assertSame('possibly_done', $it['kind']);
        $this->assertSame('2026-09-29', $it['service_date']);
        $this->assertSame('Lawn cut — Tue Sep 29 (crew on site 9:59–10:20)', $it['description']);
        $this->assertSame(74.95, $it['amount']);
        $this->assertSame(22, $it['on_site_minutes']);
        $this->assertStringContainsString('Job timer ran Tue Sep 29 9:59–10:20 (22 min)', implode(' ', $it['evidence']));
        $this->assertStringContainsString('skipped (scheduled Fri Oct 2)', implode(' ', $it['evidence']));
        $this->assertFalse($it['preselect'], 'possibly-done is never pre-ticked');
    }

    public function testZeroPricedCompletedVisitIsFlaggedWithASuggestedPrice(): void
    {
        $it = $this->find()[2412];
        $this->assertSame('zero_price', $it['kind']);
        $this->assertTrue($it['needs_price']);
        $this->assertSame(0.0, $it['amount']);
        $this->assertSame(120.0, $it['suggested_amount']);
        $this->assertSame('Priced $0 — add price?', $it['badge']);
        $this->assertFalse($it['preselect']);
    }

    public function testCompletedUninvoicedVisitIsOfferedAndPreTicked(): void
    {
        $this->visit(2200, 24, 'completed', '2026-09-17', '2026-09-17 10:30:00');
        $r = $this->finder()->find(29, ['exclude_visit_ids' => [2500], 'invoice_id' => 439]);
        $it = array_values(array_filter($r['items'], fn($i) => $i['visit_id'] === 2200))[0];
        $this->assertSame('completed', $it['kind']);
        $this->assertSame('Lawn cut — Thu Sep 17', $it['description']);
        $this->assertTrue($it['preselect']);
        $this->assertSame(74.95, $r['subtotal_preselected']);
    }

    public function testTheVisitBeingInvoicedIsNeverOffered(): void
    {
        $this->assertArrayNotHasKey(2500, $this->find());
    }

    public function testContractBilledPlansAreExcluded(): void
    {
        $this->db->exec("INSERT INTO contracts (id, status, billing_cycle) VALUES (5, 'active', 'monthly')");
        $this->plan(40, 29, 'Strata grounds', 'Lawn Maintenance', 300, 5);
        $this->visit(3000, 40, 'completed', '2026-09-20', '2026-09-20 09:00:00');
        $this->assertArrayNotHasKey(3000, $this->find());

        // A per-visit-billed or paused contract does not block per-visit invoicing.
        $this->db->exec("UPDATE contracts SET billing_cycle = 'per_visit' WHERE id = 5");
        $this->assertArrayHasKey(3000, $this->find());
    }

    public function testNonPerVisitPlansAreExcluded(): void
    {
        $this->plan(41, 29, 'Monthly care', 'Lawn Maintenance', 0, null, 'monthly_flat');
        $this->visit(3001, 41, 'completed', '2026-09-20', '2026-09-20 09:00:00');
        $this->assertArrayNotHasKey(3001, $this->find());
    }

    public function testAlreadyInvoicedVisitsAreExcluded(): void
    {
        // Linked on the visit
        $this->visit(3100, 24, 'completed', '2026-09-10', '2026-09-10 09:00:00', 400);
        // Not linked on the visit, but on a line of another live invoice
        $this->visit(3101, 24, 'completed', '2026-09-03', '2026-09-03 09:00:00');
        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, visit_id, status, invoice_date) VALUES (401, 'INV-2026-0401', 29, NULL, 'sent', '2026-09-04')");
        $this->db->exec("INSERT INTO invoice_line_items (invoice_id, description, line_total, visit_id) VALUES (401, 'cut', 74.95, 3101)");
        // The source visit of another invoice
        $this->visit(3102, 24, 'completed', '2026-08-27', '2026-08-27 09:00:00');
        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, visit_id, status, invoice_date) VALUES (402, 'INV-2026-0402', 29, 3102, 'paid', '2026-08-28')");
        // On a CANCELLED invoice only → still unbilled
        $this->visit(3103, 24, 'completed', '2026-08-20', '2026-08-20 09:00:00');
        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, visit_id, status, invoice_date) VALUES (403, 'INV-2026-0403', 29, NULL, 'cancelled', '2026-08-21')");
        $this->db->exec("INSERT INTO invoice_line_items (invoice_id, description, line_total, visit_id) VALUES (403, 'cut', 74.95, 3103)");

        $by = $this->find();
        $this->assertArrayNotHasKey(3100, $by);
        $this->assertArrayNotHasKey(3101, $by);
        $this->assertArrayNotHasKey(3102, $by);
        $this->assertArrayHasKey(3103, $by);
    }

    public function testNoEvidenceOutsideTheWindowOrAnotherPayerIsNotOffered(): void
    {
        $this->visit(3200, 24, 'skipped', '2026-09-15');                                  // skipped, no timer
        $this->visit(3201, 24, 'completed', '2026-07-01', '2026-07-01 09:00:00');           // older than 60 days
        $this->visit(3202, 24, 'skipped', '2026-09-08');
        $this->timer(3202, '2026-09-08 09:00:00', '2026-09-08 09:30:00', 30, 'void');      // void timer is no evidence
        $this->plan(42, 29, 'Previous owner cut', 'Lawn Maintenance', 60, null, 'per_visit', 99);
        $this->visit(3203, 42, 'completed', '2026-09-20', '2026-09-20 09:00:00');           // another payer

        $by = $this->find(['company_id' => 7]);
        foreach ([3200, 3201, 3202, 3203] as $id) $this->assertArrayNotHasKey($id, $by, "visit {$id}");
        $this->assertArrayHasKey(3203, $this->find(), 'without a payer filter the other company is offered');
    }

    public function testOttoMoveSuggestionIsEvidenceWithoutATimer(): void
    {
        $this->visit(3300, 24, 'scheduled', '2026-10-06');
        $this->db->exec("INSERT INTO otto_suggestions (kind, subject_type, subject_id, for_date, status) VALUES ('visit_date', 'visit', 3300, '2026-10-05', 'open')");
        $it = $this->find()[3300];
        $this->assertSame('possibly_done', $it['kind']);
        $this->assertSame('2026-10-05', $it['service_date']);
        $this->assertStringContainsString('Otto: done Mon Oct 5', implode(' ', $it['evidence']));
    }

    public function testAHandMadeInvoiceAfterTheWorkIsAWarningAndStopsPreTicking(): void
    {
        $this->visit(2200, 24, 'completed', '2026-09-17', '2026-09-17 10:30:00');
        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, visit_id, status, invoice_date, subtotal) VALUES (441, 'INV-2026-0441', 29, NULL, 'sent', '2026-10-08', 74.95)");
        $this->db->exec("INSERT INTO invoice_line_items (invoice_id, description, line_total, visit_id) VALUES (441, 'Lawn cut Sep 29', 74.95, NULL)");
        $by = $this->find();
        $this->assertStringContainsString('INV-2026-0441', implode(' ', $by[2112]['warnings']));
        $this->assertFalse($by[2200]['preselect']);
    }

    public function testSamePlanAlreadyDoneThatDayIsAWarning(): void
    {
        $this->visit(2111, 24, 'completed', '2026-09-29', '2026-09-29 11:00:00', 430);
        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, visit_id, status, invoice_date) VALUES (430, 'INV-2026-0430', 29, 2111, 'sent', '2026-09-29')");
        $this->assertStringContainsString('#2111', implode(' ', $this->find()[2112]['warnings']));
    }

    public function testOttoUnscheduledDaysComeBackAsHints(): void
    {
        $this->db->exec("INSERT INTO otto_suggestions (kind, subject_type, subject_id, for_date, suggestion_json, status)
                         VALUES ('unscheduled', 'property', 29, '2026-10-01', '{\"start\":\"13:05\",\"end\":\"13:40\"}', 'open')");
        $r = $this->finder()->find(29, ['exclude_visit_ids' => [2500]]);
        $this->assertCount(1, $r['hints']);
        $this->assertStringContainsString('Thu Oct 1 13:05–13:40 with nothing scheduled', $r['hints'][0]['text']);
    }

    // ── claim ───────────────────────────────────────────────────────────────

    private function claim(array $sel, array $opts = []): array
    {
        return $this->finder()->claim(439, $sel, 1, $opts + [
            'property_id' => 29, 'exclude_visit_ids' => [2500], 'can_mark_done' => true,
            'invoice_number' => 'INV-2026-0439', 'actor_name' => 'Tim',
        ]);
    }

    public function testClaimingThePossiblyDoneVisitMarksItCompletedOnTheRealDayAtomically(): void
    {
        $this->db->beginTransaction();
        $r = $this->claim([['visit_id' => 2112]]);
        $tot = $this->finder()->retotalInvoice(439);
        $this->db->commit();

        $this->assertSame([2112], $r['marked_done']);
        $this->assertSame(74.95, $r['subtotal_added']);
        $v = $this->visitRow(2112);
        $this->assertSame('completed', $v['status']);
        $this->assertSame(439, (int)$v['invoice_id']);
        $this->assertSame(1, (int)$v['is_invoiced']);
        $this->assertSame('2026-09-29', $v['scheduled_date']);
        $this->assertSame('2026-09-29 10:20:00', $v['completed_at']);
        $this->assertSame('2026-09-29 09:59:00', $v['started_at']);
        $this->assertSame(22, (int)$v['actual_duration_minutes']);
        $this->assertStringContainsString('Marked done Tue Sep 29 while invoicing INV-2026-0439 (Tim)', $v['completion_notes']);
        $this->assertStringContainsString('Was skipped, scheduled Fri Oct 2', $v['completion_notes']);
        $this->assertSame([[2112, 29, '2026-09-29', 3, 3012]], $this->moved);

        $line = $this->db->query("SELECT * FROM invoice_line_items WHERE invoice_id = 439 AND visit_id = 2112")->fetch();
        $this->assertSame('Lawn cut — Tue Sep 29 (crew on site 9:59–10:20)', $line['description']);
        $this->assertSame('2026-09-29', $line['service_date']);

        // GST added on top of both cuts.
        $this->assertSame(149.9, $tot['subtotal']);
        $this->assertSame(7.5, $tot['tax_amount']);
        $this->assertSame(157.4, $tot['total']);

        $audit = $this->db->query("SELECT * FROM invoice_unbilled_claims")->fetchAll();
        $this->assertCount(1, $audit);
        $this->assertSame('possibly_done', $audit[0]['kind']);
        $this->assertSame('skipped', $audit[0]['previous_status']);
    }

    public function testCompletedClaimOnlyLinksTheVisit(): void
    {
        $this->visit(2200, 24, 'completed', '2026-09-17', '2026-09-17 10:30:00');
        $this->db->beginTransaction();
        $this->claim([2200]);
        $this->db->commit();
        $v = $this->visitRow(2200);
        $this->assertSame(439, (int)$v['invoice_id']);
        $this->assertSame('2026-09-17', $v['scheduled_date']);
        $this->assertNull($v['completion_notes']);
        $this->assertSame([], $this->moved);
    }

    public function testZeroPricedClaimNeedsAPriceAndRecordsIt(): void
    {
        $this->db->beginTransaction();
        try {
            $this->claim([['visit_id' => 2412]]);
            $this->fail('a $0 line must not be billed');
        } catch (UnbilledWorkConflict $e) {
            $this->db->rollBack();
            $this->assertStringContainsString('priced $0', $e->getMessage());
        }
        $this->assertNull($this->visitRow(2412)['invoice_id']);

        $this->db->beginTransaction();
        $r = $this->claim([['visit_id' => 2412, 'amount' => 120]]);
        $this->db->commit();
        $this->assertSame(120.0, $r['lines'][0]['amount']);
        $v = $this->visitRow(2412);
        $this->assertSame(120.0, (float)$v['actual_amount']);
        $this->assertSame(439, (int)$v['invoice_id']);
    }

    public function testCrewCannotMarkAVisitDone(): void
    {
        $this->db->beginTransaction();
        $this->expectException(UnbilledWorkConflict::class);
        try {
            $this->claim([['visit_id' => 2112]], ['can_mark_done' => false]);
        } finally {
            $this->db->rollBack();
        }
    }

    public function testNoDoubleBillingAVisitInvoicedInTheMeantimeRollsEverythingBack(): void
    {
        $this->visit(2200, 24, 'completed', '2026-09-17', '2026-09-17 10:30:00');
        // Another device invoices #2200 between the list being shown and Send being pressed.
        $this->db->exec("UPDATE job_visits SET invoice_id = 450, is_invoiced = 1 WHERE id = 2200");

        $this->db->beginTransaction();
        try {
            $this->claim([['visit_id' => 2112], ['visit_id' => 2200]]);
            $this->fail('expected a conflict');
        } catch (UnbilledWorkConflict $e) {
            $this->db->rollBack();
        }
        // #2112's changes made before the conflict were rolled back with the invoice.
        $v = $this->visitRow(2112);
        $this->assertSame('skipped', $v['status']);
        $this->assertNull($v['invoice_id']);
        $this->assertSame(450, (int)$this->visitRow(2200)['invoice_id']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM invoice_line_items WHERE visit_id IN (2112, 2200)")->fetchColumn());
    }

    public function testAClaimedVisitCannotBeClaimedAgainByAnotherInvoice(): void
    {
        $this->db->beginTransaction();
        $this->claim([['visit_id' => 2112]]);
        $this->db->commit();

        $this->db->exec("INSERT INTO invoices (id, invoice_number, property_id, status, invoice_date) VALUES (460, 'INV-2026-0460', 29, 'draft', '" . self::TODAY . "')");
        $this->db->beginTransaction();
        $this->expectException(UnbilledWorkConflict::class);
        try {
            $this->finder()->claim(460, [['visit_id' => 2112]], 1, ['property_id' => 29, 'can_mark_done' => true]);
        } finally {
            $this->db->rollBack();
        }
    }

    public function testFormLinesAreNotDuplicatedWhenTheCallerInsertedThem(): void
    {
        $this->visit(2200, 24, 'completed', '2026-09-17', '2026-09-17 10:30:00');
        $this->db->beginTransaction();
        // invoices/create.php already wrote the line (edited by the person) with the visit on it.
        $this->db->exec("INSERT INTO invoice_line_items (invoice_id, description, line_total, visit_id) VALUES (439, 'Lawn cut — Thu Sep 17', 80, 2200)");
        $this->claim([2200], ['insert_lines' => false]);
        $this->db->commit();
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM invoice_line_items WHERE visit_id = 2200")->fetchColumn());
        $this->assertSame(439, (int)$this->visitRow(2200)['invoice_id']);
    }

    public function testClaimRefusesToRunOutsideATransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->claim([2112]);
    }

    public function testNormaliseSelections(): void
    {
        $this->assertSame(
            [['visit_id' => 5, 'amount' => null], ['visit_id' => 6, 'amount' => 12.5]],
            UnbilledWorkFinder::normaliseSelections(['5', ['visit_id' => 6, 'amount' => '12.5'], ['visit_id' => 5], 0, 'x'])
        );
    }
}
