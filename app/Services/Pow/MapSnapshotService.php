<?php
/**
 * Proof of Work — Map Snapshot Service
 * ──────────────────────────────────────
 * Generates and caches a static map image showing the GPS route walked
 * during a visit. Uses the Google Static Maps API.
 *
 * Features:
 * - Douglas-Peucker polyline simplification (keeps URL under 8KB limit)
 * - Google encoded polyline format
 * - Start/end markers
 * - Cache by visit + point-count hash; reuses existing snapshot if unchanged
 * - Stores PNG on server under /uploads/pow/maps/
 */
declare(strict_types=1);

class MapSnapshotService
{
    /** Max waypoints after simplification to keep URL length under 8KB */
    private const MAX_POINTS = 150;

    /** Accuracy threshold: ignore points worse than this (meters) */
    private const ACCURACY_THRESHOLD = 50.0;

    /** Static Maps API URL */
    private const MAPS_URL = 'https://maps.googleapis.com/maps/api/staticmap';

    /** Image dimensions (pixels) */
    private const MAP_W = 800;
    private const MAP_H = 500;

    /** Storage directory relative to PUBLIC_ROOT */
    private const STORAGE_DIR = '/uploads/pow/maps/';

    private string $apiKey;
    private string $storageRoot;
    private string $storageDir;

