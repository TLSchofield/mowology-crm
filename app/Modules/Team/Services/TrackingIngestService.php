<?php
declare(strict_types=1);

/**
 * TrackingIngestService — the one place a GPS fix is judged before it is stored.
 *
 * The rule this exists to enforce: employee location is recorded ONLY while the
 * employee is on the clock, has tracking enabled, and (once required) has a
 * current consent record. That boundary used to live in client code alone —
 * the iOS endpoint stored pings from anyone holding a valid token.
 *
 * Also fixes two data-integrity faults found in the 2026-09-19 audit:
 *   - every ping was stamped NOW() on receipt, so an offline queue replayed hours
 *     later landed as one burst at the reconnect moment (and could auto-start a
 *     job the crew had left). Fixes now carry their DEVICE time, bounded.
 *   - the 10 s receipt-time rate limit then discarded all but one replayed ping
 *     while answering success, so clients deleted the rest.
 *
 * Accepts the legacy single-ping body {lat,lng,accuracy,visit_id} and the batch
 * body {points:[{id,t,lat,lng,acc,speed,heading,mock,visit_id}], device:{…}}.
 *
 * Global-namespace, no autoloader: require_once, then `new TrackingIngestService($db)`.
 */
class TrackingIngestService
{
    public const MAX_BATCH               = 500;
    public const MAX_FUTURE_SECONDS      = 300;        // device clocks drift; 5 min is generous
    public const MAX_AGE_SECONDS         = 259200;     // 72 h — older than any plausible offline queue
    public const SHIFT_GRACE_SECONDS     = 120;        // a fix just before clock-in / after clock-out
    public const MIN_GAP_SECONDS         = 4;          // thinning within a batch
    public const LEGACY_MIN_GAP_SECONDS  = 9;          // live single-ping cadence guard
    public const FRESH_SECONDS           = 120;        // only a fix this recent may trigger auto-arrival

    /** @var PDO */
    private $db;
    /** @var array<string,bool> */
    private $columnCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ──────────────────────────────────────────────────────────

