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
$debugErrors     = [];

try {
if ($searchName !== '') {
    $like = '%' . $searchName . '%';
    $parts = explode(' ', $searchName, 2);
    $first = '%' . ($parts[0] ?? $searchName) . '%';
    $last  = '%' . ($parts[1] ?? $searchName) . '%';

    $stmt = $db->prepare("
        SELECT p.id, p.address, p.city, p.province, p.latitude, p.longitude,
               CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
               c.id AS contact_id
        FROM contacts c
        JOIN properties p ON p.site_contact_id = c.id
        WHERE CONCAT(c.first_name, ' ', c.last_name) LIKE ?
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

        // Bounding box delta in degrees (~111km/degree latitude, ~73km/degree longitude at 49°N)
        $latDelta = $radiusMetres / 111000;
        $lngDelta = $radiusMetres / 73000;
        $latMin = $lat - $latDelta;
        $latMax = $lat + $latDelta;
        $lngMin = $lng - $lngDelta;
        $lngMax = $lng + $lngDelta;

        // crew_location_history — bounding box pre-filter, then exact Haversine HAVING
        // :lat appears twice in haversine, use :lat1/:lat2 (emulated prepares are OFF in this project)
        $stmt2 = $db->prepare("
            SELECT
                u.full_name AS crew_name,
                clh.latitude, clh.longitude,
                clh.timestamp,
                clh.accuracy_meters,
                ROUND(6371000 * acos(GREATEST(-1, LEAST(1,
                    cos(radians(:lat1)) * cos(radians(clh.latitude)) *
                    cos(radians(clh.longitude) - radians(:lng1)) +
                    sin(radians(:lat2)) * sin(radians(clh.latitude))
                )))) AS distance_m
            FROM crew_location_history clh
            JOIN users u ON u.id = clh.crew_id
            WHERE clh.latitude BETWEEN :latMin AND :latMax
              AND clh.longitude BETWEEN :lngMin AND :lngMax
            HAVING distance_m <= :radius1
            ORDER BY clh.timestamp DESC
            LIMIT 300
        ");
        $stmt2->execute([
            ':lat1' => $lat, ':lat2' => $lat, ':lng1' => $lng,
            ':latMin' => $latMin, ':latMax' => $latMax,
            ':lngMin' => $lngMin, ':lngMax' => $lngMax,
            ':radius1' => $radiusMetres,
        ]);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row['property_address'] = $prop['address'] . ', ' . ($prop['city'] ?? '');
            $row['source'] = 'crew_ping';
            $crewPings[] = $row;
        }

        // visit_gps_points — same pattern, table may not exist on all environments
        try {
            $stmt3 = $db->prepare("
                SELECT
                    u.full_name AS crew_name,
                    vgp.lat AS latitude,
                    vgp.lng AS longitude,
                    vgp.ts AS timestamp,
                    vgp.accuracy_m AS accuracy_meters,
                    vgp.visit_id,
                    ROUND(6371000 * acos(GREATEST(-1, LEAST(1,
                        cos(radians(:lat1)) * cos(radians(vgp.lat)) *
                        cos(radians(vgp.lng) - radians(:lng1)) +
                        sin(radians(:lat2)) * sin(radians(vgp.lat))
                    )))) AS distance_m
                FROM visit_gps_points vgp
                JOIN job_visits jv ON jv.id = vgp.visit_id
                JOIN users u ON u.id = jv.assigned_crew_id
                WHERE vgp.lat BETWEEN :latMin AND :latMax
                  AND vgp.lng BETWEEN :lngMin AND :lngMax
                HAVING distance_m <= :radius1
                ORDER BY vgp.ts DESC
                LIMIT 300
            ");
            $stmt3->execute([
                ':lat1' => $lat, ':lat2' => $lat, ':lng1' => $lng,
                ':latMin' => $latMin, ':latMax' => $latMax,
                ':lngMin' => $lngMin, ':lngMax' => $lngMax,
                ':radius1' => $radiusMetres,
            ]);
            $rows3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows3 as $row) {
                $row['property_address'] = $prop['address'] . ', ' . ($prop['city'] ?? '');
                $row['source'] = 'visit_point';
                $visitPoints[] = $row;
            }
        } catch (PDOException $e) {
            $debugErrors[] = 'visit_gps_points: ' . $e->getMessage();
        }
    }
}
} catch (Throwable $e) {
    $debugErrors[] = get_class($e) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ')';
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

<?php if (!empty($debugErrors)): ?>
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-danger">
            <strong>Query error(s):</strong>
            <ul class="mb-0 mt-1"><?php foreach ($debugErrors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?></ul>
        </div>
    </div>
</div>
<?php endif; ?>

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
