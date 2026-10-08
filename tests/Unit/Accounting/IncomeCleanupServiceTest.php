<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's income clean-up (2026-10-07): 2026 bank deposits still booked as income next to
 * the invoices they paid. Bucketing, one claim per invoice/payment, locked months, the
 * before/after income math, booking + undo through the real reconciliation paths.
 */
class IncomeCleanupServiceTest extends TestCase
{
    // ── classify() ───────────────────────────────────────────────────────────

    public function test_each_kind_of_deposit_lands_in_its_bucket(): void
    {
        $lines = $this->byId(IncomeCleanupService::classify($this->deposits(), $this->payments(), $this->invoices(), ['cutover' => '2026-02-25']));

        $this->assertSame('jobber', $lines[1]['bucket'], 'Jan 16 funds transfer is Jobber era');
        $this->assertSame('a transfer between accounts', $lines[1]['flag']);
        $this->assertSame('none', $lines[1]['action'], 'Jobber era is never booked here');
        $this->assertSame('jobber', $lines[2]['bucket']);
        $this->assertNull($lines[2]['flag'], 'an ordinary Jobber-era e-Transfer stays income, unflagged');

        $this->assertSame('stripe', $lines[3]['bucket']);
        $this->assertSame('stripe_payout', $lines[3]['action']);

        $this->assertSame(['exact', 'link_payments'], [$lines[4]['bucket'], $lines[4]['action']], 'a payment recorded by hand from the Interac email');
        $this->assertSame([501], $lines[4]['allocation_ids']);

        $this->assertSame(['exact', 'already_recorded'], [$lines[5]['bucket'], $lines[5]['action']], 'paid the old way, no allocation rows');
        $this->assertSame(['INV-0102'], $lines[5]['invoice_numbers']);

        $this->assertSame(['exact_sum', 'already_recorded'], [$lines[6]['bucket'], $lines[6]['action']], 'Dorset 2 × $402.02');
        $this->assertSame(['INV-0360', 'INV-0419'], $lines[6]['invoice_numbers']);

        $this->assertSame(['exact', 'record_payment'], [$lines[7]['bucket'], $lines[7]['action']], 'the only open invoice of this amount');
        $this->assertSame([['invoice_id' => 110, 'amount' => 777.0]], $lines[7]['allocations']);
        $this->assertSame(0.0, $lines[7]['income_delta'], 'recording moves income, it doesn\'t remove it');

        $this->assertSame('needs_you', $lines[8]['bucket']);
        $this->assertStringContainsString('paid by card', $lines[8]['say'], 'same amount as a Stripe-paid invoice is a conflict, not a match');

        $this->assertSame('needs_you', $lines[9]['bucket']);
        $this->assertNotEmpty($lines[9]['candidates'], 'closest candidates shown');
        $this->assertSame('none', $lines[9]['action']);
    }

    public function test_an_invoice_or_payment_is_claimed_by_one_deposit_only(): void
    {
        $deps = [$this->dep(20, '2026-05-01', 650.00, 'E-TRANSFER CREDIT'), $this->dep(21, '2026-05-03', 650.00, 'E-TRANSFER CREDIT')];
        $inv  = [$this->inv(200, 'INV-0200', 'paid', 650.00, 0, '2026-04-30', 'Smith')];
        $lines = $this->byId(IncomeCleanupService::classify($deps, [], $inv, ['cutover' => '2026-02-25']));

        $this->assertSame('already_recorded', $lines[20]['action']);
        $this->assertSame('needs_you', $lines[21]['bucket'], 'the second $650 can\'t reuse INV-0200');
    }

    public function test_an_invoice_already_tied_to_another_deposit_is_never_offered(): void
    {
        $inv = [$this->inv(201, 'INV-0201', 'paid', 300.00, 0, '2026-06-01', 'Lee', ['claimed_by' => 999])];
        $lines = IncomeCleanupService::classify([$this->dep(22, '2026-06-02', 300.00, 'DEPOSIT')], [], $inv, ['cutover' => '2026-02-25']);
        $this->assertSame('needs_you', $lines[0]['bucket']);
        $this->assertStringContainsString('#999', $lines[0]['say']);
    }

