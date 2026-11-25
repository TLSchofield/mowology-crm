<?php
/**
 * PdfTableReader — a supplier's PDF price table as spreadsheet cells, so SupplierListParser reads it
 * like any .xlsx (owner's supplier lists, 2026-10-19: SW Garden Supply mails its contractor prices as a PDF).
 * SHARED code (Ittai). Nothing here knows a supplier: the format's spec says
 *   pdf.numbers   how many numbers end a product row (SW Garden: contractor, retail, half yard, t/yd → 4)
 *   pdf.columns   the header names to give those numbers ("Contractor", "Retail", …) — the spec's
 *                 `columns` then map them to price / list_price like any sheet
 *   pdf.start     text of the line the table starts after (e.g. "Per Yard")
 *   pdf.stop      a regex: the table ends at the first line matching it (e.g. "^\*\*\*")
 *   pdf.extra     header names whose values are folded into the Notes cell ("½ yd $45 · ~1.05 t/yd")
 * Output: row 1 = the first text line (the list's title), row 2 = header (Product, Notes, …numbers),
 * then one row per product. A product's text may wrap over lines; it ends at the line that ends with
 * the numbers. Name and notes split at the first tab (pdfparser's column gap), else all name.
 *
 * Text comes from smalot/pdfparser (vendor/, already on the server).
 */
class PdfTableReader
{
    /** @throws RuntimeException */
    public static function cells(string $bytes, array $pdf): array
    {
        // Production has no autoloader of its own: VENDOR_ROOT (app/Core/paths.php), as BankImportService does.
        $auto = (defined('VENDOR_ROOT') ? VENDOR_ROOT : dirname(__DIR__, 3) . '/vendor') . '/autoload.php';
        if (!class_exists('\\Smalot\\PdfParser\\Parser') && is_file($auto)) require_once $auto;
        if (!class_exists('\\Smalot\\PdfParser\\Parser')) throw new RuntimeException('pdf: smalot/pdfparser not installed');
        $text = (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        return self::fromText($text, $pdf);
    }

    /** PURE: the PDF's text → cells. */
    public static function fromText(string $text, array $pdf): array
    {
        $n = max(1, (int)($pdf['numbers'] ?? 1));
        $names = array_values((array)($pdf['columns'] ?? []));
        $extra = array_values((array)($pdf['extra'] ?? []));
        $lines = preg_split('/\R/u', $text) ?: [];
        $title = '';
        foreach ($lines as $l) { if (trim($l) !== '') { $title = trim($l); break; } }

        $start = (string)($pdf['start'] ?? '');
        $i = 0;
        if ($start !== '') {
            foreach ($lines as $k => $l) if (stripos($l, $start) !== false) { $i = $k + 1; break; }
        }
        $num = '-?\d+(?:[.,]\d+)?';
        $tail = '/^(.*?)\s*((?:\$?' . $num . '\s+){' . ($n - 1) . '}\$?' . $num . ')\s*$/u';

        $header = ['Product', 'Notes'];
        for ($c = 0; $c < $n; $c++) $header[] = $names[$c] ?? ('Value ' . ($c + 1));
        $cells = [1 => ['A' => $title], 2 => []];
        foreach ($header as $c => $h) $cells[2][SheetReader::letters($c + 1)] = $h;

        $row = 3;
        $buf = '';
        for ($k = $i, $m = count($lines); $k < $m; $k++) {
            $l = rtrim($lines[$k]);
            if (!empty($pdf['stop']) && @preg_match('/' . $pdf['stop'] . '/u', trim($l))) break;
            if (trim($l) === '') continue;
            if (!preg_match($tail, $l, $mm)) { $buf .= ($buf === '' ? '' : ' ') . $l; continue; }
            $textPart = trim($buf . ($buf === '' || $mm[1] === '' ? '' : ' ') . $mm[1]);
            $buf = '';
            if ($textPart === '') continue;
            $vals = preg_split('/\s+/', trim($mm[2])) ?: [];
            $parts = preg_split('/\t+/', $textPart, 2);
            $name = trim(preg_replace('/\s+/', ' ', $parts[0]));
            $notes = trim(preg_replace('/\s+/', ' ', $parts[1] ?? ''));
            $out = ['A' => $name];
            $folded = [];
            foreach ($vals as $c => $v) {
                $v = (float)str_replace([',', '$'], ['.', ''], $v);
                $col = $header[$c + 2] ?? null;
                if ($col !== null && in_array($col, $extra, true)) $folded[] = $col . ' ' . rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
                $out[SheetReader::letters($c + 3)] = $v;
            }
            $note = trim($notes . ($folded ? ($notes !== '' ? ' · ' : '') . implode(' · ', $folded) : ''));
            if ($note !== '') $out['B'] = $note;
            $cells[$row++] = $out;
        }
        return $cells;
    }
}
