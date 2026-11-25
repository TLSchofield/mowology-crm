<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's look-back review on an in-memory copy of the books: scan → proposals (idempotent,
 * skipped never re-proposed) → approve through the real paths (bank line + journal, receipt
 * through the gate) → undo; locked months and changed records are refused; Claude is asked
 * once per vendor and only within the budget.
 */
class LookbackServiceTest extends TestCase
{
    private const USER = ['id' => 1, 'role' => 'admin'];

    public function test_scan_finds_each_family_and_is_idempotent(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $r = $svc->scan();
        $kinds = $r['by_kind'];
        foreach (['split', 'gst_exempt', 'duplicate', 'loan', 'payroll', 'journal_orphan', 'personal'] as $k) {
            $this->assertArrayHasKey($k, $kinds, $k . ' found: ' . json_encode($kinds));
        }
        $this->assertSame(0, $r['ai']['calls'], 'rules only by default');
        $this->assertSame('info', $db->query("SELECT status FROM lookback_proposals WHERE kind = 'payroll'")->fetchColumn(), 'payroll is count + $ only');

        $n = (int)$db->query("SELECT COUNT(*) FROM lookback_proposals")->fetchColumn();
        $again = $svc->scan();
        $this->assertSame(0, $again['stored']['inserted']);
        $this->assertSame($n, (int)$db->query("SELECT COUNT(*) FROM lookback_proposals")->fetchColumn(), 're-running changes nothing');

        $id = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'personal'")->fetchColumn();
        $this->assertTrue($svc->skip($id, self::USER)['ok']);
        $third = $svc->scan();
        $this->assertSame(1, $third['stored']['kept_decided']);
        $this->assertSame('skipped', $db->query("SELECT status FROM lookback_proposals WHERE id = {$id}")->fetchColumn(), 'a skipped item is not proposed again');
    }

