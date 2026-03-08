<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$db = getDB();
$isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');

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

$propertyLabel = '';
if ($propertyId) {
    $propStmt = $db->prepare("SELECT address, city FROM properties WHERE id = ?");
    $propStmt->execute([$propertyId]);
    $prop = $propStmt->fetch(PDO::FETCH_ASSOC);
    if ($prop) $propertyLabel = htmlspecialchars($prop['address'] . ', ' . $prop['city']);
}

$properties = $db->query("SELECT p.id, p.address, p.city FROM properties p ORDER BY p.address")->fetchAll(PDO::FETCH_ASSOC);
$serviceTypes = $db->query("SELECT DISTINCT service_type FROM job_plans WHERE service_type IS NOT NULL AND service_type != '' ORDER BY service_type")->fetchAll(PDO::FETCH_COLUMN);
$crewMembers = $db->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$where  = ['vp.deleted_at IS NULL'];
$params = [];

if ($propertyId) {
    $where[] = 'vp.property_id = ?';
    $params[] = $propertyId;
} elseif ($contactId) {
    $where[] = 'vp.property_id IN (SELECT id FROM properties WHERE site_contact_id = ?)';
    $params[] = $contactId;
}

if ($photoType && in_array($photoType, ['before','after','additional','during','issue','other'])) {
    $where[] = 'vp.photo_type = ?';
    $params[] = $photoType;
}
if ($serviceType) { $where[] = 'vp.service_type = ?'; $params[] = $serviceType; }
if ($crewId) { $where[] = 'vp.uploaded_by = ?'; $params[] = $crewId; }
if ($dateFrom) { $where[] = 'vp.uploaded_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $where[] = 'vp.uploaded_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
if ($search) {
    $like = '%' . $search . '%';
    $where[] = '(vp.tags LIKE ? OR vp.caption LIKE ? OR vp.uploaded_by_name LIKE ? OR pr.address LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM visit_photos vp LEFT JOIN properties pr ON pr.id = vp.property_id WHERE {$whereSQL}");
$countStmt->execute($params);
$totalPhotos = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalPhotos / $perPage));

