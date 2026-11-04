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
 *                   'team', 'leaderboard', 'quiz', 'map', 'live-map', 'photos', 'products', 'portfolio',
 *                   'work-zones', 'users', 'settings', 'privacy', 'certification'
 *
 * Nav items support two types:
 *   - Section headers: ['type' => 'header', 'label' => 'Section Name', 'head' => 'otto'] (head = the department head shown beside it)
 *   - Nav links:       ['key' => '...', 'label' => '...', 'icon' => '...', 'href' => '...', 'perm' => '...']
 *
 * The 'perm' key is optional — if present, the item is hidden if the user
 * lacks that permission. The page itself still enforces access control.
 */
if (!isset($activePage)) $activePage = '';
if (!isset($user))       $user = ['name' => 'Admin'];

// The outside accountant's login sees the Ledger and nothing else (AccountantFence, 2026-10-13).
if (!empty($mwLedgerOnly)): ?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/ledger/"><span class="align-middle">Ledger</span></a>
        <ul class="sidebar-nav">
            <li class="sidebar-header">Ledger</li>
            <?php require_once dirname(__DIR__) . '/ledger/_nav.php';
            $__here = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
            foreach (mwLedgerPages() as $__k => [$__l, $__h, $__i]): ?>
            <li class="sidebar-item<?php echo $__here === $__h ? ' active' : ''; ?>"><a class="sidebar-link" href="<?php echo htmlspecialchars($__h); ?>"><i class="align-middle" data-feather="<?php echo htmlspecialchars($__i); ?>"></i> <span class="align-middle"><?php echo htmlspecialchars($__l); ?></span></a></li>
            <?php endforeach; ?>
            <li class="sidebar-item"><a class="sidebar-link" href="/crm/logout_secure.php"><i class="align-middle" data-feather="log-out"></i> <span class="align-middle">Sign out</span></a></li>
        </ul>
        <p class="mw-ledger-sidebar-note">Read-only accountant access. Each page you open is recorded.</p>
    </div>
</nav>
<?php return; endif;

require_once __DIR__ . '/nav-items.php';   // $navItems + badge counts, shared with the phone menu (mobile-nav.php)

// ── Ittai design: the menu IS the office (owner, 2026-10-19) ─────────────────────────────────────────
// With the it_design choice on (appstack_head.php sets $itShell), every page sits in its department
// head's block: a full-width strip (portrait, name, role, kanji and what it means), then that head's
// pages. Order and seats follow the Ittai v1.2 Six + One (Charlie, then clockwise from Yui). Same items,
// permissions and badges as the classic menu below; a page with no mapping stays with its section's head.
// Admin is Ada's block (kept in step with the classic Admin list further down).
if (!empty($itShell)):
    $__itBlocks = mwIttaiNavBlocks($navItems, (array)$user, (string)$activePage);
?>
<nav id="sidebar" class="sidebar it-sidebar" aria-label="Main menu">
    <div class="sidebar-content js-simplebar">
        <?php /* Product mark + the tenant's name, as Jobber does (owner, 2026-10-19). The symbol is the frozen v1.2 24 px asset. */ ?>
        <a class="sidebar-brand it-sb-brand" href="/crm/dashboard_appstack.php"><img class="it-sb-symbol" src="/crm/img/ittai/ittai-symbol-24.svg" alt="Ittai" width="24" height="24"><span class="align-middle"><?php require_once APP_ROOT . '/Services/BusinessIdentity.php'; echo htmlspecialchars(BusinessIdentity::get()['name'] ?: 'Ittai'); ?></span></a>
        <ul class="sidebar-nav it-sb-nav">
        <?php foreach ($__itBlocks as ['slug' => $__slug, 'name' => $__name, 'role' => $__role, 'kanji' => $__kanji, 'means' => $__means, 'here' => $__here, 'items' => $__items]): ?>
            <li class="it-sb-strip<?php echo $__here ? ' is-here' : ''; ?>" data-head="<?php echo $__slug; ?>">
                <button type="button" class="it-sb-toggle" aria-expanded="true" aria-controls="it-sb-<?php echo $__slug; ?>">
                    <span class="it-sb-face"><img src="/crm/img/heads/<?php echo $__slug; ?>.jpg" alt="" width="36" height="36" loading="lazy"><span class="it-sb-kanji" aria-hidden="true"><?php echo $__kanji; ?></span></span>
                    <span class="it-sb-who"><span class="it-sb-name"><?php echo $__name; ?></span><span class="it-sb-role"><?php echo $__role; ?></span>
                        <span class="it-sb-means"><span lang="ja"><?php echo $__kanji; ?></span> <?php echo $__means; ?></span></span>
                    <svg class="it-sb-chev" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </li>
            <li class="it-sb-block" id="it-sb-<?php echo $__slug; ?>"><ul class="it-sb-items">
            <?php foreach ($__items as $__item): ?>
                <li class="sidebar-item<?php echo ($activePage === $__item['key']) ? ' active' : ''; ?>">
                    <a class="sidebar-link" href="<?php echo $__item['href']; ?>"<?php echo ($activePage === $__item['key']) ? ' aria-current="page"' : ''; ?>>
                        <i class="align-middle" data-feather="<?php echo $__item['icon']; ?>"></i>
                        <span class="align-middle"><?php echo htmlspecialchars($__item['label']); ?></span>
                        <?php if (!empty($__item['badge']) && (int)$__item['badge'] > 0): ?>
                        <span class="mw-sidebar-badge mw-msg-sidebar-badge"><?php echo (int)$__item['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul></li>
        <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php return; endif;
?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/dashboard_appstack.php">
            <span class="align-middle" style="font-size:1.25rem; letter-spacing:-0.5px;"><?php echo htmlspecialchars(function_exists('_idFromName') ? _idFromName() : ''); ?></span>
        </a>

        <ul class="sidebar-nav">

            <?php foreach ($navItems as $item):

                // Section header
                if (isset($item['type']) && $item['type'] === 'header'): ?>
                    <li class="sidebar-header<?php echo !empty($item['head']) ? ' mw-sb-headed' : ''; ?>"><?php echo htmlspecialchars($item['label']); ?>
                        <?php if (!empty($item['head'])): $__h = $item['head']; ?>
                        <span class="mw-sb-head" title="<?php echo htmlspecialchars(ucfirst($__h)); ?> heads this department"><img src="/crm/img/heads/<?php echo htmlspecialchars($__h); ?>.jpg" alt="" width="20" height="20" loading="lazy"><?php echo htmlspecialchars(ucfirst($__h)); ?></span>
                        <?php endif; ?>
                    </li>
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
            <li class="sidebar-item<?php echo ($activePage === 'privacy') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/privacy_appstack.php">
                    <i class="align-middle" data-feather="lock"></i>
                    <span class="align-middle">Privacy &amp; Data</span>
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

            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <li class="sidebar-item<?php echo ($activePage === 'system-health') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/ada_appstack.php">
                    <i class="align-middle" data-feather="activity"></i>
                    <span class="align-middle">System Health</span>
                </a>
            </li>
            <li class="sidebar-item<?php echo ($activePage === 'system-log') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/system-log_appstack.php">
                    <i class="align-middle" data-feather="alert-circle"></i>
                    <span class="align-middle">System Log</span>
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
