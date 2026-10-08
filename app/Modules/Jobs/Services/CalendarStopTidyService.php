<?php
declare(strict_types=1);

/**
 * CalendarStopTidyService — remove a calendar stop that a move has left with nothing on it.
 *
 * A calendar_stops row is the "we're at this property on this day" card on the schedule. When a
 * visit is moved to another day (office drag, crew pulling work forward on site, the nightly
 * auto_rollover) the visit is re-linked to a stop on the new day, but the old stop used to stay:
 * an empty card on the old day, counted by Otto as booked work that never existed.
 *
 * A stop is removed only when NO visit other than a cancelled one still points at it — a skipped
 * or completed visit keeps its stop (that is history the office reads). calendar_stop_crew rows go
 * first (they also cascade in MySQL; explicit for engines without the FK).
 *
 * Global-namespace, no autoloader: require_once this file and call statically.
 */
class CalendarStopTidyService
{
    /**
     * Delete the stop if it is empty. Returns true when a row was removed.
     */
    public static function deleteIfEmpty(PDO $db, ?int $stopId): bool
    {
        if (!$stopId || $stopId < 1) {
            return false;
        }
        $left = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE stop_id = ? AND status <> 'cancelled'");
        $left->execute([$stopId]);
        if ((int)$left->fetchColumn() > 0) {
            return false;
        }
        try {
            $db->prepare("DELETE FROM calendar_stop_crew WHERE stop_id = ?")->execute([$stopId]);
        } catch (PDOException $e) {
            // Table absent on a very old schema — nothing to clear.
        }
        // Cancelled visits still pointing here are released, not deleted.
        $db->prepare("UPDATE job_visits SET stop_id = NULL WHERE stop_id = ?")->execute([$stopId]);
        $del = $db->prepare("DELETE FROM calendar_stops WHERE id = ?");
        $del->execute([$stopId]);
        return $del->rowCount() > 0;
    }
}
