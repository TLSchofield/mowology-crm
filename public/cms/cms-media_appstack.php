<?php
/**
 * CMS Media Library — Unified Upload + Variants + Context Linking + Icon Sets
 *
 * Tab 1: Media Assets — upload once → optimized variants → context linking.
 * Tab 2: Icon Sets  — create reusable named icon sets, assign to products.
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/crm/includes/cms-functions.php';
require_once dirname(__DIR__) . '/crm/includes/admin-ui-kit.php';

// Load new media helpers via paths.php
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
require_once APP_ROOT . '/Services/Media/MediaHelpers.php';

requireLogin();
$user = getCurrentUser();

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    die('Access denied');
}

$pageTitle = 'Media Library';
$activePage = 'media';
$extraHead = '<script src="/crm/js/media-uploader.js" defer></script>';

// Active tab
$activeTab = $_GET['tab'] ?? 'assets';

// ── Media Assets (tab 1) ────────────────────────────────────────────────────

$typeFilter   = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$viewMode     = $_GET['view'] ?? 'grid';

$allMedia = cms_getMediaAssets();

$media = $allMedia;
if ($typeFilter) {
    $media = array_filter($media, fn($m) => ($m['file_type'] ?? '') === $typeFilter);
}
if ($searchFilter) {
    $search = strtolower($searchFilter);
    $media = array_filter($media, fn($m) =>
        stripos($m['original_filename'] ?? '', $search) !== false ||
        stripos($m['alt_text'] ?? '', $search) !== false
    );
}

usort($media, fn($a, $b) => strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0'));

$db = getDB();

// Graceful column/table detection
$hasNewCols = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM media_assets LIKE 'uuid'");
    $hasNewCols = $colCheck && $colCheck->rowCount() > 0;
} catch (Exception $e) {}

$hasVariantsTable = false;
try {
    $tblCheck = $db->query("SHOW TABLES LIKE 'media_variants'");
    $hasVariantsTable = $tblCheck && $tblCheck->rowCount() > 0;
} catch (Exception $e) {}

$variantThumbMap = [];
$variantCountMap = [];
if ($hasVariantsTable && !empty($media)) {
    $mediaIds = array_filter(array_column($media, 'id'));
    if ($mediaIds) {
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));

        $thStmt = $db->prepare(
            "SELECT media_id, file_path
             FROM media_variants
             WHERE media_id IN ($placeholders)
               AND variant_type = 'thumb_square'
               AND format = 'jpeg'"
        );
        $thStmt->execute(array_values($mediaIds));
        foreach ($thStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variantThumbMap[(int)$row['media_id']] = $row['file_path'];
        }

        $vcStmt = $db->prepare(
            "SELECT media_id, COUNT(*) AS cnt
             FROM media_variants
             WHERE media_id IN ($placeholders)
             GROUP BY media_id"
        );
        $vcStmt->execute(array_values($mediaIds));
        foreach ($vcStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variantCountMap[(int)$row['media_id']] = (int)$row['cnt'];
        }
    }
}

foreach ($media as &$m) {
    $m['filename']         = $m['original_filename'] ?? $m['stored_filename'] ?? '';
    $m['media_type']       = $m['file_type'] ?? 'image';
    $m['uploaded_date']    = !empty($m['created_at']) ? date('M d, Y', strtotime($m['created_at'])) : '';
    $m['file_size_display'] = formatMediaBytes((int)($m['file_size'] ?? 0));
    $mid = (int)($m['id'] ?? 0);
    $m['thumb_url']        = $variantThumbMap[$mid] ?? null;
    $m['variant_count']    = $variantCountMap[$mid] ?? 0;
    $m['context_display']  = $hasNewCols ? ($m['context_type'] ?? 'cms') : 'cms';
}
unset($m);

$totalImages = count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'image'));
$totalVideos = count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'video'));
$totalDocs   = count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'document'));
$totalAll    = count($allMedia);

// ── Icon Sets (tab 2) ───────────────────────────────────────────────────────

$iconSets   = [];
$iconSetCount = 0;
$hasIconSetsTable = false;

try {
    $hasIconSetsTable = $db->query("SHOW TABLES LIKE 'icon_sets'")->rowCount() > 0;
} catch (Exception $e) {}

if ($hasIconSetsTable) {
    $hasIconSetId = false;
    try {
        $hasIconSetId = $db->query("SHOW COLUMNS FROM products LIKE 'icon_set_id'")->rowCount() > 0;
    } catch (Exception $e) {}

    if ($hasIconSetId) {
        $isStmt = $db->query(
            "SELECT s.*, COUNT(p.id) AS product_count
             FROM icon_sets s
             LEFT JOIN products p ON p.icon_set_id = s.id AND p.is_archived = 0
             GROUP BY s.id
             ORDER BY s.created_at DESC"
        );
    } else {
        $isStmt = $db->query(
            "SELECT s.*, 0 AS product_count FROM icon_sets s ORDER BY s.created_at DESC"
        );
    }
    $iconSets     = $isStmt->fetchAll(PDO::FETCH_ASSOC);
    $iconSetCount = count($iconSets);
}

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="mb-0">Media Library</h1>
            <small class="text-muted">Upload, optimize, and manage media assets</small>
        </div>
        <?php if ($activeTab === 'assets'): ?>
        <div>
            <a href="?tab=assets&view=grid<?php echo $typeFilter ? '&type=' . h($typeFilter) : ''; ?><?php echo $searchFilter ? '&search=' . h(urlencode($searchFilter)) : ''; ?>"
               class="btn btn-sm <?php echo $viewMode === 'grid' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                <i data-feather="grid" style="width:14px;height:14px;"></i> Grid
            </a>
            <a href="?tab=assets&view=table<?php echo $typeFilter ? '&type=' . h($typeFilter) : ''; ?><?php echo $searchFilter ? '&search=' . h(urlencode($searchFilter)) : ''; ?>"
               class="btn btn-sm <?php echo $viewMode === 'table' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                <i data-feather="list" style="width:14px;height:14px;"></i> Table
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="mediaLibTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'assets' ? 'active' : ''; ?>"
               href="?tab=assets">
                <i data-feather="image" style="width:14px;height:14px;vertical-align:-2px;"></i>
                Media Assets
                <span class="badge badge-<?php echo $activeTab === 'assets' ? 'light text-dark' : 'secondary'; ?> ml-1"><?php echo $totalAll; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'icon-sets' ? 'active' : ''; ?>"
               href="?tab=icon-sets">
                <i data-feather="layers" style="width:14px;height:14px;vertical-align:-2px;"></i>
                Icon Sets
                <?php if ($iconSetCount > 0): ?>
                <span class="badge badge-<?php echo $activeTab === 'icon-sets' ? 'light text-dark' : 'secondary'; ?> ml-1"><?php echo $iconSetCount; ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <?php if ($activeTab === 'assets'): ?>

    <!-- ════════════════════════════════════════════════════════════
         TAB 1: MEDIA ASSETS
         ════════════════════════════════════════════════════════════ -->

    <!-- Upload Dropzone -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <div class="row mb-2">
                <div class="col-md-3">
                    <label class="small font-weight-bold mb-1">Context</label>
                    <select id="upload-context-type" class="form-control form-control-sm">
                        <option value="marketing_general">Marketing / General</option>
                        <option value="cms">CMS Content</option>
                        <option value="job_visit">Job Visit</option>
                        <option value="quote_visit">Quote Visit</option>
                        <option value="portfolio">Portfolio</option>
                        <option value="internal_general">Internal</option>
                    </select>
                </div>
                <div class="col-md-2" id="upload-context-id-group" style="display:none;">
                    <label class="small font-weight-bold mb-1">Visit/Quote ID</label>
                    <input type="number" id="upload-context-id" class="form-control form-control-sm" placeholder="ID" min="0">
                </div>
                <div class="col-md-2">
                    <label class="small font-weight-bold mb-1">Category</label>
                    <select id="upload-category" class="form-control form-control-sm">
                        <option value="">None</option>
                        <option value="before">Before</option>
                        <option value="during">During</option>
                        <option value="after">After</option>
                        <option value="issue">Issue</option>
                        <option value="access">Access</option>
                        <option value="equipment">Equipment</option>
                        <option value="damage">Damage</option>
                        <option value="hero">Hero/Feature</option>
                        <option value="gallery">Gallery</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small font-weight-bold mb-1">Visibility</label>
                    <select id="upload-visibility" class="form-control form-control-sm">
                        <option value="internal">Internal</option>
                        <option value="client_visible">Client Visible</option>
                        <?php if ($user['role'] === 'admin'): ?>
                        <option value="marketing_eligible">Marketing Eligible</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <?php echo renderMediaDropzone('marketing_general', 0, '', 'internal', [
                'showPowToggle' => true,
                'showGpsToggle' => true,
            ]); ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card" style="border-left: 3px solid #007bff;">
                <div class="card-body py-2 px-3">
                    <div style="font-size: 1.3rem; font-weight: bold; color: #007bff;"><?php echo $totalImages; ?></div>
                    <small class="text-muted">Images</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card" style="border-left: 3px solid #17a2b8;">
                <div class="card-body py-2 px-3">
                    <div style="font-size: 1.3rem; font-weight: bold; color: #17a2b8;"><?php echo $totalVideos; ?></div>
                    <small class="text-muted">Videos</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card" style="border-left: 3px solid #28a745;">
                <div class="card-body py-2 px-3">
                    <div style="font-size: 1.3rem; font-weight: bold; color: #28a745;"><?php echo $totalDocs; ?></div>
                    <small class="text-muted">Documents</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card" style="border-left: 3px solid #6c757d;">
                <div class="card-body py-2 px-3">
                    <div style="font-size: 1.3rem; font-weight: bold; color: #6c757d;"><?php echo $totalAll; ?></div>
                    <small class="text-muted">Total Assets</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <?php echo admin_filter([
        'search' => ['type' => 'text', 'label' => 'Search', 'placeholder' => 'Filename or alt text...'],
        'type'   => ['type' => 'select', 'label' => 'Type', 'options' => [
            'image' => 'Images', 'video' => 'Videos', 'document' => 'Documents',
        ]],
    ], ['search' => $searchFilter, 'type' => $typeFilter]); ?>

    <?php if (empty($media)): ?>
        <?php echo admin_empty_state('No media found', 'Upload media files using the dropzone above', null); ?>
    <?php elseif ($viewMode === 'grid'): ?>
        <!-- Grid View -->
        <div class="mw-media-grid">
            <?php foreach ($media as $m): ?>
                <div class="mw-media-card" data-id="<?php echo (int)($m['id'] ?? 0); ?>">
                    <?php
                    $thumbSrc = $m['thumb_url'] ?: ($m['file_path'] ?? '');
                    if ($m['media_type'] === 'image' && $thumbSrc): ?>
                        <img src="<?php echo h($thumbSrc); ?>"
                             alt="<?php echo h($m['alt_text'] ?? ''); ?>"
                             class="mw-media-card-thumb"
                             loading="lazy">
                    <?php else: ?>
                        <div class="mw-media-card-thumb d-flex align-items-center justify-content-center"
                             style="background:#f0f2f5;">
                            <i data-feather="<?php echo $m['media_type'] === 'video' ? 'film' : 'file-text'; ?>"
                               style="width:32px;height:32px;color:#94a3b8;"></i>
                        </div>
                    <?php endif; ?>
                    <div class="mw-media-card-body">
                        <div class="mw-media-card-name" title="<?php echo h($m['filename']); ?>">
                            <?php echo h($m['filename']); ?>
                        </div>
                        <div class="mw-media-card-meta">
                            <?php if (!empty($m['image_width'])): ?>
                                <span><?php echo (int)$m['image_width']; ?>&times;<?php echo (int)$m['image_height']; ?></span>
                            <?php endif; ?>
                            <span><?php echo h($m['file_size_display']); ?></span>
                            <?php if ($m['variant_count'] > 0): ?>
                                <span title="<?php echo $m['variant_count']; ?> optimized variants"><?php echo $m['variant_count']; ?> variants</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($m['context_display']) && $m['context_display'] !== 'cms'): ?>
                            <div class="mt-1">
                                <span class="mw-context-chip mw-context-chip-<?php echo h($m['context_display']); ?>">
                                    <?php echo h(str_replace('_', ' ', $m['context_display'])); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mw-media-card-actions">
                        <?php if (!empty($m['id'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary mw-copy-responsive"
                                    data-media-id="<?php echo (int)$m['id']; ?>"
                                    title="Copy responsive image snippet">
                                <i data-feather="code" style="width:12px;height:12px;"></i>
                            </button>
                        <?php endif; ?>
                        <a href="/cms/cms-media-editor.php?id=<?php echo (int)($m['id'] ?? 0); ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i data-feather="edit-2" style="width:12px;height:12px;"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger mw-media-delete"
                                data-id="<?php echo (int)($m['id'] ?? 0); ?>">
                            <i data-feather="trash-2" style="width:12px;height:12px;"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <!-- Table View -->
        <?php foreach ($media as &$mt) {
            $mt['type_display'] = ucfirst($mt['file_type'] ?? 'unknown');
        } unset($mt);
        echo admin_table($media, [
            'file_path' => ['label' => '', 'width' => '60px', 'format' => function($val, $row) {
                $type = $row['file_type'] ?? '';
                $thumbSrc = $row['thumb_url'] ?? $val ?? '';
                if ($type === 'image' && !empty($thumbSrc)) {
                    return '<img src="' . h((string)$thumbSrc) . '" alt="' . h((string)($row['alt_text'] ?? '')) . '" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" loading="lazy">';
                } elseif ($type === 'video') {
                    return '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i data-feather="film" style="width:20px;height:20px;color:#64748b;"></i></div>';
                } else {
                    return '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i data-feather="file-text" style="width:20px;height:20px;color:#64748b;"></i></div>';
                }
            }],
            'filename'         => ['label' => 'Filename', 'width' => '25%'],
            'alt_text'         => ['label' => 'Alt Text', 'width' => '18%'],
            'type_display'     => ['label' => 'Type', 'badge' => true, 'badge_variant' => ['Image' => 'primary', 'Video' => 'info', 'Document' => 'secondary'], 'width' => '8%'],
            'file_size_display'=> ['label' => 'Size', 'width' => '8%', 'align' => 'right'],
            'variant_count'    => ['label' => 'Variants', 'width' => '7%', 'align' => 'center', 'format' => function($val) {
                return $val > 0 ? '<span class="badge badge-success">' . (int)$val . '</span>' : '<span class="text-muted">&mdash;</span>';
            }],
            'uploaded_date'    => ['label' => 'Uploaded', 'width' => '10%', 'align' => 'right'],
        ], [
            'row_actions' => [
                ['label' => 'Edit', 'icon' => 'edit-2', 'href' => '/cms/cms-media-editor.php?id={{id}}'],
                ['label' => 'Delete', 'icon' => 'trash-2', 'action' => 'delete', 'confirm' => true],
            ],
            'empty_text' => 'No media found',
            'hover'      => true,
            'striped'    => true,
        ]); ?>
    <?php endif; ?>

    <?php else: ?>

    <!-- ════════════════════════════════════════════════════════════
         TAB 2: ICON SETS
         ════════════════════════════════════════════════════════════ -->

    <?php if (!$hasIconSetsTable): ?>
        <div class="alert alert-warning">
            <i data-feather="alert-triangle" style="width:16px;height:16px;vertical-align:-2px;"></i>
            The <code>icon_sets</code> table hasn't been created yet.
            <a href="/crm/api/run-migration-910.php" class="alert-link">Run migration 910</a> to enable icon sets.
        </div>
    <?php else: ?>

    <!-- Upload new icon set card -->
    <div class="card mb-4">
        <div class="card-header py-2 px-3">
            <strong><i data-feather="upload-cloud" style="width:15px;height:15px;vertical-align:-2px;"></i> Upload New Icon Set</strong>
        </div>
        <div class="card-body p-3">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label class="small font-weight-bold mb-1">Icon Set Name <span class="text-danger">*</span></label>
                    <input type="text" id="newIconSetName" class="form-control form-control-sm"
                           placeholder="e.g. Lawn Mowing, Hedge Trimming…" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="small font-weight-bold mb-1">Source Image (JPG, PNG, WEBP · max 5 MB)</label>
                    <div class="d-flex align-items-center" style="gap:0.5rem;">
                        <input type="file" id="newIconSetFile" accept=".jpg,.jpeg,.png,.webp" class="form-control-file form-control-sm" style="flex:1;">
                        <button type="button" class="btn btn-sm btn-success" id="uploadIconSetBtn" onclick="uploadNewIconSet()">
                            <i data-feather="zap" style="width:13px;height:13px;"></i> Generate
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div id="iconSetUploadStatus" class="small mt-1"></div>
                </div>
            </div>
            <small class="form-text text-muted mt-2">
                Generates 7 sizes (32–1024 px) in two variants: <strong>Default</strong> (greyscale) &amp; <strong>Active</strong> (full colour — shown when purchased). Use on products, website, stop cards, quotes &amp; more.
            </small>
        </div>
    </div>

    <!-- Icon sets grid -->
    <?php if (empty($iconSets)): ?>
        <?php echo admin_empty_state(
            'No icon sets yet',
            'Upload an icon set above to get started. Once created, you can assign it to any product.',
            null
        ); ?>
    <?php else: ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><?php echo $iconSetCount; ?> Icon Set<?php echo $iconSetCount !== 1 ? 's' : ''; ?></h5>
    </div>

    <div class="mw-icon-set-grid" id="iconSetGrid">
        <?php foreach ($iconSets as $set): ?>
            <?php
            $basePath   = rtrim(h($set['icon_base_path']), '/') . '/';
            $setId      = (int)$set['id'];
            $setName    = h($set['name']);
            $prodCount  = (int)($set['product_count'] ?? 0);
            $cb         = '?v=' . strtotime($set['updated_at'] ?? $set['created_at'] ?? 'now');
            ?>
            <div class="mw-icon-set-card" data-id="<?php echo $setId; ?>" id="icon-set-card-<?php echo $setId; ?>">
                <div class="mw-icon-set-previews">
                    <img src="<?php echo $basePath; ?>icon_128_unsold.png<?php echo $cb; ?>"
                         alt="<?php echo $setName; ?>" class="mw-icon-set-hero" loading="lazy">
                    <div class="mw-icon-set-color-badge" title="Colored when purchased">
                        <img src="<?php echo $basePath; ?>icon_64_sold.png<?php echo $cb; ?>"
                             alt="Active variant" loading="lazy">
                    </div>
                </div>
                <div class="mw-icon-set-card-body">
                    <div class="mw-icon-set-name" id="icon-set-name-<?php echo $setId; ?>" title="<?php echo $setName; ?>">
                        <?php echo $setName; ?>
                    </div>
                    <span class="mw-icon-set-badge <?php echo $prodCount > 0 ? 'mw-icon-set-badge--used' : 'mw-icon-set-badge--unused'; ?>">
                        <?php echo $prodCount > 0
                            ? $prodCount . ' product' . ($prodCount !== 1 ? 's' : '')
                            : 'Not assigned'; ?>
                    </span>
                </div>
                <div class="mw-icon-set-card-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill"
                            onclick="renameIconSet(<?php echo $setId; ?>, <?php echo json_encode($set['name']); ?>)"
                            title="Rename">
                        <i data-feather="edit-2" style="width:12px;height:12px;"></i> Rename
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick="deleteIconSet(<?php echo $setId; ?>, <?php echo json_encode($set['name']); ?>)"
                            title="Delete icon set">
                        <i data-feather="trash-2" style="width:12px;height:12px;"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php endif; // empty iconSets ?>
    <?php endif; // hasIconSetsTable ?>

    <?php endif; // activeTab ?>

</div><!-- /.container-fluid -->

<!-- Hidden CSRF token for JS -->
<input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo generateCSRFToken(); ?>">

<script>
(function () {
    'use strict';

    function getCsrf() {
        return (document.getElementById('csrf_token') || {}).value || '';
    }

    // ── Media Assets tab ─────────────────────────────────────────────────────

    var contextSelect  = document.getElementById('upload-context-type');
    var contextIdGroup = document.getElementById('upload-context-id-group');
    var contextIdInput = document.getElementById('upload-context-id');
    var categorySelect = document.getElementById('upload-category');
    var visibilitySelect = document.getElementById('upload-visibility');
    var dropzone = document.querySelector('.mw-media-dropzone');

    if (contextSelect && dropzone) {
        contextSelect.addEventListener('change', function () {
            var val = this.value;
            if (contextIdGroup) {
                contextIdGroup.style.display = (val === 'job_visit' || val === 'quote_visit') ? '' : 'none';
            }
            dropzone.dataset.contextType = val;
            var powLabel = dropzone.querySelector('.mw-dropzone-pow-toggle');
            if (powLabel) {
                powLabel.closest('label').style.display =
                    (val === 'job_visit' || val === 'quote_visit') ? '' : 'none';
            }
        });
    }
    if (contextIdInput && dropzone) {
        contextIdInput.addEventListener('input', function () { dropzone.dataset.contextId = this.value || '0'; });
    }
    if (categorySelect && dropzone) {
        categorySelect.addEventListener('change', function () { dropzone.dataset.category = this.value; });
    }
    if (visibilitySelect && dropzone) {
        visibilitySelect.addEventListener('change', function () { dropzone.dataset.visibility = this.value; });
    }
    if (dropzone) {
        dropzone.addEventListener('allUploadsComplete', function (e) {
            if (e.detail && e.detail.succeeded > 0) {
                setTimeout(function () { location.reload(); }, 1500);
            }
        });
    }

    // Delete media (grid + table)
    document.querySelectorAll('.mw-media-delete').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!confirm('Delete this media asset?')) return;
            deleteMedia(this.dataset.id, this.closest('.mw-media-card'));
        });
    });
    document.querySelectorAll('[data-action="delete"]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!confirm('Delete this media asset?')) return;
            var row = this.closest('tr');
            if (row && row.dataset.id) deleteMedia(row.dataset.id, row);
        });
    });

    function deleteMedia(mediaId, element) {
        fetch('/crm/api/delete-media.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ id: mediaId })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { if (element) element.remove(); }
            else alert('Error: ' + (data.error || 'Delete failed'));
        })
        .catch(function (err) { alert('Error: ' + err.message); });
    }

    // Copy responsive snippet
    document.querySelectorAll('.mw-copy-responsive').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var mediaId = this.dataset.mediaId;
            var snippet = '<?php echo "<?"; ?>php echo renderResponsiveImage(' + mediaId + '); ?>';
            var b = btn;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(snippet).then(function () {
                    b.innerHTML = '<i data-feather="check" style="width:12px;height:12px;"></i>';
                    if (typeof feather !== 'undefined') feather.replace();
                    setTimeout(function () {
                        b.innerHTML = '<i data-feather="code" style="width:12px;height:12px;"></i>';
                        if (typeof feather !== 'undefined') feather.replace();
                    }, 2000);
                });
            } else {
                prompt('Copy this snippet:', snippet);
            }
        });
    });

    // ── Icon Sets tab ─────────────────────────────────────────────────────────

    // Upload new icon set
    window.uploadNewIconSet = function () {
        var name     = (document.getElementById('newIconSetName') || {}).value || '';
        var fileInput = document.getElementById('newIconSetFile');
        var statusEl = document.getElementById('iconSetUploadStatus');
        var btn      = document.getElementById('uploadIconSetBtn');

        if (!name.trim()) {
            if (statusEl) { statusEl.textContent = 'Please enter a name for this icon set.'; statusEl.style.color = '#dc2626'; }
            document.getElementById('newIconSetName').focus();
            return;
        }
        if (!fileInput || !fileInput.files[0]) {
            if (statusEl) { statusEl.textContent = 'Please select an image file.'; statusEl.style.color = '#dc2626'; }
            return;
        }

        var file = fileInput.files[0];
        if (file.size > 5 * 1024 * 1024) {
            if (statusEl) { statusEl.textContent = 'File exceeds 5 MB limit.'; statusEl.style.color = '#dc2626'; }
            return;
        }

        if (statusEl) { statusEl.textContent = 'Generating icon set…'; statusEl.style.color = '#6c757d'; }
        if (btn) btn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('name', name.trim());
        fd.append('file', file);
        fd.append('csrf_token', getCsrf());

        fetch('/crm/api/icon-sets.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (statusEl) { statusEl.textContent = '✓ Icon set created!'; statusEl.style.color = '#166534'; }
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    if (statusEl) { statusEl.textContent = 'Error: ' + (data.error || 'Unknown error'); statusEl.style.color = '#dc2626'; }
                }
            })
            .catch(function (err) {
                if (statusEl) { statusEl.textContent = 'Upload failed: ' + err.message; statusEl.style.color = '#dc2626'; }
            })
            .finally(function () {
                if (btn) btn.disabled = false;
            });
    };

    // Rename icon set
    window.renameIconSet = function (id, currentName) {
        var newName = prompt('Rename icon set:', currentName);
        if (!newName || !newName.trim() || newName.trim() === currentName) return;
        newName = newName.trim();

        var params = new URLSearchParams({ action: 'rename', id: id, name: newName, csrf_token: getCsrf() });
        fetch('/crm/api/icon-sets.php', { method: 'POST', body: params })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var nameEl = document.getElementById('icon-set-name-' + id);
                    if (nameEl) { nameEl.textContent = newName; nameEl.title = newName; }
                } else {
                    alert('Error: ' + (data.error || 'Rename failed'));
                }
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    };

    // Delete icon set
    window.deleteIconSet = function (id, name) {
        if (!confirm('Delete icon set "' + name + '"?\n\nThis will remove all generated files and unassign it from any products.')) return;

        var params = new URLSearchParams({ action: 'delete', id: id, csrf_token: getCsrf() });
        fetch('/crm/api/icon-sets.php', { method: 'POST', body: params })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var card = document.getElementById('icon-set-card-' + id);
                    if (card) card.remove();
                } else {
                    alert('Error: ' + (data.error || 'Delete failed'));
                }
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    };

}());
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
