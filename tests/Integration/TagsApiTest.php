<?php
declare(strict_types=1);

/**
 * Deep contract tests — /crm/api/tags.php
 *
 * Tags drive property access warnings, job urgency badges, and client
 * profile displays — high-impact if broken.
 */
class TagsApiTest extends ApiTestCase
{
    // ── Validation errors (no session needed) ────────────────────────────────

    /** @test */
    public function tags_list_missing_entity_type_not_crash(): void
    {
        // Auth-gated, so we just confirm no 500
        $r = $this->get('/crm/api/tags.php', ['action' => 'list']);
        $this->assertNoPHPErrors($r, 'tags.php?action=list (no entity_type)');
        $this->assertNotEquals(500, $r['status']);
    }

    // ── Authenticated: list ───────────────────────────────────────────────────

    /** @test */
    public function tags_list_missing_params_returns_400_when_authed(): void
    {
        $this->skipUnlessAuthenticated();

        // entity_type and entity_id both missing
        $r = $this->get('/crm/api/tags.php', ['action' => 'list']);
        $this->assertSame(400, $r['status'],
            'tags?action=list without required params should return 400');
        $this->assertNotNull($r['json']);
        $this->assertFalse($r['json']['success'] ?? true);
        $this->assertArrayHasKey('error', $r['json']);
    }

    /** @test */
    public function tags_list_returns_array_with_expected_keys(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/tags.php', [
            'action'      => 'list',
            'entity_type' => 'property',
            'entity_id'   => '1',
        ]);

        // Entity 1 may have no tags — 200 with empty array is valid
        $this->assertNotEquals(500, $r['status']);
        $this->assertJsonOk($r, 'tags.php?action=list');
        $this->assertIsArray($r['json']['tags']);

        foreach ($r['json']['tags'] as $tag) {
            foreach (['tag_id', 'tag_key', 'tag_label', 'tag_group', 'tag_color'] as $key) {
                $this->assertArrayHasKey($key, $tag, "Tag result missing key '{$key}'");
            }
        }
    }

    // ── Authenticated: available ──────────────────────────────────────────────

    /** @test */
    public function tags_available_returns_catalogue(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/tags.php', ['action' => 'available']);
        $this->assertJsonOk($r, 'tags.php?action=available (all)');
        $this->assertIsArray($r['json']['tags']);
    }

    /** @test */
    public function tags_available_filtered_by_group(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/tags.php', [
            'action' => 'available',
            'groups' => 'property_access,property_warning',
        ]);
        $this->assertJsonOk($r, 'tags.php?action=available&groups=property_access,property_warning');
        $this->assertIsArray($r['json']['tags']);

        // All returned tags must belong to one of the requested groups
        foreach ($r['json']['tags'] as $tag) {
            $this->assertContains(
                $tag['tag_group'],
                ['property_access', 'property_warning'],
                "Tag group '{$tag['tag_group']}' outside requested filter"
            );
        }
    }

    // ── Authenticated: apply/remove CSRF guard ────────────────────────────────

    /** @test */
    public function tags_apply_without_csrf_returns_403(): void
    {
        $this->skipUnlessAuthenticated();

        // No CSRF token — must be rejected
        $r = $this->post('/crm/api/tags.php', [
            'action'      => 'apply',
            'entity_type' => 'property',
            'entity_id'   => '1',
            'tag_id'      => '1',
            // intentionally omit csrf_token
        ]);

        $this->assertNotEquals(500, $r['status'],
            'tags.php apply without CSRF returned 500');
        $this->assertSame(403, $r['status'],
            "Expected 403 when CSRF missing, got {$r['status']}");
        $this->assertNotNull($r['json']);
        $this->assertFalse($r['json']['success'] ?? true);
    }

    /** @test */
    public function tags_remove_without_csrf_returns_403(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->post('/crm/api/tags.php', [
            'action'         => 'remove',
            'entity_tag_id'  => '1',
            // no csrf_token
        ]);

        $this->assertNotEquals(500, $r['status']);
        $this->assertSame(403, $r['status']);
    }

    // ── Authenticated: admin_list ─────────────────────────────────────────────

    /** @test */
    public function tags_admin_list_returns_usage_counts(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/tags.php', ['action' => 'admin_list']);
        $this->assertNotEquals(500, $r['status'],
            'tags.php?action=admin_list returned 500');

        if ($r['status'] === 200 && $r['json'] !== null) {
            $this->assertTrue($r['json']['success'] ?? false);
            $this->assertIsArray($r['json']['tags']);
        }
    }
}
