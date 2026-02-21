<?php
/**
 * CMS Media Editor
 *
 * Edit individual media asset metadata
 * Uses AppStack layout
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

$pageTitle = 'Edit Media';
$activePage = 'cms';
$mediaId = (int)($_GET['id'] ?? 0);

// Load media
$media = null;
if ($mediaId) {
    $media = cms_getMediaAssetById($mediaId);
    if (!$media) {
        http_response_code(404);
        die('Media not found');
    }
}

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
    <div class="row">
        <div class="col-lg-8">
            <?php echo admin_breadcrumbs([
                ['label' => 'CMS', 'url' => '/crm/cms-pages_appstack.php'],
                ['label' => 'Media Library', 'url' => '/cms/cms-media_appstack.php'],
                ['label' => 'Edit Media'],
            ]); ?>

            <h1>Edit Media</h1>

            <div id="save-alert" class="alert d-none" role="alert"></div>

            <!-- Media Editor Form -->
            <form id="media-form" method="POST" action="/crm/api/save-media.php" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="id" value="<?php echo $mediaId; ?>">

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Media Information</h5>
                    </div>
                    <div class="card-body">
                        <!-- File Preview -->
                        <?php if ($media && ($media['file_type'] ?? '') === 'image'): ?>
                            <div class="mb-4">
                                <img src="<?php echo h((string)($media['file_path'] ?? '')); ?>" alt="<?php echo h((string)($media['alt_text'] ?? '')); ?>"
                                     class="img-thumbnail" style="max-width: 100%; max-height: 400px;">
                            </div>
                        <?php elseif ($media && ($media['file_type'] ?? '') === 'video'): ?>
                            <div class="mb-4">
                                <video width="100%" height="auto" controls style="max-height: 400px;">
                                    <source src="<?php echo h((string)($media['file_path'] ?? '')); ?>" type="video/mp4">
                                    Your browser does not support the video tag.
                                </video>
                            </div>
                        <?php endif; ?>

                        <!-- Filename -->
                        <div class="form-group">
                            <label for="filename">Filename</label>
                            <input type="text" class="form-control" id="filename" readonly
                                   value="<?php echo h((string)($media['original_filename'] ?? $media['stored_filename'] ?? '')); ?>">
                        </div>

                        <!-- Alt Text -->
                        <div class="form-group">
                            <label for="alt_text">Alt Text <span class="text-danger">*</span> <small class="text-muted">(Accessibility &amp; SEO)</small></label>
                            <textarea class="form-control" id="alt_text" name="alt_text" rows="2"
                                      placeholder="Describe the image for screen readers and SEO"><?php echo h((string)($media['alt_text'] ?? '')); ?></textarea>
                            <small class="form-text text-muted">Used by screen readers and if image fails to load. Keep under 125 characters.</small>
                        </div>

                        <!-- Caption -->
                        <div class="form-group">
                            <label for="caption">Caption <small class="text-muted">(optional — shown under image on site)</small></label>
                            <input type="text" class="form-control" id="caption" name="caption" maxlength="255"
                                   value="<?php echo h((string)($media['caption'] ?? '')); ?>"
                                   placeholder="Short visible caption">
                        </div>

                        <!-- Description / internal notes -->
                        <div class="form-group">
                            <label for="description">Internal Notes <small class="text-muted">(not shown publicly)</small></label>
                            <textarea class="form-control" id="description" name="description" rows="2"
                                      placeholder="Internal notes about this asset"><?php echo h((string)($media['description'] ?? '')); ?></textarea>
                        </div>

                        <!-- File Info -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="media_type">File Type</label>
                                    <input type="text" class="form-control" id="media_type" readonly
                                           value="<?php echo h(ucfirst((string)($media['file_type'] ?? ''))); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="file_size">File Size</label>
                                    <input type="text" class="form-control" id="file_size" readonly
                                           value="<?php echo round(($media['file_size'] ?? 0) / 1024, 1); ?> KB">
                                </div>
                            </div>
                        </div>

                        <!-- Upload Date -->
                        <div class="form-group">
                            <label for="created_at">Uploaded</label>
                            <input type="text" class="form-control" id="created_at" readonly
                                   value="<?php echo date('M d, Y g:i A', strtotime($media['created_at'] ?? 'now')); ?>">
                        </div>

                        <?php if (!empty($media['image_width']) && !empty($media['image_height'])): ?>
                        <div class="form-group">
                            <label for="dimensions">Dimensions</label>
                            <input type="text" class="form-control" id="dimensions" readonly
                                   value="<?php echo (int)$media['image_width']; ?> × <?php echo (int)$media['image_height']; ?> px">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>
                    <a href="/cms/cms-media_appstack.php" class="btn btn-secondary btn-lg">Back to Library</a>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Usage</h5>
                </div>
                <div class="card-body small">
                    <p class="text-muted">This media is used in:</p>
                    <div id="mediaUsage" style="max-height: 300px; overflow-y: auto;">
                        <p class="text-muted text-center py-3">Loading usage data...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Save form via AJAX
    const form = document.getElementById('media-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const alertEl = document.getElementById('save-alert');

            fetch('/crm/api/save-media.php', {
                method: 'POST',
                body: formData,
            })
            .then(r => r.json())
            .then(data => {
                alertEl.classList.remove('d-none', 'alert-danger', 'alert-success');
                if (data.success) {
                    alertEl.classList.add('alert-success');
                    alertEl.textContent = 'Media updated successfully';
                } else {
                    alertEl.classList.add('alert-danger');
                    alertEl.textContent = 'Error: ' + (data.error || 'Unknown error');
                }
                window.scrollTo(0, 0);
            })
            .catch(err => {
                alertEl.classList.remove('d-none', 'alert-success');
                alertEl.classList.add('alert-danger');
                alertEl.textContent = 'Error: ' + err.message;
                window.scrollTo(0, 0);
            });
        });
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // Load media usage data via AJAX
    const mediaId = <?php echo $mediaId; ?>;
    if (mediaId) {
        fetch('/crm/api/get-media-usage.php?id=' + mediaId)
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('mediaUsage');
                if (data.usage && data.usage.length > 0) {
                    let html = '<ul class="list-unstyled">';
                    data.usage.forEach(item => {
                        html += '<li><a href="' + escapeHtml(item.url) + '" target="_blank">' + escapeHtml(item.name) + '</a></li>';
                    });
                    html += '</ul>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p class="text-muted text-center py-3">Not used on any pages</p>';
                }
            })
            .catch(err => {
                document.getElementById('mediaUsage').innerHTML = '<p class="text-danger text-center py-3">Error loading usage data</p>';
            });
    }
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
