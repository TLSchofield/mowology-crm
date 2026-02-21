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
        'headline'    => ['type' => 'text',     'label' => 'Headline',        'required' => true,  'maxlength' => 80,  'hint' => '5–80 chars. Make it punchy.'],
        'subheadline' => ['type' => 'textarea',  'label' => 'Subheadline',     'rows' => 2,         'maxlength' => 160, 'hint' => 'One sentence. 50–160 chars.'],
        'cta_text'    => ['type' => 'text',     'label' => 'CTA Button Text',  'maxlength' => 40],
        'cta_url'     => ['type' => 'text',     'label' => 'CTA Button URL'],
        'secondary_cta_text' => ['type' => 'text', 'label' => 'Secondary Button Text', 'maxlength' => 40],
        'secondary_cta_url'  => ['type' => 'text', 'label' => 'Secondary Button URL'],
        'hero_style'  => ['type' => 'select',   'label' => 'Hero Style',       'options' => ['auto' => 'Auto-detect', 'image_bg' => 'Full-Width Background', 'image_side' => 'Side-by-Side', 'text_only' => 'Text Only (no image)']],
        'media_id'    => ['type' => 'media',    'label' => 'Hero Image'],
        'media_alt'   => ['type' => 'text',     'label' => 'Image Alt Text',   'maxlength' => 125,  'hint' => 'Describe the image for SEO and accessibility.'],
        'trust_line'  => ['type' => 'text',     'label' => 'Trust Line',       'maxlength' => 80,   'hint' => 'e.g. "Trusted by 200+ homeowners in Vancouver"'],
    ],
    'feature_grid' => [
        'title'       => ['type' => 'text',     'label' => 'Section Title',    'maxlength' => 80],
        'description' => ['type' => 'textarea', 'label' => 'Description',      'rows' => 2, 'maxlength' => 200],
        'layout'      => ['type' => 'select',   'label' => 'Columns',          'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
        'features'    => ['type' => 'repeatable', 'label' => 'Features', 'itemType' => 'feature', 'fields' => [
            'title'       => ['type' => 'text',     'label' => 'Title',       'maxlength' => 60],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 2, 'maxlength' => 150],
        ]],
    ],
    'cta' => [
        'headline'       => ['type' => 'text',    'label' => 'Headline',              'required' => true, 'maxlength' => 80, 'hint' => 'Direct call to action. 5–80 chars.'],
        'subheadline'    => ['type' => 'textarea','label' => 'Subheadline',           'rows' => 2,        'maxlength' => 160],
        'primary_text'   => ['type' => 'text',    'label' => 'Primary Button Text',   'required' => true, 'maxlength' => 40],
        'primary_url'    => ['type' => 'text',    'label' => 'Primary Button URL',    'required' => true],
        'secondary_text' => ['type' => 'text',    'label' => 'Secondary Button Text', 'maxlength' => 40],
        'secondary_url'  => ['type' => 'text',    'label' => 'Secondary Button URL'],
        'style'          => ['type' => 'select',  'label' => 'Style',                 'options' => ['gradient' => 'Green Gradient', 'dark' => 'Dark', 'light' => 'Light']],
    ],
    'faq' => [
        'title'       => ['type' => 'text',      'label' => 'Section Title', 'maxlength' => 80],
        'description' => ['type' => 'textarea',  'label' => 'Description',   'rows' => 2, 'maxlength' => 200],
        'faqs'        => ['type' => 'repeatable','label' => 'FAQs', 'itemType' => 'faq', 'fields' => [
            'question' => ['type' => 'text',     'label' => 'Question', 'required' => true, 'maxlength' => 160],
            'answer'   => ['type' => 'textarea', 'label' => 'Answer',   'required' => true, 'rows' => 3],
        ]],
    ],
    'gallery' => [
        'title'       => ['type' => 'text',     'label' => 'Section Title', 'maxlength' => 80],
        'description' => ['type' => 'textarea', 'label' => 'Description',   'rows' => 2],
        'layout'      => ['type' => 'select',   'label' => 'Layout',        'options' => ['grid' => 'Grid', 'carousel' => 'Carousel']],
        'columns'     => ['type' => 'select',   'label' => 'Columns',       'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
        'images'      => ['type' => 'repeatable','label' => 'Gallery Images', 'itemType' => 'image', 'fields' => [
            'media_id'     => ['type' => 'media','label' => 'Image'],
            'caption'      => ['type' => 'text', 'label' => 'Caption',           'maxlength' => 120],
            'alt_override' => ['type' => 'text', 'label' => 'Alt Text (override)','maxlength' => 125],
        ]],
    ],
    'rich_text' => [
        'title'   => ['type' => 'text','label' => 'Section Title', 'maxlength' => 80],
        'content' => ['type' => 'html','label' => 'Content (HTML)'],
    ],
    'testimonials' => [
        'title'        => ['type' => 'text',    'label' => 'Section Title', 'maxlength' => 80],
        'layout'       => ['type' => 'select',  'label' => 'Layout', 'options' => ['grid' => 'Grid', 'carousel' => 'Carousel']],
        'testimonials' => ['type' => 'repeatable','label' => 'Testimonials', 'itemType' => 'testimonial', 'fields' => [
            'name'  => ['type' => 'text',     'label' => 'Name',          'required' => true, 'maxlength' => 80],
            'quote' => ['type' => 'textarea', 'label' => 'Quote',         'required' => true, 'rows' => 3, 'maxlength' => 400],
            'role'  => ['type' => 'text',     'label' => 'Role / Company','maxlength' => 80],
        ]],
    ],
    'service_cards' => [
        'title'       => ['type' => 'text',      'label' => 'Section Title', 'maxlength' => 80],
        'description' => ['type' => 'textarea',  'label' => 'Description',   'rows' => 2, 'maxlength' => 200],
        'columns'     => ['type' => 'select',    'label' => 'Columns',       'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
        'services'    => ['type' => 'repeatable','label' => 'Services', 'itemType' => 'service', 'fields' => [
            'title'       => ['type' => 'text',     'label' => 'Service Title', 'required' => true, 'maxlength' => 60],
            'description' => ['type' => 'textarea', 'label' => 'Description',   'rows' => 2,        'maxlength' => 160],
            'url'         => ['type' => 'text',     'label' => 'Link URL (optional)'],
        ]],
    ],
    // ---- New block types ----
    'service_grid' => [
        'title'       => ['type' => 'text',      'label' => 'Section Title',  'maxlength' => 80],
        'description' => ['type' => 'textarea',  'label' => 'Intro Text',     'rows' => 2, 'maxlength' => 200],
        'services'    => ['type' => 'repeatable','label' => 'Services (3-col grid)', 'itemType' => 'service', 'fields' => [
            'title'       => ['type' => 'text',     'label' => 'Service Name',  'required' => true, 'maxlength' => 60],
            'description' => ['type' => 'textarea', 'label' => 'Description',   'rows' => 2,        'maxlength' => 200],
            'icon'        => ['type' => 'text',     'label' => 'Icon name (Feather)', 'hint' => 'e.g. scissors, leaf, sun'],
            'url'         => ['type' => 'text',     'label' => 'Link URL'],
        ]],
        'cta_text'  => ['type' => 'text', 'label' => 'Bottom CTA Text', 'maxlength' => 40],
        'cta_url'   => ['type' => 'text', 'label' => 'Bottom CTA URL'],
    ],
    'before_after' => [
        'title'       => ['type' => 'text',      'label' => 'Section Title',  'maxlength' => 80],
        'description' => ['type' => 'textarea',  'label' => 'Description',    'rows' => 2, 'maxlength' => 200],
        'pairs'       => ['type' => 'repeatable','label' => 'Before/After Pairs', 'itemType' => 'pair', 'fields' => [
            'before_media_id' => ['type' => 'media','label' => 'Before Image'],
            'after_media_id'  => ['type' => 'media','label' => 'After Image'],
            'caption'         => ['type' => 'text', 'label' => 'Caption',     'maxlength' => 120],
        ]],
    ],
    'stats_banner' => [
        'title'       => ['type' => 'text',      'label' => 'Section Title',  'maxlength' => 80],
        'background'  => ['type' => 'select',    'label' => 'Background',     'options' => ['green' => 'Green', 'dark' => 'Dark', 'light' => 'Light']],
        'stats'       => ['type' => 'repeatable','label' => 'Stats', 'itemType' => 'stat', 'fields' => [
            'number' => ['type' => 'text',     'label' => 'Number / Value', 'required' => true, 'maxlength' => 20, 'hint' => 'e.g. 200+, 98%, $1M'],
            'label'  => ['type' => 'text',     'label' => 'Label',          'required' => true, 'maxlength' => 60],
        ]],
    ],
    'portfolio_showcase' => [
        'title'       => ['type' => 'text',      'label' => 'Section Title', 'maxlength' => 80],
        'description' => ['type' => 'textarea',  'label' => 'Description',   'rows' => 2, 'maxlength' => 200],
        'cta_text'    => ['type' => 'text',      'label' => 'CTA Text',      'maxlength' => 40],
        'cta_url'     => ['type' => 'text',      'label' => 'CTA URL'],
        'projects'    => ['type' => 'repeatable','label' => 'Projects', 'itemType' => 'project', 'fields' => [
            'title'    => ['type' => 'text',     'label' => 'Project Title', 'maxlength' => 80],
            'media_id' => ['type' => 'media',    'label' => 'Project Image'],
            'url'      => ['type' => 'text',     'label' => 'Project URL (optional)'],
        ]],
    ],
    'area_cards' => [
        'title'    => ['type' => 'text',      'label' => 'Section Title', 'maxlength' => 80],
        'subtitle' => ['type' => 'textarea',  'label' => 'Subtitle',      'rows' => 2, 'maxlength' => 200],
        'areas'    => ['type' => 'repeatable','label' => 'Service Areas', 'itemType' => 'area', 'fields' => [
            'name'           => ['type' => 'text',     'label' => 'Area Name',          'required' => true, 'maxlength' => 80, 'hint' => 'e.g. Vancouver, Burnaby'],
            'neighborhoods'  => ['type' => 'textarea', 'label' => 'Neighborhoods / Notes', 'rows' => 2,    'maxlength' => 300, 'hint' => 'e.g. Kitsilano, Fairview, Mount Pleasant'],
        ]],
    ],
    'custom' => [
        'html_content' => ['type' => 'html','label' => 'HTML Content'],
    ],
];

$fields = $blockFieldTemplates[$block['block_type'] ?? ''] ?? [];

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>


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

                                <?php
                                $maxlen = $field['maxlength'] ?? null;
                                $hint   = $field['hint'] ?? null;
                                $isReq  = $field['required'] ?? false;
                                ?>
                                <?php if ($field['type'] === 'text'): ?>
                                    <input type="text" class="form-control<?php echo $maxlen ? ' mw-char-input' : ''; ?>"
                                           id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                           value="<?php echo h($value); ?>"
                                           <?php echo $maxlen ? 'maxlength="' . (int)$maxlen . '" data-maxlen="' . (int)$maxlen . '"' : ''; ?>
                                           <?php echo $isReq ? 'required' : ''; ?>>
                                    <?php if ($maxlen): ?>
                                    <small class="mw-char-count text-muted"><?php echo mb_strlen((string)$value); ?> / <?php echo $maxlen; ?></small>
                                    <?php endif; ?>
                                    <?php if ($hint): ?><small class="form-text text-info"><?php echo h($hint); ?></small><?php endif; ?>

                                <?php elseif ($field['type'] === 'textarea'): ?>
                                    <textarea class="form-control<?php echo $maxlen ? ' mw-char-input' : ''; ?>"
                                              id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                              rows="<?php echo $field['rows'] ?? 3; ?>"
                                              <?php echo $maxlen ? 'maxlength="' . (int)$maxlen . '" data-maxlen="' . (int)$maxlen . '"' : ''; ?>
                                              <?php echo $isReq ? 'required' : ''; ?>><?php echo h($value); ?></textarea>
                                    <?php if ($maxlen): ?>
                                    <small class="mw-char-count text-muted"><?php echo mb_strlen((string)$value); ?> / <?php echo $maxlen; ?></small>
                                    <?php endif; ?>
                                    <?php if ($hint): ?><small class="form-text text-info"><?php echo h($hint); ?></small><?php endif; ?>

                                <?php elseif ($field['type'] === 'html'): ?>
                                    <textarea class="form-control html-editor" id="<?php echo h($fieldKey); ?>" name="config[<?php echo h($fieldKey); ?>]"
                                              rows="10" <?php echo ($field['required'] ?? false) ? 'required' : ''; ?>><?php echo h((string)$value); ?></textarea>
                                    <small class="form-text text-muted">HTML is rendered as-is on the public site. Ensure content is trusted.</small>

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
                                            <div class="mw-repeatable-item" data-index="<?php echo $idx; ?>">
                                                <div class="mw-repeatable-item-header">
                                                    <strong><?php echo ucfirst($field['itemType']); ?> <?php echo $idx + 1; ?></strong>
                                                    <div class="mw-repeatable-item-controls">
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
                                        <button type="button" class="btn btn-sm btn-success mw-repeatable-add-btn" data-field-key="<?php echo h($fieldKey); ?>" data-item-type="<?php echo h($field['itemType']); ?>">
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
                <div class="mw-media-picker-grid" id="mediaPickerGrid">
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
    document.querySelectorAll('.mw-repeatable-add-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const fieldKey = this.dataset.fieldKey;
            const itemType = this.dataset.itemType;
            const container = document.querySelector(`.repeatable-field[data-field-key="${fieldKey}"] .repeatable-items`);

            // Get field definitions from the form
            const fieldDef = <?php echo json_encode($fields, JSON_UNESCAPED_SLASHES); ?>;
            if (!fieldDef[fieldKey] || !fieldDef[fieldKey].fields) return;

            const subFields = fieldDef[fieldKey].fields;
            const newIndex = container.querySelectorAll('.mw-repeatable-item').length;

            let html = `
                <div class="mw-repeatable-item" data-index="${newIndex}">
                    <div class="mw-repeatable-item-header">
                        <strong>${itemType.charAt(0).toUpperCase() + itemType.slice(1)} ${newIndex + 1}</strong>
                        <div class="mw-repeatable-item-controls">
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
                this.closest('.mw-repeatable-item').remove();
            });
        });

        // Move up/down
        document.querySelectorAll('.move-up').forEach(btn => {
            btn.removeEventListener('click', null);
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const current = this.closest('.mw-repeatable-item');
                const prev = current.previousElementSibling;
                if (prev && prev.classList.contains('mw-repeatable-item')) {
                    current.parentNode.insertBefore(current, prev);
                }
            });
        });

        document.querySelectorAll('.move-down').forEach(btn => {
            btn.removeEventListener('click', null);
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const current = this.closest('.mw-repeatable-item');
                const next = current.nextElementSibling;
                if (next && next.classList.contains('mw-repeatable-item')) {
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
                        <div class="mw-media-picker-item" data-media-id="${item.id}" data-media-filename="${escapeHtml(item.filename)}">
                            ${isImage ? `<img src="${escapeHtml(item.thumb_path)}" alt="${escapeHtml(item.alt_text)}" class="mw-media-picker-thumb">` : `<div style="width:100%; height:100px; background:#eee; display:flex; align-items:center; justify-content:center; color:#999;">${escapeHtml(item.type.toUpperCase())}</div>`}
                            <div class="mw-media-picker-filename">${escapeHtml(item.filename)}</div>
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
                document.querySelectorAll('.mw-media-picker-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const mediaId = this.dataset.mediaId;
                        const filename = this.dataset.mediaFilename;

                        if (currentItemIndex !== null && currentSubKey !== null) {
                            // Repeatable sub-item
                            const container = document.querySelector(`.repeatable-field[data-field-key="${currentFieldKey}"] .repeatable-items`);
                            const item = container.querySelector(`.mw-repeatable-item[data-index="${currentItemIndex}"]`);
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
            field.querySelectorAll('.mw-repeatable-item').forEach(item => {
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

    // ---- Live character counters ----
    function updateCharCount(input) {
        var counter = input.parentElement.querySelector('.mw-char-count');
        if (!counter) return;
        var max = parseInt(input.dataset.maxlen, 10);
        var len = input.value.length;
        counter.textContent = len + ' / ' + max;
        counter.style.color = len > max ? '#dc3545' : (len > max * 0.85 ? '#fd7e14' : '');
    }

    document.querySelectorAll('.mw-char-input').forEach(function(el) {
        el.addEventListener('input', function() { updateCharCount(this); });
        updateCharCount(el); // run once on load
    });

    // ---- Client-side required-field validation on save ----
    var blockForm = document.getElementById('block-form');
    if (blockForm) {
        blockForm.addEventListener('submit', function(e) {
            var errors = [];
            this.querySelectorAll('[required]').forEach(function(el) {
                if (el.closest('.d-none') || el.closest('[style*="display:none"]')) return;
                if (!el.value.trim()) {
                    errors.push(el.closest('.form-group')?.querySelector('label')?.textContent?.replace('*','').trim() || el.name);
                    el.classList.add('is-invalid');
                } else {
                    el.classList.remove('is-invalid');
                }
            });
            if (errors.length) {
                e.preventDefault();
                var alertEl = document.getElementById('save-alert');
                if (alertEl) {
                    alertEl.className = 'alert alert-danger';
                    alertEl.textContent = 'Please fill in required fields: ' + errors.join(', ');
                    alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
