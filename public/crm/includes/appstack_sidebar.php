<?php
/**
 * Shared AppStack Sidebar for CRM.
 *
 * Expected variables:
 *   $user         — array with at least 'name' key (from getCurrentUser())
 *   $activePage   — string matching a key below to highlight the active nav item
 *
 * Active page keys: 'dashboard', 'clients', 'map', 'quotes', 'products', 'jobs',
 *                   'invoices', 'schedule', 'timeclock', 'team', 'portfolio', 'cms',
 *                   'media', 'marketing', 'settings', 'database', 'diagnostics', 'users'
 *
 * Each nav item can optionally specify a 'perm' key — the permission required to see it.
 * If omitted, the item is always visible. The server still enforces on the page itself;
 * this just hides links the user can't access.
 */
if (!isset($activePage)) $activePage = '';
if (!isset($user))       $user = ['name' => 'Admin'];

$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard',     'icon' => 'sliders',     'href' => '/crm/dashboard_appstack.php'],
    ['key' => 'clients',   'label' => 'Clients',       'icon' => 'users',       'href' => '/crm/clients_appstack.php',          'perm' => 'clients.view'],
    ['key' => 'companies', 'label' => 'Companies',     'icon' => 'home',        'href' => '/crm/companies/index.php',           'perm' => 'clients.view'],
    ['key' => 'quotes',    'label' => 'Quotes',        'icon' => 'dollar-sign', 'href' => '/crm/quotes_appstack.php',           'perm' => 'billing.view'],
    ['key' => 'jobs',      'label' => 'Jobs',          'icon' => 'briefcase',   'href' => '/crm/jobs/index.php',                'perm' => 'jobs.view'],
    ['key' => 'invoices',  'label' => 'Invoices',      'icon' => 'file-text',   'href' => '/crm/invoices/index.php',            'perm' => 'billing.view'],
    ['key' => 'expenses',  'label' => 'Expenses',      'icon' => 'credit-card', 'href' => '/crm/expenses_appstack.php',         'perm' => 'expenses.view'],
    ['key' => 'schedule',  'label' => 'Schedule',      'icon' => 'calendar',    'href' => '/crm/jobs/schedule.php',             'perm' => 'schedule.view'],
    ['key' => 'timeclock', 'label' => 'Time Clock',    'icon' => 'clock',       'href' => '/crm/timeclock/my-schedule.php',     'perm' => 'schedule.view'],
    ['key' => 'team',      'label' => 'Team',          'icon' => 'user-check',  'href' => '/crm/team/index.php',                'perm' => 'team.view'],
    ['key' => 'map',       'label' => 'Territory Map', 'icon' => 'map',         'href' => '/crm/map_appstack.php',              'perm' => 'jobs.view'],
    ['key' => 'products',  'label' => 'Products',      'icon' => 'package',     'href' => '/crm/products/index.php',            'perm' => 'products.view'],
    ['key' => 'portfolio', 'label' => 'Portfolio',     'icon' => 'image',       'href' => '/crm/portfolio/index.php',           'perm' => 'portfolio.view'],
    ['key' => 'cms',       'label' => 'CMS',          'icon' => 'edit-3',       'href' => '/crm/cms-pages_appstack.php',        'perm' => 'marketing.edit'],
    ['key' => 'media',     'label' => 'Media Library', 'icon' => 'image',       'href' => '/cms/cms-media_appstack.php',        'perm' => 'photos.upload'],
    ['key' => 'marketing', 'label' => 'Marketing',     'icon' => 'zap',         'href' => '/crm/marketing/recommendations.php', 'perm' => 'marketing.view'],
    ['key' => 'weather-ops', 'label' => 'Weather Ops',  'icon' => 'cloud-lightning', 'href' => '/crm/ops/weather_actions.php',  'perm' => 'schedule.edit'],
];
?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/dashboard_appstack.php">
            <span class="align-middle" style="font-size:1.25rem; letter-spacing:-0.5px;">Mowology</span>
        </a>

        <ul class="sidebar-nav">
            <li class="sidebar-header">Navigation</li>

            <?php foreach ($navItems as $item):
                // Skip items the user lacks permission for
                if (isset($item['perm']) && function_exists('userHasPermission') && !userHasPermission($item['perm'])) continue;
            ?>
            <li class="sidebar-item<?php echo ($activePage === $item['key']) ? ' active' : ''; ?>">
                <a class="sidebar-link" href="<?php echo $item['href']; ?>">
                    <i class="align-middle" data-feather="<?php echo $item['icon']; ?>"></i>
                    <span class="align-middle"><?php echo $item['label']; ?></span>
                </a>
            </li>
            <?php endforeach; ?>

            <li class="sidebar-header">Admin</li>

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

            <?php if (!function_exists('userHasPermission') || userHasPermission('database.manage')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'database') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/database_appstack.php">
                    <i class="align-middle" data-feather="database"></i>
                    <span class="align-middle">Database</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('diagnostics.view')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'diagnostics') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/diagnostics/">
                    <i class="align-middle" data-feather="activity"></i>
                    <span class="align-middle">Diagnostics</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('settings.edit')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'ops-weather') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/admin/ops_weather.php">
                    <i class="align-middle" data-feather="cloud-rain"></i>
                    <span class="align-middle">Ops Weather</span>
                </a>
            </li>
            <?php endif; ?>
            <!-- Install App (hidden when already installed or not available) -->
            <li class="sidebar-item" id="mw-pwa-sidebar-item" style="display:none;">
                <a class="sidebar-link" href="#" id="mw-pwa-sidebar-link" onclick="return false;">
                    <i class="align-middle" data-feather="download"></i>
                    <span class="align-middle">Install App</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
