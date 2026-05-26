<?php
/**
 * GeofenceService — home-exclusion logic for GPS pings.
 *
 * Heartbeat pings that originate from inside a user's home geofence are
 * suppressed UNLESS the user has an active job_visit (status='in_progress').
 * Clock-in events go through TimeclockFunctions::clockIn() which writes its
 * own crew_location_history row directly and is not subject to this filter.
 */
declare(strict_types=1);

class GeofenceService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthR = 6371000.0;
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos($lat1Rad) * cos($lat2Rad) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthR * $c;
    }

    /**
     * @return array{lat:float,lng:float,radius_m:int}|null
     */
    public function getHomeLocation(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT home_lat, home_lng, home_radius_meters
               FROM users
              WHERE id = ?
              LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['home_lat'] === null || $row['home_lng'] === null) {
            return null;
        }

        return [
            'lat'      => (float)$row['home_lat'],
            'lng'      => (float)$row['home_lng'],
            'radius_m' => max(50, (int)($row['home_radius_meters'] ?? 250)),
        ];
    }

    public function hasActiveVisit(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1
               FROM job_visits
              WHERE assigned_crew_id = ?
                AND status = 'in_progress'
              LIMIT 1"
        );
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * True when this ping is an idle heartbeat from inside the user's home
     * geofence and the user has no active visit — i.e. safe to drop.
     */
    public function shouldSuppressHomePing(int $userId, float $lat, float $lng): bool
    {
        $home = $this->getHomeLocation($userId);
        if ($home === null) {
            return false;
        }

        $dist = self::distanceMeters($lat, $lng, $home['lat'], $home['lng']);
        if ($dist > $home['radius_m']) {
            return false;
        }

        return !$this->hasActiveVisit($userId);
    }
}
