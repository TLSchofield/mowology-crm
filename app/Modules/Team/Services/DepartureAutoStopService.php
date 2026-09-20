<?php
declare(strict_types=1);

/**
 * DepartureAutoStopService — the missing half of GPS auto-arrival.
 *
 * Arrival auto-STARTED job timers; nothing ever stopped them. Found 2026-09-19: every
 * auto-started job ran until the next evening's auto clock-out (a 40-minute lawn cut
 * recorded as ~36 h), and a timer nobody stopped blocked all further auto-arrivals for
 * its owner — for two months, in the owner's case.
 *
 * This stops the TIMER when the crew has clearly left the site, and back-dates the stop
 * to the last moment they were actually there, so the recorded duration is real.
 *
 * It deliberately does NOT complete the visit. Leaving the fence might be a fuel run or
 * lunch; completing a visit emails the client and feeds invoicing, and a GPS guess must
 * not do that. The visit stays in_progress, the crew get "you left — mark complete?",
 * and coming back resumes the timer (see checkProximityAutoStart).
 *
 * Global-namespace, no autoloader: require_once, then call statically / instantiate.
 */
class DepartureAutoStopService
{
    /** Outside the fence by more than this, beyond the fix's own error circle, counts as "away". */
    public const EXIT_BUFFER_M = 60;
    /** …and they must have been away this long. Long enough to ignore GPS wobble at the kerb. */
    public const AWAY_SECONDS = 240;
    /** How far back to look at fixes. */
    public const LOOKBACK_SECONDS = 1200;
    /** A timer younger than this is never auto-stopped (arrival jitter). */
    public const MIN_JOB_SECONDS = 180;
    /** The newest fix must be at least this recent, or we know nothing about "now". */
    public const FRESH_SECONDS = 150;

    // ── Pure rules ──────────────────────────────────────────────────────────

