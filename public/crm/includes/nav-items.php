<?php
/**
 * The CRM menu's items, shared by the desktop sidebar (appstack_sidebar.php) and, in the Ittai
 * design, the phone menu (mobile-nav.php) — one list, so the two can't drift (2026-10-19).
 *   $navItems              section headers (with their department head) and links (perm, badge)
 *   mwIttaiNavBlocks(...)  the same items grouped by department head for the Ittai design
 * require_once: the badge counts are queried once per page however many menus print it.
 */
if (!isset($user)) $user = ['name' => 'Admin'];

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
    ['type' => 'header', 'label' => 'Overview', 'head' => 'charlie'],
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'sliders', 'href' => '/crm/dashboard_appstack.php'],

    // ── Clients ───────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Clients', 'head' => 'sam'],
    ['key' => 'clients',   'label' => 'Contacts',  'icon' => 'users',   'href' => '/crm/clients_appstack.php',    'perm' => 'clients.view'],
    ['key' => 'companies', 'label' => 'Companies', 'icon' => 'home',    'href' => '/crm/companies/index.php',     'perm' => 'clients.view'],

    // ── Pipeline ──────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Pipeline', 'head' => 'sam'],
    ['key' => 'tasks',     'label' => 'Tasks',     'icon' => 'check-square', 'href' => '/crm/tasks_appstack.php', 'badge' => $_mwTaskBadge],
    ['key' => 'quotes',    'label' => 'Quotes',    'icon' => 'dollar-sign', 'href' => '/crm/quotes_appstack.php',    'perm' => 'billing.view'],
    ['key' => 'contracts', 'label' => 'Contracts', 'icon' => 'pen-tool', 'href' => '/crm/contracts_appstack.php', 'perm' => 'jobs.view'],
    ['key' => 'jobs',      'label' => 'Jobs',      'icon' => 'briefcase',  'href' => '/crm/jobs/index.php',         'perm' => 'jobs.view'],
    ['key' => 'invoices',  'label' => 'Invoices',  'icon' => 'file-text',  'href' => '/crm/invoices/index.php',     'perm' => 'billing.view'],

    // ── Schedule ──────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Schedule', 'head' => 'otto'],
    ['key' => 'schedule',    'label' => 'Schedule',    'icon' => 'calendar', 'href' => '/crm/jobs/schedule.php',          'perm' => 'schedule.view'],
    ['key' => 'live-map',    'label' => 'Map',         'icon' => 'map',      'href' => '/crm/map.php',                    'perm' => 'team.view'],
    ['key' => 'timeclock',   'label' => 'Time Clock',  'icon' => 'clock',    'href' => '/crm/timeclock/my-schedule.php',  'perm' => 'schedule.view'],
    ['key' => 'timesheets',  'label' => 'Timesheets',  'icon' => 'file-text', 'href' => '/crm/timeclock/timesheets.php',     'perm' => 'timer.override'],
    ['key' => 'payroll',     'label' => 'Payroll',     'icon' => 'dollar-sign', 'href' => '/crm/timeclock/payroll-summary.php', 'perm' => 'timer.override'],
    ['key' => 'timeclock-settings', 'label' => 'Time Clock Settings', 'icon' => 'settings', 'href' => '/crm/timeclock/settings.php', 'perm' => 'settings.edit'],
    ['key' => 'work-zones',  'label' => 'Work Zones',  'icon' => 'map-pin',  'href' => '/crm/zone-report_appstack.php',   'perm' => 'jobs.view'],
    ['key' => 'clusters',    'label' => 'Route Clusters', 'icon' => 'layers',  'href' => '/crm/jobs/clusters_appstack.php', 'perm' => 'jobs.view'],

    // ── Financials ────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Financials', 'head' => 'penny'],
    ['key' => 'accounting',    'label' => 'Accounting',    'icon' => 'book-open',   'href' => '/crm/accounting_appstack.php',         'perm' => 'expenses.view'],
    ['key' => 'expenses',      'label' => 'Expenses',      'icon' => 'credit-card', 'href' => '/crm/expenses_appstack.php',          'perm' => 'expenses.view'],
    ['key' => 'profitability', 'label' => 'Profitability', 'icon' => 'trending-up', 'href' => '/crm/profitability_appstack.php',     'perm' => 'expenses.view'],
    ['key' => 'freedom',       'label' => 'Owner Freedom', 'icon' => 'compass',     'href' => '/crm/freedom_appstack.php',           'perm' => 'expenses.view'],
    ['key' => 'cost-factors',  'label' => 'Cost Factors',  'icon' => 'sliders',     'href' => '/crm/products/cost-factors.php',      'perm' => 'expenses.view'],
    ['key' => 'ledger',       'label' => 'Ledger',        'icon' => 'book',        'href' => '/crm/ledger/',                         'perm' => 'ledger.view'],
    ['key' => 'reports',      'label' => 'Reports',       'icon' => 'bar-chart-2', 'href' => '/crm/reports_appstack.php',           'perm' => 'billing.view'],

    // ── Growth ────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Growth', 'head' => 'mia'],
    ['key' => 'intel',     'label' => 'Marketing Intel',  'icon' => 'target',   'href' => '/crm/marketing/intel.php',      'perm' => 'marketing.view'],
    ['key' => 'marketing', 'label' => 'Email Campaigns', 'icon' => 'zap',      'href' => '/crm/marketing/campaigns.php', 'perm' => 'marketing.view'],
    ['key' => 'social',    'label' => 'Social Posts',    'icon' => 'share-2',  'href' => '/crm/marketing/social.php',   'perm' => 'marketing.view'],
    ['key' => 'listings',  'label' => 'Listings',        'icon' => 'map-pin',  'href' => '/crm/marketing/listings.php', 'perm' => 'marketing.view'],
    ['key' => 'referrals', 'label' => 'Referrals',       'icon' => 'gift',     'href' => '/crm/marketing/referrals.php', 'perm' => 'marketing.view'],
    ['key' => 'cms',       'label' => 'Website CMS',     'icon' => 'edit-3',   'href' => '/crm/cms-pages_appstack.php', 'perm' => 'marketing.edit'],

    // ── Fleet ─────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Fleet', 'head' => 'otto'],
    ['key' => 'driver',       'label' => 'Driver Portal',   'icon' => 'truck',       'href' => '/crm/driver-portal.php'],
    ['key' => 'trip-reports', 'label' => 'Trip Reports',    'icon' => 'clipboard',   'href' => '/crm/trip-reports_appstack.php', 'perm' => 'team.view'],

    // ── Communications ────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Communications', 'head' => 'yui'],
    ['key' => 'messages', 'label' => 'Messages', 'icon' => 'message-circle', 'href' => '/crm/messages_appstack.php', 'badge' => $_mwUnreadMessages],

    // ── Team ──────────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Team', 'head' => 'charlie'],
    ['key' => 'team',        'label' => 'Team',           'icon' => 'user-check',  'href' => '/crm/team/index.php',            'perm' => 'team.view'],
    ['key' => 'leaderboard', 'label' => 'Leaderboard',    'icon' => 'award',       'href' => '/crm/leaderboard_appstack.php',  'perm' => 'team.view'],
    ['key' => 'quiz',          'label' => 'Knowledge Quiz',  'icon' => 'book-open',   'href' => '/crm/quiz_appstack.php'],
    ['key' => 'certification', 'label' => 'Certification',    'icon' => 'award',       'href' => '/crm/certification_appstack.php'],
    ['key' => 'map',         'label' => 'Territory Map',  'icon' => 'map',         'href' => '/crm/map_appstack.php',          'perm' => 'jobs.view'],

    // ── Library ───────────────────────────────────────────────────────────────
    ['type' => 'header', 'label' => 'Library', 'head' => 'mia'],
    ['key' => 'photos',    'label' => 'Photo Timeline', 'icon' => 'camera',  'href' => '/crm/photos_appstack.php',        'perm' => 'jobs.view'],
    ['key' => 'products',  'label' => 'Products',      'icon' => 'package',  'href' => '/crm/products/index.php',         'perm' => 'products.view'],
    ['key' => 'plants',    'label' => 'Plant Library', 'icon' => 'feather',  'href' => '/crm/plants/library.php',         'perm' => 'clients.view'],
    ['key' => 'portfolio',     'label' => 'Portfolio',       'icon' => 'image',    'href' => '/crm/portfolio/index.php',        'perm' => 'portfolio.view'],
    ['key' => 'before-after', 'label' => 'Before & After', 'icon' => 'columns',  'href' => '/crm/before-after_appstack.php',  'perm' => 'portfolio.view'],
    ['key' => 'media',         'label' => 'Media Library',  'icon' => 'folder',   'href' => '/cms/cms-media_appstack.php',     'perm' => 'photos.upload'],

];

