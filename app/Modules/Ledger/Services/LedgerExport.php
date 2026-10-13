<?php
declare(strict_types=1);

/**
 * LedgerExport — every Ledger file carries entity, period, run time and lock status in its header
 * rows (plan: "Every file carries entity, period, run time and lock status; numbers stored as
 * numbers"), and every export is logged to activity_log as 'ledger.export'.
 *
 * The accountant's exports are already logged by AccountantFence::logRequest() (any ?export= request);
 * this logs everyone else's, so each export is one row whoever made it.
 */
final class LedgerExport
{
    /** Header rows for a file. Pure. */
    public static function headerRows(string $entity, string $title, string $period, string $lockStatus, string $runAt): array
    {
        return [
            ['Entity', $entity],
            ['Report', $title],
            ['Period', $period],
            ['Lock status', $lockStatus],
            ['Run at', $runAt],
            [],
        ];
    }

    /** CSV text: numbers stay numbers (no thousands separators, no currency symbols). Pure. */
    public static function csv(array $rows): string
    {
        require_once dirname(__DIR__, 3) . '/Core/Csv.php';   // spreadsheet-safe cells (security review #18)
        $fh = fopen('php://temp', 'w+');
        foreach ($rows as $r) csvPut($fh, array_values($r));
        rewind($fh);
        $out = (string)stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /** Log an export by someone other than the accountant (the fence logs theirs). */
    public static function log(int $userId, string $what, array $params): void
    {
        if (class_exists('AccountantFence') && AccountantFence::activeForSession()) return;
        if (function_exists('logActivity')) {
            logActivity($userId, null, 'ledger.export', json_encode(['export' => $what, 'params' => $params], JSON_UNESCAPED_SLASHES));
        }
    }

    /** The business's name for file headers (business_settings.company_name). */
    public static function entity(PDO $db): string
    {
        try {
            $n = $db->query("SELECT company_name FROM business_settings ORDER BY id LIMIT 1")->fetchColumn();
            return $n ? (string)$n : 'Company';
        } catch (Throwable $e) {
            return 'Company';
        }
    }

    /** Lock status of a date range, in words. */
    public static function lockStatus(PDO $db, string $from, string $to): string
    {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM accounting_periods WHERE status = 'locked'
                                AND CONCAT(year, '-', LPAD(month, 2, '0')) BETWEEN ? AND ?");
            $st->execute([substr($from, 0, 7), substr($to, 0, 7)]);
            $locked = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 'unknown';
        }
        $months = max(1, ((int)substr($to, 0, 4) * 12 + (int)substr($to, 5, 2)) - ((int)substr($from, 0, 4) * 12 + (int)substr($from, 5, 2)) + 1);
        if ($locked === 0) return 'open (not locked)';
        return $locked >= $months ? 'locked' : "partly locked ($locked month" . ($locked === 1 ? '' : 's') . ' locked)';
    }

    /** Send a CSV download and stop. */
    public static function send(string $filename, string $csv): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }
}