    public function test_approving_the_loan_moves_the_line_and_its_journal_and_penny_learns_then_undo(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->scan();
        $id = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'loan'")->fetchColumn();
        $r = $svc->approve($id, self::USER);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(9, (int)$db->query("SELECT account_id FROM accounting_transactions WHERE id = 100")->fetchColumn(), 'on 2610');
        $net = $this->net($db);
        $this->assertEqualsWithDelta(0.0, $net[8] ?? 0.0, 0.001, 'nothing left on 6130');
        $this->assertEqualsWithDelta(363.08, $net[9] ?? 0.0, 0.001, '2610 carries the payment');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM bank_line_reviews WHERE transaction_id = 100")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM transaction_rules WHERE account_id = 9")->fetchColumn(), 'an owner confirmation for the payee');
        $this->assertSame('applied', $db->query("SELECT status FROM lookback_proposals WHERE id = {$id}")->fetchColumn());

        $u = $svc->undo($id, self::USER);
        $this->assertTrue($u['ok'], $u['message']);
        $this->assertSame(8, (int)$db->query("SELECT account_id FROM accounting_transactions WHERE id = 100")->fetchColumn(), 'back on 6130');
        $this->assertEqualsWithDelta(363.08, $this->net($db)[8] ?? 0.0, 0.001, 'and so is its journal line');
    }

    public function test_receipt_change_goes_through_the_gate_and_undo_puts_it_back(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->scan();
        $id = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'gst_exempt' AND subject_id = 600")->fetchColumn();
        $r = $svc->approve($id, self::USER);
        $this->assertTrue($r['ok'], $r['message']);
        $e = $db->query("SELECT gst_amount, amount FROM expenses WHERE id = 600")->fetch();
        $this->assertSame([0.0, 52.5], [(float)$e['gst_amount'], (float)$e['amount']]);
        $this->assertSame('lookback', $db->query("SELECT source FROM expense_change_log WHERE expense_id = 600 ORDER BY id DESC LIMIT 1")->fetchColumn(), 'audited by the gate');

        $this->assertTrue($svc->undo($id, self::USER)['ok']);
        $e = $db->query("SELECT gst_amount, amount FROM expenses WHERE id = 600")->fetch();
        $this->assertSame([2.5, 50.0], [(float)$e['gst_amount'], (float)$e['amount']]);
    }

    public function test_split_and_duplicate_apply_through_the_gate(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->scan();
        $split = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'split' AND subject_id = 412")->fetchColumn();
        $this->assertTrue($svc->approve($split, self::USER)['ok']);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM expense_line_allocations WHERE expense_id = 412")->fetchColumn());
        $this->assertSame(501, (int)$db->query("SELECT job_id FROM expense_line_allocations WHERE line_item_id = 230")->fetchColumn(), 'mulch → Oakridge');

        $dup = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'duplicate'")->fetchColumn();
        $this->assertTrue($svc->approve($dup, self::USER)['ok']);
        $this->assertSame('cancelled', $db->query("SELECT status FROM expenses WHERE id = 602")->fetchColumn());
        $this->assertSame('approved', $db->query("SELECT status FROM expenses WHERE id = 601")->fetchColumn(), 'the first copy stays');
        $this->assertTrue($svc->undo($dup, self::USER)['ok']);
        $this->assertSame('approved', $db->query("SELECT status FROM expenses WHERE id = 602")->fetchColumn());
    }

    public function test_locked_month_and_changed_record_are_refused(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->scan();
        $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2026, 5, 'locked')");
        $id = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'loan'")->fetchColumn();
        $r = $svc->approve($id, self::USER);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('locked', $r['message']);
        $this->assertSame('open', $db->query("SELECT status FROM lookback_proposals WHERE id = {$id}")->fetchColumn());
        $this->assertSame(8, (int)$db->query("SELECT account_id FROM accounting_transactions WHERE id = 100")->fetchColumn(), 'nothing moved');

        $db->exec("DELETE FROM accounting_periods");
        $db->exec("UPDATE accounting_transactions SET account_id = 7 WHERE id = 100");     // someone filed it meanwhile
        $r = $svc->approve($id, self::USER);
        $this->assertSame('stale', $r['status'] ?? null);
    }

    public function test_journal_orphan_is_reversed_and_undo_reverses_the_reversal(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->scan();
        $id = (int)$db->query("SELECT id FROM lookback_proposals WHERE kind = 'journal_orphan'")->fetchColumn();
        $this->assertTrue($svc->approve($id, self::USER)['ok']);
        $this->assertEqualsWithDelta(1830.22, $this->net($db)[7] ?? 0.0, 0.001, 'the phantom $40 is gone from 6900 (payroll stays)');
        $this->assertTrue($svc->undo($id, self::USER)['ok']);
        $this->assertEqualsWithDelta(1870.22, $this->net($db)[7] ?? 0.0, 0.001);
    }

    public function test_claude_is_asked_once_per_vendor_within_the_budget_and_the_answer_is_reused(): void
    {
        $db = $this->db();
        $calls = 0;
        $transport = function (array $body) use (&$calls) {
            $calls++;
            $this->assertSame('claude-sonnet-5-5', $body['model']);
            return ['code' => 200, 'body' => json_encode(['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 80],
                'content' => [['type' => 'text', 'text' => json_encode(['category' => 'Office/Admin', 'confidence' => 0.8, 'reason' => 'Printer ink'])]]])];
        };
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('penny_lookback_budget', '0')");
        $svc = $this->svc($db, $transport);
        $r = $svc->scan(['ai_calls' => 5]);
        $this->assertSame(0, $calls, 'no budget, no call');
        $this->assertSame(1, $r['ai']['skipped_budget']);

        $db->exec("UPDATE ops_settings SET setting_value = '15' WHERE setting_key = 'penny_lookback_budget'");
        $r = $svc->scan(['ai_calls' => 5]);
        $this->assertSame(1, $calls, 'one call for the vendor (two receipts)');
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM lookback_proposals WHERE kind = 'ai_category'")->fetchColumn());
        $this->assertEqualsWithDelta(0.0048, (float)$db->query("SELECT SUM(cost_usd) FROM lookback_ai_calls")->fetchColumn(), 0.00001, '1200 in × $3/M + 80 out × $15/M');
        $svc->scan(['ai_calls' => 5]);
        $this->assertSame(1, $calls, 'the answer is a rule now — reused, not asked again');
    }

    public function test_deposits_booked_by_the_jobber_import_are_never_flagged(): void
    {
        $db = $this->db();
        // A Jobber-era deposit the import booked: flipped to transfer + its own 'bank_deposit' entry (the import only books
        // deposits with no live bank_import entry).
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, status) VALUES
                   (200, '2026-03-10', 'transfer', 402.02, 'TD VISA E-TFR DEPOSIT', 7, 'bank_import', 'reconciled')");
        $this->entry($db, '2026-03-10', 'bank_deposit', 200, [[1, 402.02, 0], [3, 0, 402.02]]);
        $svc = $this->svc($db);
        $svc->scan();
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM lookback_proposals WHERE title LIKE '%#200%' OR (subject_type = 'bank' AND subject_id = 200)")->fetchColumn(),
                          'nothing proposed for a deposit the Jobber import booked');

        // Only if a live bank_import entry sat next to it (counted twice) is that older entry flagged.
        $this->entry($db, '2026-03-10', 'bank_import', 200, [[1, 402.02, 0], [7, 0, 402.02]]);
        $svc->scan();
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM lookback_proposals WHERE kind = 'journal_double' AND title LIKE '%#200%'")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM lookback_proposals WHERE subject_type = 'bank' AND subject_id = 200")->fetchColumn());
    }

    public function test_card_line_and_summary(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $this->assertNull($svc->cardLine(), 'nothing open → no line on her card');
        $svc->scan();
        $line = $svc->cardLine();
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Look-back: ' . $line['count'] . ' proposals ($', $line['text']);
        $s = $svc->summary();
        $this->assertGreaterThan(0, $s['totals']['open']);
        $this->assertCount(4, $s['gst']);
        $this->assertSame('Look-back: 1 proposal ($12, GST −$0.60)', LookbackService::cardText(1, 12.4, -0.6));
    }

    // ─────────────────────────────────────────────────────────────────────

    private function svc(PDO $db, ?callable $transport = null): LookbackService
    {
        return new LookbackService($db, $transport, null, new class($db) extends LedgerService {
            private PDO $pdo;
            public function __construct(PDO $db) { parent::__construct($db); $this->pdo = $db; }
            public function canRepostSource(): bool { return true; }
            public function accountId(string $code): int
            {
                $s = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
                $s->execute([$code]);
                return (int)$s->fetchColumn();
            }
        });
    }

    /** account_id => net debit over live + reversal entries. */
    private function net(PDO $db): array
    {
        $out = [];
        foreach ($db->query("SELECT account_id, SUM(debit) - SUM(credit) AS n FROM journal_lines GROUP BY account_id")->fetchAll() as $r) {
            $out[(int)$r['account_id']] = round((float)$r['n'], 2);
        }
        return $out;
    }

    private function db(): PDO
    {
        $db = ExpenseGateTestDb::make();
        ExpenseGateTestDb::lawnboy412($db, 'approved');
        $db->exec("UPDATE expenses SET job_id = 501 WHERE id = 412");
        $now = date('Y-m-d H:i:s');
        $db->exec("INSERT INTO expense_split_lessons (vendor_id, line_key, accounting_category, is_stock, to_job, times_seen, updated_at) VALUES
                   (7, '" . ExpenseSplitService::lineKey('Richardson Sun & Shade Lawn Seed 5 kg') . "', 'Materials', 1, 0, 1, '{$now}'),
                   (7, '" . ExpenseSplitService::lineKey('Black Composted Bark Mulch') . "', 'Materials', 0, 1, 1, '{$now}')");

        $db->exec("DROP TABLE accounting_transactions");
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, gst_amount REAL DEFAULT 0,
                   pst_amount REAL DEFAULT 0, description TEXT, account_id INTEGER, reference_type TEXT, reference_id INT, bank_account_id INTEGER, job_id INTEGER,
                   contact_id INTEGER, vendor_id INTEGER, status TEXT DEFAULT 'cleared', matched_expense_id INTEGER, matched_invoice_id INTEGER,
                   is_auto_categorized INTEGER DEFAULT 1, notes TEXT)");
        $db->exec("CREATE TABLE bank_line_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER UNIQUE, suggested_account_id INTEGER,
                   final_account_id INTEGER, outcome TEXT, decided_by INTEGER, decided_at TEXT)");
        $db->exec("CREATE TABLE transaction_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, priority INTEGER, applies_to TEXT, condition_field TEXT,
                   condition_operator TEXT, condition_value TEXT, account_id INTEGER, transaction_type TEXT, is_active INTEGER DEFAULT 1, source TEXT DEFAULT 'manual',
                   learned_count INTEGER DEFAULT 0, owner_confirmations INTEGER NOT NULL DEFAULT 0, last_confirmed_tx_id INTEGER, last_learned_at TEXT,
                   created_by INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE receipt_facts (expense_id INTEGER PRIMARY KEY, printed_date TEXT, time_first TEXT, time_last TEXT, doc_number TEXT, doc_kind TEXT,
                   card_last4 TEXT, card_brand TEXT, terminal TEXT, store_number TEXT, parsed_at TEXT, source TEXT)");
        $db->exec("CREATE TABLE expense_duplicate_dismissals (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_a INT, expense_b INT, dismissed_by INT)");
        $db->exec("CREATE TABLE gst_filings (id INTEGER PRIMARY KEY AUTOINCREMENT, period_from TEXT, period_to TEXT, filed_on TEXT)");
        // Migration 1250
        $db->exec("CREATE TABLE lookback_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, scan_key TEXT UNIQUE NOT NULL, family TEXT, kind TEXT, subject_type TEXT,
                   subject_id INT, txn_date TEXT, title TEXT, before_json TEXT, after_json TEXT, evidence TEXT, confidence INT DEFAULT 0, source TEXT DEFAULT 'rules',
                   amount_impact REAL DEFAULT 0, gst_impact REAL DEFAULT 0, signature TEXT, status TEXT DEFAULT 'open', result_note TEXT, undo_json TEXT,
                   decided_by INT, decided_at TEXT, undone_by INT, undone_at TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE lookback_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_kind TEXT, rule_key TEXT, category TEXT, confidence INT DEFAULT 0, reason TEXT,
                   source TEXT DEFAULT 'ai', confirmations INT DEFAULT 0, rejected INT DEFAULT 0, created_at TEXT, updated_at TEXT, UNIQUE (rule_kind, rule_key))");
        $db->exec("CREATE TABLE lookback_ai_calls (id INTEGER PRIMARY KEY AUTOINCREMENT, call_kind TEXT, call_key TEXT, model TEXT, input_tokens INT DEFAULT 0,
                   output_tokens INT DEFAULT 0, cost_usd REAL DEFAULT 0, error TEXT, created_at TEXT)");

        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type, expense_category_alias) VALUES
                   (8, '6130', 'Vehicle Loan', 'expense', NULL), (9, '2610', 'Loan Payable — RAM 3500HD', 'liability', NULL),
                   (10, '5100', 'Labour — Crew Wages', 'expense', NULL), (11, '5000', 'Cost of Services', 'expense', 'Disposal/Dump'),
                   (12, '1300', 'Due from Shareholder', 'asset', NULL), (13, '2200', 'GST Collected', 'liability', NULL),
                   (14, '6500', 'Office & Administration', 'expense', 'Office/Admin')");
        $db->exec("INSERT INTO vendors (id, name) VALUES (20, 'Vancouver Landfill'), (21, 'GoodLife Fitness'), (22, 'Inkwell Supplies')");
        $ins = $db->prepare("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, amount, gst_amount, pst_amount, total, accounting_category,
                             payment_method, status, receipt_media_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'credit_card', ?, ?)");
        // Landfill with GST claimed; the same dump ticket booked twice; a gym membership; an unknown vendor filed as Other (twice).
        $ins->execute([600, '2026-05-12', 20, 'Vancouver Landfill', 50.00, 2.50, 52.50, 'Disposal/Dump', 'approved', 1]);
        $ins->execute([601, '2026-06-03', 20, 'Vancouver Landfill', 61.14, 0, 61.14, 'Disposal/Dump', 'approved', 2]);
        $ins->execute([602, '2026-06-03', 20, 'Vancouver Landfill', 61.14, 0, 61.14, 'Disposal/Dump', 'approved', 3]);
        $ins->execute([603, '2026-04-01', 21, 'GoodLife Fitness', 20.00, 1.00, 21.00, 'Overhead', 'forwarded', 4]);
        $ins->execute([604, '2026-07-01', 22, 'Inkwell', 40.00, 2.00, 42.00, 'Other', 'approved', 5]);
        $ins->execute([605, '2026-08-01', 22, 'Inkwell', 30.00, 1.50, 31.50, 'Other', 'approved', 6]);
        $ins->execute([550, '2025-11-12', 20, 'Vancouver Landfill', 50.00, 2.50, 52.50, 'Disposal/Dump', 'approved', 7]);   // 2025: never looked at
        $db->exec("INSERT INTO receipt_facts (expense_id, doc_number, doc_kind, parsed_at, source) VALUES (601, '43176009', 'ticket', '{$now}', 'ocr'), (602, '43176009', 'ticket', '{$now}', 'ocr')");
        // The landfill receipt's entry as posted.
        $this->entry($db, '2026-05-12', 'expense', 600, [[11, 50, 0], [2, 2.5, 0], [3, 0, 52.5]]);

        // Bank: the TD loan on 6130 (posted), a Wave payroll line, a phantom entry for a deleted bank line.
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type) VALUES
                   (100, '2026-05-15', 'expense', 363.08, 'TD ON-LINE LOANS 4455', 8, 'bank_import'),
                   (101, '2026-05-20', 'expense', 1830.22, 'WAVE PYRL 0012345', 7, 'bank_import')");
        $this->entry($db, '2026-05-15', 'bank_import', 100, [[8, 363.08, 0], [1, 0, 363.08]]);
        $this->entry($db, '2026-05-20', 'bank_import', 101, [[7, 1830.22, 0], [1, 0, 1830.22]]);
        $this->entry($db, '2026-03-02', 'bank_import', 999, [[7, 40, 0], [1, 0, 40]]);
        return $db;
    }

    private function entry(PDO $db, string $date, string $type, int $sourceId, array $lines): void
    {
        $db->prepare("INSERT INTO journal_entries (entry_date, memo, source_type, source_id, status) VALUES (?, 'fixture', ?, ?, 'posted')")->execute([$date, $type, $sourceId]);
        $e = (int)$db->lastInsertId();
        foreach ($lines as $l) {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, gst_amount, description) VALUES (?, ?, ?, ?, 0, 'fixture')")->execute([$e, $l[0], $l[1], $l[2]]);
        }
    }
}
