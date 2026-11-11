<?php
/**
 * XlsxReader — read the cells of an .xlsx worksheet with no Composer library (owner, 2026-10-12).
 *
 * SHARED code (Ittai): any supplier's spreadsheet goes through here; the format readers on top
 * (e.g. InlineListParser) decide what the cells mean. Uses ZipArchive + SimpleXML only.
 *
 * Handles: shared strings (plain and rich-text runs), inline strings, formula string results,
 * booleans and numbers (cached values of formulas too). Dates come back as the Excel serial
 * number; use excelDate() to turn one into Y-m-d.
 *
 * Safety: the workbook is untrusted input. Entries are size-capped before they are read (a zip
 * bomb stops at the cap), and SimpleXML is used without network access or entity expansion.
 *
 * Global-namespace class: require_once the file.
 */
declare(strict_types=1);

class XlsxReader
{
    /** Largest uncompressed XML part we will read (worksheet or shared strings). */
    public const MAX_PART_BYTES = 40 * 1024 * 1024;

    /**
     * The first worksheet's cells: [rowNumber => [columnLetters => value]].
     * Values are string, int, float or bool. Empty cells are left out.
     * @throws RuntimeException when the bytes are not a readable workbook.
     */
    public static function firstSheet(string $bytes): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is not available');
        if (strncmp($bytes, "PK", 2) !== 0) throw new RuntimeException('not an xlsx file (no zip signature)');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) throw new RuntimeException('no temp file');
        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) throw new RuntimeException('not an xlsx file (zip did not open)');
            try {
                $sheetPath = self::firstSheetPath($zip);
                $shared = self::sharedStrings($zip);
                $xml = self::part($zip, $sheetPath);
                if ($xml === null) throw new RuntimeException('worksheet missing: ' . $sheetPath);
                return self::cells($xml, $shared);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    /** Column letters → 1-based index ("A" → 1, "AA" → 27). PURE. */
    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }

    /** An Excel serial date (1900 system) → 'Y-m-d', or null. 46307 → 2026-10-12. PURE. */
    public static function excelDate($serial): ?string
    {
        if (!is_numeric($serial)) return null;
        $d = (int)floor((float)$serial);
        if ($d < 1 || $d > 2958465) return null;
        // Serial 25569 = 1970-01-01 (accounts for Excel's fictitious 1900-02-29).
        return gmdate('Y-m-d', ($d - 25569) * 86400);
    }

    // ---------------------------------------------------------------------------------------

    private static function part(ZipArchive $zip, string $name): ?string
    {
        $st = $zip->statName($name);
        if ($st === false) return null;
        if ((int)$st['size'] > self::MAX_PART_BYTES) throw new RuntimeException($name . ' is too large');
        $data = $zip->getFromName($name);
        return $data === false ? null : $data;
    }

    private static function xml(string $data): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $x = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($x === false) throw new RuntimeException('unreadable XML in workbook');
        return $x;
    }

    /** xl/workbook.xml's first <sheet> → its target via xl/_rels/workbook.xml.rels. */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb = self::part($zip, 'xl/workbook.xml');
        $rels = self::part($zip, 'xl/_rels/workbook.xml.rels');
        if ($wb !== null && $rels !== null) {
            $w = self::xml($wb);
            $sheets = $w->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets;
            $first = $sheets ? $sheets->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheet[0] : null;
            if ($first !== null) {
                $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach (self::xml($rels)->children() as $rel) {
                    if ((string)$rel['Id'] !== $rid) continue;
                    $target = ltrim((string)$rel['Target'], '/');
                    $path = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                    if ($zip->statName($path) !== false) return $path;
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return string[] */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $data = self::part($zip, 'xl/sharedStrings.xml');
        if ($data === null) return [];
        $out = [];
        foreach (self::xml($data)->children() as $si) {
            $out[] = self::stringItem($si);
        }
        return $out;
    }

    /** <si>/<is>: either one <t>, or rich-text runs <r><t>; phonetic runs <rPh> are skipped. */
    private static function stringItem(SimpleXMLElement $si): string
    {
        if (isset($si->t)) return (string)$si->t;
        $s = '';
        foreach ($si->r as $r) $s .= (string)$r->t;
        return $s;
    }

    private static function cells(string $xml, array $shared): array
    {
        $x = self::xml($xml);
        $rows = [];
        if (!isset($x->sheetData)) return $rows;
        foreach ($x->sheetData->row as $row) {
            $rn = (int)$row['r'];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
                $rnum = (int)$m[2] ?: $rn;
                $t = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : null;
                if ($t === 's') {
                    if ($v === null || !isset($shared[(int)$v])) continue;
                    $val = $shared[(int)$v];
                } elseif ($t === 'inlineStr') {
                    if (!isset($c->is)) continue;
                    $val = self::stringItem($c->is);
                } elseif ($t === 'str' || $t === 'e') {
                    if ($v === null) continue;
                    $val = $v;
                } elseif ($t === 'b') {
                    if ($v === null) continue;
                    $val = $v === '1';
                } else {
                    if ($v === null || $v === '') continue;
                    $val = is_numeric($v) ? (preg_match('/^-?\d+$/', $v) ? (int)$v : (float)$v) : $v;
                }
                if (is_string($val) && trim($val) === '') continue;
                $rows[$rnum][$m[1]] = $val;
            }
        }
        ksort($rows);
        return $rows;
    }
}
