<?php
/**
 * CloserService — Sam the Closer: his price for a quote, shown BESIDE the quote's own price.
 *
 * Gathers rows (rate card, lot, route days, products, bundles, site-minute models) and hands
 * the numbers to the pure classes: CloserPricing (the formula and tiers), SiteMinutesModel
 * (minutes per service), DriveMinutesAdded (extra truck minutes on the nearest route day).
 *
 * It never writes a price and never changes a rate. The only writes are its own:
 *   - recalibrate(): the weekly fit of minutes per service from Tim's timed visits
 *     (closer_site_models, source = 'fit'), plus the realised margin per service — the
 *     "drift" report, never applied to anything;
 *   - depot(): caches the geocoded business address once, when Tim has not set a yard.
 *
 * Satellite or drawn measurements are a draft: every Closer price says "confirmed after
 * the first visit".
 *
 * Every read is guarded so a missing table or column on production degrades to a flag on
 * the panel, never a fatal. No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/CloserPricing.php';
require_once __DIR__ . '/SiteMinutesModel.php';
require_once __DIR__ . '/DriveMinutesAdded.php';
require_once __DIR__ . '/CloserRateCard.php';

class CloserService
{
    public const ROUTE_WINDOW_DAYS = 28;
    public const TRAINING_MONTHS = 18;
    public const DRIFT_DAYS = 56;

    private PDO $db;
    private array $colCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Migration 1141 has run. */
    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'closer_site_models'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function hasColumn(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset($this->colCache[$k])) {
            try {
                $t = preg_replace('/[^a-z0-9_]/', '', $table);
                $this->colCache[$k] = $this->db->query("SHOW COLUMNS FROM `{$t}` LIKE " . $this->db->quote($col))->rowCount() > 0;
            } catch (Throwable $e) {
                $this->colCache[$k] = false;
            }
        }
        return $this->colCache[$k];
    }

    // ── The quote panel ─────────────────────────────────────────────────────

    /** Everything the "Closer's price" panel shows for one quote. */
    public function forQuote(int $quoteId): array
    {
        $quote = $this->quoteRow($quoteId);
        if (!$quote) {
            throw new RuntimeException('Quote not found');
        }
        $card  = $this->rateCard();
        $card['depot'] = $this->depot($card);
        $flags = $card['flags'];

        $lot = $this->lot((int)$quote['property_id']);
        if (!$lot['measured']) $flags[] = 'This lot has no lawn measurement — measure it before trusting any price';
        if (!$lot['has_coords']) $flags[] = 'This property has no map pin — drive minutes not counted';

        $drive = null;
        if ($lot['has_coords']) {
            $drive = DriveMinutesAdded::added($lot['lat'], $lot['lng'], $this->routeDays((int)$quote['property_id']),
                                              $card['depot'], $card['kmh'], $card['detour']);
            if ($drive === null) $flags[] = 'No route day nearby and no depot set — drive minutes not counted';
        }
        $driveMin = $drive['minutes'] ?? 0.0;

        $products = $this->products();
        $disposal = $this->disposalCost();
        if ($disposal === null) $flags[] = 'No disposal cost factor — disposal counted as $0';
        $models   = $this->models($lot, $card, $products);

        $perService = [];
        foreach (CloserPricing::SERVICES as $key => $def) {
            $prod = $this->productFor($products, $key);
            $perService[$key] = [
                'minutes'   => SiteMinutesModel::predict($models[$key] ?? null, $key, $lot),
                'materials' => (float)($prod['base_cost'] ?? 0),
                'disposal'  => $def['disposal'] ? (float)$disposal : 0.0,
                'model'     => $models[$key] ?? null,
            ];
        }

        $priced = $card['hourly_cost'] !== null;
        $mowProduct = $this->productFor($products, 'mow');
        $visitMin = max((float)$card['min_visit'], (float)($mowProduct['min_price'] ?? 0));

        // Lines: the quote's own items, each with the Closer's price beside it.
        $items = $this->lineItems($quoteId);
        $lines = [];
        $driveUsed = false;
        foreach ($items as $it) {
            $prod = isset($products[(int)$it['product_id']]) ? $products[(int)$it['product_id']] : null;
            $key = CloserPricing::serviceKey((string)($prod['service_type'] ?? ''))
                ?? CloserPricing::serviceKey((string)($prod['name'] ?? ''))
                ?? CloserPricing::serviceKey((string)$it['service_type'] . ' ' . (string)$it['description']);
            $line = [
                'id'          => (int)$it['id'],
                'label'       => trim((string)$it['service_type']) ?: trim((string)$it['description']),
                'service'     => $key,
                'quantity'    => (float)$it['quantity'],
                'quoted'      => (float)$it['unit_price'],
                'closer'      => null,
                'basis'       => null,
                'why_not'     => null,
            ];
            $s = $key ? $perService[$key] : null;
            if (self::isYardLine($it)) {
                // Mulch & co: sold in whole yards by the per_yard_area rule, not by site minutes.
                $line['why_not'] = self::yardLineNote($it);
            } elseif (!$key) {
                $line['why_not'] = 'Not a service the Closer prices';
            } elseif (!$priced) {
                $line['why_not'] = 'Hourly cost not set';
            } elseif ($s['minutes'] === null) {
                $line['why_not'] = $s['model'] === null ? 'Needs minutes for this service' : 'Lot not measured for this service';
            } else {
                $isVisit = CloserPricing::SERVICES[$key]['per_visit'] && !$driveUsed;
                $res = CloserPricing::price([
                    'site_minutes'  => $s['minutes'],
                    'drive_minutes' => $isVisit ? $driveMin : 0,
                    'materials'     => $prod ? (float)($prod['base_cost'] ?? 0) : $s['materials'],
                    'disposal'      => $s['disposal'],
                    'minimum'       => $isVisit ? max($visitMin, (float)($prod['min_price'] ?? 0)) : (float)($prod['min_price'] ?? 0),
                ], $card);
                if ($isVisit) $driveUsed = true;
                if (self::looksLikeSeasonTotal($key, $line['quantity'], $line['quoted'], $res['price'])) {
                    // One line for a whole season: comparing it with one visit would be nonsense.
                    $res['margin_at_quoted'] = null;
                    $res['quoted_below_floor'] = false;
                    $line['why_not'] = 'Quoted as one season total — compare per visit by hand';
                } else {
                    $res['margin_at_quoted'] = CloserPricing::marginAt($line['quoted'], $res['cost']);
                    $res['quoted_below_floor'] = $line['quoted'] > 0 && CloserPricing::belowFloor($line['quoted'], $res['cost'], $card['margin_floor']);
                }
                $res['site_minutes'] = $s['minutes'];
                $res['includes_drive'] = $isVisit;
                $line['closer'] = $res;
                $line['basis'] = self::basisLabel($s['model']);
            }
            $lines[] = $line;
        }

        $tiers = [];
        if ($priced) {
            $tiers = CloserPricing::tiers($this->tierServices(), $perService,
                ['drive_minutes' => $driveMin, 'minimum' => $visitMin], $card['season'], $card);
        }

        $belowFloor = array_values(array_filter($lines, function ($l) { return !empty($l['closer']['quoted_below_floor']); }));
        return [
            'quote'   => ['id' => (int)$quote['id'], 'number' => $quote['quote_number'], 'status' => $quote['status']],
            'lot'     => $lot,
            'drive'   => $drive,
            'lines'   => $lines,
            'tiers'   => $tiers,
            'services'=> array_map(function ($s) {
                return ['minutes' => $s['minutes'], 'basis' => self::basisLabel($s['model'])];
            }, $perService),
            'card'    => [
                'hourly_cost'       => $card['hourly_cost'],
                'hourly_source'     => $card['hourly_source'],
                'suggested_loaded'  => $card['suggested_loaded'],
                'target_margin_pct' => $card['target_margin_pct'],
                'margin_floor_pct'  => $card['margin_floor_pct'],
                'min_visit'         => $visitMin,
                'calibration_min'   => $card['calibration_min'],
                'depot_source'      => $card['depot_source'],
            ],
            'below_floor' => count($belowFloor),
            'flags'   => array_values(array_unique($flags)),
            'note'    => 'Draft from the map measurement — price confirmed after the first visit.',
        ];
    }

    /** A line sold by the cubic yard (QuoteCalculator's per_yard_area model writes unit 'yd'). */
    public static function isYardLine(array $item): bool
    {
        return strtolower(trim((string)($item['unit_type'] ?? ''))) === 'yd';
    }

    /** What the panel says beside a per-yard line instead of a minutes price. */
    public static function yardLineNote(array $item): string
    {
        $qty = (float)($item['quantity'] ?? 0);
        $yd  = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        return 'Sold by the yard (' . $yd . ' yd × $' . number_format((float)($item['unit_price'] ?? 0), 2)
             . ') — the per-yard rule prices it, not site minutes';
    }

    /** A per-visit service quoted once at more than five visits' worth is a season total, not a visit price. */
    public static function looksLikeSeasonTotal(string $service, float $qty, float $quoted, float $closerVisit): bool
    {
        return (CloserPricing::SERVICES[$service]['per_visit'] ?? false) && $qty <= 1 && $closerVisit > 0 && $quoted > 5 * $closerVisit;
    }

    public static function basisLabel(?array $model): ?string
    {
        if (!$model) return null;
        switch ($model['source'] ?? '') {
            case 'fit':     return 'Your timed visits (' . (int)$model['n'] . ')';
            case 'manual':  return 'Your minutes setting';
            case 'implied': return 'Implied by the current price rule — set real minutes';
        }
        return null;
    }

    // ── Rows ────────────────────────────────────────────────────────────────

    public function quoteRow(int $quoteId): ?array
    {
        $q = $this->db->prepare("SELECT id, quote_number, status, property_id FROM quotes WHERE id = ?");
        $q->execute([$quoteId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function rateCard(): array
    {
        return (new CloserRateCard($this->db))->load();
    }

    public function lot(int $propertyId): array
    {
        $cols = "p.id, p.latitude, p.longitude, p.total_lawn_sqft, p.lawn_size_sqft"
              . ($this->hasColumn('properties', 'total_hedge_linear_ft') ? ", p.total_hedge_linear_ft" : ", NULL AS total_hedge_linear_ft")
              . ($this->hasColumn('properties', 'edge_linear_ft') ? ", p.edge_linear_ft" : ", NULL AS edge_linear_ft")
              . ($this->hasColumn('properties', 'obstacle_count') ? ", p.obstacle_count" : ", NULL AS obstacle_count");
        $st = $this->db->prepare("SELECT {$cols} FROM properties p WHERE p.id = ?");
        $st->execute([$propertyId]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return self::lotFromRow($p);
    }

    /** Pure: a properties row → the lot the models use (lawn precedence as CalendarStops.php). */
    public static function lotFromRow(array $p): array
    {
        $lawn = (float)($p['total_lawn_sqft'] ?? 0) > 0 ? (float)$p['total_lawn_sqft'] : (float)($p['lawn_size_sqft'] ?? 0);
        $lat = (float)($p['latitude'] ?? 0);
        $lng = (float)($p['longitude'] ?? 0);
        return [
            'property_id' => (int)($p['id'] ?? 0),
            'lawn_sqft'   => $lawn,
            'edge_ft'     => (float)($p['edge_linear_ft'] ?? 0),
            'hedge_ft'    => (float)($p['total_hedge_linear_ft'] ?? 0),
            'obstacles'   => (int)($p['obstacle_count'] ?? 0),
            'measured'    => $lawn > 0,
            'has_coords'  => $lat != 0 && $lng != 0,
            'lat'         => $lat,
            'lng'         => $lng,
        ];
    }

    /** Route days around today (±28 days): one entry per crew per date, stops in route order. */
    public function routeDays(int $excludePropertyId): array
    {
        try {
            $st = $this->db->prepare("
                SELECT cs.stop_date, cs.crew_id, cs.property_id, p.latitude AS lat, p.longitude AS lng
                FROM calendar_stops cs
                JOIN properties p ON p.id = cs.property_id
                WHERE cs.stop_date BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND cs.status <> 'skipped'
                  AND cs.property_id <> ?
                  AND p.latitude IS NOT NULL AND p.latitude <> 0
                ORDER BY cs.stop_date, cs.crew_id, cs.route_order, cs.id
            ");
            $st->execute([self::ROUTE_WINDOW_DAYS, self::ROUTE_WINDOW_DAYS, $excludePropertyId]);
            $days = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = $r['stop_date'] . '|' . (int)$r['crew_id'];
                if (!isset($days[$k])) $days[$k] = ['date' => $r['stop_date'], 'crew_id' => $r['crew_id'] !== null ? (int)$r['crew_id'] : null, 'stops' => []];
                $days[$k]['stops'][] = ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'property_id' => (int)$r['property_id']];
            }
            return array_values($days);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Active products by id, with the service key each one maps to. */
    public function products(): array
    {
        $out = [];
        try {
            $svc = $this->hasColumn('products', 'service_type') ? 'service_type' : "NULL AS service_type";
            $arch = $this->hasColumn('products', 'is_archived') ? ' AND is_archived = 0' : '';
            $rows = $this->db->query("SELECT id, name, {$svc}, base_cost, base_price, min_price FROM products WHERE active = 1{$arch} ORDER BY id")
                             ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $r['service_key'] = CloserPricing::serviceKey((string)($r['service_type'] ?? '')) ?? CloserPricing::serviceKey((string)$r['name']);
                $out[(int)$r['id']] = $r;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** The product that stands for a service: its lowest id with that key. */
    public function productFor(array $products, string $key): ?array
    {
        foreach ($products as $p) {
            if (($p['service_key'] ?? null) === $key) return $p;
        }
        return null;
    }

    /** A disposal / dump-fee cost factor, per visit that makes waste. Null when there is none. */
    public function disposalCost(): ?float
    {
        try {
            $v = $this->db->query("SELECT rate FROM cost_factors WHERE active = 1
                                     AND (factor_name LIKE '%dispos%' OR factor_name LIKE '%dump fee%' OR factor_name LIKE '%tipping%')
                                   ORDER BY id LIMIT 1")->fetchColumn();
            return $v === false ? null : (float)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function lineItems(int $quoteId): array
    {
        try {
            $opt = $this->hasColumn('quote_line_items', 'is_optional') ? 'is_optional' : '0 AS is_optional';
            $ut  = $this->hasColumn('quote_line_items', 'unit_type') ? 'unit_type' : "'each' AS unit_type";
            $st = $this->db->prepare("SELECT id, product_id, service_type, description, quantity, {$ut}, unit_price, {$opt}
                                      FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order, id");
            $st->execute([$quoteId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** tier => service keys, from product_bundles good / better / best. Missing tiers use the defaults. */
    public function tierServices(): array
    {
        $out = [];
        try {
            $rows = $this->db->query("
                SELECT b.tier, p.name, " . ($this->hasColumn('products', 'service_type') ? 'p.service_type' : 'NULL AS service_type') . "
                FROM product_bundles b
                JOIN product_bundle_items bi ON bi.bundle_id = b.id
                JOIN products p ON p.id = bi.product_id
                WHERE b.is_active = 1 AND b.tier IN ('good','better','best')
                ORDER BY b.id, bi.sort_order
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $k = CloserPricing::serviceKey((string)($r['service_type'] ?? '')) ?? CloserPricing::serviceKey((string)$r['name']);
                if ($k && !in_array($k, $out[$r['tier']] ?? [], true)) $out[$r['tier']][] = $k;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** service key => the model it uses for this lot (fit → manual → implied by the price rule). */
    public function models(array $lot, array $card, array $products): array
    {
        $rows = [];
        foreach ($this->siteModelRows() as $r) {
            $rows[$r['service_key']][$r['source']] = $r;
        }
        $implied = $this->impliedModels($lot, $card, $products);
        $out = [];
        foreach (CloserPricing::SERVICES as $key => $def) {
            $out[$key] = SiteMinutesModel::choose($rows[$key]['fit'] ?? null, $rows[$key]['manual'] ?? null,
                                                  $implied[$key] ?? null, (int)$card['calibration_min']);
        }
        return $out;
    }

    public function siteModelRows(): array
    {
        try {
            return $this->db->query("SELECT * FROM closer_site_models")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Active price rules with their measurement group, highest priority first. */
    public function pricingRules(): array
    {
        try {
            return $this->db->query("
                SELECT r.*, mg.group_key, mg.group_label, mg.unit
                FROM product_pricing_rules r
                JOIN measurement_groups mg ON mg.id = r.measurement_group_id
                WHERE r.is_active = 1 AND mg.is_active = 1
                ORDER BY r.priority DESC, r.id
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * The seed: what QuoteCalculator's current price rule for each service implies in minutes,
     * at the target margin and the hourly cost. Lot-specific because the rule is.
     */
    public function impliedModels(array $lot, array $card, array $products): array
    {
        if ($card['hourly_cost'] === null) return [];
        $out = [];
        try {
            if (!function_exists('calculateLineItemFromRule')) {
                require_once dirname(__DIR__, 3) . '/Services/QuoteCalculator.php';
            }
            foreach ($this->pricingRules() as $rule) {
                $prod = $products[(int)$rule['product_id']] ?? null;
                $key = $prod['service_key'] ?? null;
                if (!$key || isset($out[$key])) continue;
                // A per-yard rule prices material by the yard (mulch); it implies no labour minutes.
                if (($rule['pricing_model'] ?? '') === 'per_yard_area') continue;
                $units = SiteMinutesModel::units($key, $lot);
                if ($units === null) continue;
                $ruleUnits = $rule['unit'] === 'linear_ft'
                    ? ($key === 'edge' ? $lot['edge_ft'] : $lot['hedge_ft'])
                    : $lot['lawn_sqft'];
                $line = calculateLineItemFromRule($rule, (float)$ruleUnits, $prod + ['description' => ''], []);
                $m = SiteMinutesModel::impliedFromPrice((float)$line['unit_price'], $units, (float)($prod['base_cost'] ?? 0),
                                                        (float)$card['hourly_cost'], (float)$card['target_margin']);
                if ($m) $out[$key] = $m;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * The depot: a yard Tim set (closer_depot_lat/lng), else the business address geocoded once
     * and cached. Tried at most once a day when geocoding fails.
     */
    public function depot(array $card): ?array
    {
        if ($card['depot']) return $card['depot'];
        if (strpos((string)$card['depot_source'], 'failed ' . date('Y-m-d')) !== false) return null;
        try {
            $addr = (string)$this->db->query("SELECT company_address FROM business_settings ORDER BY id LIMIT 1")->fetchColumn();
            $key = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '');
            $hit = null;
            if (trim($addr) !== '' && $key !== '') {
                $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode($addr) . '&key=' . rawurlencode($key);
                $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 4]]));
                $j = $raw ? json_decode($raw, true) : null;
                $loc = $j['results'][0]['geometry']['location'] ?? null;
                if (($j['status'] ?? '') === 'OK' && $loc) $hit = ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
            }
            $desc = $hit ? 'Depot geocoded from business_settings.company_address — set a yard here to override'
                         : 'Depot geocode failed ' . date('Y-m-d');
            $up = $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
            $up->execute(['closer_depot_lat', $hit ? (string)$hit['lat'] : '', $desc]);
            $up->execute(['closer_depot_lng', $hit ? (string)$hit['lng'] : '', $desc]);
            return $hit;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ── Weekly recalibration ────────────────────────────────────────────────

    /**
     * Completed visits from the last 18 months, cleaned, per service:
     * service key => [['visit_id', 'units', 'obstacles', 'minutes', 'crew', 'quoted', 'date'], …]
     */
    public function trainingRows(): array
    {
        $edge = $this->hasColumn('properties', 'edge_linear_ft') ? 'p.edge_linear_ft' : 'NULL';
        $obs  = $this->hasColumn('properties', 'obstacle_count') ? 'p.obstacle_count' : 'NULL';
        $hedge = $this->hasColumn('properties', 'total_hedge_linear_ft') ? 'p.total_hedge_linear_ft' : 'NULL';
        $st = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.scheduled_date, jp.service_type,
                   p.total_lawn_sqft, p.lawn_size_sqft, {$edge} AS edge_linear_ft, {$obs} AS obstacle_count,
                   {$hedge} AS total_hedge_linear_ft
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            WHERE jv.status = 'completed'
              AND jv.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        ");
        $st->execute([self::TRAINING_MONTHS]);
        $visits = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$visits) return [];

        $entries = $this->timerEntries(array_map(function ($v) { return (int)$v['visit_id']; }, $visits));
        $quoted = $this->quotedByVisit(array_map(function ($v) { return (int)$v['visit_id']; }, $visits));
        $out = [];
        foreach ($visits as $v) {
            $key = CloserPricing::serviceKey((string)$v['service_type']);
            if (!$key) continue;
            $lot = self::lotFromRow($v);
            $units = SiteMinutesModel::units($key, $lot);
            $mins = SiteMinutesModel::visitMinutes($entries[(int)$v['visit_id']] ?? []);
            if (!SiteMinutesModel::usable($units, $mins)) continue;
            $out[$key][] = [
                'visit_id'  => (int)$v['visit_id'],
                'date'      => $v['scheduled_date'],
                'units'     => $units,
                'obstacles' => $lot['obstacles'],
                'minutes'   => $mins['person_minutes'],
                'crew'      => $mins['crew'],
                'quoted'    => $quoted[(int)$v['visit_id']] ?? null,
            ];
        }
        return $out;
    }

    private function timerEntries(array $visitIds): array
    {
        $out = [];
        $cols = 'visit_id, user_id, start_time, duration_minutes'
              . ($this->hasColumn('job_time_entries', 'end_time') ? ', end_time' : ', NULL AS end_time')
              . ($this->hasColumn('job_time_entries', 'time_type') ? ', time_type' : ", 'job' AS time_type")
              . ($this->hasColumn('job_time_entries', 'cluster_session_id') ? ', cluster_session_id' : ', NULL AS cluster_session_id')
              . ($this->hasColumn('job_time_entries', 'time_source') ? ', time_source' : ', NULL AS time_source');
        foreach (array_chunk($visitIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->db->prepare("SELECT {$cols} FROM job_time_entries WHERE status IN ('completed','edited') AND visit_id IN ({$in})");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $out[(int)$e['visit_id']][] = $e;
        }
        return $out;
    }

    private function quotedByVisit(array $visitIds): array
    {
        $out = [];
        try {
            foreach (array_chunk($visitIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $st = $this->db->prepare("SELECT visit_id, quoted_amount, material_cost FROM visit_margin_snapshots WHERE visit_id IN ({$in})");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ((float)$r['quoted_amount'] > 0) $out[(int)$r['visit_id']] = ['amount' => (float)$r['quoted_amount'], 'materials' => (float)$r['material_cost']];
                }
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Pure: realised margin per service over recent visits, from timer minutes at the hourly cost —
     * not from visit_margin_snapshots.labor_cost, which can hold the estimate (snapshot is taken
     * before the actual minutes are written).
     */
    public static function realisedMargin(array $rows, float $hourly, string $since): ?array
    {
        $rev = 0.0;
        $cost = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            if (empty($r['quoted']) || ($r['date'] ?? '') < $since) continue;
            $rev  += $r['quoted']['amount'];
            $cost += $r['minutes'] / 60 * $hourly + $r['quoted']['materials'];
            $n++;
        }
        if ($n === 0 || $rev <= 0) return null;
        return ['margin_pct' => round(($rev - $cost) / $rev * 100, 1), 'n' => $n];
    }

    /** Refit every service and record drift. Returns a summary for the cron log. */
    public function recalibrate(): array
    {
        $card = (new CloserRateCard($this->db))->load();
        $since = date('Y-m-d', strtotime('-' . self::DRIFT_DAYS . ' days'));
        $up = $this->db->prepare("
            INSERT INTO closer_site_models
                (service_key, source, fixed_minutes, per_unit_minutes, per_obstacle_minutes, n, mae_pct,
                 realised_margin_pct, realised_n, fitted_at)
            VALUES (?, 'fit', ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE fixed_minutes = VALUES(fixed_minutes), per_unit_minutes = VALUES(per_unit_minutes),
                per_obstacle_minutes = VALUES(per_obstacle_minutes), n = VALUES(n), mae_pct = VALUES(mae_pct),
                realised_margin_pct = VALUES(realised_margin_pct), realised_n = VALUES(realised_n), fitted_at = NOW()
        ");
        $summary = [];
        foreach ($this->trainingRows() as $key => $rows) {
            $m = SiteMinutesModel::fit($rows);
            $drift = $card['hourly_cost'] !== null ? self::realisedMargin($rows, (float)$card['hourly_cost'], $since) : null;
            $up->execute([$key, $m['fixed_minutes'], $m['per_unit_minutes'], $m['per_obstacle_minutes'], $m['n'], $m['mae_pct'],
                          $drift['margin_pct'] ?? null, $drift['n'] ?? 0]);
            $summary[$key] = ['n' => $m['n'], 'calibrated' => $m['n'] >= $card['calibration_min'], 'mae_pct' => $m['mae_pct'],
                              'realised_margin_pct' => $drift['margin_pct'] ?? null];
        }
        return $summary;
    }

    /** Fitted models with drift, for the rate-card form. */
    public function calibrationReport(): array
    {
        try {
            return $this->db->query("SELECT service_key, source, fixed_minutes, per_unit_minutes, per_obstacle_minutes, n, mae_pct,
                                            realised_margin_pct, realised_n, fitted_at, updated_at
                                     FROM closer_site_models ORDER BY service_key, source")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
