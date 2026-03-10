<?php
/**
 * Shared AppStack Sidebar for CRM.
 *
 * Expected variables:
 *   $user         — array with at least 'name' key (from getCurrentUser())
 *   $activePage   — string matching a key below to highlight the active nav item
 *
 * Active page keys: 'dashboard', 'clients', 'companies', 'quotes', 'jobs',
 *                   'invoices', 'schedule', 'timeclock', 'expenses',
 *                   'profitability', 'cost-factors', 'intel', 'marketing', 'social', 'cms', 'media',
 *                   'team', 'leaderboard', 'quiz', 'map', 'photos', 'products', 'portfolio',
 *                   'work-zones', 'users', 'settings'
 *
 * Nav items support two types:
 *   - Section headers: ['type' => 'header', 'label' => 'Section Name']
 *   - Nav links:       ['key' => '...', 'label' => '...', 'icon' => '...', 'href' => '...', 'perm' => '...']
 *
 * The 'perm' key is optional — if present, the item is hidden if the user
 * lacks that permission. The page itself still enforces access control.
 */
if (!isset($activePage)) $activePage = '';
if (!isset($user))       $user = ['name' => 'Admin'];

// Unread message count for badge (silent fail if messages table not yet created)
$_mwUnreadMessages = 0;
try {
    if (isset($user['id'])) {
        $__msgStmt = getDB()->prepare("SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0");
        $__msgStmt->execute([(int)$user['id']]);
        $_mwUnreadMessages = (int)$__msgStmt->fetchColumn();
    }
} catch (Throwable $__e) { /* table may not exist yet */ }

// Overdue task count + pending purchase count for badge (silent fail if tasks table not yet created)
$_mwOverdueTasks = 0;
$_mwPendingPurchases = 0;
try {
    if (isset($user['id'])) {
        $__taskStmt = getDB()->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed' AND due_date IS NOT NULL AND due_date < CURDATE()");
        $__taskStmt->execute([(int)$user['id']]);
        $_mwOverdueTasks = (int)$__taskStmt->fetchColumn();

        $__purStmt = getDB()->query("SELECT COUNT(*) FROM tasks WHERE task_type = 'purchase' AND (purchase_status IS NULL OR purchase_status NOT IN ('verified'))");
        $_mwPendingPurchases = (int)$__purStmt->fetchColumn();
    }
} catch (Throwable $__e) { /* table may not exist yet */ }
$_mwTaskBadge = $_mwOverdueTasks + $_mwPendingPurchases;

