<?php
declare(strict_types=1);

/**
 * Deep contract tests — /crm/api/client-search.php
 *                     — /crm/api/global-search.php
 *                     — /crm/api/contact-timeline.php
 *
 * Covers validation errors (missing params) and authenticated response shapes.
 */
class ClientSearchApiTest extends ApiTestCase
{
    // ── client-search.php validation (no session required) ───────────────────

    /** @test */
    public function client_search_missing_action_returns_error_not_crash(): void
    {
        // No action param — should be auth-gated (not 500)
        $r = $this->get('/crm/api/client-search.php');
        $this->assertNoPHPErrors($r, 'client-search.php (no action)');
        $this->assertNotEquals(500, $r['status']);
    }

    // ── client-search.php authenticated ──────────────────────────────────────

    /** @test */
    public function client_search_empty_query_returns_empty_results(): void
    {
        $this->skipUnlessAuthenticated();

        // q is blank — endpoint short-circuits and returns empty array
        $r = $this->get('/crm/api/client-search.php', ['action' => 'search', 'q' => '']);
        $this->assertJsonOk($r, 'client-search.php?q=');
        $this->assertSame([], $r['json']['results']);
    }

    /** @test */
    public function client_search_contact_results_have_expected_shape(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/client-search.php', ['action' => 'search', 'q' => 'a', 'type' => 'contact']);
        $this->assertJsonOk($r, 'client-search.php?action=search&type=contact');
        $this->assertIsArray($r['json']['results']);

        // If any results returned, verify the shape
        foreach ($r['json']['results'] as $item) {
            $this->assertArrayHasKey('id',             $item);
            $this->assertArrayHasKey('label',          $item);
            $this->assertArrayHasKey('sublabel',       $item);
            $this->assertArrayHasKey('property_count', $item);
            $this->assertIsInt($item['id']);
            $this->assertIsInt($item['property_count']);
        }
    }

    /** @test */
    public function client_search_company_results_have_expected_shape(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/client-search.php', ['action' => 'search', 'q' => 'a', 'type' => 'company']);
        $this->assertJsonOk($r, 'client-search.php?action=search&type=company');

        foreach ($r['json']['results'] as $item) {
            $this->assertArrayHasKey('id',    $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertIsInt($item['id']);
        }
    }

    /** @test */
    public function client_search_properties_missing_params_returns_error_json(): void
    {
        $this->skipUnlessAuthenticated();

        // No contact_id or company_id
        $r = $this->get('/crm/api/client-search.php', ['action' => 'properties']);
        $this->assertNotEquals(500, $r['status'],
            'client-search.php?action=properties (no id) returned 500');
        $this->assertNotNull($r['json'], 'Expected JSON error response');
        $this->assertFalse($r['json']['success'] ?? true,
            'Expected success=false when contact_id and company_id both missing');
    }

    /** @test */
    public function client_search_properties_by_contact_returns_array(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/client-search.php', ['action' => 'properties', 'contact_id' => '1']);
        $this->assertJsonOk($r, 'client-search.php?action=properties&contact_id=1');
        $this->assertIsArray($r['json']['properties']);

        foreach ($r['json']['properties'] as $prop) {
            foreach (['id', 'address', 'city', 'province', 'status'] as $key) {
                $this->assertArrayHasKey($key, $prop,
                    "Property result missing key '{$key}'");
            }
        }
    }

    /** @test */
    public function client_search_all_contacts_returns_array(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/client-search.php', ['action' => 'all-contacts']);
        $this->assertJsonOk($r, 'client-search.php?action=all-contacts');
        $this->assertIsArray($r['json']['results']);
        // At most 100 (the hardcoded limit)
        $this->assertLessThanOrEqual(100, count($r['json']['results']));
    }

    // ── global-search.php authenticated ──────────────────────────────────────

    /** @test */
    public function global_search_short_query_returns_empty(): void
    {
        $this->skipUnlessAuthenticated();

        // 1-char query — below the 2-char minimum
        $r = $this->get('/crm/api/global-search.php', ['q' => 'a']);
        $this->assertSame(200, $r['status']);
        $this->assertNotNull($r['json']);
        $this->assertTrue($r['json']['success'] ?? false);
        $this->assertSame([], $r['json']['results']);
    }

    /** @test */
    public function global_search_results_have_expected_shape(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/global-search.php', ['q' => 'test']);
        $this->assertSame(200, $r['status']);
        $this->assertNotNull($r['json']);
        $this->assertIsArray($r['json']['results']);

        foreach ($r['json']['results'] as $item) {
            $this->assertArrayHasKey('category', $item);
            $this->assertArrayHasKey('label',    $item);
            $this->assertArrayHasKey('url',      $item);
            $this->assertArrayHasKey('icon',     $item);
            $this->assertStringStartsWith('/', $item['url'],
                "Result URL must be root-relative, got: {$item['url']}");
        }
    }

    // ── contact-timeline.php authenticated ───────────────────────────────────

    /** @test */
    public function contact_timeline_missing_contact_id_returns_400(): void
    {
        $this->skipUnlessAuthenticated();

        // Authenticated but no contact_id — should return 400
        $r = $this->get('/crm/api/contact-timeline.php');
        $this->assertNotEquals(500, $r['status']);
        $this->assertSame(400, $r['status'],
            'contact-timeline.php with no contact_id should return 400');
        $this->assertNotNull($r['json']);
        $this->assertArrayHasKey('error', $r['json']);
    }

    /** @test */
    public function contact_timeline_returns_paginated_shape(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/contact-timeline.php', [
            'contact_id' => '1',
            'page'       => '1',
            'per_page'   => '10',
        ]);

        // Contact 1 may not exist — both 200 and 404 are acceptable.
        // What's NOT acceptable: 500.
        $this->assertNotEquals(500, $r['status'],
            'contact-timeline.php returned 500. Body: ' . substr($r['body'], 0, 300));
        $this->assertNoPHPErrors($r, 'contact-timeline.php');

        if ($r['status'] === 200 && $r['json'] !== null) {
            $this->assertArrayHasKey('events', $r['json']);
            $this->assertIsArray($r['json']['events']);
        }
    }
}
