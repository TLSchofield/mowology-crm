<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Expenses/ExpenseGateTestKit.php';
require_once __DIR__ . '/../../../app/Modules/Accounting/Services/ReceiptFundingCheck.php';

/**
 * The bank line is the truth about where a receipt's money came from (Vital Auto #384, 2026-10-12):
 * a receipt linked to a chequing line can't say "credit card", and one on the card statement can't say
 * "debit". Every link goes through ReceiptFundingCheck::onLink(); single-entry reports count a linked
 * bank line once (AccountingService::countedSql).
 */
class ReceiptFundingCheckTest extends TestCase
{
    private function db(): PDO
    {
        $db = ExpenseGateTestDb::make();
        $db->exec("ALTER TABLE chart_of_accounts ADD COLUMN sub_type TEXT");
        $db->exec("UPDATE chart_of_accounts SET sub_type = 'bank' WHERE code = '1010'");
        $db->exec("UPDATE chart_of_accounts SET sub_type = 'credit_card' WHERE code = '2400'");
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type, sub_type) VALUES (8, '1020', 'Reserve funds (Savings)', 'asset', 'bank'),
                   (9, '6120', 'Vehicle Repairs', 'expense', NULL)");
        foreach (['bank_account_id INT', 'match_confidence INT', 'matched_at TEXT', 'matched_by TEXT'] as $c) {
            $db->exec("ALTER TABLE accounting_transactions ADD COLUMN {$c}");
        }
        $db->exec("ALTER TABLE job_plans ADD COLUMN contract_id INT");
        $db->exec("ALTER TABLE job_plans ADD COLUMN service_type TEXT");
        $db->exec("CREATE TABLE bank_statement_accounts (id INTEGER PRIMARY KEY, account_id INT, label TEXT, kind TEXT)");
        $db->exec("INSERT INTO bank_statement_accounts (account_id, label, kind) VALUES (1, 'Chequing', 'bank'), (3, 'Card', 'card'), (8, 'Savings', 'bank')");
        $db->exec("CREATE TABLE penny_questions (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL DEFAULT 'unbilled_materials', subject TEXT,
                   expense_id INT, plan_id INT, property_id INT, contract_id INT, vendor_id INT, vendor_name TEXT, category TEXT,
                   amount REAL NOT NULL DEFAULT 0, question TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', answer TEXT, answered_by INT,
                   answered_at TEXT, created_at TEXT, UNIQUE (kind, expense_id))");
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, action TEXT, details TEXT)");
        return $db;
    }

    /** Tim's case: #384 Vital Auto $1,711.70 saved as credit card, chequing line 43028 on Sep 16. */
    private function vitalAuto(PDO $db, string $method = 'credit_card', int $bankAccountId = 1): void
    {
        $db->prepare("INSERT INTO expenses (id, expense_date, vendor_name_raw, amount, gst_amount, total, accounting_category, payment_method, status)
                      VALUES (384, '2026-09-02', 'Vital Auto Repair & Detail', 1630.19, 81.51, 1711.70, 'Repairs/Maintenance', ?, 'approved')")
           ->execute([$method]);
        $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, account_id, amount, description, reference_type, status, bank_account_id)
                      VALUES (43028, '2026-09-16', 'expense', 9, 1711.70, 'Point of sale VITAL AUTO REPAIR', 'bank_import', 'cleared', ?)")
           ->execute([$bankAccountId]);
    }

    private function check(PDO $db, ?ExpenseGateHooksSpy &$hooks = null): ReceiptFundingCheck
    {
        $hooks = new ExpenseGateHooksSpy();
        return new ReceiptFundingCheck($db, new ExpenseGate($db, $hooks), $hooks);
    }

    // ── Pure ────────────────────────────────────────────────────────────

    public function test_decide_by_line_and_method(): void
    {
        $this->assertSame('none', ReceiptFundingCheck::decide('bank', 'debit')['action']);
        $this->assertSame('none', ReceiptFundingCheck::decide('bank', 'debit_card')['action']);
        $this->assertSame('none', ReceiptFundingCheck::decide('bank', 'cash')['action']);
        $this->assertSame(['action' => 'correct', 'to' => 'debit'], ReceiptFundingCheck::decide('bank', 'credit_card'));
        $this->assertSame(['action' => 'ask', 'to' => 'debit'], ReceiptFundingCheck::decide('bank', 'company_card'), 'company card: debit or the Visa?');
        $this->assertSame('none', ReceiptFundingCheck::decide('card', 'credit_card')['action']);
        $this->assertSame('none', ReceiptFundingCheck::decide('card', 'company_card')['action']);
        $this->assertSame(['action' => 'correct', 'to' => 'credit_card'], ReceiptFundingCheck::decide('card', 'debit'));
        $this->assertSame(['action' => 'correct', 'to' => 'credit_card'], ReceiptFundingCheck::decide('card', ''));
        $this->assertSame(['action' => 'ask', 'to' => 'credit_card'], ReceiptFundingCheck::decide('card', 'cash'));
        $this->assertSame('none', ReceiptFundingCheck::decide('other', 'credit_card')['action']);
    }

    public function test_line_kind(): void
    {
        $this->assertSame('bank', ReceiptFundingCheck::lineKind('1010', 'bank', 'bank', true));
        $this->assertSame('bank', ReceiptFundingCheck::lineKind('1020', 'bank', 'bank', true));
        $this->assertSame('card', ReceiptFundingCheck::lineKind('2400', 'credit_card', 'card', true));
        $this->assertSame('card', ReceiptFundingCheck::lineKind('2405', null, 'card', true));
        $this->assertSame('bank', ReceiptFundingCheck::lineKind(null, null, null, false), 'no account = default chequing');
        $this->assertSame('other', ReceiptFundingCheck::lineKind('2700', 'loan', null, true));
    }

    // ── The one function ────────────────────────────────────────────────

    public function test_vital_auto_is_corrected_reposted_logged_and_told(): void
    {
        $db = $this->db();
        $this->vitalAuto($db);
        $res = $this->check($db, $hooks)->onLink(384, 43028, 9);

        $this->assertSame('correct', $res['action']);
        $this->assertSame('debit', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
        $this->assertSame(1, $hooks->count('repost'), 'the books follow — once');
        $log = $db->query("SELECT action, details FROM activity_log")->fetch();
        $this->assertStringContainsString('payment source corrected from credit card to debit by bank line #43028', $log['details']);
        $q = $db->query("SELECT kind, category, status, question FROM penny_questions WHERE expense_id = 384")->fetch();
        $this->assertSame(['payment_source', 'notice', 'open'], [$q['kind'], $q['category'], $q['status']]);
        $this->assertStringContainsString('said credit card, but it was paid from chequing on Sep 16', $q['question']);
        $this->assertStringContainsString('corrected', $q['question']);
        $audit = $db->query("SELECT source, actor_kind FROM expense_change_log WHERE expense_id = 384")->fetch();
        $this->assertSame(['bank_line', 'penny'], [$audit['source'], $audit['actor_kind']], 'through the expense gate');
    }

    public function test_a_matching_receipt_is_left_alone(): void
    {
        $db = $this->db();
        $this->vitalAuto($db, 'debit');
        $this->assertSame('none', $this->check($db, $hooks)->onLink(384, 43028, 9)['action']);
        $this->assertSame(0, $hooks->count('repost'));
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM penny_questions")->fetchColumn());
    }

    public function test_company_card_on_chequing_is_asked_not_changed_and_the_answer_applies_it(): void
    {
        $db = $this->db();
        $this->vitalAuto($db, 'company_card');
        $res = $this->check($db, $hooks)->onLink(384, 43028, 9);

        $this->assertSame('ask', $res['action']);
        $this->assertSame('company_card', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
        $this->assertSame(0, $hooks->count('repost'));

        $pq = new PennyQuestionService($db);
        $open = $pq->open(5, 'Tim');
        $this->assertSame('payment_source', $open[0]['kind']);
        $this->assertTrue($open[0]['ask']);
        $this->assertStringContainsString("I haven't changed anything", $open[0]['question']);

        $ans = $pq->answer((int)$open[0]['id'], 'use_bank_line', 9, 'Tim');
        $this->assertTrue($ans['ok'], $ans['message']);
        $this->assertSame('debit', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
        $this->assertSame([], $pq->open(5, 'Tim'));
    }

    public function test_a_notice_is_closed_with_seen_and_cannot_be_reapplied(): void
    {
        $db = $this->db();
        $this->vitalAuto($db);
        $this->check($db)->onLink(384, 43028, 9);
        $pq = new PennyQuestionService($db);
        $id = (int)$pq->open(5)[0]['id'];
        $this->assertFalse($pq->answer($id, 'use_bank_line', 9)['ok'], 'already corrected');
        $this->assertTrue($pq->answer($id, 'seen', 9)['ok']);
        $this->assertSame([], $pq->open(5));
    }

    public function test_a_debit_receipt_on_the_card_statement_becomes_credit_card(): void
    {
        $db = $this->db();
        $this->vitalAuto($db, 'debit', 3);
        $res = $this->check($db)->onLink(384, 43028, 9);
        $this->assertSame('correct', $res['action']);
        $this->assertSame('credit_card', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
        $this->assertStringContainsString('paid from the credit card', $res['message']);
    }

    public function test_cash_on_the_card_statement_is_asked(): void
    {
        $db = $this->db();
        $this->vitalAuto($db, 'cash', 3);
        $this->assertSame('ask', $this->check($db)->onLink(384, 43028, 9)['action']);
        $this->assertSame('cash', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
    }

    public function test_sweep_lists_without_changing_then_applies(): void
    {
        $db = $this->db();
        $this->vitalAuto($db);
        $db->exec("UPDATE accounting_transactions SET matched_expense_id = 384 WHERE id = 43028");
        $check = $this->check($db);
        $list = $check->sweep(false);
        $this->assertCount(1, $list);
        $this->assertSame([384, 43028, 'credit_card', 'correct', 'debit'],
            [$list[0]['expense_id'], $list[0]['line_id'], $list[0]['method'], $list[0]['action'], $list[0]['to']]);
        $this->assertSame('credit_card', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());

        $check->sweep(true, 9);
        $this->assertSame('debit', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
        $this->assertSame([], $check->sweep(false), 'nothing left');
    }

    /** The receipts page / Penny's desk link path runs the check. */
    public function test_attach_expense_match_routes_through_the_check(): void
    {
        $db = $this->db();
        $this->vitalAuto($db);
        $res = (new BankImportService($db))->attachExpenseMatch(43028, 384, 9);
        $this->assertSame(384, (int)$db->query("SELECT matched_expense_id FROM accounting_transactions WHERE id = 43028")->fetchColumn());
        $this->assertSame('correct', $res['payment_source']['action']);
        $this->assertSame('debit', $db->query("SELECT payment_method FROM expenses WHERE id = 384")->fetchColumn());
    }

    // ── Reports count the money once ────────────────────────────────────

    public function test_a_linked_bank_line_is_not_counted_beside_its_receipt_row(): void
    {
        $db = $this->db();
        $this->vitalAuto($db, 'debit');
        $db->exec("UPDATE accounting_transactions SET matched_expense_id = 384, status = 'reconciled' WHERE id = 43028");
        $sum = fn() => (float)$db->query("SELECT COALESCE(SUM(t.amount), 0) FROM accounting_transactions t
                                           WHERE t.type = 'expense' AND t.status IN ('cleared', 'reconciled') AND " . AccountingService::countedSql('t'))->fetchColumn();
        $this->assertEqualsWithDelta(1711.70, $sum(), 0.001, 'receipt not approved yet: the bank line carries it');

        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, account_id, amount, reference_type, reference_id, status)
                   VALUES (50823, '2026-09-02', 'expense', 9, 1711.70, 'expense', 384, 'cleared')");
        $this->assertEqualsWithDelta(1711.70, $sum(), 0.001, 'receipt row + linked bank line = once');
    }
}
