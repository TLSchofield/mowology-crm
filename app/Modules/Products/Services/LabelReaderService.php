<?php
/**
 * LabelReaderService — reads the OCR text of a product bag or a machine nameplate, for free.
 *
 * Pure (no DB, no network): give it the text, get back what the label says —
 *   kind          product | machine (machine cues: model / serial / volts / cc / HP / Ah /
 *                 engine words / a known maker such as Honda, Stihl, EGO…)
 *   brand, maker, name, display_name, sku (Item#), model, serial, size (5 kg, 10 L, 40 lb),
 *   composition   the percent lines ("50% Turf Type Perennial Ryegrass")
 *   batch         lot / batch / B#
 *   address       the maker's street address
 *   care, safety  storage / handling / caution lines — ONLY what the label prints. Nothing is
 *                 ever added from general knowledge: a seed bag that says nothing about storage
 *                 gets no storage advice.
 *   equipment_class, power_source, voltage  (machines)
 *
 * Also home to the small normalisers the product matching shares (normName, sizeOf, words),
 * so "2 B. 5K Seed" and "Richardson Sun & Shade Lawn Seed 5 kg" compare on the same terms.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class LabelReaderService
{
    /** Makers whose name alone says "machine". */
    public const MACHINE_MAKERS = [
        'honda', 'stihl', 'husqvarna', 'ego', 'toro', 'kawasaki', 'echo', 'makita', 'dewalt', 'ryobi',
        'briggs', 'stratton', 'kohler', 'greenworks', 'milwaukee', 'oregon', 'shindaiwa', 'redmax',
        'exmark', 'scag', 'john deere', 'cub cadet', 'craftsman', 'troy-bilt', 'snapper', 'worx',
        'bosch', 'black+decker', 'black & decker', 'kobalt', 'gravely', 'ariens', 'mtd', 'chervon',
        'yamaha', 'subaru', 'kubota', 'billy goat', 'little wonder', 'tanaka', 'maruyama',
    ];
    /** Trailing words dropped when a brand goes in front of a product name ("Richardson Seed" → "Richardson"). */
    public const BRAND_GENERIC = ['seed', 'seeds', 'products', 'product', 'company', 'co', 'horticulture', 'industries', 'brands', 'brand', 'canada', 'inc', 'ltd'];
    public const STOP = ['the', 'and', 'of', 'a', 'an', 'for', 'with', 'b', 'bag', 'bags', 'ea', 'each', 'x', 'pc', 'pcs', 'qty', 'no', 'type', 'item'];

    // ── Normalisers (shared with ProductProposalService) ────────────────────

    /** "5 KG", "5kg", "5K", "5 kgs" → "5kg"; "1 yd", "1 yard" → "1yd"; null when there is no size. */
    public static function sizeOf(string $s): ?string
    {
        if (!preg_match('/(?<![\w.])(\d+(?:[.,]\d+)?)\s*(kgs?|kilos?|k|g|grams?|lbs?|pounds?|l|litres?|liters?|ml|yds?|yards?|cu\.?\s?ft|oz|gal|gallons?)(?![a-z])/i', $s, $m)) {
            return null;
        }
        $n = rtrim(rtrim(str_replace(',', '.', $m[1]), '0'), '.');
        if (strpos($m[1], '.') === false && strpos($m[1], ',') === false) $n = $m[1];
        $u = strtolower(preg_replace('/[\s.]/', '', $m[2]));
        $map = [
            'kg' => 'kg', 'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'k' => 'kg',
            'g' => 'g', 'gram' => 'g', 'grams' => 'g',
            'lb' => 'lb', 'lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
            'l' => 'l', 'litre' => 'l', 'litres' => 'l', 'liter' => 'l', 'liters' => 'l', 'ml' => 'ml',
            'yd' => 'yd', 'yds' => 'yd', 'yard' => 'yd', 'yards' => 'yd', 'cuft' => 'cuft',
            'oz' => 'oz', 'gal' => 'gal', 'gallon' => 'gal', 'gallons' => 'gal',
        ];
        return $n . ($map[$u] ?? $u);
    }

    /** Human form of a size: "5kg" → "5 kg". */
    public static function sizeLabel(?string $size): string
    {
        if (!$size || !preg_match('/^([\d.]+)([a-z]+)$/', $size, $m)) return '';
        return $m[1] . ' ' . ($m[2] === 'l' ? 'L' : $m[2]);
    }

    /** Meaningful lowercase words (no sizes, numbers, stopwords, 1–2 letter crumbs). */
    public static function words(string $s): array
    {
        $s = strtolower(str_replace(['&', '’', "'"], [' ', '', ''], $s));
        $s = preg_replace('/(?<![\w.])\d+(?:[.,]\d+)?\s*(kgs?|kilos?|k|g|lbs?|l|litres?|liters?|ml|yds?|yards?|oz|gal)(?![a-z])/', ' ', $s);
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $s) as $w) {
            if (strlen($w) < 3 || ctype_digit($w) || in_array($w, self::STOP, true)) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }

    /** Normalised key for "same item" comparisons: sorted words + size. */
    public static function normName(string $s): string
    {
        $w = self::words($s);
        sort($w);
        $size = self::sizeOf($s);
        return trim(implode(' ', $w) . ($size ? ' ' . $size : ''));
    }

    /** Upper-case alphanumeric tokens (SKU look-ups: "CBM", "TL02100350"). */
    public static function tokens(string $s): array
    {
        return array_values(array_filter(preg_split('/[^A-Z0-9\-]+/', strtoupper($s)), fn($t) => $t !== ''));
    }

    // ── The reader ──────────────────────────────────────────────────────────

    public static function read(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) as $l) {
            $l = trim(preg_replace('/\s+/', ' ', $l));
            if ($l !== '') $lines[] = $l;
        }
        $all = implode("\n", $lines);
        $out = [
            'kind' => 'product', 'confidence' => 0.0, 'brand' => null, 'maker' => null, 'name' => null, 'display_name' => null,
            'sku' => null, 'model' => null, 'serial' => null, 'size' => null, 'size_label' => '', 'composition' => [],
            'batch' => null, 'address' => null, 'care' => [], 'safety' => [], 'grade' => null,
            'equipment_class' => null, 'power_source' => null, 'voltage' => null, 'cues' => [],
        ];
        if (!$lines) return $out;

        $used = [];
        foreach ($lines as $i => $l) {
            // Item# / SKU / Part no.
            if (!$out['sku'] && preg_match('/\b(?:item\s*(?:#|no\.?|number)|sku|art(?:icle)?\s*(?:#|no\.?)|part\s*(?:#|no\.?)|cat(?:alog)?\s*(?:#|no\.?))\s*:?\s*([A-Z0-9][A-Z0-9\-]{3,})/i', $l, $m)) {
                $out['sku'] = strtoupper($m[1]); $used[$i] = true;
            }
            // Model
            if (!$out['model'] && preg_match('/\b(?:model|mod\.|mdl)\s*(?:no\.?|#|number)?\s*:?\s*([A-Z0-9][A-Z0-9\-\/]{2,})/i', $l, $m)) {
                $out['model'] = strtoupper($m[1]); $used[$i] = true; $out['cues'][] = 'model';
            }
            // Serial
            if (!$out['serial'] && preg_match('/\b(?:serial\s*(?:no\.?|number|#)?|s\s*\/\s*n|sn)\s*:?\s*([A-Z0-9][A-Z0-9\-]{3,})/i', $l, $m)) {
                $out['serial'] = strtoupper($m[1]); $used[$i] = true; $out['cues'][] = 'serial';
            }
            // Batch / lot
            if (!$out['batch'] && preg_match('/\b(?:batch|lot|b#|l#)\s*(?:no\.?|#)?\s*:?\s*([A-Z0-9][A-Z0-9\-]{3,})/i', $l, $m)) {
                $out['batch'] = strtoupper($m[1]); $used[$i] = true;
            }
            // Weight / volume
            if (!$out['size'] && preg_match('/\b(?:wt|net\s*wt|net\s*weight|weight|net|vol(?:ume)?|contents?)\b/i', $l) && ($s = self::sizeOf($l))) {
                $out['size'] = $s; $used[$i] = true;
            }
            // Composition
            if (preg_match('/^(\d{1,3}(?:[.,]\d+)?)\s*%\s*(.+)$/', $l, $m)) {
                $out['composition'][] = ['pct' => (float)str_replace(',', '.', $m[1]), 'what' => self::tidy($m[2])];
                $used[$i] = true;
            }
            // Grade (seed / fertiliser)
            if (!$out['grade'] && preg_match('/\bcanada\s*no\.?\s*\d\b.*$/i', $l)) {
                $out['grade'] = self::tidy($l); $used[$i] = true;
            }
            // Volts / power
            if (preg_match('/\b(\d{1,3}(?:\.\d)?)\s*(?:v|volts?|vdc|v\s*dc|v\s*max)\b/i', $l, $m)) {
                $out['voltage'] = $out['voltage'] ?: $m[1] . 'V'; $out['cues'][] = 'volts';
            }
            if (preg_match('/\b\d+(?:\.\d+)?\s*(?:cc|cm3|hp|ah|rpm|watts?|kw)\b/i', $l)) $out['cues'][] = 'rating';
            if (preg_match('/\b(engine|motor|lithium|li-ion|battery|cordless|2-cycle|4-cycle|2-stroke|4-stroke|spark plug)\b/i', $l)) $out['cues'][] = 'engine';
            // Care / safety — the label's own words only
            if (preg_match('/\b(store|storage|keep (?:dry|cool|away|out|in|sealed|closed)|protect from|do not (?:freeze|store|stack)|shelf life|best before|use by)\b/i', $l)) {
                $out['care'][] = self::sentence($l); $used[$i] = true;
            } elseif (preg_match('/\b(caution|warning|danger|keep out of reach|avoid contact|wear (?:gloves|eye)|harmful|toxic|first aid|treated seed|do not (?:use|eat|feed|swallow|inhale))\b/i', $l)) {
                $out['safety'][] = self::sentence($l); $used[$i] = true;
            }
        }
        if (!$out['size'] && ($s = self::sizeOf($all))) $out['size'] = $s;
        $out['size_label'] = self::sizeLabel($out['size']);

        // Address + maker (company line: Inc / Ltd / Corp …)
        $makerIdx = null;
        foreach ($lines as $i => $l) {
            if ($out['address'] === null && preg_match('/^\d{1,6}\s+[A-Za-z].*\b(road|rd|street|st|avenue|ave|way|drive|dr|blvd|boulevard|highway|hwy|lane|ln|crescent|cres)\b/i', $l)) {
                $out['address'] = self::tidy($l); $used[$i] = true;
            } elseif ($makerIdx === null && preg_match('/\b(inc|ltd|limited|corp|corporation|llc|co\.|gmbh|s\.a\.)(\.|\b)/i', $l)) {
                $out['maker'] = self::tidy(preg_replace('/^(?:made|distributed|manufactured|packed)\s+(?:for|by)\s*:?\s*/i', '', $l));
                $makerIdx = $i; $used[$i] = true;
            }
        }

        // Brand: a known machine maker anywhere, else the short line just above the maker line.
        $lower = strtolower($all);
        foreach (self::MACHINE_MAKERS as $mk) {
            if (preg_match('/(?<![a-z])' . preg_quote($mk, '/') . '(?![a-z])/', $lower)) {
                $out['brand'] = self::brandCase($mk);
                $out['cues'][] = 'maker';
                break;
            }
        }
        if (!$out['brand'] && $makerIdx !== null) {
            for ($j = $makerIdx - 1; $j >= max(0, $makerIdx - 2); $j--) {
                $c = $lines[$j];
                if (!isset($used[$j]) && !preg_match('/\d/', $c) && str_word_count($c) <= 3) {
                    $out['brand'] = self::tidy($c); $used[$j] = true; break;
                }
            }
        }
        if (!$out['brand'] && $out['maker']) $out['brand'] = trim(preg_replace('/\b(inc|ltd|limited|corp|corporation|llc|co)\b\.?/i', '', $out['maker']));

        // Kind
        $mScore = count(array_unique($out['cues'])) + ($out['serial'] ? 1 : 0) + ($out['model'] ? 1 : 0);
        $pScore = ($out['composition'] ? 2 : 0) + ($out['sku'] ? 1 : 0) + ($out['batch'] ? 1 : 0) + ($out['grade'] ? 1 : 0)
                + (preg_match('/\b(seed|fertili[sz]er|mulch|soil|compost|lime|herbicide|weed|moss|grass|bark|sand|gravel)\b/i', $all) ? 2 : 0)
                + ($out['size'] && preg_match('/kg|lb|l$|g$|ml|yd/', $out['size']) ? 1 : 0);
        $out['kind'] = $mScore >= 2 && $mScore > $pScore ? 'machine' : 'product';
        $out['confidence'] = round(min(1.0, max($mScore, $pScore) / 6), 2);

        // Name: the title lines (top of the label, not already used, no digits/%), up to 3.
        $title = [];
        foreach ($lines as $i => $l) {
            if (isset($used[$i]) || $i === $makerIdx) { if ($title) break; continue; }
            if (preg_match('/\d|%|:|@|www\.|http/i', $l) && !($out['kind'] === 'machine' && preg_match('/^\d+v\b/i', $l))) { if ($title) break; continue; }
            if ($out['brand'] && strcasecmp(trim($l), $out['brand']) === 0) continue;
            if (preg_match('/^(made in|product of|distributed|imported)/i', $l)) continue;
            $title[] = $l;
            if (count($title) >= 3 || $i > 6) break;
        }
        if ($out['kind'] === 'machine') {
            $out['equipment_class'] = self::equipmentClass($all);
            $out['power_source'] = self::powerSource($all, $out['voltage']);
            $what = self::classWord($all);
            $name = trim(($out['brand'] ? $out['brand'] . ' ' : '') . ($out['model'] ?? '') . ($what ? ' ' . $what : ''));
            $out['name'] = $what ?: ($title ? self::titleCase(implode(' ', $title)) : null);
            $out['display_name'] = $name !== '' ? $name : $out['name'];
        } else {
            $name = $title ? self::titleCase(implode(' ', $title)) : null;
            // Drop a brand word the title repeats
            $out['name'] = $name;
            $short = self::brandShort($out['brand']);
            $disp = trim(($short && $name && stripos($name, $short) === false ? $short . ' ' : '') . ($name ?? ''));
            if ($disp !== '' && $out['size_label'] !== '' && stripos($disp, $out['size_label']) === false) $disp .= ' ' . $out['size_label'];
            $out['display_name'] = $disp !== '' ? $disp : null;
        }
        $out['cues'] = array_values(array_unique($out['cues']));
        return $out;
    }

    /** Text Otto shows under "how to look after it" — the label's words, or a plain "nothing printed". */
    public static function careText(array $read): string
    {
        $parts = array_merge($read['care'] ?? [], $read['safety'] ?? []);
        return $parts ? implode(' ', $parts) : '';
    }

    public static function equipmentClass(string $text): string
    {
        $t = strtolower($text);
        if (preg_match('/\bmower|lawnmower|mulching mower\b/', $t)) return 'mower';
        if (preg_match('/\b(trimmer|string trimmer|edger|hedge|brushcutter|brush cutter|weed eater|powerhead)\b/', $t)) return 'trimmer';
        if (preg_match('/\bblower\b/', $t)) return 'blower';
        if (preg_match('/\b(truck|vehicle|van)\b/', $t)) return 'truck';
        if (preg_match('/\b(battery|batteries)\b/', $t) && preg_match('/\b\d+(\.\d+)?\s*ah\b/', $t)) return 'battery_pack';
        return 'other';
    }

    private static function classWord(string $text): ?string
    {
        $t = strtolower($text);
        foreach (['lawn mower' => 'lawn mower', 'mower' => 'mower', 'string trimmer' => 'string trimmer', 'hedge trimmer' => 'hedge trimmer',
                  'trimmer' => 'trimmer', 'edger' => 'edger', 'blower' => 'blower', 'chainsaw' => 'chainsaw', 'battery' => 'battery',
                  'charger' => 'charger', 'pressure washer' => 'pressure washer', 'aerator' => 'aerator', 'dethatcher' => 'dethatcher'] as $k => $v) {
            if (strpos($t, $k) !== false) return $v;
        }
        return null;
    }

    public static function powerSource(string $text, ?string $voltage): string
    {
        $t = strtolower($text);
        if (preg_match('/\bdiesel\b/', $t)) return 'diesel';
        if (preg_match('/\b(\d+\s*cc|engine oil|gasoline|petrol|2-cycle|4-cycle|2-stroke|4-stroke|spark plug|fuel)\b/', $t)) return 'gas';
        if ($voltage || preg_match('/\b(lithium|li-ion|battery|cordless)\b/', $t)) return 'battery';
        if (preg_match('/\b(120\s*v\s*ac|corded|plug)\b/', $t)) return 'electric';
        return 'battery';
    }

    // ── Text helpers ────────────────────────────────────────────────────────

    public static function titleCase(string $s): string
    {
        $s = self::tidy($s);
        if ($s === strtoupper($s) || $s === strtolower($s)) {
            $s = ucwords(strtolower($s), " \t-/(");
            $s = preg_replace_callback("/\\b(No|Of|And|The)\\b/", fn($m) => $m[0], $s);
        }
        return $s;
    }

    public static function brandShort(?string $brand): ?string
    {
        if (!$brand) return null;
        $w = preg_split('/\s+/', trim($brand));
        while (count($w) > 1 && in_array(strtolower(rtrim(end($w), '.')), self::BRAND_GENERIC, true)) array_pop($w);
        return self::titleCase(implode(' ', $w));
    }

    private static function brandCase(string $mk): string
    {
        $special = ['ego' => 'EGO', 'mtd' => 'MTD', 'dewalt' => 'DeWalt', 'john deere' => 'John Deere', 'black+decker' => 'Black+Decker',
                    'redmax' => 'RedMax', 'troy-bilt' => 'Troy-Bilt'];
        return $special[$mk] ?? ucwords($mk);
    }

    private static function tidy(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', trim($s, " \t·•-:")));
    }

    private static function sentence(string $s): string
    {
        $s = self::tidy($s);
        if ($s === strtoupper($s)) $s = ucfirst(strtolower($s));
        return preg_match('/[.!?]$/', $s) ? $s : $s . '.';
    }
}
