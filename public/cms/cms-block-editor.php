<?php
/**
 * CMS Block Editor
 *
 * Edit individual block content and configuration
 * Uses AppStack layout with dynamic form generation based on block type
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

$pageTitle = 'Block Editor';
$activePage = 'cms';
$blockId = (int)($_GET['block_id'] ?? 0);

// Load block if editing
$block = null;
$page = null;
if ($blockId) {
    $block = cms_getBlockById($blockId);
    if (!$block) {
        http_response_code(404);
        die('Block not found');
    }
    $page = cms_getPageById($block['page_id']);
}

// Block type templates with form field definitions
$blockFieldTemplates = [
    'hero' => [
        'headline' => ['type' => 'text', 'label' => 'Headline', 'required' => true],
        'subheadline' => ['type' => 'textarea', 'label' => 'Subheadline', 'rows' => 2],
        'cta_text' => ['type' => 'text', 'label' => 'CTA Button Text'],
        'cta_url' => ['type' => 'text', 'label' => 'CTA Button URL'],
        'media_id' => ['type' => 'media', 'label' => 'Hero Image'],
        'media_alt' => ['type' => 'text', 'label' => 'Image Alt Text'],
    ],
    'feature_grid' => [
        'title' => ['type' => 'text', 'label' => 'Section Title'],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2],
        'layout' => ['type' => 'select', 'label' => 'Columns', 'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
        'features' => ['type' => 'repeatable', 'label' => 'Features', 'itemType' => 'feature', 'fields' => [
            'title' => ['type' => 'text', 'label' => 'Title'],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2],
        ]],
    ],
    'cta' => [
        'headline' => ['type' => 'text', 'label' => 'Headline', 'required' => true],
        'subheadline' => ['type' => 'textarea', 'label' => 'Subheadline', 'rows' => 2],
        'primary_text' => ['type' => 'text', 'label' => 'Primary Button Text'],
        'primary_url' => ['type' => 'text', 'label' => 'Primary Button URL'],
        'secondary_text' => ['type' => 'text', 'label' => 'Secondary Button Text'],
        'secondary_url' => ['type' => 'text', 'label' => 'Secondary Button URL'],
        'style' => ['type' => 'select', 'label' => 'Style', 'options' => ['gradient' => 'Green Gradient', 'dark' => 'Dark', 'light' => 'Light']],
    ],
    'faq' => [
        'title' => ['type' => 'text', 'label' => 'Section Title'],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2],
        'faqs' => ['type' => 'repeatable', 'label' => 'FAQs', 'itemType' => 'faq', 'fields' => [
            'question' => ['type' => 'text', 'label' => 'Question'],
            'answer' => ['type' => 'textarea', 'label' => 'Answer', 'rows' => 3],
        ]],
    ],
    'gallery' => [
        'title' => ['type' => 'text', 'label' => 'Section Title'],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2],
        'layout' => ['type' => 'select', 'label' => 'Layout', 'options' => ['grid' => 'Grid', 'carousel' => 'Carousel']],
        'columns' => ['type' => 'select', 'label' => 'Columns', 'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
        'images' => ['type' => 'repeatable', 'label' => 'Gallery Images', 'itemType' => 'image', 'fields' => [
            'media_id' => ['type' => 'media', 'label' => 'Image'],
            'caption' => ['type' => 'text', 'label' => 'Caption'],
            'alt_override' => ['type' => 'text', 'label' => 'Alt Text (optional override)'],
        ]],
    ],
    'rich_text' => [
        'title' => ['type' => 'text', 'label' => 'Section Title'],
        'content' => ['type' => 'html', 'label' => 'Content (HTML)'],
    ],
    'testimonials' => [
        'title' => ['type' => 'text', 'label' => 'Section Title'],
        'layout' => ['type' => 'select', 'label' => 'Layout', 'options' => ['grid' => 'Grid', 'carousel' => 'Carousel']],
        'testimonials' => ['type' => 'repeatable', 'label' => 'Testimonials', 'itemType' => 'testimonial', 'fields' => [
            'name' => ['type' => 'text', 'label' => 'Name'],
            'quote' => ['type' => 'textarea', 'label' => 'Quote', 'rows' => 3],
            'role' => ['type' => 'text', 'label' => 'Role/Company (optional)'],
        ]],
    ],
    'service_cards' => [
        'title' => ['type' => 'text', 'label' => 'Section Title'],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2],
        'columns' => ['type' => 'select', 'label' => 'Columns', 'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
        'services' => ['type' => 'repeatable', 'label' => 'Services', 'itemType' => 'service', 'fields' => [
            'title' => ['type' => 'text', 'label' => 'Service Title'],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2],
        ]],
    ],
    'custom' => [
        'html_content' => ['type' => 'html', 'label' => 'HTML Content'],
    ],
];

$fields = $blockFieldTemplates[$block['block_type'] ?? ''] ?? [];

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<style>
.repeatable-item {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 10px;
    position: relative;
}

.repeatable-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
}

.repeatable-item-controls {
    display: flex;
    gap: 5px;
}

.repeatable-item-controls button {
    padding: 3px 10px;
    font-size: 12px;
}

.media-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    max-height: 500px;
    overflow-y: auto;
}

.media-picker-item {
    cursor: pointer;
    border: 2px solid transparent;
    border-radius: 4px;
    padding: 8px;
    text-align: center;
    transition: all 0.2s;
}

.media-picker-item:hover,
.media-picker-item.selected {
    border-color: #007bff;
    background-color: #e7f1ff;
}

.media-picker-thumb {
    width: 100%;
    height: 100px;
    object-fit: cover;
    border-radius: 3px;
    margin-bottom: 5px;
}

.media-picker-filename {
    font-size: 11px;
    color: #666;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.repeatable-add-btn {
    margin-top: 10px;
}
</style>

<div class="container-fluid p-4">
    <div class="row">
        <div class="col-lg-8">
            <?php echo admin_breadcrumbs([
                ['label' => 'CMS', 'url' => '/crm/cms-pages_appstack.php'],
                ['label' => 'Pages', 'url' => '/crm/cms-pages_appstack.php'],
                ['label' => $page ? $page['title'] : 'Edit Page', 'url' => $page ? '/crm/cms-page-editor.php?id=' . $page['id'] : '#'],
                ['label' => 'Edit Block'],
            ]); ?>

            <h1>Edit Block</h1>

            <!-- Block Editor Form -->
            <form method="POST" action="/crm/api/save-block.php" class="mt-4" id="blockForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="id" value="<?php echo $blockId; ?>">
                <input type="hidden" name="page_id" value="<?php echo $block['page_id'] ?? 0; ?>">

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Block Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="label">Block Label (optional)</label>
                            <input type="text" class="form-control" id="label" name="label"
                                   value="<?php echo h($block['label'] ?? ''); ?>"
                                   placeholder="e.g., 'Hero Section', 'Features'">
                            <small class="form-text text-muted">For admin reference only</small>
                        </div>

                        <div class="form-group">
                            <label>Block Type</label>
                            <div class="alert alert-info">
                                <strong><?php echo ucfirst($block['block_type'] ?? ''); ?></strong>
                                <small class="d-block">To change block type, delete and re-add</small>
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="is_visible" name="is_visible" value="1"
                                   <?php echo ($block['is_visible'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_visible">
                                Visible
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Block-Specific Fields -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Block Content</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($fields as $fieldKey => $field): ?>
                            <?php $value = $block['config'][$fieldKey] ?? ''; ?>
                            <div class="form-group">
                                <label for="<?php echo h($fieldKey); ?>">
                                    <?php echo h($field['label']); ?>
                                    <?php if ($field['required'] ?? false): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>

                                <?php if ($field['type'] === 'text'): ?>
                                    <input type="text" class="form-control" id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                           value="<?php echo h($value); ?>" <?php echo ($field['required'] ?? false) ? 'required' : ''; ?>>

                                <?php elseif ($field['type'] === 'textarea'): ?>
                                    <textarea class="form-control" id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                              rows="<?php echo $field['rows'] ?? 3; ?>" <?php echo ($field['required'] ?? false) ? 'required' : ''; ?>><?php echo h($value); ?></textarea>

                                <?php elseif ($field['type'] === 'html'): ?>
                                    <textarea class="form-control html-editor" id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                              rows="10" <?php echo ($field['required'] ?? false) ? 'required' : ''; ?>><?php echo $value; ?></textarea>
                                    <small class="form-text text-muted">HTML editor (not escaped on public site)</small>

                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select class="form-control" id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                            <?php echo ($field['required'] ?? false) ? 'required' : ''; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($field['options'] as $optKey => $optLabel): ?>
                                            <option value="<?php echo h($optKey); ?>" <?php echo $value === (string)$optKey ? 'selected' : ''; ?>>
                                                <?php echo h($optLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php elseif ($field['type'] === 'media'): ?>
                                    <div class="media-field" data-field-key="<?php echo h($fieldKey); ?>">
                                        <div class="input-group mb-2">
                                            <input type="hidden" class="media-id-input" name="config[<?php echo h($fieldKey); ?>]" value="<?php echo h($value); ?>">
                                            <input type="text" class="form-control media-display" readonly
                                                   placeholder="No media selected" value="<?php echo h($value); ?>">
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-secondary open-media-picker" type="button" data-field-key="<?php echo h($fieldKey); ?>">
                                                    Browse
                                                </button>
                                            </div>
                                        </div>
                                        <div class="media-preview-container"></div>
                                    </div>

                                <?php elseif ($field['type'] === 'repeatable'): ?>
                                    <div class="repeatable-field" data-field-key="<?php echo h($fieldKey); ?>" data-item-type="<?php echo h($field['itemType']); ?>">
                                        <div class="repeatable-items">
                                            <?php
                                            $items = [];
                                            if (is_array($value)) {
                                                $items = $value;
                                            } elseif (is_string($value) && !empty($value)) {
                                                $items = json_decode($value, true) ?: [];
                                            }
                                            ?>
                                            <?php foreach ($items as $idx => $item): ?>
                                            <div class="repeatable-item" data-index="<?php echo $idx; ?>">
                                                <div class="repeatable-item-header">
                                                    <strong><?php echo ucfirst($field['itemType']); ?> <?php echo $idx + 1; ?></strong>
                                                    <div class="repeatable-item-controls">
                                                        <button type="button" class="btn btn-sm btn-secondary move-up" title="Move up">↑</button>
                                                        <button type="button" class="btn btn-sm btn-secondary move-down" title="Move down">↓</button>
                                                        <button type="button" class="btn btn-sm btn-danger remove-item">Remove</button>
                                                    </div>
                                                </div>
                                                <?php foreach ($field['fields'] as $subKey => $subField): ?>
                                                    <div class="form-group">
                                                        <label><?php echo h($subField['label']); ?></label>
                                                        <?php if ($subField['type'] === 'text'): ?>
                                                            <input type="text" class="form-control item-field" data-key="<?php echo h($subKey); ?>"
                                                                   value="<?php echo h($item[$subKey] ?? ''); ?>">
                                                        <?php elseif ($subField['type'] === 'textarea'): ?>
                                                            <textarea class="form-control item-field" data-key="<?php echo h($subKey); ?>"
                                                                      rows="<?php echo $subField['rows'] ?? 3; ?>"><?php echo h($item[$subKey] ?? ''); ?></textarea>
                                                        <?php elseif ($subField['type'] === 'media'): ?>
                                                            <div class="input-group">
                                                                <input type="hidden" class="item-field media-id-input" data-key="<?php echo h($subKey); ?>"
                                                                       value="<?php echo h($item[$subKey] ?? ''); ?>">
                                                                <input type="text" class="form-control media-display" readonly
                                                                       placeholder="No media selected" value="<?php echo h($item[$subKey] ?? ''); ?>">
                                                                <div class="input-group-append">
                                                                    <button class="btn btn-outline-secondary open-media-picker-sub" type="button" data-field-key="<?php echo h($fieldKey); ?>" data-index="<?php echo $idx; ?>" data-sub-key="<?php echo h($subKey); ?>">
                                                                        Browse
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-success repeatable-add-btn" data-field-key="<?php echo h($fieldKey); ?>" data-item-type="<?php echo h($field['itemType']); ?>">
                                            <i data-feather="plus"></i> Add <?php echo ucfirst($field['itemType']); ?>
                                        </button>
                                    </div>

                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-lg">Save Block</button>
                    <a href="/crm/cms-page-editor.php?id=<?php echo $block['page_id'] ?? 0; ?>" class="btn btn-secondary btn-lg">Cancel</a>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Block Info</h5>
                </div>
                <div class="card-body small">
                    <p><strong>ID:</strong> <?php echo h($blockId); ?></p>
                    <p><strong>Type:</strong> <?php echo h(ucfirst($block['block_type'] ?? '')); ?></p>
                    <p><strong>Position:</strong> <?php echo h($block['position'] ?? 0); ?></p>
                    <p><strong>Created:</strong> <?php echo date('M d, Y', strtotime($block['created_at'] ?? 'now')); ?></p>
                    <p><strong>Updated:</strong> <?php echo date('M d, Y', strtotime($block['updated_at'] ?? 'now')); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Media Picker Modal -->
<div class="modal fade" id="mediaPickerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Media</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-3">
                    <input type="text" class="form-control" id="mediaSearchInput" placeholder="Search by filename or alt text...">
                </div>
                <div class="media-picker-grid" id="mediaPickerGrid">
                    <p class="text-muted">Loading media...</p>
                </div>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center" id="mediaPaginationContainer"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for repeatable items and media picker -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentFieldKey = null;
    let currentItemIndex = null;
    let currentSubKey = null;
    let currentMediaPage = 1;

    // Repeatable item management
    document.querySelectorAll('.repeatable-add-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const fieldKey = this.dataset.fieldKey;
            const itemType = this.dataset.itemType;
            const container = document.querySelector(`.repeatable-field[data-field-key="${fieldKey}"] .repeatable-items`);

            // Get field definitions from the form
            const fieldDef = <?php echo json_encode($fields, JSON_UNESCAPED_SLASHES); ?>;
            if (!fieldDef[fieldKey] || !fieldDef[fieldKey].fields) return;

            const subFields = fieldDef[fieldKey].fields;
            const newIndex = container.querySelectorAll('.repeatable-item').length;

            let html = `
                <div class="repeatable-item" data-index="${newIndex}">
                    <div class="repeatable-item-header">
                        <strong>${itemType.charAt(0).toUpperCase() + itemType.slice(1)} ${newIndex + 1}</strong>
                        <div class="repeatable-item-controls">
                            <button type="button" class="btn btn-sm btn-secondary move-up" title="Move up">↑</button>
                            <button type="button" class="btn btn-sm btn-secondary move-down" title="Move down">↓</button>
                            <button type="button" class="btn btn-sm btn-danger remove-item">Remove</button>
                        </div>
                    </div>
            `;

            Object.entries(subFields).forEach(([subKey, subField]) => {
                html += `<div class="form-group">`;
                html += `<label>${escapeHtml(subField.label)}</label>`;

                if (subField.type === 'text') {
                    html += `<input type="text" class="form-control item-field" data-key="${escapeHtml(subKey)}" value="">`;
                } else if (subField.type === 'textarea') {
                    html += `<textarea class="form-control item-field" data-key="${escapeHtml(subKey)}" rows="${subField.rows || 3}"></textarea>`;
                } else if (subField.type === 'media') {
                    html += `
                        <div class="input-group">
                            <input type="hidden" class="item-field media-id-input" data-key="${escapeHtml(subKey)}" value="">
                            <input type="text" class="form-control media-display" readonly placeholder="No media selected" value="">
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary open-media-picker-sub" type="button" data-field-key="${escapeHtml(fieldKey)}" data-index="${newIndex}" data-sub-key="${escapeHtml(subKey)}">Browse</button>
                            </div>
                        </div>
                    `;
                }
                html += `</div>`;
            });

            html += `</div>`;

            container.insertAdjacentHTML('beforeend', html);
            attachRepeatableHandlers();
        });
    });

    function attachRepeatableHandlers() {
        // Remove item
        document.querySelectorAll('.remove-item').forEach(btn => {
            btn.removeEventListener('click', null);
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                this.closest('.repeatable-item').remove();
            });
        });

        // Move up/down
        document.querySelectorAll('.move-up').forEach(btn => {
            btn.removeEventListener('click', null);
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const current = this.closest('.repeatable-item');
                const prev = current.previousElementSibling;
                if (prev && prev.classList.contains('repeatable-item')) {
                    current.parentNode.insertBefore(current, prev);
                }
            });
        });

        document.querySelectorAll('.move-down').forEach(btn => {
            btn.removeEventListener('click', null);
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const current = this.closest('.repeatable-item');
                const next = current.nextElementSibling;
                if (next && next.classList.contains('repeatable-item')) {
                    current.parentNode.insertBefore(next, current);
                }
            });
        });
    }

    attachRepeatableHandlers();

    // Media picker handlers
    document.querySelectorAll('.open-media-picker').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            currentFieldKey = this.dataset.fieldKey;
            currentItemIndex = null;
            currentSubKey = null;
            currentMediaPage = 1;
            jQuery('#mediaPickerModal').modal('show');
            loadMediaItems('');
        });
    });

    document.querySelectorAll('.open-media-picker-sub').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            currentFieldKey = this.dataset.fieldKey;
            currentItemIndex = parseInt(this.dataset.index);
            currentSubKey = this.dataset.subKey;
            currentMediaPage = 1;
            jQuery('#mediaPickerModal').modal('show');
            loadMediaItems('');
        });
    });

    // Media search
    document.getElementById('mediaSearchInput').addEventListener('input', function() {
        currentMediaPage = 1;
        loadMediaItems(this.value);
    });

    function loadMediaItems(search) {
        const url = `/crm/api/cms_media_list.php?search=${encodeURIComponent(search)}&page=${currentMediaPage}&per_page=12`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    document.getElementById('mediaPickerGrid').innerHTML = '<p class="text-danger">Error loading media</p>';
                    return;
                }

                let html = '';
                data.data.forEach(item => {
                    const isImage = item.type === 'image';
                    html += `
                        <div class="media-picker-item" data-media-id="${item.id}" data-media-filename="${escapeHtml(item.filename)}">
                            ${isImage ? `<img src="${item.thumb_path}" alt="${escapeHtml(item.alt_text)}" class="media-picker-thumb">` : `<div style="width:100%; height:100px; background:#eee; display:flex; align-items:center; justify-content:center; color:#999;">${item.type.toUpperCase()}</div>`}
                            <div class="media-picker-filename">${escapeHtml(item.filename)}</div>
                        </div>
                    `;
                });
                document.getElementById('mediaPickerGrid').innerHTML = html;

                // Pagination
                let paginationHtml = '';
                for (let i = 1; i <= data.pagination.total_pages; i++) {
                    paginationHtml += `<li class="page-item ${i === currentMediaPage ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                }
                document.getElementById('mediaPaginationContainer').innerHTML = paginationHtml;

                // Media item selection
                document.querySelectorAll('.media-picker-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const mediaId = this.dataset.mediaId;
                        const filename = this.dataset.mediaFilename;

                        if (currentItemIndex !== null && currentSubKey !== null) {
                            // Repeatable sub-item
                            const container = document.querySelector(`.repeatable-field[data-field-key="${currentFieldKey}"] .repeatable-items`);
                            const item = container.querySelector(`.repeatable-item[data-index="${currentItemIndex}"]`);
                            item.querySelector(`.item-field[data-key="${currentSubKey}"].media-id-input`).value = mediaId;
                            item.querySelector(`.media-display`).value = filename;
                        } else {
                            // Top-level media field
                            const container = document.querySelector(`.media-field[data-field-key="${currentFieldKey}"]`);
                            container.querySelector('.media-id-input').value = mediaId;
                            container.querySelector('.media-display').value = filename;
                        }

                        jQuery('#mediaPickerModal').modal('hide');
                    });
                });

                // Pagination handlers
                document.querySelectorAll('#mediaPaginationContainer .page-link').forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        currentMediaPage = parseInt(this.dataset.page);
                        loadMediaItems(document.getElementById('mediaSearchInput').value);
                    });
                });
            })
            .catch(err => {
                document.getElementById('mediaPickerGrid').innerHTML = '<p class="text-danger">Error loading media</p>';
                console.error('Media picker error:', err);
            });
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // Form submission: serialize repeatable items to JSON
    document.getElementById('blockForm').addEventListener('submit', function(e) {
        document.querySelectorAll('.repeatable-field').forEach(field => {
            const fieldKey = field.dataset.fieldKey;
            const itemType = field.dataset.itemType;
            const itemDefs = <?php echo json_encode(array_map(function($f) {
                return $f['fields'] ?? [];
            }, $fields), JSON_UNESCAPED_SLASHES); ?>;
            const subFields = itemDefs[fieldKey] || {};

            const items = [];
            field.querySelectorAll('.repeatable-item').forEach(item => {
                const itemObj = {};
                item.querySelectorAll('.item-field').forEach(field => {
                    const key = field.dataset.key;
                    itemObj[key] = field.value || '';
                });
                if (Object.values(itemObj).some(v => v)) {
                    items.push(itemObj);
                }
            });

            // Create hidden input with JSON value
            let existingInput = document.querySelector(`input[name="config[${fieldKey}]"]`);
            if (existingInput) {
                existingInput.value = JSON.stringify(items);
            } else {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `config[${fieldKey}]`;
                input.value = JSON.stringify(items);
                this.appendChild(input);
            }
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
