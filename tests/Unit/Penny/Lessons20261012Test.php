<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Expenses/ExpenseGateTestKit.php';
require_once __DIR__ . '/../Accounting/BankLineMoveServiceTest.php';
require_once __DIR__ . '/../Accounting/ReceiptFundingCheckTest.php';
require_once __DIR__ . '/../Accounting/JobberImportTest.php';
require_once __DIR__ . '/HeadLessonsTest.php';

/**
 * The 2026-10-12 bank balancing mistakes, kept as permanent tests (plan 2026-10-13, Part A4).
 *
 * On 2026-10-12 the books were found $9,404 away from the bank and every cause was found by
 * hand. Each case below is one of those mistakes, shaped like the real one but with invented
 * vendors, payers and amounts. A change that brings a mistake back fails here before deploy.
 * Cases whose detector is not built yet are marked incomplete with what they must do and the
 * plan step that builds them (B1 / B3) — they turn into real tests when that step lands.
 */
class Lessons20261012Test extends TestCase
{
    /** Another test's private fixture builder (its database layout is the one the services expect). */
    private static function fixture(string $class, string $method)
    {
        $m = new ReflectionMethod($class, $method);
        return $m->isStatic() ? $m->invoke(null) : $m->invoke(new $class($method));
    }

    private static function jobberCsv(string $name): string
    {
        return (string)(new ReflectionClassConstant(JobberImportTest::class, $name))->getValue();
    }

    private static function quietly(callable $fn)
    {
        $prev = ini_set('error_log', '/dev/null');
        try {
            return $fn();
        } finally {
            ini_set('error_log', (string)$prev);
        }
    }

    // ── a) ──────────────────────────────────────────────────────────────

    /**
     * a) 2026-10-12 (a car-repair receipt): saved as credit_card, then linked to a CHEQUING debit.
     * It posted Cr 2400 and the link reversed the bank line, so chequing was never credited and a
     * phantom card debt sat on the books. Linking must correct the receipt to debit → it posts to 1010.
     */
    public function test_a_credit_card_receipt_linked_to_a_chequing_debit_becomes_debit_and_posts_to_1010(): void
    {
        $db = self::fixture(ReceiptFundingCheckTest::class, 'db');
        HeadLessonsTest::schema($db);
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_name_raw, amount, gst_amount, total, accounting_category, payment_method, status)
                   VALUES (7001, '2026-08-20', 'Testville Auto Fixers', 411.52, 20.58, 432.10, 'Repairs/Maintenance', 'credit_card', 'approved')");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, account_id, amount, description, reference_type, status, bank_account_id)
                   VALUES (8001, '2026-08-27', 'expense', 9, 432.10, 'Point of sale TESTVILLE AUTO FIXERS', 'bank_import', 'cleared', 1)");

        $res = self::quietly(fn() => (new BankImportService($db))->attachExpenseMatch(8001, 7001, 9));

