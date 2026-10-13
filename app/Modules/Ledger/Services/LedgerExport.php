<?php
declare(strict_types=1);

/**
 * LedgerExport — every Ledger file carries entity, period, run time and lock status in its header
 * rows (plan: "Every file carries entity, period, run time and lock status; numbers stored as
 * numbers"), and every export is logged to activity_log as 'ledger.export'.
 *
 * Logging: send() / csvStream() write the 'ledger.export' row for EVERY user, accountant included —
 * only a file actually sent counts (the fence logs the page request as 'ledger.view'; a blocked export
 * is a view, not an export — review 2026-10-13).
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

    /** One 'ledger.export' row in activity_log for a file sent, whoever sent it. */
    public static function logExport(string $what, array $params = []): void
    {
        if (!function_exists('logActivity')) return;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return;
        try {
            logActivity($uid, null, 'ledger.export', json_encode(['export' => $what, 'params' => $params],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (Throwable $e) {
            error_log('ledger.export not logged: ' . $e->getMessage());
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

    /** Download headers for a file. */
    private static function downloadHeaders(string $filename, string $type): void
    {
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    /** Send a CSV download, log it as an export, and stop. */
    public static function send(string $filename, string $csv, string $what = '', array $params = []): void
    {
        self::logExport($what !== '' ? $what : $filename, $params);
        self::downloadHeaders($filename, 'text/csv; charset=utf-8');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /**
     * Start a streamed CSV download (no row cap, nothing held in memory): logs the export, sends the
     * headers and the BOM, and returns the output handle for csvLine(). The caller exits when done.
     */
    public static function csvStream(string $filename, string $what, array $params = [])
    {
        require_once dirname(__DIR__, 3) . '/Core/Csv.php';
        self::logExport($what, $params);
        while (ob_get_level() > 0) ob_end_clean();
        self::downloadHeaders($filename, 'text/csv; charset=utf-8');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        return $fh;
    }

    /** One spreadsheet-safe CSV row to a stream. */
    public static function csvLine($fh, array $row): void
    {
        csvPut($fh, array_values($row));
    }
}
