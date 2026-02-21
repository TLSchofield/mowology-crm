<?php
/**
 * CMS Page Editor
 *
 * Create and edit pages with block management
 * Includes smart SEO defaults and UI guide overlays
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/crm/includes/cms-functions.php';
require_once dirname(__DIR__) . '/crm/includes/cms-template-functions.php';
require_once dirname(__DIR__) . '/crm/includes/admin-ui-kit.php';
require_once dirname(__DIR__) . '/crm/includes/seo-functions.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    die('Access denied');
}

$pageTitle = 'Page Editor';
$activePage = 'cms';
$pageId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'edit';
$templateKey = $_GET['template'] ?? '';

// Load page if editing
$page = null;
$blocks = [];
if ($pageId) {
    $page = cms_getPageById($pageId);
    if (!$page) {
        http_response_code(404);
        die('Page not found');
    }
    $blocks = cms_getBlocksByPageId($pageId);
} elseif ($templateKey) {
    // Create from template
    $template = cms_getPageTemplate($templateKey);
    if (!$template) {
        http_response_code(404);
        die('Template not found');
    }
}

// Get all templates for reference
$templates = cms_getPageTemplates();

// Generate SEO previews for display
$suggestedMetaTitle = $page ? seo_getMetaTitle($page) : '';
$suggestedMetaDesc = $page ? seo_getMetaDescription($page, $blocks) : '';

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
    <div class="row">
        <div class="col-lg-8">
            <?php echo admin_breadcrumbs([
                ['label' => 'CMS', 'url' => '/crm/cms-pages_appstack.php'],
                ['label' => $page ? 'Edit: ' . $page['title'] : 'New Page'],
            ]); ?>

            <h1><?php echo $page ? 'Edit Page' : 'Create New Page'; ?></h1>

            <!-- Page Form -->
            <form method="POST" action="/crm/api/save-page.php" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="id" value="<?php echo $pageId; ?>">

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Page Information</h5>
                    </div>
                    <div class="card-body">
                        <!-- UI Guide -->
                        <div class="mw-ui-guide">
                            <strong>💡 Pro Tip:</strong> Fill in your page info first, then click "Create Page" to add blocks. SEO fields auto-populate with smart defaults — edit them to customize.
                        </div>

                        <!-- Slug -->
                        <div class="form-group">
                            <label for="slug">
                                URL Slug *
                                <span class="mw-help-tooltip" data-help="URL-safe identifier for this page. Used in the page link. Example: 'lawn-maintenance' becomes /lawn-maintenance">?</span>
                            </label>
                            <input type="text" class="form-control" id="slug" name="slug" required
                                   value="<?php echo h($page['slug'] ?? ''); ?>"
                                   placeholder="page-slug">
                            <small class="form-text text-muted">URL-safe identifier (lowercase, hyphens only)</small>
                        </div>

                        <!-- Title -->
                        <div class="form-group">
                            <label for="title">
                                Page Title *
                                <span class="mw-help-tooltip" data-help="The main title of your page. Used for navigation and defaults for SEO meta title. Examples: 'Lawn Maintenance', 'About Mowology', 'Contact Us'">?</span>
                            </label>
                            <input type="text" class="form-control" id="title" name="title" required
                                   value="<?php echo h($page['title'] ?? ''); ?>"
                                   placeholder="Page Title"
                                   onchange="updateSEOPreview()">
                        </div>

                        <!-- Type -->
                        <div class="form-group">
                            <label for="page_type">Page Type</label>
                            <select class="form-control" id="page_type" name="page_type">
                                <option value="custom" <?php echo ($page['page_type'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                <option value="home" <?php echo ($page['page_type'] ?? '') === 'home' ? 'selected' : ''; ?>>Homepage</option>
                                <option value="services" <?php echo ($page['page_type'] ?? '') === 'services' ? 'selected' : ''; ?>>Services</option>
                                <option value="service_landing" <?php echo ($page['page_type'] ?? '') === 'service_landing' ? 'selected' : ''; ?>>Service Landing</option>
                                <option value="portfolio" <?php echo ($page['page_type'] ?? '') === 'portfolio' ? 'selected' : ''; ?>>Portfolio</option>
                                <option value="contact" <?php echo ($page['page_type'] ?? '') === 'contact' ? 'selected' : ''; ?>>Contact</option>
                                <option value="landing" <?php echo ($page['page_type'] ?? '') === 'landing' ? 'selected' : ''; ?>>Landing</option>
                            </select>
                        </div>

                        <!-- Layout Template -->
                        <div class="form-group">
                            <label for="layout_template">Layout Template</label>
                            <select class="form-control" id="layout_template" name="layout_template">
                                <option value="default" <?php echo ($page['layout_template'] ?? '') === 'default' ? 'selected' : ''; ?>>Default</option>
                                <option value="homepage" <?php echo ($page['layout_template'] ?? '') === 'homepage' ? 'selected' : ''; ?>>Homepage</option>
                                <option value="service_landing" <?php echo ($page['layout_template'] ?? '') === 'service_landing' ? 'selected' : ''; ?>>Service Landing</option>
                                <option value="contact" <?php echo ($page['layout_template'] ?? '') === 'contact' ? 'selected' : ''; ?>>Contact</option>
                                <option value="portfolio" <?php echo ($page['layout_template'] ?? '') === 'portfolio' ? 'selected' : ''; ?>>Portfolio</option>
                            </select>
                        </div>

                        <!-- Meta Title -->
                        <div class="form-group">
                            <label for="meta_title">
                                Meta Title (SEO)
                                <span class="mw-help-tooltip" data-help="This is what appears as the title in Google search results. Keep it under 60 characters for best display. Leave empty for auto-generated default.">?</span>
                                <span class="mw-auto-seo-badge">Auto-generated</span>
                            </label>
                            <input type="text" class="form-control" id="meta_title" name="meta_title"
                                   value="<?php echo h($page['meta_title'] ?? ''); ?>"
                                   placeholder="<?php echo h($suggestedMetaTitle); ?>"
                                   maxlength="60"
                                   onchange="updateSEOPreview()">
                            <small class="form-text text-muted">Recommended: 50-60 characters</small>
                        </div>

                        <!-- Meta Description -->
                        <div class="form-group">
                            <label for="meta_description">
                                Meta Description (SEO)
                                <span class="mw-help-tooltip" data-help="This snippet appears under your title in search results. Keep it under 160 characters. Should include your target keywords naturally. Leave empty for auto-generated summary.">?</span>
                                <span class="mw-auto-seo-badge">Auto-generated</span>
                            </label>
                            <textarea class="form-control" id="meta_description" name="meta_description"
                                      rows="2" maxlength="160"
                                      placeholder="<?php echo h($suggestedMetaDesc); ?>"
                                      onchange="updateSEOPreview()"><?php echo h($page['meta_description'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">Recommended: 120-160 characters. Bonus: Include your location and value proposition.</small>
                        </div>

                        <!-- SEO Preview -->
                        <div class="mw-seo-preview">
                            <div class="mw-seo-title" id="seoPreviewTitle"><?php echo h($suggestedMetaTitle); ?></div>
                            <div class="mw-seo-url">mowology.ca/<?php echo h($page['slug'] ?? 'your-page'); ?></div>
                            <div class="mw-seo-description" id="seoPreviewDesc"><?php echo h($suggestedMetaDesc); ?></div>
                        </div>

                        <!-- Status -->
                        <div class="form-group">
                            <label for="status">
                                Status
                                <span class="mw-help-tooltip" data-help="Draft: Not visible to public or search engines. Published: Live and visible. Archived: Hidden but kept for history.">?</span>
                            </label>
                            <select class="form-control" id="status" name="status">
                                <option value="draft" <?php echo ($page['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft (not visible)</option>
                                <option value="published" <?php echo ($page['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published (live)</option>
                                <option value="archived" <?php echo ($page['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>Archived (hidden)</option>
                            </select>
                        </div>

                        <!-- Noindex -->
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="noindex" name="noindex" value="1"
                                   <?php echo ($page['noindex'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="noindex">
                                Noindex (prevent search engines from indexing)
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Blocks Section -->
                <?php if ($pageId): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Page Blocks (<?php echo count($blocks); ?>)</h5>
                        <a href="#" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addBlockModal">
                            <i data-feather="plus"></i> Add Block
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($blocks)): ?>
                            <div class="mw-ui-guide">
                                <strong>Next step:</strong> Add your first block above — start with a Hero Banner, then add sections below it.
                            </div>
                        <?php else: ?>
                            <p class="small text-muted mb-2">
                                <i data-feather="move" style="width:12px;height:12px;"></i>
                                Drag rows to reorder blocks
                            </p>
                            <div id="blocks-list">
                                <?php
                                $blockTypeLabels = [
                                    'hero' => 'Hero Banner',
                                    'feature_grid' => 'Feature Grid',
                                    'testimonials' => 'Testimonials',
                                    'cta' => 'Call to Action',
                                    'faq' => 'FAQ',
                                    'gallery' => 'Gallery',
                                    'service_cards' => 'Service Cards',
                                    'rich_text' => 'Rich Text',
                                    'portfolio_showcase' => 'Portfolio Showcase',
                                    'custom' => 'Custom HTML',
                                ];
                                foreach ($blocks as $block):
                                    $typeLabel = $blockTypeLabels[$block['block_type']] ?? ucfirst(str_replace('_', ' ', $block['block_type']));
                                    $headline = $block['config']['headline'] ?? $block['config']['title'] ?? $block['config']['heading'] ?? '';
                                ?>
                                <div class="mw-block-row" data-block-id="<?php echo (int)$block['id']; ?>">
                                    <div class="mw-block-row-handle" title="Drag to reorder">
                                        <i data-feather="grip-vertical" style="width:14px;height:14px;color:#adb5bd;"></i>
                                    </div>
                                    <div class="mw-block-row-info">
                                        <span class="badge badge-secondary mr-2"><?php echo h($typeLabel); ?></span>
                                        <?php if ($headline): ?>
                                            <span class="text-muted small"><?php echo h(mb_strimwidth($headline, 0, 60, '…')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mw-block-row-actions">
                                        <a href="/crm/cms-block-editor.php?block_id=<?php echo (int)$block['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i data-feather="edit-2" style="width:12px;height:12px;"></i> Edit
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger mw-delete-block"
                                                data-block-id="<?php echo (int)$block['id']; ?>"
                                                data-page-id="<?php echo $pageId; ?>">
                                            <i data-feather="trash-2" style="width:12px;height:12px;"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Submit Buttons -->
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <?php echo $page ? 'Update Page' : 'Create Page'; ?>
                    </button>
                    <a href="/crm/cms-pages_appstack.php" class="btn btn-secondary btn-lg">Cancel</a>

                    <?php if ($page && $page['status'] === 'published'): ?>
                    <a href="/<?php echo h(ltrim($page['slug'], '/')); ?>" target="_blank" class="btn btn-outline-primary btn-lg float-right">
                        <i data-feather="external-link"></i> View Live
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Template Reference -->
            <?php if (!$pageId && !empty($templates)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Or Create from Template</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Start with a pre-configured template</p>
                    <?php foreach ($templates as $t): ?>
                    <a href="/crm/cms-page-editor.php?template=<?php echo h($t['template_key']); ?>" class="btn btn-block btn-sm btn-outline-primary mb-2">
                        <?php echo h($t['label']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Help Section -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Help</h5>
                </div>
                <div class="card-body small">
                    <h6>Page Status:</h6>
                    <ul class="list-unstyled mb-3">
                        <li><strong>Draft:</strong> Not visible to public</li>
                        <li><strong>Published:</strong> Live on website</li>
                        <li><strong>Archived:</strong> Hidden from public</li>
                    </ul>

                    <h6>URL Slug:</h6>
                    <p>
                        This becomes part of the page URL. Example: <code>about</code> becomes <code>/about</code>
                    </p>

                    <h6>Blocks:</h6>
                    <p>
                        Pages are composed of blocks (hero, features, CTA, etc.). Add blocks to structure your content.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Block Modal -->
<?php if ($pageId): ?>
<div class="modal fade" id="addBlockModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Block</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">Choose a block type. You can edit its content immediately after adding.</p>
                <div id="add-block-error" class="alert alert-danger d-none" role="alert"></div>
                <!-- Block type card grid -->
                <div class="mw-block-type-grid">
                    <?php
                    $blockTypes = [
                        'hero'               => ['icon' => 'image',       'label' => 'Hero Banner',        'desc' => 'Full-width headline + CTA'],
                        'cta'                => ['icon' => 'zap',         'label' => 'Call to Action',     'desc' => 'Prominent button section'],
                        'feature_grid'       => ['icon' => 'grid',        'label' => 'Feature Grid',       'desc' => 'Icon + text card grid'],
                        'service_cards'      => ['icon' => 'briefcase',   'label' => 'Service Cards',      'desc' => 'Service tile grid'],
                        'service_grid'       => ['icon' => 'layout',      'label' => 'Service Grid',       'desc' => '3-col service overview'],
                        'testimonials'       => ['icon' => 'message-circle', 'label' => 'Testimonials',   'desc' => 'Customer quotes'],
                        'faq'                => ['icon' => 'help-circle', 'label' => 'FAQ',                'desc' => 'Collapsible Q&A'],
                        'gallery'            => ['icon' => 'camera',      'label' => 'Gallery',            'desc' => 'Photo grid'],
                        'before_after'       => ['icon' => 'sliders',     'label' => 'Before / After',     'desc' => 'Side-by-side image comparison'],
                        'stats_banner'       => ['icon' => 'trending-up', 'label' => 'Stats Banner',       'desc' => 'Numbers + labels row'],
                        'rich_text'          => ['icon' => 'type',        'label' => 'Rich Text',          'desc' => 'Formatted text body'],
                        'portfolio_showcase' => ['icon' => 'star',        'label' => 'Portfolio Showcase', 'desc' => 'Project highlights'],
                        'area_cards'         => ['icon' => 'map-pin',     'label' => 'Area Cards',         'desc' => 'Service area grid'],
                        'custom'             => ['icon' => 'code',        'label' => 'Custom HTML',        'desc' => 'Raw HTML block'],
                    ];
                    foreach ($blockTypes as $key => $bt): ?>
                    <button type="button" class="mw-block-type-card" data-block-type="<?php echo h($key); ?>">
                        <i data-feather="<?php echo h($bt['icon']); ?>" style="width:22px;height:22px;margin-bottom:6px;color:var(--mw-green);"></i>
                        <strong><?php echo h($bt['label']); ?></strong>
                        <span><?php echo h($bt['desc']); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
                <input type="hidden" id="add-block-csrf" value="<?php echo generateCSRFToken(); ?>">
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateSEOPreview() {
    var title = document.getElementById('meta_title').value ||
                document.getElementById('title').value || 'Your Page Title';
    var desc  = document.getElementById('meta_description').value ||
                'Professional landscaping services in Vancouver.';
    document.getElementById('seoPreviewTitle').textContent = title.substring(0, 60);
    document.getElementById('seoPreviewDesc').textContent  = desc.substring(0, 160);
}

document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.getElementById('add-block-csrf')
        ? document.getElementById('add-block-csrf').value
        : '<?php echo generateCSRFToken(); ?>';
    var pageId = <?php echo $pageId ?: 0; ?>;

    var blockTypeLabels = {
        hero: 'Hero Banner', cta: 'Call to Action', feature_grid: 'Feature Grid',
        service_cards: 'Service Cards', service_grid: 'Service Grid',
        testimonials: 'Testimonials', faq: 'FAQ', gallery: 'Gallery',
        before_after: 'Before / After', stats_banner: 'Stats Banner',
        rich_text: 'Rich Text', portfolio_showcase: 'Portfolio Showcase',
        area_cards: 'Area Cards', custom: 'Custom HTML',
    };

    // ---- Help tooltips ----
    document.querySelectorAll('.mw-help-tooltip').forEach(function(tip) {
        tip.addEventListener('click', function(e) {
            e.preventDefault();
            this.classList.toggle('show');
        });
    });

    // ---- Shared drag-wiring (called on existing + new rows) ----
    var list = document.getElementById('blocks-list');
    var dragging = null;

    function wireDragRow(row) {
        row.setAttribute('draggable', 'true');
        row.addEventListener('dragstart', function(e) {
            dragging = this;
            this.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', function() {
            dragging = null;
            this.style.opacity = '';
            if (list) list.querySelectorAll('.mw-block-row').forEach(function(r) { r.classList.remove('mw-block-row-over'); });
            saveBlockOrder();
        });
        row.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (this !== dragging) {
                this.classList.add('mw-block-row-over');
                var mid = this.getBoundingClientRect().y + this.getBoundingClientRect().height / 2;
                list.insertBefore(dragging, e.clientY - mid > 0 ? this.nextSibling : this);
            }
        });
        row.addEventListener('dragleave', function() { this.classList.remove('mw-block-row-over'); });
        row.addEventListener('drop', function(e) { e.preventDefault(); this.classList.remove('mw-block-row-over'); });
    }

    if (list) {
        list.querySelectorAll('.mw-block-row').forEach(wireDragRow);
    }

    function saveBlockOrder() {
        if (!list || !pageId) return;
        var order = [];
        list.querySelectorAll('.mw-block-row').forEach(function(r) { order.push(parseInt(r.dataset.blockId, 10)); });
        fetch('/crm/api/reorder-blocks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ page_id: pageId, order: order }),
        }).catch(function() {});
    }

    // ---- Delete block (event delegation — works for newly added rows too) ----
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.mw-delete-block');
        if (!btn) return;
        if (!confirm('Delete this block? This cannot be undone.')) return;
        var row = btn.closest('.mw-block-row');
        fetch('/crm/api/delete-block.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id: btn.dataset.blockId }),
        })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) { row.remove(); } else { alert('Error: ' + (d.error || 'Delete failed')); } })
        .catch(function(err) { alert('Error: ' + err.message); });
    });

    // ---- Add block — card click → AJAX → inline row inject ----
    document.querySelectorAll('.mw-block-type-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var blockType = this.dataset.blockType;
            var errEl = document.getElementById('add-block-error');
            errEl.classList.add('d-none');

            // Visual feedback — disable all cards while saving
            document.querySelectorAll('.mw-block-type-card').forEach(function(c) { c.disabled = true; });
            card.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Adding…';

            var fd = new FormData();
            fd.append('page_id', pageId);
            fd.append('block_type', blockType);
            fd.append('csrf_token', csrfToken);

            fetch('/crm/api/add-block.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) throw new Error(data.error || 'Add failed');

                // Close modal
                if (window.jQuery) { jQuery('#addBlockModal').modal('hide'); }

                // Build new row and inject into list (or replace empty-state)
                var label = blockTypeLabels[blockType] || blockType;
                var newRow = document.createElement('div');
                newRow.className = 'mw-block-row';
                newRow.dataset.blockId = data.block_id;
                newRow.innerHTML =
                    '<div class="mw-block-row-handle" title="Drag to reorder">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#adb5bd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>' +
                    '</div>' +
                    '<div class="mw-block-row-info">' +
                        '<span class="badge badge-secondary mr-2">' + label + '</span>' +
                        '<span class="text-muted small">New — click Edit to fill content</span>' +
                    '</div>' +
                    '<div class="mw-block-row-actions">' +
                        '<a href="' + data.edit_url + '" class="btn btn-sm btn-outline-primary">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
                            ' Edit' +
                        '</a>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger mw-delete-block" data-block-id="' + data.block_id + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>' +
                        '</button>' +
                    '</div>';

                // If empty-state placeholder exists, replace whole card body
                if (!list) {
                    var cardBody = document.querySelector('#addBlockModal').closest('.container-fluid').querySelector('.card-body .mw-ui-guide');
                    if (cardBody) {
                        var parent = cardBody.closest('.card-body');
                        parent.innerHTML = '<p class="small text-muted mb-2"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg> Drag rows to reorder blocks</p><div id="blocks-list"></div>';
                        list = document.getElementById('blocks-list');
                    }
                }

                if (list) {
                    list.appendChild(newRow);
                    wireDragRow(newRow);
                    // Scroll new row into view
                    newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                // Flash green border on new row
                newRow.style.borderColor = 'var(--mw-green)';
                setTimeout(function() { newRow.style.borderColor = ''; }, 2000);
            })
            .catch(function(err) {
                errEl.textContent = 'Error: ' + err.message;
                errEl.classList.remove('d-none');
            })
            .finally(function() {
                // Restore card grid
                document.querySelectorAll('.mw-block-type-card').forEach(function(c) {
                    c.disabled = false;
                    var bt = c.dataset.blockType;
                    var info = {
                        hero: ['image','Hero Banner','Full-width headline + CTA'],
                        cta: ['zap','Call to Action','Prominent button section'],
                        feature_grid: ['grid','Feature Grid','Icon + text card grid'],
                        service_cards: ['briefcase','Service Cards','Service tile grid'],
                        service_grid: ['layout','Service Grid','3-col service overview'],
                        testimonials: ['message-circle','Testimonials','Customer quotes'],
                        faq: ['help-circle','FAQ','Collapsible Q&A'],
                        gallery: ['camera','Gallery','Photo grid'],
                        before_after: ['sliders','Before / After','Side-by-side image comparison'],
                        stats_banner: ['trending-up','Stats Banner','Numbers + labels row'],
                        rich_text: ['type','Rich Text','Formatted text body'],
                        portfolio_showcase: ['star','Portfolio Showcase','Project highlights'],
                        custom: ['code','Custom HTML','Raw HTML block'],
                    }[bt] || ['box', bt, ''];
                    c.innerHTML = '<strong>' + info[1] + '</strong><span>' + info[2] + '</span>';
                });
                if (window.feather) feather.replace();
            });
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
