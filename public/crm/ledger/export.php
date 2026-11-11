<?php
/**
 * Ledger → one file per click (Accountant's Ledger step 2, 2026-10-13).
 *
 *   ?report=tb       comparative trial balance at as_of            xlsx | csv | pdf (refused when out of balance)
 *   ?report=gl       general ledger from..to, one account or all   xlsx | csv | pdf
 *   ?report=journal  every journal line from..to                   xlsx | csv | pdf (PDF only up to PDF_MAX_LINES)
 *   ?report=aging    A/R and A/P control balances at as_of (aging by invoice comes in step 4)
 *   ?report=bs / is  balance sheet at as_of / income statement from..to
 *   ?report=qbo      QuickBooks Online journal import CSV, &mode=detailed|summary, &datefmt=dmy|mdy|ymd
 *
 * Every file: header rows (entity, report, period, run time, lock status), no merged cells, numbers as
 * numbers. Each file sent is one activity_log 'ledger.export' row (LedgerExport), whoever asked.
 * See: ledger.view (the Ledger's own gate).
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
require_once APP_ROOT . '/Modules/Accounting/Services/ReportingService.php';
require_once APP_ROOT . '/Modules/Ledger/Services/ComparativeTrialBalanceService.php';
require_once APP_ROOT . '/Modules/Ledger/Services/GeneralLedgerService.php';
require_once APP_ROOT . '/Modules/Ledger/Services/QboJournalExport.php';

const PDF_MAX_LINES = 4000;

$db     = getDB();
$fye    = FiscalYear::setting($db);
$report = (string)($_GET['report'] ?? '');
$format = (string)($_GET['format'] ?? 'xlsx');
$asOf   = FiscalYear::cleanDate($_GET['as_of'] ?? null);
$to     = FiscalYear::cleanDate($_GET['to'] ?? null, $asOf);
$from   = FiscalYear::cleanDate($_GET['from'] ?? null, FiscalYear::bounds(FiscalYear::of($to, $fye), $fye)[0]);
if ($from > $to) [$from, $to] = [$to, $from];
$entity = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower(LedgerExport::entity($db)));

/** Stop with a plain message (a refused export is a view, not an export). */
$refuse = static function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
};

if ($report !== 'qbo' && !in_array($format, LedgerExport::FORMATS, true)) $refuse(400, 'Unknown format.');

