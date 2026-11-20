<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Accountant's Ledger step 2: the comparative trial balance (balances; its adjusted column IS the
 * existing trial balance), the general ledger (opening + lines = closing), the exports (XLSX and CSV
 * parse back, numbers stay numbers) and the QuickBooks Online journal CSV (columns, names, balance).
 */
class LedgerStep2Test extends TestCase
{
    /** A small ledger: FY2025 activity, the FY opening that brings it to "filed", 2026 activity and an AJE. */
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, sub_type TEXT, normal_balance TEXT,
                   parent_id INTEGER, display_order INTEGER DEFAULT 0, gifi_code TEXT, lead_group TEXT)");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_date TEXT, memo TEXT, source_type TEXT, source_id INTEGER,
                   entry_kind TEXT DEFAULT 'system', status TEXT DEFAULT 'posted', is_adjusting INTEGER DEFAULT 0, reversed_by_entry_id INTEGER,
                   created_by INTEGER, proposed_by TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, entry_id INTEGER, account_id INTEGER, debit REAL DEFAULT 0, credit REAL DEFAULT 0,
                   gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0, description TEXT, contact_id INTEGER, vendor_id INTEGER)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, bill_to_name TEXT)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INTEGER, vendor_name_raw TEXT)");
        $db->exec("INSERT INTO invoices VALUES (7, 'Quay Pacific'), (8, 'Macdonald Realty')");
        $db->exec("INSERT INTO vendors VALUES (3, 'Esso')");
        $db->exec("INSERT INTO expenses VALUES (40, 3, NULL), (41, NULL, 'Home Depot')");
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type, sub_type, normal_balance, parent_id, display_order, gifi_code, lead_group) VALUES
            (1, '1010', 'Chequing', 'asset', 'bank', 'debit', NULL, 10, '1002', 'A'),
            (2, '1100', 'Accounts Receivable', 'asset', 'receivable', 'debit', NULL, 20, '1062', 'B'),
            (3, '2100', 'Accounts Payable', 'liability', 'payable', 'credit', NULL, 30, '2620', 'AA'),
            (4, '2200', 'GST/HST Collected', 'liability', 'tax_payable', 'credit', NULL, 40, '2680', 'BB'),
            (5, '3200', 'Retained Earnings', 'equity', NULL, 'credit', NULL, 50, '3600', 'RE'),
            (6, '4050', 'Contract Income', 'revenue', NULL, 'credit', NULL, 60, '8000', '20'),
            (7, '4950', 'Discounts', 'revenue', NULL, 'debit', 6, 61, '8000', '20'),
            (8, '6100', 'Fuel', 'expense', NULL, 'debit', NULL, 70, '9281', '40'),
            (9, '1250', 'Income Tax Receivable', 'asset', 'receivable', 'debit', NULL, 25, '1066', 'B')");
        $e = $db->prepare("INSERT INTO journal_entries (id, entry_date, memo, source_type, source_id, entry_kind, created_at) VALUES (?,?,?,?,?,?,?)");
        $l = $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, description, vendor_id) VALUES (?,?,?,?,?,?)");
        foreach ([
            // FY2025
            [1, '2025-03-01', 'Invoice 7', 'invoice', 7, 'system', [[2, 1050, 0], [6, 0, 1000], [4, 0, 50]]],
            [2, '2025-04-10', 'Payment', 'invoice', 7, 'system', [[1, 1050, 0], [2, 0, 1050]]],
            [3, '2025-06-01', 'Esso fuel', 'expense', 40, 'system', [[8, 200, 0], [3, 0, 200]]],
            // FY opening (closes FY2025): income to RE, GST to zero → filed
            [4, '2026-01-01', 'FY2026 opening', 'fy_opening', 2025, 'manual', [[6, 1000, 0], [8, 0, 200], [4, 50, 0], [5, 0, 850]]],
            // FY2026
            [5, '2026-02-01', 'Invoice 8', 'invoice', 8, 'system', [[2, 525, 0], [6, 0, 500], [4, 0, 25]]],
            [6, '2026-02-15', 'Discount', 'manual', null, 'manual', [[7, 20, 0], [2, 0, 20]]],
            [7, '2026-03-20', 'Home Depot', 'expense', 41, 'system', [[8, 80, 0], [3, 0, 80]]],
            [8, '2026-03-31', 'AJE-2026-01 accrue fuel', 'manual', null, 'aje', [[8, 30, 0], [3, 0, 30]]],
            [9, '2026-04-02', 'Paid Esso', 'manual', null, 'manual', [[3, 200, 0], [1, 0, 200]]],
        ] as [$id, $d, $memo, $st, $sid, $kind, $lines]) {
            $e->execute([$id, $d, $memo, $st, $sid, $kind, "$d 10:00:00"]);
            foreach ($lines as [$acc, $dr, $cr]) $l->execute([$id, $acc, $dr, $cr, null, $acc === 3 && $id === 9 ? 3 : null]);
        }
        return $db;
    }

    public function test_comparative_trial_balance_balances_and_adjusted_is_the_trial_balance(): void
    {
        $db = $this->db();
        $tb = (new ComparativeTrialBalanceService($db))->build('2026-03-31');
        $this->assertTrue($tb['in_balance']);
        foreach (ComparativeTrialBalanceService::COLUMNS as $k) {
            if (in_array($k, ['py2', 'proposed'], true)) continue;
            $this->assertEqualsWithDelta(0.0, $tb['totals'][$k], 0.001, "$k nets to zero");
        }
        // The adjusted column is exactly the existing trial balance page (ReportingService) at the same date.
        $old = (new ReportingService($db))->getTrialBalance('2026-03-31');
        $byCode = array_column($tb['rows'], null, 'code');
        foreach ($old['accounts'] as $a) {
            $this->assertEqualsWithDelta($a['debit'] - $a['credit'], $byCode[$a['code']]['adjusted'], 0.001, $a['code']);
        }
        $this->assertEqualsWithDelta($old['total_debit'], $tb['debits']['adjusted'], 0.001);

        // AJE column: only the aje entry; unadjusted = adjusted − AJE.
        $this->assertEqualsWithDelta(30.0, $byCode['6100']['aje'], 0.001);
        $this->assertEqualsWithDelta(110.0, $byCode['6100']['adjusted'], 0.001);
        $this->assertEqualsWithDelta(80.0, $byCode['6100']['unadjusted'], 0.001);
        $this->assertNull($byCode['6100']['proposed'], 'proposed stays empty until step 3');
        // PY: FY2025 as filed — income closed into retained earnings, GST cleared, balance sheet kept.
        $this->assertEqualsWithDelta(0.0, $byCode['4050']['py'], 0.001);
        $this->assertEqualsWithDelta(-850.0, $byCode['3200']['py'], 0.001);
        $this->assertEqualsWithDelta(1050.0, $byCode['1010']['py'], 0.001);
        $this->assertEqualsWithDelta(-200.0, $byCode['2100']['py'], 0.001);
        // FY2024 is not in this journal at all.
        $this->assertNull($byCode['1010']['py2']);
        $this->assertFalse($tb['present']['py2']);
        // GIFI + lead carried on each line.
        $this->assertSame(['1002', 'A'], [$byCode['1010']['gifi_code'], $byCode['1010']['lead_group']]);
    }

    public function test_an_unbalanced_column_is_reported(): void
    {
        $out = ComparativeTrialBalanceService::buildRows(
            [['id' => 1, 'code' => '1010', 'name' => 'Bank', 'type' => 'asset'], ['id' => 2, 'code' => '3100', 'name' => 'Capital', 'type' => 'equity']],
            [1 => ['adjusted' => 100, 'aje' => 0, 'py' => 50, 'py2' => 0], 2 => ['adjusted' => -99.99, 'aje' => 0, 'py' => -50, 'py2' => 0]],
            ['py' => true, 'py2' => false]);
        $this->assertFalse($out['in_balance']);
        $this->assertFalse($out['balanced']['adjusted']);
        $this->assertTrue($out['balanced']['py']);
        $this->assertTrue($out['balanced']['py2'], 'a year not in the journal is not "unbalanced"');
    }

    public function test_general_ledger_opening_plus_lines_is_closing(): void
    {
        $db = $this->db();
        $svc = new GeneralLedgerService($db);
        $g = $svc->account('2100', '2026-01-01', '2026-03-31');
        $this->assertEqualsWithDelta(-200.0, $g['opening'], 0.001, 'FY2025 payable carried in');
        $this->assertCount(2, $g['lines']);
        $this->assertEqualsWithDelta(-280.0, $g['lines'][0]['balance'], 0.001, 'running balance');
        $this->assertEqualsWithDelta($g['opening'] + $g['debits'] - $g['credits'], $g['closing'], 0.001);
        $this->assertEqualsWithDelta(-310.0, $g['closing'], 0.001);
        $tb = (new ComparativeTrialBalanceService($db))->build('2026-03-31');
        $this->assertEqualsWithDelta(array_column($tb['rows'], 'adjusted', 'code')['2100'], $g['closing'], 0.001, 'GL closing = TB at the same date');
        $this->assertNull($svc->account('9999', '2026-01-01', '2026-03-31'));
        // Every account with an opening or lines; each closes to its TB figure.
        $all = $svc->allAccounts('2026-01-01', '2026-03-31');
        $adj = array_column($tb['rows'], 'adjusted', 'code');
        foreach ($all as $a) $this->assertEqualsWithDelta($adj[$a['account']['code']] ?? 0.0, $a['closing'], 0.001, $a['account']['code']);
        // The opening entry dated 2026-01-01 is a line in the period, not part of the opening.
        $re = $svc->account('3200', '2026-01-01', '2026-12-31');
        $this->assertEqualsWithDelta(0.0, $re['opening'], 0.001);
        $this->assertEqualsWithDelta(-850.0, $re['closing'], 0.001);
    }

    public function test_exports_open_and_numbers_stay_numbers(): void
    {
        $db = $this->db();
        $tb = (new ComparativeTrialBalanceService($db))->build('2026-03-31');
        $t = ComparativeTrialBalanceService::exportTable($tb);
        $header = LedgerExport::headerRows('Mowology Landscaping', $t['title'], $t['period'], 'open (not locked)', '2026-10-13 15:00:00 PDT');

        // CSV parses back: header rows, column labels, numbers without separators.
        $csv = LedgerExport::csv(LedgerExport::tableRows($t, $header));
        $rows = array_map(static fn($l) => str_getcsv($l, ',', '"', '\\'), explode("\n", trim($csv)));
        $this->assertSame(['Entity', 'Mowology Landscaping'], $rows[0]);
        $this->assertSame(['Lock status', 'open (not locked)'], $rows[3]);
        $this->assertSame('Account', $rows[6][0]);
        $bank = array_values(array_filter($rows, fn($r) => ($r[0] ?? '') === '1010'))[0];
        $this->assertSame('1050', $bank[7], 'adjusted 1010 at Mar 31 (the April payment is after the date), a plain number');
        $this->assertTrue(is_numeric($bank[7]));

        // XLSX parses back with the reader Operations uses; money cells are numbers, dates real dates.
        $path = LedgerExport::tableXlsx($t, $header);
        $cells = XlsxReader::firstSheet((string)file_get_contents($path));
        @unlink($path);
        $this->assertSame('Entity', $cells[1]['A']);
        $this->assertSame('Mowology Landscaping', $cells[1]['B']);
        $this->assertSame('Account', $cells[7]['A']);
        $found = null;
        foreach ($cells as $r) if (($r['A'] ?? null) === '1010') $found = $r;
        $this->assertNotNull($found);
        $this->assertIsNumeric($found['H']);
        $this->assertEqualsWithDelta(1050.0, (float)$found['H'], 0.001);

        $g = (new GeneralLedgerService($db))->account('2100', '2026-01-01', '2026-03-31');
        $gt = GeneralLedgerService::exportTable([$g], '2026-01-01', '2026-03-31');
        $path = LedgerExport::tableXlsx($gt, LedgerExport::headerRows('M', $gt['title'], $gt['period'], 'open', 'now'));
        $cells = XlsxReader::firstSheet((string)file_get_contents($path));
        @unlink($path);
        $line = null;
        foreach ($cells as $r) if (($r['F'] ?? null) === 'Home Depot') $line = $r;
        $this->assertNotNull($line);
        $this->assertSame('2026-03-20', XlsxReader::excelDate($line['C']), 'dates are real Excel dates');
        $this->assertEqualsWithDelta(80.0, (float)$line['H'], 0.001);
        $this->assertEqualsWithDelta(-280.0, (float)$line['I'], 0.001);
    }

    public function test_xlsx_writer_basics(): void
    {
        $this->assertSame('A', XlsxWriter::colLetters(0));
        $this->assertSame('Z', XlsxWriter::colLetters(25));
        $this->assertSame('AA', XlsxWriter::colLetters(26));
        $this->assertSame(45658, XlsxWriter::serial('2025-01-01'));
        $x = new XlsxWriter('Odd: name/with*chars');
        $x->row(['=HYPERLINK("x")', "bad\x01char & <tag>", 12, 3.5, null, '2026-02-30'], XlsxWriter::PLAIN, ['text', 'text', 'int', 'money', 'text', 'date']);
        $cells = XlsxReader::firstSheet((string)file_get_contents($p = $x->save()));
        @unlink($p);
        $this->assertSame('=HYPERLINK("x")', $cells[1]['A'], 'text is a string cell, never a formula');
        $this->assertSame('badchar & <tag>', $cells[1]['B']);
        $this->assertSame(12, (int)$cells[1]['C']);
        $this->assertEqualsWithDelta(3.5, (float)$cells[1]['D'], 0.0001);
        $this->assertSame('2026-02-30', $cells[1]['F'], 'an impossible date stays text');
    }

    public function test_pdf_export_renders(): void
    {
        if (!is_file(__DIR__ . '/../../../public/vendor/autoload.php')) $this->markTestSkipped('mPDF (public/vendor) not present');
        $t = ['title' => 'Balance sheet', 'period' => 'As at 2026-03-31', 'columns' => [['Account', 'text'], ['Name', 'text'], ['Balance', 'money']],
              'rows' => [['1010', 'Chequing', 850.0], ['cells' => ['', 'Total', -12.5], 'bold' => true]]];
        $html = LedgerExport::tableHtml($t, LedgerExport::headerRows('M', 'Balance sheet', 'As at 2026-03-31', 'open', 'now'));
        $this->assertStringContainsString('(12.50)', $html);
        $this->assertStringContainsString('850.00', $html);
        require_once __DIR__ . '/../../../public/vendor/autoload.php';
        $pdf = @LedgerExport::tablePdf($t, LedgerExport::headerRows('M', 'Balance sheet', 'As at 2026-03-31', 'open', 'now'));
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_qbo_journal_csv(): void
    {
        $db = $this->db();
        $lines = (new QboJournalExport($db))->lines('2026-01-01', '2026-03-31');
        $det = QboJournalExport::detailed($lines);
        $this->assertSame(['Journal No.', 'Journal Date', 'Account Name', 'Description', 'Debits', 'Credits', 'Name'], QboJournalExport::COLUMNS);
        foreach (QboJournalExport::journalTotals($det) as $no => [$d, $c]) $this->assertEqualsWithDelta($d, $c, 0.001, "$no balances");
        $inv = array_values(array_filter($det, fn($r) => $r[0] === 'MW-5'));
        $this->assertSame('01/02/2026', $inv[0][1], 'dd/mm/yyyy (QBO Canada)');
        $ar = array_values(array_filter($inv, fn($r) => $r[2] === 'Accounts Receivable'))[0];
        $this->assertSame('Macdonald Realty', $ar[6], 'A/R lines carry the customer');
        $this->assertSame([525.0, null], [$ar[4], $ar[5]]);
        $gst = array_values(array_filter($inv, fn($r) => $r[2] === 'GST/HST Collected'))[0];
        $this->assertSame('', $gst[6], 'no Name off A/R and A/P');
        $disc = array_values(array_filter($det, fn($r) => $r[0] === 'MW-6' && $r[4] !== null))[0];
        $this->assertSame('Contract Income:Discounts', $disc[2], 'sub-account as Parent:Sub');
        $hd = array_values(array_filter($det, fn($r) => $r[0] === 'MW-7' && $r[2] === 'Accounts Payable'))[0];
        $this->assertSame('Home Depot', $hd[6], 'A/P line: the receipt vendor');
        $aje = array_values(array_filter($det, fn($r) => $r[0] === 'MW-8' && $r[2] === 'Accounts Payable'))[0];
        $this->assertSame(QboJournalExport::NO_SUPPLIER, $aje[6], 'an A/P line with no source names a placeholder supplier');
        $this->assertNull(QboJournalExport::partyKind(['type' => 'asset', 'sub_type' => 'receivable', 'name' => 'Income Tax Receivable']));

        $sum = QboJournalExport::summary($lines, 'ymd');
        $tot = QboJournalExport::journalTotals($sum);
        $this->assertSame(['MW-2026-01', 'MW-2026-02', 'MW-2026-03'], array_keys($tot), 'one journal per month');
        foreach ($tot as $no => [$d, $c]) $this->assertEqualsWithDelta($d, $c, 0.001, "$no balances");
        $mar = array_values(array_filter($sum, fn($r) => $r[0] === 'MW-2026-03'));
        $this->assertSame('2026-03-31', $mar[0][1], 'dated the month end');
        $fuel = array_values(array_filter($mar, fn($r) => $r[2] === 'Fuel'))[0];
        $this->assertEqualsWithDelta(110.0, $fuel[4], 0.001, 'net per account for the month');
        $ap = array_values(array_filter($mar, fn($r) => $r[2] === 'Accounts Payable'));
        $this->assertCount(2, $ap, 'A/P split by name in the summary');
    }
}
