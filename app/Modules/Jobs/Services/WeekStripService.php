<?php
/**
 * WeekStripService — the dot under each day of the crew schedule's week strip (2026-10-14, Tim: "calendar
 * styling should be the same on both apps"; "iOS looks better").
 *
 * The iPhone's strip (WeekStripView.swift, data from app/Modules/Schedule/Api/week.php) shows a dot under
 * a day with work: orange while any visit is still to do, green when every visit is done / skipped /
 * cancelled, none when there are no stops. The web strip now shows the same, from one light query
 * (the day view only loads its own day's stops).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class WeekStripService
{
    /** Visit statuses that leave nothing to do — the same list week.php uses. */
    public const FINISHED = ['completed', 'skipped', 'cancelled'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Pure (tested): the dot for a day — 'todo', 'done' or null (no stops). */
    public static function dot(int $stops, int $openVisits): ?string
    {
        if ($stops <= 0) return null;
        return $openVisits > 0 ? 'todo' : 'done';
    }

    /**
     * date => 'todo' | 'done' for the 7 days from $monday. Crew filter as getCalendarStops(): a stop is
     * theirs when they are its crew or listed in calendar_stop_crew. [] on any error (no dots, never a
     * broken page).
     */
    public function dots(string $monday, ?int $crewId = null): array
    {
        try {
            $end = date('Y-m-d', strtotime($monday . ' +6 days'));
            $finished = "'" . implode("','", self::FINISHED) . "'";
            $sql = "SELECT cs.stop_date AS d, COUNT(DISTINCT cs.id) AS stops,
                           COUNT(DISTINCT CASE WHEN jv.id IS NOT NULL AND COALESCE(jv.status, 'scheduled') NOT IN ({$finished}) THEN jv.id END) AS open_visits
                    FROM calendar_stops cs
                    LEFT JOIN job_visits jv ON jv.stop_id = cs.id AND jv.status <> 'cancelled'
                    WHERE cs.stop_date BETWEEN ? AND ?";
            $args = [$monday, $end];
            if ($crewId) {
                $sql .= " AND (cs.crew_id = ? OR cs.id IN (SELECT stop_id FROM calendar_stop_crew WHERE user_id = ?))";
                $args[] = $crewId;
                $args[] = $crewId;
            }
            $sql .= " GROUP BY cs.stop_date";
            $s = $this->db->prepare($sql);
            $s->execute($args);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dot = self::dot((int)$r['stops'], (int)$r['open_visits']);
                if ($dot !== null) $out[substr((string)$r['d'], 0, 10)] = $dot;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[WeekStrip] dots: ' . $e->getMessage());
            return [];
        }
    }
}
