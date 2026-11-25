<?php
/**
 * SupplierListParser — reads ANY supplier's price/availability sheet from a format row
 * (supplier_list_formats.spec_json). PURE: cells in, rows out, no database (owner, 2026-10-12).
 *
 * SHARED code (Ittai): nothing here knows a supplier. The format says
 *   header_anchor  the cell text that starts the header row ("ProductID")
 *   columns        field => header synonyms (exact match after folding; "@month" = a month name,
 *                  e.g. Inline's "October" special-price column, so next month's file still reads)
 *   title_cell / date_cell / rules_cells ("G7:G12", "B6")
 *   kinds          [{kind, file, title}] — first whose pattern matches the file name or title
 *   valid_until    patterns over the email text + rules ("shipping by October 30th")
 * Fields: product_code, name, size, zone, category, brand, mfr_part_no, upc, unit, price,
 * list_price, special_price, available, comment. Money is rounded to cents (Excel float noise).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Services/Spreadsheet/SheetReader.php';

class SupplierListParser
{
    public const FIELDS = ['product_code', 'name', 'size', 'zone', 'category', 'brand', 'mfr_part_no', 'upc', 'unit',
                           'price', 'list_price', 'special_price', 'available', 'comment'];
    private const MONEY = ['price', 'list_price', 'special_price'];
    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september',
                            'october', 'november', 'december'];

    /** Decode a format row's spec_json. @throws RuntimeException */
    public static function spec(string $json): array
    {
        $s = json_decode($json, true);
        if (!is_array($s) || empty($s['columns']) || !is_array($s['columns'])) throw new RuntimeException('bad list format spec');
        return $s;
    }

    /** Header text → comparable form: lower case, letters/digits only, single spaces. PURE. */
    public static function fold(string $s): string
    {
        return trim((string)preg_replace('/[^a-z0-9#]+/', ' ', strtolower($s)));
    }

    /**
     * @param array $cells [row => [col => value]] from SheetReader
     * @param array $meta  file_name, body (email text), received (Y-m-d)
     * @return array{kind: string, list_date: string, title: string, rules: string[], valid_until: ?string,
     *               columns: array<string,string>, rows: array<int,array>, skipped: int}
     */
    public static function parse(array $cells, array $spec, array $meta = []): array
    {
        $anchor = self::fold((string)($spec['header_anchor'] ?? ''));
        $headerRow = null;
        foreach ($cells as $r => $row) {
            foreach ($row as $v) {
                if (is_string($v) && $anchor !== '' && self::fold($v) === $anchor) { $headerRow = $r; break 2; }
            }
        }
        if ($headerRow === null) throw new RuntimeException('header row not found (no "' . ($spec['header_anchor'] ?? '') . '" cell)');

        $map = self::mapColumns($cells[$headerRow], (array)$spec['columns']);
        if (!isset($map['name'])) throw new RuntimeException('no name column in the header row');

        $fileName = (string)($meta['file_name'] ?? '');
        $title = trim((string)self::cell($cells, (string)($spec['title_cell'] ?? '')));
        $kind = self::kind($spec, $fileName, $title);
        $listDate = self::listDate($cells, $spec, $fileName, $title, (string)($meta['received'] ?? ''));
        $rules = self::rules($cells, (array)($spec['rules_cells'] ?? []));
        // Only a time-limited list (specials) has an end date; a weekly availability just gets replaced.
        $validUntil = $kind === 'availability' ? null : self::validUntil((array)($spec['valid_until'] ?? []), trim(($meta['body'] ?? '') . "\n" . implode("\n", $rules)), $listDate);

        $rows = [];
        $skipped = 0;
        foreach ($cells as $r => $row) {
            if ($r <= $headerRow) continue;
            $out = [];
            foreach (self::FIELDS as $f) $out[$f] = isset($map[$f]) ? ($row[$map[$f]] ?? null) : null;
            $name = trim((string)($out['name'] ?? ''));
            if ($name === '' || is_numeric($name)) { if ($row) $skipped++; continue; }
            $out['name'] = $name;
            foreach (['product_code', 'size', 'zone', 'category', 'brand', 'mfr_part_no', 'upc', 'unit', 'comment'] as $f) {
                $v = $out[$f];
                if (is_float($v) && floor($v) === $v) $v = (int)$v;
                $out[$f] = ($v === null || trim((string)$v) === '') ? null : trim((string)$v);
            }
            foreach (self::MONEY as $f) $out[$f] = self::money($out[$f]);
            $out['available'] = is_numeric($out['available']) ? (int)round((float)$out['available']) : null;
            if ($out['price'] === null && $out['list_price'] !== null) $out['price'] = $out['list_price'];
            if ($out['price'] === null && $out['special_price'] === null && $out['available'] === null) { $skipped++; continue; }
            $rows[] = $out;
        }
        return ['kind' => $kind, 'list_date' => $listDate, 'title' => $title, 'rules' => $rules, 'valid_until' => $validUntil,
                'columns' => $map, 'rows' => $rows, 'skipped' => $skipped];
    }

    /** field => column letters. Exact folded header match; "@month" matches a month name. PURE. */
    public static function mapColumns(array $headerCells, array $columns): array
    {
        $map = [];
        foreach ($headerCells as $col => $text) {
            if (!is_string($text)) continue;
            $h = self::fold($text);
            foreach ($columns as $field => $syns) {
                if (isset($map[$field]) || !in_array($field, self::FIELDS, true)) continue;
                foreach ((array)$syns as $syn) {
                    $hit = $syn === '@month' ? self::isMonth($h) : self::fold((string)$syn) === $h;
                    if ($hit) { $map[$field] = $col; continue 3; }
                }
            }
        }
        return $map;
    }

    private static function isMonth(string $h): bool
    {
        foreach (self::MONTHS as $m) if ($h === $m || $h === substr($m, 0, 3)) return true;
        return false;
    }

    public static function money($v): ?float
    {
        if (is_string($v)) $v = str_replace([',', '$', ' '], '', $v);
        return is_numeric($v) ? round((float)$v, 2) : null;
    }

    /** "B3" → value. PURE. */
    public static function cell(array $cells, string $ref)
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper(trim($ref)), $m)) return null;
        return $cells[(int)$m[2]][$m[1]] ?? null;
    }

    /** Text of every non-empty cell in "G7:G12" / "B6" ranges, in order. PURE. */
    public static function rules(array $cells, array $ranges): array
    {
        $out = [];
        foreach ($ranges as $range) {
            $parts = explode(':', strtoupper((string)$range));
            if (!preg_match('/^([A-Z]+)(\d+)$/', $parts[0], $a)) continue;
            $b = isset($parts[1]) && preg_match('/^([A-Z]+)(\d+)$/', $parts[1], $mb) ? $mb : $a;
            $c1 = XlsxReader::colIndex($a[1]);
            $c2 = XlsxReader::colIndex($b[1]);
            for ($r = (int)$a[2]; $r <= (int)$b[2]; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    $v = $cells[$r][SheetReader::letters($c)] ?? null;
                    if (is_string($v) && trim($v) !== '') $out[] = trim((string)preg_replace('/\s+/', ' ', $v));
                }
            }
        }
        return $out;
    }

    private static function rx(string $pattern): string
    {
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }

    public static function kind(array $spec, string $fileName, string $title): string
    {
        foreach ((array)($spec['kinds'] ?? []) as $k) {
            foreach (['file' => $fileName, 'title' => $title] as $where => $text) {
                if (!empty($k[$where]) && $text !== '' && preg_match(self::rx((string)$k[$where]), $text)) return (string)$k['kind'];
            }
        }
        return 'availability';
    }

    /** The list's date: date cell (serial or text) → date in the file name → "2026 October" title → received → today. */
    public static function listDate(array $cells, array $spec, string $fileName, string $title, string $received = ''): string
    {
        $v = self::cell($cells, (string)($spec['date_cell'] ?? ''));
        if (is_int($v) || is_float($v)) {
            $d = XlsxReader::excelDate($v);
            if ($d) return $d;
        } elseif (is_string($v) && ($d = self::textDate($v))) {
            return $d;
        }
        if (preg_match('/(20\d\d)[_\-.](\d{1,2})[_\-.](\d{1,2})/', $fileName, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/\b(20\d\d)\s+([a-z]+)\b|\b([a-z]+)\s+(20\d\d)\b/i', $title, $m)) {
            $y = (int)($m[1] ?: $m[4]);
            $mon = self::monthNo($m[2] ?: $m[3]);
            if ($mon) return sprintf('%04d-%02d-01', $y, $mon);
        }
        if ($received !== '' && ($t = strtotime($received))) return date('Y-m-d', $t);
        return date('Y-m-d');
    }

    /** "Availability as of October 5, 2026" / "2026-10-05" → Y-m-d, or null. PURE. */
    public static function textDate(string $s): ?string
    {
        if (preg_match('/(20\d\d)-(\d{2})-(\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
        if (preg_match('/([a-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(20\d\d)/i', $s, $m) && ($mon = self::monthNo($m[1])) && checkdate($mon, (int)$m[2], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $mon, $m[2]);
        }
        return null;
    }

    public static function monthNo(string $s): ?int
    {
        $s = strtolower(rtrim($s, '.'));
        if (strlen($s) < 3) return null;
        foreach (self::MONTHS as $i => $m) if (strpos($m, $s) === 0) return $i + 1;
        return null;
    }

    /**
     * "shipping by October 30th" → 2026-10-30; "shipping in October" → 2026-10-31. The year is
     * the list's; a date more than a month before the list rolls to next year. PURE.
     */
    public static function validUntil(array $patterns, string $text, string $listDate): ?string
    {
        if ($text === '') return null;
        $year = (int)substr($listDate, 0, 4);
        foreach ($patterns as $p) {
            if (!preg_match(self::rx((string)$p), $text, $m) || !isset($m[1])) continue;
            if (!preg_match('/^([a-z]+)\.?(?:\s+(\d{1,2}))?/i', trim($m[1]), $mm)) continue;
            $mon = self::monthNo($mm[1]);
            if (!$mon) continue;
            $day = isset($mm[2]) && $mm[2] !== '' ? (int)$mm[2] : (int)date('t', mktime(0, 0, 0, $mon, 1, $year));
            if (!checkdate($mon, $day, $year)) continue;
            $d = sprintf('%04d-%02d-%02d', $year, $mon, $day);
            if (strtotime($d) < strtotime($listDate . ' -31 days')) $d = sprintf('%04d-%02d-%02d', $year + 1, $mon, $day);
            return $d;
        }
        return null;
    }
}
