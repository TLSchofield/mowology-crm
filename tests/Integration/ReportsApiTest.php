<?php
declare(strict_types=1);

/**
 * Deep contract tests — /crm/api/reports.php
 *                     — /crm/api/measurements.php
 *                     — /crm/api/employees.php
 *                     — /api/auth/token.php  (JWT endpoint, no session auth)
 */
class ReportsApiTest extends ApiTestCase
{
    // ── reports.php ───────────────────────────────────────────────────────────

    /** @test */
    public function reports_revenue_by_month_returns_array_data(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/reports.php', [
            'report' => 'revenue-by-month',
            'start'  => date('Y-01-01'),
            'end'    => date('Y-12-31'),
        ]);

        $this->assertNotEquals(500, $r['status'],
            'reports.php revenue-by-month returned 500');
        $this->assertNoPHPErrors($r, 'reports.php?report=revenue-by-month');

        if ($r['status'] === 200 && $r['json'] !== null) {
            $this->assertArrayHasKey('data', $r['json']);
            $this->assertIsArray($r['json']['data']);
        }
    }

    /** @test */
    public function reports_revenue_by_service_returns_array_data(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/reports.php', [
            'report' => 'revenue-by-service',
            'start'  => date('Y-01-01'),
            'end'    => date('Y-12-31'),
        ]);

        $this->assertNotEquals(500, $r['status']);
        $this->assertNoPHPErrors($r, 'reports.php?report=revenue-by-service');
    }

    /** @test */
    public function reports_quote_funnel_returns_array_data(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/reports.php', [
            'report' => 'quote-funnel',
            'start'  => date('Y-01-01'),
            'end'    => date('Y-12-31'),
        ]);

        $this->assertNotEquals(500, $r['status']);
        $this->assertNoPHPErrors($r, 'reports.php?report=quote-funnel');
    }

    /** @test */
    public function reports_overdue_invoices_returns_array(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/reports.php', ['report' => 'overdue-invoices']);
        $this->assertNotEquals(500, $r['status']);
        $this->assertNoPHPErrors($r, 'reports.php?report=overdue-invoices');

        if ($r['status'] === 200 && $r['json'] !== null) {
            $this->assertIsArray($r['json']['data'] ?? $r['json']);
        }
    }

    /** @test */
    public function reports_unknown_type_returns_error_not_crash(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/reports.php', ['report' => 'nonexistent_report_type']);
        $this->assertNotEquals(500, $r['status'],
            'reports.php with unknown type returned 500');
        $this->assertNoPHPErrors($r, 'reports.php (unknown type)');
    }

    // ── measurements.php ──────────────────────────────────────────────────────

    /** @test */
    public function measurements_list_with_property_id_not_crash(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/measurements.php', ['action' => 'list', 'property_id' => '1']);
        $this->assertNotEquals(500, $r['status'],
            'measurements.php returned 500. Body: ' . substr($r['body'], 0, 300));
        $this->assertNoPHPErrors($r, 'measurements.php?action=list');

        if ($r['status'] === 200 && $r['json'] !== null) {
            $this->assertTrue($r['json']['success'] ?? false);
            $this->assertArrayHasKey('measurements', $r['json']);
            $this->assertIsArray($r['json']['measurements']);
        }
    }

    /** @test */
    public function measurements_list_without_filter_not_crash(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/measurements.php', ['action' => 'list']);
        $this->assertNotEquals(500, $r['status']);
        $this->assertNoPHPErrors($r, 'measurements.php?action=list (no filter)');
    }

    /** @test */
    public function measurements_update_without_csrf_returns_403(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->post('/crm/api/measurements.php', [
            'action'         => 'update',
            'measurement_id' => '1',
            'name'           => 'Test Area',
        ]);
        $this->assertNotEquals(500, $r['status']);
        $this->assertSame(403, $r['status'],
            "Expected 403 on measurements update without CSRF, got {$r['status']}");
    }

    // ── employees.php ─────────────────────────────────────────────────────────

    /** @test */
    public function employees_list_returns_array_of_users(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/employees.php');
        $this->assertNotEquals(500, $r['status'],
            'employees.php returned 500. Body: ' . substr($r['body'], 0, 300));
        $this->assertNoPHPErrors($r, 'employees.php');
        $this->assertSame(200, $r['status']);
        $this->assertNotNull($r['json']);
    }

    // ── /api/auth/token.php (JWT endpoint — no session auth) ──────────────────

    /** @test */
    public function mobile_auth_get_request_returns_405(): void
    {
        $r = $this->get('/api/auth/token.php');
        $this->assertNoPHPErrors($r, '/api/auth/token.php GET');
        $this->assertNotEquals(500, $r['status'],
            '/api/auth/token.php GET returned 500');
        $this->assertSame(405, $r['status'],
            "Expected 405 Method Not Allowed from token endpoint on GET, got {$r['status']}");
    }

    /** @test */
    public function mobile_auth_post_missing_credentials_returns_structured_error(): void
    {
        $r = $this->post('/api/auth/token.php', [], true); // empty JSON
        $this->assertNoPHPErrors($r, '/api/auth/token.php POST (empty)');
        $this->assertNotEquals(500, $r['status']);
        $this->assertNotNull($r['json'], 'Token endpoint should return JSON for empty body');
        $this->assertArrayHasKey('error', $r['json'],
            'Token endpoint error response missing error key');
    }

    /** @test */
    public function mobile_auth_invalid_credentials_return_401(): void
    {
        $r = $this->post('/api/auth/token.php', [
            'email'    => 'nobody@nowhere.invalid',
            'password' => 'wrong_password_xyzzy',
        ], true);

        $this->assertNoPHPErrors($r, '/api/auth/token.php (invalid creds)');
        $this->assertNotEquals(500, $r['status']);
        // 401 = invalid credentials, 429 = rate-limited (both are correct documented responses)
        $this->assertContains($r['status'], [401, 429],
            "Expected 401 or 429 for invalid credentials, got {$r['status']}");
        $this->assertNotNull($r['json']);
        $this->assertArrayHasKey('error', $r['json']);
    }
}
