<?php
declare(strict_types=1);

/**
 * ProximityAutoStartService — the pure decision rules behind GPS auto-arrival.
 *
 * checkProximityAutoStart() (TimeclockFunctions.php) does the I/O; every rule
 * that decides WHETHER a ping should start someone's job timer lives here so it
 * can be unit-tested without a database.
 *
 * Why these rules exist (2026-09-19 audit): the original check evaluated every
 * crew's visits, acted on a single ping, and clocked the user in BEFORE finding
 * out the timer could not start — so driving past another crew's job put people
 * on the payroll clock with no timer and no alert.
 *
 * Global-namespace, no autoloader: require_once this file and call statically.
 */
class ProximityAutoStartService
{
    /** A prior fix must be at least this old to prove the crew stayed (not one burst of pings). */
    public const DWELL_MIN_AGE_SECONDS = 45;
    /** …and no older than this, or it says nothing about the current arrival. */
    public const DWELL_MAX_AGE_SECONDS = 300;

    /**
     * Is this visit the caller's to work? Assigned crew, or crewed onto the visit's
     * stop. Deliberately has NO admin bypass: an owner driving past a job site must
     * not be auto-started on it either.
     *
     * @param int[] $stopIdsUserIsCrewedOn calendar_stop_crew.stop_id values for this user
     */
    public static function isOwnVisit(array $visit, int $userId, array $stopIdsUserIsCrewedOn): bool
    {
        if ($userId < 1) {
            return false;
        }
        if ((int)($visit['assigned_crew_id'] ?? 0) === $userId) {
            return true;
        }
        if ((int)($visit['stop_crew_id'] ?? 0) === $userId) {
            return true;
        }
        $stopId = (int)($visit['stop_id'] ?? 0);
        return $stopId > 0 && in_array($stopId, array_map('intval', $stopIdsUserIsCrewedOn), true);
    }

    /**
     * A fix is only usable if its error circle is no bigger than the fence it is
     * being tested against. (Was 1.5× the radius — a 225 m-uncertain fix could
     * "arrive" at a 150 m fence from well outside it.)
     */
    public static function accuracyAcceptable(float $accuracyMeters, int $radiusMeters): bool
    {
        return $accuracyMeters > 0 && $accuracyMeters <= $radiusMeters;
    }

    /**
     * Timed visits may only auto-start within $leadMinutes before their start
     * (and any time after it, that day). Untimed visits — the norm for route
     * work — are always in window. $leadMinutes <= 0 disables the check.
     */
    public static function withinWindow(?string $scheduledDate, ?string $timeStart, int $nowTs, int $leadMinutes): bool
    {
        if ($leadMinutes <= 0 || !$scheduledDate || !$timeStart || $timeStart === '00:00:00') {
            return true;
        }
        $startTs = strtotime("{$scheduledDate} {$timeStart}");
        if ($startTs === false) {
            return true;
        }
        return $nowTs >= $startTs - ($leadMinutes * 60);
    }

    /**
     * Order in-range candidates: inside a drawn arrival border beats a radius
     * match, then nearest first. Callers walk this list and take the first one
     * that passes the remaining guards — so an ineligible nearest visit no longer
     * blocks the crew's own visit next door (townhouse complexes, neighbours).
     *
     * @param array<int, array{visit: array, distance: float, inside_border: bool}> $candidates
     */
    public static function rank(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            if ($a['inside_border'] !== $b['inside_border']) {
                return $a['inside_border'] ? -1 : 1;
            }
            return $a['distance'] <=> $b['distance'];
        });
        return $candidates;
    }

    /**
     * Dwell: was the crew ALSO inside this fence on an earlier fix, 45 s–5 min ago?
     * One ping inside a fence is a drive-by; two a minute apart is an arrival.
     *
     * @param array<int, array{lat: float, lng: float, age_seconds: int}> $priorFixes
     * @param callable(float, float): bool $isInsideFence
     */
    public static function hasDwell(array $priorFixes, callable $isInsideFence): bool
    {
        foreach ($priorFixes as $fix) {
            $age = (int)($fix['age_seconds'] ?? -1);
            if ($age < self::DWELL_MIN_AGE_SECONDS || $age > self::DWELL_MAX_AGE_SECONDS) {
                continue;
            }
            if ($isInsideFence((float)$fix['lat'], (float)$fix['lng'])) {
                return true;
            }
        }
        return false;
    }
}