    /**
     * Normalise either request shape into a list of points with second-resolution `ts`.
     * A point with no device time is stamped $nowTs and flagged, so callers can tell
     * a live legacy ping from a genuinely timestamped fix.
     */
    public static function normalizePoints(array $input, int $nowTs): array
    {
        $raw = isset($input['points']) && is_array($input['points'])
            ? array_slice($input['points'], 0, self::MAX_BATCH)
            : [$input];

        $out = [];
        foreach ($raw as $p) {
            if (!is_array($p) || !isset($p['lat'], $p['lng']) || !is_numeric($p['lat']) || !is_numeric($p['lng'])) {
                continue;
            }
            $t = $p['t'] ?? null;
            $hasDeviceTime = is_numeric($t) && (float)$t > 0;
            // Accept epoch milliseconds or seconds — a seconds value read as ms lands in 1970.
            $ts = $hasDeviceTime ? (int)((float)$t > 1.0e11 ? (float)$t / 1000 : (float)$t) : $nowTs;

            $acc = $p['acc'] ?? $p['accuracy'] ?? null;
            $id  = isset($p['id']) && is_string($p['id']) && preg_match('/^[0-9a-fA-F-]{36}$/', $p['id']) ? strtolower($p['id']) : null;

            $out[] = [
                'id'              => $id,
                'ts'              => $ts,
                'has_device_time' => $hasDeviceTime,
                'lat'             => (float)$p['lat'],
                'lng'             => (float)$p['lng'],
                'acc'             => is_numeric($acc) && (float)$acc > 0 ? (float)$acc : null,
                'speed'           => isset($p['speed']) && is_numeric($p['speed']) && (float)$p['speed'] >= 0 ? (float)$p['speed'] : null,
                'heading'         => isset($p['heading']) && is_numeric($p['heading']) && (float)$p['heading'] >= 0 ? (int)round((float)$p['heading']) % 360 : null,
                'mock'            => !empty($p['mock']),
                'visit_id'        => isset($p['visit_id']) && (int)$p['visit_id'] > 0 ? (int)$p['visit_id'] : null,
                'tier'            => in_array($p['tier'] ?? null, ['baseline', 'enhanced'], true) ? $p['tier'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{0:int, 1:?int}> $shifts [clockInTs, clockOutTs|null] — null = still open
     * @return string 'ok' | 'bad_coords' | 'future' | 'too_old' | 'outside_shift'
     */
    public static function classify(array $point, int $nowTs, array $shifts): string
    {
        if ($point['lat'] < -90 || $point['lat'] > 90 || $point['lng'] < -180 || $point['lng'] > 180
            || ($point['lat'] == 0.0 && $point['lng'] == 0.0)) {
            return 'bad_coords';
        }
        if ($point['ts'] > $nowTs + self::MAX_FUTURE_SECONDS) {
            return 'future';
        }
        if ($point['ts'] < $nowTs - self::MAX_AGE_SECONDS) {
            return 'too_old';
        }
        foreach ($shifts as [$in, $out]) {
            $end = $out ?? ($nowTs + self::MAX_FUTURE_SECONDS);
            if ($point['ts'] >= $in - self::SHIFT_GRACE_SECONDS && $point['ts'] <= $end + self::SHIFT_GRACE_SECONDS) {
                return 'ok';
            }
        }
        return 'outside_shift';
    }

    /** Oldest-first, dropping fixes closer than MIN_GAP_SECONDS to the one kept before them. */
    public static function thin(array $points): array
    {
        usort($points, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $kept = [];
        $last = null;
        foreach ($points as $p) {
            if ($last !== null && $p['ts'] - $last < self::MIN_GAP_SECONDS) {
                continue;
            }
            $kept[] = $p;
            $last   = $p['ts'];
        }
        return $kept;
    }

    /**
     * What the device should do next. The SERVER owns the work-hours boundary: a
     * client told tracking_allowed=false must stop capturing, which is how an
     * auto clock-out or an admin edit finally reaches a phone in someone's pocket.
     */
    public static function policy(bool $userActive, bool $trackingEnabled, bool $consentOk, bool $clockedIn, ?int $activeVisitId): array
    {
        $reason = null;
        if (!$userActive)          { $reason = 'account_inactive'; }
        elseif (!$trackingEnabled) { $reason = 'tracking_disabled'; }
        elseif (!$consentOk)       { $reason = 'consent_required'; }
        elseif (!$clockedIn)       { $reason = 'not_clocked_in'; }

        $allowed = $reason === null;
        return [
            'tracking_allowed' => $allowed,
            'reason'           => $reason,
            'clocked_in'       => $clockedIn,
            // Enhanced = on a job. Dense, high-accuracy fixes are the proof-of-presence
            // record (salting / snow liability), so this must never back off when stationary.
            'tier'             => !$allowed ? 'off' : ($activeVisitId ? 'enhanced' : 'baseline'),
            'active_visit_id'  => $allowed ? $activeVisitId : null,
            'baseline'         => ['interval_s' => 60, 'distance_m' => 50, 'accuracy' => 'balanced'],
            'enhanced'         => ['interval_s' => 15, 'distance_m' => 8,  'accuracy' => 'best'],
            'status_poll_s'    => 300,
        ];
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /** @return array{active:bool, tracking:bool} */
    public function userFlags(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT is_active, location_tracking_enabled FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['active' => !empty($row['is_active']), 'tracking' => !empty($row['location_tracking_enabled'])];
    }

    /** Clock entries overlapping the replay horizon, as [inTs, outTs|null]. */
    public function shifts(int $userId, int $nowTs): array
    {
        $stmt = $this->db->prepare("
            SELECT UNIX_TIMESTAMP(clock_in) AS t_in, UNIX_TIMESTAMP(clock_out) AS t_out
            FROM time_clock_entries
            WHERE user_id = ? AND clock_in >= FROM_UNIXTIME(?)
            ORDER BY clock_in ASC
        ");
        $stmt->execute([$userId, $nowTs - self::MAX_AGE_SECONDS - 86400]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [(int)$r['t_in'], $r['t_out'] !== null ? (int)$r['t_out'] : null];
        }
        return $out;
    }

    /**
     * Consent is only enforced once an admin turns it on (time_clock_settings
     * tracking_consent_required = 1) — flipping it before the apps ship their
     * consent screen would silently stop all tracking.
     */
    public function consentOk(int $userId): bool
    {
        if (!function_exists('getTimeClockSetting') || getTimeClockSetting('tracking_consent_required', '0') !== '1') {
            return true;
        }
        require_once __DIR__ . '/TrackingConsentService.php';
        return (new TrackingConsentService($this->db))->hasCurrentConsent($userId);
    }

    /**
     * Judge and store a request's fixes.
     *
     * @param callable(int $visitId): bool $mayStampVisit  is the caller crew on this visit?
     * @param callable(float $lat, float $lng): bool $isOffice  home-geofence classification
     * @return array{accepted: array, rejected: array, stored: int, newest: ?array, last_id: int}
     */
    public function ingest(int $userId, array $points, int $nowTs, callable $mayStampVisit, callable $isOffice): array
    {
        $shifts   = $this->shifts($userId, $nowTs);
        $accepted = [];
        $rejected = [];
        $ok       = [];

        foreach ($points as $p) {
            $verdict = self::classify($p, $nowTs, $shifts);
            if ($verdict === 'ok') {
                $ok[] = $p;
                continue;
            }
            // outside_shift is retryable: an offline clock-in may simply not have synced yet.
            $rejected[] = ['id' => $p['id'], 'reason' => $verdict, 'retryable' => $verdict === 'outside_shift'];
        }

        $hasUuid     = $this->hasColumn('crew_location_history', 'point_uuid');
        $visitCache  = [];
        $stored      = 0;
        $lastId      = 0;
        $newest      = null;

        foreach (self::thin($ok) as $p) {
            // Duplicate guard on FIX time (index range scan), not receipt time — a replayed
            // queue must not be throttled away, and a future-dated row must not poison it.
            $gap = $p['has_device_time'] ? self::MIN_GAP_SECONDS : self::LEGACY_MIN_GAP_SECONDS;
            $dup = $this->db->prepare("
                SELECT id FROM crew_location_history
                WHERE crew_id = ? AND timestamp BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)
                LIMIT 1
            ");
            $dup->execute([$userId, $p['ts'] - $gap, $p['ts'] + ($p['has_device_time'] ? $gap : 0)]);
            if ($dup->fetchColumn()) {
                $accepted[] = ['id' => $p['id'], 'duplicate' => true];
                continue;
            }

            // A client-supplied visit_id feeds the customer-facing route proof — never trust it.
            $visitId = $p['visit_id'];
            if ($visitId !== null) {
                if (!array_key_exists($visitId, $visitCache)) {
                    $visitCache[$visitId] = (bool)$mayStampVisit($visitId);
                }
                if (!$visitCache[$visitId]) {
                    $visitId = null;
                }
            }

            $office = $visitId === null && $isOffice($p['lat'], $p['lng']) ? 1 : 0;
            $acc    = $p['acc'] !== null ? (int)round($p['acc']) : null;

            try {
                if ($hasUuid) {
                    $this->db->prepare("
                        INSERT IGNORE INTO crew_location_history
                            (crew_id, latitude, longitude, accuracy_meters, visit_id, is_office, timestamp,
                             point_uuid, received_at, speed_mps, heading_deg, is_mock, tier)
                        VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?, NOW(), ?, ?, ?, ?)
                    ")->execute([$userId, $p['lat'], $p['lng'], $acc, $visitId, $office, $p['ts'],
                                 $p['id'], $p['speed'], $p['heading'], $p['mock'] ? 1 : 0, $p['tier']]);
                } else {
                    $this->db->prepare("
                        INSERT INTO crew_location_history
                            (crew_id, latitude, longitude, accuracy_meters, visit_id, is_office, timestamp)
                        VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
                    ")->execute([$userId, $p['lat'], $p['lng'], $acc, $visitId, $office, $p['ts']]);
                }
                $lastId = (int)$this->db->lastInsertId() ?: $lastId;
                $stored++;
                $accepted[] = ['id' => $p['id']];
                if ($newest === null || $p['ts'] > $newest['ts']) {
                    $newest = $p;
                }
            } catch (Throwable $e) {
                // One bad row must never fail the batch — the client would retry it forever.
                error_log("TrackingIngestService: point rejected for user {$userId}: " . $e->getMessage());
                $rejected[] = ['id' => $p['id'], 'reason' => 'store_failed', 'retryable' => false];
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'stored' => $stored, 'newest' => $newest, 'last_id' => $lastId];
    }

    /** Record what the device says about itself, so a silent phone is visible to the office. */
    public function recordHealth(int $userId, array $device, ?int $newestFixTs): void
    {
        $deviceId = isset($device['id']) && is_string($device['id']) ? substr($device['id'], 0, 64) : '';
        if ($deviceId === '' || !$this->hasTable('device_tracking_health')) {
            return;
        }
        $perm = in_array($device['permission'] ?? null, ['always', 'when_in_use', 'denied'], true) ? $device['permission'] : 'unknown';
        $bool = static fn ($v) => $v === null ? null : (int)(bool)$v;
        try {
            $this->db->prepare("
                INSERT INTO device_tracking_health
                    (user_id, device_id, platform, app_version, location_permission, precise_location,
                     battery_optimization_exempt, low_power_mode, battery_percent, queue_depth, last_point_at, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
                ON DUPLICATE KEY UPDATE
                    platform = VALUES(platform), app_version = VALUES(app_version),
                    location_permission = VALUES(location_permission), precise_location = VALUES(precise_location),
                    battery_optimization_exempt = VALUES(battery_optimization_exempt),
                    low_power_mode = VALUES(low_power_mode), battery_percent = VALUES(battery_percent),
                    queue_depth = VALUES(queue_depth),
                    last_point_at = COALESCE(VALUES(last_point_at), last_point_at),
                    last_seen_at = NOW()
            ")->execute([
                $userId, $deviceId,
                isset($device['platform']) ? substr((string)$device['platform'], 0, 16) : null,
                isset($device['app_version']) ? substr((string)$device['app_version'], 0, 24) : null,
                $perm,
                $bool($device['precise'] ?? null),
                $bool($device['battery_opt_exempt'] ?? null),
                $bool($device['low_power'] ?? null),
                isset($device['battery']) && is_numeric($device['battery']) ? max(0, min(100, (int)$device['battery'])) : null,
                isset($device['queue_depth']) && is_numeric($device['queue_depth']) ? max(0, (int)$device['queue_depth']) : null,
                $newestFixTs,
            ]);
        } catch (Throwable $e) {
            error_log("TrackingIngestService: health upsert failed for user {$userId}: " . $e->getMessage());
        }
    }

    // ── Schema probes (migration 1116 is run by hand) ───────────────────────

    private function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (!isset($this->columnCache[$key])) {
            try {
                // information_schema, not SHOW COLUMNS … LIKE ?: with native (non-emulated)
                // prepares MySQL rejects a placeholder in SHOW, the probe threw, and every
                // fix was silently stored WITHOUT its uuid/tier as if 1116 had never run.
                $stmt = $this->db->prepare("
                    SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1
                ");
                $stmt->execute([$table, $column]);
                $this->columnCache[$key] = (bool)$stmt->fetchColumn();
            } catch (Throwable $e) {
                $this->columnCache[$key] = false;
            }
        }
        return $this->columnCache[$key];
    }

    private function hasTable(string $table): bool
    {
        $key = "{$table}.*";
        if (!isset($this->columnCache[$key])) {
            try {
                $stmt = $this->db->prepare("
                    SELECT 1 FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1
                ");
                $stmt->execute([$table]);
                $this->columnCache[$key] = (bool)$stmt->fetchColumn();
            } catch (Throwable $e) {
                $this->columnCache[$key] = false;
            }
        }
        return $this->columnCache[$key];
    }
}
