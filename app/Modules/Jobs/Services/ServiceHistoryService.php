<?php
declare(strict_types=1);

/**
 * ServiceHistoryService — "when was this last done?" for the crew's job cards.
 *
 * For each visit on today's schedule: the last two CALENDAR weeks of that visit's plan
 * (Monday→Sunday, last week then this week — the same columns as the schedule's week strip, so
 * "we do this one Thursdays, last week it slid to Friday" is visible at a glance), plus a
 * one-line summary for the collapsed card.
 *
 * History is per PLAN, not per property: a stop can hold a lawn visit and a salting visit, and
 * a green Tuesday must say which service it was.
 *
 * Winter services (salt / snow) are done several times a day in a storm, so they also carry a
 * timestamp and a 24-hour application count — a 14-day grid alone would just be solid green.
 *
 * Deliberately carries no crew names: the card is for "what state is this site in", not a
 * scoreboard, and it keeps crew identity out of anything that may be shown to a client.
 */
class ServiceHistoryService
{
    public const DAYS = 14;

    /** Most significant first — what a day shows when several visits landed on it. */
    private const STATE_RANK = ['completed' => 4, 'in_progress' => 3, 'skipped' => 2, 'scheduled' => 1, 'none' => 0];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ───────────────────────────────────────────────────────────

