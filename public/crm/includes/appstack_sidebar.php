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
 *                   'profitability', 'marketing', 'social', 'cms', 'media',
 *                   'team', 'leaderboard', 'map', 'products', 'portfolio',
 *                   'users', 'settings'
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
    ['key' => 'quotes',    'label' => 'Quotes',    'icon' => 'dollar-sign', 'href' => '/crm/quotes_appstack.php',    'perm' => 'billing.view'],
    ['key' => 'contracts', 'label' => 'Contracts', 'icon' => 'pen-tool', 'href' => '/crm/contracts_appstack.php', 'perm' => 'jobs.view'],
    ['key' => 'jobs',      'label' => 'Jobs',      'icon' => 'briefcase',  'href' => '/crm/jobs/index.php',         'perm' => 'jobs.view'],
    ['key' => 'invoices',  'label' => 'Invoices',  'icon' => 'file-text',  'href' => '/crm/invoices/index.php',     'perm' => 'billing.view'],

    // ── Schedule ──────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Schedule'],
    ['key' => 'schedule',  'label' => 'Schedule',   'icon' => 'calendar', 'href' => '/crm/jobs/schedule.php',          'perm' => 'schedule.view'],
    ['key' => 'timeclock', 'label' => 'Time Clock', 'icon' => 'clock',    'href' => '/crm/timeclock/my-schedule.php',  'perm' => 'schedule.view'],

    // ── Financials ────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Financials'],
    ['key' => 'expenses',      'label' => 'Expenses',      'icon' => 'credit-card', 'href' => '/crm/expenses_appstack.php',      'perm' => 'expenses.view'],
    ['key' => 'profitability', 'label' => 'Profitability', 'icon' => 'trending-up', 'href' => '/crm/profitability_appstack.php', 'perm' => 'expenses.view'],

    // ── Growth ────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Growth'],
    ['key' => 'marketing', 'label' => 'Email Campaigns', 'icon' => 'zap',      'href' => '/crm/marketing/campaigns.php', 'perm' => 'marketing.view'],
    ['key' => 'social',    'label' => 'Social Posts',    'icon' => 'share-2',  'href' => '/crm/marketing/social.php',   'perm' => 'marketing.view'],
    ['key' => 'cms',       'label' => 'Website CMS',     'icon' => 'edit-3',   'href' => '/crm/cms-pages_appstack.php', 'perm' => 'marketing.edit'],

    // ── Fleet ─────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Fleet'],
    ['key' => 'driver',       'label' => 'Driver Portal',   'icon' => 'truck',       'href' => '/crm/driver-portal.php'],
    ['key' => 'trip-reports', 'label' => 'Trip Reports',    'icon' => 'clipboard',   'href' => '/crm/trip-reports_appstack.php', 'perm' => 'team.view'],

    // ── Team ──────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Team'],
    ['key' => 'team',        'label' => 'Team',           'icon' => 'user-check',  'href' => '/crm/team/index.php',            'perm' => 'team.view'],
    ['key' => 'leaderboard', 'label' => 'Leaderboard',    'icon' => 'award',       'href' => '/crm/leaderboard_appstack.php',  'perm' => 'team.view'],
    ['key' => 'map',         'label' => 'Territory Map',  'icon' => 'map',         'href' => '/crm/map_appstack.php',          'perm' => 'jobs.view'],

    // ── Library ───────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Library'],
    ['key' => 'products',  'label' => 'Products',      'icon' => 'package',  'href' => '/crm/products/index.php',         'perm' => 'products.view'],
    ['key' => 'portfolio', 'label' => 'Portfolio',     'icon' => 'image',    'href' => '/crm/portfolio/index.php',        'perm' => 'portfolio.view'],
    ['key' => 'media',     'label' => 'Media Library', 'icon' => 'folder',   'href' => '/cms/cms-media_appstack.php',     'perm' => 'photos.upload'],

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
