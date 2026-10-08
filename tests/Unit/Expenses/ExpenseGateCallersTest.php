<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every surface changes an expense through ExpenseGate (migration 1233): a representative caller
 * from each — Penny's card, the receipts@ inbox, trip attribution — plus a scan of the code that
 * fails when a new write to expenses / expense_line_items appears outside the gate.
 * (iOS / JWT callers: ExpenseServiceTest, ExpenseLineItemServiceTest, ExpenseApprovalServiceTest.)
 */
class ExpenseGateCallersTest extends TestCase
{
    // ── Penny's card ────────────────────────────────────────────────────

    private function cardDb(): PDO
    {
        $db = ExpenseGateTestDb::make();
        ExpenseGateTestDb::lawnboy412($db);
        $db->exec("CREATE TABLE expense_suggestions (id INTEGER PRIMARY KEY, expense_id INT, source TEXT, status TEXT, suggestion_json TEXT,
                   checks_json TEXT, current_json TEXT, used_image INT, outcome_json TEXT, decided_by INT, decided_at TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, can_approve_own_expenses INT DEFAULT 0)");
        $db->exec("INSERT INTO users (id) VALUES (6), (9)");
        $s = ['vendor' => ['value' => 'LAWNBOY'], 'accounting_category' => ['value' => 'Materials'], 'job' => ['value' => 501],
              'total' => ['value' => 218.40], 'gst' => ['value' => 10.00], 'pst' => ['value' => 8.40], 'subtotal' => ['value' => 200.00]];
        $db->prepare("INSERT INTO expense_suggestions (id, expense_id, source, status, suggestion_json) VALUES (1, 412, 'live', 'pending', ?)")
           ->execute([json_encode($s)]);
        return $db;
    }

    public function test_pennys_card_saves_the_split_and_approves_through_the_gate(): void
    {
        $db = $this->cardDb();
        $gate = new ExpenseGateSpy();
        $desk = new BookkeeperDeskService($db, null, $gate);
        $split = ['on' => true, 'lines' => [
            ['line_item_id' => 229, 'is_stock' => true, 'accounting_category' => 'Materials'],
            ['line_item_id' => 230, 'job_id' => 501, 'accounting_category' => 'Materials'],
        ]];
        $res = $desk->decide(1, ['accounting_category' => 'Materials', 'job' => '501', 'split' => json_encode($split)], ['id' => 9]);

        $this->assertTrue($res['ok'], $res['message']);
        $this->assertTrue($res['approved']);
        $this->assertCount(2, $gate->calls, 'the edits, then the approval');
        [$edit, $approve] = $gate->calls;
        $this->assertSame(['penny_card', 412, 9], [$edit['source'], $edit['id'], $edit['actor']['id']]);
        $this->assertSame([229, 230], array_column($edit['changes']['allocations'], 'line_item_id'));
        $this->assertTrue($edit['changes']['allocations'][0]['is_stock']);
        $this->assertSame(501, $edit['changes']['allocations'][1]['job_id']);
        $this->assertSame(['approved', 'approve', 'penny_card'], [$approve['changes']['status'], $approve['opts']['transition'], $approve['source']]);
    }

    public function test_the_card_end_to_end_on_the_412_receipt(): void
    {
        $db = $this->cardDb();
        $desk = new BookkeeperDeskService($db, null, new ExpenseGate($db, new ExpenseGateHooksSpy()));
        $res = $desk->decide(1, ['split' => json_encode(['on' => true, 'lines' => [
            ['line_item_id' => 229, 'is_stock' => true], ['line_item_id' => 230, 'job_id' => 501],
        ]])], ['id' => 9]);
        $this->assertTrue($res['approved'], $res['message']);
        $this->assertSame('approved', $db->query("SELECT status FROM expenses WHERE id = 412")->fetchColumn());
        $log = $db->query("SELECT action, fields FROM expense_change_log WHERE expense_id = 412 ORDER BY id")->fetchAll();
        $this->assertSame(['update', 'approve'], array_column($log, 'action'));
        $this->assertSame('job_id,property_id,allocations', $log[0]['fields'], 'Penny\'s job accepted on the header + the split');
        $this->assertSame(80.0, (float)$db->query("SELECT net_amount FROM expense_line_allocations WHERE expense_id = 412 AND job_id = 501")->fetchColumn());
    }

    public function test_an_unknown_category_on_a_split_line_is_refused(): void
    {
        $db = $this->cardDb();
        $gate = new ExpenseGateSpy();
        $res = (new BookkeeperDeskService($db, null, $gate))->decide(1, ['split' => ['on' => true, 'lines' => [['line_item_id' => 229, 'accounting_category' => 'Snacks']]]], ['id' => 9]);
        $this->assertFalse($res['ok']);
        $this->assertSame([], $gate->calls);
    }

    public function test_split_payload_reading(): void
    {
        $this->assertNull(ExpenseSplitService::choices(null));
        $this->assertSame([], ExpenseSplitService::choices('{"on":false}'));
        $c = ExpenseSplitService::choices(['on' => true, 'lines' => [['line_item_id' => '229', 'is_stock' => '1', 'job_id' => '501', 'asset_tag' => 'boat']]]);
        $this->assertSame([229, null, true, null], [$c[0]['line_item_id'], $c[0]['job_id'], $c[0]['is_stock'], $c[0]['asset_tag']], 'stock clears the job; unknown tag dropped');
    }

