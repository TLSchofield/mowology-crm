<?php
declare(strict_types=1);

/**
 * OttoHarvestService — the four brief items harvested from the operations / small-business plugins
 * (2026-11-03, plan docs/plans/2026-11-03-otto-harvest.md): last week's clock review, the week's load
 * against crew capacity, the dated seasonal stock watch, the quarterly supplier review.
 *
 * Reads only (the rules are the pure classes beside it). Called from OpsDeskService::brief() inside its
 * own try: Charlie's brief, the team card and the iPhone get the lines; nothing new is persisted except
 * the one-time seed of ops_settings otto_seasonal_stock.
 *
 * No namespace / no autoloader: require_once and `new OttoHarvestService($db, $today)`.
 */
require_once __DIR__ . '/ClockWeekRules.php';
require_once __DIR__ . '/WeekLoadRules.php';
require_once __DIR__ . '/SeasonalStockRules.php';
require_once __DIR__ . '/SupplierReviewRules.php';

class OttoHarvestService
{
    public const CREW_COUNT_KEY = 'otto_crew_count';
    public const SEASONAL_KEY = 'otto_seasonal_stock';

    private PDO $db;
    private string $today;
    private string $now;

    public function __construct(PDO $db, ?string $today = null, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now ?? date('Y-m-d H:i:s');
        $this->today = $today ?? substr($this->now, 0, 10);
    }

    /** Every item, each part failing on its own. */
    public function briefItems(): array
    {
        $out = [];
        foreach (['clockWeekItems', 'weekLoadItems', 'seasonalStockItems', 'supplierReviewItems'] as $part) {
            try {
                foreach ($this->$part() as $it) $out[] = $it;
            } catch (Throwable $e) {
                error_log("Otto harvest: {$part} failed: " . $e->getMessage());
            }
        }
        return $out;
    }

    private function setting(string $key): ?string
    {
        $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    // ── A. last week's clock ──────────────────────────────────────────────────

    public function clockWeekItems(): array
    {
        if (!ClockWeekRules::shownToday($this->today)) return [];
        $monday = ClockWeekRules::lastWeekMonday($this->today);
        $from = date('Y-m-d', strtotime($monday . ' -' . (7 * ClockWeekRules::HISTORY_WEEKS) . ' days'));
        $end = date('Y-m-d', strtotime($monday . ' +7 days'));
        $st = $this->db->prepare(
            "SELECT t.id, t.user_id, t.clock_in, t.clock_out, t.total_minutes,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.full_name, u.username) AS name, u.hourly_rate
               FROM time_clock_entries t
               JOIN users u ON u.id = t.user_id
              WHERE t.clock_in >= ? AND t.clock_in < ? AND COALESCE(u.is_vehicle_tablet, 0) = 0
              ORDER BY t.user_id, t.clock_in"
        );
        $st->execute([$from . ' 00:00:00', $end . ' 00:00:00']);
        $people = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['user_id'];
            $people[$uid] = $people[$uid] ?? ['user_id' => $uid, 'name' => (string)$r['name'], 'rate' => $r['hourly_rate'] !== null ? (float)$r['hourly_rate'] : null, 'week' => [], 'hist' => []];
            $wk = ClockWeekRules::lastWeekMonday(date('Y-m-d', strtotime((string)$r['clock_in']) + 7 * 86400));   // the Monday of the entry's own week
            if ($wk === $monday) $people[$uid]['week'][] = $r;
            else $people[$uid]['hist'][$wk][] = $r;
        }
        $per = [];
        foreach ($people as $uid => $p) {
            $history = [];
            for ($i = 1; $i <= ClockWeekRules::HISTORY_WEEKS; $i++) {
                $wk = date('Y-m-d', strtotime($monday . " -{$i} weeks"));
                $history[] = isset($p['hist'][$wk]) ? ClockWeekRules::hours($p['hist'][$wk], $this->now) : 0.0;
            }
            $prevWk = date('Y-m-d', strtotime($monday . ' -1 week'));
            $neighbours = $p['hist'][$prevWk] ?? [];   // the week before, for overlaps across Sunday night
            $per[] = ['user_id' => $uid, 'flags' => ClockWeekRules::person($p['name'], $p['week'], $history, $p['rate'] > 0 ? $p['rate'] : null, $monday, $this->now, $neighbours)];
        }
        return ClockWeekRules::briefItems($per, $monday, $this->today);
    }

    // ── B. the week's load ────────────────────────────────────────────────────

    public function crewCount(): int
    {
        $v = $this->setting(self::CREW_COUNT_KEY);
        if ($v !== null && (int)$v > 0) return (int)$v;
        return (int)$this->db->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND COALESCE(is_vehicle_tablet, 0) = 0 AND role IN ('user', 'manager')")->fetchColumn();
    }

    public function weekLoadItems(): array
    {
        $end = date('Y-m-d', strtotime($this->today . ' +' . (WeekLoadRules::DAYS - 1) . ' days'));
        $days = $this->dayLoads($this->today, $end);
        $crew = $this->crewCount();
        $item = WeekLoadRules::briefItem(WeekLoadRules::days($days, $crew), $crew, $this->today);
        return $item ? [$item] : [];
    }

