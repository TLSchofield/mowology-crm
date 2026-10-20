<?php
/**
 * SupplierFormatInferrer — works out how an UNKNOWN supplier's price/availability sheet reads, so a
 * new supplier's emailed list imports without someone writing a supplier_list_formats row by hand
 * (Tim, 2026-10-20: "these email lists are meant to be imported automatically").
 *
 * PURE: cells in, a spec out, no database. SHARED code (Ittai): nothing here knows a supplier or a
 * trade. The spec it returns is exactly what SupplierListParser::spec()/parse() already read, and it is
 * proved by a round trip through that parser before it is offered — an inferred spec the parser cannot
 * read back is a failure with a reason, never a guess that imports wrong prices.
 *
 * How: find the header row (the row whose cells match the most known field words and are followed by
 * data rows with a number in a price/availability column), map each header to a field, keep the real
 * header text as the synonym so next week's file (same wording) reads the same way.
 */
declare(strict_types=1);

require_once __DIR__ . '/SupplierListParser.php';

class SupplierFormatInferrer
{
    /** Rows to look at for the header (titles, notes and logos sit above it). */
    private const HEADER_SEARCH_ROWS = 60;
    /** Data rows used to check a candidate header. */
    private const PROBE_ROWS = 12;

    /**
     * Ordered: the first pattern a (folded) header matches wins, so the specific words come first.
     * Folded headers are lower case, letters/digits/# only, single spaces.
     */
    private const RULES = [
        ['special_price', '/\b(special|sale|promo|promotion|clearance|discount)\b/'],
        ['list_price',    '/\b(list|retail|msrp|srp|regular)\b/'],
        ['upc',           '/\b(upc|ean|barcode|gtin)\b/'],
        ['mfr_part_no',   '/\b(mfr|mfg|manufacturer|vendor)\b.*\b(part|sku|no|number|code|#)\b|\bpart (no|number|#)\b/'],
        ['available',     '/\b(avail|available|availability|qty|quantity|stock|on hand|inventory|in stock|units)\b/'],
        ['price',         '/\b(price|trade|wholesale|cost|net|contractor|your|each|unit price)\b/'],
        ['product_code',  '/\b(sku|code|id|productid|item (no|number|#)|item#|part|number|#)\b|^no$/'],
        ['zone',          '/\b(zone|hardiness)\b/'],
        ['size',          '/\b(size|pot|container|caliper|height|pack|format|cal)\b/'],
        ['brand',         '/\b(brand|make)\b/'],
        ['category',      '/\b(category|type|group|dept|department|class|section)\b/'],
        ['comment',       '/\b(comment|comments|note|notes|remark|remarks)\b/'],
        ['unit',          '/^(unit|uom|per)$/'],
        ['name',          '/\b(name|description|desc|item|plant|botanical|product|variety|material|species|cultivar)\b/'],
    ];

    /** What a list must have to be worth importing: a name and at least one money/availability column. */
    private const NEED_ONE_OF = ['price', 'list_price', 'special_price', 'available'];