$stmt = $db->prepare("
    SELECT vp.id, vp.visit_id, vp.photo_type, vp.filename, vp.caption,
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

$groups = [];
foreach ($photos as $p) {
    $dateKey  = $p['scheduled_date'] ?? substr($p['uploaded_at'], 0, 10);
    $visitKey = $dateKey . '_' . $p['visit_id'];
    if (!isset($groups[$visitKey])) {
        $groups[$visitKey] = [
            'date'        => $dateKey,
            'visit_id'    => (int)$p['visit_id'],
            'visit_number'=> $p['visit_number'],
            'plan_title'  => $p['plan_title'],
            'plan_number' => $p['plan_number'],
            'service_type'=> $p['service_type'],
            'property'    => trim(($p['property_address'] ?? '') . ', ' . ($p['property_city'] ?? ''), ', '),
            'photos'      => [],
        ];
    }
    $origUrl = '/uploads/photos/' . $p['filename'];
    $groups[$visitKey]['photos'][] = [
        'id'          => (int)$p['id'],
        'visit_id'    => (int)$p['visit_id'],
        'type'        => $p['photo_type'],
        'caption'     => $p['caption'],
        'tags'        => $p['tags'] ? (json_decode($p['tags'], true) ?: []) : [],
        'thumb_url'   => $p['thumb_path'] ?? $p['grid_path'] ?? $origUrl,
        'view_url'    => $p['view_path'] ?? $origUrl,
        'orig_url'    => $origUrl,
        'uploaded_at' => $p['uploaded_at'],
        'uploaded_by' => $p['uploaded_by_name'],
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
            <?php if ($propertyId): ?>across all visits<?php endif; ?>
        </p>
    </div>
    <?php if ($isAdmin && !empty($groups)): ?>
    <div class="d-flex gap-2">
        <button id="btnSelectMode" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectMode()">
            <i data-feather="check-square" style="width:14px;height:14px;"></i> Select
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Filter Bar -->
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
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Tags, caption, crew, address..."
                       value="<?php echo htmlspecialchars($search); ?>">
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

<?php if (empty($groups)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i data-feather="camera-off" style="width:48px;height:48px;" class="text-muted mb-3"></i>
            <h5 class="text-muted">No photos found</h5>
            <p class="text-muted mb-0"><?php echo isset($hasFilters) && $hasFilters ? 'Try adjusting your filters.' : 'Photos will appear here as crew uploads them during visits.'; ?></p>
        </div>
    </div>
<?php else: ?>

<div id="timelineWrap">
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
                $beforePhoto = null; $afterPhoto = null;
                foreach ($group['photos'] as $gp) {
                    if ($gp['type'] === 'before' && !$beforePhoto) $beforePhoto = $gp;
                    if ($gp['type'] === 'after'  && !$afterPhoto)  $afterPhoto  = $gp;
                }
                $hasBeforeAfter = $beforePhoto && $afterPhoto;
                ?>
                <?php if ($hasBeforeAfter): ?>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-outline-success"
                                onclick="openCompareSlider(<?php echo htmlspecialchars(json_encode($beforePhoto['view_url'])); ?>, <?php echo htmlspecialchars(json_encode($afterPhoto['view_url'])); ?>)">
                            <i data-feather="columns" style="width:14px;height:14px;"></i> Before / After Compare
                        </button>
                    </div>
                <?php endif; ?>
                <div class="mw-timeline-grid">
                    <?php foreach ($group['photos'] as $photo): ?>
                        <div class="mw-timeline-photo"
                             data-photo-id="<?php echo (int)$photo['id']; ?>"
                             onclick="photoTileClick(event, <?php echo htmlspecialchars(json_encode($group['photos'])); ?>, <?php echo (int)$photo['id']; ?>)">
                            <div class="mw-select-overlay" onclick="event.stopPropagation(); togglePhotoSelect(event, <?php echo (int)$photo['id']; ?>)">
                                <div class="mw-select-check"><i data-feather="check" style="width:14px;height:14px;"></i></div>
                            </div>
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

    <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-center mt-3 mb-4">
            <ul class="pagination pagination-sm">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Prev</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- Batch Action Bar -->
<div class="mw-batch-bar" id="batchBar" style="display:none">
    <span class="mw-batch-count" id="batchCount">0 selected</span>
    <button class="btn btn-sm btn-outline-light" onclick="batchAddTag()">
        <i data-feather="tag" style="width:13px;height:13px;"></i> Add Tag
    </button>
    <button class="btn btn-sm btn-outline-light" onclick="batchChangeType()">
        <i data-feather="refresh-cw" style="width:13px;height:13px;"></i> Change Type
    </button>
    <?php if ($isAdmin): ?>
    <button class="btn btn-sm btn-outline-danger" onclick="batchDelete()">
        <i data-feather="trash-2" style="width:13px;height:13px;"></i> Delete
    </button>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary" onclick="cancelSelectMode()">Cancel</button>
</div>

<!-- Lightbox Modal -->
<div id="photoLightbox" class="mw-lightbox" style="display:none" onclick="if(event.target===this)closeLightbox()">
    <div class="mw-lightbox-inner">
        <div class="mw-lightbox-photo-side">
            <button type="button" class="mw-lightbox-close" onclick="closeLightbox()">&times;</button>
            <button type="button" class="mw-lightbox-nav mw-lightbox-prev" onclick="lightboxNav(-1)">&#8249;</button>
            <div class="mw-lightbox-img-wrap">
                <img id="lightboxImg" class="mw-lightbox-img" src="" alt="">
                <canvas id="annotationCanvas" class="mw-annotation-canvas"></canvas>
            </div>
            <button type="button" class="mw-lightbox-nav mw-lightbox-next" onclick="lightboxNav(1)">&#8250;</button>
            <div class="mw-lightbox-meta" id="lightboxMeta"></div>
            <div class="mw-lightbox-tags-row" id="lightboxTagsRow">
                <div id="lightboxTagChips" class="mw-lightbox-tag-chips"></div>
                <div class="mw-tag-editor" id="tagEditor" style="display:none">
                    <input type="text" id="tagInput" class="form-control form-control-sm mw-tag-input"
                           placeholder="Type a tag and press Enter..." list="tagSuggestions" autocomplete="off"
                           onkeydown="tagInputKeydown(event)">
                    <datalist id="tagSuggestions">
                        <option value="drainage issue"><option value="pest damage">
                        <option value="upsell opportunity"><option value="property access issue">
                        <option value="equipment damage"><option value="hazard">
                        <option value="before treatment"><option value="after treatment">
                        <option value="seasonal change"><option value="client request">
                    </datalist>
                    <button class="btn btn-sm btn-primary" onclick="addLightboxTag()">Add</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="saveLightboxTags()">Save</button>
                </div>
            </div>
            <?php if ($isAdmin): ?>
            <div class="mw-annotation-toolbar" id="annotationToolbar" style="display:none">
                <span class="mw-annotation-label small text-white-50">Draw:</span>
                <button class="mw-ann-tool active" data-tool="arrow" onclick="setAnnTool(this,'arrow')">&#8594; Arrow</button>
                <button class="mw-ann-tool" data-tool="rect" onclick="setAnnTool(this,'rect')">&#9645; Rect</button>
                <button class="mw-ann-tool" data-tool="circle" onclick="setAnnTool(this,'circle')">&#9675; Circle</button>
                <input type="color" id="annColor" value="#e85d04" title="Color" class="mw-ann-color" oninput="ann.color=this.value">
                <button class="btn btn-sm btn-outline-light btn-sm ml-1" onclick="ann.undo()">Undo</button>
                <button class="btn btn-sm btn-outline-danger btn-sm" onclick="ann.clear()">Clear</button>
                <button class="btn btn-sm btn-success btn-sm" onclick="saveAnnotation()">Save</button>
            </div>
            <?php endif; ?>
            <div class="mw-lightbox-actions">
                <button class="btn btn-sm btn-outline-light mw-lb-action-btn" onclick="toggleLightboxTags()" id="btnLbTags">
                    <i data-feather="tag" style="width:13px;height:13px;"></i> Tags
                </button>
                <button class="btn btn-sm btn-outline-light mw-lb-action-btn" onclick="toggleCommentPanel()" id="btnLbComments">
                    <i data-feather="message-circle" style="width:13px;height:13px;"></i> Comments
                    <span id="commentCount" class="badge badge-secondary ml-1" style="display:none"></span>
                </button>
                <?php if ($isAdmin): ?>
                <button class="btn btn-sm btn-outline-light mw-lb-action-btn" onclick="toggleAnnotation()" id="btnLbAnnotate">
                    <i data-feather="edit-3" style="width:13px;height:13px;"></i> Annotate
                </button>
                <?php endif; ?>
                <a id="btnLbDownload" href="#" class="btn btn-sm btn-outline-light mw-lb-action-btn" target="_blank" download>
                    <i data-feather="download" style="width:13px;height:13px;"></i>
                </a>
            </div>
        </div>
        <div class="mw-comment-panel" id="commentPanel">
            <div class="mw-comment-panel-header">
                <span class="font-weight-bold">Comments</span>
                <button onclick="toggleCommentPanel()" class="mw-comment-panel-close">&times;</button>
            </div>
            <div class="mw-comment-list" id="commentList">
                <div class="text-center text-muted py-3 small">Loading comments...</div>
            </div>
            <div class="mw-comment-form">
                <textarea id="commentInput" class="form-control form-control-sm" rows="2"
                          placeholder="Add a comment... (1000 char max)" maxlength="1000"></textarea>
                <button class="btn btn-sm btn-primary mt-2 w-100" onclick="submitComment()">Post Comment</button>
            </div>
        </div>
    </div>
</div>

<!-- Before/After Compare Modal -->
<div id="compareModal" class="mw-lightbox" style="display:none" onclick="if(event.target===this)closeCompare()">
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
var IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

var lbPhotos = [], lbIndex = 0;
var currentPhotoId = 0, currentTags = [], tagsDirty = false;
var commentPanelOpen = false, annotationActive = false;

function openLightbox(photos, activeId) {
    lbPhotos = photos;
    lbIndex = photos.findIndex(function(p) { return p.id === activeId; });
    if (lbIndex < 0) lbIndex = 0;
    showLightboxPhoto();
    document.getElementById('photoLightbox').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    if (tagsDirty) saveLightboxTags();
    document.getElementById('photoLightbox').style.display = 'none';
    document.body.style.overflow = '';
    commentPanelOpen = false; annotationActive = false;
    document.getElementById('commentPanel').classList.remove('is-open');
    if (document.getElementById('annotationToolbar')) document.getElementById('annotationToolbar').style.display = 'none';
    ann.clear();
}

function lightboxNav(dir) {
    if (tagsDirty) saveLightboxTags();
    lbIndex = (lbIndex + dir + lbPhotos.length) % lbPhotos.length;
    showLightboxPhoto();
}

function showLightboxPhoto() {
    var p = lbPhotos[lbIndex];
    currentPhotoId = p.id; currentTags = (p.tags || []).slice(); tagsDirty = false;
    var img = document.getElementById('lightboxImg');
    img.src = p.view_url || p.orig_url; img.alt = p.caption || p.type + ' photo';
    document.getElementById('btnLbDownload').href = p.orig_url || p.view_url;
    var meta = '<span class="badge badge-light mr-2">' + escHtml((p.type||'').charAt(0).toUpperCase()+(p.type||'').slice(1)) + '</span>';
    if (p.uploaded_by) meta += '<span class="text-white-50 mr-2">' + escHtml(p.uploaded_by) + '</span>';
    if (p.uploaded_at) meta += '<span class="text-white-50">' + new Date(p.uploaded_at).toLocaleString() + '</span>';
    if (p.caption) meta += '<div class="mt-1 text-white">' + escHtml(p.caption) + '</div>';
    document.getElementById('lightboxMeta').innerHTML = meta;
    renderTagChips();
    img.onload = function() { ann.resize(); if (IS_ADMIN) loadAnnotation(currentPhotoId); };
    if (img.complete) { ann.resize(); if (IS_ADMIN) loadAnnotation(currentPhotoId); }
    if (commentPanelOpen) loadComments(currentPhotoId);
    if (typeof feather !== 'undefined') feather.replace();
}

function renderTagChips() {
    var row = document.getElementById('lightboxTagChips');
    if (!row) return;
    if (!currentTags.length) { row.innerHTML = '<span class="text-white-50 small">No tags</span>'; return; }
    row.innerHTML = currentTags.map(function(t,i) {
        return '<span class="mw-tag-chip mw-tag-chip--editable">' + escHtml(t) + '<button type="button" class="mw-tag-remove" onclick="removeTag(' + i + ')">&times;</button></span>';
    }).join(' ');
}

function toggleLightboxTags() {
    var ed = document.getElementById('tagEditor'), btn = document.getElementById('btnLbTags');
    var showing = ed.style.display !== 'none';
    ed.style.display = showing ? 'none' : 'flex'; btn.classList.toggle('active', !showing);
}
function removeTag(i) { currentTags.splice(i,1); tagsDirty=true; renderTagChips(); }
function tagInputKeydown(e) { if (e.key==='Enter') { e.preventDefault(); addLightboxTag(); } }
function addLightboxTag() {
    var inp = document.getElementById('tagInput');
    var val = inp.value.trim().toLowerCase().substring(0,50);
    if (!val || currentTags.indexOf(val)!==-1) { inp.value=''; return; }
    if (currentTags.length>=10) { alert('Maximum 10 tags'); return; }
    currentTags.push(val); tagsDirty=true; inp.value=''; renderTagChips();
}
function saveLightboxTags() {
    tagsDirty=false;
    fetch('/crm/api/job-photo.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'update_tags',photo_id:currentPhotoId,tags:currentTags,csrf_token:CSRF})
    }).catch(function(){});
    if (lbPhotos[lbIndex]) lbPhotos[lbIndex].tags = currentTags.slice();
}

function toggleCommentPanel() {
    commentPanelOpen = !commentPanelOpen;
    var panel = document.getElementById('commentPanel'), btn = document.getElementById('btnLbComments');
    panel.classList.toggle('is-open', commentPanelOpen);
    btn.classList.toggle('active', commentPanelOpen);
    if (commentPanelOpen) loadComments(currentPhotoId);
}

function loadComments(photoId) {
    var list = document.getElementById('commentList');
    list.innerHTML = '<div class="text-center text-muted py-3 small">Loading...</div>';
    fetch('/crm/api/job-photo.php?action=list_comments&photo_id='+photoId)
    .then(function(r){return r.json();})
    .then(function(data){
        if (!data.comments||!data.comments.length) {
            list.innerHTML='<div class="text-center text-muted py-3 small">No comments yet.</div>';
            document.getElementById('commentCount').style.display='none'; return;
        }
        var html='';
        data.comments.forEach(function(c){
            var ini=(c.user_name||'?').split(' ').map(function(w){return w[0];}).join('').substring(0,2).toUpperCase();
            html+='<div class="mw-comment-item" data-comment-id="'+c.id+'">'
                +'<div class="mw-comment-avatar">'+escHtml(ini)+'</div>'
                +'<div class="mw-comment-body">'
                +'<div class="mw-comment-author">'+escHtml(c.user_name||'Unknown')+'</div>'
                +'<div class="mw-comment-text">'+escHtml(c.comment)+'</div>'
                +'<div class="mw-comment-time">'+formatDate(c.created_at)+'</div>'
                +'</div>'
                +(IS_ADMIN?'<button class="mw-comment-delete" title="Delete" onclick="deleteComment('+c.id+')"><i data-feather="trash-2" style="width:11px;height:11px;"></i></button>':'')
                +'</div>';
        });
        list.innerHTML=html;
        var cnt=document.getElementById('commentCount'); cnt.textContent=data.comments.length; cnt.style.display='';
        if (typeof feather!=='undefined') feather.replace();
    }).catch(function(){ list.innerHTML='<div class="text-center text-danger py-3 small">Failed to load.</div>'; });
}

function submitComment() {
    var inp=document.getElementById('commentInput'), val=inp.value.trim();
    if (!val) return;
    var btn=inp.nextElementSibling; btn.disabled=true;
    fetch('/crm/api/job-photo.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'add_comment',photo_id:currentPhotoId,comment:val,csrf_token:CSRF})
    }).then(function(r){return r.json();}).then(function(data){
        btn.disabled=false;
        if (data.success) { inp.value=''; loadComments(currentPhotoId); }
        else alert(data.error||'Failed to post comment');
    }).catch(function(){ btn.disabled=false; alert('Network error'); });
}

function deleteComment(commentId) {
    if (!confirm('Delete this comment?')) return;
    fetch('/crm/api/job-photo.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'delete_comment',comment_id:commentId,csrf_token:CSRF})
    }).then(function(r){return r.json();}).then(function(data){ if (data.success) loadComments(currentPhotoId); });
}

var ann = (function() {
    var canvas,ctx,shapes=[],isDrawing=false,startX=0,startY=0,previewShape=null,tool='arrow',color='#e85d04';
    function init() {
        canvas=document.getElementById('annotationCanvas'); if (!canvas) return;
        ctx=canvas.getContext('2d');
        canvas.addEventListener('mousedown',onDown); canvas.addEventListener('mousemove',onMove);
        canvas.addEventListener('mouseup',onUp); canvas.addEventListener('mouseleave',onUp);
    }
    function resize() {
        if (!canvas) return;
        var imgEl=document.getElementById('lightboxImg');
        canvas.style.width=imgEl.offsetWidth+'px'; canvas.style.height=imgEl.offsetHeight+'px';
        canvas.width=imgEl.offsetWidth; canvas.height=imgEl.offsetHeight; redraw();
    }
    function pos(e) { var r=canvas.getBoundingClientRect(); return {x:(e.clientX-r.left)/r.width,y:(e.clientY-r.top)/r.height}; }
    function onDown(e) { if (!annotationActive) return; var p=pos(e); isDrawing=true; startX=p.x; startY=p.y; }
    function onMove(e) {
        if (!isDrawing||!annotationActive) return; var p=pos(e);
        previewShape={type:tool,x1:startX,y1:startY,x2:p.x,y2:p.y,color:color,label:''}; redraw();
    }
    function onUp() {
        if (!isDrawing) return; isDrawing=false;
        if (previewShape) {
            var dx=Math.abs(previewShape.x2-previewShape.x1),dy=Math.abs(previewShape.y2-previewShape.y1);
            if (dx>0.01||dy>0.01) shapes.push(previewShape);
            previewShape=null; redraw();
        }
    }
    function drawOne(s,preview) {
        var w=canvas.width,h=canvas.height;
        var x1=s.x1*w,y1=s.y1*h,x2=s.x2*w,y2=s.y2*h;
        ctx.strokeStyle=s.color; ctx.fillStyle=s.color; ctx.lineWidth=preview?2:2.5; ctx.globalAlpha=preview?0.65:1;
        if (s.type==='arrow') {
            ctx.beginPath(); ctx.moveTo(x1,y1); ctx.lineTo(x2,y2); ctx.stroke();
            var angle=Math.atan2(y2-y1,x2-x1),len=14;
            ctx.beginPath(); ctx.moveTo(x2,y2);
            ctx.lineTo(x2-len*Math.cos(angle-0.4),y2-len*Math.sin(angle-0.4));
            ctx.lineTo(x2-len*Math.cos(angle+0.4),y2-len*Math.sin(angle+0.4));
            ctx.closePath(); ctx.fill();
        } else if (s.type==='rect') {
            ctx.beginPath(); ctx.strokeRect(x1,y1,x2-x1,y2-y1);
        } else if (s.type==='circle') {
            var rx=(x2-x1)/2,ry=(y2-y1)/2;
            ctx.beginPath(); ctx.ellipse(x1+rx,y1+ry,Math.abs(rx),Math.abs(ry),0,0,2*Math.PI); ctx.stroke();
        }
        ctx.globalAlpha=1;
    }
    function redraw() {
        if (!ctx) return; ctx.clearRect(0,0,canvas.width,canvas.height);
        shapes.forEach(function(s){drawOne(s,false);});
        if (previewShape) drawOne(previewShape,true);
    }
    return {
        init:init, resize:resize,
        set color(c){color=c;},
        get shapes(){return shapes;},
        setTool:function(t){tool=t;},
        loadShapes:function(arr){shapes=arr||[];redraw();},
        undo:function(){shapes.pop();redraw();},
        clear:function(){shapes=[];previewShape=null;if(ctx)ctx.clearRect(0,0,canvas.width,canvas.height);},
        setActive:function(on){if(canvas)canvas.style.cursor=on?'crosshair':'';}
    };
})();

function toggleAnnotation() {
    annotationActive=!annotationActive;
    var toolbar=document.getElementById('annotationToolbar'),btn=document.getElementById('btnLbAnnotate');
    if (toolbar) toolbar.style.display=annotationActive?'flex':'none';
    if (btn) btn.classList.toggle('active',annotationActive);
    ann.setActive(annotationActive);
    if (annotationActive) { ann.resize(); loadAnnotation(currentPhotoId); }
}
function setAnnTool(btn,t) {
    document.querySelectorAll('.mw-ann-tool').forEach(function(b){b.classList.remove('active');});
    btn.classList.add('active'); ann.setTool(t);
}
function loadAnnotation(photoId) {
    fetch('/crm/api/job-photo.php?action=get_annotation&photo_id='+photoId)
    .then(function(r){return r.json();})
    .then(function(data){ if (data.annotation&&data.annotation.shapes) { ann.resize(); ann.loadShapes(data.annotation.shapes); } else { ann.clear(); } })
    .catch(function(){});
}
function saveAnnotation() {
    var btn=document.querySelector('.mw-annotation-toolbar .btn-success');
    if (btn) btn.disabled=true;
    fetch('/crm/api/job-photo.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'save_annotation',photo_id:currentPhotoId,shapes:ann.shapes,csrf_token:CSRF})
    }).then(function(r){return r.json();}).then(function(data){
        if (btn) btn.disabled=false;
        if (!data.success) alert(data.error||'Failed to save');
    }).catch(function(){if(btn) btn.disabled=false;});
}

