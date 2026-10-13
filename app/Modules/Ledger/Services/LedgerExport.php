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

    // ── Tables: one shape, three files (step 2) ─────────────────────────────────────────────────────
    //
    // A table: ['title' => …, 'period' => …, 'from' => Y-m-d, 'to' => Y-m-d (for the lock status),
    //           'columns' => [[label, type, width], …]   type: text | money | int | date
    //           'rows' => [ [cells…] | ['cells' => […], 'bold' => true], … ],
    //           'notes' => [lines after the table], 'landscape' => bool]
    // Every file opens with the header rows (entity, report, period, lock status, run at); no merged
    // cells; money stays a number (CSV: plain 1234.5, XLSX: a number cell, PDF: printed).

    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /** A table row's cells and whether it is bold. Pure. */
    public static function rowParts($row): array
    {
        if (is_array($row) && array_key_exists('cells', $row)) return [array_values($row['cells']), !empty($row['bold'])];
        return [array_values((array)$row), false];
    }

    /** The table as CSV rows (header rows, column labels, body, notes). Pure. */
    public static function tableRows(array $t, array $header): array
    {
        $out = $header;
        $out[] = array_map(static fn($c) => $c[0], $t['columns']);
        $types = array_map(static fn($c) => $c[1] ?? 'text', $t['columns']);
        foreach ($t['rows'] as $row) {
            [$cells] = self::rowParts($row);
            foreach ($cells as $i => $v) {
                if ($v !== null && $v !== '' && in_array($types[$i] ?? 'text', ['money', 'int'], true) && is_numeric($v)) {
                    $cells[$i] = ($types[$i] === 'int') ? (int)$v : round((float)$v, 2);
                }
            }
            $out[] = $cells;
        }
        if (!empty($t['notes'])) { $out[] = []; foreach ($t['notes'] as $n) $out[] = [$n]; }
        return $out;
    }

    /** Header rows for a table in this database. */
    public static function headerFor(PDO $db, array $t): array
    {
        $from = (string)($t['from'] ?? ($t['to'] ?? date('Y-m-d')));
        $to = (string)($t['to'] ?? date('Y-m-d'));
        return self::headerRows(self::entity($db), (string)$t['title'], (string)$t['period'], self::lockStatus($db, $from, $to), date('Y-m-d H:i:s T'));
    }

    /** Write the table as an .xlsx; returns the temp path (caller unlinks). */
    public static function tableXlsx(array $t, array $header): string
    {
        require_once dirname(__DIR__, 3) . '/Services/Spreadsheet/XlsxWriter.php';
        $x = new XlsxWriter((string)$t['title']);
        foreach ($header as $h) $x->row($h, $h ? XlsxWriter::BOLD : XlsxWriter::PLAIN, ['text', 'text']);
        $x->row(array_map(static fn($c) => $c[0], $t['columns']), XlsxWriter::BOLD);
        $x->freezeBelow($x->rowCount());
        $x->widths(array_map(static fn($c) => $c[2] ?? 14, $t['columns']));
        $types = array_map(static fn($c) => $c[1] ?? 'text', $t['columns']);
        foreach ($t['rows'] as $row) {
            [$cells, $bold] = self::rowParts($row);
            $x->row($cells, $bold ? XlsxWriter::BOLD : XlsxWriter::PLAIN, $types);
        }
        if (!empty($t['notes'])) { $x->row([]); foreach ($t['notes'] as $n) $x->row([$n]); }
        return $x->save();
    }

    /** Money for print: 1,234.50 / (1,234.50). Pure. */
    public static function printMoney($v): string
    {
        if ($v === null || $v === '') return '';
        $n = round((float)$v, 2);
        $s = number_format(abs($n), 2);
        return $n < 0 ? '(' . $s . ')' : $s;
    }

    /** The table as printable HTML (the PDF's body). Pure. */
    public static function tableHtml(array $t, array $header): string
    {
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $types = array_map(static fn($c) => $c[1] ?? 'text', $t['columns']);
        $h = '<style>body{font-family:helvetica,sans-serif;font-size:8.5pt;color:#1a1a1a}'
           . 'table.hd td{padding:0 8px 1px 0;font-size:8.5pt}table.hd td.k{color:#666}'
           . 'table.t{border-collapse:collapse;width:100%;margin-top:8px}'
           . 'table.t th{background:#eef3f0;border-bottom:1px solid #999;text-align:left;padding:3px 4px;font-size:7.5pt;text-transform:uppercase}'
           . 'table.t td{border-bottom:0.5px solid #ddd;padding:2px 4px;vertical-align:top}'
           . 'table.t .n{text-align:right;white-space:nowrap}table.t tr.b td{font-weight:bold;border-top:1px solid #999}'
           . 'p.note{font-size:7.5pt;color:#555;margin:2px 0}</style>';
        $h .= '<table class="hd">';
        foreach ($header as $r) if ($r) $h .= '<tr><td class="k">' . $e($r[0]) . '</td><td>' . $e($r[1] ?? '') . '</td></tr>';
        $h .= '</table><table class="t"><thead><tr>';
        foreach ($t['columns'] as $i => $c) $h .= '<th' . (in_array($types[$i], ['money', 'int'], true) ? ' class="n"' : '') . '>' . $e($c[0]) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($t['rows'] as $row) {
            [$cells, $bold] = self::rowParts($row);
            $h .= '<tr' . ($bold ? ' class="b"' : '') . '>';
            foreach ($t['columns'] as $i => $c) {
                $v = $cells[$i] ?? '';
                $num = in_array($types[$i], ['money', 'int'], true);
                $txt = $types[$i] === 'money' && is_numeric($v) ? self::printMoney($v) : (string)$v;
                $h .= '<td' . ($num ? ' class="n"' : '') . '>' . $e($txt) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        foreach ($t['notes'] ?? [] as $n) $h .= '<p class="note">' . $e($n) . '</p>';
        return $h;
    }

    /** Render the table to PDF bytes with mPDF (public/vendor). */
    public static function tablePdf(array $t, array $header): string
    {
        if (!class_exists('Mpdf\Mpdf', false)) require_once dirname(__DIR__, 3) . '/Services/Pdf/pdf_bootstrap.php';
        $tmpDir = (defined('STORAGE_ROOT') ? STORAGE_ROOT : sys_get_temp_dir()) . '/tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        if (!is_writable($tmpDir)) { $tmpDir = sys_get_temp_dir() . '/mpdf'; if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true); }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => !empty($t['landscape']) ? 'Letter-L' : 'Letter',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 12, 'margin_bottom' => 14,
            'default_font' => 'helvetica', 'tempDir' => $tmpDir,
        ]);
        $mpdf->SetTitle((string)$t['title']);
        $mpdf->SetFooter((string)$t['title'] . '|' . (string)$t['period'] . '|Page {PAGENO} of {nbpg}');
        $mpdf->WriteHTML(self::tableHtml($t, $header));
        return $mpdf->Output('', 'S');
    }

    /**
     * Send a table as xlsx / csv / pdf, log the export, stop. $basename has no extension.
     * @throws InvalidArgumentException on an unknown format
     */
    public static function sendTable(PDO $db, array $t, string $format, string $basename, string $what, array $params = []): void
    {
        if (!in_array($format, self::FORMATS, true)) throw new InvalidArgumentException('Unknown format');
        $header = self::headerFor($db, $t);
        $file = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) . '.' . $format;
        if ($format === 'csv') {
            self::send($file, self::csv(self::tableRows($t, $header)), $what . '.csv', $params);
        }
        if ($format === 'xlsx') {
            $path = self::tableXlsx($t, $header);
            self::logExport($what . '.xlsx', $params);
            while (ob_get_level() > 0) ob_end_clean();
            self::downloadHeaders($file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            @unlink($path);
            exit;
        }
        $pdf = self::tablePdf($t, $header);
        self::logExport($what . '.pdf', $params);
        while (ob_get_level() > 0) ob_end_clean();
        self::downloadHeaders($file, 'application/pdf');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
