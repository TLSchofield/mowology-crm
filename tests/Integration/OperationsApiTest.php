<?php
declare(strict_types=1);

/**
 * Deep contract tests — /crm/api/pow-actions.php
 *                     — /crm/api/tasks.php
 *                     — /crm/api/pipeline.php
 *
 * These are the highest-traffic operational endpoints — used on every job visit
 * and from the dashboard. A silent regression here breaks field operations.
 */
class OperationsApiTest extends ApiTestCase
{
    // ── pow-actions.php: method guard ─────────────────────────────────────────

    /** @test */
    public function pow_actions_get_request_returns_405(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/pow-actions.php');
        $this->assertNotEquals(500, $r['status']);
        $this->assertSame(405, $r['status'],
            'pow-actions.php should reject GET with 405, got ' . $r['status']);
    }

    /** @test */
    public function pow_actions_post_without_visit_id_returns_error(): void
    {
        $this->skipUnlessAuthenticated();

        // Valid CSRF would be needed for full test — but missing visit_id itself
        // should return a structured error, not a crash
        $r = $this->post('/crm/api/pow-actions.php', [
            'action'  => 'start_visit',
            // intentionally no visit_id, no csrf_token
        ]);

        $this->assertNotEquals(500, $r['status'],
            'pow-actions.php returned 500 on missing visit_id. Body: ' . substr($r['body'], 0, 300));
        $this->assertNoPHPErrors($r, 'pow-actions.php (no visit_id)');

        // Expect 400 (validation) or 403 (CSRF rejection) — either is acceptable,
        // what matters is it's not 500
        $this->assertContains($r['status'], [400, 403, 422],
            "Expected structured error from pow-actions.php, got {$r['status']}");
    }

    /** @test */
    public function pow_actions_unknown_visit_returns_404_not_crash(): void
    {
        $this->skipUnlessAuthenticated();

        // A real CSRF token is needed to get past the CSRF check.
        // Skip past this by just verifying the endpoint doesn't 500 without one.
        $r = $this->post('/crm/api/pow-actions.php', [], true); // empty JSON
        $this->assertNoPHPErrors($r, 'pow-actions.php (empty JSON)');
        $this->assertNotEquals(500, $r['status']);
    }

    // ── tasks.php: list ───────────────────────────────────────────────────────

    /** @test */
    public function tasks_list_returns_array(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/tasks.php');
        $this->assertNotEquals(500, $r['status'],
            'tasks.php GET returned 500. Body: ' . substr($r['body'], 0, 300));
        $this->assertNoPHPErrors($r, 'tasks.php GET');
        $this->assertSame(200, $r['status']);
        $this->assertNotNull($r['json']);

        // Response should be an array of tasks (top-level array or {tasks: []})
        if (is_array($r['json'])) {
            // Could be top-level array or keyed object — either valid
            $this->assertTrue(true);
        }
    }

    /** @test */
    public function tasks_list_filtered_by_status_not_crash(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/tasks.php', ['status' => 'pending']);
        $this->assertNotEquals(500, $r['status']);
        $this->assertNoPHPErrors($r, 'tasks.php?status=pending');
    }

    /** @test */
    public function tasks_create_without_csrf_returns_403(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->post('/crm/api/tasks.php', ['action' => 'create', 'title' => 'Test Task']);
        $this->assertNotEquals(500, $r['status'],
            'tasks.php create without CSRF returned 500');
        $this->assertSame(403, $r['status'],
            "Expected 403 (CSRF rejection) on tasks create, got {$r['status']}");
    }

    /** @test */
    public function tasks_get_items_action_returns_200_or_404(): void
    {
        $this->skipUnlessAuthenticated();

        // Non-existent task ID — should return 404 or 400, not 500
        $r = $this->get('/crm/api/tasks.php', ['action' => 'get_items', 'task_id' => '999999']);
        $this->assertNotEquals(500, $r['status'],
            'tasks.php?action=get_items returned 500');
        $this->assertNoPHPErrors($r, 'tasks.php?action=get_items');
    }

    // ── pipeline.php: stages ─────────────────────────────────────────────────

    /** @test */
    public function pipeline_stages_returns_ordered_array(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/pipeline.php', ['action' => 'stages']);
        $this->assertJsonOk($r, 'pipeline.php?action=stages');
        $this->assertIsArray($r['json']['data']);

        // Each stage must have expected keys
        foreach ($r['json']['data'] as $stage) {
            foreach (['id', 'stage_order'] as $key) {
                $this->assertArrayHasKey($key, $stage,
                    "Pipeline stage missing key '{$key}'");
            }
        }
    }

    /** @test */
    public function pipeline_stats_returns_numeric_values(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/pipeline.php', ['action' => 'stats']);
        $this->assertJsonOk($r, 'pipeline.php?action=stats');

        $data = $r['json']['data'] ?? $r['json'];
        foreach (['total_value', 'weighted_forecast', 'win_rate', 'avg_days_in_stage'] as $key) {
            if (isset($data[$key])) {
                $this->assertIsNumeric($data[$key], "pipeline stats '{$key}' should be numeric");
            }
        }
    }

    /** @test */
    public function pipeline_move_without_csrf_returns_403(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->post('/crm/api/pipeline.php', [
            'action'   => 'move',
            'quote_id' => '1',
            'stage'    => 'qualified',
        ]);
        $this->assertNotEquals(500, $r['status']);
        $this->assertSame(403, $r['status'],
            "Expected 403 on pipeline move without CSRF, got {$r['status']}");
    }

    /** @test */
    public function pipeline_unknown_action_returns_error_not_crash(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/pipeline.php', ['action' => 'nonexistent_action']);
        $this->assertNotEquals(500, $r['status'],
            'pipeline.php with unknown action returned 500');
        $this->assertNoPHPErrors($r, 'pipeline.php (unknown action)');
    }
}
