<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ExpenseGate — the one door for every expense change (migration 1233). Each change is
 * validated, written (only what changed), audited (who / where / before / after), and each
 * learning / books signal fires exactly once.
 */
class ExpenseGateTest extends TestCase
{
    private PDO $db;
    private ExpenseGateHooksSpy $hooks;
    private ExpenseGate $gate;

    protected function setUp(): void
    {
        $this->db = ExpenseGateTestDb::make();
        ExpenseGateTestDb::lawnboy412($this->db);
        $this->hooks = new ExpenseGateHooksSpy();
        $this->gate = new ExpenseGate($this->db, $this->hooks);
    }

    private function audit(int $id): array
    {
        $s = $this->db->prepare("SELECT * FROM expense_change_log WHERE expense_id = ? ORDER BY id");
        $s->execute([$id]);
        return $s->fetchAll();
    }

    private function row(int $id): array
    {
        return $this->db->query("SELECT * FROM expenses WHERE id = {$id}")->fetch();
    }

    private const TIM = ['id' => 6, 'kind' => 'user'];

    // ── Create ──────────────────────────────────────────────────────────

    public function test_a_create_writes_audits_and_teaches_once(): void
    {
        $res = $this->gate->apply(null, ExpenseGate::rowFromInput([
            'vendor_id' => 7, 'vendor_name_raw' => 'LAWNBOY', 'amount' => 40, 'gst_amount' => 2, 'raw_ocr_json' => 'LAWNBOY CBM 1 40.00',
            'line_items_source' => 'ocr',
        ], ['expense_date' => '2026-10-08', 'total' => 42.00, 'status' => 'pending_approval', 'created_by' => 6])
            + ['line_items' => [['name' => 'CBM', 'quantity' => 1, 'line_total' => 40, 'product_id' => 11]]],
            self::TIM, 'ios_save', ['ocr_parsed' => ['total' => 42], 'learn_lines' => ['line_items' => []], 'price_intel' => true]);

        $id = $res['expense_id'];
        $this->assertSame('create', $res['action']);
        $this->assertSame('pending_approval', $this->row($id)['status']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = {$id}")->fetchColumn());
        $this->assertSame(3.0, (float)$this->db->query("SELECT current_stock FROM products WHERE id = 11")->fetchColumn(), 'stock in');
        $a = $this->audit($id);
        $this->assertCount(1, $a);
        $this->assertSame(['create', 'ios_save', 6], [$a[0]['action'], $a[0]['source'], (int)$a[0]['actor_user_id']]);
        foreach (['storeBaseline', 'learnLines', 'facts', 'duplicates', 'priceIntel'] as $h) {
            $this->assertSame(1, $this->hooks->count($h), $h . ' once');
        }
        $this->assertSame(0, $this->hooks->count('learnConfirmed'), 'not confirmed yet');
    }

    public function test_a_create_cannot_be_born_approved(): void
    {
        $res = $this->gate->apply(null, ['expense_date' => '2026-10-08', 'total' => 5, 'status' => 'approved'], self::TIM, 'desktop_create');
        $this->assertSame('draft', $this->row($res['expense_id'])['status'], 'only the audited approve may set approved');
    }

    // ── Update ──────────────────────────────────────────────────────────

    public function test_only_what_changed_is_written_and_audited_with_before_and_after(): void
    {
        $res = $this->gate->apply(412, ['total' => 218.40, 'gst_amount' => 10, 'accounting_category' => 'Tools/Equipment', 'notes' => 'two bags'],
                                  self::TIM, 'desktop_modal');
        $this->assertSame(['accounting_category', 'notes'], $res['changed']);
        $a = $this->audit(412);
        $this->assertCount(1, $a);
        $this->assertSame('accounting_category,notes', $a[0]['fields']);
        $this->assertSame(['accounting_category' => 'Materials', 'notes' => null], json_decode($a[0]['before_text'], true));
        $this->assertSame(['accounting_category' => 'Tools/Equipment', 'notes' => 'two bags'], json_decode($a[0]['after_text'], true));
        $this->assertSame(1, $this->hooks->count('learnBank'), 'a person re-categorised it: the bank-rule lesson, once');
        $this->assertSame(0, $this->hooks->count('repost'), 'not posted yet — nothing to re-post');
    }

    public function test_nothing_changed_is_no_write_no_audit_no_learning(): void
    {
        $res = $this->gate->apply(412, ['total' => '218.4', 'accounting_category' => 'Materials', 'job_id' => ''], self::TIM, 'desktop_modal');
        $this->assertTrue($res['noop']);
        $this->assertCount(0, $this->audit(412));
        $this->assertSame([], $this->hooks->calls);
    }

    public function test_a_bad_date_or_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->gate->apply(412, ['expense_date' => '2026-13-40'], self::TIM, 'desktop_modal');
    }

