<?php
/**
 * TruckLocationService — persistence layer for vehicle GPS pings.
 *
 * Owns:
 *   - INSERT into vehicle_location_pings (with dedup on device_id + recorded_at)
 *   - A small JSON cache file with the most recent ping (read on every map poll)
 *   - DB read for the trail (on-demand when the user toggles "show trail")
 *
 * The cache file lets /crm/api/truck-location.php answer in ~1ms without
 * touching the database. The cache is overwritten on every save; if it
 * ever goes missing or stale the API endpoint falls back to a DB query.
 *
 * Cache file format (single device — extend to keyed-by-device when fleet grows):
 *   { "device_id": "...", "lat": ..., "lng": ..., "speed_kph": ..., ... }
 *
 * Not responsible for:
 *   - Calling the Trackimo API (TrackimoService)
 *   - Deciding when to poll (the cron does that)
 */
declare(strict_types=1);

class TruckLocationService
{
    private PDO $db;
    private string $cachePath;

    public function __construct(PDO $db, ?string $cachePath = null)
    {
        $this->db = $db;
        if ($cachePath === null) {
            $base = defined('STORAGE_ROOT') ? STORAGE_ROOT : sys_get_temp_dir();
            $cachePath = $base . '/cache/trackimo_last_position.json';
        }
        $this->cachePath = $cachePath;
    }

    /**
     * Persist a ping. Skips the INSERT (returns 0) when (device_id, recorded_at)
     * already exists — Trackimo will return the same row repeatedly when the
     * truck is parked, and we don't want duplicate ticks in the trail.
     *
     * Cache file is always refreshed, even on a duplicate insert, so the
     * "age" timestamp on the map stays current.
     *
     * Returns the inserted id, or 0 on duplicate.
     */
    public function savePing(string $deviceId, array $ping, string $source = 'poll'): int
    {
        $this->assertPingShape($ping);

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vehicle_location_pings
                    (device_id, lat, lng, speed_kph, heading, battery_pct, source, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $deviceId,
                $ping['lat'],
                $ping['lng'],
                $ping['speed_kph'] ?? null,
                $ping['heading'] ?? null,
                $ping['battery_pct'] ?? null,
                $source,
                $ping['recorded_at'],
            ]);
            $id = (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation (our uq_device_recorded)
            if ($e->getCode() === '23000') {
                $id = 0;
            } else {
                throw $e;
            }
        }

        $this->writeCacheFile(array_merge($ping, ['device_id' => $deviceId, 'source' => $source]));
        return $id;
    }

    /**
     * Read the cache file. Falls back to a DB query if the file is missing
     * or doesn't match the requested device. Returns null if no ping exists.
     */
    public function getLastKnownPosition(string $deviceId): ?array
    {
        if (is_file($this->cachePath)) {
            $raw = @file_get_contents($this->cachePath);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (is_array($cached) && ($cached['device_id'] ?? null) === $deviceId) {
                    return $this->withAge($cached);
                }
            }
        }

        $stmt = $this->db->prepare(
            "SELECT device_id, lat, lng, speed_kph, heading, battery_pct, source, recorded_at
             FROM vehicle_location_pings
             WHERE device_id = ?
             ORDER BY recorded_at DESC
             LIMIT 1"
        );
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return $this->withAge($row);
    }

    /**
     * Return all pings for a device on a given date, ordered chronologically.
     * Date format: 'Y-m-d'. Used by the trail toggle on the map.
     */
    public function getTrailForDate(string $deviceId, string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT lat, lng, speed_kph, heading, recorded_at
             FROM vehicle_location_pings
             WHERE device_id = ?
               AND recorded_at >= ?
               AND recorded_at <  ?
             ORDER BY recorded_at ASC"
        );
        $stmt->execute([$deviceId, $date . ' 00:00:00', $date . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize numeric types for clean JSON output.
        foreach ($rows as &$r) {
            $r['lat'] = (float)$r['lat'];
            $r['lng'] = (float)$r['lng'];
            if ($r['speed_kph'] !== null) $r['speed_kph'] = (float)$r['speed_kph'];
            if ($r['heading']   !== null) $r['heading']   = (int)$r['heading'];
        }
        return $rows;
    }

    /**
     * Delete pings older than N days. Run from a periodic cleanup cron when
     * the table grows — not wired up yet.
     */
    public function purgeOlderThan(int $days): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM vehicle_location_pings WHERE recorded_at < (NOW() - INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    // ──────────────────────────────────────────────────────────────────────

    private function writeCacheFile(array $payload): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $this->cachePath . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload), LOCK_EX) !== false) {
            @rename($tmp, $this->cachePath);
        }
    }

    private function withAge(array $row): array
    {
        $ts = strtotime((string)($row['recorded_at'] ?? 'now')) ?: time();
        $row['age_seconds'] = max(0, time() - $ts);
        $row['lat'] = isset($row['lat']) ? (float)$row['lat'] : null;
        $row['lng'] = isset($row['lng']) ? (float)$row['lng'] : null;
        return $row;
    }

    private function assertPingShape(array $ping): void
    {
        foreach (['lat', 'lng', 'recorded_at'] as $required) {
            if (!array_key_exists($required, $ping) || $ping[$required] === null || $ping[$required] === '') {
                throw new InvalidArgumentException("Ping missing required field: {$required}");
            }
        }
    }
}
