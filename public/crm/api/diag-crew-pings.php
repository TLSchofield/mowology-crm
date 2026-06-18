<?php
/**
 * TEMPORARY read-only diagnostic — crew GPS ping cadence analysis.
 *
 * Purpose: determine whether a crew member's location pings are coming from the
 * native background plugin (movement-based, irregular cadence) or the browser
 * foreground fallback (near-perfect 30s cadence, dies when screen locks).
 *
 * Admin-only. No writes. Safe to delete after use.
 *   /crm/api/diag-crew-pings.php?name=nigel&hours=48
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!function_exists('isAdmin') || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

try {
    $db = getDB();

    $name  = isset($_GET['name']) ? trim((string)$_GET['name']) : 'nigel';
    $hours = isset($_GET['hours']) ? max(1, min(168, (int)$_GET['hours'])) : 48;

    // Resolve crew member(s) by name.
    $u = $db->prepare(
        "SELECT id, full_name, first_name, last_name, role,
                IFNULL(device_type,'personal') AS device_type,
                COALESCE(location_tracking_enabled,0) AS tracking_on
         FROM users
         WHERE full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?
         ORDER BY id LIMIT 5"
    );
    $like = '%' . $name . '%';
    $u->execute([$like, $like, $like]);
    $users = $u->fetchAll(PDO::FETCH_ASSOC);

    if (!$users) {
        echo json_encode(['error' => "No user matching '$name'"]);
        exit;
    }

    $out = ['query' => ['name' => $name, 'hours' => $hours], 'crew' => []];

    foreach ($users as $usr) {
        $cid = (int)$usr['id'];

        $p = $db->prepare(
            "SELECT timestamp, accuracy_meters
             FROM crew_location_history
             WHERE crew_id = ? AND timestamp >= (NOW() - INTERVAL ? HOUR)
             ORDER BY timestamp ASC"
        );
        $p->execute([$cid, $hours]);
        $rows = $p->fetchAll(PDO::FETCH_ASSOC);

        $n = count($rows);
        $intervals = [];          // seconds between consecutive pings
        $perHour   = [];          // 'YYYY-MM-DD HH' => count
        $accs      = [];
        $prev = null;
        foreach ($rows as $r) {
            $ts = strtotime($r['timestamp']);
            $hr = date('Y-m-d H', $ts);
            $perHour[$hr] = ($perHour[$hr] ?? 0) + 1;
            if ($r['accuracy_meters'] !== null) $accs[] = (int)$r['accuracy_meters'];
            if ($prev !== null) $intervals[] = $ts - $prev;
            $prev = $ts;
        }

        // Bucket inter-ping intervals. A big spike at the 20-40s bucket means the
        // browser foreground pinger (SEND_INTERVAL = 30s). Irregular spread =
        // native movement-based plugin.
        $buckets = ['0-10s'=>0, '10-20s'=>0, '20-40s'=>0, '40-90s'=>0, '90s-5m'=>0, '5-30m'=>0, '>30m'=>0];
        $maxGap = 0;
        foreach ($intervals as $iv) {
            if ($iv > $maxGap) $maxGap = $iv;
            if     ($iv <= 10)  $buckets['0-10s']++;
            elseif ($iv <= 20)  $buckets['10-20s']++;
            elseif ($iv <= 40)  $buckets['20-40s']++;
            elseif ($iv <= 90)  $buckets['40-90s']++;
            elseif ($iv <= 300) $buckets['90s-5m']++;
            elseif ($iv <= 1800)$buckets['5-30m']++;
            else                $buckets['>30m']++;
        }

        $ivCount = count($intervals);
        $pct30 = $ivCount ? round(100 * $buckets['20-40s'] / $ivCount, 1) : 0;

        // Heuristic verdict.
        $verdict = 'unknown';
        if ($n === 0) {
            $verdict = 'NO PINGS in window';
        } elseif ($pct30 >= 55) {
            $verdict = 'LIKELY BROWSER foreground pinger (30s cadence dominates) — native background NOT working';
        } elseif ($buckets['90s-5m'] + $buckets['5-30m'] + $buckets['40-90s'] > $buckets['20-40s']) {
            $verdict = 'LIKELY NATIVE background plugin (movement-based cadence)';
        } else {
            $verdict = 'MIXED / inconclusive';
        }

        ksort($perHour);

        $out['crew'][] = [
            'user'        => [
                'id' => $cid, 'name' => $usr['full_name'] ?: trim($usr['first_name'].' '.$usr['last_name']),
                'role' => $usr['role'], 'device_type' => $usr['device_type'],
                'tracking_enabled' => (int)$usr['tracking_on'],
            ],
            'ping_count'  => $n,
            'first_ping'  => $n ? $rows[0]['timestamp'] : null,
            'last_ping'   => $n ? $rows[$n-1]['timestamp'] : null,
            'interval_buckets' => $buckets,
            'pct_at_30s_cadence' => $pct30,
            'max_gap_seconds' => $maxGap,
            'max_gap_human'  => $maxGap ? round($maxGap/60, 1).' min' : '—',
            'accuracy_m' => $accs ? ['min'=>min($accs), 'max'=>max($accs), 'avg'=>round(array_sum($accs)/count($accs))] : null,
            'pings_per_hour' => $perHour,
            'VERDICT' => $verdict,
        ];
    }

    echo json_encode($out, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'diag failed', 'detail' => $e->getMessage()]);
}
