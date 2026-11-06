<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LineItemRecoveryTest extends TestCase
{
    public function test_inbox_create_changes_include_only_fitted_items(): void
    {
        $parsed = ['line_items' => [['name' => 'Soil', 'amount' => 12.50]]];
        $this->assertSame('inbox', ReceiptInboxService::fittedLineItemChanges($parsed, 12.50, 13.13)['line_items_source']);
        $this->assertSame([], ReceiptInboxService::fittedLineItemChanges($parsed, 20.00, 20.00));
        $this->assertSame('inbox', ReceiptInboxService::fittedLineItemChanges($parsed, 0.0, 13.13, 0.63, 0.0)['line_items_source']);
    }

    public function test_auto_apply_uses_tax_fallback_when_subtotal_is_missing(): void
    {
        $db = $this->pennyDb();
        $db->exec("UPDATE expenses SET amount = 0, total = 10.50, gst_amount = 0.50, pst_amount = 0 WHERE id = 1");
        $gate = new ExpenseGateSpy();
        $r = (new BookkeeperDeskService($db, null, $gate))->autoApplyPennyItems();
        $this->assertContains(1, $r['ids']);
    }

    private function pennyDb(): PDO
    {
        $db = ExpenseGateTestDb::make();
        $db->exec("CREATE TABLE expense_suggestions (id INTEGER PRIMARY KEY, expense_id INT, source TEXT, status TEXT, suggestion_json TEXT)");
        for ($id = 1; $id <= 7; $id++) {
            $forwarded = $id === 3 ? 1 : 0;
            $db->exec("INSERT INTO expenses (id, expense_date, amount, total, status, forwarded_to_accounting)
                       VALUES ({$id}, '2026-11-01', 10, 10.50, 'draft', {$forwarded})");
            $items = $id === 7 ? [['name' => 'Wrong', 'amount' => 9.00]] : [['name' => 'Item', 'amount' => 10.00]];
            $st = $db->prepare("INSERT INTO expense_suggestions (id, expense_id, source, status, suggestion_json) VALUES (?, ?, 'live', 'pending', ?)");
            $st->execute([$id, $id, json_encode(['line_items' => $items])]);
        }
        $db->exec("INSERT INTO expense_line_items (expense_id, name, line_total, sort_order) VALUES (2, 'Old', 2, 0), (5, 'Linked', 2, 0), (6, 'Good', 10, 0)");
        $db->exec("UPDATE expense_line_items SET product_id = 99 WHERE expense_id = 5");
        $db->exec("INSERT INTO expense_line_allocations (expense_id, label, net_amount, created_at) VALUES (4, 'Split', 10, '2026-11-01')");
        return $db;
    }

    public function test_auto_apply_uses_gate_and_skips_protected_or_unfitted_receipts(): void
    {
        $db = $this->pennyDb();
        $gate = new ExpenseGateSpy();
        $r = (new BookkeeperDeskService($db, null, $gate))->autoApplyPennyItems();
        $this->assertEqualsCanonicalizing([1, 2], $r['ids']);
        $this->assertSame(2, $r['applied']);
        $this->assertSame(['penny_auto', 'penny'], [$gate->calls[0]['source'], $gate->calls[0]['changes']['line_items_source']]);
        $this->assertIsCallable($gate->calls[0]['opts']['locked_if']);
        $this->assertSame(1, $r['skipped']['forwarded']);
        $this->assertSame(1, $r['skipped']['split']);
        $this->assertSame(1, $r['skipped']['product_linked']);
        $this->assertSame(1, $r['skipped']['already_adds_up']);
        $this->assertSame(1, $r['skipped']['penny_does_not_add_up']);
    }

    public function test_locked_recheck_refuses_changed_totals_and_a_read_no_longer_pending(): void
    {
        $db = $this->pennyDb();
        $gate = new ExpenseGateSpy();
        (new BookkeeperDeskService($db, null, $gate))->autoApplyPennyItems();
        $call = array_values(array_filter($gate->calls, static function (array $c): bool { return $c['id'] === 1; }))[0];
        $check = $call['opts']['locked_if'];
        $snapshot = ['expense' => ['status' => 'draft', 'forwarded_to_accounting' => 0, 'amount' => 10, 'total' => 10.50],
                     'lines' => [], 'allocations' => []];
        $this->assertTrue($check($snapshot));
        // The owner changed the receipt's amounts after the batch read them: Penny's items no longer fit.
        $edited = $snapshot;
        $edited['expense']['amount'] = 12;
        $edited['expense']['total'] = 12.60;
        $this->assertFalse($check($edited));
        // The owner answered the read before the write.
        $db->exec("UPDATE expense_suggestions SET status = 'accepted' WHERE id = 1");
        $this->assertFalse($check($snapshot));
        // Penny read the receipt again: the older read must not land.
        $db->exec("UPDATE expense_suggestions SET status = 'pending' WHERE id = 1");
        $db->exec("INSERT INTO expense_suggestions (id, expense_id, source, status, suggestion_json) VALUES (50, 1, 'live', 'pending', '{}')");
        $this->assertFalse($check($snapshot));
    }

    public function test_auto_apply_does_not_report_a_gate_refusal_as_applied(): void
    {
        $db = $this->pennyDb();
        $gate = new ExpenseGateSpy();
        $gate->extra = ['noop' => true];
        $r = (new BookkeeperDeskService($db, null, $gate))->autoApplyPennyItems();
        $this->assertSame(0, $r['applied']);
        $this->assertSame([], $r['ids']);
        $this->assertSame(2, $r['skipped']['changed_before_apply']);
    }

    public function test_cron_recovers_items_before_checking_model_readiness(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Modules/Expenses/Cron/penny_prepare.php');
        $auto = strpos($source, '$auto = $desk->autoApplyPennyItems(50, $dryRun);');
        $modelCheck = strpos($source, 'if (!$dryRun && !$svc->ready())');
        $this->assertNotFalse($auto);
        $this->assertNotFalse($modelCheck);
        $this->assertLessThan($modelCheck, $auto);
    }

    public function test_auto_apply_dry_run_writes_nothing(): void
    {
        $db = $this->pennyDb();
        $gate = new ExpenseGateSpy();
        $r = (new BookkeeperDeskService($db, null, $gate))->autoApplyPennyItems(50, true);
        $this->assertSame(2, $r['would_apply']);
        $this->assertSame([], $gate->calls);
    }
}
