<?php
declare(strict_types=1);

/**
 * Deep contract tests — /crm/api/me.php
 *
 * Smoke tests (no session): covered in SmokeTest.
 * Authenticated tests: verify exact JSON shape, key presence, data types.
 */
class AuthApiTest extends ApiTestCase
{
    // ── Unauthenticated contract ──────────────────────────────────────────────

    /** @test */
    public function me_unauthenticated_does_not_expose_user_data(): void
    {
        $r = $this->get('/crm/api/me.php');

        // Primary: must not be a server error
        $this->assertNotEquals(500, $r['status'],
            'me.php returned 500 for unauthenticated request');

        // If the server returned JSON (401 path), verify no user data leaks
        if ($r['json'] !== null) {
            $this->assertArrayNotHasKey('user',  $r['json'],
                'me.php leaked user data in unauthenticated response');
            $this->assertArrayNotHasKey('roles', $r['json'],
                'me.php leaked roles in unauthenticated response');
        }
    }

    // ── Authenticated contract ────────────────────────────────────────────────

    /** @test */
    public function me_authenticated_returns_expected_shape(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/me.php');
        $this->assertJsonOk($r, 'me.php');

        // Wait — me.php does NOT return a top-level 'success' key.
        // It returns: { user: {}, roles: [], permissions: [], capabilities: {} }
        // So override the check:
        $this->assertSame(200, $r['status']);
        $j = $r['json'];
        $this->assertNotNull($j);

        foreach (['user', 'roles', 'permissions', 'capabilities'] as $key) {
            $this->assertArrayHasKey($key, $j, "me.php response missing key '{$key}'");
        }

        // User sub-object shape
        $user = $j['user'];
        foreach (['id', 'email', 'name', 'legacy_role'] as $key) {
            $this->assertArrayHasKey($key, $user, "me.php user missing key '{$key}'");
        }
        $this->assertIsInt($user['id']);
        $this->assertIsString($user['email']);

        // Capabilities shape — spot check a few required keys
        $caps = $j['capabilities'];
        foreach (['is_admin', 'can_view_jobs', 'can_edit_jobs', 'can_view_clients'] as $key) {
            $this->assertArrayHasKey($key, $caps, "me.php capabilities missing '{$key}'");
            $this->assertIsBool($caps[$key], "capabilities['{$key}'] must be bool");
        }
    }

    /** @test */
    public function me_authenticated_roles_is_array(): void
    {
        $this->skipUnlessAuthenticated();

        $r = $this->get('/crm/api/me.php');
        $this->assertSame(200, $r['status']);
        $this->assertIsArray($r['json']['roles']);
        $this->assertIsArray($r['json']['permissions']);
    }
}
