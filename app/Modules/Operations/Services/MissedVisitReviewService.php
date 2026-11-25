<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

require_once __DIR__ . '/MissedVisitRules.php';

/**
 * Otto's end-of-day missed-visit review (migration 1349).
 *
 * Opens once a day — when the last crew member has clocked out (and stayed out 20 min, after
 * noon) or at 18:00, whichever is first (tick(), called from Charlie's 15-minute cron). It
 * collects today's visits that were not done and any older ones still open, asks
 * MissedVisitRules::pick() for Otto's suggestion per visit, stores it, and pushes everyone
 * with schedule.edit (admins, managers, the truck tablets).
 *
 * A person decides each visit (decide()): move (tomorrow / a date), skip with a reason, or
 * cancel — through the shared, guarded paths (VisitLifecycleService::moveVisit / skipVisit /
 * updateVisitStatus; moves stamp original_scheduled_date). When the answer differs from Otto's
 * pick a HeadLessons row (head 'otto') is written. Nothing here messages a client: the move,
 * skip and cancel paths it uses send nothing today, and none is added.
 *
 * Anything unanswered by the auto_rollover run keeps the old behaviour — except that
 * auto_rollover now leaves a contract / prepaid visit open for the next review instead of
 * skipping it, and leaves alone a visit decided here.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MissedVisitReviewService
{
    public const LOOKBACK_DAYS = 60;      // older open visits than this are not reviewed (they are history)
    public const SOURCE = 'otto_missed_review';

    private PDO $db;
    private int $siteId;
    /** @var callable(array $userIds, string $title, string $body, array $data): void */
    private $push;
    /** @var callable(): string  'Y-m-d H:i:s' */
    private $clock;
    /** @var array{move: callable, skip: callable, cancel: callable, reopen: callable} */
    private array $apply;

    public function __construct(PDO $db, ?callable $push = null, ?callable $clock = null, array $apply = [], int $siteId = 1)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->push = $push;
        $this->clock = $clock ?? static fn(): string => date('Y-m-d H:i:s');
        $this->apply = $apply + [
            'move'   => [self::class, 'applyMove'],
            'skip'   => [self::class, 'applySkip'],
            'cancel' => [self::class, 'applyCancel'],
            'reopen' => [self::class, 'applyReopen'],
        ];
    }

    private function now(): string { return (string)($this->clock)(); }
    private function today(): string { return substr($this->now(), 0, 10); }
    private static function addDays(string $date, int $n): string { return date('Y-m-d', strtotime($date . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' day')); }

    /** Migration 1349 has run. */
    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM otto_missed_review_items LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Opening the day's review ───────────────────────────────────────────────

    /**
     * Called every cron tick: opens today's review once the trigger fires.
     * @return array{opened: ?int, trigger: ?string, items: int, why: string}
     */
    public function tick(): array
    {
        $today = $this->today();
        $out = ['opened' => null, 'trigger' => null, 'items' => 0, 'why' => ''];
        if (!$this->ready()) { $out['why'] = 'migration 1349 not run'; return $out; }
        if ($this->reviewFor($today)) { $out['why'] = 'already open today'; return $out; }

        $c = $this->clockFacts($today);
        $trigger = MissedVisitRules::openTrigger(substr($this->now(), 11, 5), $c['clocked_today'], $c['on_clock'], $c['mins_since_last_out']);
        if ($trigger === null) {
            $out['why'] = 'not yet (' . $c['clocked_today'] . ' clocked in today, ' . $c['on_clock'] . ' still on)';
            return $out;
        }
        $r = $this->build($today, $trigger);
        return ['opened' => $r['review_id'], 'trigger' => $trigger, 'items' => $r['items'], 'why' => $r['new'] ? 'opened' : 'already open'];
    }

    /** Who clocked in today, who is still on, minutes since the last clock-out. */
    protected function clockFacts(string $today): array
    {
        $from = $today . ' 00:00:00';
        $s = $this->db->prepare("SELECT COUNT(DISTINCT user_id) FROM time_clock_entries WHERE clock_in >= ? AND COALESCE(status, '') <> 'voided'");
        $s->execute([$from]);
        $clocked = (int)$s->fetchColumn();
        $s = $this->db->prepare("SELECT COUNT(*) FROM time_clock_entries WHERE status = 'active' AND clock_out IS NULL AND clock_in >= ?");
        $s->execute([self::addDays($today, -1) . ' 00:00:00']);
        $on = (int)$s->fetchColumn();
        $s = $this->db->prepare("SELECT MAX(clock_out) FROM time_clock_entries WHERE clock_out >= ? AND COALESCE(status, '') <> 'voided'");
        $s->execute([$from]);
        $last = $s->fetchColumn();
        $mins = $last ? (int)floor((strtotime($this->now()) - strtotime((string)$last)) / 60) : null;
        return ['clocked_today' => $clocked, 'on_clock' => $on, 'mins_since_last_out' => $mins];
    }

    public function reviewFor(string $date): ?array
    {
        $s = $this->db->prepare('SELECT * FROM otto_missed_reviews WHERE site_id = ? AND review_date = ?');
        $s->execute([$this->siteId, $date]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Build (or top up) the review for $date: one item per open visit, with Otto's pick.
     * Pushes only when the review is new and has something in it.
     * @return array{review_id: int, items: int, added: int, new: bool, notified: int}
     */
    public function build(string $date, string $trigger = 'manual', bool $notify = true): array
    {
        $review = $this->reviewFor($date);
        $new = false;
        if (!$review) {
            try {
                $this->db->prepare('INSERT INTO otto_missed_reviews (site_id, review_date, opened_by, item_count, created_at) VALUES (?, ?, ?, 0, ?)')
                    ->execute([$this->siteId, $date, substr($trigger, 0, 20), $this->now()]);
                $new = true;
            } catch (PDOException $e) {
                // another tick opened it at the same moment (unique site_id + review_date)
            }
            $review = $this->reviewFor($date);
            if (!$review) throw new RuntimeException('Could not open the missed-visit review for ' . $date);
        }
        $reviewId = (int)$review['id'];

        $have = $this->db->prepare('SELECT visit_id FROM otto_missed_review_items WHERE review_id = ?');
        $have->execute([$reviewId]);
        $already = array_flip(array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN)));

        $added = 0;
        $ins = $this->db->prepare('INSERT INTO otto_missed_review_items
            (review_id, visit_id, plan_id, missed_date, why_missed, pick, pick_date, pick_reason, skip_allowed, skip_block, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($this->collect($date) as $v) {
            if (isset($already[(int)$v['visit_id']])) continue;
            $facts = $this->visitFacts($v, $date);
            $p = MissedVisitRules::pick($facts, $date, $this->daysFor($v, $date, $facts));
            try {
                $ins->execute([$reviewId, (int)$v['visit_id'], (int)$v['plan_id'], $v['scheduled_date'], self::whyMissed($v),
                    $p['pick'], $p['date'], mb_substr($p['reason'], 0, 255), $p['skip_allowed'] ? 1 : 0,
                    $p['skip_block'] !== null ? mb_substr($p['skip_block'], 0, 160) : null, $this->now()]);
                $added++;
            } catch (PDOException $e) {
                error_log('[missed review] item for visit ' . $v['visit_id'] . ': ' . $e->getMessage());
            }
        }
        $cnt = $this->db->prepare('SELECT COUNT(*) FROM otto_missed_review_items WHERE review_id = ?');
        $cnt->execute([$reviewId]);
        $items = (int)$cnt->fetchColumn();
        $this->db->prepare('UPDATE otto_missed_reviews SET item_count = ? WHERE id = ?')->execute([$items, $reviewId]);

        $notified = 0;
        if ($new && $notify && $items > 0) {
            $notified = $this->notify($reviewId, $date, $items);
        }
        return ['review_id' => $reviewId, 'items' => $items, 'added' => $added, 'new' => $new, 'notified' => $notified];
    }

    /** Open visits on or before $date (not done, not skipped/cancelled by anyone), plan active. */
    public function collect(string $date): array
    {
        $s = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.plan_id, jv.scheduled_date, jv.status, jv.rollover_count,
                   jv.original_scheduled_date, jv.completion_notes, jv.weather_status, jv.weather_reason,
                   jv.assigned_crew_id, jv.scheduled_time_start, jv.started_at,
                   jp.plan_number, jp.title AS plan_title, jp.service_type, jp.is_recurring, jp.is_prepaid_bundle,
                   jp.recurrence_pattern, jp.recurrence_interval, jp.recurrence_interval_unit, jp.recurrence_day_of_week,
                   jp.default_crew_id, jp.default_time_start, jp.estimated_duration_minutes, jp.property_id, jp.contract_id,
                   c.contract_number, c.status AS contract_status,
                   p.address, p.city, p.latitude, p.longitude
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN contracts c ON c.id = jp.contract_id
            LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jv.status IN ('scheduled', 'in_progress')
              AND jv.completed_at IS NULL
              AND jv.scheduled_date <= ? AND jv.scheduled_date >= ?
              AND jp.status = 'active'
            ORDER BY jv.scheduled_date, jv.id");
        $s->execute([$date, self::addDays($date, -self::LOOKBACK_DAYS)]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Is the plan's contract binding (active, or paused but not cancelled/expired)? */
    public static function contractBinds(array $v): bool
    {
        return !empty($v['contract_id']) && !in_array((string)($v['contract_status'] ?? ''), ['cancelled', 'expired'], true);
    }

    /** "Started, not finished · weather: rain · rolled 3 days since Thu Oct 9". */
    public static function whyMissed(array $v): string
    {
        $bits = [];
        if (($v['status'] ?? '') === 'in_progress') $bits[] = 'Started, not finished';
        $ws = strtolower((string)($v['weather_status'] ?? ''));
        if ($ws !== '' && $ws !== 'ok') $bits[] = 'Weather: ' . (trim((string)($v['weather_reason'] ?? '')) ?: $ws);
        $note = trim((string)($v['completion_notes'] ?? ''));
        if ($note !== '') $bits[] = $note;
        $rc = (int)($v['rollover_count'] ?? 0);
        if ($rc > 0) {
            $orig = (string)($v['original_scheduled_date'] ?? '');
            $bits[] = 'Rolled on ' . $rc . ' day' . ($rc === 1 ? '' : 's') . ($orig !== '' ? ' since ' . MissedVisitRules::dayLabel($orig) : '');
        }
        if (!$bits) $bits[] = 'Not started';
        return mb_substr(implode(' · ', $bits), 0, 255);
    }

    /** The visit facts MissedVisitRules::pick() reads. */
    protected function visitFacts(array $v, string $date): array
    {
        $planId = (int)$v['plan_id'];
        $vid = (int)$v['visit_id'];
        $n = $this->db->prepare("SELECT MIN(scheduled_date) FROM job_visits WHERE plan_id = ? AND id <> ? AND status IN ('scheduled', 'in_progress') AND scheduled_date >= ? AND scheduled_date > ?");
        $n->execute([$planId, $vid, $date, (string)$v['scheduled_date']]);
        $next = $n->fetchColumn() ?: null;
        // Done since it was missed (the next regular visit already happened): skipping is the same as
        // "don't double up".
        $d = $this->db->prepare("SELECT MAX(scheduled_date) FROM job_visits WHERE plan_id = ? AND id <> ? AND status = 'completed' AND scheduled_date > ? AND scheduled_date <= ?");
        $d->execute([$planId, $vid, (string)$v['scheduled_date'], $date]);
        $doneSince = $d->fetchColumn() ?: null;
        $crewId = (int)($v['assigned_crew_id'] ?: $v['default_crew_id'] ?: 0);
        return [
            'is_recurring' => (int)$v['is_recurring'] === 1,
            'contract_number' => self::contractBinds($v) ? ((string)($v['contract_number'] ?? '') ?: 'contract #' . (int)$v['contract_id']) : null,
            'is_prepaid_bundle' => !empty($v['is_prepaid_bundle']),
            'freq_days' => MissedVisitRules::freqDays($v['recurrence_pattern'] ?? null, $v['recurrence_interval'] ?? 1, $v['recurrence_interval_unit'] ?? null, $v['recurrence_day_of_week'] ?? null),
            'next_visit' => $next,
            'done_since' => $doneSince,
            'every_day' => (bool)preg_match('/snow|salt|ice|de-?ic/i', (string)($v['service_type'] ?? '')),
            'crew_name' => $crewId ? $this->firstName($crewId) : '',
            'crew_id' => $crewId,
        ];
    }

    private array $names = [];
    private function firstName(int $userId): string
    {
        if (!array_key_exists($userId, $this->names)) {
            $s = $this->db->prepare('SELECT full_name FROM users WHERE id = ?');
            $s->execute([$userId]);
            $full = trim((string)$s->fetchColumn());
            $first = (string)strtok($full, ' ');
            $this->names[$userId] = $first !== '' ? ucfirst(strtolower($first)) : '';
        }
        return $this->names[$userId];
    }

    /**
     * The days after $date the visit could go to: rest day, crew working, plan already there,
     * bylaw hours, forecast, route. Up to the plan's next visit (max 14 days) or LOOKAHEAD_DAYS.
     */
    protected function daysFor(array $v, string $date, array $facts): array
    {
        require_once APP_ROOT . '/Modules/Operations/Services/DispatchRules.php';
        $span = MissedVisitRules::LOOKAHEAD_DAYS;
        if (!empty($facts['next_visit'])) $span = max(1, min(14, MissedVisitRules::daysBetween($date, (string)$facts['next_visit'])));
        $end = self::addDays($date, $span);
        $planId = (int)$v['plan_id'];

        $pv = $this->db->prepare("SELECT DISTINCT scheduled_date FROM job_visits WHERE plan_id = ? AND id <> ? AND status NOT IN ('skipped', 'cancelled') AND scheduled_date > ? AND scheduled_date <= ?");
        $pv->execute([$planId, (int)$v['visit_id'], $date, $end]);
        $planDays = array_flip($pv->fetchAll(PDO::FETCH_COLUMN));

        $crewId = (int)($facts['crew_id'] ?? 0);
        $works = null;
        if ($crewId) {
            $w = $this->db->prepare('SELECT day_of_week FROM crew_work_schedules WHERE user_id = ?');
            try { $w->execute([$crewId]); $rows = $w->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $rows = []; }
            if ($rows) $works = array_flip(array_map('intval', $rows));
        }
        $forecast = $facts['every_day'] ? [] : $this->forecast((string)($v['city'] ?? ''));
        $rules = $this->municipalRules();
        $start = substr((string)($v['scheduled_time_start'] ?: $v['default_time_start'] ?: '08:00'), 0, 5);
        // Capped at a working day: a plan length over 8 h is a data slip (PLN-2026-0044 says 834 min)
        // and would fail every day's bylaw hours.
        $mins = min(480, max(30, (int)($v['estimated_duration_minutes'] ?: 60)));

        $days = [];
        for ($i = 1; $i <= $span; $i++) {
            $d = self::addDays($date, $i);
            $wet = null; $mm = null;
            if (isset($forecast[$d]['precipitation'])) {
                $mm = (float)$forecast[$d]['precipitation'];
                $wet = $mm >= MissedVisitRules::WET_MM;
            }
            $route = $this->routeFit($crewId, $d, $v);
            $bylaw = true;
            if ($rules) {
                try { $bylaw = $rules->fitSlotForVisit((int)$v['visit_id'], $d, $start, $mins) !== null; } catch (Throwable $e) { $bylaw = true; }
            }
            $days[$d] = [
                'rest' => DispatchRules::isRestDay($d),
                'crew_works' => $works === null ? null : isset($works[(int)date('w', strtotime($d . ' 12:00:00'))]),
                'plan_visit' => isset($planDays[$d]),
                'bylaw_ok' => $bylaw,
                'wet' => $wet, 'wet_mm' => $mm,
                'route' => $route['fit'], 'route_n' => $route['n'],
            ];
        }
        return $days;
    }

    /** Stops the same crew already has that day within ROUTE_KM. fit null = no route data that day. */
    protected function routeFit(int $crewId, string $date, array $v): array
    {
        if (!$crewId || $v['latitude'] === null || $v['longitude'] === null || (float)$v['latitude'] === 0.0) return ['fit' => null, 'n' => 0];
        $s = $this->db->prepare("SELECT p.latitude, p.longitude FROM calendar_stops cs JOIN properties p ON p.id = cs.property_id
                                 WHERE cs.stop_date = ? AND cs.crew_id = ? AND cs.property_id <> ? AND COALESCE(cs.status, '') <> 'skipped'");
        $s->execute([$date, $crewId, (int)$v['property_id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['fit' => null, 'n' => 0];
        $n = 0;
        foreach ($rows as $r) {
            if ($r['latitude'] === null || $r['longitude'] === null) continue;
            if (DispatchRules::haversineKm((float)$v['latitude'], (float)$v['longitude'], (float)$r['latitude'], (float)$r['longitude']) <= MissedVisitRules::ROUTE_KM) $n++;
        }
        return ['fit' => $n > 0, 'n' => $n];
    }

    /** date => [precipitation, …] from the cached 7-day forecast (never blocks a page). [] when unknown. */
    protected function forecast(string $city): array
    {
        static $cache = [];
        $city = trim($city) !== '' ? trim($city) : 'Vancouver';
        if (isset($cache[$city])) return $cache[$city];
        $f = [];
        try {
            require_once APP_ROOT . '/Services/Weather/WeatherService.php';
            if (function_exists('getWeekForecast')) $f = (array)getWeekForecast($city, 'BC');
        } catch (Throwable $e) {
            error_log('[missed review] forecast: ' . $e->getMessage());
        }
        return $cache[$city] = $f;
    }

    private $muni = false;
    protected function municipalRules()
    {
        if ($this->muni === false) {
            $this->muni = null;
            try {
                if (defined('APP_ROOT') && is_file(APP_ROOT . '/Modules/Operations/Services/MunicipalRuleService.php')) {
                    require_once APP_ROOT . '/Modules/Operations/Services/MunicipalRuleService.php';
                    $this->muni = new MunicipalRuleService($this->db);
                }
            } catch (Throwable $e) { $this->muni = null; }
        }
        return $this->muni;
    }

    // ── Who hears about it ─────────────────────────────────────────────────────

    /** Active users holding schedule.edit (admins always; RBAC roles; legacy managers with no RBAC rows). */
    public function recipients(string $perm = 'schedule.edit'): array
    {
        $ids = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT u.id FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN role_permissions rp ON rp.role_id = ur.role_id
                JOIN permissions p ON p.id = rp.permission_id
                WHERE u.is_active = 1 AND p.`key` = ?");
            $s->execute([$perm]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { /* RBAC tables missing: legacy below */ }
        try {
            // admins always; managers with no RBAC rows fall back to the legacy manager list (schedule.edit)
            $s = $this->db->query("SELECT u.id FROM users u WHERE u.is_active = 1 AND (u.role = 'admin'
                OR (u.role = 'manager' AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)))");
            $ids = array_merge($ids, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) { /* users table always exists */ }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    private function notify(int $reviewId, string $date, int $items): int
    {
        $to = $this->recipients();
        if (!$to) return 0;
        $title = 'Otto: ' . $items . ' visit' . ($items === 1 ? '' : 's') . ' not done';
        $body = 'Tap to decide: tomorrow, another day, skip or cancel. I have a pick for each. Anything left rolls on tonight as before.';
        $data = ['head' => 'otto', 'open' => 'otto_review', 'type' => 'otto_missed_review', 'review_id' => $reviewId,
                 'date' => $date, 'url' => '/crm/ops/missed-visits.php',      // Android opens data.url
                 'image_url' => _idSiteUrl() . '/crm/img/heads/otto.jpg'];   // Android shows Otto's face
        try {
            if ($this->push) {
                ($this->push)($to, $title, $body, $data);
            } else {
                require_once APP_ROOT . '/Services/Push/ApnsService.php';
                require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
                PushDispatcher::notifyUsers($to, $title, $body, $data);
            }
            $this->db->prepare('UPDATE otto_missed_reviews SET notified_at = ?, notified_count = ? WHERE id = ?')
                ->execute([$this->now(), count($to), $reviewId]);
            return count($to);
        } catch (Throwable $e) {
            error_log('[missed review] push: ' . $e->getMessage());
            return 0;
        }
    }

    // ── Reading for the screens ────────────────────────────────────────────────

    /**
     * Items still waiting for a person (newest review first), plus today's decided ones.
     * Each item says whether it can still be decided (the visit is still open on its missed date).
     */
    public function open(?int $reviewId = null): array
    {
        $today = $this->today();
        $where = $reviewId ? 'r.id = ?' : 'r.review_date >= ?';
        $arg = $reviewId ?: self::addDays($today, -7);
        $s = $this->db->prepare("
            SELECT i.*, r.review_date, jv.status AS visit_status, jv.scheduled_date AS visit_date,
                   jp.plan_number, jp.title AS plan_title, jp.service_type, p.address, p.city,
                   COALESCE(jv.assigned_crew_id, jp.default_crew_id) AS crew_id, u.full_name AS crew_name,
                   d.full_name AS decided_by_name
            FROM otto_missed_review_items i
            JOIN otto_missed_reviews r ON r.id = i.review_id
            JOIN job_visits jv ON jv.id = i.visit_id
            JOIN job_plans jp ON jp.id = i.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN users u ON u.id = COALESCE(jv.assigned_crew_id, jp.default_crew_id)
            LEFT JOIN users d ON d.id = i.decided_by
            WHERE r.site_id = ? AND {$where}
            ORDER BY r.review_date DESC, i.missed_date, i.id");
        $s->execute([$this->siteId, $arg]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $seen = [];
        foreach ($rows as $r) {
            $vid = (int)$r['visit_id'];
            if (isset($seen[$vid])) continue;           // the newest review's row for a visit wins
            $seen[$vid] = true;
            $openNow = in_array($r['visit_status'], ['scheduled', 'in_progress'], true) && $r['visit_date'] === $r['missed_date'];
            $decided = $r['decided_at'] !== null;
            if (!$decided && !$openNow && $r['review_date'] !== $today && !$reviewId) continue;   // handled elsewhere, old
            if ($decided && substr((string)$r['decided_at'], 0, 10) !== $today && !$reviewId) continue;
            $items[] = self::present($r, $openNow);
        }
        $waiting = count(array_filter($items, fn($i) => $i['can_decide']));
        $latest = $reviewId ? $this->reviewById($reviewId) : $this->latestReview();
        return [
            'review' => $latest ? ['id' => (int)$latest['id'], 'date' => $latest['review_date'], 'label' => MissedVisitRules::dayLabel($latest['review_date']),
                                    'opened_by' => $latest['opened_by'], 'item_count' => (int)$latest['item_count']] : null,
            'waiting' => $waiting,
            'with_pick' => count(array_filter($items, fn($i) => $i['can_decide'] && $i['pick'] !== null)),
            'items' => $items,
            'reasons' => array_map(fn($k, $l) => ['key' => $k, 'label' => $l], array_keys(MissedVisitRules::REASONS), MissedVisitRules::REASONS),
            'tomorrow' => self::addDays($today, 1),
            'today' => $today,
        ];
    }

    private function reviewById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM otto_missed_reviews WHERE id = ? AND site_id = ?');
        $s->execute([$id, $this->siteId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function latestReview(): ?array
    {
        $s = $this->db->prepare('SELECT * FROM otto_missed_reviews WHERE site_id = ? ORDER BY review_date DESC LIMIT 1');
        $s->execute([$this->siteId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** One item as every screen shows it. */
    public static function present(array $r, bool $openNow): array
    {
        $crew = trim((string)($r['crew_name'] ?? ''));
        $dec = $r['decision'] ?? null;
        $decText = null;
        if ($dec === 'move') $decText = 'Moved to ' . MissedVisitRules::dayLabel((string)$r['decision_date']);
        elseif ($dec === 'skip') $decText = 'Skipped' . (!empty($r['decision_reason']) ? ' (' . (MissedVisitRules::REASONS[$r['decision_reason']] ?? $r['decision_reason']) . ')' : '');
        elseif ($dec === 'cancel') $decText = 'Cancelled' . (!empty($r['decision_reason']) ? ' (' . (MissedVisitRules::REASONS[$r['decision_reason']] ?? $r['decision_reason']) . ')' : '');
        if ($decText && !empty($r['decided_by_name'])) $decText .= ' by ' . strtok((string)$r['decided_by_name'], ' ');
        if (!$dec && !$openNow) $decText = 'Changed on the schedule since';
        return [
            'id' => (int)$r['id'],
            'visit_id' => (int)$r['visit_id'],
            'plan_id' => (int)$r['plan_id'],
            'property' => trim((string)($r['address'] ?? '')) . (trim((string)($r['city'] ?? '')) !== '' ? ', ' . trim((string)$r['city']) : ''),
            'plan' => trim(((string)($r['plan_title'] ?? '')) . (!empty($r['plan_number']) ? ' · ' . $r['plan_number'] : '')),
            'service' => (string)($r['service_type'] ?? ''),
            'crew' => $crew !== '' ? ucwords(strtolower($crew)) : '',
            'missed_date' => (string)$r['missed_date'],
            'missed_label' => MissedVisitRules::dayLabel((string)$r['missed_date']),
            'why' => (string)($r['why_missed'] ?? ''),
            'pick' => $r['pick'] !== null && $r['pick'] !== '' ? (string)$r['pick'] : null,
            'pick_date' => $r['pick_date'] ?: null,
            'pick_label' => MissedVisitRules::pickLabel($r['pick'] ?: null, $r['pick_date'] ?: null),
            'pick_reason' => (string)$r['pick_reason'],
            'skip_allowed' => (int)$r['skip_allowed'] === 1,
            'skip_block' => $r['skip_block'] ?: null,
            'decision' => $dec,
            'decision_text' => $decText,
            'apply_error' => $r['apply_error'] ?: null,
            'can_decide' => !$dec && $openNow,
            'review_date' => (string)($r['review_date'] ?? ''),
        ];
    }

    // ── Deciding ───────────────────────────────────────────────────────────────

    /**
     * Apply a person's answer to one item.
     * $choice: 'accept' (Otto's pick) | 'tomorrow' | 'date' (with $date) | 'skip' | 'cancel' (with $reason).
     * @return array{ok: bool, message: string, item?: array}
     */
    public function decide(int $itemId, string $choice, ?string $date, ?string $reason, ?string $note, int $userId): array
    {
        $s = $this->db->prepare('SELECT i.*, r.review_date, r.site_id FROM otto_missed_review_items i JOIN otto_missed_reviews r ON r.id = i.review_id WHERE i.id = ?');
        $s->execute([$itemId]);
        $item = $s->fetch(PDO::FETCH_ASSOC);
        if (!$item || (int)$item['site_id'] !== $this->siteId) return ['ok' => false, 'message' => 'That review item is gone.'];
        if ($item['decided_at'] !== null) return ['ok' => false, 'message' => 'Already decided.'];

        $v = $this->db->prepare('SELECT id, plan_id, status, scheduled_date FROM job_visits WHERE id = ?');
        $v->execute([(int)$item['visit_id']]);
        $visit = $v->fetch(PDO::FETCH_ASSOC);
        if (!$visit || !in_array($visit['status'], ['scheduled', 'in_progress'], true) || $visit['scheduled_date'] !== $item['missed_date']) {
            return ['ok' => false, 'message' => 'This visit was changed on the schedule since; nothing to decide.'];
        }

        $today = $this->today();
        if ($choice === 'accept') {
            if ($item['pick'] === null || $item['pick'] === '') return ['ok' => false, 'message' => 'Otto has no pick for this one: choose.'];
            $choice = $item['pick'] === 'skip' ? 'skip' : 'date';
            $date = $item['pick_date'];
            if ($choice === 'skip') { $reason = $reason ?: 'other'; $note = $note ?: "Otto's pick: " . $item['pick_reason']; }
        }
        if ($choice === 'tomorrow') { $choice = 'date'; $date = self::addDays($today, 1); }

        $reasonKey = $reason !== null && isset(MissedVisitRules::REASONS[$reason]) ? $reason : null;
        $note = $note !== null ? mb_substr(trim($note), 0, 200) : null;

        if ($choice === 'date') {
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok' => false, 'message' => 'Pick a date.'];
            if ($date < $today) return ['ok' => false, 'message' => MissedVisitRules::dayLabel($date) . ' has passed: pick another date.'];
            if ($date > self::addDays($today, 120)) return ['ok' => false, 'message' => 'Pick a date in the next four months.'];
            $c = $this->db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND id <> ? AND scheduled_date = ? AND status NOT IN ('skipped', 'cancelled')");
            $c->execute([(int)$visit['plan_id'], (int)$visit['id'], $date]);
            if ((int)$c->fetchColumn() > 0) return ['ok' => false, 'message' => 'This plan already has a visit on ' . MissedVisitRules::dayLabel($date) . '.'];
            $res = $this->run('move', (int)$visit['id'], $visit['status'], $userId, $date, null);
            $decision = 'move';
        } elseif ($choice === 'skip' || $choice === 'cancel') {
            if ($reasonKey === null) return ['ok' => false, 'message' => 'Say why: weather, client asked, no access or other.'];
            $text = "Otto's review: " . MissedVisitRules::REASONS[$reasonKey] . ($note ? ' — ' . $note : '');
            $res = $this->run($choice, (int)$visit['id'], $visit['status'], $userId, null, $text);
            $decision = $choice;
            $date = null;
        } else {
            return ['ok' => false, 'message' => 'Unknown choice.'];
        }

        if (!$res['ok']) {
            $this->db->prepare('UPDATE otto_missed_review_items SET apply_error = ? WHERE id = ?')->execute([mb_substr($res['message'], 0, 255), $itemId]);
            return ['ok' => false, 'message' => $res['message']];
        }

        $this->db->prepare('UPDATE otto_missed_review_items SET decision = ?, decision_date = ?, decision_reason = ?, decision_note = ?,
                                   decided_by = ?, decided_at = ?, apply_error = NULL WHERE id = ? AND decided_at IS NULL')
            ->execute([$decision, $date, $reasonKey, $note, $userId ?: null, $this->now(), $itemId]);

        // A different answer from Otto's is a lesson.
        $said = MissedVisitRules::answerKey($item['pick'] ?: null, $item['pick_date'] ?: null);
        $correct = MissedVisitRules::answerKey($decision, $date);
        if ($said !== $correct) {
            require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/Services/HeadLessons.php';
            HeadLessons::recordQuietly($this->db, [
                'site_id' => $this->siteId, 'head' => 'otto', 'subject_kind' => 'job_plan',
                'subject_key' => 'plan:' . (int)$item['plan_id'], 'field' => 'missed_visit',
                'said' => $said, 'correct' => $correct, 'source' => self::SOURCE,
                'ref_type' => 'job_visit', 'ref_id' => (int)$item['visit_id'], 'corrected_by' => $userId,
                'reason' => $reasonKey ? MissedVisitRules::REASONS[$reasonKey] . ($note ? ': ' . $note : '') : ($note ?: null),
            ]);
        }
        $msg = $decision === 'move' ? 'Moved to ' . MissedVisitRules::dayLabel((string)$date) . '.' : ($decision === 'skip' ? 'Skipped.' : 'Cancelled.');
        return ['ok' => true, 'message' => $msg];
    }

    /** Otto's pick for every undecided item that has one (one tap). */
    public function acceptAll(?int $reviewId, int $userId): array
    {
        $open = $this->open($reviewId);
        $done = 0; $failed = [];
        foreach ($open['items'] as $i) {
            if (!$i['can_decide'] || $i['pick'] === null) continue;
            $r = $this->decide($i['id'], 'accept', null, null, null, $userId);
            if ($r['ok']) $done++; else $failed[] = $i['property'] . ': ' . $r['message'];
        }
        $left = count(array_filter($open['items'], fn($i) => $i['can_decide'] && $i['pick'] === null));
        $msg = $done . ' done the way Otto suggested' . ($failed ? '; ' . count($failed) . ' could not be applied' : '') . ($left ? '; ' . $left . ' left for you' : '') . '.';
        return ['ok' => !$failed, 'message' => $msg, 'done' => $done, 'failed' => $failed, 'left' => $left];
    }

    /** In-progress visits are first put back to scheduled (timers stopped, capped and flagged). */
    private function run(string $what, int $visitId, string $status, int $userId, ?string $date, ?string $text): array
    {
        try {
            if ($status === 'in_progress') {
                $r = ($this->apply['reopen'])($this->db, $visitId, $userId);
                if (empty($r['ok'])) return ['ok' => false, 'message' => $r['message'] ?? 'Could not stop the visit\'s timer.'];
            }
            $r = $what === 'move' ? ($this->apply['move'])($this->db, $visitId, (string)$date, $userId)
               : ($this->apply[$what])($this->db, $visitId, (string)$text, $userId);
            return ['ok' => !empty($r['ok']), 'message' => (string)($r['message'] ?? '')];
        } catch (Throwable $e) {
            error_log('[missed review] ' . $what . ' visit ' . $visitId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not ' . $what . ' the visit: ' . $e->getMessage()];
        }
    }

    /**
     * One request from the web card/page (otto-missed.php) or the iPhone (schedule/missed-review),
     * after the caller checked schedule.edit (and CSRF on the web).
     *   GET  {mode: list, review_id?}
     *   POST {mode: decide, item_id, choice: accept|tomorrow|date|skip|cancel, date?, reason?, note?}
     *   POST {mode: accept_all, review_id?}
     *   POST {mode: build}   open / top up today's review now (no push)
     * @return array{0: int, 1: array}  [HTTP status, body]
     */
    public function handle(string $method, array $in, int $userId): array
    {
        if (!$this->ready()) return [503, ['ok' => false, 'error' => "Otto's missed-visit review needs migration 1349."]];
        $mode = (string)($in['mode'] ?? ($method === 'GET' ? 'list' : ''));
        if ($method === 'GET' && $mode === 'list') {
            $rid = (int)($in['review_id'] ?? 0);
            return [200, ['ok' => true] + $this->open($rid > 0 ? $rid : null)];
        }
        if ($method !== 'POST') return [405, ['ok' => false, 'error' => 'POST required']];
        if ($mode === 'decide') {
            $r = $this->decide((int)($in['item_id'] ?? 0), (string)($in['choice'] ?? ''),
                isset($in['date']) && $in['date'] !== '' ? (string)$in['date'] : null,
                isset($in['reason']) && $in['reason'] !== '' ? (string)$in['reason'] : null,
                isset($in['note']) ? (string)$in['note'] : null, $userId);
            return [$r['ok'] ? 200 : 422, $r + ($r['ok'] ? [] : ['error' => $r['message']])];
        }
        if ($mode === 'accept_all') {
            $rid = (int)($in['review_id'] ?? 0);
            $r = $this->acceptAll($rid > 0 ? $rid : null, $userId);
            return [200, $r];
        }
        if ($mode === 'build') {
            $r = $this->build($this->today(), 'manual', false);
            return [200, ['ok' => true, 'message' => $r['added'] . ' added; ' . $r['items'] . ' in today\'s review.'] + $r];
        }
        return [400, ['ok' => false, 'error' => 'Unknown mode']];
    }

    // ── The shared, guarded paths (production) ─────────────────────────────────

    private static function loadPlan(): void
    {
        // ensureCalendarStop() lives in Plan/CalendarStops.php, loaded by PlanFunctions.php.
        require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';
        require_once APP_ROOT . '/Modules/Jobs/Services/VisitLifecycleService.php';
    }

    public static function applyMove(PDO $db, int $visitId, string $date, int $userId): array
    {
        self::loadPlan();
        $ok = VisitLifecycleService::moveVisit($visitId, $date, null, $userId);
        if ($ok && function_exists('logActivityExtended')) {
            logActivityExtended($userId, 'Visit moved (Otto review)', "Visit #{$visitId} moved to {$date} from Otto's missed-visit review", null, null, null, null, null, $visitId);
        }
        return ['ok' => $ok, 'message' => $ok ? '' : 'The schedule refused the move.'];
    }

    public static function applySkip(PDO $db, int $visitId, string $text, int $userId): array
    {
        self::loadPlan();
        $r = VisitLifecycleService::skipVisit($visitId, $userId, $text);
        return ['ok' => !empty($r['success']), 'message' => (string)($r['message'] ?? '')];
    }

    public static function applyCancel(PDO $db, int $visitId, string $text, int $userId): array
    {
        self::loadPlan();
        $ok = VisitLifecycleService::updateVisitStatus($visitId, 'cancelled', $userId, $text);
        return ['ok' => $ok, 'message' => $ok ? '' : 'The schedule refused the cancel.'];
    }

    /** An in-progress visit nobody finished: stop its timers (capped + flagged), back to scheduled. */
    public static function applyReopen(PDO $db, int $visitId, int $userId): array
    {
        self::loadPlan();
        require_once APP_ROOT . '/Modules/Jobs/Services/VisitTimerStop.php';
        VisitTimerStop::stopForVisit($db, $visitId, "Otto's missed-visit review");
        $ok = VisitLifecycleService::updateVisitStatus($visitId, 'scheduled', $userId, null);
        if ($ok) $db->prepare('UPDATE job_visits SET started_at = NULL WHERE id = ?')->execute([$visitId]);
        return ['ok' => $ok, 'message' => $ok ? '' : 'Could not put the visit back to scheduled.'];
    }
}
