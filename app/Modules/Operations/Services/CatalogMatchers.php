<?php
/**
 * Catalogue matchers — how a supplier's line is turned into match keys, per product family
 * (owner, 2026-10-12). PURE: no database. SHARED code (Ittai).
 *
 * Families are data (catalog_families.matcher), so a new trade picks one of these by name:
 *   botanical   → PlantMatcher: genus / species / 'cultivar' / series (PlantNameNormalizer)
 *   part_number → PartNumberMatcher: UPC/barcode, then manufacturer part no., then brand + name
 *   name        → PartNumberMatcher too (it falls through to brand + name when there are no codes)
 *
 * A matcher's parse() returns the tiers CatalogService tries, in order. Every tier is an exact
 * key lookup; anything fuzzy only ever SUGGESTS (review queue), never links on its own.
 */
declare(strict_types=1);

require_once __DIR__ . '/PlantNameNormalizer.php';

interface CatalogMatcher
{
    /**
     * @param array $item name (required), brand, mfr_part_no, upc, zone…
     * @return array{key: string, lookups: array<int, array{col: string, value: string, how: string, confidence: float}>,
     *               alt_key: ?string, group_key: string, display: string, brand: ?string, mfr_part_no: ?string,
     *               upc: ?string, attrs: array<string,string>, aliases: string[]}
     */
    public function parse(array $item): array;

    /** A catalogue row back into parse() shape, to compare against. */
    public function fromCatalog(array $row): array;

    /** May an exact tier's hit be linked automatically? (e.g. plants: the series must agree) */
    public function accepts(array $item, array $candidate, string $how): bool;

    /** 0..1 likeness of two parse() results — for suggestions only. */
    public function similarity(array $a, array $b): float;

    /** @return array{code: string, height: ?string} */
    public function size(string $raw): array;
}

final class PlantMatcher implements CatalogMatcher
{
    public function parse(array $item): array
    {
        $n = PlantNameNormalizer::parse((string)($item['name'] ?? ''));
        $lookups = [['col' => 'match_key', 'value' => $n['key'], 'how' => 'exact', 'confidence' => 1.0]];
        // Same genus + species + cultivar, series/trade name written differently (or left out).
        if ($n['cultivar_key'] !== null) $lookups[] = ['col' => 'alt_key', 'value' => $n['cultivar_key'], 'how' => 'alt', 'confidence' => 0.9];
        $attrs = array_filter(['genus' => $n['genus'], 'species' => $n['species'], 'cultivar' => $n['cultivar'], 'series' => $n['series']],
                              fn($v) => $v !== '');
        $zone = trim((string)($item['zone'] ?? ''));
        if ($zone !== '') {
            $attrs['zone'] = $zone;
            if (preg_match('/^(\d{1,2})[ab]?$/i', $zone, $m)) $attrs['hardiness_zone_min'] = $m[1];
        }
        return ['key' => $n['key'], 'lookups' => $lookups, 'alt_key' => $n['cultivar_key'], 'group_key' => $n['genus'],
                'display' => $n['display'], 'brand' => null, 'mfr_part_no' => null, 'upc' => null,
                'attrs' => $attrs, 'aliases' => $n['aliases'], 'parsed' => $n];
    }

    public function fromCatalog(array $row): array
    {
        return ['parsed' => PlantNameNormalizer::fromKey((string)$row['match_key'])];
    }

    public function accepts(array $item, array $candidate, string $how): bool
    {
        return $how !== 'alt' || PlantNameNormalizer::seriesCompatible($item['parsed'], $candidate['parsed']);
    }

    public function similarity(array $a, array $b): float
    {
        return PlantNameNormalizer::similarity($a['parsed'], $b['parsed']);
    }

    public function size(string $raw): array
    {
        return PlantNameNormalizer::size($raw);
    }
}

final class PartNumberMatcher implements CatalogMatcher
{
    /** Manufacturer part number → upper case letters and digits ("SCH-40 1/2" → SCH4012). PURE. */
    public static function mpn(?string $s): ?string
    {
        $v = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', (string)$s));
        return $v === '' ? null : substr($v, 0, 80);
    }

