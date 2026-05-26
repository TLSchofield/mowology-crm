<?php
/**
 * One-shot data seed — Tim's home address + geofence.
 *
 * Sets the HR address fields and home_lat/home_lng/home_radius_meters on
 * Tim's user record (email = mowology@icloud.com) so the home-exclusion
 * filter in GeofenceService can suppress idle pings from his home.
 *
 * Idempotent: safe to re-run; UPDATE only touches the matched row.
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db    = getDB();
$email = 'mowology@icloud.com';

$stmt = $db->prepare(
    "UPDATE users
        SET address            = ?,
            city               = ?,
            province           = ?,
            postal_code        = ?,
            home_lat           = ?,
            home_lng           = ?,
            home_radius_meters = ?
      WHERE email = ?"
);
$stmt->execute([
    '2845 W 15th Avenue',
    'Vancouver',
    'BC',
    'V6K 3A1',
    49.2635,
    -123.1565,
    100,
    $email,
]);
$rows = $stmt->rowCount();

$check = $db->prepare(
    "SELECT id, full_name, email, address, city, province, postal_code,
            home_lat, home_lng, home_radius_meters
       FROM users WHERE email = ?"
);
$check->execute([$email]);
$user = $check->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Seed Tim Home Address</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:900px;margin:0 auto}.ok{color:#2D8659;font-weight:bold}.err{color:#c00;font-weight:bold}table{border-collapse:collapse;margin-top:1rem}td{padding:.35rem .75rem;border:1px solid #ccc}</style>
</head><body>
<h1>Seed Tim's Home Address + Geofence</h1>
<?php if (!$user): ?>
  <p class="err">No user found with email <?= htmlspecialchars($email) ?>.</p>
<?php else: ?>
  <p class="ok">UPDATE matched <?= $rows ?> row(s).</p>
  <table>
    <?php foreach ($user as $k => $v): ?>
      <tr><td><?= htmlspecialchars((string)$k) ?></td><td><?= htmlspecialchars((string)($v ?? '—')) ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<p><a href="/crm/team/profile.php?id=<?= (int)($user['id'] ?? 0) ?>">← Team Profile</a></p>
</body></html>
