<?php
/**
 * Bank balance check (migration 1236): each invoice payment its own journal entry, the
 * part-payment backfill, Jobber-era deposits without double counting, the opening balance,
 * the drift math, locked months skipped, and undo.
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BankBalanceCheckServiceTest extends TestCase
{
    private const FROM = '2026-10-07 12:00:00';

    // ── InvoicePaymentPlanner (pure) ────────────────────────────────────────

    public function testLegacyEntryCoversEarlierPaymentsAndLaterPartPaymentIsMissing(): void
    {
        $inv = ['id' => 7, 'amount_paid' => 1000, 'paid_at' => '2026-05-01 12:00:00'];
        $pay = [
            ['kind' => 'alloc', 'id' => 1, 'date' => '2026-05-01', 'amount' => 400, 'created_at' => '2026-05-01 12:00:00'],
            ['kind' => 'alloc', 'id' => 2, 'date' => '2026-06-15', 'amount' => 600, 'created_at' => '2026-06-15 09:00:00'],
        ];
        $legacy = ['entry_id' => 90, 'amount' => 400, 'entry_date' => '2026-05-01', 'created_at' => '2026-05-02 03:00:00'];
        $plan = InvoicePaymentPlanner::plan($inv, $pay, [], $legacy, self::FROM);
        $this->assertSame('missing', $plan['state']);
        $this->assertCount(1, $plan['actions']);
        $this->assertSame('payment_allocation', $plan['actions'][0]['source_type']);
        $this->assertSame(2, $plan['actions'][0]['source_id']);
        $this->assertSame(600.0, $plan['effect']);
        $this->assertFalse($plan['actions'][0]['auto'], 'recorded before the go-forward moment → a proposal, not automatic');

        $pay[1]['created_at'] = '2026-10-08 09:00:00';
        $plan = InvoicePaymentPlanner::plan($inv, $pay, [], $legacy, self::FROM);
        $this->assertTrue($plan['actions'][0]['auto'], 'recorded after → the nightly sync posts it');

        // Once posted it is ok.
        $plan = InvoicePaymentPlanner::plan($inv, $pay, ['payment_allocation:2' => ['entry_id' => 91, 'amount' => 600]], $legacy, self::FROM);
        $this->assertSame('ok', $plan['state']);
        $this->assertSame([], $plan['actions']);
    }

    public function testLegacyThatDoesNotAddUpIsReversedAndEveryPaymentPostedAlone(): void
    {
        // Legacy posted $1,000 but an allocation was detached later: only $700 is on record.
        $inv = ['id' => 8, 'amount_paid' => 700, 'paid_at' => '2026-04-01'];
        $pay = [['kind' => 'alloc', 'id' => 5, 'date' => '2026-04-01', 'amount' => 700, 'created_at' => '2026-04-01 10:00:00']];
        $legacy = ['entry_id' => 50, 'amount' => 1000, 'entry_date' => '2026-04-01', 'created_at' => '2026-04-02 00:00:00'];
        $plan = InvoicePaymentPlanner::plan($inv, $pay, [], $legacy, self::FROM);
        $this->assertSame('redo', $plan['state']);
        $this->assertSame('reverse', $plan['actions'][0]['op']);
        $this->assertSame(50, $plan['actions'][0]['entry_id']);
        $this->assertSame('post', $plan['actions'][1]['op']);
        $this->assertSame(-300.0, $plan['effect']);
        foreach ($plan['actions'] as $a) $this->assertFalse($a['auto'], 'a reversal is never automatic');
    }

    public function testNoEntryYetPostsEachPaymentAndTheCashPartOfTheRest(): void
    {
        // $250 Stripe + $500 allocation + $250 direct, of which $100 was account credit (not cash).
        $inv = ['id' => 9, 'amount_paid' => 1000, 'paid_at' => '2026-07-01', 'credit_applied' => 100];
        $pay = [
            ['kind' => 'stripe', 'id' => 3, 'date' => '2026-06-01', 'amount' => 250, 'created_at' => '2026-06-01 10:00:00'],
            ['kind' => 'alloc', 'id' => 4, 'date' => '2026-06-20', 'amount' => 500, 'created_at' => '2026-06-20 10:00:00'],
        ];
        $plan = InvoicePaymentPlanner::plan($inv, $pay, [], null, self::FROM);
        $this->assertSame('missing', $plan['state']);
        $types = array_map(fn($a) => $a['source_type'] . ':' . $a['source_id'] . '=' . $a['amount'], $plan['actions']);
        $this->assertSame(['stripe_payment:3=250', 'payment_allocation:4=500', 'payment:9=150'], $types);
        $this->assertSame(900.0, $plan['effect']);
    }

    public function testMorePaymentsOnRecordThanPaidIsReviewOnly(): void
    {
        $plan = InvoicePaymentPlanner::plan(['id' => 1, 'amount_paid' => 100],
            [['kind' => 'alloc', 'id' => 1, 'date' => '2026-01-01', 'amount' => 150, 'created_at' => '2026-01-01']], [], null, self::FROM);
        $this->assertSame('review', $plan['state']);
        $this->assertSame([], $plan['actions']);
    }

    // ── drift math (pure) ───────────────────────────────────────────────────

    public function testDriftTableAttributesAndCarriesBalancesThroughMonthsWithoutAStatement(): void
    {
        $closing = ['2025-01' => 5100.0, '2025-03' => 5000.0];
        $books = ['2024-12' => 4865.0, '2025-01' => 100.0, '2025-02' => -500.0];   // cumulative 4965 / 4465 / 4465
        $causes = [
            'opening' => [['2025-01-02', 135.0]],
            'jobber_deposits' => [['2025-02-10', 300.0]],
            'statement_gaps' => [['2025-03-05', 100.0]],
        ];
        $rows = BankBalanceCheckService::driftTable($closing, $books, $causes, ['opening', 'jobber_deposits']);
        $this->assertSame(['2025-01', '2025-02', '2025-03'], array_column($rows, 'ym'));
        $this->assertSame(-135.0, $rows[0]['drift']);
        $this->assertSame(135.0 * -1, $rows[0]['causes']['opening']);
        $this->assertSame(0.0, $rows[0]['remainder']);
        $this->assertNull($rows[1]['drift'], 'no statement that month');
        $this->assertSame(4465.0, $rows[2]['books']);
        $this->assertSame(-535.0, $rows[2]['drift']);
        $this->assertSame(-400.0, $rows[2]['delta'], 'change since the last month with a statement');
        $this->assertSame(0.0, $rows[2]['remainder']);
        $this->assertSame(-100.0, $rows[2]['after'], 'after the two fix groups; the gap is review only');
    }

    public function testStatementBalancesUnwindTheOpening(): void
    {
        $lines = [
            ['date' => '2025-01-03', 'amount' => 100.0, 'signed' => 100.0, 'balance' => 5100.0],
            ['date' => '2025-01-20', 'amount' => 50.0, 'signed' => -50.0, 'balance' => 5050.0],
            ['date' => '2025-02-02', 'amount' => 10.0, 'signed' => -10.0, 'balance' => null],
            ['date' => '2025-02-03', 'amount' => 40.0, 'signed' => -40.0, 'balance' => 5000.0],
        ];
        $b = BankBalanceCheckService::statementBalances($lines);
        $this->assertSame(5000.0, $b['opening']);
        $this->assertSame('2025-01-03', $b['first_date']);
        $this->assertSame(['2025-01' => 5050.0, '2025-02' => 5000.0], $b['closing']);
    }

    // ── DB: part-payment backfill + undo ────────────────────────────────────

    public function testPartPaymentBackfillPostsTheMissingPaymentAndUndoTakesItOut(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        $db->exec("INSERT INTO invoices (id, invoice_number, total, subtotal, tax_amount, amount_paid, status, paid_at, created_at, issue_date)
                   VALUES (1, 'INV-2026-0001', 1000, 1000, 0, 1000, 'paid', '2026-05-01 12:00:00', '2026-04-20', '2026-04-20')");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, amount, payment_date, created_at) VALUES
                   (1, 1, 400, '2026-05-01', '2026-05-01 12:00:00'), (2, 1, 600, '2026-06-15', '2026-06-15 09:00:00')");
        $legacy = $ledger->postPayment(['id' => 1, 'invoice_id' => 1, 'date' => '2026-05-01', 'amount' => 400]);
        $db->exec("UPDATE journal_entries SET created_at = '2026-05-02 03:00:00' WHERE id = {$legacy}");

        $svc = new BankBalanceCheckService($db, $ledger);
        $grp = $svc->fixGroups()['part_payments'];
        $this->assertSame(1, $grp['count']);
        $this->assertSame(600.0, $grp['total']);
        $this->assertSame(400.0, self::balance($db, 1));

        $res = $svc->approve('part_payments', $grp['signature'], 1);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(1000.0, self::balance($db, 1));
        $this->assertSame(0, $svc->fixGroups()['part_payments']['count'], 'nothing left to post');

        // A second approval with the old signature is refused (it changed).
        $this->assertFalse($svc->approve('part_payments', $grp['signature'], 1)['ok']);

        $undo = $svc->undo($res['batch_id'], 1);
        $this->assertTrue($undo['ok'], $undo['message']);
        $this->assertSame(400.0, self::balance($db, 1));
        $this->assertSame(1, $svc->fixGroups()['part_payments']['count'], 'back on the list');
    }

    public function testRedoReversesTheLegacyEntryAndUndoPutsItBack(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        $db->exec("INSERT INTO invoices (id, invoice_number, total, subtotal, tax_amount, amount_paid, status, paid_at, created_at, issue_date)
                   VALUES (2, 'INV-2026-0002', 1000, 1000, 0, 700, 'partial', '2026-04-01', '2026-03-20', '2026-03-20')");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, amount, payment_date, created_at) VALUES (5, 2, 700, '2026-04-01', '2026-04-01 10:00:00')");
        $legacy = $ledger->postPayment(['id' => 2, 'invoice_id' => 2, 'date' => '2026-04-01', 'amount' => 1000]);
        $db->exec("UPDATE journal_entries SET created_at = '2026-04-02 00:00:00' WHERE id = {$legacy}");

        $svc = new BankBalanceCheckService($db, $ledger);
        $grp = $svc->fixGroups()['part_payments'];
        $this->assertSame(-300.0, $grp['total']);
        $res = $svc->approve('part_payments', $grp['signature'], 1);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(700.0, self::balance($db, 1));
        $this->assertNotNull($db->query("SELECT reversed_by_entry_id FROM journal_entries WHERE id = {$legacy}")->fetchColumn());

        $svc->undo($res['batch_id'], 1);
        $this->assertSame(1000.0, self::balance($db, 1));
        $live = (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'payment' AND source_id = 2 AND reversed_by_entry_id IS NULL")->fetchColumn();
        $this->assertSame(1, $live, 'the old entry is back (as a re-posted copy)');
    }

    public function testNightlySyncPostsNewPartPaymentsOnlyAfterTheMigration(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        $db->exec("DELETE FROM ops_settings");
        $db->exec("INSERT INTO invoices (id, invoice_number, total, subtotal, tax_amount, amount_paid, status, paid_at, created_at, issue_date)
                   VALUES (3, 'INV-2026-0003', 1000, 1000, 0, 400, 'partial', '2026-09-01', '2026-08-20', '2026-08-20')");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, amount, payment_date, created_at) VALUES (8, 3, 400, '2026-09-01', '2026-09-01 10:00:00')");
        $sync = new LedgerSyncService($db, $ledger);
        $sync->syncInvoices();   // old way: one entry for amount_paid
        $this->assertSame(400.0, self::balance($db, 1));
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'payment' AND source_id = 3")->fetchColumn());
        $db->exec("UPDATE journal_entries SET created_at = '2026-09-02 00:00:00' WHERE source_type = 'payment'");

        // Migration 1236 runs; a second part payment arrives after it.
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('ledger_payments_each_from', '" . self::FROM . "')");
        $db->exec("UPDATE invoices SET amount_paid = 1000, status = 'paid' WHERE id = 3");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, amount, payment_date, created_at) VALUES (9, 3, 600, '2026-10-08', '2026-10-08 09:00:00')");
        $sync->syncInvoices();
        $sync->syncInvoices();   // idempotent
        $this->assertSame(1000.0, self::balance($db, 1));
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'payment_allocation' AND source_id = 9")->fetchColumn());
    }

    // ── DB: Jobber-era deposits ─────────────────────────────────────────────

    public function testJobberDepositsPostOnceAndNeverDoubleCount(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, bank_account_id, matched_invoice_id) VALUES
                   (10, '2025-05-10', 'income', 500, 'e-Transfer deposit SMITH', 5, 'bank_import', NULL, NULL),
                   (11, '2025-06-10', 'income', 300, 'e-Transfer deposit JONES', 5, 'bank_import', NULL, NULL),
                   (12, '2025-07-10', 'income', 200, 'Deposit booked by clean-up', 5, 'bank_import', NULL, NULL),
                   (13, '2026-03-05', 'income', 450, 'e-Transfer CRM client', 5, 'bank_import', NULL, NULL),
                   (14, '2026-05-05', 'income', 999, 'After Jobber', 5, 'bank_import', NULL, NULL),
                   (15, '2025-08-01', 'transfer', 800, 'Linked to invoice', 5, 'bank_import', NULL, 4)");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, transaction_id, amount, payment_date, created_at) VALUES (20, 99, 11, 300, '2025-06-10', '2025-06-10')");
        $db->exec("INSERT INTO income_cleanup_log (transaction_id, status) VALUES (12, 'booked')");
        $db->exec("INSERT INTO invoices (id, invoice_number, total, amount_paid, status, issue_date, created_at) VALUES (5, 'INV-2026-0005', 450, 0, 'sent', '2026-02-26', '2026-02-26')");

        $svc = new BankBalanceCheckService($db, $ledger);
        $j = $svc->jobberItems();
        $this->assertSame(['tx:10'], array_column($j['post'], 'key'), 'allocated, cleaned-up, after-March and linked deposits are out');
        $this->assertSame(450.0, $j['crm'][0]['amount']);
        $this->assertSame('INV-2026-0005', $j['crm'][0]['invoice_number']);

        $grp = $svc->fixGroups()['jobber_deposits'];
        $res = $svc->approve('jobber_deposits', $grp['signature'], 1);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(500.0, self::balance($db, 1));
        $this->assertSame(500.0, self::balance($db, 5, true), 'revenue credited');

        // The nightly bank sync must not post it again, and a second look finds nothing.
        (new LedgerSyncService($db, $ledger))->syncBankImports();
        $this->assertSame(500.0, self::balance($db, 1));
        $svc->forget();
        $this->assertSame(0, $svc->fixGroups()['jobber_deposits']['count']);
    }

    public function testLockedMonthIsSkipped(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2025, 5, 'locked')");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type) VALUES
                   (10, '2025-05-10', 'income', 500, 'Locked month', 5, 'bank_import'),
                   (11, '2025-06-10', 'income', 300, 'Open month', 5, 'bank_import')");
        $svc = new BankBalanceCheckService($db, new LedgerService($db));
        $grp = $svc->fixGroups()['jobber_deposits'];
        $this->assertSame(1, $grp['count']);
        $this->assertSame(1, $grp['locked']);
        $res = $svc->approve('jobber_deposits', $grp['signature'], 1);
        $this->assertSame(1, $res['booked']);
        $this->assertSame(300.0, self::balance($db, 1));
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_deposit' AND source_id = 10")->fetchColumn());
    }

    // ── DB: opening balance + report ────────────────────────────────────────

    public function testOpeningBalanceTopsUpToTheFirstStatementAndTheReportShowsNoDrift(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        // FY2024 opening (migration 1067) put $4,865 on 1010.
        $ledger->postManual(['entry_date' => '2025-01-01', 'memo' => 'FY2024 opening', 'source_type' => 'opening', 'source_id' => 2024,
                             'lines' => [['account_id' => 1, 'debit' => 4865, 'credit' => 0], ['account_id' => 39, 'debit' => 0, 'credit' => 4865]]]);
        $db->exec("INSERT INTO bank_import_sessions (id, bank_account_id, bank_name, status) VALUES (1, 1, 'Vancity', 'imported')");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, bank_account_id) VALUES
                   (30, '2025-01-03', 'expense', 100, 'Shell', 10, 'bank_import', 1)");
        $raw = json_encode(['amount' => 100, 'type' => 'expense', 'raw_line' => '2025-01-03,Shell,100.00,,4900.00']);
        $db->prepare("INSERT INTO bank_import_rows (id, session_id, transaction_date, type, amount, raw_amount, raw_row, transaction_id) VALUES (1, 1, '2025-01-03', 'expense', 100, 100, ?, 30)")
           ->execute([$raw]);
        (new LedgerSyncService($db, $ledger))->syncBankImports();

        $svc = new BankBalanceCheckService($db, $ledger);
        $rep = $svc->report();
        $acct = $rep['accounts'][0];
        $this->assertSame('1010', $acct['code']);
        $this->assertSame(5000.0, $acct['opening']);
        $this->assertSame(-135.0, $acct['latest']['drift']);
        $this->assertSame(0.0, $acct['latest']['remainder']);
        $this->assertSame(0.0, $acct['latest']['after']);
        $this->assertSame([['code' => '1010', 'before' => -135.0, 'after' => 0.0]], $rep['groups']['opening']['preview']);

        $res = $svc->approve('opening', $rep['groups']['opening']['signature'], 1);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame('2025-01-02', $db->query("SELECT entry_date FROM journal_entries WHERE source_type = 'bank_opening'")->fetchColumn());
        $this->assertSame(135.0, self::balance($db, 39) * -1 - 4865.0);
        $svc->forget();
        $this->assertSame(0.0, $svc->report()['accounts'][0]['latest']['drift']);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** Net Dr − Cr of an account (Cr − Dr when $credit). */
    private static function balance(PDO $db, int $accountId, bool $credit = false): float
    {
        $v = (float)$db->query("SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) FROM journal_lines jl
                                JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = {$accountId}")->fetchColumn();
        return round($credit ? -$v : $v, 2);
    }

    private static function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BankLineMoveServiceTest::schema($db);
        $db->exec("ALTER TABLE accounting_transactions ADD COLUMN notes TEXT");
        $db->exec("ALTER TABLE journal_entries ADD COLUMN created_at TEXT DEFAULT CURRENT_TIMESTAMP");
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (12, '1100', 'Accounts Receivable', 'asset'),
                   (20, '1020', 'Reserve funds (Savings ••6819)', 'asset'), (25, '1025', 'GST Reserves (Savings ••6827)', 'asset'),
                   (39, '3900', 'Opening Balance Equity', 'equity')");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, total REAL, subtotal REAL DEFAULT 0, tax_amount REAL DEFAULT 0,
                   amount_paid REAL DEFAULT 0, status TEXT, paid_at TEXT, created_at TEXT, issue_date TEXT, contact_id INTEGER, plan_id INTEGER,
                   contract_id INTEGER, service_type TEXT)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INTEGER, transaction_id INTEGER, etransfer_notification_id INTEGER,
                   amount REAL, payment_date TEXT, method TEXT, reference TEXT, created_by INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE stripe_payments (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount_cents INTEGER, status TEXT, webhook_received_at TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE client_credits (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, amount REAL, invoice_id INTEGER)");
        $db->exec("CREATE TABLE ops_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT UNIQUE, setting_value TEXT, description TEXT)");
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('ledger_payments_each_from', '" . self::FROM . "')");
        $db->exec("CREATE TABLE bank_import_sessions (id INTEGER PRIMARY KEY, bank_account_id INTEGER, bank_name TEXT, status TEXT)");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY, session_id INTEGER, transaction_date TEXT, type TEXT, amount REAL, raw_amount REAL,
                   is_duplicate INTEGER DEFAULT 0, raw_row TEXT, transaction_id INTEGER)");
        $db->exec("CREATE TABLE income_cleanup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, status TEXT)");
        $db->exec("CREATE TABLE bank_balance_fix_log (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT, fix_group TEXT, item_key TEXT, op TEXT,
                   entry_id INTEGER, target_entry_id INTEGER, source_type TEXT, source_id INTEGER, amount REAL, entry_date TEXT, created_by INTEGER,
                   created_at TEXT, undone_at TEXT, undone_by INTEGER, undo_entry_id INTEGER)");
        return $db;
    }
}
