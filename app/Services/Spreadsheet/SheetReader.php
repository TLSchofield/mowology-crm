<?php
/**
 * SheetReader — one way in for supplier spreadsheets, whatever the file type (owner, 2026-10-12).
 * SHARED code (Ittai). Returns the same shape for .xlsx and .csv:
 *   [rowNumber => [columnLetters => value]]  (row 1 = first line; numbers come back as int/float)
 * so a format reader (SupplierListParser) can address cells as "B3" in either.
 */
declare(strict_types=1);

require_once __DIR__ . '/XlsxReader.php';
require_once __DIR__ . '/XlsReader.php';   // old Excel (.xls), e.g. Specimen Trees (2026-10-19)

class SheetReader
{
    /** File types we read. */
    public const TYPES = ['xlsx', 'xls', 'csv'];

    /** The file type from a name (".xlsx" → xlsx), or from the bytes when the name says nothing. PURE. */
    public static function typeOf(string $fileName, string $bytes = ''): ?string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, self::TYPES, true)) return $ext;
        if ($bytes !== '' && strncmp($bytes, 'PK', 2) === 0) return 'xlsx';
        if ($bytes !== '' && strncmp($bytes, "\xD0\xCF\x11\xE0", 4) === 0) return 'xls';
        return null;
    }

    /** @throws RuntimeException */
    public static function read(string $bytes, string $fileName): array
    {
        $type = self::typeOf($fileName, $bytes);
        if ($type === 'xlsx') return XlsxReader::firstSheet($bytes);
        if ($type === 'xls') return XlsReader::firstSheet($bytes);
        if ($type === 'csv') return self::csv($bytes);
        throw new RuntimeException('unsupported file type: ' . $fileName);
    }

    /** CSV → cells. Comma, semicolon or tab, whichever the first line uses most. PURE. */
    public static function csv(string $bytes): array
    {
        $bytes = (string)preg_replace('/^\xEF\xBB\xBF/', '', $bytes);   // BOM
        if (!mb_check_encoding($bytes, 'UTF-8')) $bytes = (string)mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        $first = strtok($bytes, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $sep = (string)array_key_first($counts);
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);
        $rows = [];
        $r = 0;
        while (($line = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $r++;
            foreach ($line as $i => $v) {
                $v = trim((string)$v);
                if ($v === '') continue;
                $col = self::letters($i + 1);
                $num = str_replace([',', '$'], '', $v);
                $rows[$r][$col] = is_numeric($num) && preg_match('/^-?\$?[\d,]*\.?\d+$/', $v)
                    ? (preg_match('/^-?\d+$/', $num) ? (int)$num : (float)$num) : $v;
            }
        }
        fclose($fh);
        return $rows;
    }

    /** 1 → A, 27 → AA. PURE. */
    public static function letters(int $n): string
    {
        $s = '';
        while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
        return $s;
    }
}