try {
    switch ($report) {
        case 'tb':
            $tb = (new ComparativeTrialBalanceService($db))->build($asOf);
            if (!$tb['in_balance']) {
                $bad = array_keys(array_filter($tb['balanced'], static fn($ok) => !$ok));
                $refuse(409, 'Export refused: the trial balance does not balance (' . implode(', ', $bad) . '). Open the trial balance to see the difference.');
            }
            LedgerExport::sendTable($db, ComparativeTrialBalanceService::exportTable($tb), $format, "$entity-trial-balance-$asOf", 'tb', ['as_of' => $asOf]);
            break;

        case 'gl':
            $svc = new GeneralLedgerService($db);
            $code = (string)($_GET['account'] ?? '');
            if ($code !== '' && preg_match('/^[A-Za-z0-9\-]{1,20}$/', $code)) {
                $g = $svc->account($code, $from, $to);
                if (!$g) $refuse(404, 'No such account.');
                $ledgers = [$g];
            } else {
                $code = '';
                $ledgers = $svc->allAccounts($from, $to);
            }
            if ($format === 'pdf' && array_sum(array_map(static fn($g) => count($g['lines']), $ledgers)) > PDF_MAX_LINES) {
                $refuse(413, 'Too many lines for a PDF — choose one account or a shorter period, or take the XLSX / CSV.');
            }
            LedgerExport::sendTable($db, GeneralLedgerService::exportTable($ledgers, $from, $to), $format,
                                    "$entity-general-ledger-" . ($code !== '' ? $code . '-' : '') . "$from-$to", 'gl', ['account' => $code ?: 'all', 'from' => $from, 'to' => $to]);
            break;

        case 'journal':
            $f = JournalBrowserService::normalizeFilters(array_merge($_GET, ['from' => $from, 'to' => $to]));
            $svc = new JournalBrowserService($db);
            if ($format === 'csv') {
                // Streamed: no row cap, nothing held in memory.
                $t = ['title' => 'Journal', 'period' => "$from to $to", 'from' => $from, 'to' => $to];
                $fh = LedgerExport::csvStream("$entity-journal-$from-$to.csv", 'journal.csv', array_filter($f, fn($v) => $v !== null));
                foreach (LedgerExport::headerFor($db, $t) as $h) LedgerExport::csvLine($fh, $h);
                LedgerExport::csvLine($fh, ['Entry', 'Date', 'Kind', 'Source type', 'Source id', 'Memo', 'Account', 'Account name', 'GIFI', 'Debit', 'Credit', 'Line description', 'By']);
                $svc->streamLines($f, static function (array $l) use ($fh): void {
                    LedgerExport::csvLine($fh, [(int)$l['id'], $l['entry_date'], $l['display_kind'], $l['source_type'], $l['source_id'] !== null ? (int)$l['source_id'] : null,
                        $l['memo'], $l['code'], $l['account_name'], $l['gifi_code'], (float)$l['debit'], (float)$l['credit'], $l['description'],
                        $l['created_by_name'] ?: ($l['proposed_by'] ?: 'system')]);
                });
                fclose($fh);
                exit;
            }
            $rows = [];
            $svc->streamLines($f, static function (array $l) use (&$rows): void {
                $rows[] = [(int)$l['id'], $l['entry_date'], $l['display_kind'], str_replace('_', ' ', (string)$l['source_type']) . ($l['source_id'] ? ' ' . (int)$l['source_id'] : ''),
                           $l['memo'], $l['code'], $l['account_name'], $l['gifi_code'], (float)$l['debit'] ?: null, (float)$l['credit'] ?: null, $l['description'],
                           $l['created_by_name'] ?: ($l['proposed_by'] ?: 'system')];
            });
            if ($format === 'pdf' && count($rows) > PDF_MAX_LINES) $refuse(413, 'Too many lines for a PDF (' . count($rows) . ') — shorten the period or take the XLSX / CSV.');
            $t = ['title' => 'Journal', 'period' => "$from to $to", 'from' => $from, 'to' => $to, 'landscape' => true, 'rows' => $rows,
                  'columns' => [['Entry', 'int', 8], ['Date', 'date', 11], ['Kind', 'text', 9], ['Source', 'text', 16], ['Memo', 'text', 40],
                                ['Account', 'text', 8], ['Account name', 'text', 24], ['GIFI', 'text', 6], ['Debit', 'money', 13], ['Credit', 'money', 13],
                                ['Line description', 'text', 30], ['By', 'text', 14]]];
            LedgerExport::sendTable($db, $t, $format, "$entity-journal-$from-$to", 'journal', array_filter($f, fn($v) => $v !== null));
            break;

        case 'aging':
            // Step 4 builds aging by invoice and bill (current / 30 / 60 / 90 / 120+). Until then this is the
            // control-account figure the aging must tie to, with the buckets left empty.
            $tb = (new ComparativeTrialBalanceService($db))->build($asOf);
            $rows = [];
            foreach ($tb['rows'] as $r) {
                $kind = QboJournalExport::partyKind($r);
                if ($kind === null) continue;
                $rows[] = [$kind === 'ar' ? 'Receivable' : 'Payable', $r['code'], $r['name'], $kind === 'ar' ? $r['adjusted'] : -$r['adjusted'], null, null, null, null, null, null];
            }
            $t = ['title' => 'Aging (control accounts — stub)', 'period' => "As at $asOf", 'from' => $asOf, 'to' => $asOf, 'rows' => $rows,
                  'columns' => [['Ledger', 'text', 11], ['Account', 'text', 8], ['Account name', 'text', 28], ['Control balance', 'money', 15],
                                ['Current', 'money', 12], ['1-30', 'money', 12], ['31-60', 'money', 12], ['61-90', 'money', 12], ['91-120', 'money', 12], ['120+', 'money', 12]],
                  'notes' => ['Stub: the control-account balances the aging must tie to. Aging by invoice and bill arrives with Ledger step 4.']];
            LedgerExport::sendTable($db, $t, $format, "$entity-aging-$asOf", 'aging', ['as_of' => $asOf]);
            break;

        case 'bs':
            $bs = (new ReportingService($db))->getBalanceSheet($asOf);
            $rows = [];
            foreach (['Assets' => 'assets', 'Liabilities' => 'liabilities', 'Equity' => 'equity'] as $label => $k) {
                $rows[] = ['cells' => [$label, '', null], 'bold' => true];
                foreach ($bs[$k] as $a) $rows[] = [$a['code'], $a['name'], $a['balance']];
                if ($k === 'equity') $rows[] = ['', 'Net income to date (not yet closed)', $bs['net_income']];
                $rows[] = ['cells' => ['', 'Total ' . strtolower($label), $k === 'equity' ? $bs['equity_with_earnings'] : $bs['total_' . ($k === 'assets' ? 'assets' : 'liabilities')]], 'bold' => true];
            }
            $rows[] = ['cells' => ['', 'Liabilities + equity', $bs['liabilities_plus_equity']], 'bold' => true];
            $t = ['title' => 'Balance sheet', 'period' => "As at $asOf", 'from' => FiscalYear::bounds(FiscalYear::of($asOf, $fye), $fye)[0], 'to' => $asOf,
                  'columns' => [['Account', 'text', 10], ['Name', 'text', 40], ['Balance', 'money', 16]], 'rows' => $rows,
                  'notes' => [$bs['balances'] ? 'Assets = liabilities + equity.' : 'OUT OF BALANCE: assets ' . $bs['total_assets'] . ' vs liabilities + equity ' . $bs['liabilities_plus_equity'] . '.']];
            LedgerExport::sendTable($db, $t, $format, "$entity-balance-sheet-$asOf", 'bs', ['as_of' => $asOf]);
            break;

        case 'is':
            $is = (new ReportingService($db))->getIncomeStatement($from, $to);
            $rows = [['cells' => ['Revenue', '', null], 'bold' => true]];
            foreach ($is['revenue'] as $r) $rows[] = [$r['code'], $r['name'], $r['amount']];
            $rows[] = ['cells' => ['', 'Total revenue', $is['total_revenue']], 'bold' => true];
            $rows[] = ['cells' => ['Expenses', '', null], 'bold' => true];
            foreach ($is['expenses'] as $r) $rows[] = [$r['code'], $r['name'], $r['amount']];
            $rows[] = ['cells' => ['', 'Total expenses', $is['total_expenses']], 'bold' => true];
            $rows[] = ['cells' => ['', 'Net income', $is['net_income']], 'bold' => true];
            $t = ['title' => 'Income statement', 'period' => "$from to $to", 'from' => $from, 'to' => $to,
                  'columns' => [['Account', 'text', 10], ['Name', 'text', 40], ['Amount', 'money', 16]], 'rows' => $rows,
                  'notes' => ['The FY opening entry (closing the prior year) is left out of the period, as on the income statement page.']];
            LedgerExport::sendTable($db, $t, $format, "$entity-income-statement-$from-$to", 'is', ['from' => $from, 'to' => $to]);
            break;

        case 'qbo':
            $mode = ($_GET['mode'] ?? 'summary') === 'detailed' ? 'detailed' : 'summary';
            $fmt = isset(QboJournalExport::DATE_FORMATS[$_GET['datefmt'] ?? '']) ? (string)$_GET['datefmt'] : 'dmy';
            $q = new QboJournalExport($db);
            $lines = $q->lines($from, $to);
            $rows = $mode === 'detailed' ? QboJournalExport::detailed($lines, $fmt) : QboJournalExport::summary($lines, $fmt);
            foreach (QboJournalExport::journalTotals($rows) as $no => [$d, $c]) {
                if (abs($d - $c) >= 0.005) $refuse(409, "Export refused: journal $no does not balance ($d vs $c).");
            }
            // QBO reads the first row as the column names, so this file has no header block; the entity,
            // period, run time and lock status go in the file name and the export log instead.
            $lock = preg_replace('/[^a-z0-9]+/', '-', strtolower(LedgerExport::lockStatus($db, $from, $to)));
            $csv = LedgerExport::csv(array_merge([QboJournalExport::COLUMNS], $rows));
            LedgerExport::send("$entity-qbo-journal-$mode-$from-$to-" . trim($lock, '-') . '-run-' . date('Ymd-His') . '.csv', $csv, "qbo.$mode",
                               ['from' => $from, 'to' => $to, 'datefmt' => $fmt, 'journals' => count(QboJournalExport::journalTotals($rows))]);
            break;

        default:
            $refuse(400, 'Unknown report.');
    }
} catch (Throwable $e) {
    error_log('ledger/export ' . $report . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $refuse(500, 'The export failed — nothing was sent. The error is in the server log.');
}
