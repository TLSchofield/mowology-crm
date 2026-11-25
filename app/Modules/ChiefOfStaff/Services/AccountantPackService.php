<?php
/**
 * AccountantPackService — the year-end package Charlie gets ready for the accountant.
 *
 * Read-only counts and links, never a filing: what's still unfinished comes first
 * (unapproved receipts, uncategorised bank lines, months not closed), then the reports
 * to send. Every count is guarded — a missing table is a line left out, not an error.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class AccountantPackService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int, array{text: string, url: ?string, bad: bool}> for the year (default: last year) */
    public function lines(?int $year = null): array
    {
        $year = $year ?? ((int)date('Y') - 1);
        $from = $year . '-01-01';
        $to = $year . '-12-31';
        $n = function (string $sql, array $p) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($p);
                return (int)$s->fetchColumn();
            } catch (Throwable $e) {
                return null;
            }
        };
        return self::compose($year, [
            'receipts'     => $n("SELECT COUNT(*) FROM expenses WHERE status IN ('draft', 'pending_approval') AND expense_date BETWEEN ? AND ?", [$from, $to]),
            'bank'         => $n("SELECT COUNT(*) FROM bank_import_rows WHERE account_id IS NULL AND is_duplicate = 0 AND transaction_date BETWEEN ? AND ?", [$from, $to]),
            // Month closing only means something once it has been used at least once.
            'closing_used' => $n("SELECT COUNT(*) FROM accounting_periods WHERE status IN ('closed', 'locked')", []),
            'closed'       => $n("SELECT COUNT(*) FROM accounting_periods WHERE year = ? AND status IN ('closed', 'locked')", [$year]),
        ]);
    }

    /** Pure: the facts → lines, unfinished first. */
    public static function compose(int $year, array $f): array
    {
        $lines = [];
        $s = static fn(int $n) => $n === 1 ? '' : 's';
        if (($f['receipts'] ?? null) !== null) {
            $r = (int)$f['receipts'];
            $lines[] = ['text' => $r > 0 ? "{$r} receipt{$s($r)} from {$year} still not approved" : "All {$year} receipts approved",
                        'url' => '/crm/dashboard_appstack.php#mw-penny', 'bad' => $r > 0];
        }
        if (($f['bank'] ?? null) !== null) {
            $b = (int)$f['bank'];
            $lines[] = ['text' => $b > 0 ? "{$b} bank line{$s($b)} from {$year} not categorised" : "Every {$year} bank line categorised",
                        'url' => '/crm/accounting/bank-import.php', 'bad' => $b > 0];
        }
        if ((int)($f['closing_used'] ?? 0) > 0 && ($f['closed'] ?? null) !== null) {
            $open = 12 - min(12, (int)$f['closed']);
            $lines[] = ['text' => $open > 0 ? "{$open} month{$s($open)} of {$year} not closed" : "All 12 months of {$year} closed",
                        'url' => null, 'bad' => $open > 0];
        }
        $bad = array_values(array_filter($lines, fn($l) => $l['bad']));
        $good = array_values(array_filter($lines, fn($l) => !$l['bad']));
        $send = [
            ['text' => "Income statement for {$year}", 'url' => '/crm/accounting/income-statement.php', 'bad' => false],
            ['text' => "Balance sheet at Dec 31, {$year}", 'url' => '/crm/accounting/balance-sheet.php', 'bad' => false],
            ['text' => "GST collected and paid in {$year}", 'url' => '/crm/tax-report_appstack.php', 'bad' => false],
        ];
        return array_merge($bad, $good, $send);
    }
}
