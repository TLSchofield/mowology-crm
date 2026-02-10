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

require_once dirname(__DIR__) . '/includes/bootstrap.php';
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

<style>
.help-tooltip {
    position: relative;
    display: inline-block;
    margin-left: 5px;
    width: 18px;
    height: 18px;
    background-color: #007bff;
    color: white;
    border-radius: 50%;
    text-align: center;
    line-height: 18px;
    font-size: 12px;
    font-weight: bold;
    cursor: help;
}

.help-tooltip:hover::after,
.help-tooltip.show::after {
    content: attr(data-help);
    position: absolute;
    bottom: 120%;
    left: -150px;
    width: 300px;
    background-color: #333;
    color: #fff;
    padding: 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: normal;
    text-align: left;
    z-index: 1000;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.help-tooltip::before {
    content: '';
    position: absolute;
    bottom: 115%;
    left: -20px;
    width: 0;
    height: 0;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
    border-top: 5px solid #333;
}

.seo-preview {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 12px;
    margin-top: 10px;
    font-size: 13px;
}

.seo-preview .title {
    color: #1a73e8;
    text-decoration: underline;
    font-weight: 600;
    margin-bottom: 4px;
}

.seo-preview .url {
    color: #006621;
    font-size: 12px;
    margin-bottom: 4px;
}

.seo-preview .description {
    color: #545454;
    line-height: 1.4;
    margin-top: 4px;
}

.ui-guide {
    background-color: #e8f5e9;
    border-left: 4px solid #4caf50;
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 2px;
}

.ui-guide strong {
    color: #2e7d32;
}

.auto-seo-badge {
    display: inline-block;
    background-color: #c8e6c9;
    color: #1b5e20;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
}
</style>

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
                        <div class="ui-guide">
                            <strong>💡 Pro Tip:</strong> Fill in your page info first, then click "Create Page" to add blocks. SEO fields auto-populate with smart defaults — edit them to customize.
                        </div>

                        <!-- Slug -->
                        <div class="form-group">
                            <label for="slug">
                                URL Slug *
                                <span class="help-tooltip" data-help="URL-safe identifier for this page. Used in the page link. Example: 'lawn-maintenance' becomes /lawn-maintenance">?</span>
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
                                <span class="help-tooltip" data-help="The main title of your page. Used for navigation and defaults for SEO meta title. Examples: 'Lawn Maintenance', 'About Mowology', 'Contact Us'">?</span>
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
                                <span class="help-tooltip" data-help="This is what appears as the title in Google search results. Keep it under 60 characters for best display. Leave empty for auto-generated default.">?</span>
                                <span class="auto-seo-badge">Auto-generated</span>
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
                                <span class="help-tooltip" data-help="This snippet appears under your title in search results. Keep it under 160 characters. Should include your target keywords naturally. Leave empty for auto-generated summary.">?</span>
                                <span class="auto-seo-badge">Auto-generated</span>
                            </label>
                            <textarea class="form-control" id="meta_description" name="meta_description"
                                      rows="2" maxlength="160"
                                      placeholder="<?php echo h($suggestedMetaDesc); ?>"
                                      onchange="updateSEOPreview()"><?php echo h($page['meta_description'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">Recommended: 120-160 characters. Bonus: Include your location and value proposition.</small>
                        </div>

                        <!-- SEO Preview -->
                        <div class="seo-preview">
                            <div class="title" id="seoPreviewTitle"><?php echo h($suggestedMetaTitle); ?></div>
                            <div class="url">mowology.ca/<?php echo h($page['slug'] ?? 'your-page'); ?></div>
                            <div class="description" id="seoPreviewDesc"><?php echo h($suggestedMetaDesc); ?></div>
                        </div>

                        <!-- Status -->
                        <div class="form-group">
                            <label for="status">
                                Status
                                <span class="help-tooltip" data-help="Draft: Not visible to public or search engines. Published: Live and visible. Archived: Hidden but kept for history.">?</span>
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
                            <p class="text-muted">No blocks added yet. Add a block to get started.</p>
                        <?php else: ?>
                            <div id="blocks-list">
                                <?php foreach ($blocks as $block): ?>
                                <div class="card mb-2" data-block-id="<?php echo $block['id']; ?>">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo ucfirst($block['block_type']); ?></strong>
                                            <small class="text-muted"><?php echo $block['label'] ?? 'Block'; ?></small>
                                        </div>
                                        <div>
                                            <a href="/crm/cms-block-editor.php?block_id=<?php echo $block['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                            <button type="button" class="btn btn-sm btn-danger delete-block" data-block-id="<?php echo $block['id']; ?>">Delete</button>
                                        </div>
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
                    <a href="<?php echo h($page['slug']); ?>" target="_blank" class="btn btn-outline-primary btn-lg float-right">
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
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Select a block type to add:</p>
                <form id="add-block-form" method="POST" action="/crm/api/add-block.php">
                    <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-group">
                        <label for="block_type">Block Type</label>
                        <select class="form-control" id="block_type" name="block_type" required>
                            <option value="">-- Select --</option>
                            <option value="hero">Hero Banner</option>
                            <option value="feature_grid">Feature Grid</option>
                            <option value="testimonials">Testimonials</option>
                            <option value="cta">Call to Action</option>
                            <option value="faq">FAQ</option>
                            <option value="gallery">Gallery</option>
                            <option value="service_cards">Service Cards</option>
                            <option value="rich_text">Rich Text</option>
                            <option value="portfolio_showcase">Portfolio Showcase</option>
                            <option value="custom">Custom HTML</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Add Block</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateSEOPreview() {
    const title = document.getElementById('meta_title').value ||
                  document.getElementById('title').value || 'Your Page Title';
    const desc = document.getElementById('meta_description').value ||
                 'Professional landscaping services in Vancouver. Expert care for your landscape.';

    document.getElementById('seoPreviewTitle').textContent = title.substring(0, 60);
    document.getElementById('seoPreviewDesc').textContent = desc.substring(0, 160);
}

document.addEventListener('DOMContentLoaded', function() {
    // Show all help tooltips on mobile
    const tooltips = document.querySelectorAll('.help-tooltip');
    tooltips.forEach(tip => {
        tip.addEventListener('click', function(e) {
            e.preventDefault();
            this.classList.toggle('show');
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