        $this->assertSame('correct', $res['payment_source']['action']);
        $row = $db->query("SELECT * FROM expenses WHERE id = 7001")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('debit', $row['payment_method'], 'the bank line is the truth');
        $this->assertSame('1010', LedgerSyncService::fundingCodeFor($row['payment_method']));
        $this->assertSame('1010', self::quietly(fn() => (new LedgerSyncService($db, new LedgerService($db)))->expenseArgs($row))['funding'],
                          'the re-post recipe credits chequing, not the card');
        $lesson = HeadLessonsTest::rows($db, 'receipt_funding');
        $this->assertCount(1, $lesson, 'and Penny records the lesson');
        $this->assertSame(['payment_method', 'credit_card', 'debit'], [$lesson[0]['field'], $lesson[0]['said'], $lesson[0]['correct']]);
    }

    // ── b) ──────────────────────────────────────────────────────────────

    /** b) 2026-10-12: anything containing "card" went to 2400, so a DEBIT card posted as credit-card debt. */
    public function test_funding_code_debit_card_is_chequing_and_credit_card_is_the_card(): void
    {
        $this->assertSame('1010', LedgerSyncService::fundingCodeFor('debit_card'));
        $this->assertSame('1010', LedgerSyncService::fundingCodeFor('Debit Card'));
        $this->assertSame('1010', LedgerSyncService::fundingCodeFor('debit'));
        $this->assertSame('2400', LedgerSyncService::fundingCodeFor('credit_card'));
        $this->assertSame('2400', LedgerSyncService::fundingCodeFor('Credit-Card'));
    }

    // ── c) ──────────────────────────────────────────────────────────────

    /**
     * c) 2026-10-12: a same-day "Cheque deposit-branch" credit and a debit with the same wording
     * (deposit taken back) were counted as a customer payment. They are a reversal pair: both go
     * to 1100 as transfers and net to zero — never income.
     */
    public function test_a_same_day_deposit_and_take_back_pair_is_a_reversal_not_income(): void
    {
        $this->markTestIncomplete('B3 (anomaly catcher): no same-day reversal-pair detector exists yet. Needed: given a credit '
            . '"Cheque deposit-branch" $123.45 and a debit with the same wording and amount on the same day, both are recognised '
            . 'as one reversal pair (moved to 1100 as transfers, net zero) and neither is offered as income or a payment.');
    }

    // ── d) ──────────────────────────────────────────────────────────────

    /**
     * d) 2026-10-12 (a property manager's EFT): Jobber marked the invoice paid 7 days AFTER the money
     * arrived, as a different method (cheque vs EFT). Matching by invoice and amount must still book
     * the deposit through the catch-up path — never require the Jobber date within 3 days.
     */
    public function test_a_jobber_payment_marked_7_days_late_as_another_method_still_matches_via_catch_up(): void
    {
        $db = self::fixture(JobberImportTest::class, 'db');
        HeadLessonsTest::schema($db);
        $imp = new JobberImportService($db);
        $imp->import(self::jobberCsv('INVOICES'), [], 'none', 1, 'Invoices.csv');
        $imp->import(self::jobberCsv('TRANSACTIONS'), [], 'none', 1, 'Transaction List.csv');
        // Jobber: invoice 9003 ($315) paid by "Cheque" on 2026-02-20. The bank: an EFT on 2026-02-13.
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id)
                   VALUES (8101, '2026-02-13', 'income', 315.00, 0, 'EFT credit SAMPLE PROPERTY MGMT', 5, 'bank_import', 1)");
        $inv = (int)$db->query("SELECT id FROM jobber_invoices WHERE jobber_number = '9003'")->fetchColumn();
        $pay = (int)$db->query("SELECT id FROM jobber_payments WHERE invoice_numbers = '9003'")->fetchColumn();

        $jl = new JobberLedgerService($db, new LedgerService($db));
        $r = $jl->bookMatched(8101, [], [['jobber_invoice_id' => $inv, 'amount' => 315.00]], 'test: marked paid in Jobber 7 days later', 1);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame($pay, (int)$db->query("SELECT jobber_payment_id FROM jobber_ledger_log WHERE op = 'payment' AND transaction_id = 8101 OR
                                                  (op = 'payment' AND batch_id = " . $db->quote($r['batch_id']) . ") LIMIT 1")->fetchColumn(),
                          "the Jobber payment that closed the invoice is used up — it can't pay a second deposit");
        $again = $jl->catchUpPayment($db->query("SELECT * FROM jobber_invoices WHERE id = {$inv}")->fetch(PDO::FETCH_ASSOC), 315.00);
        $this->assertNull($again, 'used once');
        $this->assertCount(1, HeadLessonsTest::rows($db, 'jobber_book_matched'), 'a person matched it: one lesson');
    }

    // ── e) ──────────────────────────────────────────────────────────────

    /**
     * e) 2026-10-12 (a strata manager): a batch of equal monthly cheques, then a "Chargeback debit" of
     * the same amount (+ a returned-cheque fee). One cheque was a duplicate payment. The chargeback
     * pairs with the duplicate as transfers on 1100, the fee goes to bank charges, and the rest of the
     * batch is never undone.
     */
    public function test_a_chargeback_after_a_batch_of_equal_cheques_pairs_with_the_duplicate(): void
    {
        $this->markTestIncomplete('B3 (anomaly catcher): no chargeback pairing exists yet. Needed: given three $250.00 cheque '
            . 'deposits from one payer and a later "Chargeback debit" $250.00 + $7.50 fee, the chargeback is paired with the '
            . 'duplicate cheque (both transfers on 1100), the $7.50 goes to 6800, and the other cheques stay booked.');
    }

    // ── f) ──────────────────────────────────────────────────────────────

    /** f) 2026-10-12: a receipt and the bank line linked to it were both summed in the P&L ($3,565.96 of costs twice). */
    public function test_a_linked_bank_line_and_its_receipt_count_once_in_the_p_and_l(): void
    {
        $db = ExpenseGateTestDb::make();
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, account_id, amount, description, reference_type, status, matched_expense_id) VALUES
                   (8201, '2026-05-04', 'expense', 4, 88.20, 'Point of sale FAKE GARDEN DEPOT', 'bank_import', 'reconciled', 7201),
                   (8202, '2026-05-03', 'expense', 4, 88.20, 'Fake Garden Depot', 'expense', 'cleared', NULL),
                   (8203, '2026-05-09', 'expense', 5, 40.00, 'Point of sale FAKE FUEL', 'bank_import', 'cleared', NULL)");
        $db->exec("UPDATE accounting_transactions SET reference_id = 7201 WHERE id = 8202");
        $sum = (float)$db->query("SELECT SUM(t.amount) FROM accounting_transactions t WHERE t.type = 'expense' AND t.status IN ('cleared', 'reconciled')
                                  AND " . AccountingService::countedSql('t'))->fetchColumn();
        $this->assertEqualsWithDelta(128.20, $sum, 0.001, 'receipt + its linked bank line once, plus the unlinked line');
    }

    // ── g) ──────────────────────────────────────────────────────────────

    /**
     * g) 2026-10-12: the bank changes same-day running balances between exports, so deduping statement
     * lines on (date, amount, balance) kept copies that were the same line. Dedupe on (date, amount),
     * keeping the MOST copies any single file holds (two real $50 lines in one file stay two).
     */
    public function test_statement_dedupe_is_on_date_and_amount_keeping_the_most_copies_in_one_file(): void
    {
        $this->markTestIncomplete('B1 (monthly tie-out): no shared statement-dedupe helper exists yet (the 2026-10-12 tie-out was '
            . 'done by hand). Needed: file A has 2026-04-01 $50.00 twice (balances 900/850), file B has it once (balance 870) → '
            . '2 lines kept, not 3 and not 1; balance never part of the key.');
    }

    // ── h) ──────────────────────────────────────────────────────────────

    /**
     * h) 2026-10-12: payroll, a card bill and a Stripe fee were imported twice ($4,472.51). A bank line
     * already in the books (same day ±1, same amount, same statement kind) is a duplicate on re-import;
     * the card statement's own payment line is NOT a duplicate of the chequing line that paid it.
     */
    public function test_a_bank_line_imported_twice_is_flagged_as_a_duplicate(): void
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $diff = static fn($a, $b) => (int)round((strtotime((string)$a) - strtotime((string)$b)) / 86400);
        method_exists($db, 'createFunction') ? $db->createFunction('DATEDIFF', $diff, 2) : $db->sqliteCreateFunction('DATEDIFF', $diff, 2);
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, description TEXT,
                   reference_type TEXT, bank_account_id INT)");
        $db->exec("CREATE TABLE bank_statement_accounts (id INTEGER PRIMARY KEY, account_id INT, kind TEXT)");
        $db->exec("INSERT INTO bank_statement_accounts (account_id, kind) VALUES (1, 'bank'), (3, 'card')");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, reference_type, bank_account_id) VALUES
                   (8301, '2026-03-13', 'expense', 1234.56, 'FAKEPAY PYRL 00991', 'bank_import', 1),
                   (8302, '2026-03-20', 'transfer', 600.00, 'Card bill payment FAKE VISA', 'bank_import', 1)");
        $svc = new BankImportService($db);
        $dup = new ReflectionMethod(BankImportService::class, 'checkTrueDuplicate');

        $this->assertSame(8301, $dup->invoke($svc, '2026-03-13', 1234.56, 'expense', 'bank'), 'the payroll run imported again');
        $this->assertSame(8301, $dup->invoke($svc, '2026-03-14', 1234.56, 'expense', 'bank'), 'a day off between exports');
        $this->assertNull($dup->invoke($svc, '2026-03-20', 600.00, 'income', 'credit_card'), "the card statement's own payment line is a different line");
        $this->assertNull($dup->invoke($svc, '2026-03-13', 1234.66, 'expense', 'bank'), 'a different amount is another line');
        $this->assertNull($dup->invoke($svc, '2026-03-16', 1234.56, 'expense', 'bank'), 'three days later is another run');
    }

    // ── i) ──────────────────────────────────────────────────────────────

    /**
     * i) 2026-10-12: deposits that paid 2025 invoices (cheques Jobber never saw) sat as 2026 income.
     * A 2026 deposit paying a pre-2026 invoice settles the opening receivable (1100), not 2026 revenue.
     */
    public function test_a_deposit_paying_a_pre_2026_invoice_settles_receivable_not_2026_income(): void
    {
        $db = self::fixture(JobberImportTest::class, 'db');
        HeadLessonsTest::schema($db);
        (new JobberImportService($db))->import(self::jobberCsv('INVOICES'), [], 'none', 1, 'Invoices.csv');
        // 9006: issued 2025-12-01, $420, never marked paid in Jobber. The cheque arrives 2026-03-02.
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id)
                   VALUES (8401, '2026-03-02', 'income', 420.00, 20.00, 'Cheque deposit-branch', 5, 'bank_import', 1)");
        $inv = (int)$db->query("SELECT id FROM jobber_invoices WHERE jobber_number = '9006'")->fetchColumn();

        $r = (new JobberLedgerService($db, new LedgerService($db)))->bookMatched(8401, [], [['jobber_invoice_id' => $inv, 'amount' => 420.00]], 'test: 2025 invoice', 1);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(420.0, (float)$r['fy2025_part']);
        $this->assertSame(0.0, (float)$r['income_after'], 'nothing of it is 2026 income');
        $net = fn(int $acct) => round((float)$db->query("SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) FROM journal_lines WHERE account_id = {$acct}")->fetchColumn(), 2);
        $this->assertSame(-420.0, $net(12), 'credited to 1100 Accounts Receivable');
        $this->assertSame(420.0, $net(1), 'cash in once');
        $this->assertSame(0.0, $net(5), 'no 2026 revenue');
        $tx = $db->query("SELECT type, amount FROM accounting_transactions WHERE id = 8401")->fetch(PDO::FETCH_ASSOC);
        $this->assertFalse($tx['type'] === 'income' && (float)$tx['amount'] > 0.005, 'the single-entry line is not left as 2026 income');
    }
}