$navItems = [

    // ── Overview ──────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Overview'],
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'sliders', 'href' => '/crm/dashboard_appstack.php'],

    // ── Clients ───────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Clients'],
    ['key' => 'clients',   'label' => 'Contacts',  'icon' => 'users',   'href' => '/crm/clients_appstack.php',    'perm' => 'clients.view'],
    ['key' => 'companies', 'label' => 'Companies', 'icon' => 'home',    'href' => '/crm/companies/index.php',     'perm' => 'clients.view'],

    // ── Pipeline ──────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Pipeline'],
    ['key' => 'tasks',     'label' => 'Tasks',     'icon' => 'check-square', 'href' => '/crm/tasks_appstack.php', 'badge' => $_mwTaskBadge],
    ['key' => 'quotes',    'label' => 'Quotes',    'icon' => 'dollar-sign', 'href' => '/crm/quotes_appstack.php',    'perm' => 'billing.view'],
    ['key' => 'contracts', 'label' => 'Contracts', 'icon' => 'pen-tool', 'href' => '/crm/contracts_appstack.php', 'perm' => 'jobs.view'],
    ['key' => 'jobs',      'label' => 'Jobs',      'icon' => 'briefcase',  'href' => '/crm/jobs/index.php',         'perm' => 'jobs.view'],
    ['key' => 'invoices',  'label' => 'Invoices',  'icon' => 'file-text',  'href' => '/crm/invoices/index.php',     'perm' => 'billing.view'],

    // ── Schedule ──────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Schedule'],
    ['key' => 'schedule',    'label' => 'Schedule',    'icon' => 'calendar', 'href' => '/crm/jobs/schedule.php',          'perm' => 'schedule.view'],
    ['key' => 'timeclock',   'label' => 'Time Clock',  'icon' => 'clock',    'href' => '/crm/timeclock/my-schedule.php',  'perm' => 'schedule.view'],
    ['key' => 'work-zones',  'label' => 'Work Zones',  'icon' => 'map-pin',  'href' => '/crm/zone-report_appstack.php',   'perm' => 'jobs.view'],

    // ── Financials ────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Financials'],
    ['key' => 'expenses',      'label' => 'Expenses',      'icon' => 'credit-card', 'href' => '/crm/expenses_appstack.php',          'perm' => 'expenses.view'],
    ['key' => 'profitability', 'label' => 'Profitability', 'icon' => 'trending-up', 'href' => '/crm/profitability_appstack.php',     'perm' => 'expenses.view'],
    ['key' => 'cost-factors',  'label' => 'Cost Factors',  'icon' => 'sliders',     'href' => '/crm/products/cost-factors.php',      'perm' => 'expenses.view'],
    ['key' => 'reports',      'label' => 'Reports',       'icon' => 'bar-chart-2', 'href' => '/crm/reports_appstack.php',           'perm' => 'expenses.view'],

    // ── Growth ────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Growth'],
    ['key' => 'intel',     'label' => 'Marketing Intel',  'icon' => 'target',   'href' => '/crm/marketing/intel.php',      'perm' => 'marketing.view'],
    ['key' => 'marketing', 'label' => 'Email Campaigns', 'icon' => 'zap',      'href' => '/crm/marketing/campaigns.php', 'perm' => 'marketing.view'],
    ['key' => 'social',    'label' => 'Social Posts',    'icon' => 'share-2',  'href' => '/crm/marketing/social.php',   'perm' => 'marketing.view'],
    ['key' => 'referrals', 'label' => 'Referrals',       'icon' => 'gift',     'href' => '/crm/marketing/referrals.php', 'perm' => 'marketing.view'],
    ['key' => 'cms',       'label' => 'Website CMS',     'icon' => 'edit-3',   'href' => '/crm/cms-pages_appstack.php', 'perm' => 'marketing.edit'],

    // ── Fleet ─────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Fleet'],
    ['key' => 'driver',       'label' => 'Driver Portal',   'icon' => 'truck',       'href' => '/crm/driver-portal.php'],
    ['key' => 'trip-reports', 'label' => 'Trip Reports',    'icon' => 'clipboard',   'href' => '/crm/trip-reports_appstack.php', 'perm' => 'team.view'],

    // ── Communications ────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Communications'],
    ['key' => 'messages', 'label' => 'Messages', 'icon' => 'message-circle', 'href' => '/crm/messages_appstack.php', 'badge' => $_mwUnreadMessages],

    // ── Team ──────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Team'],
    ['key' => 'team',        'label' => 'Team',           'icon' => 'user-check',  'href' => '/crm/team/index.php',            'perm' => 'team.view'],
    ['key' => 'leaderboard', 'label' => 'Leaderboard',    'icon' => 'award',       'href' => '/crm/leaderboard_appstack.php',  'perm' => 'team.view'],
    ['key' => 'quiz',        'label' => 'Knowledge Quiz', 'icon' => 'book-open',   'href' => '/crm/quiz_appstack.php'],
    ['key' => 'map',         'label' => 'Territory Map',  'icon' => 'map',         'href' => '/crm/map_appstack.php',          'perm' => 'jobs.view'],

    // ── Library ───────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Library'],
    ['key' => 'photos',    'label' => 'Photo Timeline', 'icon' => 'camera',  'href' => '/crm/photos_appstack.php',        'perm' => 'jobs.view'],
    ['key' => 'products',  'label' => 'Products',      'icon' => 'package',  'href' => '/crm/products/index.php',         'perm' => 'products.view'],
    ['key' => 'portfolio',     'label' => 'Portfolio',       'icon' => 'image',    'href' => '/crm/portfolio/index.php',        'perm' => 'portfolio.view'],
    ['key' => 'before-after', 'label' => 'Before & After', 'icon' => 'columns',  'href' => '/crm/before-after_appstack.php',  'perm' => 'portfolio.view'],
    ['key' => 'media',         'label' => 'Media Library',  'icon' => 'folder',   'href' => '/cms/cms-media_appstack.php',     'perm' => 'photos.upload'],

];
?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/dashboard_appstack.php">
            <span class="align-middle" style="font-size:1.25rem; letter-spacing:-0.5px;">Mowology</span>
        </a>

        <ul class="sidebar-nav">

            <?php foreach ($navItems as $item):

                // Section header
                if (isset($item['type']) && $item['type'] === 'header'): ?>
                    <li class="sidebar-header"><?php echo htmlspecialchars($item['label']); ?></li>
                <?php continue; endif;

                // Permission check
                if (isset($item['perm']) && function_exists('userHasPermission') && !userHasPermission($item['perm'])) continue;
            ?>
            <li class="sidebar-item<?php echo ($activePage === $item['key']) ? ' active' : ''; ?>">
                <a class="sidebar-link" href="<?php echo $item['href']; ?>">
                    <i class="align-middle" data-feather="<?php echo $item['icon']; ?>"></i>
                    <span class="align-middle"><?php echo $item['label']; ?></span>
                    <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                    <span class="mw-sidebar-badge mw-msg-sidebar-badge"><?php echo (int)$item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>

            <li class="sidebar-header">Admin</li>

            <?php if (!function_exists('userHasPermission') || userHasPermission('settings.edit')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'audit-trail') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/audit-trail_appstack.php">
                    <i class="align-middle" data-feather="shield"></i>
                    <span class="align-middle">Audit Trail</span>
                </a>
            </li>
            <li class="sidebar-item<?php echo ($activePage === 'import') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/import_appstack.php">
                    <i class="align-middle" data-feather="upload"></i>
                    <span class="align-middle">Import Data</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('database.manage')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'sites') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/sites_appstack.php">
                    <i class="align-middle" data-feather="globe"></i>
                    <span class="align-middle">Tenant Sites</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('users.manage')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'users') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/users_appstack.php">
                    <i class="align-middle" data-feather="user-plus"></i>
                    <span class="align-middle">Users &amp; Roles</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('settings.edit')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'settings') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/settings.php">
                    <i class="align-middle" data-feather="settings"></i>
                    <span class="align-middle">Settings</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Install App (PWA) — hidden when already installed or on desktop -->
            <li class="sidebar-item" id="mw-pwa-sidebar-item">
                <a class="sidebar-link" href="#" id="mw-pwa-sidebar-link"
                   onclick="event.preventDefault();if(window.mwDeferredInstall){window.mwDeferredInstall.prompt();}else{alert('Tap your browser menu → Add to Home Screen');}">
                    <i class="align-middle" data-feather="download"></i>
                    <span class="align-middle">Install App</span>
                </a>
            </li>

        </ul>
    </div>
</nav>
