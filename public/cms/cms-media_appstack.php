<?php
/**
 * CMS Media Library
 *
 * Manage all media assets: upload, organize, delete, bulk actions
 * Uses AppStack layout with admin UI kit components
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/crm/includes/cms-functions.php';
require_once dirname(__DIR__) . '/crm/includes/admin-ui-kit.php';

requireLogin();
$user = getCurrentUser();

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    die('Access denied');
}

$pageTitle = 'Media Library';
$activePage = 'media';

// Get filter values
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);

// Fetch media assets
$allMedia = cms_getMediaAssets();

// Apply filters
$media = $allMedia;
if ($typeFilter) {
    $media = array_filter($media, fn($m) => $m['file_type'] === $typeFilter);
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

// Prepare for table rendering
foreach ($media as &$m) {
    // Map to display-friendly keys used by the table template
    $m['filename'] = $m['original_filename'] ?? $m['stored_filename'] ?? '';
    $m['media_type'] = $m['file_type'] ?? 'image';
    $m['type_badge'] = match($m['file_type'] ?? '') {
        'image' => 'primary',
        'video' => 'info',
        'document' => 'secondary',
        default => 'secondary',
    };
    $m['type_display'] = ucfirst($m['file_type'] ?? 'unknown');
    $m['uploaded_date'] = !empty($m['created_at']) ? date('M d, Y', strtotime($m['created_at'])) : '';
    $m['file_size_kb'] = round(($m['file_size'] ?? 0) / 1024, 1);
}

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0">Media Library</h1>
            <small class="text-muted">Manage images, videos, and documents</small>
        </div>
        <button class="btn btn-primary" data-toggle="modal" data-target="#uploadModal">
            <i data-feather="upload"></i> Upload Media
        </button>
    </div>

    <!-- Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left border-primary">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #007bff;">
                        <?php echo count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'image')); ?>
                    </div>
                    <small class="text-muted">Images</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left border-info">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #17a2b8;">
                        <?php echo count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'video')); ?>
                    </div>
                    <small class="text-muted">Videos</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left border-success">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #28a745;">
                        <?php echo count(array_filter($allMedia, fn($m) => ($m['file_type'] ?? '') === 'document')); ?>
                    </div>
                    <small class="text-muted">Documents</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left border-secondary">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #6c757d;">
                        <?php echo count($allMedia); ?>
                    </div>
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

    <!-- Media Table -->
    <?php if (empty($media)): ?>
        <?php echo admin_empty_state(
            'No media found',
            'Upload media files to get started',
            ['url' => '#', 'label' => 'Upload Media', 'onclick' => 'jQuery("#uploadModal").modal("show")']
        ); ?>
    <?php else: ?>
        <?php echo admin_table($media, [
            'file_path' => [
                'label' => '',
                'width' => '60px',
                'format' => function($val, $row) {
                    $type = $row['file_type'] ?? '';
                    if ($type === 'image' && !empty($val)) {
                        return '<img src="' . h((string)$val) . '" alt="' . h((string)($row['alt_text'] ?? '')) . '" style="width:48px;height:48px;object-fit:cover;border-radius:4px;">';
                    } elseif ($type === 'video') {
                        return '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i data-feather="film" style="width:20px;height:20px;color:#64748b;"></i></div>';
                    } else {
                        return '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i data-feather="file-text" style="width:20px;height:20px;color:#64748b;"></i></div>';
                    }
                },
            ],
            'filename' => [
                'label' => 'Filename',
                'width' => '28%',
            ],
            'alt_text' => [
                'label' => 'Alt Text',
                'width' => '22%',
            ],
            'type_display' => [
                'label' => 'Type',
                'badge' => true,
                'badge_variant' => ['Image' => 'primary', 'Video' => 'info', 'Document' => 'secondary'],
                'width' => '10%',
            ],
            'file_size_kb' => [
                'label' => 'Size (KB)',
                'width' => '10%',
                'align' => 'right',
            ],
            'uploaded_date' => [
                'label' => 'Uploaded',
                'width' => '12%',
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

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload Media</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="upload-form" method="POST" action="/crm/api/upload-media.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-group">
                        <label for="media_file">Select File</label>
                        <input type="file" class="form-control-file" id="media_file" name="media_file" required>
                        <small class="form-text text-muted">Images: JPG, PNG, GIF (max 5MB) | Videos: MP4, WebM (max 50MB) | Documents: PDF, DOCX (max 10MB)</small>
                    </div>

                    <div class="form-group">
                        <label for="alt_text">Alt Text</label>
                        <input type="text" class="form-control" id="alt_text" name="alt_text" placeholder="Description for accessibility">
                    </div>

                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Delete action handler
    document.querySelectorAll('[data-action="delete"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this media?')) return;

            const row = this.closest('tr');
            const mediaId = row.dataset.id;

            fetch('/crm/api/delete-media.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('[name="csrf_token"]')?.value || '',
                },
                body: JSON.stringify({ id: mediaId }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    row.remove();
                    alert('Media deleted successfully');
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
        });
    });

    // Upload form handler
    const uploadForm = document.getElementById('upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch('/crm/api/upload-media.php', {
                method: 'POST',
                body: formData,
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Media uploaded successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