    public function test_an_open_invoice_is_not_paid_when_two_clients_owe_the_same_and_the_memo_says_nobody(): void
    {
        $inv = [$this->inv(300, 'INV-0300', 'sent', 0, 120.00, '2026-06-01', 'Able'), $this->inv(301, 'INV-0301', 'sent', 0, 120.00, '2026-06-02', 'Baker')];
        $lines = IncomeCleanupService::classify([$this->dep(23, '2026-06-10', 120.00, 'E-TRANSFER CREDIT REF 77 TIMATMOWOLOGY')], [], $inv, ['cutover' => '2026-02-25']);
        $this->assertSame('needs_you', $lines[0]['bucket'], 'recording a payment writes to a client\'s account — never a guess');
        $this->assertStringContainsString('2 open invoices', (string)$lines[0]['note']);

        $named = IncomeCleanupService::classify([$this->dep(24, '2026-06-10', 120.00, 'E-TRANSFER CREDIT BAKER')], [], $inv, ['cutover' => '2026-02-25']);
        $this->assertSame(['exact', 'record_payment', ['INV-0301']], [$named[0]['bucket'], $named[0]['action'], $named[0]['invoice_numbers']]);
    }

    public function test_locked_and_skipped_lines_are_not_bookable(): void
    {
        $lines = $this->byId(IncomeCleanupService::classify($this->deposits(), $this->payments(), $this->invoices(),
            ['cutover' => '2026-02-25', 'locked' => ['2026-04'], 'decided' => [4 => ['status' => 'skipped', 'note' => 'mine']]]));
        $this->assertTrue($lines[5]['locked']);
        $this->assertFalse(IncomeCleanupService::isBookable($lines[5]));
        $this->assertTrue($lines[4]['skipped']);
        $this->assertFalse(IncomeCleanupService::isBookable($lines[4]));
        $this->assertStringContainsString('locked', (string)IncomeCleanupService::refusal($lines[5], $lines[5]['signature'], false));
        $this->assertStringContainsString('skipped', (string)IncomeCleanupService::refusal($lines[4], $lines[4]['signature'], false));
        $this->assertStringContainsString('changed', (string)IncomeCleanupService::refusal($lines[3], 'not-the-signature', false));
        $this->assertNull(IncomeCleanupService::refusal($lines[3], $lines[3]['signature'], false));
        $this->assertStringContainsString('locked', (string)IncomeCleanupService::refusal($lines[3], $lines[3]['signature'], true), 'locked at booking time');
    }

