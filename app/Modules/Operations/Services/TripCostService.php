<?php
/**
 * TripCostService — Penny prices Otto's overhead runs; Otto shows the baseline.
 *
 *   labour = (drive_min + onsite_min) / 60 × driver's hourly rate × (1 + burden%) × people in the truck
 *            rate: users.hourly_rate; for the owner with no rate, Owner Freedom's owner rate.
 *            burden: ops_settings freedom_burden_pct (Owner Freedom's, default 15).
 *            No rate → labour NULL, rate_missing = 1, and the card says "rate missing — set it".
 *   truck  = km × ops_settings truck_cost_per_km (seeded 0.70 by migration 1216, "edit me")
 *   receipts = the receipt(s) Penny time-matched to the stop (StopEvidenceService: printed time on
 *            the ticket / till slip, or photographed there) — else that day's expenses whose vendor
 *            matches the place (ops_places.vendor_match or its name), minus receipts time-matched to
 *            another stop. Penny's card nags while a run has none. This is "receipts on this run",
 *            NOT job cost: one supplier slip can hold job material and shop stock (2026-10-07 Lawn
 *            Boy: mulch for Oakridge + grass seed for stock) — per-line job attribution lives elsewhere.
 *   onsite = GPS minutes at the stop, or the scale ticket's Time In → Time Out when there is one.
 *
 * Before pricing, Otto asks Penny about the day (StopEvidenceService::resolveDay): strong evidence
 * names unnamed stops itself; a bank-line guess comes back as a Yes / No proposal.
 *
 * One ops_trip_runs row per overhead stop, idempotent on (run_date, place_id, arrived_at); a
 * re-run (after a stop is named, or the crew toggle) rewrites the numbers and keeps the toggle.
 * Baseline: one-man runs only, grouped by place; the median is computed here (MySQL 5.7).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/TripSegmentService.php';
require_once __DIR__ . '/StopEvidenceService.php';

class TripCostService
{
    public const DEFAULT_TRUCK_PER_KM = 0.70;
    public const KIND_LABEL = ['dump' => 'Dump run', 'supplier' => 'Supply run', 'fuel' => 'Fuel stop', 'other' => 'Run', 'yard' => 'Yard'];

    private PDO $db;
    public TripSegmentService $seg;
    public StopEvidenceService $ev;
    private ?array $settings = null;

    public function __construct(PDO $db, ?TripSegmentService $seg = null, ?StopEvidenceService $ev = null)
    {
        $this->db = $db;
        $this->seg = $seg ?? new TripSegmentService($db);
        $this->ev = $ev ?? new StopEvidenceService($db, $this->seg);
    }

    public function ready(): bool
    {
        return $this->seg->ready();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Settings and rates
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{per_km: float, per_km_default: bool, burden_pct: float, owner_id: int, owner_rate: float} */
    public function settings(): array
    {
        if ($this->settings !== null) return $this->settings;
        $perKm = null;
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'truck_cost_per_km' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) $perKm = (float)$v;
        } catch (Throwable $e) { /* default below */ }
        $burden = 15.0; $ownerId = 0; $ownerRate = 0.0;
        try {
            require_once dirname(__DIR__, 2) . '/Accounting/Services/OwnerFreedomService.php';
            $f = (new OwnerFreedomService($this->db))->settings();
            $burden = (float)$f['burden_pct'];
            $ownerId = (int)$f['owner_user_id'];
            $ownerRate = (float)$f['owner_rate'];
        } catch (Throwable $e) { /* Owner Freedom unavailable — 15% burden, no owner rate */ }
        return $this->settings = [
            'per_km' => $perKm ?? self::DEFAULT_TRUCK_PER_KM, 'per_km_default' => $perKm === null || abs($perKm - self::DEFAULT_TRUCK_PER_KM) < 0.001,
            'burden_pct' => $burden, 'owner_id' => $ownerId, 'owner_rate' => $ownerRate,
        ];
    }

    /** @return array{name: string, rate: ?float} */
    public function person(?int $userId): array
    {
        if (!$userId) return ['name' => '', 'rate' => null];
        try {
            $s = $this->db->prepare("SELECT full_name, hourly_rate FROM users WHERE id = ? LIMIT 1");
            $s->execute([$userId]);
            $u = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $u = [];
        }
        $rate = isset($u['hourly_rate']) && (float)$u['hourly_rate'] > 0 ? (float)$u['hourly_rate'] : null;
        $st = $this->settings();
        if ($rate === null && $userId === $st['owner_id'] && $st['owner_rate'] > 0) $rate = $st['owner_rate'];
        return ['name' => (string)($u['full_name'] ?? ''), 'rate' => $rate];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Receipts
    // ─────────────────────────────────────────────────────────────────────────

    /** Words that identify a place on a receipt vendor. */
    public static function vendorWords(array $place): array
    {
        $raw = (string)($place['vendor_match'] ?? '');
        $words = $raw !== '' ? explode('|', $raw) : [(string)($place['name'] ?? '')];
        return array_values(array_filter(array_map(fn($w) => strtolower(trim($w)), $words), fn($w) => strlen($w) >= 3));
    }

    /** Does a vendor string belong to this place? */
    public static function vendorMatches(string $vendor, array $words): bool
    {
        $v = strtolower($vendor);
        foreach ($words as $w) if ($w !== '' && strpos($v, $w) !== false) return true;
        return false;
    }

    /**
     * That day's non-rejected expenses at this place: {ids: int[], total: float}.
     * @param int[] $exclude receipts Penny time-matched to a different stop that day
     */
    public function receipts(string $date, array $place, array $exclude = []): array
    {
        $words = self::vendorWords($place);
        if (!$words) return ['ids' => [], 'total' => 0.0];
        try {
            $s = $this->db->prepare("
                SELECT e.id, e.total, COALESCE(v.name, '') AS vendor, COALESCE(e.vendor_name_raw, '') AS vendor_raw
                FROM expenses e
                LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.expense_date = ? AND e.status <> 'rejected'
            ");
            $s->execute([$date]);
            $ids = []; $total = 0.0;
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (in_array((int)$r['id'], $exclude, true)) continue;
                if (self::vendorMatches($r['vendor'] . ' ' . $r['vendor_raw'], $words)) {
                    $ids[] = (int)$r['id'];
                    $total += (float)$r['total'];
                }
            }
            return ['ids' => $ids, 'total' => round($total, 2)];
        } catch (Throwable $e) {
            error_log('TripCost receipts: ' . $e->getMessage());
            return ['ids' => [], 'total' => 0.0];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure costing (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{labour: ?float, truck: float, receipts: float, total: float, rate_missing: bool}
     */
    public static function cost(float $driveMin, float $onsiteMin, float $km, ?float $rate, float $burdenPct, int $people, float $perKm, float $receipts): array
    {
        $labour = $rate === null ? null : round(($driveMin + $onsiteMin) / 60 * $rate * (1 + $burdenPct / 100) * max(1, $people), 2);
        $truck = round($km * $perKm, 2);
        return [
            'labour' => $labour, 'truck' => $truck, 'receipts' => round($receipts, 2),
            'total' => round(($labour ?? 0) + $truck + $receipts, 2), 'rate_missing' => $rate === null,
        ];
    }

    public static function median(array $values): ?float
    {
        $v = array_values(array_map('floatval', $values));
        if (!$v) return null;
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /**
     * Baseline per place from stored one-man rows.
     * @param list<array{place_id, name, kind, drive_min, onsite_min, km, total, rate_missing}> $rows
     * @return list<array{place_id: int, name: string, kind: string, runs: int, onsite_median: float, onsite_avg: float,
     *                    round_trip_avg: float, km_avg: float, cost_avg: float, priced: int}>
     */
    public static function aggregate(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $k = (int)$r['place_id'];
            $by[$k]['name'] = (string)$r['name'];
            $by[$k]['kind'] = (string)$r['kind'];
            $by[$k]['onsite'][] = (float)$r['onsite_min'];
            $by[$k]['round'][] = (float)$r['onsite_min'] + (float)$r['drive_min'];
            $by[$k]['km'][] = (float)$r['km'];
            if (empty($r['rate_missing']) && $r['total'] !== null) $by[$k]['cost'][] = (float)$r['total'];
        }
        $out = [];
        $avg = fn(array $a) => $a ? round(array_sum($a) / count($a), 1) : 0.0;
        foreach ($by as $id => $b) {
            $out[] = [
                'place_id' => $id, 'name' => $b['name'], 'kind' => $b['kind'], 'runs' => count($b['onsite']),
                'onsite_median' => round((float)self::median($b['onsite']), 1), 'onsite_avg' => $avg($b['onsite']),
                'round_trip_avg' => $avg($b['round']), 'km_avg' => $avg($b['km']),
                'cost_avg' => isset($b['cost']) ? round(array_sum($b['cost']) / count($b['cost']), 2) : null,
                'priced' => count($b['cost'] ?? []),
            ];
        }
        usort($out, fn($a, $b) => [$a['kind'], -$a['runs']] <=> [$b['kind'], -$b['runs']]);
        return $out;
    }

    /** "Dump run, one man: 6 runs, avg 12 min there, 35 min round trip, $41" */
    public static function baselineLine(array $b): string
    {
        $kind = self::KIND_LABEL[$b['kind']] ?? 'Run';
        return $kind . ' (' . $b['name'] . '), one man: ' . $b['runs'] . ' run' . ($b['runs'] === 1 ? '' : 's')
            . ', avg ' . self::mins($b['onsite_avg']) . ' there, ' . self::mins($b['round_trip_avg']) . ' round trip, '
            . rtrim(rtrim(number_format($b['km_avg'], 1), '0'), '.') . ' km'
            . ($b['cost_avg'] !== null ? ', $' . number_format($b['cost_avg'], 0) : ', cost needs a pay rate');
    }

    public static function mins(float $m): string
    {
        $m = (int)round($m);
        return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Processing a day
    // ─────────────────────────────────────────────────────────────────────────

    /** Owner toggles kept on a date's rows: [trip_key => 1|0]. */
    public function overrides(string $date): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT trip_key, crew_override FROM ops_trip_runs WHERE run_date = ? AND crew_override IS NOT NULL");
            $s->execute([$date]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['trip_key']] = (int)$r['crew_override'];
        } catch (Throwable $e) { /* none */ }
        return $out;
    }

    /**
     * The day, priced: each run gets crew, driver, rate and per-leg cost. Read-only.
     * @return array the day from TripSegmentService::day() with runs[].cost and runs[].legs[].cost
     */
    public function pricedDay(string $date): array
    {
        $penny = $this->ev->resolveDay($date);   // may name stops (creates places) before the day is split
        $day = $this->seg->day($date, $this->overrides($date));
        $st = $this->settings();
        $places = [];
        foreach ($this->seg->places() as $p) $places[(int)$p['id']] = $p;
        $claimed = [];   // a receipt belongs to one stop a day — the first that claims it (2026-10-07: #412 was counted on two Lawnboy runs)
        foreach ($day['runs'] as &$run) {
            $c = $run['crew'];
            $driver = $this->person($c['driver_id']);
            $people = $c['one_man'] === false ? max(2, (int)$c['crew_count']) : 1;
            $run['driver'] = ['id' => $c['driver_id'], 'name' => $driver['name'], 'rate' => $driver['rate']];
            $run['people'] = $people;
            $run['total'] = 0.0;
            foreach ($run['legs'] as &$leg) {
                $evs = $penny['stops'][$leg['arrived_at']] ?? [];
                $leg = self::applyEvidence($leg, $evs);
                if ($evs) {
                    // Prefer the time-matched receipt(s) over the vendor-word match.
                    $rc = ['ids' => array_column($evs, 'receipt_id'), 'total' => round(array_sum(array_column($evs, 'total')), 2)];
                } elseif ($leg['place_id'] && isset($places[$leg['place_id']])) {
                    $rc = $this->receipts($date, $places[$leg['place_id']], array_merge(self::matchedElsewhere($penny['stops'], $leg['arrived_at']), $claimed));
                } else {
                    $rc = ['ids' => [], 'total' => 0.0];
                }
                $leg['receipt_ids'] = $rc['ids'];
                $claimed = array_merge($claimed, array_map('intval', $rc['ids']));
                $leg['cost'] = self::cost($leg['drive_min'], $leg['onsite_min'], $leg['km'], $c['driver_id'] ? $driver['rate'] : null, $st['burden_pct'], $people, $st['per_km'], $rc['total']);
                $run['total'] += $leg['cost']['total'];
            }
            unset($leg);
            $run['total'] = round($run['total'], 2);
        }
        unset($run);
        $day['settings'] = $st;
        $day['evidence'] = $penny['stops'];
        $day['proposals'] = $penny['weak'];
        $day['penny_named'] = $penny['created'];
        $day['penny_places'] = $penny['penny_places'];
        return $day;
    }

    /**
     * A leg with Penny's evidence: the first one shown, and a scale ticket's Time In → Time Out as
     * the minutes on site (onsite_basis 'ticket'), else GPS.
     */
    public static function applyEvidence(array $leg, array $evs): array
    {
        $leg['evidence'] = $evs[0] ?? null;
        $leg['evidence_line'] = StopEvidenceService::evidenceLine($evs[0] ?? null);
        $leg['onsite_basis'] = 'gps';
        $tk = $evs[0]['ticket'] ?? null;
        if ($tk) {
            $leg['onsite_min'] = (float)$tk['minutes'];
            $leg['onsite_basis'] = 'ticket';
            $leg['ticket_in'] = $tk['in'];
            $leg['ticket_out'] = $tk['out'];
        }
        return $leg;
    }

    /** Receipt ids Penny time-matched to stops other than the one starting at $start. */
    public static function matchedElsewhere(array $byStop, int $start): array
    {
        $ids = [];
        foreach ($byStop as $st => $list) {
            if ((int)$st === $start) continue;
            foreach ($list as $e) if ($e['receipt_id']) $ids[] = (int)$e['receipt_id'];
        }
        return $ids;
    }

    /**
     * Store a day's finished runs. Runs still away at the last ping are skipped today and stored
     * as-is for past dates. Rows for the date that no longer match a stop are removed.
     * @return array{stored: int, removed: int, open: int, unnamed: int}
     */
    public function process(string $date): array
    {
        $day = $this->pricedDay($date);
        $today = $date >= date('Y-m-d');
        $keep = [];
        $stored = 0; $open = 0;
        $ev = $this->ev->ready1217();   // migration 1217: the evidence line + where onsite_min came from
        $up = $this->db->prepare("
            INSERT INTO ops_trip_runs
                (run_date, trip_key, place_id, kind, user_id, crew_count, one_man, crew_basis, from_property_id, return_property_id,
                 left_at, arrived_at, departed_at, returned_at, drive_min, onsite_min, km, labour_cost, truck_cost, receipt_cost,
                 receipt_ids, total, rate_missing, ping_from, ping_to" . ($ev ? ", evidence, onsite_basis" : "") . ")
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($ev ? ", ?, ?" : "") . ")
            ON DUPLICATE KEY UPDATE" . ($ev ? " evidence = VALUES(evidence), onsite_basis = VALUES(onsite_basis)," : "") . " trip_key = VALUES(trip_key), kind = VALUES(kind), user_id = VALUES(user_id), crew_count = VALUES(crew_count),
                one_man = VALUES(one_man), crew_basis = VALUES(crew_basis), from_property_id = VALUES(from_property_id),
                return_property_id = VALUES(return_property_id), left_at = VALUES(left_at), departed_at = VALUES(departed_at),
                returned_at = VALUES(returned_at), drive_min = VALUES(drive_min), onsite_min = VALUES(onsite_min), km = VALUES(km),
                labour_cost = VALUES(labour_cost), truck_cost = VALUES(truck_cost), receipt_cost = VALUES(receipt_cost),
                receipt_ids = VALUES(receipt_ids), total = VALUES(total), rate_missing = VALUES(rate_missing),
                ping_from = VALUES(ping_from), ping_to = VALUES(ping_to)
        ");
        $dt = fn(?int $t) => $t === null ? null : date('Y-m-d H:i:s', $t);
        foreach ($day['runs'] as $run) {
            if ($run['returned_at'] === null && $today) { $open++; continue; }
            $c = $run['crew'];
            foreach ($run['legs'] as $leg) {
                if (!$leg['place_id']) continue;
                $cost = $leg['cost'];
                $vals = [
                    $date, $run['trip_key'], $leg['place_id'], $leg['kind'], $c['driver_id'], $run['people'],
                    $c['one_man'] === null ? null : ($c['one_man'] ? 1 : 0), $c['basis'],
                    $run['from']['property_id'] ?? null, $run['to']['property_id'] ?? null,
                    $dt($run['left_at']), $dt($leg['arrived_at']), $dt($leg['departed_at']), $dt($run['returned_at']),
                    $leg['drive_min'], $leg['onsite_min'], $leg['km'], $cost['labour'], $cost['truck'], $cost['receipts'],
                    $leg['receipt_ids'] ? implode(',', $leg['receipt_ids']) : null, $cost['total'], $cost['rate_missing'] ? 1 : 0,
                    $dt($run['left_at']), $dt($run['returned_at'] ?? $leg['departed_at']),
                ];
                if ($ev) {
                    $vals[] = $leg['evidence_line'] !== '' ? mb_substr($leg['evidence_line'], 0, 255) : null;
                    $vals[] = $leg['onsite_basis'];
                }
                $up->execute($vals);
                $keep[] = $leg['place_id'] . '@' . $dt($leg['arrived_at']);
                $stored++;
            }
        }
        $removed = 0;
        $s = $this->db->prepare("SELECT id, place_id, arrived_at FROM ops_trip_runs WHERE run_date = ?");
        $s->execute([$date]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array($r['place_id'] . '@' . $r['arrived_at'], $keep, true)) {
                $this->db->prepare("DELETE FROM ops_trip_runs WHERE id = ?")->execute([(int)$r['id']]);
                $removed++;
            }
        }
        return ['stored' => $stored, 'removed' => $removed, 'open' => $open, 'unnamed' => count($day['unnamed'])];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Owner actions
    // ─────────────────────────────────────────────────────────────────────────

    /** Name an unnamed stop once: creates the place, then re-prices that day. */
    public function nameStop(float $lat, float $lng, string $name, string $kind, int $userId, ?string $date = null): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'Give the place a name (up to 120 characters).'];
        if (!in_array($kind, ['dump', 'supplier', 'yard', 'fuel', 'other'], true)) return ['ok' => false, 'error' => 'Pick what kind of place it is.'];
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) return ['ok' => false, 'error' => 'That stop has no position.'];
        foreach ($this->seg->places() as $p) {
            if (TripSegmentService::meters($lat, $lng, (float)$p['lat'], (float)$p['lng']) <= (float)$p['radius_m']) {
                return ['ok' => false, 'error' => 'That spot is already ' . $p['name'] . '.'];
            }
        }
        try {
            $this->db->prepare("INSERT INTO ops_places (name, kind, lat, lng, radius_m, vendor_match, created_by) VALUES (?, ?, ?, ?, 150, ?, ?)")
                     ->execute([$name, $kind, round($lat, 7), round($lng, 7), strtolower($name), $userId ?: null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') return ['ok' => false, 'error' => 'There is already a place called ' . $name . '.'];
            throw $e;
        }
        $id = (int)$this->db->lastInsertId();
        $res = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $this->process($date) : null;
        return ['ok' => true, 'place_id' => $id, 'processed' => $res];
    }

    /**
     * The owner's answer to Penny's guess for an unnamed stop (a supplier charge on the bank feed).
     * Yes → the place is created (source 'confirmed') and the day re-priced; No → remembered, never
     * offered again for that spot, and the stop stays open for the name form.
     */
    public function confirmStop(float $lat, float $lng, string $name, string $kind, ?int $vendorId, bool $yes, int $userId, string $date, string $evidence = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok' => false, 'error' => 'Invalid date.'];
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'Unknown guess.'];
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) return ['ok' => false, 'error' => 'That stop has no position.'];
        if (!$yes) {
            return $this->ev->reject($date, $lat, $lng, $name, $userId ?: null)
                ? ['ok' => true, 'rejected' => true]
                : ['ok' => false, 'error' => 'Could not remember that answer (migration 1217 needed).'];
        }
        if (!in_array($kind, ['dump', 'supplier', 'fuel', 'other'], true)) $kind = 'supplier';
        $id = $this->ev->createPlace($name, $kind, $lat, $lng, $vendorId ?: null,
            mb_substr(trim($evidence) !== '' ? trim($evidence) . ' — you said yes' : 'You said yes to Penny\'s guess', 0, 255), 'confirmed', $userId ?: null);
        if (!$id) return ['ok' => false, 'error' => 'That spot is already a known place.'];
        return ['ok' => true, 'place_id' => $id, 'processed' => $this->process($date)];
    }

    /** The owner's one-man / two-man toggle for a run (null clears it), then re-price the day. */
    public function setCrew(string $tripKey, ?int $oneMan): array
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})@\d{2}:\d{2}$/', $tripKey, $m)) return ['ok' => false, 'error' => 'Unknown run.'];
        $this->process($m[1]);   // today's finished runs aren't stored until the toggle or the overnight cron
        $s = $this->db->prepare("UPDATE ops_trip_runs SET crew_override = ? WHERE trip_key = ?");
        $s->execute([$oneMan === null ? null : ($oneMan ? 1 : 0), $tripKey]);
        if ($s->rowCount() === 0) {
            $c = $this->db->prepare("SELECT COUNT(*) FROM ops_trip_runs WHERE trip_key = ?");
            $c->execute([$tripKey]);
            if ((int)$c->fetchColumn() === 0) return ['ok' => false, 'error' => 'That run is not stored yet — it is saved once the truck is back.'];
        }
        return ['ok' => true, 'processed' => $this->process($m[1])];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────────

    /** One-man baseline per place. */
    public function baseline(): array
    {
        $rows = $this->db->query("
            SELECT r.place_id, p.name, r.kind, r.drive_min, r.onsite_min, r.km, r.total, r.rate_missing
            FROM ops_trip_runs r JOIN ops_places p ON p.id = r.place_id
            WHERE r.one_man = 1 AND r.kind IN ('dump', 'supplier', 'fuel', 'other')
        ")->fetchAll(PDO::FETCH_ASSOC);
        return self::aggregate($rows);
    }

    /**
     * Stored runs with no receipt linked yet (last $days days) — re-checked now, so a receipt filed
     * since the cron links itself and drops off Penny's card.
     * @return list<array{id: int, run_date: string, kind: string, name: string}>
     */
    public function missingReceipts(int $days = 14): array
    {
        $s = $this->db->prepare("
            SELECT r.id, r.run_date, r.kind, r.place_id, r.labour_cost, r.truck_cost, r.arrived_at, r.departed_at,
                   p.name, p.vendor_match, p.lat, p.lng
            FROM ops_trip_runs r JOIN ops_places p ON p.id = r.place_id
            WHERE r.receipt_ids IS NULL AND r.kind IN ('dump', 'supplier') AND r.run_date >= ?
            ORDER BY r.run_date DESC, r.arrived_at DESC
            LIMIT 20
        ");
        $s->execute([date('Y-m-d', strtotime("-{$days} days"))]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // A receipt whose printed time (or photo) puts it at this stop wins over the vendor words.
            $e = $this->ev->receiptForWindow((string)$r['run_date'], (int)strtotime((string)$r['arrived_at']),
                (int)strtotime((string)$r['departed_at']), (float)$r['lat'], (float)$r['lng']);
            $rc = $e ? ['ids' => [(int)$e['receipt_id']], 'total' => (float)$e['total']] : $this->receipts((string)$r['run_date'], $r);
            if ($rc['ids']) {
                $this->db->prepare("UPDATE ops_trip_runs SET receipt_ids = ?, receipt_cost = ?, total = COALESCE(labour_cost, 0) + COALESCE(truck_cost, 0) + ? WHERE id = ?")
                         ->execute([implode(',', $rc['ids']), $rc['total'], $rc['total'], (int)$r['id']]);
                continue;
            }
            $out[] = ['id' => (int)$r['id'], 'run_date' => (string)$r['run_date'], 'kind' => (string)$r['kind'], 'name' => (string)$r['name']];
        }
        return $out;
    }

    /**
     * Charlie's brief items ({key, kind, value, since, text, url, priority}): Penny's guesses for
     * today's unnamed stops, for a Yes / No on Otto's card. Otto asks Penny before asking Tim, so a
     * stop with no evidence yet is NOT in the brief — it stays open on the card and is re-checked as
     * receipts and bank lines arrive; stops Penny can prove are named without asking anyone.
     */
    public function briefItems(string $date): array
    {
        if (!$this->ready()) return [];
        $penny = $this->ev->resolveDay($date);   // names what Penny can prove
        if (!$penny['weak']) return [];
        $out = [];
        foreach (TripSegmentService::unnamedStops(TripSegmentService::segments(
            $pings = $this->seg->pings($date), $this->seg->propertiesNear($pings), $this->seg->places())) as $u) {
            $g = $penny['weak'][$u['start']] ?? null;
            if (!$g) continue;
            $out[] = [
                'key' => 'otto:unnamed-stop:' . $date . ':' . number_format($u['lat'], 4, '.', '') . ',' . number_format($u['lng'], 4, '.', ''),
                'kind' => 'unnamed_stop', 'priority' => 3, 'value' => (int)round($u['minutes']), 'since' => $date,
                'text' => 'Penny thinks the truck\'s ' . date('g:i', $u['start']) . ' stop (' . self::mins((float)$u['minutes']) . ') was '
                    . $g['name'] . ' (' . StopEvidenceService::evidenceLine($g) . '). Yes or no on my card.',
                'url' => '/crm/dashboard_appstack.php#mw-otto-trips',
            ];
        }
        return $out;
    }

    /** "Dump run 7 Oct — no dump receipt yet" */
    public static function missingLine(array $m): string
    {
        $what = $m['kind'] === 'dump' ? 'dump receipt' : 'receipt from ' . $m['name'];
        return (self::KIND_LABEL[$m['kind']] ?? 'Run') . ' ' . date('j M', strtotime($m['run_date'])) . ' — no ' . $what . ' yet';
    }
}
