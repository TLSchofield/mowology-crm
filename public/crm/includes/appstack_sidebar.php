<?php
/**
 * Shared AppStack Sidebar for CRM.
 *
 * Expected variables:
 *   $user         — array with at least 'name' key (from getCurrentUser())
 *   $activePage   — string matching a key below to highlight the active nav item
 *
 * Active page keys: 'dashboard', 'clients', 'map', 'quotes', 'products', 'jobs',
 *                   'invoices', 'schedule', 'portfolio', 'settings'
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
    ['key' => 'map',       'label' => 'Territory Map', 'icon' => 'map',         'href' => '/crm/map_appstack.php'],
    ['key' => 'products',  'label' => 'Products',      'icon' => 'package',     'href' => '/crm/products/index.php'],
    ['key' => 'portfolio', 'label' => 'Portfolio',     'icon' => 'image',       'href' => '/crm/portfolio/index.php'],
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
        </ul>
    </div>
</nav>