    /**
     * @param array  $cells    [row => [col => value]] from SheetReader / PdfTableReader
     * @param string $fileName used only to spot a specials list
     * @return array{ok: bool, reason: string, spec: ?array, header_row: ?int, mapped: array<string,string>,
     *               unmapped: string[], confidence: float, rows: int, sample: array<int,array>}
     */
    public static function infer(array $cells, string $fileName = ''): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'reason' => $why, 'spec' => null, 'header_row' => null,
                                                   'mapped' => [], 'unmapped' => [], 'confidence' => 0.0, 'rows' => 0, 'sample' => []];
        if (!$cells) return $fail('the file has no cells');

        $best = null;
        $seen = 0;
        foreach ($cells as $r => $row) {
            if (++$seen > self::HEADER_SEARCH_ROWS) break;
            $cand = self::candidate($cells, (int)$r, $row);
            if ($cand && ($best === null || $cand['score'] > $best['score'])) $best = $cand;
        }
        if ($best === null) return $fail('no header row found (need a name column and a price or availability column)');

        $columns = [];
        foreach ($best['fields'] as $field => $col) {
            $text = trim((string)$cells[$best['row']][$col]);
            $columns[$field] = [$text];
        }
        // A month-named column ("October") is the specials price; let next month's file read too.
        foreach ($best['months'] as $col) {
            $columns['special_price'] = array_values(array_unique(array_merge($columns['special_price'] ?? [], ['@month'])));
        }

        $headerTexts = [];
        foreach ($cells[$best['row']] as $col => $v) if (is_string($v) && trim($v) !== '') $headerTexts[$col] = trim($v);

        // The anchor is the header cell the parser will search for. Try the name/code/price headers —
        // the first whose text appears ONLY in the header row (a title above must not steal the match).
        $order = array_filter(array_map(fn($f) => $best['fields'][$f] ?? null, ['product_code', 'name', 'price', 'available', 'size']), fn($c) => $c !== null);
        $spec = null;
        $parsed = null;
        foreach ($order as $col) {
            $text = trim((string)$cells[$best['row']][$col]);
            $try = ['header_anchor' => $text, 'columns' => $columns, 'inferred' => true];
            $kinds = self::kinds($fileName);
            if ($kinds) $try['kinds'] = $kinds;
            try {
                $p = SupplierListParser::parse($cells, $try, ['file_name' => $fileName]);
            } catch (Throwable $e) {
                continue;
            }
            if (!$p['rows']) continue;
            // The parser must have found the SAME header row we inferred.
            if (($p['columns']['name'] ?? null) !== ($best['fields']['name'] ?? null)) continue;
            $spec = $try;
            $parsed = $p;
            break;
        }
        if ($spec === null) return $fail('a header row was guessed but the file does not read back from it');

        $mappedCols = array_values($best['fields']);
        $unmapped = [];
        foreach ($headerTexts as $col => $t) if (!in_array($col, $mappedCols, true) && !in_array($col, $best['months'], true)) $unmapped[] = $t;
        $mapped = [];
        foreach ($best['fields'] as $f => $col) $mapped[$f] = (string)$headerTexts[$col];

        return ['ok' => true, 'reason' => '', 'spec' => $spec, 'header_row' => $best['row'], 'mapped' => $mapped,
                'unmapped' => $unmapped, 'confidence' => self::confidence($best, $parsed), 'rows' => count($parsed['rows']),
                'sample' => array_slice($parsed['rows'], 0, 5)];
    }

    /** Score one row as a header. Null when it cannot be one. */
    private static function candidate(array $cells, int $r, array $row): ?array
    {
        $fields = [];
        $months = [];
        $texts = 0;
        foreach ($row as $col => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $texts++;
            $h = SupplierListParser::fold($v);
            if ($h === '' || is_numeric($h)) continue;
            if (self::isMonth($h)) { $months[] = $col; continue; }
            $f = self::classify($h);
            if ($f !== null && !isset($fields[$f])) $fields[$f] = $col;
        }
        if ($texts < 2 || !isset($fields['name'])) return null;
        $money = array_values(array_intersect(self::NEED_ONE_OF, array_keys($fields)));
        if (!$money && !$months) return null;

        // Data below must look like data: names in the name column, numbers in a money/availability column.
        $probe = 0;
        $named = 0;
        $numeric = 0;
        $moneyCols = array_map(fn($f) => $fields[$f], $money);
        foreach ($months as $m) $moneyCols[] = $m;
        foreach ($cells as $rr => $dataRow) {
            if ($rr <= $r) continue;
            if (++$probe > self::PROBE_ROWS) break;
            $n = $dataRow[$fields['name']] ?? null;
            if (is_string($n) && trim($n) !== '' && !is_numeric(trim($n))) $named++;
            foreach ($moneyCols as $mc) {
                if (is_numeric(is_string($dataRow[$mc] ?? null) ? str_replace([',', '$', ' '], '', $dataRow[$mc]) : ($dataRow[$mc] ?? null))) { $numeric++; break; }
            }
        }
        $probed = min($probe, self::PROBE_ROWS);
        if ($probed < 2 || $named < max(2, (int)floor($probed / 2)) || $numeric < max(1, (int)floor($probed / 3))) return null;

        return ['row' => $r, 'fields' => $fields, 'months' => $months, 'score' => count($fields) + count($months),
                'named' => $named, 'numeric' => $numeric, 'probed' => $probed];
    }

    /** A folded header → the field it names, or null. PURE. */
    public static function classify(string $folded): ?string
    {
        foreach (self::RULES as [$field, $rx]) if (preg_match($rx, $folded)) return $field;
        return null;
    }

    private static function isMonth(string $h): bool
    {
        static $m = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        foreach ($m as $x) if ($h === $x || $h === substr($x, 0, 3)) return true;
        return false;
    }

    private static function kinds(string $fileName): array
    {
        return preg_match('/special|sale|promo|clearance/i', $fileName)
            ? [['kind' => 'specials', 'file' => 'special|sale|promo|clearance', 'title' => 'special|sale|promo|clearance']]
            : [];
    }

    /** 0..1 — how far to trust the reading. Shown on the check banner; never a gate by itself. */
    private static function confidence(array $best, array $parsed): float
    {
        $f = $best['fields'];
        $c = 0.35;                                                         // a name + a money/availability column exist
        if (isset($f['price']) || isset($f['special_price'])) $c += 0.2;   // a real price, not only a quantity
        if (isset($f['product_code'])) $c += 0.1;
        if (isset($f['size'])) $c += 0.1;
        if ($best['probed'] > 0) $c += 0.15 * ($best['numeric'] / $best['probed']);
        $total = count($parsed['rows']) + (int)($parsed['skipped'] ?? 0);
        if ($total > 0) $c += 0.1 * (count($parsed['rows']) / $total);
        return round(min(1.0, $c), 2);
    }
}