    /** UPC/EAN/GTIN: 8, 12, 13 or 14 digits with a valid check digit, as given (no padding). PURE. */
    public static function upc(?string $s): ?string
    {
        $d = (string)preg_replace('/\D+/', '', (string)$s);
        if (!in_array(strlen($d), [8, 12, 13, 14], true)) return null;
        $sum = 0;
        $digits = array_reverse(str_split(substr($d, 0, -1)));
        foreach ($digits as $i => $c) $sum += (int)$c * ($i % 2 === 0 ? 3 : 1);
        return ((10 - $sum % 10) % 10) === (int)substr($d, -1) ? $d : null;
    }

    public function parse(array $item): array
    {
        $name = trim((string)($item['name'] ?? ''));
        $brand = PlantNameNormalizer::fold((string)($item['brand'] ?? ''));
        $mpn = self::mpn($item['mfr_part_no'] ?? null);
        $upc = self::upc($item['upc'] ?? null);
        $fname = PlantNameNormalizer::fold($name);
        $bn = 'bn:' . $brand . '|' . $fname;
        $key = $upc ? 'upc:' . $upc : ($mpn ? 'mpn:' . $brand . ':' . $mpn : $bn);
        $lookups = [];
        if ($upc) $lookups[] = ['col' => 'upc', 'value' => $upc, 'how' => 'upc', 'confidence' => 1.0];
        if ($mpn) $lookups[] = ['col' => 'mfr_part_no', 'value' => $mpn, 'how' => 'mpn', 'confidence' => 0.95];
        $lookups[] = ['col' => 'match_key', 'value' => $key, 'how' => 'exact', 'confidence' => 1.0];
        if ($brand !== '' && $key !== $bn) $lookups[] = ['col' => 'alt_key', 'value' => $bn, 'how' => 'alt', 'confidence' => 0.9];
        $words = explode(' ', $fname);
        return ['key' => $key, 'lookups' => $lookups, 'alt_key' => $brand !== '' ? $bn : null,
                'group_key' => $brand !== '' ? $brand : (string)($words[0] ?? ''), 'display' => $name,
                'brand' => trim((string)($item['brand'] ?? '')) ?: null, 'mfr_part_no' => $mpn, 'upc' => $upc,
                'attrs' => [], 'aliases' => [], 'parsed' => ['brand' => $brand, 'name' => $fname]];
    }

    public function fromCatalog(array $row): array
    {
        return $this->parse(['name' => $row['canonical_name'] ?? '', 'brand' => $row['brand'] ?? null,
                             'mfr_part_no' => $row['mfr_part_no'] ?? null, 'upc' => $row['upc'] ?? null]);
    }

    /** A part number shared by two brands is two products. */
    public function accepts(array $item, array $candidate, string $how): bool
    {
        if ($how !== 'mpn') return true;
        $x = $item['parsed']['brand'];
        $y = $candidate['parsed']['brand'];
        return $x === '' || $y === '' || $x === $y;
    }

    public function similarity(array $a, array $b): float
    {
        $x = $a['parsed'];
        $y = $b['parsed'];
        if ($x['brand'] !== '' && $y['brand'] !== '' && $x['brand'] !== $y['brand']) return 0.0;
        if ($x['name'] === '' || $y['name'] === '') return 0.0;
        similar_text($x['name'], $y['name'], $pct);
        return round($pct / 100, 3);
    }

    public function size(string $raw): array
    {
        $s = strtolower(trim($raw));
        $s = str_replace(['"', "''", "\u{201D}", 'inches', 'inch'], 'in', $s);
        if (preg_match('/^(\d+\s+)?(\d+)\s*\/\s*(\d+)\s*(?:in)?(?![0-9])/', $s, $m)) {
            return ['code' => trim($m[1]) . ($m[1] !== '' ? '-' : '') . $m[2] . '/' . $m[3] . 'in', 'height' => null];
        }
        if (preg_match('/^(?:#\s*)?(\d+)\s*(?:awg|ga|gauge)\b/', $s, $m)) return ['code' => $m[1] . 'awg', 'height' => null];
        $code = trim((string)preg_replace('/[^a-z0-9\/.]+/', '-', $s), '-');
        return ['code' => $code === '' ? 'each' : substr($code, 0, 40), 'height' => null];
    }
}

final class CatalogMatchers
{
    /** The matcher a family's row names (catalog_families.matcher). Unknown → part number. */
    public static function for(string $matcher): CatalogMatcher
    {
        return $matcher === 'botanical' ? new PlantMatcher() : new PartNumberMatcher();
    }
}
