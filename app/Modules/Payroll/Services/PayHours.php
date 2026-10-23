<?php
/**
 * PayHours — the minutes a pay run may pay: APPROVED time only, breaks taken off (Penny Payroll step 2, 2026-10-21).
 *
 * Fable's review found the payroll summary pays from raw clock entries, approved or not. A pay run reads
 * time only from shifts whose weekly timesheet is approved, and reports the days it left out, so the owner
 * sees "3 days not approved yet" instead of a smaller cheque. Each shift belongs to the day it started
 * (clock_in is a local DATETIME, so the connection's UTC offset never shifts it). Breaks come off per
 * BreakService (unpaid tapped breaks; nothing when breaks are paid).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PayHours
{
    private PDO $db;
    /** @var callable(int, string, string): array  BreakService::periodForUser shape */
    private $breaks;

    public function __construct(PDO $db, ?callable $breaks = null)
    {
        $this->db = $db;
        if ($breaks === null) {
            require_once dirname(__DIR__, 2) . '/Operations/Services/BreakService.php';
            $svc = new BreakService($db);
            $breaks = fn(int $u, string $f, string $t) => $svc->periodForUser($u, $f, $t);
        }
        $this->breaks = $breaks;
    }

    /**
     * @return array{days: array<string,int>, unapproved: array<string,int>}  paid minutes per day; minutes left out
     */
    public function forPeriod(int $userId, string $from, string $to): array
    {
        // One row per clock entry, approved when ANY approved timesheet covers its day — never joined against
        // every covering timesheet, which double-counted minutes when two sheets overlap (Codex 2026-10-23).
        // A shift belongs to the day it started (ESA "day": a shift over midnight is the 24 h from its start).
        $st = $this->db->prepare("
            SELECT DATE(e.clock_in) AS d, SUM(e.total_minutes) AS m,
                   MIN(CASE WHEN EXISTS (SELECT 1 FROM timesheets t WHERE t.user_id = e.user_id
                                            AND DATE(e.clock_in) BETWEEN t.week_start AND t.week_end
                                            AND t.status = 'approved') THEN 1 ELSE 0 END) AS approved
            FROM time_clock_entries e
            WHERE e.user_id = ? AND e.clock_in >= ? AND e.clock_in < ?
              AND e.status IN ('completed', 'edited')
            GROUP BY DATE(e.clock_in)
        ");
        $st->execute([$userId, $from, date('Y-m-d', strtotime($to . ' +1 day'))]);
        $days = []; $unapproved = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = max(0, (int)$r['m']);
            if ((int)$r['approved'] === 1) $days[(string)$r['d']] = $m; else $unapproved[(string)$r['d']] = $m;
        }
        $brk = ($this->breaks)($userId, $from, $to);
        foreach ((array)($brk['days'] ?? []) as $d => $b) {
            if (isset($days[$d])) $days[$d] = max(0, $days[$d] - (int)($b['unpaid_minutes'] ?? 0));
        }
        ksort($days); ksort($unapproved);
        return ['days' => $days, 'unapproved' => $unapproved];
    }
}
