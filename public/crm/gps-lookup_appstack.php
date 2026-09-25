<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'GPS Lookup';
$activePage = 'map';

$db = getDB();

$searchName    = trim($_GET['name'] ?? 'Ambrosi Verti');
$radiusMetres  = max(50, min(5000, (int)($_GET['radius'] ?? 500)));

$properties      = [];
$crewPings       = [];
$visitPoints     = [];
$error           = '';

if ($searchName !== '') {
    $like = '%' . $searchName . '%';
    // Split into two parts for first/last name search
    $parts = explode(' ', $searchName, 2);
    $first = '%' . ($parts[0] ?? $searchName) . '%';
    $last  = '%' . ($parts[1] ?? $searchName) . '%';

    $stmt = $db->prepare("
        SELECT p.id, p.address, p.city, p.province, p.latitude, p.longitude,
               c.full_name AS contact_name, c.id AS contact_id
        FROM contacts c
        JOIN properties p ON p.site_contact_id = c.id
        WHERE c.full_name LIKE ?
           OR c.first_name LIKE ?
           OR c.last_name  LIKE ?
           OR p.address    LIKE ?
        LIMIT 10
    ");
    $stmt->execute([$like, $first, $last, $like]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($properties as $prop) {
        if ($prop['latitude'] === null || $prop['longitude'] === null) {
            continue;
        }

        $lat = (float)$prop['latitude'];
        $lng = (float)$prop['longitude'];

        // crew_location_history proximity query
        $stmt2 = $db->prepare("
            SELECT
                u.full_name AS crew_name,
                clh.latitude, clh.longitude,
                clh.timestamp,
                clh.accuracy_meters,
                ROUND(6371000 * acos(GREATEST(-1, LEAST(1,
                    cos(radians(:lat)) * cos(radians(clh.latitude)) *
                    cos(radians(clh.longitude) - radians(:lng)) +
                    sin(radians(:lat)) * sin(radians(clh.latitude))
                )))) AS distance_m
            FROM crew_location_history clh
            JOIN users u ON u.id = clh.crew_id
            HAVING distance_m <= :radius
            ORDER BY clh.timestamp DESC
            LIMIT 300
        ");
        $stmt2->execute([':lat' => $lat, ':lng' => $lng, ':radius' => $radiusMetres]);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row['property_address'] = $prop['address'] . ', ' . $prop['city'];
            $row['source'] = 'crew_ping';
            $crewPings[] = $row;
        }

        // visit_gps_points proximity query
        $stmt3 = $db->prepare("
            SELECT
                u.full_name AS crew_name,
                vgp.lat AS latitude,
                vgp.lng AS longitude,
                vgp.ts AS timestamp,
                vgp.accuracy_m AS accuracy_meters,
                vgp.visit_id,
                ROUND(6371000 * acos(GREATEST(-1, LEAST(1,
                    cos(radians(:lat)) * cos(radians(vgp.lat)) *
                    cos(radians(vgp.lng) - radians(:lng)) +
                    sin(radians(:lat)) * sin(radians(vgp.lat))
                )))) AS distance_m
            FROM visit_gps_points vgp
            JOIN job_visits jv ON jv.id = vgp.visit_id
            JOIN users u ON u.id = jv.assigned_crew_id
            HAVING distance_m <= :radius
            ORDER BY vgp.ts DESC
            LIMIT 300
        ");
        $stmt3->execute([':lat' => $lat, ':lng' => $lng, ':radius' => $radiusMetres]);
        $rows3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows3 as $row) {
            $row['property_address'] = $prop['address'] . ', ' . $prop['city'];
            $row['source'] = 'visit_point';
            $visitPoints[] = $row;
        }
    }
}

// Combine + sort all results by timestamp desc
$allResults = array_merge($crewPings, $visitPoints);
usort($allResults, function($a, $b) {
    return strcmp($b['timestamp'], $a['timestamp']);
});

// Unique crew names across all results
$uniqueCrew = array_unique(array_column($allResults, 'crew_name'));
sort($uniqueCrew);

$dateRange = '';
if ($allResults) {
    $timestamps = array_column($allResults, 'timestamp');
    $dateRange = date('M j, Y', strtotime(min($timestamps))) . ' – ' . date('M j, Y', strtotime(max($timestamps)));
}
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i data-feather="map-pin" class="mr-2"></i> GPS Proximity Lookup
                </h5>
            </div>
            <div class="card-body">
                <form method="get" class="form-inline">
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Contact / Address</label>
                        <input type="text" name="name" class="form-control"
                               value="<?= htmlspecialchars($searchName) ?>"
                               placeholder="e.g. Ambrosi Verti" style="width:240px">
                    </div>
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Radius (m)</label>
                        <input type="number" name="radius" class="form-control" style="width:100px"
                               value="<?= $radiusMetres ?>" min="50" max="5000" step="50">
                    </div>
                    <button type="submit" class="btn btn-primary mb-2">Search</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($searchName !== ''): ?>

    <!-- Properties found -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Matched Properties (<?= count($properties) ?>)</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($properties)): ?>
                        <div class="p-3 text-muted">No contacts or properties found matching "<?= htmlspecialchars($searchName) ?>".</div>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Contact</th><th>Address</th><th>City</th><th>Coordinates</th></tr></thead>
                            <tbody>
                            <?php foreach ($properties as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['contact_name']) ?></td>
                                    <td><?= htmlspecialchars($p['address']) ?></td>
                                    <td><?= htmlspecialchars($p['city'] ?? '') ?></td>
                                    <td>
                                        <?php if ($p['latitude']): ?>
                                            <span class="badge badge-success"><?= htmlspecialchars($p['latitude']) ?>, <?= htmlspecialchars($p['longitude']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">No coordinates</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <?php if ($allResults): ?>
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div style="font-size:2rem;font-weight:700;color:var(--mw-green)"><?= count($allResults) ?></div>
                    <div class="text-muted small">Total GPS Records</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div style="font-size:2rem;font-weight:700;color:var(--mw-green)"><?= count($uniqueCrew) ?></div>
                    <div class="text-muted small">Unique Crew Members</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div style="font-size:1.1rem;font-weight:700;color:var(--mw-green)"><?= htmlspecialchars(implode(', ', $uniqueCrew)) ?></div>
                    <div class="text-muted small">Crew Names</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div style="font-size:1rem;font-weight:700;color:var(--mw-green)"><?= htmlspecialchars($dateRange) ?></div>
                    <div class="text-muted small">Date Range</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- GPS Records table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">GPS Records within <?= $radiusMetres ?>m</h5>
                    <span class="badge badge-<?= $allResults ? 'success' : 'secondary' ?>"><?= count($allResults) ?> records</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($properties)): ?>
                        <div class="p-3 text-muted">Run a search above to see GPS records.</div>
                    <?php elseif (empty($allResults)): ?>
                        <div class="p-3">
                            <div class="alert alert-warning mb-0">
                                <i data-feather="alert-circle" class="mr-2"></i>
                                <strong>No GPS records found</strong> within <?= $radiusMetres ?>m of
                                "<?= htmlspecialchars($searchName) ?>".
                                <?php if (count($properties) && !$properties[0]['latitude']): ?>
                                    (Property has no stored coordinates — geocode it first.)
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Crew</th>
                                        <th>Distance</th>
                                        <th>Accuracy</th>
                                        <th>Source</th>
                                        <th>Property</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($allResults as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('M j Y, g:ia', strtotime($r['timestamp']))) ?></td>
                                        <td><?= htmlspecialchars($r['crew_name'] ?? '—') ?></td>
                                        <td>
                                            <?php
                                                $d = (int)$r['distance_m'];
                                                $cls = $d < 100 ? 'success' : ($d < 300 ? 'warning' : 'secondary');
                                            ?>
                                            <span class="badge badge-<?= $cls ?>"><?= $d ?>m</span>
                                        </td>
                                        <td><?= $r['accuracy_meters'] !== null ? htmlspecialchars($r['accuracy_meters']) . 'm' : '—' ?></td>
                                        <td>
                                            <?php if ($r['source'] === 'crew_ping'): ?>
                                                <span class="badge badge-info">crew ping</span>
                                            <?php else: ?>
                                                <span class="badge badge-primary">visit #<?= (int)$r['visit_id'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small"><?= htmlspecialchars($r['property_address']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
