<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/freshness.php
 *
 * Mobile Schedule API — Day Freshness Check
 *
 * GET /api/schedule/freshness?start=YYYY-MM-DD&days=7
 * Authorization: Bearer <jwt>
 *
 * Lightweight checksum-per-day endpoint used by the iOS app to decide
 * which days actually need a full /schedule/day fetch during background
 * prefetch. The checksum is computed from each stop's id + updated_at,
 * so it shifts the moment any stop on that day is created, updated,
 * or moved.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "start": "2026-05-23",
 *   "end":   "2026-05-29",
 *   "role":  "user",
 *   "days":  [
 *     { "date": "2026-05-23", "stop_count": 3, "checksum": "a1b2c3..." },
 *     { "date": "2026-05-24", "stop_count": 0, "checksum": "d41d8cd9..." }
 *   ]
 * }
 *
 * Empty days return the MD5 of an empty string ("d41d8cd98f00b204e9800998ecf8427e")
 * so the client can still compare deterministically.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth & config ────────────────────────────────────────────────────────────
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();

// ── Input validation ─────────────────────────────────────────────────────────
$startInput = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$daysInput  = isset($_GET['days'])  ? (int)$_GET['days']           : 7;

if ($startInput === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing start date. Use YYYY-MM-DD.']);
    exit;
}

$start = DateTimeImmutable::createFromFormat('Y-m-d', $startInput);
if (!$start || $start->format('Y-m-d') !== $startInput) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid start date value.']);
    exit;
}

// Clamp days to a safe range
if ($daysInput < 1)  { $daysInput = 1;  }
if ($daysInput > 14) { $daysInput = 14; }

$end      = $start->modify('+' . ($daysInput - 1) . ' days');
$startStr = $start->format('Y-m-d');
$endStr   = $end->format('Y-m-d');

// ── Role-based crew filter (mirrors day.php / week.php) ──────────────────────
$crewFilter = jwtIsAdmin($jwtUser['role']) ? null : (int)$jwtUser['id'];

// ── Fetch stop ids + updated_at for the range ────────────────────────────────
try {
    $db = getDB();

    $sql = "SELECT cs.id, cs.stop_date, cs.updated_at
            FROM calendar_stops cs
            WHERE cs.stop_date BETWEEN ? AND ?";
    $params = [$startStr, $endStr];

    if ($crewFilter !== null) {
        // Same crew predicate as getCalendarStops(): lead crew OR additional crew via junction
        $sql .= " AND (cs.crew_id = ? OR cs.id IN (SELECT stop_id FROM calendar_stop_crew WHERE user_id = ?))";
        $params[] = $crewFilter;
        $params[] = $crewFilter;
    }

    $sql .= " ORDER BY cs.stop_date ASC, cs.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[schedule/freshness] query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load freshness data.']);
    exit;
}

// ── Group rows by date ───────────────────────────────────────────────────────
$byDate = [];
foreach ($rows as $row) {
    $d = (string)$row['stop_date'];
    if (!isset($byDate[$d])) {
        $byDate[$d] = [];
    }
    $byDate[$d][] = (string)$row['id'] . ':' . (string)$row['updated_at'];
}

// ── Build per-day response (always emit one entry per requested day) ─────────
$emptyChecksum = md5('');
$days  = [];
$cursor = $start;
for ($i = 0; $i < $daysInput; $i++) {
    $dateStr = $cursor->format('Y-m-d');
    $entries = $byDate[$dateStr] ?? [];

    $days[] = [
        'date'       => $dateStr,
        'stop_count' => count($entries),
        'checksum'   => $entries === [] ? $emptyChecksum : md5(implode(',', $entries)),
    ];

    $cursor = $cursor->modify('+1 day');
}

// ── Respond ──────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'start'   => $startStr,
    'end'     => $endStr,
    'role'    => $jwtUser['role'],
    'days'    => $days,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