if (!function_exists('mwIttaiNavBlocks')) {
    /**
     * The menu IS the office (owner, 2026-10-19): every page in its department head's block, in the
     * Ittai v1.2 Six + One order (Charlie, then clockwise from Yui). Same items, permissions and
     * badges as the classic menu; a page with no move stays with its section's head; Admin is Ada's.
     * @return array<int, array{slug: string, name: string, role: string, kanji: string, means: string, here: bool, items: array}>
     */
    function mwIttaiNavBlocks(array $navItems, array $user, string $activePage): array
    {
        $heads = [
            'charlie' => ['Charlie', 'Chief of Staff', '統', 'unite, coordinate'],
            'yui'     => ['Yui',     'Communications', '結', 'tie together'],
            'sam'     => ['Sam',     'Sales',          '商', 'trade'],
            'penny'   => ['Penny',   'Finance',        '計', 'keep the account'],
            'otto'    => ['Otto',    'Operations',     '運', 'carry, run'],
            'mia'     => ['Mia',     'Marketing',      '広', 'spread wide'],
            'ada'     => ['Ada',     'Technology',     '技', 'skill, craft'],
        ];
        // Pages that move to the head whose job they are (Ittai v1.2 job descriptions).
        $move = ['invoices' => 'penny', 'payroll' => 'penny', 'jobs' => 'otto', 'map' => 'otto', 'products' => 'otto', 'plants' => 'otto'];
        $can = static fn(string $p): bool => !function_exists('userHasPermission') || userHasPermission($p);
        $groups = array_fill_keys(array_keys($heads), []);
        $section = 'charlie';
        foreach ($navItems as $it) {
            if (($it['type'] ?? '') === 'header') { $section = $it['head'] ?? $section; continue; }
            if (isset($it['perm']) && !$can($it['perm'])) continue;
            $groups[$move[$it['key']] ?? $section][] = $it;
        }
        // Admin is Ada's (kept in step with the classic Admin list in appstack_sidebar.php).
        $admin = ($user['role'] ?? '') === 'admin';
        foreach ([
            ['audit-trail', 'Audit Trail', 'shield', '/crm/audit-trail_appstack.php', $can('settings.edit')],
            ['import', 'Import Data', 'upload', '/crm/import_appstack.php', $can('settings.edit')],
            ['privacy', 'Privacy & Data', 'lock', '/crm/privacy_appstack.php', $can('settings.edit')],
            ['sites', 'Tenant Sites', 'globe', '/crm/sites_appstack.php', $can('database.manage')],
            ['users', 'Users & Roles', 'user-plus', '/crm/users_appstack.php', $can('users.manage')],
            ['settings', 'Settings', 'settings', '/crm/settings.php', $can('settings.edit')],
            ['system-health', 'System Health', 'activity', '/crm/ada_appstack.php', $admin],
            ['system-log', 'System Log', 'alert-circle', '/crm/system-log_appstack.php', $admin],
        ] as [$k, $l, $i, $h, $ok]) {
            if ($ok) $groups['ada'][] = ['key' => $k, 'label' => $l, 'icon' => $i, 'href' => $h];
        }
        $out = [];
        foreach ($heads as $slug => [$name, $role, $kanji, $means]) {
            if (!$groups[$slug]) continue;
            $out[] = ['slug' => $slug, 'name' => $name, 'role' => $role, 'kanji' => $kanji, 'means' => $means,
                      'here' => in_array($activePage, array_column($groups[$slug], 'key'), true), 'items' => $groups[$slug]];
        }
        return $out;
    }
}