    // ── The receipts@ inbox ─────────────────────────────────────────────

    public function test_the_inbox_dismiss_is_the_gates_audited_cancel(): void
    {
        $db = ExpenseGateTestDb::make();
        $db->exec("CREATE TABLE receipt_inbox_messages (id INTEGER PRIMARY KEY, expense_id INT, outcome TEXT)");
        $db->exec("INSERT INTO expenses (id, expense_date, total, status, source) VALUES (600, '2026-10-07', 12.00, 'pending_approval', 'email_inbox'),
                                                                                    (601, '2026-10-07', 12.00, 'approved', 'email_inbox')");
        $svc = new ReceiptInboxService($db);
        $this->assertTrue($svc->dismiss(600, 6)['ok']);
        $this->assertSame('cancelled', $db->query("SELECT status FROM expenses WHERE id = 600")->fetchColumn());
        $log = $db->query("SELECT action, source, actor_user_id FROM expense_change_log WHERE expense_id = 600")->fetch();
        $this->assertSame(['cancel', 'email_inbox_review', 6], [$log['action'], $log['source'], (int)$log['actor_user_id']]);
        $this->assertFalse($svc->dismiss(601, 6)['ok'], 'an approved receipt is not the inbox\'s to dismiss');
    }

    // ── Trip attribution (Penny tags single-purpose receipts) ───────────

    public function test_trip_attribution_tags_through_the_gate_as_penny(): void
    {
        $db = ExpenseGateTestDb::make();
        ExpenseGateTestDb::lawnboy412($db);
        $db->exec("CREATE TABLE ops_trip_job_costs (id INTEGER PRIMARY KEY, run_id INT, run_date TEXT, trip_key TEXT, kind TEXT, property_id INT,
                   job_plan_id INT, visit_id INT, source TEXT, expense_id INT, expense_line_id INT, amount REAL, is_stock INT, label TEXT)");
        $db->exec("DELETE FROM expense_line_items WHERE id = 229");   // mulch only — a single-purpose receipt
        $db->exec("INSERT INTO ops_trip_runs (id, run_date, kind, from_property_id, labour_cost, truck_cost, receipt_ids, arrived_at)
                   VALUES (1, '2026-10-07', 'supplier', 31, 20, 10, '412', '2026-10-07 09:10:00')");
        $gate = new ExpenseGateSpy();
        (new TripAttributionService($db, '2026-10-07', $gate))->attribute('2026-10-07');
        $this->assertCount(1, $gate->calls);
        $this->assertSame([412, ['job_id' => 501], 'trip_attribution', 'penny'],
                          [$gate->calls[0]['id'], $gate->calls[0]['changes'], $gate->calls[0]['source'], $gate->calls[0]['actor']['kind']]);
        $this->assertIsCallable($gate->calls[0]['opts']['only_if'], 'never overwrites a job');
    }

    // ── Nothing writes an expense outside the gate ──────────────────────

    /**
     * Writes to expenses / expense_line_items that are deliberately NOT routed through the gate —
     * none changes money (amount, tax, category, job, date, vendor, status, lines):
     */
    private const OUTSIDE_THE_GATE = [
        'app/Modules/Expenses/Services/ExpenseGate.php'               => 'the gate itself',
        'app/Services/Receipts/ExpenseLineItems.php'                  => 'saveLineItems() — called only by the gate',
        'app/Services/Receipts/ReceiptLearning.php'                   => 'learning bookkeeping: capture baseline + learning_recorded_at claim',
        'app/Modules/Expenses/Services/ExpenseLineItemService.php'    => 'link(): a line\'s product link + stock, never money',
        'app/Modules/Products/Api/api-cost-factors.php'               => 'overhead_item_id: the GGOB overhead link',
        'public/crm/api/tasks.php'                                    => 'purchase_task_id: the task a purchase came from',
    ];

    public function test_no_expense_write_outside_the_gate(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (['app', 'public'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $path = substr($f->getPathname(), strlen($root) + 1);
                if (substr($path, -4) !== '.php' || strpos($path, '/database/') !== false || strpos($path, '/migrations/') !== false) continue;
                $src = (string)file_get_contents($f->getPathname());
                if (preg_match('/\b(UPDATE\s+`?expenses`?\s|INSERT\s+INTO\s+`?expenses`?[\s(]|DELETE\s+FROM\s+`?expenses`?\s|INSERT\s+INTO\s+`?expense_line_items`?|UPDATE\s+`?expense_line_items`?\s|DELETE\s+FROM\s+`?expense_line_items`?\s)/i', $src)) {
                    $found[] = $path;
                }
            }
        }
        sort($found);
        $allowed = array_keys(self::OUTSIDE_THE_GATE);
        sort($allowed);
        $this->assertSame($allowed, $found, 'a new write to an expense must go through ExpenseGate::apply() (or be added here with why)');
    }
}
