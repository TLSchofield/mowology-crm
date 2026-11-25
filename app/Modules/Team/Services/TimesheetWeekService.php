<?php
declare(strict_types=1);

/**
 * TimesheetWeekService — one week of everyone's hours as a grid (Tim, 2026-10-23: "like Jobber's, adjustable always").
 *
 * The Mowology app is the master record of hours. This reads the same rows the single-person page reads
 * (time_clock_entries + job_time_entries, breaks off the day) and the same overtime rules (OvertimeCalculator), but
 * lays a whole week out as people × Monday–Sunday, so a day that is wrong can be seen and fixed in one place.
 *
 * Pure building (buildPerson, weekStartOf, fmt…) is separate from loading (forWeek) so it can be tested without a database.
 * Nothing here changes a row: edits go through time-entry-edit.php / job-time-entry-edit.php, as before.
 *
 * Global namespace, no autoloader: require_once the file.
 */
require_once __DIR__ . '/OvertimeCalculator.php';
require_once __DIR__ . '/TimeclockRules.php';

class TimesheetWeekService
{
    /** Monday (Y-m-d) of the week a date falls in. */
    public static function weekStartOf(string $date): string
    {
        return TimeclockRules::weekStart($date);
    }

    /** The seven dates, Monday first. @return string[] */
    public static function weekDates(string $weekStart): array
    {
        $out = [];
        $d = new DateTime($weekStart);
        for ($i = 0; $i < 7; $i++) {
            $out[] = $d->format('Y-m-d');
            $d->modify('+1 day');
        }
        return $out;
    }

    /** 452 → "7h 32m"; 0 → "—". */
    public static function fmt(int $minutes): string
    {
        if ($minutes <= 0) return '—';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? ($m > 0 ? $h . 'h ' . $m . 'm' : $h . 'h') : $m . 'm';
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = isset($parts[0]) ? mb_substr($parts[0], 0, 1) : '';
        $b = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($a . $b);
    }

    /**
     * The words (never colour alone) for a timesheet's state.
     * @return array{code: string, word: string}
     */
    public static function statusWord(?string $status): array
    {
        switch ($status) {
            case 'approved':  return ['code' => 'approved',  'word' => 'Approved'];
            case 'submitted': return ['code' => 'submitted', 'word' => 'Waiting for approval'];
            case 'rejected':  return ['code' => 'rejected',  'word' => 'Sent back'];
            default:          return ['code' => 'open',      'word' => 'Open'];
        }
    }

    /**
     * One person's week.
     *
     * @param array{id: int, name: string, rate: float} $user
     * @param array<int, array> $clock  time_clock_entries rows (id, clock_in, clock_out, total_minutes, status, notes)
     * @param array<int, array> $jobs   job_time_entries rows (id, start_time, end_time, duration_minutes, status, job_title, job_number, property_address)
     * @param array<int, array{unpaid_minutes?: int, break_minutes?: int}> $breaks entry id => breaks
     * @param ?array $timesheet         the timesheets row (id, status) or null when none exists yet
     */
    public static function buildPerson(string $weekStart, array $user, array $clock, array $jobs, array $breaks, ?array $timesheet): array
    {
        $dates = self::weekDates($weekStart);
        $days = [];
        foreach ($dates as $d) {
            $days[$d] = ['date' => $d, 'shift_min' => 0, 'job_min' => 0, 'anomaly_min' => 0, 'entries' => [], 'jobs' => [], 'open' => false];
        }

        foreach ($clock as $ce) {
            $status = (string)($ce['status'] ?? '');
            if ($status === 'void') continue;
            $day = substr((string)$ce['clock_in'], 0, 10);
            if (!isset($days[$day])) continue;
            $counted = in_array($status, ['completed', 'edited'], true);
            $unpaid = (int)($breaks[(int)$ce['id']]['unpaid_minutes'] ?? 0);
            $paid = $counted ? self::paidMinutes((int)($ce['total_minutes'] ?? 0), $unpaid) : 0;
            $out = $ce['clock_out'] ?? null;
            $days[$day]['entries'][] = [
                'id'          => (int)$ce['id'],
                'in'          => (string)$ce['clock_in'],
                'out'         => $out !== null && $out !== '' ? (string)$out : null,
                'minutes'     => $paid,
                'status'      => $status,
                'notes'       => (string)($ce['notes'] ?? ''),
                'next_day'    => $out ? (substr((string)$out, 0, 10) !== $day) : false,
            ];
            if ($status === 'active') $days[$day]['open'] = true;
            $days[$day]['shift_min'] += $paid;
        }

        foreach ($jobs as $je) {
            if (($je['status'] ?? '') === 'void') continue;
            $day = substr((string)$je['start_time'], 0, 10);
            if (!isset($days[$day])) continue;
            $min = (int)($je['duration_minutes'] ?? 0);
            $end = $je['end_time'] ?? null;
            $days[$day]['jobs'][] = [
                'id'       => (int)$je['id'],
                'start'    => (string)$je['start_time'],
                'end'      => $end !== null && $end !== '' ? (string)$end : null,
                'minutes'  => $min,
                'title'    => (string)($je['job_title'] ?? ''),
                'number'   => (string)($je['job_number'] ?? ''),
                'address'  => (string)($je['property_address'] ?? ''),
                'next_day' => $end ? (substr((string)$end, 0, 10) !== $day) : false,
            ];
            $days[$day]['job_min'] += $min;
        }

        // A timer that ran past the shift: the same flag the single-person page shows.
        foreach ($days as &$day) {
            if ($day['shift_min'] > 0 && $day['job_min'] > $day['shift_min']) {
                $day['anomaly_min'] = $day['job_min'] - $day['shift_min'];
            }
        }
        unset($day);

        $daily = [];
        foreach ($days as $d => $row) $daily[$d] = $row['shift_min'];
        $pay = OvertimeCalculator::calculate($daily, (float)($user['rate'] ?? 0));
        $total = array_sum($daily);
        $ot = (int)$pay['ot_1_5_minutes'] + (int)$pay['ot_2_0_minutes'];

        $flags = [];
        foreach ($days as $d => $row) {
            if ($row['anomaly_min'] > 0) $flags[] = ['code' => 'timer_past_shift', 'date' => $d, 'minutes' => $row['anomaly_min']];
            if ($row['open']) $flags[] = ['code' => 'shift_running', 'date' => $d, 'minutes' => 0];
        }

        $st = self::statusWord($timesheet['status'] ?? null);
        return [
            'user_id'    => (int)$user['id'],
            'name'       => (string)$user['name'],
            'initials'   => self::initials((string)$user['name']),
            'rate'       => (float)($user['rate'] ?? 0),
            'timesheet_id' => $timesheet ? (int)$timesheet['id'] : null,
            'status'     => $st['code'],
            'status_word' => $st['word'],
            'days'       => $days,
            'total_min'  => $total,
            'regular_min' => (int)$pay['regular_minutes'],
            'ot_min'     => $ot,
            'gross'      => (float)$pay['gross_pay'],
            'flags'      => $flags,
            'locked'     => ($timesheet['status'] ?? '') === 'approved',
        ];
    }