    /** Planned crew-minutes per day between two dates: date => {minutes, visits, untimed, biggest}. Also read by LeadRouteDay. */
    public function dayLoads(string $from, string $end): array
    {
        $st = $this->db->prepare(
            "SELECT jv.scheduled_date, jp.id AS plan_id, COALESCE(jp.title, '') AS title,
                    jp.estimated_duration_minutes AS est, GREATEST(COALESCE(jp.default_crew_size, 1), 1) AS crew
               FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
              WHERE jv.scheduled_date BETWEEN ? AND ? AND jv.status = 'scheduled'"
        );
        $st->execute([$from, $end]);
        $days = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (string)$r['scheduled_date'];
            $days[$d] = $days[$d] ?? ['minutes' => 0, 'visits' => 0, 'untimed' => 0, 'biggest' => null];
            $days[$d]['visits']++;
            if ($r['est'] === null || (int)$r['est'] <= 0) { $days[$d]['untimed']++; continue; }   // no length: unknown, never zero
            $est = (int)$r['est']; $crew = (int)$r['crew'];
            $days[$d]['minutes'] += $est * $crew;
            if ($days[$d]['biggest'] === null || $est > $days[$d]['biggest']['minutes']) {
                $days[$d]['biggest'] = ['plan_id' => (int)$r['plan_id'], 'title' => (string)$r['title'], 'minutes' => $est, 'crew' => $crew];
            }
        }
        ksort($days);
        return $days;
    }

    // ── C. the seasonal stock watch ───────────────────────────────────────────

    /** The watch list; seeded once when the business runs snow routes. */
    public function watchList(): array
    {
        $raw = $this->setting(self::SEASONAL_KEY);
        if ($raw !== null) {
            $w = json_decode($raw, true);
            return is_array($w) ? $w : [];
        }
        if (!$this->snowRoutes()) return [];
        $seed = SeasonalStockRules::seed();
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = setting_value")
                ->execute([self::SEASONAL_KEY, (string)json_encode($seed),
                           'Otto: seasonal stock watch — items the owner names, each with a receipt pattern, season start (MM-DD), lead days and what makes it needed (snow_routes). Decide-by = start − lead.']);
        } catch (Throwable $e) {
            error_log('Otto harvest: could not seed the seasonal watch: ' . $e->getMessage());
        }
        return $seed;
    }

    private function snowRoutes(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(DISTINCT jp.id) FROM job_plans jp WHERE jp.status = 'active' AND EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jp.id)")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function seasonalStockItems(): array
    {
        $watch = $this->watchList();
        if (!$watch) return [];
        $snow = $this->snowRoutes();
        $last = [];
        $st = $this->db->prepare(
            "SELECT MAX(e.expense_date) FROM expense_line_items li JOIN expenses e ON e.id = li.expense_id
              WHERE e.expense_date >= ? AND LOWER(COALESCE(li.name, '')) REGEXP ?"
        );
        $good = [];
        foreach ($watch as $i => $w) {
            $pattern = (string)($w['pattern'] ?? '');
            if (!SeasonalStockRules::patternOk($pattern) || !preg_match('/^\d{2}-\d{2}$/', (string)($w['season_start'] ?? ''))) {
                error_log('Otto harvest: seasonal watch item ' . $i . ' skipped — bad pattern or season_start in ops_settings ' . self::SEASONAL_KEY);
                continue;   // one bad setting never hides the others (Codex 2026-11-03)
            }
            try {
                $start = SeasonalStockRules::seasonStart((string)$w['season_start'], $this->today);
                $st->execute([date('Y-m-d', strtotime($start . ' -' . SeasonalStockRules::LOOKBACK_DAYS . ' days')), $pattern]);
                $d = $st->fetchColumn();
                $last[$i] = $d ? substr((string)$d, 0, 10) : null;
                $good[$i] = $w;
            } catch (Throwable $e) {
                error_log('Otto harvest: seasonal watch item ' . $i . ' failed: ' . $e->getMessage());
            }
        }
        $watch = $good;
        return SeasonalStockRules::items($watch, $last, ['snow_routes' => $snow > 0], ['snow_routes' => $snow], $this->today);
    }

    // ── D. the quarterly supplier review ──────────────────────────────────────

    public function supplierReviewItems(): array
    {
        $since = date('Y-m-d', strtotime($this->today . ' -' . SupplierReviewRules::WINDOW_DAYS . ' days'));
        $st = $this->db->prepare(
            "SELECT COALESCE(v.name, e.vendor_name_raw, '') AS vendor, COALESCE(e.accounting_category, v.default_accounting_category, '') AS category,
                    SUM(e.amount) AS spend, COUNT(*) AS n
               FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
              WHERE e.expense_date > ? AND COALESCE(e.status, '') <> 'rejected'
              GROUP BY vendor, category"
        );
        $st->execute([$since]);
        $spend = $st->fetchAll(PDO::FETCH_ASSOC);
        $alts = [];
        foreach ($this->db->query("SELECT COALESCE(default_accounting_category, '') AS c, COUNT(*) AS n FROM vendors WHERE is_active = 1 GROUP BY c") as $r) {
            $alts[(string)$r['c']] = (int)$r['n'];
        }
        $lists = [];
        try {
            foreach ($this->db->query("SELECT sa.name, MAX(COALESCE(l.list_date, DATE(l.imported_at))) AS d FROM supplier_price_lists l JOIN supplier_accounts sa ON sa.id = l.supplier_account_id GROUP BY sa.name") as $r) {
                $lists[(string)$r['name']] = $r['d'] ? (string)$r['d'] : null;
            }
        } catch (Throwable $e) { /* no price lists yet */ }
        $item = SupplierReviewRules::briefItem(SupplierReviewRules::build($spend, $alts, $lists, $this->today), $this->today);
        return $item ? [$item] : [];
    }
}