    public function test_income_before_and_after(): void
    {
        $lines = IncomeCleanupService::classify($this->deposits(), $this->payments(), $this->invoices(), ['cutover' => '2026-02-25']);
        $income = ['2026-01' => ['income' => 2700.00, 'ledger_101' => 2700.00], '2026-03' => ['income' => 5000.00, 'ledger_101' => 5000.00],
                   '2026-04' => ['income' => 3000.00, 'ledger_101' => 3000.00], '2026-05' => ['income' => 4000.00, 'ledger_101' => 4000.00],
                   '2026-06' => ['income' => 1500.00, 'ledger_101' => 1500.00]];
        $e = IncomeCleanupService::incomeEffect($income, $lines);
        $byMonth = [];
        foreach ($e['months'] as $m) $byMonth[$m['month']] = $m;

        // Removed: Stripe 1,234.56 (Mar), recorded 500 (Mar), INV-0102 950 (Apr), Dorset 804.04 (Apr). Not: record_payment (0), needs-you, Jobber.
        $this->assertEqualsWithDelta(-1734.56, $byMonth['2026-03']['delta'], 0.001);
        $this->assertEqualsWithDelta(-1754.04, $byMonth['2026-04']['delta'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byMonth['2026-05']['delta'], 0.001, 'recording a payment on an open invoice changes no total');
        $this->assertEqualsWithDelta(0.0, $byMonth['2026-01']['delta'], 0.001, 'Jobber era is not touched');
        $this->assertEqualsWithDelta(2200.00, $byMonth['2026-01']['jobber_flagged'], 0.001);
        $this->assertEqualsWithDelta(16200.00 - 3488.60, $e['total']['after'], 0.001);
        $this->assertEqualsWithDelta(399.99 + 1111.11, $e['total']['needs_you'], 0.001);
        $this->assertEqualsWithDelta($e['total']['after'] - $e['total']['needs_you'], $e['total']['after_if_needs_you_double'], 0.001);

        $groups = IncomeCleanupService::summarise($lines);
        $this->assertSame(1, $groups['stripe']['bookable']);
        $this->assertEqualsWithDelta(-1450.00, $groups['exact']['income_delta'], 0.001, 'exact: 500 linked + 950 paid + 0 recorded');
        $this->assertEqualsWithDelta(-804.04, $groups['exact_sum']['income_delta'], 0.001);
        $this->assertSame(1, $groups['jobber']['flagged']);
    }

    public function test_gst_changes_to_a_filed_period_become_an_adjustment_on_the_next_return(): void
    {
        $lines = IncomeCleanupService::classify($this->deposits(), $this->payments(), $this->invoices(), ['cutover' => '2026-02-25']);
        $income = ['2026-03' => ['income' => 5000.00, 'ledger_101' => 5000.00], '2026-04' => ['income' => 3000.00, 'ledger_101' => 3000.00]];
        $g = IncomeCleanupService::gstEffect(2026, $income, $lines, [
            ['period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'filed_on' => '2026-04-30', 'basis' => 'ledger', 'line_101' => 9000],
            ['period_from' => '2026-04-01', 'period_to' => '2026-06-30', 'filed_on' => '2026-07-30', 'basis' => 'invoices', 'line_101' => null],
        ]);
        $this->assertEqualsWithDelta(-1734.56, $g['periods'][0]['ledger_delta'], 0.001);
        $this->assertEqualsWithDelta(5000.00 - 1734.56, $g['periods'][0]['ledger_after'], 0.001);
        $this->assertSame(0.0, $g['periods'][0]['invoice_basis_delta'], 'line 101 from invoices does not move');
        $this->assertTrue($g['filed'][0]['adjust']);
        $this->assertStringContainsString('NEXT return', $g['filed'][0]['say']);
        $this->assertFalse($g['filed'][1]['adjust'], 'filed from invoices: nothing to adjust');
    }

    public function test_non_income_reasons(): void
    {
        $this->assertSame('a transfer between accounts', IncomeCleanupService::nonIncomeReason('TFR-FR 01234 5678'));
        $this->assertSame('loan or credit-line money', IncomeCleanupService::nonIncomeReason('TD LOAN ADVANCE'));
        $this->assertSame('a refund or reversal', IncomeCleanupService::nonIncomeReason('HOME DEPOT REFUND'));
        $this->assertSame('a government / tax payment', IncomeCleanupService::nonIncomeReason('CANADA REVENUE GST'));
        $this->assertNull(IncomeCleanupService::nonIncomeReason('E-TRANSFER CREDIT JOHN SMITH'));
    }

    // ── book() / reverse() through the real paths (SQLite) ───────────────────

    public function test_booking_links_and_undo_puts_everything_back(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, reference_type, status, account_id) VALUES
                   (4, '2026-03-12', 'income', 500.00, 'E-TRANSFER CREDIT REF 1', 'bank_import', 'cleared', 5),
                   (5, '2026-04-02', 'income', 950.00, 'MOBILE DEPOSIT', 'bank_import', 'cleared', 5),
                   (90, '2026-03-10', 'income', 500.00, 'Invoice payment', 'invoice', 'reconciled', 5),
                   (91, '2026-03-30', 'income', 950.00, 'Invoice payment', 'invoice', 'reconciled', 5)");
        $db->exec("INSERT INTO bank_import_rows (id, transaction_id, amount, match_status) VALUES (1, 4, 500.00, 'unmatched'), (2, 5, 950.00, 'unmatched')");
        $db->exec("INSERT INTO invoices (id, invoice_number, status, total, amount_paid, balance_due, payment_method, paid_at, invoice_date, bill_to_name) VALUES
                   (101, 'INV-0101', 'paid', 500, 500, 0, 'e_transfer', '2026-03-10 12:00:00', '2026-03-01', 'Jones'),
                   (102, 'INV-0102', 'paid', 950, 950, 0, 'cheque', '2026-03-30 12:00:00', '2026-03-15', 'Patel')");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, transaction_id, amount, payment_date, method) VALUES (501, 101, NULL, 500.00, '2026-03-10', 'e_transfer')");
        $svc = new IncomeCleanupService($db);

        $p = $svc->proposal(2026);
        $sig = [];
        foreach ($p['lines'] as $l) $sig[$l['id']] = $l['signature'];
        $this->assertEqualsWithDelta(500 + 950 + 500 + 950, $p['income']['total']['before'], 0.001, 'both deposits counted next to their invoices');

        $r = $svc->book($sig, 7);
        $this->assertCount(2, $r['booked'], json_encode($r['failed']));
        $this->assertEqualsWithDelta(1450.00, $r['income_removed'], 0.001);
        $row = fn($id) => $db->query("SELECT type, status, matched_invoice_id FROM accounting_transactions WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['type' => 'transfer', 'status' => 'reconciled', 'matched_invoice_id' => 101], $this->ints($row(4)));
        $this->assertSame(['type' => 'transfer', 'status' => 'reconciled', 'matched_invoice_id' => 102], $this->ints($row(5)), 'claimed so the journal sync skips it');
        $this->assertSame(4, (int)$db->query("SELECT transaction_id FROM invoice_payment_allocations WHERE id = 501")->fetchColumn());
        $this->assertSame('paid', $db->query("SELECT status FROM invoices WHERE id = 102")->fetchColumn(), 'invoice untouched — never paid twice');
        $this->assertEqualsWithDelta(950.0, (float)$db->query("SELECT amount_paid FROM invoices WHERE id = 102")->fetchColumn(), 0.001);
        $this->assertEqualsWithDelta(1450.00, $svc->proposal(2026)['income']['total']['before'], 0.001, 'each payment counts once now');

        // Approving the same lines again does nothing.
        $again = $svc->book($sig, 7);
        $this->assertCount(0, $again['booked']);
        $this->assertCount(2, $again['failed']);

        foreach ($db->query("SELECT id FROM income_cleanup_log WHERE status = 'booked'")->fetchAll(PDO::FETCH_COLUMN) as $logId) {
            $u = $svc->reverse((int)$logId, 7);
            $this->assertTrue($u['ok'], $u['message']);
        }
        $this->assertSame(['type' => 'income', 'status' => 'cleared', 'matched_invoice_id' => null], $this->ints($row(4)));
        $this->assertSame(['type' => 'income', 'status' => 'cleared', 'matched_invoice_id' => null], $this->ints($row(5)));
        $this->assertNull($db->query("SELECT transaction_id FROM invoice_payment_allocations WHERE id = 501")->fetchColumn() ?: null);
        $this->assertEqualsWithDelta(2900.00, $svc->proposal(2026)['income']['total']['before'], 0.001, 'undo restores the before state');
    }

    public function test_a_locked_month_is_refused_and_nothing_changes(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, reference_type, status, account_id) VALUES
                   (5, '2026-04-02', 'income', 950.00, 'MOBILE DEPOSIT', 'bank_import', 'cleared', 5)");
        $db->exec("INSERT INTO invoices (id, invoice_number, status, total, amount_paid, balance_due, payment_method, paid_at, invoice_date, bill_to_name) VALUES
                   (102, 'INV-0102', 'paid', 950, 950, 0, 'cheque', '2026-03-30 12:00:00', '2026-03-15', 'Patel')");
        $svc = new IncomeCleanupService($db);
        $line = $svc->proposal(2026)['lines'][0];
        $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2026, 4, 'locked')");

        $r = $svc->book([5 => $line['signature']], 7);
        $this->assertCount(0, $r['booked']);
        $this->assertStringContainsString('locked', $r['failed'][0]['message']);
        $this->assertSame('income', $db->query("SELECT type FROM accounting_transactions WHERE id = 5")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM income_cleanup_log")->fetchColumn());
    }

    public function test_an_invoice_paid_since_tim_looked_is_not_paid_again(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, reference_type, status, account_id) VALUES
                   (7, '2026-05-20', 'income', 777.00, 'BRANCH DEPOSIT', 'bank_import', 'cleared', 5)");
        $db->exec("INSERT INTO invoices (id, invoice_number, status, total, amount_paid, balance_due, payment_method, paid_at, invoice_date, bill_to_name) VALUES
                   (110, 'INV-0110', 'sent', 777, 0, 777, NULL, NULL, '2026-05-01', 'Garcia')");
        $svc = new IncomeCleanupService($db);
        $line = $svc->proposal(2026)['lines'][0];
        $this->assertSame('record_payment', $line['action']);

        $db->exec("UPDATE invoices SET status = 'paid', amount_paid = 777, balance_due = 0, payment_method = 'cash', paid_at = '2026-05-21 12:00:00' WHERE id = 110");
        $r = $svc->book([7 => $line['signature']], 7);
        $this->assertCount(0, $r['booked'], 'the proposal changed (now "already recorded"): refused, Tim looks again');
        $this->assertStringContainsString('changed', $r['failed'][0]['message']);
        $this->assertEqualsWithDelta(777.0, (float)$db->query("SELECT amount_paid FROM invoices WHERE id = 110")->fetchColumn(), 0.001);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM invoice_payment_allocations")->fetchColumn());
    }

    public function test_skip_and_unskip(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, reference_type, status, account_id) VALUES
                   (9, '2026-06-20', 'income', 1111.11, 'E-TRANSFER CREDIT', 'bank_import', 'cleared', 5)");
        $svc = new IncomeCleanupService($db);
        $this->assertTrue($svc->skip(9, 7, 'Jobber invoice paid late')['ok']);
        $l = $svc->proposal(2026)['lines'][0];
        $this->assertTrue($l['skipped']);
        $this->assertSame('Jobber invoice paid late', $l['skip_note']);
        $svc->unskip(9, 7);
        $this->assertFalse($svc->proposal(2026)['lines'][0]['skipped']);
    }

    public function test_journal_check_finds_a_tied_deposit_posted_as_money_out(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, reference_type, status, account_id, matched_invoice_id) VALUES
                   (30, '2026-07-01', 'transfer', 804.04, 'E-TRANSFER CREDIT', 'bank_import', 'reconciled', 5, NULL),
                   (31, '2026-07-02', 'transfer', 100.00, 'E-TRANSFER CREDIT', 'bank_import', 'reconciled', 5, 400)");
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, transaction_id, amount, payment_date, method) VALUES
                   (1, 360, 30, 402.02, '2026-07-01', 'e_transfer'), (2, 419, 30, 402.02, '2026-07-01', 'e_transfer'), (3, 400, 31, 100, '2026-07-02', 'e_transfer')");
        $db->exec("INSERT INTO journal_entries (id, entry_date, source_type, source_id, status) VALUES (1, '2026-07-01', 'bank_import', 30, 'posted')");
        $j = (new IncomeCleanupService($db))->journalCheck();
        $this->assertSame(1, $j['count']);
        $this->assertEqualsWithDelta(804.04, $j['total'], 0.001);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function deposits(): array
    {
        return [
            $this->dep(1, '2026-01-16', 2200.00, 'TFR-FR 6123456 SAVINGS'),
            $this->dep(2, '2026-01-20', 500.00, 'E-TRANSFER CREDIT JOHN SMITH'),
            $this->dep(3, '2026-03-05', 1234.56, 'STRIPE TRANSFER'),
            $this->dep(4, '2026-03-12', 500.00, 'E-TRANSFER CREDIT REF 1 TIMATMOWOLOGY'),
            $this->dep(5, '2026-04-02', 950.00, 'MOBILE DEPOSIT'),
            $this->dep(6, '2026-04-20', 804.04, 'E-TRANSFER CREDIT DORSET'),
            $this->dep(7, '2026-05-20', 777.00, 'BRANCH DEPOSIT'),
            $this->dep(8, '2026-06-02', 399.99, 'E-TRANSFER CREDIT'),
            $this->dep(9, '2026-06-20', 1111.11, 'E-TRANSFER CREDIT'),
        ];
    }