    /** Minutes paid on a shift once its unpaid break is off (Mowology pays breaks, so unpaid is normally 0). */
    public static function paidMinutes(int $shiftMinutes, int $unpaidMinutes): int
    {
        return max(0, $shiftMinutes - max(0, $unpaidMinutes));
    }

    /**
     * Everyone with a timesheet or any shift that week, A–Z.
     * @return array{week_start: string, week_end: string, dates: string[], people: array, totals: array}
     */
    public static function forWeek(PDO $db, string $weekStart): array
    {
        $weekStart = self::weekStartOf($weekStart);
        $dates = self::weekDates($weekStart);
        $weekEnd = $dates[6];

        // People: anyone with a timesheet row that week or a live shift in it.
        $ids = [];
        $tsByUser = [];
        $st = $db->prepare("SELECT id, user_id, status FROM timesheets WHERE week_start = ?");
        $st->execute([$weekStart]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $tsByUser[(int)$r['user_id']] = $r; $ids[(int)$r['user_id']] = true; }
        $st = $db->prepare("SELECT DISTINCT user_id FROM time_clock_entries WHERE DATE(clock_in) BETWEEN ? AND ? AND status <> 'void'");
        $st->execute([$weekStart, $weekEnd]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) $ids[(int)$uid] = true;
        // A person with only a job timer that week (no shift, no timesheet yet) must not vanish from the grid.
        try {
            $st = $db->prepare("SELECT DISTINCT user_id FROM job_time_entries WHERE DATE(start_time) BETWEEN ? AND ? AND status IN ('completed', 'edited')");
            $st->execute([$weekStart, $weekEnd]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) $ids[(int)$uid] = true;
        } catch (Throwable $e) { /* no job timers table here: shifts alone still show */ }
        if (!$ids) return ['week_start' => $weekStart, 'week_end' => $weekEnd, 'dates' => $dates, 'people' => [], 'totals' => ['minutes' => 0, 'ot_minutes' => 0, 'flags' => 0, 'waiting' => 0]];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $us = $db->prepare("SELECT id, full_name, hourly_rate FROM users WHERE id IN ($in) ORDER BY full_name");
        $us->execute(array_keys($ids));
        $users = $us->fetchAll(PDO::FETCH_ASSOC);

        $breakSvc = class_exists('BreakService') ? new BreakService($db) : null;
        $people = [];
        $tot = ['minutes' => 0, 'ot_minutes' => 0, 'flags' => 0, 'waiting' => 0];
        foreach ($users as $u) {
            $uid = (int)$u['id'];
            $ce = $db->prepare("SELECT * FROM time_clock_entries WHERE user_id = ? AND DATE(clock_in) BETWEEN ? AND ? AND status <> 'void' ORDER BY clock_in");
            $ce->execute([$uid, $weekStart, $weekEnd]);
            $clock = $ce->fetchAll(PDO::FETCH_ASSOC);
            $jobs = function_exists('getJobTimeEntriesForRange') ? getJobTimeEntriesForRange($uid, $weekStart, $weekEnd) : [];
            $breaks = [];
            if ($breakSvc && $clock) {
                try { $breaks = $breakSvc->forEntries($clock); } catch (Throwable $e) { error_log('[timesheet-week] breaks: ' . $e->getMessage()); }
            }
            $p = self::buildPerson($weekStart, ['id' => $uid, 'name' => (string)$u['full_name'], 'rate' => (float)($u['hourly_rate'] ?? 0)],
                                   $clock, $jobs, $breaks, $tsByUser[$uid] ?? null);
            $people[] = $p;
            $tot['minutes'] += $p['total_min'];
            $tot['ot_minutes'] += $p['ot_min'];
            $tot['flags'] += count($p['flags']);
            if ($p['status'] === 'submitted') $tot['waiting']++;
        }
        return ['week_start' => $weekStart, 'week_end' => $weekEnd, 'dates' => $dates, 'people' => $people, 'totals' => $tot];
    }
}