    public function test_status_can_only_close_through_its_audited_transition(): void
    {
        $this->gate->apply(412, ['status' => 'approved'], self::TIM, 'desktop_modal');
        $this->assertSame('pending_approval', $this->row(412)['status'], 'a plain edit never approves');
        $this->assertSame(0, $this->hooks->count('learnConfirmed'));

        $res = $this->gate->apply(412, ['status' => 'approved', 'approved_by' => 9, 'approved_at' => 'now'], ['id' => 9, 'kind' => 'user'],
                                  'approval', ['transition' => 'approve', 'learn_baseline' => ['total' => 218.40]]);
        $this->assertSame('approve', $res['action']);
        $this->assertSame('approved', $this->row(412)['status']);
        $this->assertSame(1, $this->hooks->count('learnConfirmed'), 'approval teaches the header lessons, once');
        $this->assertSame(['total' => 218.40], $this->hooks->calls['learnConfirmed'][0][1], 'the inbox baseline is passed through');
    }

    public function test_a_receipt_sent_to_accounting_keeps_its_money(): void
    {
        $this->db->exec("UPDATE expenses SET status = 'forwarded', forwarded_to_accounting = 1 WHERE id = 412");
        try {
            $this->gate->apply(412, ['total' => 1.00], self::TIM, 'desktop_modal');
            $this->fail('a sent receipt must not drift');
        } catch (Exception $e) {
            $this->assertStringContainsString('sent to accounting', $e->getMessage());
        }
        $res = $this->gate->apply(412, ['raw_ocr_json' => 'LAWNBOY …'], ['id' => null, 'kind' => 'system'], 'receipt_facts', ['allow_locked' => true]);
        $this->assertSame(['raw_ocr_json'], $res['changed'], 'the text is not money — it may still arrive');
        $this->assertSame(1, $this->hooks->count('facts'));
    }

    public function test_only_if_guards_a_conditional_write(): void
    {
        $this->db->exec("UPDATE expenses SET job_id = 77 WHERE id = 412");
        $res = $this->gate->apply(412, ['job_id' => 501], ['id' => null, 'kind' => 'penny'], 'trip_attribution',
                                  ['only_if' => function (array $e) { return empty($e['job_id']); }]);
        $this->assertTrue($res['noop']);
        $this->assertSame(77, (int)$this->row(412)['job_id'], 'never overwrites a job that is there');
    }

    // ── Posted receipts: append-only ─────────────────────────────────────

    public function test_a_posted_receipt_is_reposted_once_and_a_rejected_one_reversed(): void
    {
        $this->db->exec("UPDATE expenses SET status = 'approved' WHERE id = 412");
        $res = $this->gate->apply(412, ['job_id' => 501], self::TIM, 'desktop_reassign_job');
        $this->assertTrue($res['reposted']);
        $this->assertSame(1, $this->hooks->count('repost'));
        $this->assertSame('books re-posted', $this->audit(412)[0]['note']);

        $this->gate->apply(412, ['notes' => 'checked'], self::TIM, 'desktop_modal');
        $this->assertSame(1, $this->hooks->count('repost'), 'a note is not the books');

        $this->gate->apply(412, ['status' => 'rejected', 'rejection_reason' => 'dupe'], self::TIM, 'approval', ['transition' => 'reject']);
        $this->assertSame(1, $this->hooks->count('unpost'), 'rejected after posting: its entry is reversed');
    }

    public function test_inside_someone_elses_transaction_the_repost_waits(): void
    {
        $this->db->exec("UPDATE expenses SET status = 'approved' WHERE id = 412");
        $this->db->beginTransaction();
        $this->gate->apply(412, ['total' => 210.00], self::TIM, 'penny_card');
        $this->db->commit();
        $this->assertSame(0, $this->hooks->count('repost'), 'posting runs its own transaction — never nested');
        $this->assertStringContainsString('waits', $this->audit(412)[0]['note']);
    }

    // ── Lines ───────────────────────────────────────────────────────────

    public function test_one_line_change_is_audited_and_teaches_once(): void
    {
        $res = $this->gate->apply(412, ['line_item' => ['op' => 'update', 'id' => 230, 'name' => 'CBM bark mulch', 'quantity' => 3, 'line_total' => 120]],
                                  self::TIM, 'desktop_line');
        $this->assertSame('lines', $res['action']);
        $this->assertSame(3.0, (float)$this->db->query("SELECT current_stock FROM products WHERE id = 11")->fetchColumn(), '2 + (3 − 2)');
        $this->assertSame(1, $this->hooks->count('learnLineOp'));
        $this->assertSame('update', $this->hooks->calls['learnLineOp'][0][0]);
        $this->assertSame('Black Composted Bark Mulch', $this->hooks->calls['learnLineOp'][0][1]['name'], 'the line before, for the rename lesson');

        $this->expectExceptionMessage('Line item not found');
        $this->gate->apply(412, ['line_item' => ['op' => 'delete', 'id' => 999]], self::TIM, 'desktop_line');
    }

    // ── Split by line ───────────────────────────────────────────────────

