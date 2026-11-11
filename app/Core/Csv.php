<?php
/**
 * Csv — spreadsheet-safe CSV cells (security review #18, 2026-10-12).
 *
 * A cell that starts with = + - @ (or a tab / carriage return) is run as a FORMULA by Excel, Numbers
 * and Sheets. A public quote-form name such as =HYPERLINK("https://evil","Click") reached every
 * export unchanged. csvCell() prefixes such text with an apostrophe, which spreadsheets show as text;
 * plain numbers ("-12.50", "1,234.00", "12%") are left alone so money columns stay numbers.
 *
 * Use csvPut() wherever fputcsv() was used. Global functions; require_once APP_ROOT . '/Core/Csv.php'.
 */
declare(strict_types=1);

if (!function_exists('csvCell')) {
    /** One cell, safe to open in a spreadsheet. PURE. */
    function csvCell($value)
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) return $value;
        $s = (string)$value;
        if ($s === '') return $s;
        if (preg_match('/^[+-]?(\d{1,3}(,\d{3})+|\d+)?(\.\d+)?%?$/', $s) && preg_match('/\d/', $s)) return $s;   // a plain number
        return strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }
}

if (!function_exists('csvRow')) {
    /** Every cell of a row through csvCell(). PURE. */
    function csvRow(array $row): array
    {
        return array_map('csvCell', $row);
    }
}

if (!function_exists('csvPut')) {
    /** fputcsv() with every cell made spreadsheet-safe. */
    function csvPut($handle, array $row, string $sep = ',', string $enc = '"', string $esc = '\\')
    {
        return fputcsv($handle, csvRow($row), $sep, $enc, $esc);
    }
}