    private function payments(): array
    {
        return [['id' => 501, 'invoice_id' => 101, 'invoice_number' => 'INV-0101', 'amount' => 500.00, 'payment_date' => '2026-03-10', 'method' => 'e_transfer',
                 'reference' => null, 'payer' => 'Jones', 'etransfer_notification_id' => 77, 'email_amount' => 500.00, 'email_date' => '2026-03-10', 'sender_name' => 'Jones']];
    }

    private function invoices(): array
    {
        return [
            $this->inv(101, 'INV-0101', 'paid', 500, 0, '2026-03-10', 'Jones', ['alloc_count' => 1]),
            $this->inv(102, 'INV-0102', 'paid', 950, 0, '2026-03-30', 'Patel'),
            $this->inv(360, 'INV-0360', 'paid', 402.02, 0, '2026-04-10', 'Dorset Strata'),
            $this->inv(419, 'INV-0419', 'paid', 402.02, 0, '2026-04-15', 'Dorset Strata'),
            $this->inv(110, 'INV-0110', 'sent', 0, 777.00, null, 'Garcia', ['invoice_date' => '2026-05-01']),
            $this->inv(120, 'INV-0120', 'paid', 399.99, 0, '2026-06-01', 'Chen', ['payment_method' => 'stripe', 'stripe_paid' => 1]),
            $this->inv(130, 'INV-0130', 'sent', 0, 1100.00, null, 'Nguyen', ['invoice_date' => '2026-06-01']),
        ];
    }

