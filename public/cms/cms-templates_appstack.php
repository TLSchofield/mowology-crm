<?php
/**
 * CMS Templates Manager
 *
 * Manage page and block templates, presets, and template groups
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/crm/includes/cms-functions.php';
require_once dirname(__DIR__) . '/crm/includes/cms-template-functions.php';
require_once dirname(__DIR__) . '/crm/includes/admin-ui-kit.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin'])) {
    http_response_code(403);
    die('Access denied');
}

$pageTitle = 'CMS Templates';
$activePage = 'cms';
$tab = $_GET['tab'] ?? 'page_templates';

// Get templates based on tab
$pageTemplates = cms_getPageTemplates();
$blockTemplates = cms_getBlockTemplates();
$presets = cms_getTemplatePresets();
$groups = cms_getFeaturedTemplateGroups();

?>
<?php include dirname(__DIR__) . '/crm/includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0">Templates</h1>
            <small class="text-muted">Manage page templates, block templates, and presets</small>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'page_templates' ? 'active' : ''; ?>"
               href="?tab=page_templates">
                Page Templates (<?php echo count($pageTemplates); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'block_templates' ? 'active' : ''; ?>"
               href="?tab=block_templates">
                Block Templates (<?php echo count($blockTemplates); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'presets' ? 'active' : ''; ?>"
               href="?tab=presets">
                Presets (<?php echo count($presets); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'groups' ? 'active' : ''; ?>"
               href="?tab=groups">
                Template Groups
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'performance' ? 'active' : ''; ?>"
               href="?tab=performance">
                Performance
            </a>
        </li>
    </ul>

    <!-- PAGE TEMPLATES TAB -->
    <?php if ($tab === 'page_templates'): ?>
    <div class="tab-pane active">
        <div class="mb-3">
            <a href="/crm/cms-template-editor.php?type=page&action=create" class="btn btn-primary">
                <i data-feather="plus"></i> New Page Template
            </a>
        </div>

        <?php echo admin_table($pageTemplates, [
            'label' => [
                'label' => 'Template Name',
                'width' => '30%',
            ],
            'category' => [
                'label' => 'Category',
                'width' => '15%',
            ],
            'page_type' => [
                'label' => 'Page Type',
                'badge' => true,
                'width' => '15%',
            ],
            'usage_count' => [
                'label' => 'Used',
                'width' => '10%',
                'align' => 'right',
            ],
            'version' => [
                'label' => 'Version',
                'width' => '10%',
                'align' => 'center',
            ],
        ], [
            'row_actions' => [
                ['label' => 'Edit', 'icon' => 'edit-2', 'href' => '/crm/cms-template-editor.php?type=page&id={{id}}'],
                ['label' => 'Use', 'icon' => 'copy', 'href' => '/crm/cms-page-editor.php?template={{template_key}}'],
                ['label' => 'Delete', 'icon' => 'trash-2', 'action' => 'delete', 'confirm' => true],
            ],
            'empty_text' => 'No page templates found',
        ]); ?>
    </div>
    <?php endif; ?>

    <!-- BLOCK TEMPLATES TAB -->
    <?php if ($tab === 'block_templates'): ?>
    <div class="tab-pane active">
        <div class="mb-3">
            <a href="/crm/cms-template-editor.php?type=block&action=create" class="btn btn-primary">
                <i data-feather="plus"></i> New Block Template
            </a>
        </div>

        <?php echo admin_table($blockTemplates, [
            'label' => [
                'label' => 'Block Template',
                'width' => '30%',
            ],
            'block_type' => [
                'label' => 'Block Type',
                'width' => '20%',
            ],
            'category' => [
                'label' => 'Category',
                'width' => '15%',
            ],
            'usage_count' => [
                'label' => 'Used',
                'width' => '10%',
                'align' => 'right',
            ],
            'version' => [
                'label' => 'Version',
                'width' => '10%',
                'align' => 'center',
            ],
        ], [
            'row_actions' => [
                ['label' => 'Edit', 'icon' => 'edit-2', 'href' => '/crm/cms-template-editor.php?type=block&id={{id}}'],
                ['label' => 'Delete', 'icon' => 'trash-2', 'action' => 'delete', 'confirm' => true],
            ],
            'empty_text' => 'No block templates found',
        ]); ?>
    </div>
    <?php endif; ?>

    <!-- PRESETS TAB -->
    <?php if ($tab === 'presets'): ?>
    <div class="tab-pane active">
        <div class="mb-3">
            <a href="/crm/cms-preset-editor.php?action=create" class="btn btn-primary">
                <i data-feather="plus"></i> New Preset
            </a>
        </div>

        <?php if (empty($presets)): ?>
            <?php echo admin_empty_state(
                'No presets yet',
                'Save template customizations as presets for quick reuse',
                ['url' => '/crm/cms-preset-editor.php?action=create', 'label' => 'Create Preset']
            ); ?>
        <?php else: ?>
        <div class="row">
            <?php foreach ($presets as $preset): ?>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo h($preset['label']); ?></h5>
                        <p class="card-text text-muted small"><?php echo h($preset['description'] ?? ''); ?></p>
                        <p class="small mb-0">
                            <strong>Used:</strong> <?php echo $preset['applied_to_count']; ?> times
                        </p>
                    </div>
                    <div class="card-footer">
                        <a href="/crm/cms-preset-editor.php?id=<?php echo $preset['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                        <button type="button" class="btn btn-sm btn-danger delete-preset" data-preset-id="<?php echo $preset['id']; ?>">Delete</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- GROUPS TAB -->
    <?php if ($tab === 'groups'): ?>
    <div class="tab-pane active">
        <div class="mb-3">
            <a href="/crm/cms-template-group-editor.php?action=create" class="btn btn-primary">
                <i data-feather="plus"></i> New Group
            </a>
        </div>

        <?php if (empty($groups)): ?>
            <?php echo admin_empty_state(
                'No template groups',
                'Organize templates into groups for easier discovery',
                ['url' => '/crm/cms-template-group-editor.php?action=create', 'label' => 'Create Group']
            ); ?>
        <?php else: ?>
        <div class="row">
            <?php foreach ($groups as $group): ?>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo h($group['label']); ?></h5>
                        <p class="card-text text-muted small"><?php echo h($group['description'] ?? ''); ?></p>
                        <p class="small mb-0">
                            <strong>Templates:</strong> <?php echo count($group['members']); ?>
                        </p>
                    </div>
                    <div class="card-footer">
                        <a href="/crm/cms-template-group-editor.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                        <button type="button" class="btn btn-sm btn-danger delete-group" data-group-id="<?php echo $group['id']; ?>">Delete</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- PERFORMANCE TAB -->
    <?php if ($tab === 'performance'): ?>
    <div class="tab-pane active">
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div style="font-size: 2rem; font-weight: bold; color: #007bff;">
                            <?php echo count($pageTemplates) + count($blockTemplates); ?>
                        </div>
                        <small class="text-muted">Total Templates</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div style="font-size: 2rem; font-weight: bold; color: #28a745;">
                            <?php echo array_sum(array_map(fn($t) => $t['usage_count'] ?? 0, array_merge($pageTemplates, $blockTemplates))); ?>
                        </div>
                        <small class="text-muted">Total Uses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div style="font-size: 2rem; font-weight: bold; color: #ffc107;">
                            <?php echo count($presets); ?>
                        </div>
                        <small class="text-muted">Presets Created</small>
                    </div>
                </div>
            </div>
        </div>

        <h5>Most Used Page Templates</h5>
        <?php
        $topPageTemplates = array_slice(
            array_sort($pageTemplates, fn($a, $b) => ($b['usage_count'] ?? 0) <=> ($a['usage_count'] ?? 0)),
            0,
            10
        );
        ?>
        <?php if (empty($topPageTemplates)): ?>
        <p class="text-muted">No usage data yet</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Template</th>
                    <th class="text-right">Uses</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topPageTemplates as $t): ?>
                <tr>
                    <td><?php echo h($t['label']); ?></td>
                    <td class="text-right"><?php echo $t['usage_count'] ?? 0; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Hidden CSRF token for JS delete actions -->
<input type="hidden" id="csrf_token" value="<?php echo generateCSRFToken(); ?>">

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.getElementById('csrf_token').value;

    function apiDelete(url, id, element, confirmMsg) {
        if (!confirm(confirmMsg || 'Delete this item?')) return;
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({ id: id }),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (element) element.closest('.col-md-6, tr').remove();
            } else {
                alert('Error: ' + (data.error || 'Delete failed'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
    }

    // Delete page template
    document.querySelectorAll('[data-action="delete"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var row = this.closest('tr');
            var id = row ? row.dataset.id : null;
            if (!id) return;
            apiDelete('/crm/api/delete-page-template.php', id, row, 'Delete this template?');
        });
    });

    // Delete preset
    document.querySelectorAll('.delete-preset').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.dataset.presetId;
            apiDelete('/crm/api/delete-preset.php', id, this, 'Delete this preset?');
        });
    });

    // Delete group
    document.querySelectorAll('.delete-group').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.dataset.groupId;
            apiDelete('/crm/api/delete-group.php', id, this, 'Delete this template group?');
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/crm/includes/appstack_footer.php'; ?>
