<?php
/**
 * Route Day Hint API — Day load preview for plan scheduling
 *
 * Returns crew workload summary and proximity data for a given day-of-week.
 * Used by the plan edit modal to surface route intelligence when selecting
 * visit days or assigning crew.
 *
 * GET ?dow=4&prop_lat=49.xxx&prop_lng=-123.xxx[&crew_id=5]
 *   dow      — 0 (Sun) through 6 (Sat)
 *   prop_lat — property latitude
 *   prop_lng — property longitude
 *   crew_id  — optional; filters to a specific crew member
 */
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
session_write_close();

$dow     = isset($_GET['dow']) ? (int)$_GET['dow'] : -1;
$propLat = isset($_GET['prop_lat']) && $_GET['prop_lat'] !== '' ? (float)$_GET['prop_lat'] : null;
$propLng = isset($_GET['prop_lng']) && $_GET['prop_lng'] !== '' ? (float)$_GET['prop_lng'] : null;
$crewId  = isset($_GET['crew_id']) && $_GET['crew_id'] !== '' ? (int)$_GET['crew_id'] : null;

if ($dow < 0 || $dow > 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'dow must be 0–6']);
    exit;
}

try {
    $db = getDB();
    $tz = new DateTimeZone('America/Vancouver');

    // Find nearest future date with this DOW (at least tomorrow)
    $today      = new DateTime('now', $tz);
    $todayDow   = (int)$today->format('w');
    $daysAhead  = ($dow - $todayDow + 7) % 7;
    if ($daysAhead === 0) $daysAhead = 7;
    $targetDate = (clone $today)->modify("+{$daysAhead} days")->format('Y-m-d');
    $dateLabel  = (clone $today)->modify("+{$daysAhead} days")->format('D M j');

    // --- Query stop summary for the target date ---
    $baseSql = "
        SELECT
            COUNT(DISTINCT cs.id)                         AS stop_count,
            COALESCE(SUM(jp.estimated_duration_minutes), 0) AS total_job_min
        FROM calendar_stops cs
        JOIN job_visits jv ON jv.stop_id = cs.id AND jv.status != 'cancelled'
        JOIN job_plans  jp ON jv.plan_id = jp.id
        WHERE cs.stop_date = ?
          AND cs.status   != 'cancelled'
    ";
    $params = [$targetDate];
    if ($crewId !== null) { $baseSql .= ' AND cs.crew_id = ?'; $params[] = $crewId; }

    $stmt = $db->prepare($baseSql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $stopCount   = (int)($row['stop_count']   ?? 0);
    $totalJobMin = (int)($row['total_job_min'] ?? 0);

    // If the exact next date has no stops, widen to first matching DOW in 45-day window
    if ($stopCount === 0) {
        $wideSql = "
            SELECT
                cs.stop_date,
                COUNT(DISTINCT cs.id)                         AS stop_count,
                COALESCE(SUM(jp.estimated_duration_minutes), 0) AS total_job_min
            FROM calendar_stops cs
            JOIN job_visits jv ON jv.stop_id = cs.id AND jv.status != 'cancelled'
            JOIN job_plans  jp ON jv.plan_id = jp.id
            WHERE DAYOFWEEK(cs.stop_date) = ?
              AND cs.stop_date > CURDATE()
              AND cs.stop_date <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)
              AND cs.status != 'cancelled'
        ";
        $wideParams = [$dow + 1]; // MySQL DAYOFWEEK: 1=Sun…7=Sat
        if ($crewId !== null) { $wideSql .= ' AND cs.crew_id = ?'; $wideParams[] = $crewId; }
        $wideSql .= ' GROUP BY cs.stop_date ORDER BY cs.stop_date ASC LIMIT 1';

        $wideStmt = $db->prepare($wideSql);
        $wideStmt->execute($wideParams);
        $wide = $wideStmt->fetch(PDO::FETCH_ASSOC);

        if ($wide) {
            $targetDate  = $wide['stop_date'];
            $stopCount   = (int)$wide['stop_count'];
            $totalJobMin = (int)$wide['total_job_min'];
            $dateLabel   = (new DateTime($targetDate))->format('D M j');
        }
    }

    // --- Nearest-stop proximity via haversine ---
    $nearestKm = null;
    if ($stopCount > 0 && $propLat !== null && $propLng !== null && $propLat != 0.0 && $propLng != 0.0) {
        $proxSql = "
            SELECT p.latitude, p.longitude
            FROM calendar_stops cs
            JOIN properties p ON cs.property_id = p.id
            WHERE cs.stop_date = ?
              AND cs.status   != 'cancelled'
              AND p.latitude  IS NOT NULL AND p.longitude IS NOT NULL
              AND p.latitude  != 0        AND p.longitude != 0
        ";
        $proxParams = [$targetDate];
        if ($crewId !== null) { $proxSql .= ' AND cs.crew_id = ?'; $proxParams[] = $crewId; }

        $proxStmt = $db->prepare($proxSql);
        $proxStmt->execute($proxParams);
        $coords = $proxStmt->fetchAll(PDO::FETCH_ASSOC);

        $minDist = PHP_FLOAT_MAX;
        foreach ($coords as $c) {
            $d = haversineKm($propLat, $propLng, (float)$c['latitude'], (float)$c['longitude']);
            if ($d < $minDist) $minDist = $d;
        }
        if ($minDist < PHP_FLOAT_MAX) {
            $nearestKm = round($minDist, 1);
        }
    }

    // --- Feasibility (mirrors route-engine.js thresholds) ---
    // 8 min estimated drive between each stop; 10% buffer + 15 min fixed
    $workWindow  = 480;
    $estDriveMin = $stopCount > 1 ? ($stopCount - 1) * 8 : 0;
    $totalMin    = $totalJobMin + $estDriveMin + ($estDriveMin * 0.10) + 15;
    $load        = $workWindow > 0 ? $totalMin / $workWindow : 0;

    if ($stopCount === 0) {
        $feasibility = 'empty';
    } elseif ($load <= 0.85) {
        $feasibility = 'green';
    } elseif ($load <= 1.0) {
        $feasibility = 'yellow';
    } else {
        $feasibility = 'red';
    }

    echo json_encode([
        'success'       => true,
        'date'          => $targetDate,
        'date_label'    => $dateLabel,
        'stop_count'    => $stopCount,
        'total_job_min' => $totalJobMin,
        'day_load_pct'  => round($load * 100, 1),
        'feasibility'   => $feasibility,
        'nearest_km'    => $nearestKm,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R    = 6371.0;
    $rad  = M_PI / 180;
    $dLat = ($lat2 - $lat1) * $rad;
    $dLng = ($lng2 - $lng1) * $rad;
    $a    = pow(sin($dLat / 2), 2)
          + cos($lat1 * $rad) * cos($lat2 * $rad) * pow(sin($dLng / 2), 2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
