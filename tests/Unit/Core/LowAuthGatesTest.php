<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Low-severity auth items from the Fable audit 2026-10-12 — the gates stay where they were put. */
final class LowAuthGatesTest extends TestCase
{
    private function src(string $f): string
    {
        return (string)file_get_contents(__DIR__ . '/../../../' . $f);
    }

    public function testTripReportsByPermissionNotRoleString(): void
    {
        foreach (['public/crm/api/trip-report.php', 'public/crm/trip-reports_appstack.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringNotContainsString("\$user['role'] === 'user'", $s, $f);
            $this->assertStringNotContainsString("\$user['role'] !== 'user'", $s, $f);
            $this->assertStringContainsString("userHasPermission('team.edit')", $s, $f);
        }
    }

    public function testCalendarStopsCrewSeeOwnStops(): void
    {
        $s = $this->src('app/Modules/Jobs/Api/calendar-stops.php');
        $this->assertStringContainsString("\$seesAll = userHasPermission('schedule.edit');", $s);
        $this->assertStringContainsString('if (!in_array($me, $crewOnStop, true)) continue;', $s);
    }

    public function testGeocodeSaveNeedsEditPermission(): void
    {
        $this->assertStringContainsString("userHasAnyPermission(['clients.edit', 'jobs.edit'])", $this->src('public/crm/api/geocode-save.php'));
    }

    public function testLogoutNeedsPostAndCsrf(): void
    {
        $crm = $this->src('public/loginAuth/logout.php');
        $this->assertMatchesRegularExpression("/=== 'POST'\\s*&& verifyCSRFToken/", $crm);
        $this->assertStringContainsString('<form method="post"', $crm);
        $site = $this->src('public/site-admin/api/logout.php');
        $this->assertStringContainsString("=== 'POST' && verifySiteCsrf()", $site);
        $this->assertStringContainsString('siteCsrfField()', $this->src('public/site-admin/includes/nav.php'));
    }

    public function testSiteAdminRechecksUser(): void
    {
        $s = $this->src('public/site-admin/includes/auth.php');
        $this->assertStringContainsString('return siteRecheckUser();', $s);
        $this->assertStringContainsString('const SITE_RECHECK_SECONDS = 60;', $s);
    }

    public function testTokenEndpointNoCorsForUnknownOrigin(): void
    {
        $s = $this->src('public/api/auth/token.php');
        $this->assertStringNotContainsString('$corsOrigin = $allowedOrigins[0]', $s);
    }

    public function testIdleTimeoutOnOfficePagesOnly(): void
    {
        $s = $this->src('public/crm/includes/appstack_head.php');
        $this->assertStringContainsString('if (!$crewPage && !$crewLite', $s);
        // Office staff and (since 2026-10-13) the outside accountant: RoleRules::hasIdleTimeout().
        $this->assertStringContainsString("RoleRules::hasIdleTimeout((string)(\$_SESSION['user_role']", $s);
        $this->assertTrue(RoleRules::hasIdleTimeout('admin') && RoleRules::hasIdleTimeout('manager') && !RoleRules::hasIdleTimeout('user'));
        $this->assertStringContainsString('checkSessionTimeout(1800);', $s);
    }

    public function testReportsHubNeedsBillingView(): void
    {
        $s = $this->src('public/crm/reports_appstack.php');
        $this->assertStringContainsString("requirePermission('billing.view');", $s);
        $this->assertStringNotContainsString("requirePermission('expenses.view');", $s);
        $cp = $this->src('public/crm/reports/crew-profitability.php');
        $this->assertStringContainsString("requirePermission('billing.view');", $cp);
        $this->assertStringContainsString("requirePermission('team.edit');", $cp);
        $this->assertStringContainsString("'href' => '/crm/reports_appstack.php',           'perm' => 'billing.view'", $this->src('public/crm/includes/appstack_sidebar.php'));
    }
}
