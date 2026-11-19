<?php
/**
 * ScheduleListService — the schedule's List view: one row per stop, grouped by day, in the iPhone list's
 * shape (headline, address, service, "last done", crew, visit count). Pure formatting over the stops
 * getCalendarStops() already returns plus ServiceHistoryService::forVisits(); no queries of its own.
 *
 * Headline follows the iPhone's Stop.headline: building name, else company, else client, never just the
 * street address. A stop is "unfinished" when its day has passed and a visit is still open, or its history
 * line is a skip — the rows Otto's roll-over would later pick up. Global namespace (require_once + static).
 */
declare(strict_types=1);

class ScheduleListService
{
    private const RESOLVED = ['completed', 'skipped', 'cancelled'];

    /**
     * @param array $calendarData  date => [stop, ...] as returned by getCalendarStops()
     * @param array $historyByVisit visit_id => ['summary' => ?string, ...] from ServiceHistoryService::forVisits()
     * @return array date => list of row arrays, days ascending, rows by time then route order
     */
    public static function rowsByDay(array $calendarData, array $historyByVisit, string $today): array
    {
        ksort($calendarData);
        $days = [];
        foreach ($calendarData as $date => $stops) {
            $rows = [];
            foreach ($stops as $stop) {
                $rows[] = self::row($stop, $historyByVisit, (string)$date, $today);
            }
            usort($rows, static function (array $a, array $b): int {
                return strcmp($a['sort_time'], $b['sort_time']) ?: ($a['route_order'] <=> $b['route_order']);
            });
            if ($rows) {
                $days[(string)$date] = $rows;
            }
        }
        return $days;
    }

    public static function row(array $stop, array $historyByVisit, string $date, string $today): array
    {
        $visits   = $stop['visits'] ?? [];
        $statuses = array_map(static fn (array $v): string => strtolower((string)($v['visit_status'] ?? 'scheduled')), $visits);
        $resolved = $statuses !== [] && count(array_filter($statuses, static fn (string $s): bool => in_array($s, self::RESOLVED, true))) === count($statuses);
        $done     = $statuses !== [] && count(array_filter($statuses, static fn (string $s): bool => $s === 'completed')) === count($statuses);

        // Several services on one stop: a skip wins, else the first visit still to do.
        $open = array_values(array_filter($visits, static fn (array $v): bool => !in_array(strtolower((string)($v['visit_status'] ?? '')), self::RESOLVED, true)));
        $pool = $open ?: $visits;
        $line = null;
        $warn = false;
        foreach ([true, false] as $wantWarning) {
            foreach ($pool as $v) {
                $s = $historyByVisit[(int)($v['visit_id'] ?? 0)]['summary'] ?? null;
                if ($s !== null && (strpos((string)$s, 'Skipped') === 0) === $wantWarning) {
                    $line = (string)$s;
                    $warn = $wantWarning;
                    break 2;
                }
            }
        }

        $services = [];
        foreach ($visits as $v) {
            $t = trim((string)(($v['plan_title'] ?? '') !== '' ? $v['plan_title'] : ($v['service_type'] ?? '')));
            if ($t !== '' && !in_array($t, $services, true)) {
                $services[] = $t;
            }
        }

        $first  = $visits[0] ?? [];
        $time   = $stop['estimated_arrival'] ?? ($first['scheduled_time_start'] ?? null);
        $crew   = array_values(array_filter((array)($stop['crew_names'] ?? (!empty($stop['crew_name']) ? [$stop['crew_name']] : []))));
        $client = trim((string)(($stop['company_name'] ?? '') ?: ($stop['contact_name'] ?? '')));

        return [
            'stop_id'       => (int)($stop['stop_id'] ?? 0),
            'date'          => $date,
            'route_order'   => (int)($stop['route_order'] ?? 0),
            'sort_time'     => $time !== null && $time !== '' ? (string)$time : '23:59:59',
            'time'          => $time !== null && $time !== '' ? date('g:i A', strtotime((string)$time)) : null,
            'headline'      => self::headline($stop),
            'client'        => $client,
            'address'       => trim((string)($stop['property_address'] ?? '')),
            'services'      => $services,
            'history'       => $line,
            'crew'          => $crew,
            'visit_count'   => count($visits),
            'done'          => $done,
            'resolved'      => $resolved,
            // Past its day with work still open, or the history line says it was skipped.
            'needs_attention' => (!$resolved && $date < $today) || ($warn && !$resolved),
        ];
    }

    /** Building name, else company, else client; never a name that only repeats the street address. */
    public static function headline(array $stop): string
    {
        $address = strtolower(trim((string)($stop['property_address'] ?? '')));
        foreach (['property_name', 'company_name', 'contact_name'] as $key) {
            $name = trim((string)($stop[$key] ?? ''));
            if ($name !== '' && strtolower($name) !== $address) {
                return $name;
            }
        }
        return (string)($stop['property_address'] ?? '');
    }
}
