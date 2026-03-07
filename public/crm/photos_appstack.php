<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$db = getDB();
$isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');

// ── Filters from query string ────────────────────────────────────────────────
$propertyId  = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;
$contactId   = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : 0;
$photoType   = $_GET['type'] ?? '';
$serviceType = $_GET['service'] ?? '';
$crewId      = isset($_GET['crew_id']) ? (int)$_GET['crew_id'] : 0;
$dateFrom    = $_GET['from'] ?? '';
$dateTo      = $_GET['to'] ?? '';
$search      = trim($_GET['q'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 60;
$offset      = ($page - 1) * $perPage;

// ── Build title ──────────────────────────────────────────────────────────────
$propertyLabel = '';
if ($propertyId) {
    $propStmt = $db->prepare("SELECT address, city FROM properties WHERE id = ?");
    $propStmt->execute([$propertyId]);
    $prop = $propStmt->fetch(PDO::FETCH_ASSOC);
    if ($prop) $propertyLabel = htmlspecialchars($prop['address'] . ', ' . $prop['city']);
}

// ── Load filter options ──────────────────────────────────────────────────────

// Properties dropdown
$properties = $db->query("
    SELECT p.id, p.address, p.city
    FROM properties p
    WHERE p.status = 'active'
    ORDER BY p.address
")->fetchAll(PDO::FETCH_ASSOC);

// Service types dropdown
$serviceTypes = $db->query("
    SELECT DISTINCT service_type FROM job_plans
    WHERE service_type IS NOT NULL AND service_type != ''
    ORDER BY service_type
")->fetchAll(PDO::FETCH_COLUMN);

// Crew members dropdown
$crewMembers = $db->query("
    SELECT id, full_name FROM users
    WHERE status = 'active'
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Build query ──────────────────────────────────────────────────────────────
$where  = ['vp.deleted_at IS NULL'];
$params = [];

if ($propertyId) {
    $where[] = 'vp.property_id = ?';
    $params[] = $propertyId;
} elseif ($contactId) {
    // All properties for this contact
    $where[] = 'vp.property_id IN (SELECT id FROM properties WHERE site_contact_id = ?)';
    $params[] = $contactId;
}

if ($photoType && in_array($photoType, ['before','after','additional','during','issue','other'])) {
    $where[] = 'vp.photo_type = ?';
    $params[] = $photoType;
}

if ($serviceType) {
    $where[] = 'vp.service_type = ?';
    $params[] = $serviceType;
}

if ($crewId) {
    $where[] = 'vp.uploaded_by = ?';
    $params[] = $crewId;
}

if ($dateFrom) {
    $where[] = 'vp.uploaded_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = 'vp.uploaded_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

if ($search) {
    $like = '%' . $search . '%';
    $where[] = '(vp.tags LIKE ? OR vp.caption LIKE ? OR vp.uploaded_by_name LIKE ? OR pr.address LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = implode(' AND ', $where);

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(*) FROM visit_photos vp
    LEFT JOIN properties pr ON pr.id = vp.property_id
    WHERE {$whereSQL}
");
$countStmt->execute($params);
$totalPhotos = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalPhotos / $perPage));

// Fetch photos
$stmt = $db->prepare("
    SELECT
        vp.id, vp.visit_id, vp.photo_type, vp.filename, vp.caption,
        vp.tags, vp.thumb_path, vp.grid_path, vp.view_path,
        vp.uploaded_at, vp.uploaded_by, vp.uploaded_by_name,
        vp.service_type, vp.property_id, vp.sha256,
        jv.scheduled_date, jv.visit_number,
        jp.title AS plan_title, jp.plan_number,
        pr.address AS property_address, pr.city AS property_city
    FROM visit_photos vp
    JOIN job_visits jv ON jv.id = vp.visit_id
    JOIN job_plans jp ON jp.id = jv.plan_id
    LEFT JOIN properties pr ON pr.id = vp.property_id
    WHERE {$whereSQL}
    ORDER BY vp.uploaded_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group photos by visit date for timeline display
$groups = [];
foreach ($photos as $p) {
    $dateKey = $p['scheduled_date'] ?? substr($p['uploaded_at'], 0, 10);
    $visitKey = $dateKey . '_' . $p['visit_id'];
    if (!isset($groups[$visitKey])) {
        $groups[$visitKey] = [
            'date'      => $dateKey,
            'visit_id'  => (int)$p['visit_id'],
            'visit_number' => $p['visit_number'],
            'plan_title'   => $p['plan_title'],
            'plan_number'  => $p['plan_number'],
            'service_type' => $p['service_type'],
            'property'     => trim(($p['property_address'] ?? '') . ', ' . ($p['property_city'] ?? ''), ', '),
            'photos'       => [],
        ];
    }
    $origUrl = '/uploads/photos/' . $p['filename'];
    $groups[$visitKey]['photos'][] = [
        'id'         => (int)$p['id'],
        'type'       => $p['photo_type'],
        'caption'    => $p['caption'],
        'tags'       => $p['tags'] ? json_decode($p['tags'], true) : [],
        'thumb_url'  => $p['thumb_path'] ?? $p['grid_path'] ?? $origUrl,
        'view_url'   => $p['view_path'] ?? $origUrl,
        'orig_url'   => $origUrl,
        'uploaded_at'=> $p['uploaded_at'],
        'uploaded_by'=> $p['uploaded_by_name'],
    ];
}

$pageTitle  = $propertyLabel ? "Photos — {$propertyLabel}" : 'Photo Timeline';
$activePage = 'photos';
$csrfToken  = generateCSRFToken();
$extraHead  = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">
            <i data-feather="camera" class="align-middle mr-2" style="width:24px;height:24px;"></i>
            <?php echo $propertyLabel ? 'Photos — ' . $propertyLabel : 'Photo Timeline'; ?>
        </h1>
        <p class="text-muted mb-0 mt-1">
            <?php echo number_format($totalPhotos); ?> photo<?php echo $totalPhotos !== 1 ? 's' : ''; ?>
            <?php if ($propertyId): ?>
                across all visits
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="mw-filter-bar d-flex flex-wrap align-items-end gap-2">
            <?php if ($propertyId): ?>
                <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
            <?php else: ?>
                <div class="form-group mb-0">
                    <label class="small text-muted mb-0">Property</label>
                    <select name="property_id" class="form-control form-control-sm">
                        <option value="">All Properties</option>
                        <?php foreach ($properties as $pr): ?>
                            <option value="<?php echo $pr['id']; ?>" <?php echo $propertyId === (int)$pr['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pr['address'] . ', ' . $pr['city']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group mb-0">
                <label class="small text-muted mb-0">Type</label>
                <select name="type" class="form-control form-control-sm">
                    <option value="">All Types</option>
                    <option value="before" <?php echo $photoType === 'before' ? 'selected' : ''; ?>>Before</option>
                    <option value="after" <?php echo $photoType === 'after' ? 'selected' : ''; ?>>After</option>
                    <option value="additional" <?php echo $photoType === 'additional' ? 'selected' : ''; ?>>Additional</option>
                </select>
            </div>

            <div class="form-group mb-0">
                <label class="small text-muted mb-0">Service</label>
                <select name="service" class="form-control form-control-sm">
                    <option value="">All Services</option>
                    <?php foreach ($serviceTypes as $st): ?>
                        <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $serviceType === $st ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $st))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-0">
                <label class="small text-muted mb-0">Crew</label>
                <select name="crew_id" class="form-control form-control-sm">
                    <option value="">All Crew</option>
                    <?php foreach ($crewMembers as $cm): ?>
                        <option value="<?php echo $cm['id']; ?>" <?php echo $crewId === (int)$cm['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cm['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-0">
                <label class="small text-muted mb-0">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>

            <div class="form-group mb-0">
                <label class="small text-muted mb-0">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>

            <div class="form-group mb-0">
                <label class="small text-muted mb-0">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Tags, caption, crew, address..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <button type="submit" class="btn btn-sm btn-primary mb-0">Filter</button>
            <?php
            $hasFilters = $photoType || $serviceType || $crewId || $dateFrom || $dateTo || $search || (!$propertyId && isset($_GET['property_id']));
            if ($hasFilters): ?>
                <a href="<?php echo $propertyId ? '?property_id=' . $propertyId : '?'; ?>" class="btn btn-sm btn-outline-secondary mb-0">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Timeline ───────────────────────────────────────────────────────────── -->
<?php if (empty($groups)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i data-feather="camera-off" style="width:48px;height:48px;" class="text-muted mb-3"></i>
            <h5 class="text-muted">No photos found</h5>
            <p class="text-muted mb-0">
                <?php if ($hasFilters): ?>
                    Try adjusting your filters.
                <?php else: ?>
                    Photos will appear here as crew uploads them during visits.
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <div class="mw-timeline-group card mb-3">
            <div class="mw-timeline-header card-header d-flex justify-content-between align-items-center py-2">
                <div>
                    <strong><?php echo date('M j, Y', strtotime($group['date'])); ?></strong>
                    <span class="text-muted mx-1">&middot;</span>
                    <a href="/crm/jobs/visit-detail.php?id=<?php echo $group['visit_id']; ?>" class="text-muted">
                        <?php echo htmlspecialchars($group['visit_number'] ?? ''); ?>
                    </a>
                    <?php if ($group['service_type']): ?>
                        <span class="badge badge-light ml-1"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $group['service_type']))); ?></span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small">
                    <?php if (!$propertyId && $group['property']): ?>
                        <i data-feather="map-pin" style="width:12px;height:12px;"></i>
                        <?php echo htmlspecialchars($group['property']); ?>
                        <span class="mx-1">&middot;</span>
                    <?php endif; ?>
                    <?php echo count($group['photos']); ?> photo<?php echo count($group['photos']) !== 1 ? 's' : ''; ?>
                </div>
            </div>
            <div class="card-body p-2">
                <?php
                // Check for before/after pair for comparison slider
                $hasBeforeAfter = false;
                $beforePhoto = null;
                $afterPhoto = null;
                foreach ($group['photos'] as $gp) {
                    if ($gp['type'] === 'before' && !$beforePhoto) $beforePhoto = $gp;
                    if ($gp['type'] === 'after' && !$afterPhoto) $afterPhoto = $gp;
                }
                $hasBeforeAfter = $beforePhoto && $afterPhoto;
                ?>

                <?php if ($hasBeforeAfter): ?>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-outline-success"
                                onclick="openCompareSlider(<?php echo htmlspecialchars(json_encode($beforePhoto['view_url'])); ?>, <?php echo htmlspecialchars(json_encode($afterPhoto['view_url'])); ?>)">
                            <i data-feather="columns" style="width:14px;height:14px;"></i>
                            Before / After Compare
                        </button>
                    </div>
                <?php endif; ?>

                <div class="mw-timeline-grid">
                    <?php foreach ($group['photos'] as $photo): ?>
                        <div class="mw-timeline-photo"
                             onclick="openLightbox(<?php echo htmlspecialchars(json_encode($group['photos'])); ?>, <?php echo (int)$photo['id']; ?>)">
                            <img src="<?php echo htmlspecialchars($photo['thumb_url']); ?>"
                                 alt="<?php echo htmlspecialchars($photo['caption'] ?: $photo['type'] . ' photo'); ?>"
                                 loading="lazy">
                            <span class="mw-timeline-type-badge mw-timeline-type-badge--<?php echo htmlspecialchars($photo['type']); ?>">
                                <?php echo htmlspecialchars(ucfirst($photo['type'])); ?>
                            </span>
                            <?php if (!empty($photo['tags'])): ?>
                                <span class="mw-timeline-tag-count" title="<?php echo htmlspecialchars(implode(', ', $photo['tags'])); ?>">
                                    <i data-feather="tag" style="width:10px;height:10px;"></i>
                                    <?php echo count($photo['tags']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ── Pagination ─────────────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-center mt-3 mb-4">
            <ul class="pagination pagination-sm">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Prev</a>
                    </li>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- ── Lightbox Modal ─────────────────────────────────────────────────────── -->
<div id="photoLightbox" class="mw-lightbox" style="display:none;" onclick="if(event.target===this)closeLightbox()">
    <div class="mw-lightbox-content">
        <button type="button" class="mw-lightbox-close" onclick="closeLightbox()">&times;</button>
        <button type="button" class="mw-lightbox-nav mw-lightbox-prev" onclick="lightboxNav(-1)">&#8249;</button>
        <img id="lightboxImg" class="mw-lightbox-img" src="" alt="">
        <button type="button" class="mw-lightbox-nav mw-lightbox-next" onclick="lightboxNav(1)">&#8250;</button>
        <div class="mw-lightbox-meta" id="lightboxMeta"></div>
    </div>
</div>

<!-- ── Before/After Compare Modal ─────────────────────────────────────────── -->
<div id="compareModal" class="mw-lightbox" style="display:none;" onclick="if(event.target===this)closeCompare()">
    <div class="mw-lightbox-content" style="max-width:900px;">
        <button type="button" class="mw-lightbox-close" onclick="closeCompare()">&times;</button>
        <h5 class="text-center text-white mb-3">Before / After Comparison</h5>
        <div class="mw-compare-slider" id="compareSlider">
            <img id="compareAfter" class="mw-compare-img mw-compare-img--after" src="" alt="After">
            <div class="mw-compare-overlay" id="compareOverlay">
                <img id="compareBefore" class="mw-compare-img mw-compare-img--before" src="" alt="Before">
            </div>
            <input type="range" class="mw-compare-handle" id="compareHandle" min="0" max="100" value="50"
                   oninput="document.getElementById('compareOverlay').style.width=this.value+'%'">
            <div class="mw-compare-labels">
                <span class="mw-compare-label mw-compare-label--before">Before</span>
                <span class="mw-compare-label mw-compare-label--after">After</span>
            </div>
        </div>
    </div>
</div>

<script>
// ── Lightbox ─────────────────────────────────────────────────────────────────
var lbPhotos = [], lbIndex = 0;

function openLightbox(photos, activeId) {
    lbPhotos = photos;
    lbIndex = photos.findIndex(function(p) { return p.id === activeId; });
    if (lbIndex < 0) lbIndex = 0;
    showLightboxPhoto();
    document.getElementById('photoLightbox').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('photoLightbox').style.display = 'none';
    document.body.style.overflow = '';
}

function lightboxNav(dir) {
    lbIndex = (lbIndex + dir + lbPhotos.length) % lbPhotos.length;
    showLightboxPhoto();
}

function showLightboxPhoto() {
    var p = lbPhotos[lbIndex];
    document.getElementById('lightboxImg').src = p.view_url || p.orig_url;
    var meta = '<span class="badge badge-light mr-2">' + (p.type || '').charAt(0).toUpperCase() + (p.type || '').slice(1) + '</span>';
    if (p.uploaded_by) meta += '<span class="text-white-50 mr-2">' + p.uploaded_by + '</span>';
    if (p.uploaded_at) meta += '<span class="text-white-50">' + new Date(p.uploaded_at).toLocaleString() + '</span>';
    if (p.caption) meta += '<div class="mt-1 text-white">' + p.caption + '</div>';
    if (p.tags && p.tags.length) {
        meta += '<div class="mt-1">';
        p.tags.forEach(function(t) { meta += '<span class="mw-tag-chip">' + t + '</span> '; });
        meta += '</div>';
    }
    document.getElementById('lightboxMeta').innerHTML = meta;
}

// Keyboard nav
document.addEventListener('keydown', function(e) {
    if (document.getElementById('photoLightbox').style.display !== 'flex') return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') lightboxNav(-1);
    if (e.key === 'ArrowRight') lightboxNav(1);
});

// ── Before/After Compare ─────────────────────────────────────────────────────
function openCompareSlider(beforeUrl, afterUrl) {
    document.getElementById('compareBefore').src = beforeUrl;
    document.getElementById('compareAfter').src = afterUrl;
    document.getElementById('compareHandle').value = 50;
    document.getElementById('compareOverlay').style.width = '50%';
    document.getElementById('compareModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeCompare() {
    document.getElementById('compareModal').style.display = 'none';
    document.body.style.overflow = '';
}
</script>

<?php include 'includes/appstack_footer.php'; ?>