    /**
     * Decide whether the crew has departed, and when they were last on site.
     *
     * @param array<int, array{ts:int, inside:bool, away:bool}> $fixes  any order
     *        inside = within the fence; away = beyond fence + buffer + accuracy (clearly gone).
     *        A fix that is neither is "at the edge" and proves nothing either way.
     * @return array{departed:bool, left_at:?int, reason:string}
     */
    public static function evaluate(array $fixes, int $timerStartTs, int $nowTs): array
    {
        if ($nowTs - $timerStartTs < self::MIN_JOB_SECONDS) {
            return ['departed' => false, 'left_at' => null, 'reason' => 'too_soon'];
        }
        usort($fixes, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $fixes = array_values(array_filter($fixes, static fn (array $f): bool => $f['ts'] >= $timerStartTs - 60));
        if (count($fixes) < 2) {
            return ['departed' => false, 'left_at' => null, 'reason' => 'not_enough_fixes'];
        }

        $newest = $fixes[count($fixes) - 1];
        if ($nowTs - $newest['ts'] > self::FRESH_SECONDS) {
            return ['departed' => false, 'left_at' => null, 'reason' => 'stale'];
        }

        // Walk back from now: the unbroken run of "away" fixes at the end of the trail.
        $firstAwayTs = null;
        $awayCount   = 0;
        for ($i = count($fixes) - 1; $i >= 0; $i--) {
            if (!$fixes[$i]['away']) {
                break;
            }
            $firstAwayTs = $fixes[$i]['ts'];
            $awayCount++;
        }
        if ($firstAwayTs === null || $awayCount < 2) {
            return ['departed' => false, 'left_at' => null, 'reason' => 'still_on_site'];
        }
        if ($newest['ts'] - $firstAwayTs < self::AWAY_SECONDS) {
            return ['departed' => false, 'left_at' => null, 'reason' => 'not_away_long_enough'];
        }

        // They left at the last fix INSIDE the fence; if we never saw one (arrived on a
        // polygon edge, sparse fixes), the first "away" fix is the best evidence we have.
        $leftAt = null;
        foreach ($fixes as $f) {
            if ($f['ts'] >= $firstAwayTs) {
                break;
            }
            if ($f['inside']) {
                $leftAt = $f['ts'];
            }
        }
        $leftAt = max($leftAt ?? $firstAwayTs, $timerStartTs);

        return ['departed' => true, 'left_at' => $leftAt, 'reason' => 'departed'];
    }

    /** Classify one fix against a circular fence. */
    public static function classify(float $distanceM, float $accuracyM, int $radiusM): array
    {
        $acc = $accuracyM > 0 ? min($accuracyM, 100.0) : 35.0;
        return [
            'inside' => $distanceM <= $radiusM,
            'away'   => $distanceM > $radiusM + self::EXIT_BUFFER_M + $acc,
        ];
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /**
     * Call after storing a fresh fix. Stops the user's live job timer if they have left the
     * site. Returns a payload for the client, or null when nothing happened.
     *
     * Only AUTO-started timers are auto-stopped unless auto_departure_all_timers = 1: someone
     * who pressed Start themselves may be working the boulevard across the road on purpose.
     */
    public static function check(PDO $db, int $userId, int $nowTs): ?array
    {
        if (getTimeClockSetting('auto_departure_enabled', '1') !== '1') {
            return null;
        }
        $timer = getLiveJobTimer($userId);
        if (!$timer) {
            return null;
        }
        if (empty($timer['auto_started']) && getTimeClockSetting('auto_departure_all_timers', '0') !== '1') {
            return null;
        }

        $visitId = (int)$timer['visit_id'];
        $site = $db->prepare("
            SELECT p.id AS property_id, p.latitude, p.longitude, p.address
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jv.id = ?
        ");
        $site->execute([$visitId]);
        $prop = $site->fetch(PDO::FETCH_ASSOC);
        if (!$prop || !$prop['latitude'] || !$prop['longitude']) {
            return null;   // nowhere to measure from
        }

        $radius  = (int)getTimeClockSetting('gps_proximity_meters', '150');
        $border  = null;
        if (function_exists('geofenceGetArrivalBorder') && !empty($prop['property_id'])) {
            $border = geofenceGetArrivalBorder((int)$prop['property_id']);
        }

        $startTs = (int)strtotime((string)$timer['start_time']);
        $stmt = $db->prepare("
            SELECT UNIX_TIMESTAMP(timestamp) AS ts, latitude, longitude, accuracy_meters
            FROM crew_location_history
            WHERE crew_id = ? AND timestamp >= FROM_UNIXTIME(?)
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$userId, max($startTs - 60, $nowTs - self::LOOKBACK_SECONDS)]);

        $fixes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dist = haversineDistance((float)$r['latitude'], (float)$r['longitude'], (float)$prop['latitude'], (float)$prop['longitude']);
            $c    = self::classify($dist, (float)($r['accuracy_meters'] ?? 0), $radius);
            // Inside a drawn arrival border is inside, however far the centre point is.
            if ($border && !empty($border['polygon'])
                && geofencePointInPolygon((float)$r['latitude'], (float)$r['longitude'], $border['polygon'], $border['bbox'])) {
                $c = ['inside' => true, 'away' => false];
            }
            $fixes[] = ['ts' => (int)$r['ts'], 'inside' => $c['inside'], 'away' => $c['away']];
        }

        $verdict = self::evaluate($fixes, $startTs, $nowTs);
        if (!$verdict['departed']) {
            return null;
        }

        $leftAt = (int)$verdict['left_at'];
        $upd = $db->prepare("
            UPDATE job_time_entries
            SET end_time         = FROM_UNIXTIME(?),
                duration_minutes = GREATEST(1, TIMESTAMPDIFF(MINUTE, start_time, FROM_UNIXTIME(?))),
                notes            = CONCAT(COALESCE(notes, ''), ' [auto-stopped: left the job site]'),
                status           = 'completed',
                auto_stopped     = 1
            WHERE id = ? AND status = 'active' AND end_time IS NULL
        ");
        $upd->execute([$leftAt, $leftAt, (int)$timer['id']]);
        if ($upd->rowCount() === 0) {
            return null;   // someone stopped it first
        }

        try {
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, 'timer_auto_stopped_on_departure', ?, 'gps')
            ")->execute([$visitId, $userId, json_encode(['entry_id' => (int)$timer['id'], 'left_at' => date('Y-m-d H:i:s', $leftAt)])]);
        } catch (Throwable $e) { /* audit is non-critical */ }

        if (function_exists('ensureTimesheetExists')) {
            ensureTimesheetExists($userId, date('Y-m-d', $leftAt));
        }

        return [
            'visit_id'         => $visitId,
            'job_title'        => $timer['job_title'] ?? '',
            'property_address' => $prop['address'] ?? ($timer['property_address'] ?? ''),
            'left_at'          => date('Y-m-d H:i:s', $leftAt),
            'duration_minutes' => max(1, (int)floor(($leftAt - $startTs) / 60)),
            'visit_completed'  => false,
        ];
    }
}
