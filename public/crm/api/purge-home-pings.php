<?php
/**
 * One-shot maintenance — purge idle home-zone GPS pings.
 *
 * Removes crew_location_history rows that fall inside a user's home geofence
 * and are NOT tied to a job (visit_id IS NULL) — i.e. the idle heartbeats that
 * pollute the crew-map trail. The matching ping-suppression filter
 * (GeofenceService) prevents new ones going forward; this clears the backlog.
 *
 * SAFE BY DEFAULT:
 *   - No ?confirm=1  → DRY RUN. Reports per-user / per-date counts only.
 *   - ?confirm=1     → backs up every matched row into
 *                      crew_location_history_home_purge_bak, then DELETEs.
 *
 * Never touches:
 *   - time_clock_entries (clock-in/out events live there, not here)
 *   - pings with a visit_id (a job started from home keeps its trail)
 *
 * Restore (if ever needed):
 *   INSERT INTO crew_location_history (crew_id,latitude,longitude,accuracy_meters,visit_id,timestamp)
 *   SELECT crew_id,latitude,longitude,accuracy_meters,visit_id,timestamp
 *   FROM crew_location_history_home_purge_bak;
 *
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');
require_once APP_ROOT . '/Modules/Team/Services/GeofenceService.php';

$db      = getDB();
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$log     = [];

// ── 1. Users with a home geofence set ───────────────────────────────────────
$users = $db->query(
    "SELECT id, full_name, home_lat, home_lng, home_radius_meters
       FROM users
      WHERE home_lat IS NOT NULL AND home_lng IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Find matching ping ids per user (bounding box prefilter + Haversine) ──
$matchByUser = [];   // userId => ['name'=>, 'ids'=>[], 'byDate'=>[date=>count]]
$allIds      = [];

foreach ($users as $u) {
    $uid    = (int)$u['id'];
    $hLat   = (float)$u['home_lat'];
    $hLng   = (float)$u['home_lng'];
    $radius = max(50, (int)($u['home_radius_meters'] ?? 250));

    // Bounding box a bit larger than the radius so the precise filter never clips.
    $latPad = ($radius + 30) / 111000.0;
    $lngPad = ($radius + 30) / (111000.0 * max(0.1, cos(deg2rad($hLat))));

    $stmt = $db->prepare(
        "SELECT id, latitude, longitude, timestamp
           FROM crew_location_history
          WHERE crew_id = ?
            AND visit_id IS NULL
            AND latitude  BETWEEN ? AND ?
            AND longitude BETWEEN ? AND ?"
    );
    $stmt->execute([$uid, $hLat - $latPad, $hLat + $latPad, $hLng - $lngPad, $hLng + $lngPad]);

    $ids = [];
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dist = GeofenceService::distanceMeters((float)$row['latitude'], (float)$row['longitude'], $hLat, $hLng);
        if ($dist <= $radius) {
            $ids[] = (int)$row['id'];
            $d = substr((string)$row['timestamp'], 0, 10);
            $byDate[$d] = ($byDate[$d] ?? 0) + 1;
        }
    }

    if ($ids) {
        krsort($byDate);
        $matchByUser[$uid] = ['name' => $u['full_name'], 'radius' => $radius, 'ids' => $ids, 'byDate' => $byDate];
        $allIds = array_merge($allIds, $ids);
    }
}

$totalMatched = count($allIds);
$deleted = 0;

// ── 3. Execute (only when confirmed) ─────────────────────────────────────────
if ($confirm && $totalMatched > 0) {
    // Backup table (DDL — must run OUTSIDE any transaction)
    $db->exec(
        "CREATE TABLE IF NOT EXISTS crew_location_history_home_purge_bak (
            bak_id          INT AUTO_INCREMENT PRIMARY KEY,
            orig_id         INT NOT NULL,
            crew_id         INT NOT NULL,
            latitude        DECIMAL(10,7) NOT NULL,
            longitude       DECIMAL(10,7) NOT NULL,
            accuracy_meters INT NULL,
            visit_id        INT NULL,
            timestamp       DATETIME NULL,
            purged_at       DATETIME NOT NULL,
            INDEX idx_orig (orig_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    foreach (array_chunk($allIds, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));

        $db->beginTransaction();
        try {
            // Back up first, then delete the exact same ids.
            $db->prepare(
                "INSERT INTO crew_location_history_home_purge_bak
                    (orig_id, crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp, purged_at)
                 SELECT id, crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp, NOW()
                   FROM crew_location_history
                  WHERE id IN ($ph)"
            )->execute($chunk);

            $del = $db->prepare("DELETE FROM crew_location_history WHERE id IN ($ph)");
            $del->execute($chunk);
            $deleted += $del->rowCount();

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $log[] = '❌ Batch failed (rolled back): ' . $e->getMessage();
            break;
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Purge Home-Zone Pings</title>
<style>
 body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:900px;margin:0 auto;color:#1A5F4A}
 h1{color:#0D3B2E}.ok{color:#2D8659;font-weight:bold}.err{color:#c00;font-weight:bold}.warn{color:#e85d04}
 table{border-collapse:collapse;margin:.5rem 0 1.5rem;width:100%}td,th{padding:.35rem .75rem;border:1px solid #ccc;text-align:left}
 .banner{padding:1rem;border-radius:8px;margin-bottom:1.5rem;font-weight:bold}
 .banner.dry{background:#E8F3F0;border:1px solid #2D8659}.banner.done{background:#d4edda;border:1px solid #2D8659}
 .cta{display:inline-block;margin-top:1rem;padding:.6rem 1.2rem;background:#c00;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold}
</style></head><body>
<h1>Purge Home-Zone GPS Pings</h1>

<?php if ($totalMatched === 0): ?>
  <div class="banner dry">
    No matching pings found.
    <?php if (empty($users)): ?><br>(No users have a home geofence set — run <code>seed-tim-home-address.php</code> first.)<?php endif; ?>
  </div>
<?php elseif (!$confirm): ?>
  <div class="banner dry">DRY RUN — nothing deleted. <?= $totalMatched ?> idle home-zone ping(s) would be purged (visit_id IS NULL only).</div>
  <?php foreach ($matchByUser as $uid => $m): ?>
    <h3><?= htmlspecialchars((string)$m['name']) ?> (id <?= $uid ?>) — <?= count($m['ids']) ?> pings, radius <?= $m['radius'] ?>m</h3>
    <table><tr><th>Date</th><th>Pings</th></tr>
      <?php foreach ($m['byDate'] as $d => $c): ?><tr><td><?= htmlspecialchars($d) ?></td><td><?= $c ?></td></tr><?php endforeach; ?>
    </table>
  <?php endforeach; ?>
  <a class="cta" href="?confirm=1">⚠ Purge these <?= $totalMatched ?> pings now (backed up first)</a>
<?php else: ?>
  <div class="banner done">✅ Purged <?= $deleted ?> ping(s). Backed up to <code>crew_location_history_home_purge_bak</code> (recoverable).</div>
  <?php foreach ($log as $line): ?><p class="err"><?= htmlspecialchars($line) ?></p><?php endforeach; ?>
  <?php foreach ($matchByUser as $uid => $m): ?>
    <p><strong><?= htmlspecialchars((string)$m['name']) ?></strong>: <?= count($m['ids']) ?> pings removed.</p>
  <?php endforeach; ?>
<?php endif; ?>

<p style="margin-top:2rem"><a href="/crm/timeclock/crew-map.php">← Crew Map</a></p>
</body></html>