    private function dep(int $id, string $date, float $amount, string $desc): array
    {
        return ['id' => $id, 'transaction_date' => $date, 'amount' => $amount, 'description' => $desc, 'allocated' => 0, 'gst_amount' => 0];
    }

    private function inv(int $id, string $no, string $status, float $paid, float $balance, ?string $paidOn, string $payer, array $extra = []): array
    {
        return $extra + ['id' => $id, 'invoice_number' => $no, 'status' => $status, 'total' => $paid + $balance, 'amount_paid' => $paid,
                         'balance_due' => $balance, 'payment_method' => $status === 'paid' ? 'cheque' : null,
                         'paid_at' => $paidOn ? $paidOn . ' 12:00:00' : null, 'updated_at' => $paidOn ? $paidOn . ' 12:00:00' : '2026-05-01 00:00:00',
                         'invoice_date' => $extra['invoice_date'] ?? ($paidOn ?: '2026-05-01'), 'stripe_charge_id' => null, 'payer' => $payer,
                         'alloc_count' => 0, 'alloc_deposit_id' => null, 'claimed_by' => null, 'stripe_paid' => 0];
    }

    private function byId(array $lines): array
    {
        $o = [];
        foreach ($lines as $l) $o[$l['id']] = $l;
        return $o;
    }

