<?php
/**
 * Shared AppStack Sidebar for CRM.
 *
 * Expected variables:
 *   $user         — array with at least 'name' key (from getCurrentUser())
 *   $activePage   — string matching a key below to highlight the active nav item
 *
 * Active page keys: 'dashboard', 'clients', 'map', 'quotes', 'products', 'jobs',
 *                   'invoices', 'schedule', 'timeclock', 'team', 'portfolio', 'cms', 'media', 'marketing', 'settings', 'database', 'diagnostics'
 */
if (!isset($activePage)) $activePage = '';
if (!isset($user))       $user = ['name' => 'Admin'];

$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard',     'icon' => 'sliders',     'href' => '/crm/dashboard_appstack.php'],
    ['key' => 'clients',   'label' => 'Clients',       'icon' => 'users',       'href' => '/crm/clients_appstack.php'],
    ['key' => 'quotes',    'label' => 'Quotes',        'icon' => 'dollar-sign', 'href' => '/crm/quotes_appstack.php'],
    ['key' => 'jobs',      'label' => 'Jobs',          'icon' => 'briefcase',   'href' => '/crm/jobs/index.php'],
    ['key' => 'invoices',  'label' => 'Invoices',      'icon' => 'file-text',   'href' => '/crm/invoices/index.php'],
    ['key' => 'schedule',  'label' => 'Schedule',      'icon' => 'calendar',    'href' => '/crm/jobs/schedule.php'],
    ['key' => 'timeclock', 'label' => 'Time Clock',    'icon' => 'clock',       'href' => '/crm/timeclock/my-schedule.php'],
    ['key' => 'team',      'label' => 'Team',          'icon' => 'user-check',  'href' => '/crm/team/index.php'],
    ['key' => 'map',       'label' => 'Territory Map', 'icon' => 'map',         'href' => '/crm/map_appstack.php'],
    ['key' => 'products',  'label' => 'Products',      'icon' => 'package',     'href' => '/crm/products/index.php'],
    ['key' => 'portfolio', 'label' => 'Portfolio',     'icon' => 'image',       'href' => '/crm/portfolio/index.php'],
    ['key' => 'cms',       'label' => 'CMS',          'icon' => 'edit-3',       'href' => '/crm/cms-pages_appstack.php'],
    ['key' => 'media',     'label' => 'Media Library', 'icon' => 'image',       'href' => '/cms/cms-media_appstack.php'],
    ['key' => 'marketing', 'label' => 'Marketing',     'icon' => 'zap',         'href' => '/crm/marketing/recommendations.php'],
    ['key' => 'weather-ops', 'label' => 'Weather Ops',  'icon' => 'cloud-lightning', 'href' => '/crm/ops/weather_actions.php'],
];
?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/dashboard_appstack.php">
            <span class="align-middle" style="font-size:1.25rem; letter-spacing:-0.5px;">Mowology</span>
        </a>

        <ul class="sidebar-nav">
            <li class="sidebar-header">Navigation</li>

            <?php foreach ($navItems as $item): ?>
            <li class="sidebar-item<?php echo ($activePage === $item['key']) ? ' active' : ''; ?>">
                <a class="sidebar-link" href="<?php echo $item['href']; ?>">
                    <i class="align-middle" data-feather="<?php echo $item['icon']; ?>"></i>
                    <span class="align-middle"><?php echo $item['label']; ?></span>
                </a>
            </li>
            <?php endforeach; ?>

            <li class="sidebar-header">Settings</li>

            <li class="sidebar-item<?php echo ($activePage === 'settings') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/settings.php">
                    <i class="align-middle" data-feather="settings"></i>
                    <span class="align-middle">Settings</span>
                </a>
            </li>

            <li class="sidebar-item<?php echo ($activePage === 'database') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/database_appstack.php">
                    <i class="align-middle" data-feather="database"></i>
                    <span class="align-middle">Database</span>
                </a>
            </li>

            <li class="sidebar-item<?php echo ($activePage === 'diagnostics') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/diagnostics/">
                    <i class="align-middle" data-feather="activity"></i>
                    <span class="align-middle">Diagnostics</span>
                </a>
            </li>

            <li class="sidebar-item<?php echo ($activePage === 'ops-weather') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/admin/ops_weather.php">
                    <i class="align-middle" data-feather="cloud-rain"></i>
                    <span class="align-middle">Ops Weather</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
