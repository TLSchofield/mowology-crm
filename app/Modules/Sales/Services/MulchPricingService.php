<?php
/**
 * MulchPricingService — Sam's installed price per yard for mulch / soil / compost at an address.
 *
 * Tim, 2026-10-07: "Sam should be better informed about mulch pricing from the postcode we were at."
 *
 *   cost / yd   = material + haul + labour (+ disposal, only when asked)
 *     material  = median pre-tax unit cost of the last N receipt lines for that material
 *                 (expense_line_items, linked to the product or named like it; bagged lines and
 *                 junk outside $15–$150/yd are left out and listed). Falls back to products.base_cost.
 *                 A line that holds the GST (the lines add up to the receipt TOTAL) has the GST taken out.
 *     haul      = one round trip supplier ↔ the property, per load, ÷ yards per load:
 *                   1. real Otto trips (ops_trip_runs) to that supplier from / back to this property
 *                      (or, for a postcode, any property in it) — median minutes + km;
 *                   2. else straight line × detour at the truck's speed (the Closer's 1.4 / 40 km/h)
 *                      plus the real median time on site at that supplier when Otto has one — ESTIMATE;
 *                   3. else Otto's typical supply run anywhere (ops_cost_facts run:supplier:any) — real,
 *                      but not this address.
 *                 minutes / 60 × hourly cost × crew in the truck + km × truck_cost_per_km.
 *     labour    = spreading minutes per yard (setting) / 60 × hourly cost.
 *     disposal  = Otto's dump-run fact per load ÷ yards per load — only when the job makes waste.
 *   sell / yd   = cost / (1 − target margin), rounded up to the dollar.  GST is NEVER in it — it is
 *                 added on top of the quote, as every quote does.
 *   minimum     = one yard carrying a whole load's haul, at the margin, rounded up to $5
 *                 (or the mulch_min_charge setting when that is higher).
 *
 * Every number carries real: true|false and where it came from, so the dry run can show Tim which
 * inputs are measured and which are assumed. Reuses the Closer's rate card (hourly cost, target
 * margin = overhead_settings.profit_margin, truck speed / detour) and Otto's shared cost facts —
 * nothing is duplicated. Read-only: never writes a price.
 *
 * Settings (ops_settings, migration 1270): mulch_yards_per_load, mulch_spread_min_per_yard,
 * mulch_load_min, mulch_haul_crew, mulch_receipts_n, mulch_min_charge.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CloserPricing.php';
require_once __DIR__ . '/SiteMinutesModel.php';
require_once __DIR__ . '/DriveMinutesAdded.php';
require_once __DIR__ . '/CloserRateCard.php';
require_once dirname(__DIR__, 2) . '/Operations/Services/CostFactsService.php';

class MulchPricingService
{
    /** Material families, checked in this order ("composted bark mulch" is mulch, not compost). */
    public const FAMILIES = [
        'mulch'   => ['label' => 'Bark mulch', 'words' => ['mulch', 'bark', 'cbm', 'wood chip', 'woodchip', 'hog fuel']],
        'soil'    => ['label' => 'Soil',       'words' => ['soil', 'garden mix', 'triple mix', 'planting mix', 'lawn mix', 'top dress']],
        'compost' => ['label' => 'Compost',    'words' => ['compost']],
    ];

    public const DEFAULTS = [
        'mulch_yards_per_load'      => 2.0,   // what the truck carries; Tim's Lawnboy slips are 2 yd
        'mulch_spread_min_per_yard' => 45.0,  // person-minutes to barrow and spread one yard
        'mulch_load_min'            => 15.0,  // time at the yard to load, when Otto has no measured stop
        'mulch_haul_crew'           => 1.0,   // people in the truck on the pickup
        'mulch_receipts_n'          => 6.0,   // last N receipt lines in the median
        'mulch_min_charge'          => 0.0,   // 0 = use the derived one-yard minimum
    ];
    public const SETTING_LABELS = [
        'mulch_yards_per_load'      => 'Yards per load',
        'mulch_spread_min_per_yard' => 'Spreading minutes per yard',
        'mulch_load_min'            => 'Loading minutes at the supplier',
        'mulch_haul_crew'           => 'People in the truck',
        'mulch_receipts_n'          => 'Receipt lines in the median',
        'mulch_min_charge'          => 'Minimum charge',
    ];

    /** Per-yard unit costs outside this band are not bulk yards (bags, delivery fees, OCR junk). */
    public const UNIT_LOW = 15.0;
    public const UNIT_HIGH = 150.0;
    /** Fallback when the Closer's hourly cost is not set — flagged. */
    public const FALLBACK_HOURLY = 45.0;
    public const DEFAULT_PER_KM = 0.70;   // TripCostService::DEFAULT_TRUCK_PER_KM
    public const GST = 0.05;

    protected PDO $db;
    private ?array $settingsCache = null;
    private ?array $cardCache = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Pure (unit tested)
    // ═════════════════════════════════════════════════════════════════════════

    /** Which material family a line / product name / question is about, or null. */
    public static function family(string $text): ?string
    {
        $t = ' ' . strtolower($text) . ' ';
        foreach (self::FAMILIES as $key => $f) {
            foreach ($f['words'] as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '/', $t)) return $key;
            }
        }
        return null;
    }

    /** "Black Mulch 2 yd" → 2.0 ; null when the name carries no yardage. */
    public static function yardsInName(string $name): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:cu\.?\s*)?(?:yd|yds|yard|yards)\b/i', $name, $m)) {
            $v = (float)$m[1];
            return $v > 0 ? $v : null;
        }
        return null;
    }

    /**
     * Pre-tax cost per yard of one receipt line.
     * @param array $line quantity, unit_price, line_total, name, and the receipt's amount / tax_amount / total / lines_sum
     * @return array{unit: ?float, qty: float, gst_removed: bool}
     */
    public static function unitCost(array $line): array
    {
        $qty = (float)($line['quantity'] ?? 1) ?: 1.0;
        $named = self::yardsInName((string)($line['name'] ?? ''));
        if ($named !== null && abs($qty - 1.0) < 0.001) $qty = $named;
        $total = (float)($line['line_total'] ?? 0);
        $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== null ? (float)$line['unit_price'] : 0.0;
        $unit = ($unitPrice > 0 && abs($qty - (float)($line['quantity'] ?? 1)) < 0.001) ? $unitPrice : ($total > 0 ? $total / $qty : null);
        // The lines add up to the receipt TOTAL (tax in) while the receipt has tax → the lines hold the GST.
        $gst = false;
        $tax = (float)($line['tax_amount'] ?? 0);
        $rTotal = (float)($line['receipt_total'] ?? 0);
        $sum = (float)($line['lines_sum'] ?? 0);
        if ($unit !== null && $tax > 0 && $rTotal > 0 && $sum > 0 && abs($sum - $rTotal) <= 0.05) {
            $unit = $unit * ($rTotal - $tax) / $rTotal;
            $gst = true;
        }
        return ['unit' => $unit === null ? null : round($unit, 2), 'qty' => $qty, 'gst_removed' => $gst];
    }

    /**
     * Material cost per yard: median of the most recent N in-band receipt lines, else the product's cost.
     * @param list<array> $lines newest first: id, expense_id, expense_date, vendor, name, quantity, unit_price, line_total, tax_amount, receipt_total, lines_sum
     * @return array{per_yard: ?float, real: bool, source: string, n: int, evidence: list<array>, excluded: list<array>, gst_removed: int, usual_vendor: ?string}
     */
    public static function material(array $lines, ?float $productCost, int $n = 6, float $lo = self::UNIT_LOW, float $hi = self::UNIT_HIGH): array
    {
        $used = []; $excluded = []; $gst = 0;
        foreach ($lines as $l) {
            $u = self::unitCost($l);
            $row = [
                'expense_id' => (int)($l['expense_id'] ?? 0), 'date' => (string)($l['expense_date'] ?? ''),
                'vendor' => (string)($l['vendor'] ?? ''), 'name' => (string)($l['name'] ?? ''),
                'qty' => round($u['qty'], 2), 'per_yard' => $u['unit'], 'gst_removed' => $u['gst_removed'],
            ];
            if ($u['unit'] === null || $u['unit'] < $lo || $u['unit'] > $hi) {
                $row['why'] = $u['unit'] === null ? 'no amount' : ($u['unit'] < $lo ? 'too cheap for a yard (bagged?)' : 'too dear for a yard (delivery / OCR?)');
                $excluded[] = $row;
                continue;
            }
            if (count($used) >= max(1, $n)) continue;
            if ($u['gst_removed']) $gst++;
            $used[] = $row;
        }
        $vendors = [];
        foreach ($used as $r) {
            $v = trim($r['vendor']);
            if ($v !== '') $vendors[$v] = ($vendors[$v] ?? 0) + 1;
        }
        arsort($vendors);
        $usual = $vendors ? (string)array_key_first($vendors) : null;
        if ($used) {
            return ['per_yard' => round((float)CostFactsService::median(array_column($used, 'per_yard')), 2), 'real' => true, 'source' => 'receipts',
                    'n' => count($used), 'evidence' => $used, 'excluded' => $excluded, 'gst_removed' => $gst, 'usual_vendor' => $usual];
        }
        if ($productCost !== null && $productCost > 0) {
            return ['per_yard' => round($productCost, 2), 'real' => false, 'source' => 'product cost', 'n' => 0, 'evidence' => [],
                    'excluded' => $excluded, 'gst_removed' => 0, 'usual_vendor' => null];
        }
        return ['per_yard' => null, 'real' => false, 'source' => 'none', 'n' => 0, 'evidence' => [], 'excluded' => $excluded, 'gst_removed' => 0, 'usual_vendor' => null];
    }

    /**
     * Haul for one load. Tiers: real trips at this place → straight-line estimate → typical supply run.
     *
     * @param list<array> $runs     real ops_trip_runs rows (drive_min, onsite_min, km, run_date, id) to this supplier from/to here
     * @param ?float      $straightKm supplier ↔ property, straight line (null = no coordinates)
     * @param ?array      $placeFact ops_cost_facts run:supplier:place:<id> (for the real time on site)
     * @param ?array      $anyFact   ops_cost_facts run:supplier:any
     * @param array       $k         hourly, crew, per_km, kmh, detour, load_min, yards_per_load
     */
    public static function haul(array $runs, ?float $straightKm, ?array $placeFact, ?array $anyFact, array $k): array
    {
        $ypl = max(0.5, (float)$k['yards_per_load']);
        $crew = max(1.0, (float)$k['crew']);
        $out = ['tier' => 'none', 'real' => false, 'minutes' => null, 'km' => null, 'n' => 0, 'evidence' => [], 'note' => ''];
        if ($runs) {
            $mins = []; $kms = [];
            foreach ($runs as $r) {
                $mins[] = (float)$r['drive_min'] + (float)$r['onsite_min'];
                $kms[] = (float)$r['km'];
                $out['evidence'][] = ['run_id' => (int)($r['id'] ?? 0), 'date' => (string)($r['run_date'] ?? ''),
                                      'minutes' => round((float)$r['drive_min'] + (float)$r['onsite_min'], 1), 'km' => round((float)$r['km'], 2)];
            }
            $out = ['tier' => 'trips', 'real' => true, 'minutes' => round((float)CostFactsService::median($mins), 1),
                    'km' => round((float)CostFactsService::median($kms), 2), 'n' => count($runs), 'evidence' => $out['evidence'],
                    'note' => 'Median of ' . count($runs) . ' real Otto trip' . (count($runs) === 1 ? '' : 's') . ' to this supplier from here'];
        } elseif ($straightKm !== null) {
            $roadKm = $straightKm * 2 * (float)$k['detour'];
            $drive = (float)$k['kmh'] > 0 ? $roadKm / (float)$k['kmh'] * 60 : 0.0;
            $loadReal = $placeFact !== null && (int)($placeFact['sample_n'] ?? 0) > 0 && $placeFact['median_onsite_min'] !== null;
            $load = $loadReal ? (float)$placeFact['median_onsite_min'] : (float)$k['load_min'];
            $out = ['tier' => 'estimate', 'real' => false, 'minutes' => round($drive + $load, 1), 'km' => round($roadKm, 2), 'n' => 0,
                    'evidence' => [], 'load_real' => $loadReal,
                    'note' => 'ESTIMATE: ' . round($straightKm, 1) . ' km straight line × 2 × ' . $k['detour'] . ' detour at ' . $k['kmh'] . ' km/h, + '
                              . round($load) . ' min loading' . ($loadReal ? ' (Otto\'s median of ' . (int)$placeFact['sample_n'] . ' stops there)' : ' (setting)')];
        } elseif ($anyFact !== null && (int)($anyFact['sample_n'] ?? 0) > 0 && $anyFact['median_round_trip_min'] !== null) {
            $out = ['tier' => 'typical', 'real' => true, 'minutes' => round((float)$anyFact['median_round_trip_min'], 1),
                    'km' => round((float)$anyFact['median_km'], 2), 'n' => (int)$anyFact['sample_n'], 'evidence' => [],
                    'note' => 'Otto\'s typical supply run (median of ' . (int)$anyFact['sample_n'] . ', anywhere) — not measured for this address'];
        }
        if ($out['minutes'] === null) {
            return $out + ['per_load' => null, 'per_yard' => null, 'labour' => null, 'truck' => null, 'yards_per_load' => $ypl, 'crew' => $crew];
        }
        $labour = round($out['minutes'] / 60 * (float)$k['hourly'] * $crew, 2);
        $truck = round($out['km'] * (float)$k['per_km'], 2);
        $perLoad = round($labour + $truck, 2);
        return $out + ['per_load' => $perLoad, 'per_yard' => round($perLoad / $ypl, 2), 'labour' => $labour, 'truck' => $truck,
                       'yards_per_load' => $ypl, 'crew' => $crew];
    }

    /** Spreading labour per yard. */
    public static function labour(float $minPerYard, float $hourly): float
    {
        return round(max(0.0, $minPerYard) / 60 * $hourly, 2);
    }

    public static function roundUp5(float $v): float
    {
        return (float)(ceil(round($v, 2) / 5) * 5);
    }

    /**
     * The price from the parts. GST is not in any input and not in the output.
     * @return array{cost_per_yard: float, sell_per_yard: float, margin_pct: ?float, minimum_charge: float, minimum_basis: string}
     */
    public static function price(float $material, float $haulPerYard, float $labourPerYard, float $disposalPerYard,
                                 float $haulPerLoad, float $disposalPerLoad, float $margin, float $minSetting = 0.0): array
    {
        if ($margin < 0 || $margin >= 0.95) throw new InvalidArgumentException('Target margin must be between 0% and 95%');
        $cost = round($material + $haulPerYard + $labourPerYard + $disposalPerYard, 2);
        $sell = (float)ceil(round($cost / (1 - $margin), 2));
        $oneYard = $material + $haulPerLoad + $labourPerYard + $disposalPerLoad;
        $derived = self::roundUp5($oneYard / (1 - $margin));
        $min = max($derived, $minSetting);
        return [
            'cost_per_yard'  => $cost,
            'sell_per_yard'  => $sell,
            'margin_pct'     => CloserPricing::marginAt($sell, $cost),
            'minimum_charge' => $min,
            'minimum_basis'  => $minSetting > $derived ? 'setting' : 'one yard carrying a whole load\'s haul',
        ];
    }

    /** "V6K 2B4" / "v6k2b4" / "V6K" → ['full' => 'V6K2B4'|null, 'fsa' => 'V6K'] or null. */
    public static function postcode(string $s): ?array
    {
        if (!preg_match('/\b([A-Za-z]\d[A-Za-z])\s?(\d[A-Za-z]\d)?\b/', $s, $m)) return null;
        $fsa = strtoupper($m[1]);
        return ['full' => !empty($m[2]) ? $fsa . strtoupper($m[2]) : null, 'fsa' => $fsa];
    }

    /** One line for the quote builder / card. */
    public static function headline(array $r): string
    {
        $where = $r['location']['label'] ?? '';
        if (($r['sell_per_yard'] ?? null) === null) {
            return "Sam can't price " . strtolower((string)($r['product']['label'] ?? 'mulch')) . ($where !== '' ? ' for ' . $where : '') . ' yet — ' . ($r['missing'] ?? 'missing inputs');
        }
        $m = fn($v) => '$' . number_format((float)$v, 0);
        $parts = 'material ' . $m($r['material']['per_yard']) . ' + haul ' . $m($r['haul']['per_yard'] ?? 0) . ' + labour ' . $m($r['labour']['per_yard']);
        if (!empty($r['disposal']['included'])) $parts .= ' + disposal ' . $m($r['disposal']['per_yard']);
        return "Sam's " . strtolower((string)$r['product']['label']) . ' price' . ($where !== '' ? ' for ' . $where : '') . ': '
            . $m($r['sell_per_yard']) . '/yd + GST (' . $parts . ' = ' . $m($r['cost_per_yard']) . ' cost)';
    }

    /**
     * Ask: is this a "what should I charge for mulch at …" question?
     * @return array{family: string, postcode: ?array, address: ?string}|null
     */
    public static function intent(string $q): ?array
    {
        $t = strtolower($q);
        $fam = self::family($t);
        if ($fam === null) return null;
        if (!preg_match('/\b(charge|charging|sell|quote|quoting|installed|install|price for|price at|price in|pricing|per yard|a yard|\/yd)\b/', $t)) return null;
        $pc = self::postcode($q);
        $addr = null;
        if (preg_match('/\b(\d{2,6})\s+((?:[nsew]\.?\s+|west\s+|east\s+)?[a-z0-9]+(?:\s+(?:st|street|ave|avenue|rd|road|dr|drive|blvd|cres|crescent|pl|place|way|ct|court))?)\b/i', $q, $m)) {
            $addr = trim($m[1] . ' ' . $m[2]);
        }
        return ['family' => $fam, 'postcode' => $pc, 'address' => $addr];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Readers (overridden by fakes in tests)
    // ═════════════════════════════════════════════════════════════════════════

    /** @return array<string, array{value: float, seeded: bool, present: bool}> */
    public function settings(): array
    {
        if ($this->settingsCache !== null) return $this->settingsCache;
        $raw = [];
        try {
            $keys = array_keys(self::DEFAULTS);
            $s = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
            $s->execute($keys);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $raw[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) { /* defaults below */ }
        $out = [];
        foreach (self::DEFAULTS as $k => $d) {
            $v = isset($raw[$k]) && is_numeric($raw[$k]) ? (float)$raw[$k] : $d;
            $out[$k] = ['value' => $v, 'present' => isset($raw[$k]), 'seeded' => abs($v - $d) < 0.0001];
        }
        return $this->settingsCache = $out;
    }

    /** The Closer's rate card (hourly cost, target margin, truck speed / detour, depot). */
    public function rateCard(): array
    {
        if ($this->cardCache !== null) return $this->cardCache;
        try {
            return $this->cardCache = (new CloserRateCard($this->db))->load();
        } catch (Throwable $e) {
            return $this->cardCache = ['hourly_cost' => null, 'target_margin' => CloserRateCard::DEFAULT_TARGET_MARGIN_PCT / 100,
                'target_margin_pct' => CloserRateCard::DEFAULT_TARGET_MARGIN_PCT, 'margin_floor' => 0.25, 'kmh' => DriveMinutesAdded::DEFAULT_KMH,
                'detour' => DriveMinutesAdded::DEFAULT_DETOUR, 'depot' => null, 'flags' => []];
        }
    }

    /** @return array{value: float, real: bool} */
    public function perKm(): array
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'truck_cost_per_km' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return ['value' => (float)$v, 'real' => abs((float)$v - self::DEFAULT_PER_KM) >= 0.001];
        } catch (Throwable $e) { /* default */ }
        return ['value' => self::DEFAULT_PER_KM, 'real' => false];
    }

    public function property(int $id): ?array
    {
        try {
            $s = $this->db->prepare("SELECT id, property_name, address, city, postal_code, latitude, longitude FROM properties WHERE id = ? LIMIT 1");
            $s->execute([$id]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Properties whose postcode starts with $prefix (no spaces, upper case), with coordinates. */
    public function propertiesInPostcode(string $prefix): array
    {
        try {
            $s = $this->db->prepare("SELECT id, address, postal_code, latitude, longitude FROM properties
                                     WHERE REPLACE(UPPER(postal_code), ' ', '') LIKE ? AND latitude IS NOT NULL AND latitude <> 0 LIMIT 200");
            $s->execute([$prefix . '%']);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Properties whose address starts like "6015 Tisdall". */
    public function propertiesByAddress(string $addr): array
    {
        try {
            $s = $this->db->prepare("SELECT id, property_name, address, postal_code, latitude, longitude FROM properties WHERE address LIKE ? LIMIT 5");
            $s->execute([$addr . '%']);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function products(): array
    {
        foreach (["SELECT id, name, sku, base_cost, base_price FROM products WHERE COALESCE(is_archived, 0) = 0",
                  "SELECT id, name, sku, base_cost, base_price FROM products"] as $sql) {
            try {
                return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* try the next */ }
        }
        return [];
    }

    /**
     * Receipt lines for a family, newest first: linked to one of its products, or unlinked and named like it.
     * Rejected / deleted receipts are left out.
     */
    public function receiptLines(string $family, array $productIds): array
    {
        try {
            $rows = $this->db->query("
                SELECT li.id, li.expense_id, li.product_id, li.name, li.quantity, li.unit_price, li.line_total,
                       e.expense_date, e.amount AS receipt_amount, e.tax_amount, e.total AS receipt_total,
                       COALESCE(v.name, e.vendor_name_raw, '') AS vendor
                FROM expense_line_items li
                JOIN expenses e ON e.id = li.expense_id
                LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE (e.status IS NULL OR e.status NOT IN ('rejected', 'deleted'))
                ORDER BY e.expense_date DESC, li.id DESC
                LIMIT 1500
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $sums = [];
        foreach ($rows as $r) $sums[(int)$r['expense_id']] = ($sums[(int)$r['expense_id']] ?? 0) + (float)$r['line_total'];
        $out = [];
        foreach ($rows as $r) {
            $linked = $r['product_id'] !== null && in_array((int)$r['product_id'], $productIds, true);
            $named = $r['product_id'] === null && self::family((string)$r['name']) === $family;
            if (!$linked && !$named) continue;
            $r['lines_sum'] = round($sums[(int)$r['expense_id']] ?? 0, 2);
            $out[] = $r;
            if (count($out) >= 60) break;
        }
        return $out;
    }

    public function supplierPlaces(): array
    {
        try {
            return $this->db->query("SELECT id, name, kind, lat, lng, address, vendor_match FROM ops_places WHERE active = 1 AND kind = 'supplier'")
                            ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Real supply runs to $placeId that started from or returned to one of $propertyIds. */
    public function runsFrom(int $placeId, array $propertyIds): array
    {
        $propertyIds = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));
        if (!$propertyIds) return [];
        try {
            $in = implode(',', array_fill(0, count($propertyIds), '?'));
            $s = $this->db->prepare("SELECT id, run_date, drive_min, onsite_min, km FROM ops_trip_runs
                                     WHERE place_id = ? AND (from_property_id IN ($in) OR return_property_id IN ($in))
                                     ORDER BY run_date DESC, id DESC LIMIT 12");
            $s->execute(array_merge([$placeId], $propertyIds, $propertyIds));
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function fact(string $key): ?array
    {
        return (new CostFactsService($this->db))->get($key);
    }

    /** What we've charged before for this family (quote lines), newest first. Evidence only. */
    public function quotedBefore(string $family): array
    {
        try {
            $rows = $this->db->query("
                SELECT qli.service_type, qli.description, qli.quantity, qli.unit_type, qli.unit_price, q.quote_number, q.status, q.created_at
                FROM quote_line_items qli JOIN quotes q ON q.id = qli.quote_id
                ORDER BY q.id DESC LIMIT 800
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (self::family((string)$r['service_type'] . ' ' . (string)$r['description']) !== $family) continue;
            if ((float)$r['unit_price'] <= 0) continue;
            $out[] = ['quote' => (string)$r['quote_number'], 'status' => (string)$r['status'], 'date' => substr((string)$r['created_at'], 0, 10),
                      'line' => trim((string)$r['service_type']), 'qty' => (float)$r['quantity'], 'unit' => (string)($r['unit_type'] ?? ''),
                      'unit_price' => (float)$r['unit_price']];
            if (count($out) >= 10) break;
        }
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // The breakdown
    // ═════════════════════════════════════════════════════════════════════════

    /** Resolve where: a property, a postcode (centroid of our properties in it), or an address. */
    public function location(?int $propertyId, ?string $postcode = null, ?string $address = null): array
    {
        if ($propertyId) {
            $p = $this->property($propertyId);
            if (!$p) return ['kind' => 'none', 'label' => '', 'error' => 'Property ' . $propertyId . ' not found'];
            $pc = self::postcode((string)($p['postal_code'] ?? ''));
            $has = (float)($p['latitude'] ?? 0) != 0.0 && (float)($p['longitude'] ?? 0) != 0.0;
            return ['kind' => 'property', 'property_id' => (int)$p['id'], 'property_ids' => [(int)$p['id']],
                    'address' => (string)$p['address'], 'name' => (string)($p['property_name'] ?? ''),
                    'fsa' => $pc['fsa'] ?? null, 'label' => $pc['fsa'] ?? (string)$p['address'],
                    'lat' => $has ? (float)$p['latitude'] : null, 'lng' => $has ? (float)$p['longitude'] : null,
                    'basis' => $has ? 'the property\'s pin' : 'no pin on this property'];
        }
        if ($address !== null && $address !== '') {
            $hits = $this->propertiesByAddress($address);
            if (count($hits) >= 1) return $this->location((int)$hits[0]['id']);
            $pcFromAddr = self::postcode($address);
            if ($pcFromAddr) $postcode = $address;
            else return ['kind' => 'none', 'label' => $address, 'error' => 'No property at ' . $address];
        }
        $pc = $postcode !== null ? self::postcode($postcode) : null;
        if ($pc) {
            $rows = $pc['full'] ? $this->propertiesInPostcode($pc['full']) : [];
            $basis = $pc['full'] ? $pc['full'] : $pc['fsa'];
            if (!$rows) { $rows = $this->propertiesInPostcode($pc['fsa']); $basis = $pc['fsa']; }
            if (!$rows) return ['kind' => 'postcode', 'fsa' => $pc['fsa'], 'label' => $pc['fsa'], 'property_ids' => [], 'lat' => null, 'lng' => null,
                                'basis' => 'no properties of ours in ' . $pc['fsa']];
            $lat = array_sum(array_map(fn($r) => (float)$r['latitude'], $rows)) / count($rows);
            $lng = array_sum(array_map(fn($r) => (float)$r['longitude'], $rows)) / count($rows);
            return ['kind' => 'postcode', 'fsa' => $pc['fsa'], 'label' => $pc['fsa'], 'property_ids' => array_map(fn($r) => (int)$r['id'], $rows),
                    'lat' => round($lat, 6), 'lng' => round($lng, 6), 'basis' => 'centre of ' . count($rows) . ' of our properties in ' . $basis];
        }
        return ['kind' => 'none', 'label' => '', 'property_ids' => [], 'lat' => null, 'lng' => null, 'basis' => 'no address'];
    }

    /** Pick the family's product: the one asked for, else the one with receipts, else the first. */
    public function product(string $family, ?int $productId = null): array
    {
        $all = $this->products();
        $fam = array_values(array_filter($all, fn($p) => self::family((string)$p['name'] . ' ' . (string)($p['sku'] ?? '')) === $family));
        $pick = null;
        if ($productId) foreach ($all as $p) if ((int)$p['id'] === $productId) $pick = $p;
        if (!$pick) {
            foreach ($fam as $p) if ((float)($p['base_cost'] ?? 0) > 0) { $pick = $p; break; }
        }
        if (!$pick && $fam) $pick = $fam[0];
        return [
            'family' => $family, 'label' => self::FAMILIES[$family]['label'],
            'id' => $pick ? (int)$pick['id'] : null, 'name' => $pick ? (string)$pick['name'] : null,
            'base_cost' => $pick && (float)($pick['base_cost'] ?? 0) > 0 ? (float)$pick['base_cost'] : null,
            'ids' => array_map(fn($p) => (int)$p['id'], $fam),
        ];
    }

    /** The supplier: the place of the usual receipt vendor, else the nearest supplier place. */
    public function supplier(?string $usualVendor, ?float $lat, ?float $lng): ?array
    {
        $places = $this->supplierPlaces();
        if (!$places) return null;
        if ($usualVendor !== null && $usualVendor !== '') {
            foreach ($places as $p) {
                if (self::vendorMatches($usualVendor, self::vendorWords($p))) return $p + ['basis' => 'where the receipts come from (' . $usualVendor . ')'];
            }
        }
        if ($lat !== null && $lng !== null) {
            usort($places, fn($a, $b) => DriveMinutesAdded::haversineKm($lat, $lng, (float)$a['lat'], (float)$a['lng'])
                                       <=> DriveMinutesAdded::haversineKm($lat, $lng, (float)$b['lat'], (float)$b['lng']));
            return $places[0] + ['basis' => 'the nearest supplier Otto knows'];
        }
        return null;
    }

    /** "LAWNBOY ENTERPRISES" → "Lawnboy" (as Penny's ProductProposalService::vendorLabel). */
    public static function vendorLabel(string $v): string
    {
        $v = trim($v);
        if ($v === strtoupper($v)) $v = ucwords(strtolower($v));
        return (string)preg_replace('/\s+(Enterprises|Ltd\.?|Inc\.?|Limited|Corp\.?)$/i', '', $v);
    }

    public static function vendorWords(array $place): array
    {
        $raw = (string)($place['vendor_match'] ?? '');
        $words = $raw !== '' ? explode('|', $raw) : [(string)($place['name'] ?? '')];
        $words = array_map(fn($w) => strtolower(trim($w)), $words);
        // "Lawn Boy" place vs "LAWNBOY ENTERPRISES" vendor: compare without spaces too
        return array_values(array_filter($words, fn($w) => strlen($w) >= 3));
    }

    public static function vendorMatches(string $vendor, array $words): bool
    {
        $v = strtolower($vendor);
        $vs = str_replace(' ', '', $v);
        foreach ($words as $w) {
            if ($w === '') continue;
            if (strpos($v, $w) !== false || strpos($vs, str_replace(' ', '', $w)) !== false) return true;
        }
        return false;
    }

    /**
     * The full breakdown for one place and material.
     * @param array $opt property_id, postcode, address, family|text, product_id, disposal (bool)
     */
    public function breakdown(array $opt): array
    {
        $family = isset($opt['family']) && isset(self::FAMILIES[$opt['family']]) ? (string)$opt['family'] : (self::family((string)($opt['text'] ?? '')) ?? 'mulch');
        $set = $this->settings();
        $sv = fn(string $k) => (float)$set[$k]['value'];
        $card = $this->rateCard();
        $flags = [];

        $hourly = isset($card['hourly_cost']) && $card['hourly_cost'] !== null ? (float)$card['hourly_cost'] : null;
        $hourlyReal = $hourly !== null;
        if ($hourly === null) { $hourly = self::FALLBACK_HOURLY; $flags[] = 'The Closer\'s hourly cost is not set — using $' . (int)self::FALLBACK_HOURLY . '/h'; }
        $margin = (float)($card['target_margin'] ?? 0.35);
        $perKm = $this->perKm();

        $loc = $this->location(isset($opt['property_id']) ? (int)$opt['property_id'] : null, $opt['postcode'] ?? null, $opt['address'] ?? null);
        if (!empty($loc['error'])) $flags[] = $loc['error'];
        $product = $this->product($family, isset($opt['product_id']) ? (int)$opt['product_id'] : null);

        $material = self::material($this->receiptLines($family, $product['ids']), $product['base_cost'], (int)$sv('mulch_receipts_n'));
        if ($material['source'] === 'product cost') $flags[] = 'No usable receipt lines for ' . strtolower($product['label']) . ' — using the product\'s cost';
        if ($material['source'] === 'none') $flags[] = 'No receipts and no product cost for ' . strtolower($product['label']);

        $supplier = $this->supplier($material['usual_vendor'], $loc['lat'] ?? null, $loc['lng'] ?? null);
        $runs = $supplier ? $this->runsFrom((int)$supplier['id'], $loc['property_ids'] ?? []) : [];
        $straight = ($supplier && ($loc['lat'] ?? null) !== null)
            ? DriveMinutesAdded::haversineKm((float)$loc['lat'], (float)$loc['lng'], (float)$supplier['lat'], (float)$supplier['lng']) : null;
        $placeFact = $supplier ? $this->fact('run:supplier:place:' . (int)$supplier['id']) : null;
        $anyFact = $this->fact('run:supplier:any');
        $haul = self::haul($runs, $straight, $placeFact, $anyFact, [
            'hourly' => $hourly, 'crew' => $sv('mulch_haul_crew'), 'per_km' => $perKm['value'],
            'kmh' => (float)($card['kmh'] ?? DriveMinutesAdded::DEFAULT_KMH), 'detour' => (float)($card['detour'] ?? DriveMinutesAdded::DEFAULT_DETOUR),
            'load_min' => $sv('mulch_load_min'), 'yards_per_load' => $sv('mulch_yards_per_load'),
        ]);
        if (!$supplier) $flags[] = 'Otto has no supplier place' . ($material['usual_vendor'] ? ' for ' . $material['usual_vendor'] : '') . ' — name the stop on Otto\'s card';
        if ($haul['tier'] === 'none') $flags[] = 'No haul: no supplier pin / address pin and no supply-run fact yet';

        $labour = ['per_yard' => self::labour($sv('mulch_spread_min_per_yard'), $hourly), 'min_per_yard' => $sv('mulch_spread_min_per_yard'),
                   'hourly' => $hourly, 'real' => false, 'source' => $set['mulch_spread_min_per_yard']['seeded'] ? 'default setting — not measured' : 'Tim\'s setting'];

        $disposal = ['included' => false, 'per_yard' => 0.0, 'per_load' => 0.0, 'real' => false, 'note' => 'Not included — fresh material makes no waste'];
        if (!empty($opt['disposal'])) {
            $df = $this->fact('run:dump:any');
            if ($df && (int)$df['sample_n'] > 0 && $df['median_cost'] !== null) {
                $disposal = ['included' => true, 'per_load' => round((float)$df['median_cost'], 2),
                             'per_yard' => round((float)$df['median_cost'] / max(0.5, $sv('mulch_yards_per_load')), 2), 'real' => true,
                             'note' => 'Otto\'s median dump run (n=' . (int)$df['sample_n'] . ') incl. dump fee, per load'];
            } else {
                $disposal['note'] = 'Asked for disposal, but Otto has no dump-run fact yet — not included';
                $flags[] = $disposal['note'];
            }
        }

        $inputs = [
            ['key' => 'material', 'label' => 'Material / yd', 'value' => $material['per_yard'], 'real' => $material['real'], 'source' => $material['source'] === 'receipts' ? 'median of ' . $material['n'] . ' receipt line' . ($material['n'] === 1 ? '' : 's') : $material['source']],
            ['key' => 'haul_minutes', 'label' => 'Haul round trip (min)', 'value' => $haul['minutes'], 'real' => $haul['real'], 'source' => $haul['note'] ?: 'none'],
            ['key' => 'haul_km', 'label' => 'Haul km', 'value' => $haul['km'], 'real' => $haul['real'], 'source' => $haul['tier']],
            ['key' => 'hourly', 'label' => 'Hourly cost', 'value' => $hourly, 'real' => $hourlyReal, 'source' => $hourlyReal ? 'Closer rate card (closer_hourly_cost)' : 'fallback'],
            ['key' => 'per_km', 'label' => 'Truck $/km', 'value' => $perKm['value'], 'real' => $perKm['real'], 'source' => $perKm['real'] ? 'truck_cost_per_km' : 'truck_cost_per_km default 0.70'],
            ['key' => 'margin', 'label' => 'Target margin', 'value' => round($margin * 100, 1), 'real' => true, 'source' => 'overhead_settings.profit_margin'],
        ];
        foreach (self::DEFAULTS as $k => $d) {
            if ($k === 'mulch_receipts_n' || ($k === 'mulch_min_charge' && $sv($k) <= 0)) continue;   // 0 = derived, nothing assumed
            $inputs[] = ['key' => $k, 'label' => self::SETTING_LABELS[$k], 'value' => $sv($k), 'real' => !$set[$k]['seeded'],
                         'source' => $set[$k]['present'] ? ($set[$k]['seeded'] ? 'setting, still the default' : 'Tim\'s setting') : 'default (migration 1270 not run)'];
        }

        $out = [
            'ok' => true, 'product' => $product, 'location' => $loc,
            'supplier' => $supplier ? ['place_id' => (int)$supplier['id'], 'name' => (string)$supplier['name'], 'basis' => $supplier['basis'],
                                       'straight_km' => $straight === null ? null : round($straight, 2)] : null,
            'material' => $material, 'haul' => $haul, 'labour' => $labour, 'disposal' => $disposal,
            'inputs' => $inputs, 'history' => $this->quotedBefore($family), 'flags' => array_values(array_unique($flags)),
            'gst' => 'Not included — GST is added on top of the quote',
            'cost_per_yard' => null, 'sell_per_yard' => null, 'margin_pct' => null, 'minimum_charge' => null,
        ];
        if ($material['per_yard'] === null) {
            $out['missing'] = 'no material cost';
        } else {
            $p = self::price((float)$material['per_yard'], (float)($haul['per_yard'] ?? 0), $labour['per_yard'], (float)$disposal['per_yard'],
                             (float)($haul['per_load'] ?? 0), (float)$disposal['per_load'], $margin, $sv('mulch_min_charge'));
            $out = array_merge($out, $p);
            $out['target_margin_pct'] = round($margin * 100, 1);
        }
        $out['headline'] = self::headline($out);
        $out['all_real'] = !in_array(false, array_column($inputs, 'real'), true);
        return $out;
    }

    /** Compact form for the quote builder hint / Sam's card. */
    public static function hint(array $b): array
    {
        return [
            'ok' => $b['sell_per_yard'] !== null, 'headline' => $b['headline'],
            'where' => $b['location']['label'] ?? '', 'family' => $b['product']['family'], 'label' => $b['product']['label'],
            'sell_per_yard' => $b['sell_per_yard'], 'cost_per_yard' => $b['cost_per_yard'], 'minimum_charge' => $b['minimum_charge'],
            'material' => $b['material']['per_yard'], 'haul' => $b['haul']['per_yard'] ?? null, 'labour' => $b['labour']['per_yard'],
            'disposal' => $b['disposal']['included'] ? $b['disposal']['per_yard'] : null,
            'estimated' => array_values(array_map(fn($i) => $i['label'], array_filter($b['inputs'], fn($i) => !$i['real']))),
            'material_basis' => $b['material']['source'] === 'receipts'
                ? 'median of ' . $b['material']['n'] . ' receipt' . ($b['material']['n'] === 1 ? '' : 's') . ($b['material']['usual_vendor'] ? ', mostly ' . self::vendorLabel($b['material']['usual_vendor']) : '')
                : $b['material']['source'],
            'haul_basis' => $b['haul']['note'] ?? '', 'haul_tier' => $b['haul']['tier'] ?? 'none',
            'includes_pickup' => ($b['haul']['per_yard'] ?? null) !== null,
        ];
    }

    /** Ask: "what should I charge for mulch at 2448 Larch" → a sentence, or null when it isn't that question. */
    public function answer(string $question): ?array
    {
        $i = self::intent($question);
        if ($i === null) return null;
        if ($i['address'] === null && $i['postcode'] === null) {
            $b = $this->breakdown(['family' => $i['family']]);
        } elseif ($i['address'] !== null) {
            $b = $this->breakdown(['family' => $i['family'], 'address' => $i['address']]);
            if (!empty($b['location']['error']) && $i['postcode'] !== null) $b = $this->breakdown(['family' => $i['family'], 'postcode' => $question]);
        } else {
            $b = $this->breakdown(['family' => $i['family'], 'postcode' => $question]);
        }
        $s = $b['headline'] . '.';
        if ($b['sell_per_yard'] !== null) {
            $s .= ' Minimum ' . '$' . number_format((float)$b['minimum_charge'], 0) . ' for a small job.';
            $s .= ' Material: ' . self::hint($b)['material_basis'] . '. Haul: ' . ($b['haul']['note'] ?: 'none') . '.';
            $est = self::hint($b)['estimated'];
            if ($est) $s .= ' Assumed, not measured: ' . implode(', ', $est) . '.';
            if (($b['location']['kind'] ?? 'none') === 'none') $s .= ' Give me an address or postcode for the haul from there.';
        }
        return ['answer' => $s . "\n— from Sam's mulch pricing", 'head' => 'sam', 'about' => strtolower($b['product']['label'])];
    }

    /** Read-only coverage: how much real data the breakdown can stand on. */
    public function coverage(): array
    {
        $fam = [];
        $products = $this->products();
        foreach (array_keys(self::FAMILIES) as $f) {
            $ids = array_map(fn($p) => (int)$p['id'], array_filter($products, fn($p) => self::family((string)$p['name'] . ' ' . (string)($p['sku'] ?? '')) === $f));
            $lines = $this->receiptLines($f, $ids);
            $m = self::material($lines, null, 1000);
            $vendors = [];
            foreach ($m['evidence'] as $e) $vendors[$e['vendor']] = ($vendors[$e['vendor']] ?? 0) + 1;
            arsort($vendors);
            $fam[$f] = ['products' => count($ids), 'receipt_lines' => count($lines), 'usable' => count($m['evidence']),
                        'excluded' => count($m['excluded']), 'linked' => count(array_filter($lines, fn($l) => $l['product_id'] !== null)),
                        'median_per_yard' => $m['per_yard'], 'vendors' => $vendors,
                        'newest' => $m['evidence'][0]['date'] ?? null, 'oldest' => $m['evidence'] ? end($m['evidence'])['date'] : null];
        }
        $places = [];
        foreach ($this->supplierPlaces() as $p) {
            $runs = 0; $props = 0;
            try {
                $s = $this->db->prepare("SELECT COUNT(*) AS n, COUNT(DISTINCT COALESCE(from_property_id, return_property_id)) AS props FROM ops_trip_runs WHERE place_id = ?");
                $s->execute([(int)$p['id']]);
                $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                $runs = (int)($r['n'] ?? 0); $props = (int)($r['props'] ?? 0);
            } catch (Throwable $e) { /* no runs table */ }
            $places[] = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'vendor_match' => (string)($p['vendor_match'] ?? ''),
                         'runs' => $runs, 'properties_with_runs' => $props, 'fact' => $this->fact('run:supplier:place:' . (int)$p['id'])];
        }
        $props = ['with_pin' => null, 'with_postcode' => null];
        try {
            $props['with_pin'] = (int)$this->db->query("SELECT COUNT(*) FROM properties WHERE latitude IS NOT NULL AND latitude <> 0")->fetchColumn();
            $props['with_postcode'] = (int)$this->db->query("SELECT COUNT(*) FROM properties WHERE postal_code IS NOT NULL AND postal_code <> ''")->fetchColumn();
        } catch (Throwable $e) { /* leave null */ }
        $set = $this->settings();
        $card = $this->rateCard();
        return [
            'ok' => true, 'materials' => $fam, 'supplier_places' => $places,
            'supply_run_any' => $this->fact('run:supplier:any'), 'dump_run_any' => $this->fact('run:dump:any'),
            'properties' => $props,
            'settings' => array_map(fn($s) => $s['value'] . ($s['present'] ? ($s['seeded'] ? ' (default)' : ' (set)') : ' (migration 1270 not run)'), $set),
            'rate_card' => ['hourly_cost' => $card['hourly_cost'] ?? null, 'target_margin_pct' => $card['target_margin_pct'] ?? null,
                            'kmh' => $card['kmh'] ?? null, 'detour' => $card['detour'] ?? null, 'flags' => $card['flags'] ?? []],
            'truck_cost_per_km' => $this->perKm(),
        ];
    }
}
