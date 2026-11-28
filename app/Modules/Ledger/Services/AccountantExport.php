<?php
declare(strict_types=1);

/**
 * AccountantExport — "Prepare for my accountant" (plan: docs/plans/2026-11-28-accountant-ledger-export-plan.md).
 *
 * One spreadsheet from the ledger: every journal line for a period, with the columns an accountant asks for
 * (date, account, description, debit, credit, GST, PST, vendor, receipt reference). It is the existing journal
 * export (ledger/export.php) with four columns added; nothing else about the files changes. The owner downloads
 * it and sends it themselves. Receipts are not included: the receipt reference is Ittai's own number, text only.
 *
 * Period presets: this_month, last_month, this_year (fiscal year so far), last_year (the last full fiscal year).
 * No namespace / no autoloader in production: require_once and `new`.
 */
final class AccountantExport
{
    public const PRESETS = ['this_month', 'last_month', 'this_year', 'last_year'];

    /** Table columns for the journal file (label, type, width). */
    public const COLUMNS = [
        ['Entry', 'int', 8], ['Date', 'date', 11], ['Kind', 'text', 9], ['Source', 'text', 16], ['Memo', 'text', 40],
        ['Account', 'text', 8], ['Account name', 'text', 24], ['GIFI', 'text', 6], ['Debit', 'money', 13], ['Credit', 'money', 13],
        ['Line description', 'text', 30], ['By', 'text', 14],
        ['GST', 'money', 11], ['PST', 'money', 11], ['Vendor', 'text', 24], ['Receipt ref', 'text', 12],
    ];

    /** Pure: [from, to] for a preset on a given day, from the fiscal year end (MM-DD). Unknown preset → null. */
    public static function period(string $preset, string $today, string $fye = FiscalYear::DEFAULT_END): ?array
    {
        $t = new DateTimeImmutable($today);
        switch ($preset) {
            case 'this_month':
                return [$t->format('Y-m-01'), $t->format('Y-m-t')];
            case 'last_month':
                $m = $t->modify('first day of last month');
                return [$m->format('Y-m-01'), $m->format('Y-m-t')];
            case 'this_year':
                $fy = FiscalYear::of($t->format('Y-m-d'), $fye);
                return [FiscalYear::bounds($fy, $fye)[0], $t->format('Y-m-d')];
            case 'last_year':
                $fy = FiscalYear::of($t->format('Y-m-d'), $fye) - 1;
                return FiscalYear::bounds($fy, $fye);
        }
        return null;
    }

    /** Pure: one journal line as a row of COLUMNS. Money blank when zero; vendor and receipt ref blank when not a receipt. */
    public static function lineRow(array $l): array
    {
        $isReceipt = ($l['source_type'] ?? '') === 'expense' && !empty($l['source_id']);
        $gst = round((float)($l['gst_amount'] ?? 0), 2);
        $pst = round((float)($l['pst_amount'] ?? 0), 2);
        return [
            (int)$l['id'], $l['entry_date'], $l['display_kind'],
            str_replace('_', ' ', (string)$l['source_type']) . (!empty($l['source_id']) ? ' ' . (int)$l['source_id'] : ''),
            $l['memo'], $l['code'], $l['account_name'], $l['gifi_code'],
            (float)$l['debit'] ?: null, (float)$l['credit'] ?: null, $l['description'],
            $l['created_by_name'] ?: ($l['proposed_by'] ?: 'system'),
            $gst ?: null, $pst ?: null,
            $isReceipt ? (trim((string)($l['vendor_name'] ?? '')) ?: null) : null,
            $isReceipt ? 'R' . (int)$l['source_id'] : null,
        ];
    }

    /** The table for LedgerExport::sendTable. Streams every line of the period. */
    public static function table(JournalBrowserService $svc, array $filters, string $from, string $to): array
    {
        $rows = [];
        $svc->streamLines($filters, static function (array $l) use (&$rows): void {
            $rows[] = self::lineRow($l);
        });
        return ['title' => 'Journal', 'period' => "$from to $to", 'from' => $from, 'to' => $to, 'landscape' => true,
                'rows' => $rows, 'columns' => self::COLUMNS];
    }
}
