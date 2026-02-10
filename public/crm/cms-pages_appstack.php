<?php
/**
 * CMS Pages Admin Page
 *
 * Manage all CMS pages: list, create, edit, delete, publish
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

$pageTitle = 'CMS Pages';
$activePage = 'cms';

// Get filter values
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);

// Fetch pages
$allPages = cms_getPublishedPages();

// Apply filters
$pages = $allPages;
if ($statusFilter) {
    $pages = array_filter($pages, fn($p) => $p['status'] === $statusFilter);
}
if ($typeFilter) {
    $pages = array_filter($pages, fn($p) => $p['page_type'] === $typeFilter);
}
if ($searchFilter) {
    $search = strtolower($searchFilter);
    $pages = array_filter($pages, fn($p) =>
        stripos($p['title'] ?? '', $search) !== false ||
        stripos($p['slug'] ?? '', $search) !== false
    );
}

// Sort by date (newest first)
usort($pages, fn($a, $b) => strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0));

// Prepare for table rendering
foreach ($pages as &$p) {
    $p['status_badge'] = match($p['status']) {
        'published' => 'success',
        'draft' => 'warning',
        'archived' => 'secondary',
        default => 'secondary',
    };
    $p['type_display'] = ucfirst(str_replace('_', ' ', $p['page_type']));
    $p['edit_url'] = "/crm/cms-page-editor.php?id=" . $p['id'];
    $p['view_url'] = "/" . $p['slug'];
    $p['created_date'] = date('M d, Y', strtotime($p['created_at']));
}

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0">CMS Pages</h1>
            <small class="text-muted">Manage your website pages</small>
        </div>
        <a href="/crm/cms-page-editor.php?action=create" class="btn btn-primary">
            <i data-feather="plus"></i> New Page
        </a>
    </div>

    <!-- Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left border-primary">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #007bff;">
                        <?php echo count(array_filter($allPages, fn($p) => $p['status'] === 'published')); ?>
                    </div>
                    <small class="text-muted">Published</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left border-warning">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #ffc107;">
                        <?php echo count(array_filter($allPages, fn($p) => $p['status'] === 'draft')); ?>
                    </div>
                    <small class="text-muted">Drafts</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left border-success">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #28a745;">
                        <?php echo array_sum(array_map(fn($p) => $p['view_count'] ?? 0, $allPages)); ?>
                    </div>
                    <small class="text-muted">Total Views</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left border-info">
                <div class="card-body">
                    <div style="font-size: 1.5rem; font-weight: bold; color: #17a2b8;">
                        <?php echo count($allPages); ?>
                    </div>
                    <small class="text-muted">Total Pages</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <?php echo admin_filter([
        'search' => [
            'type' => 'text',
            'label' => 'Search',
            'placeholder' => 'Title or slug...',
        ],
        'status' => [
            'type' => 'select',
            'label' => 'Status',
            'options' => [
                'published' => 'Published',
                'draft' => 'Draft',
                'archived' => 'Archived',
            ],
        ],
        'type' => [
            'type' => 'select',
            'label' => 'Type',
            'options' => [
                'home' => 'Homepage',
                'services' => 'Services',
                'service_landing' => 'Service Landing',
                'portfolio' => 'Portfolio',
                'contact' => 'Contact',
                'custom' => 'Custom',
                'landing' => 'Landing',
            ],
        ],
    ], [
        'search' => $searchFilter,
        'status' => $statusFilter,
        'type' => $typeFilter,
    ]); ?>

    <!-- Pages Table -->
    <?php if (empty($pages)): ?>
        <?php echo admin_empty_state(
            'No pages found',
            'Create your first page or adjust your filters',
            ['url' => '/crm/cms-page-editor.php?action=create', 'label' => 'Create Page']
        ); ?>
    <?php else: ?>
        <?php echo admin_table($pages, [
            'title' => [
                'label' => 'Title',
                'sortable' => true,
                'width' => '35%',
            ],
            'slug' => [
                'label' => 'Slug',
                'width' => '20%',
            ],
            'type_display' => [
                'label' => 'Type',
                'width' => '15%',
            ],
            'status' => [
                'label' => 'Status',
                'badge' => true,
                'badge_variant' => ['published' => 'success', 'draft' => 'warning', 'archived' => 'secondary'],
                'width' => '12%',
            ],
            'created_date' => [
                'label' => 'Created',
                'width' => '12%',
                'align' => 'right',
            ],
        ], [
            'row_actions' => [
                ['label' => 'Edit', 'icon' => 'edit-2', 'href' => '/crm/cms-page-editor.php?id={{id}}'],
                ['label' => 'View', 'icon' => 'external-link', 'href' => '/{{slug}}', 'target' => '_blank'],
                ['label' => 'Delete', 'icon' => 'trash-2', 'action' => 'delete', 'confirm' => true],
            ],
            'empty_text' => 'No pages found',
            'hover' => true,
            'striped' => true,
        ]); ?>
    <?php endif; ?>
</div>

<!-- Scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Delete action handler
    document.querySelectorAll('[data-action="delete"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this page?')) return;

            const row = this.closest('tr');
            const pageId = row.dataset.id;

            fetch('/crm/api/delete-page.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('[name="csrf_token"]')?.value || '',
                },
                body: JSON.stringify({ id: pageId }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    row.remove();
                    alert('Page deleted successfully');
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
