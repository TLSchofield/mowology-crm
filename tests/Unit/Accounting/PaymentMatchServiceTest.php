<?php
/**
 * Payments reconciled against invoices (migration 1260): one matcher over CRM invoices AND the
 * Jobber import. Subsets by payer, a Dorset same-day batch against the Jobber payments of that day,
 * partials, a 2025 Jobber invoice paid in 2026 (no 2026 income), Stripe left alone, an idempotent
 * re-scan, and undo through the path that booked it. Fixture names are invented.
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PaymentMatchServiceTest extends TestCase
{
    // ── pure ────────────────────────────────────────────────────────────────

    public function testSubsetSearchFindsLargerSetsAndStaysBounded(): void
    {
        $cents = [10000, 25050, 40202, 40202, 12345, 9999, 33333, 1500, 2700, 8800, 15000, 7000];
        $ans = PaymentMatchService::subsets($cents, 10000 + 25050 + 12345 + 1500 + 2700 + 8800);
        $this->assertNotEmpty($ans);
        $this->assertSame(10000 + 25050 + 12345 + 1500 + 2700 + 8800, array_sum(array_map(fn($i) => $cents[$i], $ans[0])), 'six items, to the cent');
        $this->assertSame([], PaymentMatchService::subsets($cents, 1), 'nothing adds to a cent');
        $this->assertSame([], PaymentMatchService::subsets($cents, 999999, 10, 50), 'budget stops a hopeless search');
        $two = PaymentMatchService::subsets([40202, 40202, 40202], 80404);
        $this->assertTrue(PaymentMatchService::interchangeable($two, [40202, 40202, 40202]), '2 × $402.02 out of three is one answer');
    }

    public function testPayerIdentity(): void
    {
        $this->assertSame(['BCS4079', 'VR1540', 'LMS2881'], PaymentMatchService::strataIds('Strata Plan BCS-4079 / VR 1540 and LMS2881'));
        $id = PaymentMatchService::identity('PREAUTHORIZED CREDIT DORSET REALTY GR', [['GREEN LEAF HOLDINGS', 'Alexandra Bee']]);
        $this->assertSame(['dorset'], $id['words'], '"realty", "credit", "preauthorized" name nobody');
        $c = PaymentMatchService::cand('jb_inv', 1, 'Jobber #9101', ['BCS 4079 c/o Dorset Realty Group'], 100, '2026-01-01');
        $this->assertNotNull(PaymentMatchService::named($id, $c));
        $other = PaymentMatchService::cand('jb_inv', 2, 'Jobber #9102', ['Macdonald Realty Ltd'], 100, '2026-01-01');
        $this->assertNull(PaymentMatchService::named($id, $other), 'sharing "realty" is not the same payer');
        $alias = PaymentMatchService::identity('E-TRANSFER GREEN LEAF HOLDINGS', [['GREEN LEAF HOLDINGS', 'Alexandra Bee']]);
        $this->assertStringContainsString('taught', (string)PaymentMatchService::named($alias, PaymentMatchService::cand('crm_open', 3, 'INV-3', ['Alexandra Bee'], 50, '2026-03-01')));
        $strata = PaymentMatchService::identity('MISC PAYMENT STRATA PLAN VR1540');
        $this->assertSame('strata VR1540', PaymentMatchService::named($strata, PaymentMatchService::cand('crm_open', 4, 'INV-4', ['The Owners, Strata Plan VR 1540'], 50, '2026-03-01')));
    }

    public function testSubsetByPayerNeverMixesClients(): void
    {
        $c = $this->cands([
            PaymentMatchService::cand('crm_open', 1, 'INV-0001', ['BCS 4079 c/o Dorset Realty'], 402.02, '2026-03-01', ['invoice_id' => 1, 'issued' => '2026-03-01', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 2, 'INV-0002', ['VR 1540 c/o Dorset Realty'], 615.50, '2026-03-02', ['invoice_id' => 2, 'issued' => '2026-03-02', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 3, 'INV-0003', ['LMS 2881 c/o Dorset Realty'], 210.00, '2026-03-03', ['invoice_id' => 3, 'issued' => '2026-03-03', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 4, 'INV-0004', ['LMS 2881 c/o Dorset Realty'], 99.99, '2026-03-03', ['invoice_id' => 4, 'issued' => '2026-03-03', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 5, 'INV-0005', ['Patel'], 615.50, '2026-03-02', ['invoice_id' => 5, 'issued' => '2026-03-02', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 6, 'INV-0006', ['Patel'], 402.02, '2026-03-02', ['invoice_id' => 6, 'issued' => '2026-03-02', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 7, 'INV-0007', ['Nguyen'], 1000.00, '2026-03-02', ['invoice_id' => 7, 'issued' => '2026-03-02', 'partial_ok' => true]),
            PaymentMatchService::cand('crm_open', 8, 'INV-0008', ['Nguyen'], 227.52, '2026-03-02', ['invoice_id' => 8, 'issued' => '2026-03-02', 'partial_ok' => true]),
        ]);
        $deps = [$this->dep(1, '2026-04-01', 1227.52, 'PREAUTHORIZED CREDIT DORSET REALTY GR')];
        $l = PaymentMatchService::match($deps, $c)[0];
        $this->assertSame('high', $l['outcome'], $l['why']);
        $this->assertSame(['INV-0001', 'INV-0002', 'INV-0003'], array_column($l['targets'], 'number'), 'three strata plans of one property manager, not Patel or Nguyen');
        $this->assertSame('record_payment', $l['action']);

        // The memo names nobody: only a set from ONE payer is offered (Nguyen 1000 + 227.52), at medium.
        $u = PaymentMatchService::match([$this->dep(2, '2026-04-01', 1227.52, 'MOBILE DEPOSIT')], $c)[0];
        $this->assertSame('medium', $u['outcome'], $u['why']);
        $this->assertSame(['INV-0007', 'INV-0008'], array_column($u['targets'], 'number'));
    }

    public function testDorsetSameDayBatchMatchesThatDaysJobberPayments(): void
    {
        $pay = fn(int $id, float $amt, string $client, string $inv) => ['id' => $id, 'payment_date' => '2026-03-16', 'amount' => $amt, 'fee' => 0,
            'method' => 'Pre-authorized debit', 'client_name' => $client, 'invoice_numbers' => $inv, 'kind' => 'payment'];
        $units = JobberLedgerService::units([
            $pay(1, 840.00, 'BCS 4079 c/o Dorset Realty', '9201'), $pay(2, 840.00, 'BCS 2106 c/o Dorset Realty', '9202'),
            $pay(3, 1312.50, 'VR 1540 c/o Dorset Realty', '9203'), $pay(4, 262.50, 'LMS 2881 c/o Dorset Realty', '9204'),
            $pay(5, 157.50, 'LMS 2881 c/o Dorset Realty', '9205'),
        ]);
        $c = [];
        foreach ($units as $u) {
            $cand = PaymentMatchService::cand('jb_pay', 0, 'Jobber #' . $u['ids'][0], $u['clients'], $u['net'], $u['date'],
                ['unit' => $u['key'], 'payment_ids' => $u['ids'], 'method' => 'other', 'confirmation' => null, 'pre2026' => 0.0]);
            $c[$cand['key']] = $cand;
        }
        $deps = [
            $this->dep(11, '2026-03-17', 840.00, 'PREAUTHORIZED CREDIT DORSET REALTY GR'),
            $this->dep(12, '2026-03-17', 840.00, 'PREAUTHORIZED CREDIT DORSET REALTY GR'),
            $this->dep(13, '2026-03-17', 1312.50, 'PREAUTHORIZED CREDIT DORSET REALTY GR'),
            $this->dep(14, '2026-03-17', 420.00, 'PREAUTHORIZED CREDIT DORSET REALTY GR'),
        ];
        $lines = [];
        foreach (PaymentMatchService::match($deps, $c) as $l) $lines[$l['id']] = $l;
        $used = [];
        foreach ($lines as $id => $l) {
            $this->assertSame('high', $l['outcome'], "deposit {$id}: " . $l['why']);
            $this->assertSame('jobber', $l['action']);
            foreach ($l['targets'] as $t) $used[] = $t['key'];
        }
        $this->assertCount(5, array_unique($used), 'every Jobber payment of the day used exactly once');
        $this->assertCount(2, $lines[14]['targets'], '$420 = LMS 2881\'s two payments');
        $this->assertSame(1312.50, $lines[13]['targets'][0]['amount']);
    }

    public function testPartialsSeveralDepositsOneInvoice(): void
    {
        $c = $this->cands([PaymentMatchService::cand('crm_open', 9, 'INV-0009', ['Maria Garcia'], 800.00, '2026-03-01', ['invoice_id' => 9, 'issued' => '2026-03-01', 'partial_ok' => true])]);
        $deps = [$this->dep(21, '2026-03-20', 500.00, 'E-TRANSFER CREDIT MARIA GARCIA'), $this->dep(22, '2026-04-20', 300.00, 'E-TRANSFER CREDIT M GARCIA')];
        $lines = PaymentMatchService::match($deps, $c);
        $this->assertSame(['medium', 'medium'], array_column($lines, 'outcome'));
        $this->assertTrue($lines[0]['partial']);
        $this->assertStringContainsString('$300.00 would still be owing', (string)$lines[0]['note']);
        $this->assertStringContainsString('rest of INV-0009', (string)$lines[1]['note']);
        $open = PaymentMatchService::openCoverage($c, $lines);
        $this->assertSame('covered', $open[0]['status']);
        $this->assertSame(800.0, $open[0]['in_bank']);
        $this->assertSame(0.0, $lines[0]['income_delta'], 'recording moves income, never removes it');

        // A partial with nobody named is never guessed.
        $n = PaymentMatchService::match([$this->dep(23, '2026-03-20', 500.00, 'BRANCH DEPOSIT')], $c)[0];
        $this->assertSame('needs_you', $n['outcome']);
        $this->assertSame('INV-0009', $n['candidates'][0]['number']);
    }

    public function testStripeAfterCutoverIsLeftAlone(): void
    {
        $c = $this->cands([PaymentMatchService::cand('crm_open', 30, 'INV-0030', ['Chen'], 1234.56, '2026-03-01', ['invoice_id' => 30, 'issued' => '2026-03-01', 'partial_ok' => true])]);
        $l = PaymentMatchService::match([$this->dep(31, '2026-03-05', 1234.56, 'STRIPE TRANSFER')], $c)[0];
        $this->assertSame('stripe', $l['outcome']);
        $this->assertSame([], $l['targets']);
        $this->assertFalse(PaymentMatchService::bookable($l));
    }

    // ── DB: scan → book → re-scan → undo (SQLite, real paths) ───────────────

    public function testA2025JobberInvoicePaidIn2026IsNot2026IncomeAndUndoes(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO jobber_invoices (id, jobber_number, client_name, issued_date, status, subtotal, tax, tax_source, total, balance)
                   VALUES (1, '9006', 'BCS 4079 c/o Dorset Realty', '2025-12-01', 'past_due', 400, 20, 'computed', 420, 420)");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id, status)
                   VALUES (201, '2026-03-17', 'income', 420.00, 20.00, 'PREAUTHORIZED CREDIT DORSET REALTY GR', 5, 'bank_import', 1, 'cleared'),
                          (202, '2026-03-20', 'income', 1500.00, 0, 'STRIPE TRANSFER', 5, 'bank_import', 1, 'cleared')");
        $svc = new PaymentMatchService($db);
        $dry = $svc->dryRun();
        $this->assertSame(0, $dry['written']);
        $this->assertSame(420.0, $dry['summary']['fy2025_settled']);
        $this->assertSame(-420.0, $dry['summary']['income_2026_delta']);
        $this->assertSame(1, $dry['summary']['by_outcome']['stripe']['count']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM payment_match_log")->fetchColumn(), 'the dry run writes nothing');

        $scan = $svc->scan();
        $l = $this->line($scan, 201);
        $this->assertSame(['high', 'jobber', 420.0], [$l['outcome'], $l['action'], $l['fy2025_part']]);
        $this->assertSame('covered', $scan['open'][0]['status'], 'the open Jobber invoice shows its money in the bank');
        $this->assertSame($l['signature'], $this->line($svc->scan(), 201)['signature'], 're-scan is stable');

        $r = $svc->book([201 => $l['signature'], 202 => 'anything'], 1);
        $this->assertCount(1, $r['booked'], json_encode($r['failed']));
        $this->assertStringContainsString('Nothing proposed', $r['failed'][0]['message'], 'Stripe is never booked here');
        $tx = $db->query("SELECT type, amount FROM accounting_transactions WHERE id = 201")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('transfer', $tx['type'], 'paid a 2025 invoice: settles the opening receivable, not 2026 income');
        $this->assertSame(420.0, self::bal($db, 1), 'cash in');
        $this->assertSame(-420.0, self::bal($db, 12), 'opening receivable down');
        $this->assertSame(0.0, self::bal($db, 5, true), 'no revenue');
        $this->assertSame('income', $db->query("SELECT type FROM accounting_transactions WHERE id = 202")->fetchColumn());

        $again = $svc->scan();
        $this->assertNull($this->line($again, 201), 'booked deposits drop off');
        $this->assertSame([], array_values(array_filter($again['open'], fn($o) => $o['key'] === 'jb_inv:1')), 'the invoice is paid now');
        $this->assertFalse($svc->book([201 => $l['signature']], 1)['ok'], 'approving twice does nothing');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_deposit'")->fetchColumn());

        $logId = (int)$db->query("SELECT id FROM payment_match_log WHERE status = 'booked'")->fetchColumn();
        $u = $svc->undo($logId, 1);
        $this->assertTrue($u['ok'], $u['message']);
        $this->assertSame('income', $db->query("SELECT type FROM accounting_transactions WHERE id = 201")->fetchColumn());
        $this->assertSame(0.0, self::bal($db, 1));
        $this->assertSame(0.0, self::bal($db, 12));
        $this->assertSame($l['signature'], $this->line($svc->scan(), 201)['signature'], 'back on the list exactly as before');
        $this->assertFalse($svc->undo($logId, 1)['ok'], 'undone once');
    }

    public function testARecordedJobberPaymentBooksThroughTheJobberLedger(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO jobber_invoices (id, jobber_number, client_name, issued_date, status, subtotal, tax, tax_source, total, balance)
                   VALUES (1, '9401', 'Alice Example', '2026-01-15', 'paid', 100, 5, 'csv', 105, 0)");
        $db->exec("INSERT INTO jobber_payments (id, payment_key, kind, client_name, payment_date, amount, method, confirmation_no, invoice_numbers)
                   VALUES (1, 'k1', 'payment', 'Alice Example', '2026-01-20', 105.00, 'e-Transfer', 'C1A2B3C4D5E6', '9401')");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id, status)
                   VALUES (501, '2026-01-21', 'income', 105.00, 5.00, 'E-TRANSFER CREDIT ALICE EXAMPLE', 5, 'bank_import', 1, 'cleared')");
        $svc = new PaymentMatchService($db);
        $l = $this->line($svc->scan(), 501);
        $this->assertSame(['high', 'jobber', 0.0], [$l['outcome'], $l['action'], $l['fy2025_part']]);
        $this->assertTrue($svc->book([501 => $l['signature']], 1)['ok']);
        $this->assertSame(['income', 105.0], [$db->query("SELECT type FROM accounting_transactions WHERE id = 501")->fetchColumn(),
                                              (float)$db->query("SELECT amount FROM accounting_transactions WHERE id = 501")->fetchColumn()], 'paid a 2026 invoice: stays 2026 income');
        $this->assertSame(100.0, self::bal($db, 5, true), '2026 Jobber revenue posted once (net of GST)');
        $this->assertSame(0.0, self::bal($db, 12), 'receivable raised and settled');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM jobber_ledger_log WHERE op = 'payment' AND jobber_payment_id = 1")->fetchColumn());
    }

    public function testCrmAlreadyPaidLinksOnceAndUndoes(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO invoices (id, invoice_number, status, total, amount_paid, balance_due, payment_method, paid_at, updated_at, issue_date, invoice_date, bill_to_name)
                   VALUES (102, 'INV-0102', 'paid', 950, 950, 0, 'cheque', '2026-03-30 12:00:00', '2026-03-30', '2026-03-15', '2026-03-15', 'Patel'),
                          (103, 'INV-0103', 'paid', 300, 300, 0, 'cheque', '2026-03-30 12:00:00', '2026-03-30', '2026-03-15', '2026-03-15', 'Patel')");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, status)
                   VALUES (301, '2026-04-02', 'income', 1250.00, 'MOBILE DEPOSIT PATEL', 5, 'bank_import', 'cleared')");
        $svc = new PaymentMatchService($db);
        $l = $this->line($svc->scan(), 301);
        $this->assertSame(['high', 'already_recorded'], [$l['outcome'], $l['action']]);
        $this->assertSame(-1250.0, $l['income_delta'], 'the double count comes out');

        $r = $svc->book([301 => $l['signature']], 1);
        $this->assertTrue($r['ok'], json_encode($r['failed']));
        $this->assertSame(['transfer', 102], [$db->query("SELECT type FROM accounting_transactions WHERE id = 301")->fetchColumn(),
                                               (int)$db->query("SELECT matched_invoice_id FROM accounting_transactions WHERE id = 301")->fetchColumn()]);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM income_cleanup_log WHERE status = 'booked'")->fetchColumn(), 'booked through the income clean-up');
        $this->assertNull($this->line($svc->scan(), 301));
        $this->assertSame(0, count(array_filter($svc->scan()['candidates'], fn($c) => $c['key'] === 'crm_paid:103')), 'INV-0103 claimed too');

        $u = $svc->undo((int)$r['booked'][0]['log_id'], 1);
        $this->assertTrue($u['ok'], $u['message']);
        $this->assertSame('income', $db->query("SELECT type FROM accounting_transactions WHERE id = 301")->fetchColumn());
        $this->assertSame('reversed', $db->query("SELECT status FROM income_cleanup_log")->fetchColumn());
        $this->assertSame('high', $this->line($svc->scan(), 301)['outcome']);
    }

    public function testManualPickMustAddUpAndSkipIsRemembered(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO jobber_invoices (id, jobber_number, client_name, issued_date, status, subtotal, tax, tax_source, total, balance)
                   VALUES (1, '9301', 'Old Client A', '2025-11-01', 'past_due', 200, 10, 'computed', 210, 210),
                          (2, '9302', 'Old Client A', '2025-11-15', 'past_due', 100, 5, 'computed', 105, 105)");
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id, status)
                   VALUES (401, '2026-02-10', 'income', 300.00, 0, 'BRANCH DEPOSIT', 5, 'bank_import', 1, 'cleared')");
        $svc = new PaymentMatchService($db);
        $this->assertSame('needs_you', $this->line($svc->scan(), 401)['outcome']);
        $bad = $svc->manual(401, [['key' => 'jb_inv:1'], ['key' => 'jb_inv:2']], 1);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('difference $-15.00', $bad['message']);
        $ok = $svc->manual(401, [['key' => 'jb_inv:1'], ['key' => 'jb_inv:2', 'amount' => 90]], 1);
        $this->assertTrue($ok['ok'], $ok['message']);
        $this->assertSame('transfer', $db->query("SELECT type FROM accounting_transactions WHERE id = 401")->fetchColumn());
        $left = array_values(array_filter($svc->scan()['candidates'], fn($c) => $c['key'] === 'jb_inv:2'));
        $this->assertSame(15.0, $left[0]['amount'], 'part-paid: $15 still owing on #9302');

        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, status)
                   VALUES (402, '2026-05-01', 'income', 77.00, 'DEPOSIT', 5, 'bank_import', 'cleared')");
        $this->assertTrue($svc->skip(402, 1, 'owner loan repayment')['ok']);
        $s = $this->line($svc->scan(), 402);
        $this->assertSame('skipped', $s['outcome']);
        $this->assertStringContainsString('owner loan', $s['why']);
        $svc->unskip(402, 1);
        $this->assertSame('needs_you', $this->line($svc->scan(), 402)['outcome']);
        $card = $svc->cardLine();
        $this->assertNull($card, 'nothing proposed → Penny says nothing');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function cands(array $list): array
    {
        $o = [];
        foreach ($list as $c) $o[$c['key']] = $c;
        return $o;
    }

    private function dep(int $id, string $date, float $amount, string $desc): array
    {
        return ['id' => $id, 'date' => $date, 'amount' => $amount, 'gst_amount' => 0.0, 'description' => $desc];
    }

    private function line(array $scan, int $id): ?array
    {
        foreach ($scan['lines'] as $l) if ($l['id'] === $id) return $l;
        return null;
    }

    private static function bal(PDO $db, int $accountId, bool $credit = false): float
    {
        $v = (float)$db->query("SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) FROM journal_lines jl
                                JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = {$accountId}")->fetchColumn();
        return round($credit ? -$v : $v, 2) + 0.0;
    }

    private static function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BankLineMoveServiceTest::schema($db);
        foreach (['notes TEXT', 'match_confidence INTEGER', 'matched_at TEXT', 'matched_by TEXT', 'payment_reference TEXT', 'reference_id INTEGER'] as $col) {
            $db->exec("ALTER TABLE accounting_transactions ADD COLUMN {$col}");
        }
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (12, '1100', 'Accounts Receivable', 'asset'),
                   (30, '6800', 'Bank Charges & Fees', 'expense'), (31, '2160', 'Customer Deposits', 'liability')");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, property_name TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, total REAL, subtotal REAL DEFAULT 0, tax_amount REAL DEFAULT 0,
                   amount_paid REAL DEFAULT 0, balance_due REAL DEFAULT 0, payment_method TEXT, payment_reference TEXT, paid_at TEXT, updated_at TEXT, created_at TEXT,
                   issue_date TEXT, invoice_date TEXT, due_date TEXT, stripe_charge_id TEXT, bill_to_name TEXT, company_id INTEGER, contact_id INTEGER,
                   property_id INTEGER, plan_id INTEGER, notes TEXT)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INTEGER, transaction_id INTEGER, etransfer_notification_id INTEGER,
                   amount REAL, payment_date TEXT, method TEXT, reference TEXT, created_by INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY, transaction_id INTEGER, amount REAL, match_status TEXT)");
        $db->exec("CREATE TABLE income_cleanup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, bucket TEXT, action TEXT, amount REAL,
                   invoice_ids TEXT, invoice_numbers TEXT, allocation_ids TEXT, before_row TEXT, detail TEXT, status TEXT, note TEXT,
                   booked_by INTEGER, booked_at TEXT, reversed_by INTEGER, reversed_at TEXT)");
        $db->exec("CREATE TABLE jobber_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, jobber_number TEXT UNIQUE, contact_id INTEGER, company_id INTEGER, property_id INTEGER,
                   client_name TEXT, issued_date TEXT, status TEXT, subtotal REAL, tax REAL, tax_source TEXT, total REAL, balance REAL, crm_invoice_id INTEGER)");
        $db->exec("CREATE TABLE jobber_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_key TEXT UNIQUE, kind TEXT, client_name TEXT, contact_id INTEGER,
                   payment_date TEXT, amount REAL, fee REAL DEFAULT 0, method TEXT, cheque_no TEXT, confirmation_no TEXT, invoice_numbers TEXT, quote_number TEXT,
                   payout_id TEXT, refunded_on TEXT)");
        $db->exec("CREATE TABLE jobber_import_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT)");
        $db->exec("CREATE TABLE jobber_ledger_log (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT, op TEXT, jobber_invoice_id INTEGER, jobber_payment_id INTEGER,
                   transaction_id INTEGER, entry_id INTEGER, target_entry_id INTEGER, amount REAL, entry_date TEXT, tx_before_type TEXT, tx_before_amount REAL,
                   tx_before_gst REAL, tx_before_status TEXT, created_by INTEGER, created_at TEXT, undone_at TEXT, undone_by INTEGER, undo_entry_id INTEGER)");
        $db->exec("CREATE TABLE payment_match_log (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, confidence TEXT, source TEXT, action TEXT, amount REAL,
                   fy2025_part REAL DEFAULT 0, targets TEXT, signature TEXT, path TEXT, path_ref TEXT, status TEXT, note TEXT, created_by INTEGER, created_at TEXT,
                   undone_by INTEGER, undone_at TEXT)");
        $db->exec("CREATE TABLE payment_match_snapshot (id INTEGER PRIMARY KEY, computed_at TEXT, waiting INTEGER, waiting_total REAL, high INTEGER, high_total REAL,
                   needs_you INTEGER, needs_you_total REAL)");
        return $db;
    }
}