    /** Monday of LAST week through Sunday of THIS week: 14 dates, oldest first. */
    public static function window(string $today): array
    {
        $ts     = strtotime($today . ' 12:00:00');
        $dow    = (int)date('N', $ts);                       // 1 = Monday
        $monday = strtotime('-' . ($dow - 1 + 7) . ' days', $ts);
        $dates  = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $dates[] = date('Y-m-d', strtotime("+{$i} days", $monday));
        }
        return $dates;
    }

    public static function isWinterService(?string $serviceType, ?string $title = null): bool
    {
        $text = strtolower(($serviceType ?? '') . ' ' . ($title ?? ''));
        return strpos($text, 'salt') !== false || strpos($text, 'snow') !== false
            || strpos($text, 'de-ic') !== false || strpos($text, 'deic') !== false;
    }

    /**
     * @param array<int,array{date:string,state:string}> $events one per visit in the window
     * @return array<int,array{date:string,state:string,count:int}> 14 cells, oldest first
     */
    public static function grid(array $events, array $window): array
    {
        $cells = [];
        foreach ($window as $date) {
            $cells[$date] = ['date' => $date, 'state' => 'none', 'count' => 0];
        }
        foreach ($events as $e) {
            $date = $e['date'];
            if (!isset($cells[$date])) {
                continue;
            }
            $state = isset(self::STATE_RANK[$e['state']]) ? $e['state'] : 'none';
            if ($state === 'completed') {
                $cells[$date]['count']++;
            }
            if (self::STATE_RANK[$state] > self::STATE_RANK[$cells[$date]['state']]) {
                $cells[$date]['state'] = $state;
            }
        }
        return array_values($cells);
    }

    /**
     * The collapsed-card line. $lastCompletedAt / $lastSkippedDate describe the plan's past,
     * EXCLUDING the visit the card is for.
     */
    public static function summary(?string $lastCompletedAt, ?string $lastSkippedDate, string $today, bool $winter, int $applications24h = 0): ?string
    {
        if ($lastCompletedAt === null && $lastSkippedDate === null) {
            return 'First visit';
        }

        $done = null;
        if ($lastCompletedAt !== null) {
            $doneDate = substr($lastCompletedAt, 0, 10);
            $days     = (int)round((strtotime($today) - strtotime($doneDate)) / 86400);
            $time     = strlen($lastCompletedAt) >= 16 ? substr($lastCompletedAt, 11, 5) : null;

            if ($days <= 0)      { $when = 'today'; }
            elseif ($days === 1) { $when = 'yesterday'; }
            else                 { $when = $days . ' days ago'; }

            // The hour only matters where a site is done more than once a day.
            if ($winter && $time !== null && $days <= 1) {
                $when .= ' ' . $time;
            }
            $done = 'Last done ' . $when;
            if ($winter && $applications24h > 1) {
                $done .= ' · ' . $applications24h . ' in 24 h';
            }
        }

        // A skip AFTER the last completion is the thing the crew most needs to know walking up.
        $skippedSince = $lastSkippedDate !== null
            && ($lastCompletedAt === null || $lastSkippedDate > substr($lastCompletedAt, 0, 10));
        if ($skippedSince) {
            $skip = 'Skipped ' . date('D', strtotime($lastSkippedDate));
            return $done !== null ? $skip . ' — ' . lcfirst($done) : $skip . ' — not done yet';
        }
        return $done;
    }

    // ── Lookup ───────────────────────────────────────────────────────────────

    /**
     * History for the plans behind these visits.
     *
     * @param int[] $visitIds
     * @return array<int,array> keyed by visit id
     */
    public function forVisits(array $visitIds, string $today): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map('intval', $visitIds))));
        if (!$visitIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($visitIds), '?'));

        $stmt = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.plan_id, jp.service_type, jp.title
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jv.id IN ($in)
        ");
        $stmt->execute($visitIds);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$visits) {
            return [];
        }

        $planIds = array_values(array_unique(array_map(static fn (array $v): int => (int)$v['plan_id'], $visits)));
        $pin     = implode(',', array_fill(0, count($planIds), '?'));
        $window  = self::window($today);

        // Everything on those plans inside the window. A completed visit counts on the day it was
        // actually finished, which is not always the day it was scheduled.
        $ev = $this->db->prepare("
            SELECT id, plan_id, status,
                   CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                        THEN DATE(completed_at) ELSE scheduled_date END AS day,
                   completed_at
            FROM job_visits
            WHERE plan_id IN ($pin)
              AND status IN ('completed', 'skipped', 'scheduled', 'in_progress')
              AND (scheduled_date BETWEEN ? AND ? OR DATE(completed_at) BETWEEN ? AND ?)
        ");
        $ev->execute(array_merge($planIds, [$window[0], $window[self::DAYS - 1], $window[0], $window[self::DAYS - 1]]));
        $eventsByPlan = [];
        foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventsByPlan[(int)$row['plan_id']][] = $row;
        }

        // The most recent completion / skip per plan may be older than the window.
        $last = $this->db->prepare("
            SELECT plan_id,
                   MAX(CASE WHEN status = 'completed' THEN COALESCE(completed_at, CONCAT(scheduled_date, ' 00:00:00')) END) AS last_done,
                   MAX(CASE WHEN status = 'skipped' AND scheduled_date <= ? THEN scheduled_date END) AS last_skip
            FROM job_visits
            WHERE plan_id IN ($pin) AND id NOT IN ($in)
            GROUP BY plan_id
        ");
        $last->execute(array_merge([$today], $planIds, $visitIds));
        $lastByPlan = [];
        foreach ($last->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lastByPlan[(int)$row['plan_id']] = $row;
        }

        $dayAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $out    = [];
        foreach ($visits as $v) {
            $visitId = (int)$v['visit_id'];
            $planId  = (int)$v['plan_id'];
            $winter  = self::isWinterService($v['service_type'], $v['title']);

            $events = [];
            $apps24 = 0;
            foreach ($eventsByPlan[$planId] ?? [] as $row) {
                $events[] = ['date' => (string)$row['day'], 'state' => (string)$row['status']];
                if ($row['status'] === 'completed' && $row['completed_at'] !== null && $row['completed_at'] >= $dayAgo) {
                    $apps24++;
                }
            }

            $lastDone = $lastByPlan[$planId]['last_done'] ?? null;
            $lastSkip = $lastByPlan[$planId]['last_skip'] ?? null;

            $out[$visitId] = [
                'days'              => self::grid($events, $window),
                'today'             => $today,
                'winter'            => $winter,
                'last_completed_at' => $lastDone,
                'applications_24h'  => $winter ? $apps24 : 0,
                'summary'           => self::summary($lastDone, $lastSkip, $today, $winter, $apps24),
            ];
        }
        return $out;
    }
}