    public function test_a_split_through_the_gate_is_saved_audited_and_learned_once(): void
    {
        $res = $this->gate->apply(412, ['allocations' => ExpenseGateTestDb::split412()], self::TIM, 'penny_card');
        $this->assertSame('split', $res['action']);
        $this->assertCount(2, $res['allocations']);
        $this->assertSame(1, $this->hooks->count('learnSplit'));
        $after = json_decode($this->audit(412)[0]['after_text'], true)['allocations'];
        $this->assertEquals([['Richardson Sun & Shade Lawn Seed 5 kg', null, true, 120.0, 6.0, 8.4], ['Black Composted Bark Mulch', 501, false, 80.0, 4.0, 0.0]],
            array_map(function ($a) { return [$a['label'], $a['job_id'], $a['stock'], $a['net'], $a['gst'], $a['pst']]; }, $after));

        $again = $this->gate->apply(412, ['allocations' => ExpenseGateTestDb::split412()], self::TIM, 'desktop_modal');
        $this->assertTrue($again['noop'], 'the same split saved again is not a change');
        $this->assertSame(1, $this->hooks->count('learnSplit'));

        $off = $this->gate->apply(412, ['allocations' => []], self::TIM, 'desktop_modal');
        $this->assertSame(['allocations'], $off['changed']);
        $this->assertFalse((new ExpenseSplitService($this->db))->hasSplit(412));
        $this->assertTrue($this->gate->apply(412, ['allocations' => []], self::TIM, 'desktop_modal')['noop'], 'off when already off: nothing');
    }

    public function test_a_line_edit_on_a_split_receipt_rebalances_and_reposts(): void
    {
        $this->gate->apply(412, ['allocations' => ExpenseGateTestDb::split412()], self::TIM, 'penny_card');
        $this->db->exec("UPDATE expenses SET status = 'approved' WHERE id = 412");
        // The mulch was 2 × $45, not 2 × $40: the total moves too.
        $this->gate->apply(412, ['total' => 228.90, 'amount' => 210, 'gst_amount' => 10.50], self::TIM, 'desktop_modal');
        $this->gate->apply(412, ['line_item' => ['op' => 'update', 'id' => 230, 'name' => 'Black Composted Bark Mulch', 'quantity' => 2, 'line_total' => 90]],
                           self::TIM, 'desktop_line');
        $by = array_column((new ExpenseSplitService($this->db))->forExpense(412), null, 'line_item_id');
        $this->assertSame([90.0, 4.5, 0.0, 501], [$by[230]['net_amount'], $by[230]['gst_amount'], $by[230]['pst_amount'], $by[230]['job_id']],
                          'Tim\'s choices kept, the money worked out again');
        $this->assertSame(2, $this->hooks->count('repost'));
    }

    public function test_split_learning_teaches_penny_the_next_lawnboy_receipt(): void
    {
        $gate = new ExpenseGate($this->db);   // real hooks
        $gate->apply(412, ['allocations' => [
            ['line_item_id' => 229, 'accounting_category' => 'Tools/Equipment'],   // Tim says: the seed spreader kit, not stock
            ['line_item_id' => 230, 'job_id' => 501, 'accounting_category' => 'Materials'],
        ]], self::TIM, 'penny_card');
        $lesson = $this->db->query("SELECT * FROM expense_split_lessons WHERE line_key = 'richardson sun shade lawn seed 5 kg'")->fetch();
        $this->assertSame(['Tools/Equipment', 0, 0], [$lesson['accounting_category'], (int)$lesson['is_stock'], (int)$lesson['to_job']]);
        $p = (new ExpenseSplitService($this->db))->propose(412);
        $seed = array_column($p['lines'], null, 'line_item_id')[229];
        $this->assertSame(['Tools/Equipment', 'learned'], [$seed['accounting_category'], $seed['rule']]);
    }

    public function test_bank_desk_alignment_never_teaches_a_bank_rule_back(): void
    {
        $this->gate->apply(412, ['accounting_category' => 'Fuel'], ['id' => null, 'kind' => 'penny'], 'bank_desk');
        $this->gate->apply(412, ['accounting_category' => 'Materials'], self::TIM, 'bank_desk');
        $this->assertSame(0, $this->hooks->count('learnBank'));
    }

    // ── Delete ──────────────────────────────────────────────────────────

    public function test_delete_keeps_the_row_in_the_audit_and_reverses_the_books(): void
    {
        $this->gate->apply(412, ['allocations' => ExpenseGateTestDb::split412()], self::TIM, 'penny_card');
        $this->gate->apply(412, [ExpenseGate::DELETE => true], self::TIM, 'desktop_delete');
        $this->assertFalse($this->db->query("SELECT id FROM expenses WHERE id = 412")->fetch());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM expense_line_allocations WHERE expense_id = 412")->fetchColumn());
        $last = $this->audit(412)[1];
        $this->assertSame('delete', $last['action']);
        $this->assertSame(218.4, (float)json_decode($last['before_text'], true)['total']);
        $this->assertSame(1, $this->hooks->count('unpost'));
        $this->assertSame(0.0, (float)$this->db->query("SELECT current_stock FROM products WHERE id = 44")->fetchColumn(), 'the seed it brought in leaves stock again');
    }
}
