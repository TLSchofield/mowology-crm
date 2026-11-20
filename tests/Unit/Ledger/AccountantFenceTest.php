<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The accountant role (migration 1355 + AccountantFence): can view the Ledger; refused on clients,
 * schedule, payroll SINs / bank details, crew GPS and everything else, whatever a page's own gate says.
 */
class AccountantFenceTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../database/migrations/1355_accountants_ledger.sql';

    /** @return array<string, string[]> role => permission keys granted by migration 1355 */
    private function grants(): array
    {
        $sql = (string)file_get_contents(self::MIGRATION);
        $out = [];
        preg_match_all("/JOIN permissions p ON p\\.`key` (?:IN \\(([^)]*)\\)|= '([a-z.]+)')\\s*WHERE r\\.name = '([a-z]+)'/", $sql, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $keys = $row[1] !== '' ? array_map(fn($k) => trim($k, " '"), explode(',', $row[1])) : [$row[2]];
            $out[$row[3]] = array_merge($out[$row[3]] ?? [], $keys);
        }
        return $out;
    }

    public function test_migration_gives_the_accountant_only_ledger_view_and_propose(): void
    {
        $g = $this->grants();
        $this->assertEqualsCanonicalizing(['ledger.view', 'ledger.propose'], $g['accountant']);
        $this->assertEqualsCanonicalizing(['ledger.view', 'ledger.propose', 'ledger.post'], $g['admin']);
        $this->assertSame(['ledger.view'], $g['manager']);
        $this->assertArrayNotHasKey('staff', $g, 'crew get no ledger key');
    }

    public function test_the_accountant_holds_none_of_the_keys_that_gate_people_places_and_money_details(): void
    {
        $g = $this->grants();
        // Where those are gated (2026-10-13): contacts/companies clients.view; schedule schedule.view; the
        // live map, crew profiles and GPS team.view; SIN and bank account on a profile hr.view / hr.edit;
        // Stripe / API keys settings.edit; invoices and receipts billing.view / expenses.view.
        foreach (['clients.view', 'clients.edit', 'schedule.view', 'team.view', 'hr.view', 'hr.edit', 'timer.override',
                  'settings.view', 'settings.edit', 'billing.view', 'expenses.view', 'users.manage', 'ledger.post'] as $key) {
            $this->assertNotContains($key, $g['accountant'], "accountant must not hold $key");
        }
    }

    public function test_the_legacy_fallback_also_gives_the_accountant_only_ledger_keys(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Core/Auth/authz.php');
        $this->assertMatchesRegularExpression("/case 'accountant':\\s*return \\['ledger\\.view', 'ledger\\.propose'\\];/", $src);
    }

    public function test_accountant_can_open_the_ledger(): void
    {
        foreach (['/crm/ledger/', '/crm/ledger/journal.php?from=2026-01-01', '/crm/ledger/entry.php?id=5&part=1', '/crm/ledger/gifi.php?export=csv',
                  '/crm/ledger/exceptions.php', '/crm/accounting/trial-balance.php', '/crm/accounting/balance-sheet.php',
                  '/api/expenses/receipt-image?m=1&e=2&s=3', '/loginAuth/reset_password.php?token=x', '/crm/logout_secure.php?stay=1'] as $uri) {
            $this->assertSame('allow', AccountantFence::verdict('accountant', $uri), $uri);
        }
    }

    public function test_accountant_is_refused_on_clients_schedule_payroll_sin_and_gps(): void
    {
        foreach (['/crm/clients_appstack.php', '/crm/companies/index.php', '/crm/contacts/view.php?id=3',
                  '/crm/jobs/schedule.php', '/crm/timeclock/payroll-summary.php', '/crm/team/profile.php?id=4',
                  '/api/team/employees', '/crm/map.php', '/crm/api/crew-location.php', '/crm/api/tracking-sync.php',
                  '/api/schedule/clock', '/crm/settings.php', '/crm/accounting/chart-of-accounts.php', '/crm/invoices/view.php?id=1',
                  '/crm/expenses_appstack.php', '/crm/api/serve-receipt.php?id=9', '/crm/users_appstack.php', '/crm/ledger/../clients_appstack.php',
                  '/crm//clients_appstack.php', '/crm/%2e%2e/crm/map.php', '/cms/cms-media_appstack.php', '/site-admin/index.php'] as $uri) {
            $this->assertSame('deny', AccountantFence::verdict('accountant', $uri), $uri);
        }
    }

    public function test_landing_pages_send_the_accountant_to_the_ledger(): void
    {
        foreach (['/crm/', '/crm/dashboard_appstack.php', '/crm/app-launch.php'] as $uri) {
            $this->assertSame('redirect', AccountantFence::verdict('accountant', $uri), $uri);
        }
    }

    public function test_other_roles_are_untouched_and_the_public_site_stays_open(): void
    {
        foreach (['admin', 'manager', 'user', 'staff', ''] as $role) {
            $this->assertSame('allow', AccountantFence::verdict($role, '/crm/clients_appstack.php'));
        }
        $this->assertSame('allow', AccountantFence::verdict('accountant', '/services'));
        $this->assertSame('allow', AccountantFence::verdict('accountant', '/index.php'));
    }

    public function test_no_app_token_for_an_accountant(): void
    {
        $this->assertFalse(AccountantFence::jwtAllowed('accountant'));
        $this->assertFalse(AccountantFence::jwtAllowed(' Accountant '));
        $this->assertTrue(AccountantFence::jwtAllowed('manager'));
    }

    public function test_every_view_and_export_is_an_audit_row_with_secrets_left_out(): void
    {
        $this->assertSame('ledger.view', AccountantFence::auditAction('/crm/ledger/journal.php?q=x', ['q' => 'x']));
        $this->assertSame('ledger.view', AccountantFence::auditAction('/crm/ledger/gifi.php?export=csv', ['export' => 'csv']),
            'a request for an export is a view; LedgerExport logs ledger.export only when a file is sent (a blocked export is not one)');
        $this->assertSame('ledger.view', AccountantFence::auditAction('/api/expenses/receipt-image?m=1', ['m' => '1']));
        $this->assertNull(AccountantFence::auditAction('/crm/api/get-csrf.php', []));
        $this->assertNull(AccountantFence::auditAction('/loginAuth/login.php', []));
        $p = AccountantFence::loggableParams(['m' => '4', 's' => 'secret-sig', 'token' => 't', 'from' => '2026-01-01']);
        $this->assertSame(['m' => '4', 'from' => '2026-01-01'], $p);
    }

    public function test_role_rules_know_the_accountant(): void
    {
        $this->assertTrue(RoleRules::isAccountant('accountant'));
        $this->assertFalse(RoleRules::isOffice('accountant'));
        $this->assertTrue(RoleRules::hasIdleTimeout('accountant'));
        $this->assertSame('accountant', RoleRules::rbacRoleFor('accountant'));
        $this->assertSame('staff', RoleRules::rbacRoleFor('user'));
    }

    public function test_invite_validation(): void
    {
        $this->assertNull(AccountantInviteService::invalid('Ricole', 'ricole@example.test'));
        $this->assertNotNull(AccountantInviteService::invalid('', 'ricole@example.test'));
        $this->assertNotNull(AccountantInviteService::invalid('Ricole', 'not-an-email'));
    }
}
