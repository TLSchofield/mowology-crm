<?php
declare(strict_types=1);

/**
 * TimesheetService — a crew member's own week of clock punches and job time.
 *
 * Feeds the mobile "My Timesheet" screen (JWT clock endpoint, mode=week). The
 * web equivalent is public/crm/timeclock/my-timesheet.php, which predates this
 * service and still queries inline.
 *
 * Global-namespace, no autoloader: require_once this file, then call statically.
 */
class TimesheetService
{
    /**
     * Normalise any date to the Monday of its week (Y-m-d). Invalid input → this week.
     * PURE.
     */
    public static function weekStartFor(string $date, ?int $now = null): string
    {
        $now = $now ?? time();
        $ts  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? strtotime($date) : false;
        if ($ts === false) {
            $ts = $now;
        }
        return date('Y-m-d', strtotime('monday this week', $ts));
    }

    /**
     * Shape raw clock + job rows into a Mon→Sun week with per-day and week totals.
     * An open clock entry counts up to $now so the running shift is included.
     * PURE — no DB.
     *
     * @param array  $clockEntries rows of time_clock_entries (clock_in, clock_out, status, notes)
     * @param array  $jobEntries   rows from getJobTimeEntriesForRange()
     * @param string $weekStart    Monday, Y-m-d
     * @param array  $breaksByEntry entry id → BreakService::forEntries() summary (breaks counted once)
     * @param bool   $breaksPaid    false → tapped break time comes off paid_seconds
     */
    public static function buildWeek(array $clockEntries, array $jobEntries, string $weekStart, int $now,
                                     array $breaksByEntry = [], bool $breaksPaid = true): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("{$weekStart} +{$i} days"));
            $days[$d] = ['date' => $d, 'total_seconds' => 0, 'job_seconds' => 0, 'break_seconds' => 0, 'paid_seconds' => 0,
                         'entries' => [], 'jobs' => []];
        }

        $weekTotal = 0;
        $weekBreak = 0;
        $weekPaid  = 0;
        foreach ($clockEntries as $e) {
            $d = date('Y-m-d', strtotime($e['clock_in']));
            if (!isset($days[$d])) {
                continue;
            }
            $end     = !empty($e['clock_out']) ? strtotime($e['clock_out']) : $now;
            $seconds = max(0, $end - strtotime($e['clock_in']));
            $sum     = $breaksByEntry[(int)$e['id']] ?? ['breaks' => [], 'break_minutes' => 0, 'unpaid_minutes' => 0];
            $breakSeconds = (int)$sum['break_minutes'] * 60;
            $paidSeconds  = max(0, $seconds - ($breaksPaid ? 0 : (int)$sum['unpaid_minutes'] * 60));

            $days[$d]['entries'][] = [
                'id'               => (int)$e['id'],
                'clock_in'         => $e['clock_in'],
                'clock_out'        => $e['clock_out'] ?? null,
                'duration_seconds' => $seconds,
                'is_open'          => empty($e['clock_out']),
                'edited'           => ($e['status'] ?? '') === 'edited',
                'notes'            => ($e['notes'] ?? '') !== '' ? $e['notes'] : null,
                'break_seconds'    => $breakSeconds,
                'paid_seconds'     => $paidSeconds,
                'breaks'           => array_map(static fn (array $b): array => [
                    'start'   => date('Y-m-d H:i:s', (int)$b['start']),
                    'end'     => $b['open'] ? null : date('Y-m-d H:i:s', (int)$b['end']),
                    'minutes' => (int)$b['minutes'],
                    'source'  => (string)$b['source'],
                ], $sum['breaks']),
            ];
            $days[$d]['total_seconds'] += $seconds;
            $days[$d]['break_seconds'] += $breakSeconds;
            $days[$d]['paid_seconds']  += $paidSeconds;
            $weekTotal                 += $seconds;
            $weekBreak                 += $breakSeconds;
            $weekPaid                  += $paidSeconds;
        }

        $jobTotal = 0;
        foreach ($jobEntries as $j) {
            $d = date('Y-m-d', strtotime($j['start_time']));
            if (!isset($days[$d])) {
                continue;
            }
            $seconds = !empty($j['end_time'])
                ? max(0, strtotime($j['end_time']) - strtotime($j['start_time']))
                : max(0, (int)($j['duration_minutes'] ?? 0) * 60);

            $days[$d]['jobs'][] = [
                'id'               => (int)$j['id'],
                'visit_id'         => (int)$j['visit_id'],
                'job_title'        => $j['job_title'] ?? null,
                'property_address' => $j['property_address'] ?? null,
                'start_time'       => $j['start_time'],
                'end_time'         => $j['end_time'] ?? null,
                'duration_seconds' => $seconds,
            ];
            $days[$d]['job_seconds'] += $seconds;
            $jobTotal                += $seconds;
        }

        return [
            'week_start'        => $weekStart,
            'week_end'          => date('Y-m-d', strtotime("{$weekStart} +6 days")),
            'total_seconds'     => $weekTotal,
            'job_total_seconds' => $jobTotal,
            'break_seconds'     => $weekBreak,
            'paid_seconds'      => $weekPaid,
            'breaks_paid'       => $breaksPaid,
            'days'              => array_values($days),
        ];
    }

    /**
     * One user's week. Requires timeclock-functions.php to be loaded by the caller.
     */
    public static function forUser(int $userId, string $date): array
    {
        $weekStart = self::weekStartFor($date);
        $weekEnd   = date('Y-m-d', strtotime("{$weekStart} +6 days"));
        $clock     = getClockEntriesForRange($userId, $weekStart, $weekEnd);

        $breaks = [];
        $paid   = true;
        try {
            require_once dirname(__DIR__, 2) . '/Operations/Services/BreakService.php';
            $svc    = new BreakService(getDB());
            $paid   = $svc->breaksPaid();
            $breaks = $svc->forEntries($clock);
        } catch (Throwable $e) {
            error_log('[TimesheetService] breaks: ' . $e->getMessage());
        }

        return self::buildWeek(
            $clock,
            getJobTimeEntriesForRange($userId, $weekStart, $weekEnd),
            $weekStart,
            time(),
            $breaks,
            $paid
        );
    }
}
