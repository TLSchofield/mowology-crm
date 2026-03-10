<?php
/**
 * Optimize Route — Nearest-Neighbour Reorder
 * ─────────────────────────────────────────────
 * Reorders calendar_stops for a given date using a greedy
 * nearest-neighbour algorithm on property lat/lng coordinates.
 * Runs server-side in <10ms for a typical 8–12 stop day.
 * No API calls required — uses existing geocoded coordinates.
 *
 * POST JSON: { date: 'YYYY-MM-DD', crew_id?: int, csrf_token: string }
 * Returns:   { success: true, count: int, savings_km: float, message: string }
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    requirePermission('jobs.edit');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['date'])) {
        throw new Exception('Missing required field: date');
    }

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }

    $date       = $input['date'];
    $crewId     = isset($input['crew_id']) ? (int)$input['crew_id'] : null;
    $fromStopId = isset($input['from_stop_id']) ? (int)$input['from_stop_id'] : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    $db = getDB();

    // Fetch stops with property lat/lng — only stops that are geocoded
    $sql = "
        SELECT cs.id        AS stop_id,
               cs.route_order,
               cs.crew_id,
               p.latitude,
               p.longitude,
               p.address    AS property_address
        FROM   calendar_stops cs
        JOIN   properties p ON cs.property_id = p.id
        WHERE  cs.stop_date = ?
          AND  p.latitude   IS NOT NULL
          AND  p.longitude  IS NOT NULL
          AND  p.latitude   != 0
          AND  p.longitude  != 0
    ";
    $params = [$date];

    if ($crewId !== null) {
        $sql .= ' AND cs.crew_id = ?';
        $params[] = $crewId;
    }
    $sql .= ' ORDER BY cs.route_order ASC, cs.id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 2) {
        echo json_encode([
            'success'    => true,
            'count'      => count($rows),
            'savings_km' => 0.0,
            'message'    => 'Nothing to optimise (fewer than 2 geocoded stops)',
        ]);
        exit;
    }

    // Build typed stop array
    $stopData = array_map(fn($s) => [
        'id'      => (int)$s['stop_id'],
        'lat'     => (float)$s['latitude'],
        'lng'     => (float)$s['longitude'],
        'address' => $s['property_address'] ?? '',
    ], $rows);

    // If from_stop_id is provided, lock stops before it and optimise the rest
    // from that stop's position (useful for "optimise from here" mid-route).
    if ($fromStopId !== null) {
        $fromIdx = null;
        foreach ($stopData as $i => $s) {
            if ($s['id'] === $fromStopId) { $fromIdx = $i; break; }
        }

        if ($fromIdx !== null && $fromIdx < count($stopData) - 1) {
            $remaining     = array_slice($stopData, $fromIdx);
            $originalDist  = routeTotalDistM($remaining);
            $optimised     = nearestNeighbourFromStop($remaining);
            $optimisedDist = routeTotalDistM($optimised);
            $savingsKm     = round(max(0.0, ($originalDist - $optimisedDist) / 1000), 2);

            $upd = $db->prepare('UPDATE calendar_stops SET route_order = ? WHERE id = ?');
            foreach ($optimised as $newPos => $s) {
                $upd->execute([$fromIdx + $newPos, $s['id']]);
            }

            echo json_encode([
                'success'    => true,
                'count'      => count($optimised),
                'savings_km' => $savingsKm,
                'message'    => $savingsKm >= 0.1 ? "Route optimised from stop — saved {$savingsKm} km" : 'Route optimised from stop',
            ]);
            exit;
        }
    }

    $originalDist  = routeTotalDistM($stopData);
    $optimised     = nearestNeighbourM($stopData);
    $optimisedDist = routeTotalDistM($optimised);
    $savingsKm     = round(max(0.0, ($originalDist - $optimisedDist) / 1000), 2);

    // Write new route_order values
    $upd = $db->prepare('UPDATE calendar_stops SET route_order = ? WHERE id = ?');
    foreach ($optimised as $newOrder => $s) {
        $upd->execute([$newOrder, $s['id']]);
    }

    $msg = $savingsKm >= 0.1
        ? "Route optimised — saved {$savingsKm} km"
        : 'Route optimised';

    echo json_encode([
        'success'    => true,
        'count'      => count($optimised),
        'savings_km' => $savingsKm,
        'message'    => $msg,
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ── Nearest-neighbour from a fixed start ──────────────────────────────────────
// Fixes stops[0] as the starting point (e.g. "optimise from here") and runs
// nearest-neighbour on the remaining stops from that position.
function nearestNeighbourFromStop(array $stops): array
{
    if (count($stops) <= 1) return $stops;

    $ordered   = [$stops[0]];
    $unvisited = array_values(array_slice($stops, 1));
    $current   = $stops[0];

    while (!empty($unvisited)) {
        $nearestIdx  = 0;
        $nearestDist = PHP_FLOAT_MAX;
        foreach ($unvisited as $i => $s) {
            $d = haversineM($current['lat'], $current['lng'], $s['lat'], $s['lng']);
            if ($d < $nearestDist) { $nearestDist = $d; $nearestIdx = $i; }
        }
        $current   = $unvisited[$nearestIdx];
        $ordered[] = $current;
        array_splice($unvisited, $nearestIdx, 1);
    }

    return $ordered;
}

// ── Nearest-neighbour greedy algorithm ────────────────────────────────────────
// Starting from the stop nearest to the centroid ensures we don't begin from
// an outlier, which typically produces shorter total routes.
function nearestNeighbourM(array $stops): array
{
    if (count($stops) <= 1) return $stops;

    $centLat = array_sum(array_column($stops, 'lat')) / count($stops);
    $centLng = array_sum(array_column($stops, 'lng')) / count($stops);

    $startIdx = 0;
    $minDist  = PHP_FLOAT_MAX;
    foreach ($stops as $i => $s) {
        $d = haversineM($centLat, $centLng, $s['lat'], $s['lng']);
        if ($d < $minDist) { $minDist = $d; $startIdx = $i; }
    }

    $unvisited = array_values($stops);
    $ordered   = [$unvisited[$startIdx]];
    array_splice($unvisited, $startIdx, 1);
    $current   = $ordered[0];

    while (!empty($unvisited)) {
        $nearestIdx  = 0;
        $nearestDist = PHP_FLOAT_MAX;
        foreach ($unvisited as $i => $s) {
            $d = haversineM($current['lat'], $current['lng'], $s['lat'], $s['lng']);
            if ($d < $nearestDist) { $nearestDist = $d; $nearestIdx = $i; }
        }
        $current   = $unvisited[$nearestIdx];
        $ordered[] = $current;
        array_splice($unvisited, $nearestIdx, 1);
    }

    return $ordered;
}

// Sum of inter-stop distances (metres) for a given stop sequence
function routeTotalDistM(array $stops): float
{
    $total = 0.0;
    $n     = count($stops);
    for ($i = 0; $i < $n - 1; $i++) {
        $total += haversineM(
            $stops[$i]['lat'], $stops[$i]['lng'],
            $stops[$i + 1]['lat'], $stops[$i + 1]['lng']
        );
    }
    return $total;
}

// Haversine distance in metres between two lat/lng points
function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R    = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1.0 - $a));
}
