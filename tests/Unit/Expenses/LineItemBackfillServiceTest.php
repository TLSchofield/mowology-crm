<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LineItemBackfillServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = ExpenseGateTestDb::make();
        $good = "RONA\nITEM\nQTY PRICE\nTOTAL\n3295249290177 2 5.00EA\nGLOVES NITRILE L\n10.00P\nSubtotal:\n$10.00\nGST:\n$0.50\nTotal:\n$10.50";
        $bad = str_replace('10.00P', '8.00P', $good);
        foreach ([1 => $good, 2 => $bad, 3 => $good, 4 => $good, 5 => $good, 6 => $good] as $id => $text) {
            $st = $db->prepare("INSERT INTO expenses (id, expense_date, vendor_name_raw, amount, total, status, forwarded_to_accounting, raw_ocr_json)
                                VALUES (?, '2026-11-01', 'Rona', 10, 10.50, 'draft', ?, ?)");
            $st->execute([$id, $id === 4 ? 1 : 0, json_encode(['text' => $text, 'parsed' => [], 'source' => 'inbox'])]);
        }
        $db->exec("INSERT INTO expense_line_items (expense_id, name, line_total, sort_order) VALUES (3, 'Existing', 10, 0), (6, 'Linked', 10, 0)");
        $db->exec("UPDATE expense_line_items SET product_id = 99 WHERE expense_id = 6");
        $db->exec("INSERT INTO expense_line_allocations (expense_id, label, net_amount, created_at) VALUES (5, 'Split', 10, '2026-11-01')");
        return $db;
    }

    public function test_fills_only_fitted_unprotected_receipts(): void
    {
        $gate = new ExpenseGateSpy();
        $r = (new LineItemBackfillService($this->db(), $gate))->run(true);
        $this->assertSame(2, $r['checked']);
        $this->assertSame(1, $r['filled']);
        $this->assertSame(1, $gate->calls[0]['id']);
        $this->assertSame(['reread', 'line_item_backfill'], [$gate->calls[0]['changes']['line_items_source'], $gate->calls[0]['source']]);
        $this->assertIsCallable($gate->calls[0]['opts']['locked_if']);
    }

    public function test_locked_recheck_refuses_changed_totals_or_new_lines(): void
    {
        $gate = new ExpenseGateSpy();
        (new LineItemBackfillService($this->db(), $gate))->run(true);
        $check = $gate->calls[0]['opts']['locked_if'];
        $snapshot = ['expense' => ['status' => 'draft', 'forwarded_to_accounting' => 0, 'amount' => 10, 'total' => 10.50],
                     'lines' => [], 'allocations' => []];
        $this->assertTrue($check($snapshot));
        $edited = $snapshot;
        $edited['expense']['amount'] = 15;
        $edited['expense']['total'] = 15.75;
        $this->assertFalse($check($edited));
        $this->assertFalse($check(['lines' => [['id' => 1, 'product_id' => null]]] + $snapshot));
    }

    public function test_limit_caps_writes_not_the_scan(): void
    {
        // Receipt 2 (oldest unfittable) must not use up a limit of 1 and hide receipt 1's fit… ids run oldest first,
        // so put an unfittable receipt ahead of a fittable one and ask for one write.
        $db = $this->db();
        $db->exec("UPDATE expenses SET id = 100 WHERE id = 1");
        $gate = new ExpenseGateSpy();
        $r = (new LineItemBackfillService($db, $gate))->run(true, 1);
        $this->assertSame(1, $r['filled']);
        $this->assertSame(100, $gate->calls[0]['id']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $gate = new ExpenseGateSpy();
        $r = (new LineItemBackfillService($this->db(), $gate))->run(false);
        $this->assertSame(1, $r['would_fill']);
        $this->assertSame([], $gate->calls);
    }
}
