<?php
/**
 * SupplierSearchService — "japanese maple #5" → who has it, cheapest first (owner, 2026-10-19:
 * "for any plant or material, the cheapest supplier that has it, how many they have, and how far the
 * yard is from the job"; and quote lines priced from it).
 *
 * Reads only the LATEST list from each supplier (supplier_price_lists, kind availability — a supplier's
 * current list), so an old price never wins. Words match the supplier's name for the item, its common
 * name (Specimen puts it in the comment) and the shared catalogue's names; a size in the query
 * ("#5", "5 gal", "2m", "6cm") narrows to that catalogue size code. Results are grouped by catalogue
 * product + size, each with every supplier's offer: price, stock (null = the list has no stock column),
 * the list's date and age, the order rules (minimums), and — when a job is given — about how far that
 * supplier's yard is (straight line × the Closer's detour at its speed: an ESTIMATE, labelled so).
 *
 * Selling price: plants = list cost + BC PST (7%, paid on purchase — installed plants are a contractor's own
 * cost, so no PST is charged to the client; Tim 2026-10-19, ops_settings supplier_plant_pst_pct) and then the
 * plant markup (35%, ops_settings supplier_plant_markup_pct).
 * Bulk materials (family hardscape: soil, mulch, gravel…) are NOT marked up here: Otto and Sam price them
 * installed (MulchPricingService) — the search says so and hands the cost on.
 * Read-only. Global-namespace service: require_once the file.
 */
require_once __DIR__ . '/CatalogService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/DriveMinutesAdded.php';

class SupplierSearchService
{
    public const PLANT_MARKUP_PCT = 35.0;
    public const PLANT_PST_PCT = 7.0;
    public const STALE_DAYS = 30;
    public const MAX_ROWS = 600;
    private const STOP = ['a', 'an', 'the', 'pot', 'pots', 'plant', 'plants', 'size', 'of', 'in'];

    private PDO $db;
    private int $siteId;
    private CatalogService $catalog;

