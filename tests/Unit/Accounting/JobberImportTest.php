<?php
/**
 * Jobber import (migration 1239): the three CSV exports read by their headers, clients matched to
 * the CRM, history stored, and the 2026 money booked once — revenue for 2026-issued invoices,
 * deposits against the Jobber payments they carried (fees to 6800, 2025 receivable not 2026
 * income), never anything for 2017–2025, and every approval undoable.
 * Fixture rows are invented (no real client data).
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class JobberImportTest extends TestCase
{
    private const INVOICES = "\xEF\xBB\xBFInvoice #,Client name,Client email,Client phone,Service street,Service city,Service province,Service ZIP,Issued date,Due date,Job #s,Status,Total ($),Balance ($)\n"
        . "9001,Alice Example,alice@example.test,(604) 555-0101,12 West 1st Avenue,Vancouver,British Columbia,V5Z 1A1,\"Nov 20, 2025\",\"Dec 04, 2025\",101,Paid,210.0,0.0\n"
        . "9002,Alice Example,alice@example.test,(604) 555-0101,12 West 1st Avenue,Vancouver,Bc,V5Z 1A1,\"Jan 15, 2026\",\"Jan 29, 2026\",\"101, 102\",Paid,105.0,0.0\n"
        . "9003,VR9999 C/o Sample Property Mgmt Ltd,pm@sample.test,(604) 555-0199,500 Main Street,Vancouver,BC,V6A 1A1,\"Feb 02, 2026\",\"Feb 16, 2026\",103,Paid,315.0,0.0\n"
        . "9004,Bob Newcomer,bob@new.test,(778) 555-0102,77 Oak Rd,Burnaby,B.C.,V5H 1B1,\"Feb 10, 2026\",\"Feb 24, 2026\",104,Paid,52.5,0.0\n"
        . "9005,Carol Card,carol@other.test,(604) 555-0103,9 Pine Dr,Vancouver,BC,V6K 1C1,\"Mar 01, 2026\",\"Mar 15, 2026\",105,Paid,100.8,0.0\n"
        . "9006,Dan Late,dan@example.test,(604) 555-0104,1 Elm St,Vancouver,BC,V6K 1D1,\"Dec 01, 2025\",\"Dec 15, 2025\",106,Past Due,420.0,420.0\n"
        . "9007,Old Client,,,,,-,,\"May 24, 2017\",\"Jun 07, 2017\",1,Bad Debt,99.0,99.0\n"
        . "9008,Draft Person,,,,,,,-,-,107,Draft,53.55,53.55\n";

    private const TRANSACTIONS = "Totals\n\nTotal Transactions,7\nTotal Transaction Value,-312.30\n\n"
        . "Client name,Date,Type,Total $,Tip $,Note,Cheque #,Invoice #,Method,Transaction ID,Transaction #,Confirmation #,Job #,Postal code,Paid - Tax $,Refunded on\n"
        . "Alice Example,2026-01-15,Invoice,105.00,\"\",\"\",\"\",9002,\"\",\"\",\"\",\"\",101,V5Z 1A1,100.00,\"\"\n"
        . "VR9999 C/o Sample Property Mgmt Ltd,2026-02-02,Invoice,315.00,\"\",\"\",\"\",9003,\"\",\"\",\"\",\"\",103,V6A 1A1,300.00,\"\"\n"
        . "Alice Example,2026-01-20,Payment,-315.00,\"\",Payment applied to Invoice #9001 and Invoice #9002,\"\",\"9001, 9002\",e-Transfer,\"\",\"\",20260120111122223333,\"\",V5Z 1A1,-300.00,\"\"\n"
        . "VR9999 C/o Sample Property Mgmt Ltd,2026-02-20,Payment,-315.00,\"\",Payment applied to Invoice #9003,55,9003,Cheque,\"\",\"\",\"\",103,V6A 1A1,-300.00,\"\"\n"
        . "Bob Newcomer,2026-02-21,Payment,\"-52.50\",\"\",Payment applied to Invoice #9004,56,9004,Cheque,\"\",\"\",\"\",104,V5H 1B1,-50.00,\"\"\n"
        . "Carol Card,2026-03-02,Payment,-100.80,\"\",Payment applied to Invoice #9005,\"\",9005,Jobber Payments,ch_test1,\"\",\"\",105,V6K 1C1,-96.00,\"\"\n"
        . "Erin Prepay,2026-03-11,Deposit,-210.00,\"\",Payment applied to Estimate #973,\"\",\"\",Jobber Payments,ch_test2,\"\",\"\",\"\",V6S 1C3,-200.00,\"\"\n";

    private const CARD = "Client name,Date,Time,Type,Paid with,Paid through,Total $,Tip $,Fee $,Note,Card ending #,Card type,Invoice #,Quote #,Payout #\n"
        . "Carol Card,2026-03-02,10:00,Payment,Credit card,Client Hub,-100.80,\"\",3.22,Payment applied to Invoice #9005,1111,Visa,9005,\"\",po_A\n"
        . "Erin Prepay,2026-03-11,11:00,Deposit,Credit card,Jobber online,-210.00,\"\",6.39,Payment applied to Estimate #973,2222,Visa,\"\",973,po_B\n"
        . "Report totals:,,,,,,\"-\$310.80\",\$0.00,\$9.61,,,,,,\n";

    // ── CSV (pure) ──────────────────────────────────────────────────────────

    public function testEachExportIsRecognisedByItsHeaders(): void
    {
        $p = JobberCsv::parse(self::INVOICES);
        $this->assertSame('invoices', $p['kind']);
        $this->assertCount(8, $p['rows']);
        $map = JobberCsv::guessMapping('invoices', $p['headers']);
        $this->assertSame([], JobberCsv::missing('invoices', $map));
        $this->assertSame('Total ($)', $p['headers'][$map['total']]);
        $this->assertSame('Job #s', $p['headers'][$map['job_numbers']]);
        $this->assertNull($map['subtotal']);

        $t = JobberCsv::parse(self::TRANSACTIONS);
        $this->assertSame('transactions', $t['kind']);
        $this->assertCount(7, $t['rows'], 'the Totals preamble is skipped');
        $tm = JobberCsv::guessMapping('transactions', $t['headers']);
        $this->assertSame('Paid - Tax $', $t['headers'][$tm['ex_tax']]);
        $this->assertSame('Transaction ID', $t['headers'][$tm['charge_id']]);
        $this->assertSame('Transaction #', $t['headers'][$tm['transaction_no']]);

        $c = JobberCsv::parse(self::CARD);
        $this->assertSame('card', $c['kind']);
        $this->assertCount(2, $c['rows'], 'the Report totals row is dropped');

        // Tim can re-point a field (and blank one).
        $o = JobberCsv::guessMapping('invoices', $p['headers'], ['due_date' => 'Issued date', 'job_numbers' => '']);
        $this->assertSame('Issued date', $p['headers'][$o['due_date']]);
        $this->assertNull($o['job_numbers']);
        $this->assertNull(JobberCsv::detectKind(['Name', 'Amount']));
    }

    public function testValuesAreNormalised(): void
    {
        $this->assertSame(-1721.66, JobberCsv::money('"-1,721.66"'));
        $this->assertSame(-15224.75, JobberCsv::money('-$15,224.75'));
        $this->assertSame(-12.0, JobberCsv::money('(12.00)'));
        $this->assertNull(JobberCsv::money('-'));
        $this->assertSame('2026-03-01', JobberCsv::date('Mar 01, 2026'));
        $this->assertSame('2026-03-01', JobberCsv::date('2026-03-01'));
        $this->assertSame('2026-03-01', JobberCsv::date('03/01/2026'));
        $this->assertNull(JobberCsv::date('-'));
        $this->assertSame('bad_debt', JobberCsv::status('Bad Debt'));
        $this->assertSame('past_due', JobberCsv::status('Past Due'));
        $this->assertSame('paid', JobberCsv::status('Paid'));
        $this->assertSame('BC', JobberCsv::province('British Columbia (BC)'));
        $this->assertSame('BC', JobberCsv::province('B.C.'));
        $this->assertSame(['11830', '11786'], JobberCsv::invoiceNumbers('11830, 11786'));
        $this->assertSame([100.0, 5.0], JobberCsv::splitGst(105.0));
        $p = JobberCsv::parse(self::INVOICES);
        $row = JobberCsv::invoiceRow($p['rows'][1], JobberCsv::guessMapping('invoices', $p['headers']));
        $this->assertSame('computed', $row['tax_source']);
        $this->assertSame(5.0, $row['tax']);
        $this->assertSame('101, 102', $row['job_numbers']);
    }

    // ── matching (pure) ─────────────────────────────────────────────────────

    public function testClientsMatchByAddressThenEmailPhoneName(): void
    {
        $ix = JobberImportService::buildIndex(self::contacts(), self::companies(), self::properties());
        $alice = JobberImportService::matchClient($ix, ['client_name' => 'Alice Example', 'client_email' => 'alice@example.test', 'service_street' => '12 West 1st Avenue']);
        $this->assertSame(['contact_id' => 1, 'company_id' => null, 'property_id' => 1, 'how' => 'address'], $alice);
        $strata = JobberImportService::matchClient($ix, ['client_name' => 'VR9999 C/o Sample Property Mgmt Ltd', 'client_email' => 'pm@sample.test', 'service_street' => '500 Main Street']);
        $this->assertSame(2, $strata['property_id']);
        $this->assertSame(1, $strata['company_id']);
        $this->assertSame(3, $strata['contact_id'], 'the company\'s billing contact');
        $this->assertSame('phone', JobberImportService::matchClient($ix, ['client_name' => 'Carol C', 'client_phone' => '604.555.0103'])['how']);
        $this->assertSame('none', JobberImportService::matchClient($ix, ['client_name' => 'Nobody', 'service_street' => '1 Nowhere Lane'])['how']);
        $this->assertSame('998 w 19th ave', JobberImportService::normAddress('998 West 19th Avenue'));
        $this->assertSame(['9001' => 210.0, '9002' => 105.0], JobberImportService::allocate(315.0, [['9001', 210.0], ['9002', 105.0]]));
    }

    public function testUnitsAndMatchingByPayment(): void
    {
        $pay = [
            ['id' => 1, 'payment_date' => '2026-01-20', 'amount' => 315.0, 'fee' => 0, 'method' => 'e-Transfer', 'confirmation_no' => '20260120111122223333', 'client_name' => 'A'],
            ['id' => 2, 'payment_date' => '2026-02-20', 'amount' => 315.0, 'fee' => 0, 'method' => 'Cheque', 'cheque_no' => '55', 'client_name' => 'B'],
            ['id' => 3, 'payment_date' => '2026-02-21', 'amount' => 52.5, 'fee' => 0, 'method' => 'Cheque', 'cheque_no' => '56', 'client_name' => 'C'],
            ['id' => 4, 'payment_date' => '2026-03-02', 'amount' => 100.8, 'fee' => 3.22, 'method' => 'Jobber Payments', 'payout_id' => 'po_A', 'client_name' => 'D'],
            ['id' => 5, 'payment_date' => '2026-03-02', 'amount' => 40.0, 'fee' => 1.0, 'method' => 'Jobber Payments', 'payout_id' => 'po_A', 'client_name' => 'E'],
            ['id' => 6, 'payment_date' => '2026-04-02', 'amount' => 77.0, 'fee' => 0, 'method' => 'Cash', 'client_name' => 'F'],
        ];
        $units = JobberLedgerService::units($pay);
        $this->assertCount(5, $units, 'two charges in one payout');
        $po = array_values(array_filter($units, fn($u) => $u['key'] === 'po:po_A'))[0];
        $this->assertSame(136.58, $po['net']);
        $dep = [
            ['id' => 10, 'date' => '2026-01-20', 'amount' => 315.0, 'description' => 'e-Transfer credit A Ref 20260120111122223333'],
            ['id' => 11, 'date' => '2026-02-23', 'amount' => 367.5, 'description' => 'Branch deposit'],
            ['id' => 12, 'date' => '2026-03-04', 'amount' => 136.58, 'description' => 'JOBBER PAYOUT'],
            ['id' => 13, 'date' => '2026-04-03', 'amount' => 77.0, 'description' => 'Deposit'],
            ['id' => 14, 'date' => '2026-04-03', 'amount' => 315.0, 'description' => 'e-Transfer credit someone else'],
        ];
        $m = [];
        foreach (JobberLedgerService::match($units, $dep) as $x) $m[$x['deposit_id']] = $x;
        $this->assertSame('strong', $m[10]['confidence']);
        $this->assertCount(2, $m[11]['units'], 'two cheques make one deposit');
        $this->assertSame(['po:po_A'], $m[12]['units']);
        $this->assertSame('weak', $m[13]['confidence']);
        $this->assertArrayNotHasKey(14, $m, 'an amount alone, a different month: no match');
    }

    // ── DB: import → revenue → deposits → undo ──────────────────────────────

    public function testImportBookAndUndoWithoutCountingAnythingTwice(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        $imp = new JobberImportService($db);
        $this->assertTrue($imp->ready());

        $pv = $imp->preview(self::INVOICES);
        $this->assertSame('invoices', $pv['kind']);
        $this->assertSame(1, $pv['open']['past_due']['count']);
        $this->assertSame(4, $pv['issued_2026']['count']);
        $this->assertSame(4, $pv['unmatched']['clients']);
        $this->assertSame(2, $pv['unmatched']['recent'], 'Bob (2026) and Dan (owing) — not the 2017 bad debt or the undated draft');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM jobber_invoices")->fetchColumn(), 'preview writes nothing');

        $r = $imp->import(self::INVOICES, [], 'recent', 1, 'Invoices.csv');
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(8, $r['inserted']);
        $this->assertSame(2, $r['created_contacts']);
        $this->assertSame(0, $imp->import(self::INVOICES, [], 'none', 1, 'again')['inserted'], 're-import only updates');
        $this->assertSame('bad_debt', $db->query("SELECT status FROM jobber_invoices WHERE jobber_number = '9007'")->fetchColumn());

        $t = $imp->import(self::TRANSACTIONS, [], 'none', 1, 'Transaction List.csv');
        $this->assertTrue($t['ok'], $t['message']);
        $this->assertSame(5, $t['inserted']);
        $this->assertSame(2, $t['tax_updated']);
        $this->assertSame('15.00', number_format((float)$db->query("SELECT tax FROM jobber_invoices WHERE jobber_number = '9003'")->fetchColumn(), 2));
        $c = $imp->import(self::CARD, [], 'none', 1, 'Jobber Payments.csv');
        $this->assertSame(0, $c['inserted'], 'card rows are the same payments');
        $this->assertSame(2, $c['updated']);
        $this->assertSame('po_B', $db->query("SELECT payout_id FROM jobber_payments WHERE kind = 'deposit'")->fetchColumn());

        $ar = $imp->receivableCheck();
        $this->assertSame(420.0, $ar['still_owing']);
        $this->assertSame(210.0, $ar['paid_in_2026']);
        $this->assertSame(99.0, $ar['bad_debt_excluded']);

        // Bank lines: four Jobber deposits, a 2025 one, an unrelated one.
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id) VALUES
                   (101, '2026-01-20', 'income', 315.00, 15.00, 'e-Transfer credit ALICE Ref 20260120111122223333', 5, 'bank_import', 1),
                   (102, '2026-02-23', 'income', 367.50, 0, 'Branch deposit', 5, 'bank_import', 1),
                   (103, '2026-03-04', 'income', 97.58, 0, 'JOBBER PAYMENTS PAYOUT', 5, 'bank_import', 1),
                   (104, '2026-03-13', 'income', 203.61, 0, 'STRIPE TRANSFER', 5, 'bank_import', 1),
                   (105, '2025-12-30', 'income', 210.00, 0, 'e-Transfer 2025', 5, 'bank_import', 1),
                   (106, '2026-05-01', 'income', 999.00, 0, 'Deposit', 5, 'bank_import', 1)");
        // The bank balance check had already booked 104 as income.
        $bbc = $ledger->postManual(['entry_date' => '2026-03-13', 'source_type' => 'bank_deposit', 'source_id' => 104, 'memo' => 'Jobber-era deposit',
                                    'lines' => [['account_id' => 1, 'debit' => 203.61, 'credit' => 0], ['account_id' => 5, 'debit' => 0, 'credit' => 203.61]]]);

        $jl = new JobberLedgerService($db, $ledger);
        $rep = $jl->report();
        $this->assertTrue($rep['ready']);
        $this->assertSame(['9002', '9003', '9004', '9005'], array_column($rep['revenue']['items'], 'number'), 'only 2026-issued, no drafts');
        $this->assertSame(573.3, $rep['revenue']['total']);
        $byKey = [];
        foreach ($rep['proposals'] as $p) $byKey[$p['key']] = $p;
        $this->assertSame(['tx:101', 'tx:102', 'tx:103', 'tx:104'], array_keys($byKey));
        $this->assertSame(210.0, $byKey['tx:101']['fy2025_part']);
        $this->assertSame(105.0, $byKey['tx:101']['income_after']);
        $this->assertSame(3.22, $byKey['tx:103']['fee']);
        $this->assertFalse($byKey['tx:104']['preselect'], 'prepayment + already booked: Tim decides');
        $this->assertSame($bbc, $byKey['tx:104']['bbc_entry_id']);
        $this->assertSame([106], array_column($rep['unmatched_deposits'], 'id'), 'the 2025 deposit is never offered');

        $rev = $jl->bookRevenue($rep['revenue']['signature'], 1);
        $this->assertTrue($rev['ok'], $rev['message']);
        $this->assertSame(573.3, self::bal($db, 12), 'AR up by the 2026 invoices');
        $this->assertSame(round(546.0 + 203.61, 2), self::bal($db, 5, true), 'revenue net of GST (Jobber\'s split / 5/105) + the earlier income booking of 104');
        $this->assertSame(27.3, self::bal($db, 3, true), 'GST collected');

        $picks = array_map(fn($p) => ['key' => $p['key'], 'signature' => $p['signature']], array_values($byKey));
        $dep = $jl->bookDeposits($picks, 1);
        $this->assertSame(4, $dep['booked'], $dep['message']);
        $this->assertSame(round(315 + 367.5 + 97.58 + 203.61, 2), self::bal($db, 1), 'cash in once each — the earlier income booking of 104 reversed');
        $this->assertSame(9.61, self::bal($db, 30), 'fees to 6800');
        $this->assertSame(210.0, self::bal($db, 31, true), 'prepayment held as a customer deposit');
        $this->assertSame(round(573.3 - 315 - 367.5 - 100.8, 2), self::bal($db, 12), 'AR: 2026 invoices paid; 9001 (2025) reduces the opening receivable');
        $this->assertSame(546.0, self::bal($db, 5, true), 'revenue not doubled by the earlier income booking');
        $this->assertNotNull($db->query("SELECT reversed_by_entry_id FROM journal_entries WHERE id = {$bbc}")->fetchColumn());
        $tx = $db->query("SELECT type, amount, gst_amount FROM accounting_transactions WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['income', 105.0, 5.0], [$tx['type'], (float)$tx['amount'], (float)$tx['gst_amount']], 'the FY2025 part is not 2026 income');
        $this->assertSame('income', $db->query("SELECT type FROM accounting_transactions WHERE id = 102")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'jobber_invoice' AND source_id IN
                                              (SELECT id FROM jobber_invoices WHERE issued_date < '2026-01-01')")->fetchColumn(), '2017–2025 never posted');

        // The nightly bank sync and the bank balance check leave them alone.
        (new LedgerSyncService($db, $ledger))->syncBankImports();
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_import'")->fetchColumn());
        $this->assertSame([], array_column((new BankBalanceCheckService($db, $ledger))->jobberItems()['post'], 'key'));
        $this->assertSame([], $jl->report()['proposals']);

        // A deposit with no Jobber invoice, and a link by hand that doesn't add up.
        $this->assertFalse($jl->linkManual(106, ['9002'], 1)['ok']);
        $this->assertTrue($jl->bookAsIncome(106, 1)['ok']);

        // Undo the deposits: cash out again, the bank line restored, the earlier booking back.
        $undo = $jl->undo($dep['batch_id'], 1);
        $this->assertTrue($undo['ok'], $undo['message']);
        $this->assertSame(round(999 + 203.61, 2), self::bal($db, 1), '106 booked as income + 104 re-posted as before');
        $this->assertSame(315.0, (float)$db->query("SELECT amount FROM accounting_transactions WHERE id = 101")->fetchColumn());
        $this->assertCount(4, (new JobberLedgerService($db, $ledger))->report()['proposals'], 'back on the list');
    }

    public function testRecreatingA2025InvoiceTakesItsRevenueBackOut(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        (new JobberImportService($db))->import(self::INVOICES, [], 'none', 1, 'Invoices.csv');
        $db->exec("INSERT INTO invoices (id, invoice_number, total, subtotal, tax_amount, amount_paid, status, issue_date, created_at, contact_id)
                   VALUES (50, 'INV-2026-0100', 420, 400, 20, 0, 'sent', '2026-10-01', '2026-10-01', NULL)");
        $jid = (int)$db->query("SELECT id FROM jobber_invoices WHERE jobber_number = '9006'")->fetchColumn();
        $jl = new JobberLedgerService($db, $ledger);
        $r = $jl->linkCrmInvoice($jid, 'INV-2026-0100', 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(-420.0, self::bal($db, 12));
        $this->assertSame(400.0, self::bal($db, 5));
        $this->assertSame([50], JobberLedgerService::carryoverInvoiceIds($db), 'its payment stays out of 2026 income');
        $this->assertFalse($jl->linkCrmInvoice($jid, 'INV-2026-0100', 1)['ok'], 'linked once');
        $open = (new JobberImportService($db))->openInvoices();
        $this->assertSame('INV-2026-0100', $open['past_due'][0]['crm_invoice']);
        $this->assertTrue($jl->undo($jl->batches()[0]['batch_id'], 1)['ok']);
        $this->assertSame(0.0, self::bal($db, 12));
        $this->assertSame([], JobberLedgerService::carryoverInvoiceIds($db));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private static function contacts(): array
    {
        return [['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Example', 'email' => 'alice@example.test', 'phone' => '604-555-0101', 'mobile' => null],
                ['id' => 2, 'first_name' => 'Carol', 'last_name' => 'Card', 'email' => 'carol@crm.test', 'phone' => '6045550103', 'mobile' => null],
                ['id' => 3, 'first_name' => 'Pat', 'last_name' => 'Manager', 'email' => 'pat@sample.test', 'phone' => null, 'mobile' => null]];
    }

    private static function companies(): array
    {
        return [['id' => 1, 'company_name' => 'Sample Property Mgmt Ltd', 'billing_email' => null, 'billing_phone' => null, 'primary_contact_id' => null, 'billing_contact_id' => 3]];
    }

    private static function properties(): array
    {
        return [['id' => 1, 'address' => '12 W 1st Ave', 'site_contact_id' => 1, 'company_id' => null],
                ['id' => 2, 'address' => '500 Main St', 'site_contact_id' => null, 'company_id' => 1]];
    }

    private static function bal(PDO $db, int $accountId, bool $credit = false): float
    {
        $v = (float)$db->query("SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) FROM journal_lines jl WHERE jl.account_id = {$accountId}")->fetchColumn();
        return round($credit ? -$v : $v, 2);
    }

    private static function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BankLineMoveServiceTest::schema($db);
        $db->exec("ALTER TABLE accounting_transactions ADD COLUMN notes TEXT");
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (12, '1100', 'Accounts Receivable', 'asset'),
                   (30, '6800', 'Bank Charges & Fees', 'expense'), (31, '2160', 'Customer Deposits', 'liability')");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT, last_name TEXT, email TEXT, phone TEXT, mobile TEXT, notes TEXT, is_active INTEGER DEFAULT 1)");
        foreach (self::contacts() as $c) {
            $db->prepare("INSERT INTO contacts (id, first_name, last_name, email, phone) VALUES (?, ?, ?, ?, ?)")->execute([$c['id'], $c['first_name'], $c['last_name'], $c['email'], $c['phone']]);
        }
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, billing_email TEXT, billing_phone TEXT, primary_contact_id INTEGER, billing_contact_id INTEGER)");
        $db->exec("INSERT INTO companies (id, company_name, billing_contact_id) VALUES (1, 'Sample Property Mgmt Ltd', 3)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, site_contact_id INTEGER)");
        $db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (1, '12 W 1st Ave', 1), (2, '500 Main St', NULL)");
        $db->exec("CREATE TABLE company_properties (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, property_id INTEGER, is_primary INTEGER DEFAULT 0)");
        $db->exec("INSERT INTO company_properties (company_id, property_id) VALUES (1, 2)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, total REAL, subtotal REAL DEFAULT 0, tax_amount REAL DEFAULT 0,
                   amount_paid REAL DEFAULT 0, status TEXT, paid_at TEXT, created_at TEXT, issue_date TEXT, contact_id INTEGER, plan_id INTEGER)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INTEGER, transaction_id INTEGER, amount REAL, payment_date TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE income_cleanup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, status TEXT)");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY, session_id INTEGER, transaction_date TEXT, type TEXT, amount REAL, raw_amount REAL,
                   is_duplicate INTEGER DEFAULT 0, raw_row TEXT, transaction_id INTEGER, match_status TEXT)");
        $db->exec("CREATE TABLE jobber_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, jobber_number TEXT UNIQUE, contact_id INTEGER, company_id INTEGER, property_id INTEGER,
                   match_how TEXT, client_name TEXT, client_email TEXT, client_phone TEXT, service_street TEXT, service_city TEXT, service_province TEXT, service_postal TEXT,
                   subject TEXT, job_numbers TEXT, issued_date TEXT, due_date TEXT, status TEXT, status_raw TEXT, subtotal REAL, tax REAL, tax_source TEXT, total REAL,
                   balance REAL, paid_date TEXT, line_items TEXT, crm_invoice_id INTEGER, batch_id TEXT, imported_by INTEGER, imported_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE jobber_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_key TEXT UNIQUE, kind TEXT, client_name TEXT, contact_id INTEGER,
                   payment_date TEXT, amount REAL, amount_ex_tax REAL, tip REAL DEFAULT 0, method TEXT, cheque_no TEXT, stripe_charge_id TEXT, transaction_no TEXT,
                   confirmation_no TEXT, invoice_numbers TEXT, quote_number TEXT, payout_id TEXT, fee REAL DEFAULT 0, job_number TEXT, postal_code TEXT, note TEXT,
                   refunded_on TEXT, batch_id TEXT, imported_at TEXT)");
        $db->exec("CREATE TABLE jobber_import_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT, filename TEXT, row_count INTEGER, inserted INTEGER,
                   updated INTEGER, created_contacts INTEGER, mapping TEXT, created_by INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE jobber_ledger_log (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT, op TEXT, jobber_invoice_id INTEGER, jobber_payment_id INTEGER,
                   transaction_id INTEGER, entry_id INTEGER, target_entry_id INTEGER, amount REAL, entry_date TEXT, tx_before_type TEXT, tx_before_amount REAL,
                   tx_before_gst REAL, tx_before_status TEXT, created_by INTEGER, created_at TEXT, undone_at TEXT, undone_by INTEGER, undo_entry_id INTEGER)");
        return $db;
    }
}