    private function ints(array $r): array
    {
        $r['matched_invoice_id'] = $r['matched_invoice_id'] !== null ? (int)$r['matched_invoice_id'] : null;
        return $r;
    }

    private function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = static fn() => '2026-10-07 09:00:00';
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, gst_amount REAL DEFAULT 0,
                   pst_amount REAL DEFAULT 0, description TEXT, account_id INTEGER, reference_type TEXT, reference_id INTEGER, status TEXT DEFAULT 'cleared',
                   matched_invoice_id INTEGER, matched_expense_id INTEGER, match_confidence INTEGER, matched_at TEXT, matched_by TEXT, notes TEXT, payment_reference TEXT)");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY, transaction_id INTEGER, amount REAL, match_status TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, total REAL, amount_paid REAL, balance_due REAL,
                   payment_method TEXT, paid_at TEXT, updated_at TEXT, invoice_date TEXT, stripe_charge_id TEXT, bill_to_name TEXT,
                   company_id INTEGER, contact_id INTEGER, property_id INTEGER)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INTEGER, transaction_id INTEGER, etransfer_notification_id INTEGER,
                   amount REAL, payment_date TEXT, method TEXT, reference TEXT, created_by INTEGER)");
        $db->exec("CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER, month INTEGER, status TEXT)");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_date TEXT, memo TEXT, source_type TEXT, source_id INTEGER,
                   status TEXT, reversed_by_entry_id INTEGER)");
        $db->exec("CREATE TABLE income_cleanup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, bucket TEXT, action TEXT, amount REAL,
                   invoice_ids TEXT, invoice_numbers TEXT, allocation_ids TEXT, before_row TEXT, detail TEXT, status TEXT, note TEXT,
                   booked_by INTEGER, booked_at TEXT, reversed_by INTEGER, reversed_at TEXT)");
        return $db;
    }
}