    public function __construct(string $apiKey, string $publicRoot)
    {
        $this->apiKey      = $apiKey;
        $this->storageRoot = rtrim($publicRoot, '/');
        $this->storageDir  = $this->storageRoot . self::STORAGE_DIR;

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Generate (or retrieve cached) map snapshot for a visit.
     *
     * @param int   $visitId
     * @param array $points  Rows from visit_gps_points: {lat, lng, accuracy_m, ts}
     * @return string|null   Relative URL path (/uploads/pow/maps/visit_42.png) or null on failure
     */
    public function getOrGenerate(int $visitId, array $points): ?string
    {
        // Filter by accuracy
        $filtered = array_values(array_filter($points, function($p) {
            return ($p['accuracy_m'] === null || (float)$p['accuracy_m'] <= self::ACCURACY_THRESHOLD);
        }));

        if (count($filtered) < 2) {
            // Not enough points to draw a route
            return null;
        }

        $hash     = $this->computeHash($filtered);
        $filename = 'visit_' . $visitId . '_' . substr($hash, 0, 12) . '.png';
        $filepath = $this->storageDir . $filename;
        $webPath  = self::STORAGE_DIR . $filename;

        if (file_exists($filepath)) {
            return $webPath; // Cache hit
        }

        // Simplify polyline to stay within URL limits
        $simplified = $this->douglasPeucker($filtered, 0.00003); // ~3m epsilon
        if (count($simplified) > self::MAX_POINTS) {
            $simplified = $this->nthPoint($simplified, self::MAX_POINTS);
        }

        $imageData = $this->fetchStaticMap($simplified);
        if ($imageData === null) {
            return null;
        }

        file_put_contents($filepath, $imageData);

        // Clean up old snapshots for this visit
        $this->pruneOldSnapshots($visitId, $filename);

        return $webPath;
    }

    /**
     * Return visit stats computed from GPS points.
     * @param array $points Rows from visit_gps_points
     * @return array {distance_m, points_total, points_used, duration_seconds}
     */
    public function computeStats(array $points): array
    {
        $filtered = array_values(array_filter($points, function($p) {
            return ($p['accuracy_m'] === null || (float)$p['accuracy_m'] <= self::ACCURACY_THRESHOLD);
        }));

        $distanceM = 0.0;
        for ($i = 1, $n = count($filtered); $i < $n; $i++) {
            $distanceM += $this->haversine(
                (float)$filtered[$i-1]['lat'], (float)$filtered[$i-1]['lng'],
                (float)$filtered[$i]['lat'],   (float)$filtered[$i]['lng']
            );
        }

        $durationSeconds = 0;
        if (count($filtered) >= 2) {
            $first = strtotime($filtered[0]['ts']);
            $last  = strtotime($filtered[count($filtered) - 1]['ts']);
            $durationSeconds = max(0, $last - $first);
        }

        return [
            'distance_m'       => (int)round($distanceM),
            'points_total'     => count($points),
            'points_used'      => count($filtered),
            'duration_seconds' => $durationSeconds,
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Fetch the static map image from Google Maps API.
     * Returns binary PNG data or null on failure.
     */
    private function fetchStaticMap(array $points): ?string
    {
        if (empty($this->apiKey)) {
            error_log('[MapSnapshot] Google Maps API key not configured');
            return null;
        }

        $encoded  = $this->encodePolyline($points);
        $startPt  = $points[0];
        $endPt    = $points[count($points) - 1];

        $params = http_build_query([
            'size'    => self::MAP_W . 'x' . self::MAP_H,
            'maptype' => 'satellite',
            'path'    => 'color:0x2D8659FF|weight:3|enc:' . $encoded,
            'markers' => 'color:green|label:S|' . $startPt['lat'] . ',' . $startPt['lng'],
            'key'     => $this->apiKey,
            'scale'   => '2',
        ]);

        // Second marker (end) appended separately — http_build_query can't duplicate keys
        $endMarker = '&markers=' . urlencode('color:red|label:E|' . $endPt['lat'] . ',' . $endPt['lng']);

        $url = self::MAPS_URL . '?' . $params . $endMarker;

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => 'User-Agent: Mowology-CRM/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            error_log('[MapSnapshot] file_get_contents failed for visit map');
            return null;
        }

        // Check for API error response (HTML/JSON instead of PNG)
        if (substr($data, 1, 3) !== 'PNG' && strpos($data, '<?xml') !== false) {
            error_log('[MapSnapshot] API returned error XML: ' . substr($data, 0, 500));
            return null;
        }

        return $data;
    }

    /**
     * Google Maps Encoded Polyline Algorithm.
     */
    private function encodePolyline(array $points): string
    {
        $encoded  = '';
        $prevLat  = 0;
        $prevLng  = 0;

        foreach ($points as $p) {
            $lat = (int)round((float)$p['lat'] * 1e5);
            $lng = (int)round((float)$p['lng'] * 1e5);
            $encoded .= $this->encodeSignedInt($lat - $prevLat);
            $encoded .= $this->encodeSignedInt($lng - $prevLng);
            $prevLat  = $lat;
            $prevLng  = $lng;
        }

        return $encoded;
    }

    private function encodeSignedInt(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunks = '';
        while ($value >= 0x20) {
            $chunks .= chr(($value & 0x1f) | 0x20 | 0x3f);
            $value >>= 5;
        }
        $chunks .= chr($value + 0x3f);
        return $chunks;
    }

    /**
     * Douglas-Peucker polyline simplification.
     * @param array $points Array of {lat, lng, ...}
     * @param float $epsilon Tolerance in decimal degrees
     * @return array Simplified point array
     */
    private function douglasPeucker(array $points, float $epsilon): array
    {
        $n = count($points);
        if ($n <= 2) return $points;

        $maxDist  = 0.0;
        $maxIndex = 0;

        $first = $points[0];
        $last  = $points[$n - 1];

        for ($i = 1; $i < $n - 1; $i++) {
            $dist = $this->perpendicularDistance($points[$i], $first, $last);
            if ($dist > $maxDist) {
                $maxDist  = $dist;
                $maxIndex = $i;
            }
        }

        if ($maxDist > $epsilon) {
            $left  = $this->douglasPeucker(array_slice($points, 0, $maxIndex + 1), $epsilon);
            $right = $this->douglasPeucker(array_slice($points, $maxIndex), $epsilon);
            return array_merge(array_slice($left, 0, -1), $right);
        }

        return [$first, $last];
    }

    private function perpendicularDistance(array $point, array $lineStart, array $lineEnd): float
    {
        $dx = (float)$lineEnd['lng'] - (float)$lineStart['lng'];
        $dy = (float)$lineEnd['lat'] - (float)$lineStart['lat'];

        if ($dx === 0.0 && $dy === 0.0) {
            // Degenerate segment
            return sqrt(
                (((float)$point['lat'] - (float)$lineStart['lat']) ** 2) +
                (((float)$point['lng'] - (float)$lineStart['lng']) ** 2)
            );
        }

        $t = ((((float)$point['lat'] - (float)$lineStart['lat']) * $dy) +
              (((float)$point['lng'] - (float)$lineStart['lng']) * $dx))
             / ($dx * $dx + $dy * $dy);
        $t = max(0.0, min(1.0, $t));

        $nearLat = (float)$lineStart['lat'] + $t * $dy;
        $nearLng = (float)$lineStart['lng'] + $t * $dx;

        return sqrt(
            (((float)$point['lat'] - $nearLat) ** 2) +
            (((float)$point['lng'] - $nearLng) ** 2)
        );
    }

    /** Sample every Nth point to get at most $maxCount points */
    private function nthPoint(array $points, int $maxCount): array
    {
        $n    = count($points);
        $step = max(1, (int)ceil($n / $maxCount));
        $out  = [];
        for ($i = 0; $i < $n; $i += $step) {
            $out[] = $points[$i];
        }
        // Always include last
        if (end($out) !== $points[$n - 1]) {
            $out[] = $points[$n - 1];
        }
        return $out;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 +
                cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function computeHash(array $points): string
    {
        $data = count($points) . '|';
        if (!empty($points)) {
            $first = $points[0];
            $last  = $points[count($points) - 1];
            $data .= $first['lat'] . $first['lng'] . $last['lat'] . $last['lng'];
        }
        return md5($data);
    }

    private function pruneOldSnapshots(int $visitId, string $keepFile): void
    {
        $prefix = 'visit_' . $visitId . '_';
        foreach (glob($this->storageDir . $prefix . '*.png') ?: [] as $file) {
            if (basename($file) !== $keepFile) {
                @unlink($file);
            }
        }
    }
}
