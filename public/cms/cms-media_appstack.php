<?php
/**
 * CMS Media Library — Unified Upload + Variants + Context Linking
 *
 * Upload once → optimized variants → context linking → proof-of-work stamps.
 * Supports drag-and-drop, multi-file, mobile camera capture, browser GPS.
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

// Get filter values
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$viewMode = $_GET['view'] ?? 'grid';

// Fetch media assets
$allMedia = cms_getMediaAssets();

// Apply filters
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

// Sort by date (newest first)
usort($media, fn($a, $b) => strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0'));

// Enrich media items
$db = getDB();

// Check if new columns exist (graceful degradation if migration not run yet)
$hasNewCols = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM media_assets LIKE 'uuid'");
    $hasNewCols = $colCheck && $colCheck->rowCount() > 0;
} catch (Exception $e) {
    // Columns don't exist yet — that's fine
}

// Check if media_variants table exists
$hasVariantsTable = false;
try {
    $tblCheck = $db->query("SHOW TABLES LIKE 'media_variants'");
    $hasVariantsTable = $tblCheck && $tblCheck->rowCount() > 0;
} catch (Exception $e) {
    // Table doesn't exist yet
}

// Build variant maps in ONE query (eliminates the N+1)
$variantThumbMap = [];   // media_id => thumb file_path
$variantCountMap = [];   // media_id => total variant count
if ($hasVariantsTable && !empty($media)) {
    $mediaIds = array_filter(array_column($media, 'id'));
    if ($mediaIds) {
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));

        // Thumbnails — one row per media_id (the thumb_square jpeg)
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

        // Counts — one row per media_id
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
    $m['filename'] = $m['original_filename'] ?? $m['stored_filename'] ?? '';
    $m['media_type'] = $m['file_type'] ?? 'image';
    $m['uploaded_date'] = !empty($m['created_at']) ? date('M d, Y', strtotime($m['created_at'])) : '';
    $m['file_size_display'] = formatMediaBytes((int)($m['file_size'] ?? 0));

    $mid = (int)($m['id'] ?? 0);
    $m['thumb_url'] = $variantThumbMap[$mid] ?? null;
    $m['variant_count'] = $variantCountMap[$mid] ?? 0;
    $m['context_display'] = $hasNewCols ? ($m['context_type'] ?? 'cms') : 'cms';
}
unset($m);

// Stats
$totalImages = count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'image'));
$totalVideos = count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'video'));
$totalDocs = count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'document'));
$totalAll = count($allMedia);

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0">Media Library</h1>
            <small class="text-muted">Upload, optimize, and manage media assets</small>
        </div>
        <div>
            <a href="?view=grid<?php echo $typeFilter ? '&type=' . h($typeFilter) : ''; ?><?php echo $searchFilter ? '&search=' . h(urlencode($searchFilter)) : ''; ?>"
               class="btn btn-sm <?php echo $viewMode === 'grid' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                <i data-feather="grid" style="width:14px;height:14px;"></i> Grid
            </a>
            <a href="?view=table<?php echo $typeFilter ? '&type=' . h($typeFilter) : ''; ?><?php echo $searchFilter ? '&search=' . h(urlencode($searchFilter)) : ''; ?>"
               class="btn btn-sm <?php echo $viewMode === 'table' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                <i data-feather="list" style="width:14px;height:14px;"></i> Table
            </a>
        </div>
    </div>

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
        'search' => [
            'type' => 'text',
            'label' => 'Search',
            'placeholder' => 'Filename or alt text...',
        ],
        'type' => [
            'type' => 'select',
            'label' => 'Type',
            'options' => [
                'image' => 'Images',
                'video' => 'Videos',
                'document' => 'Documents',
            ],
        ],
    ], [
        'search' => $searchFilter,
        'type' => $typeFilter,
    ]); ?>

    <?php if (empty($media)): ?>
        <?php echo admin_empty_state(
            'No media found',
            'Upload media files using the dropzone above',
            null
        ); ?>
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
        <?php
        foreach ($media as &$mt) {
            $mt['type_display'] = ucfirst($mt['file_type'] ?? 'unknown');
        }
        unset($mt);

        echo admin_table($media, [
            'file_path' => [
                'label' => '',
                'width' => '60px',
                'format' => function($val, $row) {
                    $type = $row['file_type'] ?? '';
                    $thumbSrc = $row['thumb_url'] ?? $val ?? '';
                    if ($type === 'image' && !empty($thumbSrc)) {
                        return '<img src="' . h((string)$thumbSrc) . '" alt="' . h((string)($row['alt_text'] ?? '')) . '" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" loading="lazy">';
                    } elseif ($type === 'video') {
                        return '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i data-feather="film" style="width:20px;height:20px;color:#64748b;"></i></div>';
                    } else {
                        return '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i data-feather="file-text" style="width:20px;height:20px;color:#64748b;"></i></div>';
                    }
                },
            ],
            'filename' => [
                'label' => 'Filename',
                'width' => '25%',
            ],
            'alt_text' => [
                'label' => 'Alt Text',
                'width' => '18%',
            ],
            'type_display' => [
                'label' => 'Type',
                'badge' => true,
                'badge_variant' => ['Image' => 'primary', 'Video' => 'info', 'Document' => 'secondary'],
                'width' => '8%',
            ],
            'file_size_display' => [
                'label' => 'Size',
                'width' => '8%',
                'align' => 'right',
            ],
            'variant_count' => [
                'label' => 'Variants',
                'width' => '7%',
                'align' => 'center',
                'format' => function($val) {
                    return $val > 0 ? '<span class="badge badge-success">' . (int)$val . '</span>' : '<span class="text-muted">&mdash;</span>';
                },
            ],
            'uploaded_date' => [
                'label' => 'Uploaded',
                'width' => '10%',
                'align' => 'right',
            ],
        ], [
            'row_actions' => [
                ['label' => 'Edit', 'icon' => 'edit-2', 'href' => '/cms/cms-media-editor.php?id={{id}}'],
                ['label' => 'Delete', 'icon' => 'trash-2', 'action' => 'delete', 'confirm' => true],
            ],
            'empty_text' => 'No media found',
            'hover' => true,
            'striped' => true,
        ]); ?>
    <?php endif; ?>
</div>

<!-- Hidden CSRF token for JS -->
<input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo generateCSRFToken(); ?>">

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Dynamic context fields ---
    var contextSelect = document.getElementById('upload-context-type');
    var contextIdGroup = document.getElementById('upload-context-id-group');
    var contextIdInput = document.getElementById('upload-context-id');
    var categorySelect = document.getElementById('upload-category');
    var visibilitySelect = document.getElementById('upload-visibility');
    var dropzone = document.querySelector('.mw-media-dropzone');

    if (contextSelect && dropzone) {
        contextSelect.addEventListener('change', function() {
            var val = this.value;
            // Show/hide context ID field for visit types
            if (contextIdGroup) {
                contextIdGroup.style.display = (val === 'job_visit' || val === 'quote_visit') ? '' : 'none';
            }
            // Update dropzone data attributes
            dropzone.dataset.contextType = val;

            // Show/hide PoW toggle
            var powLabel = dropzone.querySelector('.mw-dropzone-pow-toggle');
            if (powLabel) {
                powLabel.closest('label').style.display =
                    (val === 'job_visit' || val === 'quote_visit') ? '' : 'none';
            }
        });
    }

    if (contextIdInput && dropzone) {
        contextIdInput.addEventListener('input', function() {
            dropzone.dataset.contextId = this.value || '0';
        });
    }

    if (categorySelect && dropzone) {
        categorySelect.addEventListener('change', function() {
            dropzone.dataset.category = this.value;
        });
    }

    if (visibilitySelect && dropzone) {
        visibilitySelect.addEventListener('change', function() {
            dropzone.dataset.visibility = this.value;
        });
    }

    // --- Reload page when uploads complete ---
    if (dropzone) {
        dropzone.addEventListener('allUploadsComplete', function(e) {
            if (e.detail && e.detail.succeeded > 0) {
                setTimeout(function() { location.reload(); }, 1500);
            }
        });
    }

    // --- Delete handlers ---
    // Grid delete buttons
    document.querySelectorAll('.mw-media-delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Delete this media asset?')) return;
            deleteMedia(this.dataset.id, this.closest('.mw-media-card'));
        });
    });

    // Table delete buttons (from admin_table)
    document.querySelectorAll('[data-action="delete"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Delete this media asset?')) return;
            var row = this.closest('tr');
            if (row && row.dataset.id) deleteMedia(row.dataset.id, row);
        });
    });

    function deleteMedia(mediaId, element) {
        var csrf = document.getElementById('csrf_token');
        fetch('/crm/api/delete-media.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf ? csrf.value : ''
            },
            body: JSON.stringify({ id: mediaId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (element) element.remove();
            } else {
                alert('Error: ' + (data.error || 'Delete failed'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
    }

    // --- Copy responsive HTML snippet ---
    document.querySelectorAll('.mw-copy-responsive').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var mediaId = this.dataset.mediaId;
            var snippet = '<?php echo "<?"; ?>php echo renderResponsiveImage(' + mediaId + '); ?>';
            if (navigator.clipboard) {
                navigator.clipboard.writeText(snippet).then(function() {
                    btn.innerHTML = '<i data-feather="check" style="width:12px;height:12px;"></i>';
                    if (typeof feather !== 'undefined') feather.replace();
                    setTimeout(function() {
                        btn.innerHTML = '<i data-feather="code" style="width:12px;height:12px;"></i>';
                        if (typeof feather !== 'undefined') feather.replace();
                    }, 2000);
                });
            } else {
                prompt('Copy this snippet:', snippet);
            }
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
