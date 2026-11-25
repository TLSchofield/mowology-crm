<?php
declare(strict_types=1);

/**
 * Static CRM page inventory for tools/crm-crawl.php.
 *
 * Mined from public/crm/includes/appstack_sidebar.php (nav items) plus the
 * known subdirectory list/create pages found during planning. Dynamic-ID
 * detail pages (quotes/view.php?id=, jobs/view.php?id=, etc.) are NOT listed
 * here — the crawler discovers those by following real <a href> links out
 * of the corresponding list pages (see CrmCrawler::discoverDynamicPages()),
 * with a read-only DB sample as fallback for entity types with no reachable
 * list-page links.
 *
 * `perm` is the RBAC permission key the page (or its sidebar entry) is
 * gated behind — null means no gate. `expectGate` = true means the QA
 * account (role='user' + extended read-only 'viewer') is deliberately NOT
 * granted this permission (it's *.edit/*.manage, or a hardcoded
 * role==='admin' check), so a redirect-to-login/403 here is a PASS
 * (expected), not a FAIL.
 */
class PageInventory
{
    /** @return array<int, array{key:string,label:string,path:string,perm:?string,expectGate:bool}> */
    public static function pages(): array
    {
        $p = [];
        $add = function (string $key, string $label, string $path, ?string $perm = null, bool $expectGate = false) use (&$p) {
            $p[] = ['key' => $key, 'label' => $label, 'path' => $path, 'perm' => $perm, 'expectGate' => $expectGate];
        };

        // ── Overview ──────────────────────────────────────────────────────
        $add('dashboard', 'Dashboard', '/crm/dashboard_appstack.php');

        // ── Clients ───────────────────────────────────────────────────────
        $add('clients', 'Contacts', '/crm/clients_appstack.php', 'clients.view');
        $add('companies', 'Companies', '/crm/companies/index.php', 'clients.view');

        // ── Pipeline ──────────────────────────────────────────────────────
        $add('tasks', 'Tasks', '/crm/tasks_appstack.php');
        $add('quotes', 'Quotes', '/crm/quotes_appstack.php', 'billing.view');
        $add('quotes-create', 'Quote — Create', '/crm/quotes/create.php', 'billing.view');
        $add('contracts', 'Contracts', '/crm/contracts_appstack.php', 'jobs.view');
        $add('contracts-create', 'Contract — Create', '/crm/contracts/create.php', 'jobs.view');
        $add('jobs', 'Jobs', '/crm/jobs/index.php', 'jobs.view');
        $add('jobs-create', 'Job — Create', '/crm/jobs/create.php', 'jobs.view');
        $add('invoices', 'Invoices', '/crm/invoices/index.php', 'billing.view');
        $add('invoices-create', 'Invoice — Create', '/crm/invoices/create.php', 'billing.view');

        // ── Schedule ──────────────────────────────────────────────────────
        $add('schedule', 'Schedule', '/crm/jobs/schedule.php', 'schedule.view');
        $add('live-map', 'Map', '/crm/map.php', 'team.view');
        $add('timeclock', 'Time Clock', '/crm/timeclock/my-schedule.php', 'schedule.view');
        $add('timeclock-timesheet', 'My Timesheet', '/crm/timeclock/my-timesheet.php', 'schedule.view');
        $add('work-zones', 'Work Zones', '/crm/zone-report_appstack.php', 'jobs.view');
        // clusters_appstack.php gates on the legacy role field directly
        // (admin/manager only), not jobs.view — expected to redirect away.
        $add('clusters', 'Route Clusters', '/crm/jobs/clusters_appstack.php', 'jobs.view', expectGate: true);

        // ── Financials ────────────────────────────────────────────────────
        $add('accounting', 'Accounting', '/crm/accounting_appstack.php', 'expenses.view');
        $add('accounting-transactions', 'Accounting — Transactions', '/crm/accounting/transactions.php', 'expenses.view');
        $add('accounting-chart', 'Accounting — Chart of Accounts', '/crm/accounting/chart-of-accounts.php', 'expenses.view');
        $add('accounting-income', 'Accounting — Income Statement', '/crm/accounting/income-statement.php', 'expenses.view');
        $add('accounting-balance', 'Accounting — Balance Sheet', '/crm/accounting/balance-sheet.php', 'expenses.view');
        $add('accounting-trial', 'Accounting — Trial Balance', '/crm/accounting/trial-balance.php', 'expenses.view');
        $add('expenses', 'Expenses', '/crm/expenses_appstack.php', 'expenses.view');
        $add('profitability', 'Profitability', '/crm/profitability_appstack.php', 'expenses.view');
        $add('cost-factors', 'Cost Factors', '/crm/products/cost-factors.php', 'expenses.view');
        $add('reports', 'Reports', '/crm/reports_appstack.php', 'expenses.view');
        $add('reports-overdue', 'Reports — Overdue Invoices', '/crm/reports/overdue-invoices.php', 'expenses.view');
        $add('reports-clv', 'Reports — Client Lifetime Value', '/crm/reports/client-lifetime-value.php', 'expenses.view');
        $add('reports-funnel', 'Reports — Quote Funnel', '/crm/reports/quote-funnel.php', 'expenses.view');

        // ── Growth (marketing.view — granted to the QA viewer role) ───────
        $add('intel', 'Marketing Intel', '/crm/marketing/intel.php', 'marketing.view');
        $add('marketing', 'Email Campaigns', '/crm/marketing/campaigns.php', 'marketing.view');
        $add('social', 'Social Posts', '/crm/marketing/social.php', 'marketing.view');
        $add('referrals', 'Referrals', '/crm/marketing/referrals.php', 'marketing.view');
        $add('cms', 'Website CMS', '/crm/cms-pages_appstack.php', 'marketing.edit', expectGate: true);

        // ── Fleet ─────────────────────────────────────────────────────────
        // driver-portal.php redirects any non-driver user to / by design
        // (business-logic routing, not a permission failure) — expected.
        $add('driver', 'Driver Portal', '/crm/driver-portal.php', null, expectGate: true);
        $add('trip-reports', 'Trip Reports', '/crm/trip-reports_appstack.php', 'team.view');

        // ── Communications ───────────────────────────────────────────────
        $add('messages', 'Messages', '/crm/messages_appstack.php');

        // ── Team ──────────────────────────────────────────────────────────
        $add('team', 'Team', '/crm/team/index.php', 'team.view');
        $add('leaderboard', 'Leaderboard', '/crm/leaderboard_appstack.php', 'team.view');
        $add('quiz', 'Knowledge Quiz', '/crm/quiz_appstack.php');
        $add('certification', 'Certification', '/crm/certification_appstack.php');
        $add('map', 'Territory Map', '/crm/map_appstack.php', 'jobs.view');

        // ── Library ───────────────────────────────────────────────────────
        $add('photos', 'Photo Timeline', '/crm/photos_appstack.php', 'jobs.view');
        $add('products', 'Products', '/crm/products/index.php', 'products.view');
        $add('products-categories', 'Products — Categories', '/crm/products/categories.php', 'products.view');
        $add('products-bundles', 'Products — Bundles', '/crm/products/bundles.php', 'products.view');
        $add('products-measurements', 'Products — Measurements', '/crm/products/measurements.php', 'products.view');
        $add('portfolio', 'Portfolio', '/crm/portfolio/index.php', 'portfolio.view');
        $add('before-after', 'Before & After', '/crm/before-after_appstack.php', 'portfolio.view');
        $add('media', 'Media Library', '/cms/cms-media_appstack.php', 'photos.upload', expectGate: true);

        // ── Admin (deliberately NOT granted — *.edit/*.manage/hardcoded admin) ──
        $add('audit-trail', 'Audit Trail', '/crm/audit-trail_appstack.php', 'settings.edit', expectGate: true);
        $add('import', 'Import Data', '/crm/import_appstack.php', 'settings.edit', expectGate: true);
        $add('privacy', 'Privacy & Data', '/crm/privacy_appstack.php', 'settings.edit', expectGate: true);
        $add('sites', 'Tenant Sites', '/crm/sites_appstack.php', 'database.manage', expectGate: true);
        $add('users', 'Users & Roles', '/crm/users_appstack.php', 'users.manage', expectGate: true);
        $add('settings', 'Settings', '/crm/settings.php', 'settings.edit', expectGate: true);
        $add('system-log', 'System Log', '/crm/system-log_appstack.php', null, expectGate: true); // hardcoded role==='admin'

        return $p;
    }

    /**
     * List/index pages worth following links out of, keyed by the entity
     * type a discovered dynamic-ID page belongs to. Used by the crawler to
     * queue up to N view.php?id=... pages per entity via real link discovery.
     *
     * @return array<string, string> entity => list-page path
     */
    public static function listPagesForDynamicDiscovery(): array
    {
        return [
            'quote'     => '/crm/quotes_appstack.php',
            'job'       => '/crm/jobs/index.php',
            'invoice'   => '/crm/invoices/index.php',
            'company'   => '/crm/companies/index.php',
            'contract'  => '/crm/contracts_appstack.php',
            'portfolio' => '/crm/portfolio/index.php',
            'team'      => '/crm/team/index.php',
        ];
    }
}