    public function __construct(PDO $db, int $siteId = 1, ?CatalogService $catalog = null)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->catalog = $catalog ?? new CatalogService($db, $siteId);
    }

    /**
     * "Japanese Maple #5" → words ['japanese', 'maple'] + size '#5'. Sizes: #N / No.N / N gal → pot,
     * N m → height, N cm → caliper or round pot. PURE.
     * @return array{words: string[], size: ?string}
     */
    public static function parseQuery(string $q): array
    {
        $q = ' ' . strtolower(trim(preg_replace('/\s+/', ' ', $q))) . ' ';
        $size = null;
        $pats = [
            '/\s(?:#|no\.?\s*)(\d{1,2})(?:\s*pot)?(?=\s)/' => static fn($m) => '#' . $m[1],
            '/\s(\d{1,2})\s*(?:gal|gallon|gallons|g)(?=\s)/' => static fn($m) => '#' . $m[1],
            '/\s(\d+(?:\.\d+)?)\s*m(?=\s)/' => static fn($m) => sprintf('%.1fM', (float)$m[1]),   // "2m" → "2.0M", as the lists write it
            '/\s(\d{1,2})\s*cm(?:\s*cal)?(?=\s)/' => static fn($m) => $m[1] . 'CM CAL',
        ];
        foreach ($pats as $rx => $fn) {
            if (preg_match($rx, $q, $m)) { $size = $fn($m); $q = (string)preg_replace($rx, ' ', $q, 1); break; }
        }
        $words = [];
        foreach (preg_split('/[^a-z0-9\']+/', $q) ?: [] as $w) {
            if (strlen($w) < 2 || in_array($w, self::STOP, true)) continue;
            $words[] = $w;
        }
        return ['words' => array_values(array_unique($words)), 'size' => $size];
    }

    /** Selling price: cost + the PST paid on it, then the markup, to the cent. PURE. */
    public static function sell(?float $cost, float $markupPct, float $pstPct = 0.0): ?float
    {
        return $cost === null ? null : round($cost * (1 + $pstPct / 100) * (1 + $markupPct / 100), 2);
    }

    /**
     * Botanical stems for a common name, learned from the lists that carry both (Specimen: "ACER PALMATUM"
     * + "Japanese Maple") so a botanical-only list (Inline: "Acer palmatum 'Bloodgood'") is found too.
     * "ACER PALM ATROPURPUREUM" → "acer palm" (genus + the species' first 4 letters: lists abbreviate). PURE.
     * @param string[] $names botanical names of items whose common name matched
     * @return string[] up to 4 stems, most frequent first
     */
    public static function stems(array $names): array
    {
        $n = [];
        foreach ($names as $name) {
            $w = array_values(array_filter(preg_split('/[^a-z]+/', strtolower($name)) ?: [], static fn($x) => $x !== '' && $x !== 'x'));
            if (count($w) < 2 || strlen($w[0]) < 3 || strlen($w[1]) < 4) continue;
            $stem = $w[0] . ' ' . substr($w[1], 0, 4);
            $n[$stem] = ($n[$stem] ?? 0) + 1;
        }
        arsort($n);
        return array_slice(array_keys($n), 0, 4);
    }

    /** About how far by road, and how long, from a straight line (the Closer's detour and speed). PURE. */
    public static function roadEstimate(float $straightKm, float $detour = DriveMinutesAdded::DEFAULT_DETOUR, float $kmh = DriveMinutesAdded::DEFAULT_KMH): array
    {
        $km = $straightKm * $detour;
        return ['km' => round($km, 1), 'minutes' => (int)round($kmh > 0 ? $km / $kmh * 60 : 0)];
    }

    public function plantMarkupPct(): float
    {
        return $this->setting('supplier_plant_markup_pct', self::PLANT_MARKUP_PCT);
    }

    /** PST paid on plants bought to install (0 turns it off). */
    public function plantPstPct(): float
    {
        return $this->setting('supplier_plant_pst_pct', self::PLANT_PST_PCT);
    }

    private function setting(string $key, float $default): float
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return (float)$v;
        } catch (Throwable $e) { /* default */ }
        return $default;
    }

    /** The job's pin, for distances. */
    public function propertyPin(int $propertyId): ?array
    {
        try {
            $s = $this->db->prepare("SELECT id, address, city, latitude, longitude FROM properties WHERE id = ?");
            $s->execute([$propertyId]);
            $p = $s->fetch(PDO::FETCH_ASSOC);
            if (!$p || !is_numeric($p['latitude']) || !is_numeric($p['longitude']) || (float)$p['latitude'] == 0.0) return null;
            return ['id' => (int)$p['id'], 'label' => trim((string)$p['address']), 'lat' => (float)$p['latitude'], 'lng' => (float)$p['longitude']];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{query: string, words: string[], size: ?string, size_code: ?string, job: ?array, results: array, markup_pct: float}
     */
    public function search(string $q, ?int $propertyId = null, int $limit = 12, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $pq = self::parseQuery($q);
        $sizeCode = $pq['size'] !== null ? $this->catalog->size('plants', $pq['size'])['code'] : null;
        $job = $propertyId ? $this->propertyPin($propertyId) : null;
        $markup = $this->plantMarkupPct();
        $pst = $this->plantPstPct();
        $base = ['query' => $q, 'words' => $pq['words'], 'size' => $pq['size'], 'size_code' => $sizeCode, 'job' => $job,
                 'markup_pct' => $markup, 'pst_pct' => $pst, 'results' => []];
        if (!$pq['words']) return $base;

        $latest = "JOIN supplier_price_lists l ON l.id = i.list_id
                JOIN (SELECT supplier_account_id, MAX(list_date) AS d FROM supplier_price_lists WHERE site_id = ? AND kind = 'availability' GROUP BY supplier_account_id) x
                  ON x.supplier_account_id = l.supplier_account_id AND x.d = l.list_date
                LEFT JOIN catalog_products cp ON cp.id = i.catalog_product_id";
        $wordSql = [];
        $wordArgs = [];
        foreach ($pq['words'] as $w) {
            $wordSql[] = "(LOWER(i.name) LIKE ? OR LOWER(COALESCE(i.comment, '')) LIKE ? OR LOWER(COALESCE(cp.canonical_name, '')) LIKE ? OR LOWER(COALESCE(cp.common_names, '')) LIKE ?)";
            $like = '%' . $w . '%';
            array_push($wordArgs, $like, $like, $like, $like);
        }
        // Common name → botanical stems (see stems()), from items whose COMMON name matched every word.
        $stems = [];
        $commonOf = '';
        try {
            $cw = [];
            $ca = [$this->siteId, $this->siteId];
            foreach ($pq['words'] as $w) { $cw[] = "(LOWER(COALESCE(i.comment, '')) LIKE ? OR LOWER(COALESCE(cp.common_names, '')) LIKE ?)"; array_push($ca, '%' . $w . '%', '%' . $w . '%'); }
            $s = $this->db->prepare("SELECT i.name, i.comment FROM supplier_price_items i $latest WHERE i.site_id = ? AND l.kind = 'availability' AND " . implode(' AND ', $cw) . " LIMIT 300");
            $s->execute($ca);
            $hits = $s->fetchAll(PDO::FETCH_ASSOC);
            $stems = self::stems(array_column($hits, 'name'));
        } catch (Throwable $e) { /* words only */ }
        $match = '(' . implode(' AND ', $wordSql) . ')';
        $args = array_merge([$this->siteId, $this->siteId], $wordArgs);
        if ($stems) {
            $match = '(' . $match . ' OR ' . implode(' OR ', array_fill(0, count($stems), 'LOWER(i.name) LIKE ?')) . ')';
            foreach ($stems as $st) $args[] = '%' . $st . '%';
        }
        $where = [$match];
        if ($sizeCode !== null) { $where[] = 'i.size_code = ?'; $args[] = $sizeCode; }
        $base['stems'] = $stems;
        $sql = "SELECT i.id, i.name, i.size, i.size_code, i.price, i.available, i.comment, i.category, i.catalog_product_id, i.name_key,
                       i.product_code, i.price_change, i.prev_price,
                       l.id AS list_id, l.list_date, l.rules_json, l.supplier_account_id, a.name AS supplier_name, a.lat, a.lng, a.address,
                       f.family, cp.canonical_name, cp.common_names
                FROM supplier_price_items i
                JOIN supplier_price_lists l ON l.id = i.list_id
                JOIN (SELECT supplier_account_id, MAX(list_date) AS d FROM supplier_price_lists WHERE site_id = ? AND kind = 'availability' GROUP BY supplier_account_id) x
                  ON x.supplier_account_id = l.supplier_account_id AND x.d = l.list_date
                LEFT JOIN supplier_accounts a ON a.id = l.supplier_account_id
                LEFT JOIN supplier_list_formats f ON f.id = l.format_id
                LEFT JOIN catalog_products cp ON cp.id = i.catalog_product_id
                WHERE i.site_id = ? AND l.kind = 'availability' AND i.price IS NOT NULL AND " . implode(' AND ', $where) . "
                ORDER BY i.price
                LIMIT " . self::MAX_ROWS;
        $s = $this->db->prepare($sql);
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $base['results'] = self::group($rows, $pq['words'], $job, $markup, $today, $limit, $stems, $pst);
        return $base;
    }

    /**
     * Rows → products × size, each with every supplier's best offer, cheapest in stock first. PURE.
     */
    public static function group(array $rows, array $words, ?array $job, float $markupPct, string $today, int $limit, array $stems = [], float $pstPct = 0.0): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $pid = $r['catalog_product_id'] !== null && $r['catalog_product_id'] !== '' ? 'p' . (int)$r['catalog_product_id'] : 'n' . (string)$r['name_key'];
            $key = $pid . '|' . (string)($r['size_code'] ?? $r['size']);
            $sid = (int)$r['supplier_account_id'];
            $price = (float)$r['price'];
            // One offer per supplier: its cheapest line of this product and size (prefer in stock).
            $have = $groups[$key]['offers'][$sid] ?? null;
            $inStock = $r['available'] === null ? null : ((int)$r['available'] > 0);
            if ($have !== null) {
                $better = ($inStock === true && $have['in_stock'] !== true) || ($inStock === $have['in_stock'] && $price < $have['price']);
                if (!$better) continue;
            }
            $age = (int)floor((strtotime($today) - strtotime((string)$r['list_date'])) / 86400);
            $offer = [
                'supplier_id' => $sid, 'supplier' => (string)$r['supplier_name'], 'item' => (string)$r['name'], 'size' => (string)$r['size'],
                'price' => $price, 'available' => $r['available'] === null ? null : (int)$r['available'], 'in_stock' => $inStock,
                'list_id' => (int)$r['list_id'], 'list_date' => (string)$r['list_date'], 'age_days' => $age, 'stale' => $age > self::STALE_DAYS,
                'price_change' => $r['price_change'], 'prev_price' => $r['prev_price'] === null ? null : (float)$r['prev_price'],
                'rules' => self::orderRules((string)($r['rules_json'] ?? '')), 'family' => (string)($r['family'] ?? 'plants'),
                'item_id' => (int)$r['id'], 'distance' => null,
            ];
            if ($job && is_numeric($r['lat']) && is_numeric($r['lng'])) {
                $straight = DriveMinutesAdded::haversineKm($job['lat'], $job['lng'], (float)$r['lat'], (float)$r['lng']);
                $offer['distance'] = self::roadEstimate($straight) + ['estimate' => true, 'yard' => (string)($r['address'] ?? '')];
            }
            if (!isset($groups[$key])) {
                $common = trim((string)($r['comment'] ?? ''));
                $groups[$key] = [
                    'key' => $key, 'name' => (string)($r['canonical_name'] ?: $r['name']), 'common' => $common !== '' && !preg_match('/\d/', $common) ? $common : self::firstCommon((string)($r['common_names'] ?? '')),
                    'size' => (string)$r['size'], 'size_code' => $r['size_code'], 'family' => (string)($r['family'] ?? 'plants'), 'offers' => [],
                ];
            }
            $groups[$key]['offers'][$sid] = $offer;
        }

        foreach ($groups as &$g) {
            $offers = array_values($g['offers']);
            usort($offers, static function ($a, $b) {
                $rank = static fn($o) => $o['in_stock'] === false ? 2 : ($o['stale'] ? 1 : 0);
                return [$rank($a), $a['price']] <=> [$rank($b), $b['price']];
            });
            $g['offers'] = $offers;
            $g['best'] = $offers[0];
            $g['bulk'] = $g['family'] === 'hardscape';
            $g['sell'] = $g['bulk'] ? null : self::sell($offers[0]['price'], $markupPct, $pstPct);
            $g['sell_note'] = $g['bulk'] ? 'Bulk material: Otto and Sam price it installed (haul + spreading), not a flat markup.' : null;
            $hay = strtolower($g['name'] . ' ' . $g['common']);
            $g['score'] = 0;
            foreach ($words as $w) if (strpos($hay, $w) !== false) $g['score']++;
            // Found by its botanical stem only (a botanical-only list): as relevant as a full common-name match.
            if ($g['score'] < count($words)) {
                foreach ($stems as $st) if (strpos(strtolower($g['best']['item']), $st) !== false) { $g['score'] = count($words); break; }
            }
        }
        unset($g);
        $out = array_values($groups);
        usort($out, static fn($a, $b) => [-$a['score'], $a['best']['in_stock'] === false ? 1 : 0, $a['best']['price']]
                                        <=> [-$b['score'], $b['best']['in_stock'] === false ? 1 : 0, $b['best']['price']]);
        return array_slice($out, 0, $limit);
    }

    /** The list's order rules that a buyer must meet ("Order Minimum $750.00"), at most 3. PURE. */
    public static function orderRules(string $json): array
    {
        $rules = json_decode($json, true);
        if (!is_array($rules)) return [];
        $keep = array_values(array_filter(array_map('strval', $rules), static fn($r) => preg_match('/minimum|min\.|order/i', $r)));
        return array_slice($keep, 0, 3);
    }

    private static function firstCommon(string $names): string
    {
        $parts = array_filter(array_map('trim', explode('|', $names)));
        return $parts ? (string)reset($parts) : '';
    }
}