document.addEventListener('keydown',function(e){
    if (document.getElementById('photoLightbox').style.display!=='flex') return;
    if (e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
    if (e.key==='Escape') closeLightbox();
    if (e.key==='ArrowLeft') lightboxNav(-1);
    if (e.key==='ArrowRight') lightboxNav(1);
});

var selectModeActive=false, selectedIds=[];
function photoTileClick(e,photos,photoId) {
    if (selectModeActive) togglePhotoSelect(e,photoId); else openLightbox(photos,photoId);
}
function toggleSelectMode() {
    selectModeActive=!selectModeActive;
    var btn=document.getElementById('btnSelectMode');
    if (btn) btn.classList.toggle('active',selectModeActive);
    var wrap=document.getElementById('timelineWrap');
    if (wrap) wrap.classList.toggle('is-select-mode',selectModeActive);
    if (!selectModeActive) { selectedIds=[]; document.querySelectorAll('.mw-timeline-photo.is-selected').forEach(function(t){t.classList.remove('is-selected');}); updateBatchBar(); }
}
function cancelSelectMode() {
    selectModeActive=false;
    var btn=document.getElementById('btnSelectMode'); if (btn) btn.classList.remove('active');
    var wrap=document.getElementById('timelineWrap'); if (wrap) wrap.classList.remove('is-select-mode');
    selectedIds=[]; document.querySelectorAll('.mw-timeline-photo.is-selected').forEach(function(t){t.classList.remove('is-selected');}); updateBatchBar();
}
function togglePhotoSelect(e,photoId) {
    var tile=e.currentTarget||document.querySelector('.mw-timeline-photo[data-photo-id="'+photoId+'"]');
    var idx=selectedIds.indexOf(photoId);
    if (idx===-1) { selectedIds.push(photoId); if(tile) tile.classList.add('is-selected'); }
    else { selectedIds.splice(idx,1); if(tile) tile.classList.remove('is-selected'); }
    updateBatchBar();
}
function updateBatchBar() {
    var bar=document.getElementById('batchBar'),cnt=document.getElementById('batchCount');
    if (!selectedIds.length) { bar.style.display='none'; return; }
    cnt.textContent=selectedIds.length+' selected'; bar.style.display='flex';
    if (typeof feather!=='undefined') feather.replace();
}
function batchPost(action,extra,confirmMsg) {
    if (!selectedIds.length) return;
    if (confirmMsg&&!confirm(confirmMsg)) return;
    var body=Object.assign({action:action,photo_ids:selectedIds,csrf_token:CSRF},extra||{});
    fetch('/crm/api/job-photo.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(function(r){return r.json();}).then(function(data){ if(data.success){cancelSelectMode();location.reload();} else alert(data.error||'Failed'); })
    .catch(function(){alert('Network error');});
}
function batchAddTag() {
    var tag=prompt('Tag to add to '+selectedIds.length+' photo(s):');
    if (!tag||!tag.trim()) return; batchPost('bulk_tag',{tags:[tag.trim().toLowerCase()]});
}
function batchChangeType() {
    var type=prompt('New type for '+selectedIds.length+' photo(s):\nbefore / after / additional / during / issue / other');
    if (!type) return; type=type.trim().toLowerCase();
    if (['before','after','additional','during','issue','other'].indexOf(type)===-1) { alert('Invalid type'); return; }
    batchPost('bulk_change_type',{photo_type:type});
}
function batchDelete() { batchPost('bulk_delete',{},'Delete '+selectedIds.length+' photo(s)? Cannot be undone.'); }

function openCompareSlider(beforeUrl,afterUrl) {
    document.getElementById('compareBefore').src=beforeUrl; document.getElementById('compareAfter').src=afterUrl;
    document.getElementById('compareHandle').value=50; document.getElementById('compareOverlay').style.width='50%';
    document.getElementById('compareModal').style.display='flex'; document.body.style.overflow='hidden';
}
function closeCompare() { document.getElementById('compareModal').style.display='none'; document.body.style.overflow=''; }

function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function formatDate(str) { try{return new Date(str).toLocaleString();}catch(e){return str;} }

ann.init();
</script>

<?php include 'includes/appstack_footer.php'; ?>
