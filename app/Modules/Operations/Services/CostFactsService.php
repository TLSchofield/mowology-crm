<?php
/**
 * CostFactsService — Otto's shared cost facts (ops_cost_facts, migration 1218).
 *
 * How heads share facts cheaply: each fact is written ONCE by the head that owns it, by a cron,
 * and every other head reads a small summary row with plain SQL — never the raw truck trail and
 * never an AI call. Otto owns trip costs, so Otto writes these; Sam reads them when he suggests a
 * "Material pickup" / "Disposal run" line, Penny and Charlie read them for context.
 *
 * Facts (one-man runs with a pay rate only, so the cost is comparable run to run):
 *   run:dump:place:<id>      run:supplier:place:<id>      run:dump:any      run:supplier:any
 *
 * median_cost = median trip cost (labour + truck) + the median dump fee for dump runs. Supplier
 * receipts are NOT added: the materials are billed on their own quote line, so the pickup line
 * only charges the trip. Medians are computed in PHP (MySQL 5.7 has no window functions).
 *
 * refresh() is called at the end of the trip_runs_daily cron — never per page load.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CostFactsService
{
    public const KINDS = ['dump', 'supplier'];
    public const LABEL = ['dump' => 'Dump run', 'supplier' => 'Supply run'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM ops_cost_facts LIMIT 1");
            $this->db->query("SELECT 1 FROM ops_trip_runs LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function median(array $values): ?float
    {
        $v = array_values(array_map('floatval', $values));
        if (!$v) return null;
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /**
     * Build the facts from stored runs.
     * @param list<array{place_id, place_name, kind, run_date, drive_min, onsite_min, km, labour_cost, truck_cost, receipt_cost, receipt_ids, one_man, rate_missing}> $rows
     * @return array<string, array> fact_key => fact row
     */
    public static function build(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $kind = (string)$r['kind'];
            if (!in_array($kind, self::KINDS, true)) continue;
            if ((int)($r['one_man'] ?? 0) !== 1) continue;
            if (!empty($r['rate_missing']) || $r['labour_cost'] === null) continue;
            $pid = (int)$r['place_id'];
            $groups["run:{$kind}:place:{$pid}"]['meta'] = ['kind' => $kind, 'place_id' => $pid, 'label' => self::LABEL[$kind] . ' — ' . (string)$r['place_name']];
            $groups["run:{$kind}:place:{$pid}"]['rows'][] = $r;
            $groups["run:{$kind}:any"]['meta'] = ['kind' => $kind, 'place_id' => null, 'label' => self::LABEL[$kind] . ' — any place'];
            $groups["run:{$kind}:any"]['rows'][] = $r;
        }
        $out = [];
        foreach ($groups as $key => $g) {
            $onsite = $round = $km = $trip = $rcpt = [];
            $last = null;
            foreach ($g['rows'] as $r) {
                $onsite[] = (float)$r['onsite_min'];
                $round[] = (float)$r['onsite_min'] + (float)$r['drive_min'];
                $km[] = (float)$r['km'];
                $trip[] = (float)$r['labour_cost'] + (float)($r['truck_cost'] ?? 0);
                if (!empty($r['receipt_ids']) && (float)($r['receipt_cost'] ?? 0) > 0) $rcpt[] = (float)$r['receipt_cost'];
                if ($last === null || (string)$r['run_date'] > $last) $last = (string)$r['run_date'];
            }
            $kind = $g['meta']['kind'];
            $mTrip = self::median($trip);
            $mRcpt = self::median($rcpt);
            $addFee = $kind === 'dump' ? (float)($mRcpt ?? 0) : 0.0;
            $avgFee = $kind === 'dump' && $rcpt ? array_sum($rcpt) / count($rcpt) : 0.0;
            $out[$key] = [
                'fact_key' => $key, 'label' => $g['meta']['label'], 'kind' => $kind, 'place_id' => $g['meta']['place_id'],
                'sample_n' => count($g['rows']),
                'median_onsite_min' => round((float)self::median($onsite), 1),
                'median_round_trip_min' => round((float)self::median($round), 1),
                'median_km' => round((float)self::median($km), 2),
                'median_trip_cost' => round((float)$mTrip, 2),
                'median_receipt' => $mRcpt === null ? null : round($mRcpt, 2),
                'receipt_n' => count($rcpt),
                'median_cost' => round((float)$mTrip + $addFee, 2),
                'avg_cost' => round(array_sum($trip) / count($trip) + $avgFee, 2),
                'last_run_date' => $last,
            ];
        }
        ksort($out);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Writing (Otto, from the cron)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recompute every fact from ops_trip_runs and upsert it. Facts that no longer have runs
     * behind them are removed. Portable SQL (select, then update or insert).
     * @return array{facts: int, removed: int}
     */
    public function refresh(): array
    {
        $rows = $this->db->query("
            SELECT r.place_id, COALESCE(p.name, '') AS place_name, r.kind, r.run_date, r.drive_min, r.onsite_min, r.km,
                   r.labour_cost, r.truck_cost, r.receipt_cost, r.receipt_ids,
                   COALESCE(r.crew_override, r.one_man) AS one_man, r.rate_missing
            FROM ops_trip_runs r LEFT JOIN ops_places p ON p.id = r.place_id
            WHERE r.kind IN ('dump', 'supplier')
        ")->fetchAll(PDO::FETCH_ASSOC);
        $facts = self::build($rows);

        $find = $this->db->prepare("SELECT id FROM ops_cost_facts WHERE fact_key = ?");
        $upd = $this->db->prepare("
            UPDATE ops_cost_facts SET label = ?, kind = ?, place_id = ?, sample_n = ?, median_onsite_min = ?, median_round_trip_min = ?,
                   median_km = ?, median_trip_cost = ?, median_receipt = ?, receipt_n = ?, median_cost = ?, avg_cost = ?, last_run_date = ?
            WHERE id = ?
        ");
        $ins = $this->db->prepare("
            INSERT INTO ops_cost_facts (label, kind, place_id, sample_n, median_onsite_min, median_round_trip_min, median_km,
                   median_trip_cost, median_receipt, receipt_n, median_cost, avg_cost, last_run_date, fact_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($facts as $f) {
            $vals = [$f['label'], $f['kind'], $f['place_id'], $f['sample_n'], $f['median_onsite_min'], $f['median_round_trip_min'],
                     $f['median_km'], $f['median_trip_cost'], $f['median_receipt'], $f['receipt_n'], $f['median_cost'], $f['avg_cost'], $f['last_run_date']];
            $find->execute([$f['fact_key']]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                $upd->execute(array_merge($vals, [(int)$id]));
            } else {
                $ins->execute(array_merge($vals, [$f['fact_key']]));
            }
        }
        $removed = 0;
        foreach ($this->db->query("SELECT id, fact_key FROM ops_cost_facts WHERE fact_key LIKE 'run:%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($facts[$r['fact_key']])) {
                $this->db->prepare("DELETE FROM ops_cost_facts WHERE id = ?")->execute([(int)$r['id']]);
                $removed++;
            }
        }
        return ['facts' => count($facts), 'removed' => $removed];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading (every head) — one small row, no recomputation
    // ─────────────────────────────────────────────────────────────────────────

    public function get(string $factKey): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM ops_cost_facts WHERE fact_key = ? LIMIT 1");
            $s->execute([$factKey]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ? self::cast($r) : null;
        } catch (Throwable $e) {
            return null;   // migration 1218 not run — readers degrade to "not enough runs yet"
        }
    }

    /** @return list<array> facts of these kinds, "any" rows first, then busiest place */
    public function forKinds(array $kinds): array
    {
        $kinds = array_values(array_intersect($kinds, self::KINDS));
        if (!$kinds) return [];
        try {
            $s = $this->db->prepare("SELECT * FROM ops_cost_facts WHERE kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ")
                                     ORDER BY kind, place_id IS NOT NULL, sample_n DESC");
            $s->execute($kinds);
            return array_map([self::class, 'cast'], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function cast(array $r): array
    {
        foreach (['sample_n', 'receipt_n'] as $k) $r[$k] = (int)($r[$k] ?? 0);
        $r['place_id'] = $r['place_id'] === null ? null : (int)$r['place_id'];
        foreach (['median_onsite_min', 'median_round_trip_min', 'median_km', 'median_trip_cost', 'median_receipt', 'median_cost', 'avg_cost'] as $k) {
            $r[$k] = isset($r[$k]) && $r[$k] !== null ? (float)$r[$k] : null;
        }
        return $r;
    }
}
