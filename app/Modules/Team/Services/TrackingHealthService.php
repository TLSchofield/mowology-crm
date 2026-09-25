<?php
declare(strict_types=1);

/**
 * TrackingHealthService — notices when a clocked-in, tracked employee's phone has
 * gone quiet, and says so.
 *
 * The 2026-09-19 audit's worst property was SILENCE: a swiped-away app, a revoked
 * permission or a dead WebView all looked identical to "crew member is parked" —
 * and old clients re-sent stale positions with fresh timestamps, so a dead phone
 * looked alive. For salting / snow work, a gap discovered weeks later during a
 * slip-and-fall claim cannot be repaired. It has to be caught on the day.
 *
 * Global-namespace, no autoloader: require_once, then `new TrackingHealthService($db)`.
 */
class TrackingHealthService
{
    public const DEFAULT_SILENT_MINUTES   = 15;
    public const DEFAULT_COOLDOWN_MINUTES = 60;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ──────────────────────────────────────────────────────────

    /**
     * Silent = on the clock long enough to have reported, and nothing heard since
     * (or ever, this shift). A fix from BEFORE clock-in says nothing about this shift.
     */
    public static function isSilent(int $clockInTs, ?int $lastFixTs, int $nowTs, int $silentMinutes): bool
    {
        $threshold = max(1, $silentMinutes) * 60;
        if ($nowTs - $clockInTs < $threshold) {
            return false;   // just clocked in — give the phone a chance
        }
        $lastHeard = ($lastFixTs !== null && $lastFixTs >= $clockInTs) ? $lastFixTs : $clockInTs;
        return $nowTs - $lastHeard >= $threshold;
    }

    public static function shouldAlert(?int $lastAlertTs, int $nowTs, int $cooldownMinutes): bool
    {
        return $lastAlertTs === null || $nowTs - $lastAlertTs >= max(1, $cooldownMinutes) * 60;
    }

    /** What the device last said about itself, turned into a likely cause a human can act on. PURE. */
    public static function likelyCause(?array $health): string
    {
        if (!$health) {
            return 'No device report yet — the app may be closed or out of date.';
        }
        $perm = $health['location_permission'] ?? 'unknown';
        if ($perm === 'denied')      { return 'Location access is turned off for the app.'; }
        if (isset($health['precise_location']) && (int)$health['precise_location'] === 0) {
            return 'Precise Location is off.';
        }
        if ($perm === 'when_in_use') { return 'Location is set to "While Using" — the app was probably closed.'; }
        if (isset($health['battery_optimization_exempt']) && (int)$health['battery_optimization_exempt'] === 0) {
            return 'Battery optimisation is likely killing the app in the background.';
        }
        if (!empty($health['low_power_mode'])) { return 'Low Power Mode is on.'; }
        if (isset($health['battery_percent']) && (int)$health['battery_percent'] <= 5) {
            return 'The phone battery was nearly flat.';
        }
        return 'The app may have been closed, or the phone has no signal.';
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /**
     * Everyone on the clock with tracking enabled, with their newest fix this shift.
     * Truck tablets are excluded — they are tracked by Trackimo, not the phone pipeline.
     */
    public function clockedInTrackedUsers(): array
    {
        $stmt = $this->db->query("
            SELECT u.id AS user_id, u.full_name, tce.id AS clock_entry_id,
                   IFNULL(u.device_type, 'personal') AS device_type,
                   UNIX_TIMESTAMP(tce.clock_in) AS clock_in_ts,
                   (SELECT UNIX_TIMESTAMP(MAX(clh.timestamp)) FROM crew_location_history clh
                     WHERE clh.crew_id = u.id AND clh.timestamp >= tce.clock_in) AS last_fix_ts
            FROM time_clock_entries tce
            JOIN users u ON u.id = tce.user_id
            WHERE tce.clock_out IS NULL AND tce.status = 'active'
              AND u.is_active = 1 AND u.location_tracking_enabled = 1
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function latestHealth(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM device_tracking_health WHERE user_id = ? ORDER BY last_seen_at DESC LIMIT 1");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;   // migration 1116 not run yet
        }
    }

    /** Cooldown lives in time_clock_settings so it works before migration 1116 exists. */
    public function lastAlertTs(int $userId): ?int
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM time_clock_settings WHERE setting_key = ?");
        $stmt->execute(["tracking_silent_alert_{$userId}"]);
        $v = $stmt->fetchColumn();
        return $v !== false && is_numeric($v) ? (int)$v : null;
    }

    public function markAlerted(int $userId, int $nowTs): void
    {
        $key = "tracking_silent_alert_{$userId}";
        $upd = $this->db->prepare("UPDATE time_clock_settings SET setting_value = ? WHERE setting_key = ?");
        $upd->execute([(string)$nowTs, $key]);
        if ($upd->rowCount() === 0) {
            $exists = $this->db->prepare("SELECT 1 FROM time_clock_settings WHERE setting_key = ?");
            $exists->execute([$key]);
            if (!$exists->fetchColumn()) {
                $this->db->prepare("INSERT INTO time_clock_settings (setting_key, setting_value) VALUES (?, ?)")
                         ->execute([$key, (string)$nowTs]);
            }
        }
    }

    /**
     * One sweep. Returns the people found silent and whether each was alerted.
     *
     * @param callable(array $row, string $cause, int $minutesSilent): void $notify
     */
    public function sweep(int $nowTs, int $silentMinutes, int $cooldownMinutes, callable $notify): array
    {
        $found = [];
        foreach ($this->clockedInTrackedUsers() as $row) {
            $clockIn = (int)$row['clock_in_ts'];
            $lastFix = $row['last_fix_ts'] !== null ? (int)$row['last_fix_ts'] : null;
            if (!self::isSilent($clockIn, $lastFix, $nowTs, $silentMinutes)) {
                continue;
            }
            $userId  = (int)$row['user_id'];
            $minutes = (int)floor(($nowTs - max($clockIn, $lastFix ?? 0)) / 60);
            $alerted = false;
            if (self::shouldAlert($this->lastAlertTs($userId), $nowTs, $cooldownMinutes)) {
                $notify($row, self::likelyCause($this->latestHealth($userId)), $minutes);
                $this->markAlerted($userId, $nowTs);
                $alerted = true;
            }
            $found[] = ['user_id' => $userId, 'name' => $row['full_name'], 'minutes_silent' => $minutes, 'alerted' => $alerted];
        }
        return $found;
    }
}
