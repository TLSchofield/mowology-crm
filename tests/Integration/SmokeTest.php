<?php
declare(strict_types=1);

/**
 * Smoke Test — 20 Most Critical API Endpoints
 *
 * Verifies that every critical endpoint:
 *   1. Does NOT return HTTP 500 (no PHP crash, missing include, parse error)
 *   2. Is properly auth-gated (unauthenticated → 3xx redirect or 401/403)
 *   3. Does not leak PHP error text in the response body
 *
 * These tests require no credentials and can run against production safely.
 * They catch broken includes, renamed functions, missing migrations, etc.
 *
 * Run: vendor/bin/phpunit --testsuite Integration
 */
class SmokeTest extends ApiTestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #1 — GET /crm/api/me.php
    // Auth health check used by PWA to gate UI elements
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function me_endpoint_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/me.php');
        $this->assertNoPHPErrors($r, 'me.php');
        $this->assertAuthGated($r, 'me.php');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #2 — GET /crm/api/csrf-token.php
    // CSRF token issuance — used by every form and JS POST
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function csrf_token_endpoint_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/csrf-token.php');
        $this->assertNoPHPErrors($r, 'csrf-token.php');
        $this->assertAuthGated($r, 'csrf-token.php');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #3 — GET /crm/api/client-search.php?action=search
    // Contact autocomplete — used on quote create, job create, every form
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function client_search_contact_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/client-search.php', ['action' => 'search', 'q' => 'test']);
        $this->assertNoPHPErrors($r, 'client-search.php?action=search');
        $this->assertAuthGated($r, 'client-search.php?action=search');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #4 — GET /crm/api/client-search.php?action=properties
    // Property lookup for a contact — used on quote + job create
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function client_search_properties_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/client-search.php', ['action' => 'properties', 'contact_id' => '1']);
        $this->assertNoPHPErrors($r, 'client-search.php?action=properties');
        $this->assertAuthGated($r, 'client-search.php?action=properties');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #5 — GET /crm/api/global-search.php
    // Spotlight/command palette — app-wide search
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function global_search_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/global-search.php', ['q' => 'test']);
        $this->assertNoPHPErrors($r, 'global-search.php');
        $this->assertAuthGated($r, 'global-search.php');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #6 — GET /crm/api/contact-timeline.php
    // Chronological activity feed for a contact profile page
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function contact_timeline_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/contact-timeline.php', ['contact_id' => '1']);
        $this->assertNoPHPErrors($r, 'contact-timeline.php');
        $this->assertAuthGated($r, 'contact-timeline.php');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #7 — GET /crm/api/tags.php?action=list
    // Tags on an entity — used on property profile, job view
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function tags_list_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/tags.php', ['action' => 'list', 'entity_type' => 'property', 'entity_id' => '1']);
        $this->assertNoPHPErrors($r, 'tags.php?action=list');
        $this->assertAuthGated($r, 'tags.php?action=list');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #8 — GET /crm/api/tags.php?action=available
    // Available tag catalogue — used to populate tag pickers
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function tags_available_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/tags.php', ['action' => 'available', 'groups' => 'property_access']);
        $this->assertNoPHPErrors($r, 'tags.php?action=available');
        $this->assertAuthGated($r, 'tags.php?action=available');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #9 — POST /crm/api/tags.php?action=apply
    // Apply a tag to an entity — property page, job card
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function tags_apply_post_is_auth_gated(): void
    {
        $r = $this->post('/crm/api/tags.php', ['action' => 'apply']);
        $this->assertNoPHPErrors($r, 'tags.php POST action=apply');
        $this->assertAuthGated($r, 'tags.php POST action=apply');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #10 — GET /crm/api/quote-autofill.php?action=get-measurements
    // Load measurement totals used to price a quote
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function quote_autofill_get_measurements_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/quote-autofill.php', ['action' => 'get-measurements', 'property_id' => '1']);
        $this->assertNoPHPErrors($r, 'quote-autofill.php?action=get-measurements');
        $this->assertAuthGated($r, 'quote-autofill.php?action=get-measurements');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #11 — POST /crm/api/quote-autofill.php?action=auto-fill
    // Auto-populate quote line items from measurement data
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function quote_autofill_auto_fill_post_is_auth_gated(): void
    {
        $r = $this->post('/crm/api/quote-autofill.php', ['action' => 'auto-fill', 'property_id' => '1']);
        $this->assertNoPHPErrors($r, 'quote-autofill.php POST action=auto-fill');
        $this->assertAuthGated($r, 'quote-autofill.php POST action=auto-fill');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #12 — POST /crm/api/pow-actions.php
    // Job visit lifecycle (start, checklist, materials, end) — highest-traffic
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function pow_actions_post_is_auth_gated(): void
    {
        $r = $this->post('/crm/api/pow-actions.php', [], true); // empty JSON body
        $this->assertNoPHPErrors($r, 'pow-actions.php POST');
        $this->assertAuthGated($r, 'pow-actions.php POST');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #13 — GET /crm/api/tasks.php
    // Task list view — used on dashboard, team pages
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function tasks_list_get_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/tasks.php');
        $this->assertNoPHPErrors($r, 'tasks.php GET');
        $this->assertAuthGated($r, 'tasks.php GET');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #14 — POST /crm/api/tasks.php?action=create
    // Task creation — used from multiple surfaces
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function tasks_create_post_is_auth_gated(): void
    {
        $r = $this->post('/crm/api/tasks.php', ['action' => 'create']);
        $this->assertNoPHPErrors($r, 'tasks.php POST action=create');
        $this->assertAuthGated($r, 'tasks.php POST action=create');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #15 — GET /crm/api/pipeline.php?action=stages
    // Pipeline stage definitions — drives the Kanban board
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function pipeline_stages_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/pipeline.php', ['action' => 'stages']);
        $this->assertNoPHPErrors($r, 'pipeline.php?action=stages');
        $this->assertAuthGated($r, 'pipeline.php?action=stages');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #16 — GET /crm/api/pipeline.php?action=stats
    // Pipeline aggregate stats — revenue, win rate, forecast
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function pipeline_stats_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/pipeline.php', ['action' => 'stats']);
        $this->assertNoPHPErrors($r, 'pipeline.php?action=stats');
        $this->assertAuthGated($r, 'pipeline.php?action=stats');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #17 — GET /crm/api/measurements.php?action=list
    // Property measurement records — used in area measurement tool
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function measurements_list_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/measurements.php', ['action' => 'list', 'property_id' => '1']);
        $this->assertNoPHPErrors($r, 'measurements.php?action=list');
        $this->assertAuthGated($r, 'measurements.php?action=list');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #18 — GET /crm/api/reports.php?report=revenue-by-month
    // Revenue analytics — business reporting dashboard
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function reports_revenue_by_month_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/reports.php', [
            'report' => 'revenue-by-month',
            'start'  => date('Y-01-01'),
            'end'    => date('Y-12-31'),
        ]);
        $this->assertNoPHPErrors($r, 'reports.php?report=revenue-by-month');
        $this->assertAuthGated($r, 'reports.php?report=revenue-by-month');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #19 — GET /crm/api/employees.php
    // Team member list — used on job assignment, schedule, dispatch
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function employees_list_is_auth_gated(): void
    {
        $r = $this->get('/crm/api/employees.php');
        $this->assertNoPHPErrors($r, 'employees.php');
        $this->assertAuthGated($r, 'employees.php');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint #20 — POST /api/auth/token.php
    // JWT endpoint for BlueMoon / iOS app — does NOT use session auth.
    // An empty POST body should return a structured error (400/401/405),
    // NOT a PHP crash (500).
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function mobile_auth_token_empty_post_returns_error_not_crash(): void
    {
        $r = $this->post('/api/auth/token.php', [], true); // empty JSON body
        $this->assertNoPHPErrors($r, '/api/auth/token.php');
        $this->assertNotEquals(500, $r['status'],
            "/api/auth/token.php returned 500. Body: " . substr($r['body'], 0, 400));
        // Should be 400 (missing email/password), 401 (bad credentials), or 405 (method policy)
        $this->assertContains(
            $r['status'], [400, 401, 403, 405, 422],
            "Expected a structured error status from /api/auth/token.php but got {$r['status']}"
        );
    }
}
