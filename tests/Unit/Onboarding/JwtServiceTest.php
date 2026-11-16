<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BLUEMOON_JWT_SECRET')) define('BLUEMOON_JWT_SECRET', 'test-secret-that-is-at-least-thirty-two-characters');
        Tenant::forTests(null);
    }

    public function test_mobile_payload_matches_the_token_endpoint_contract(): void
    {
        $hash = password_hash('a secure password', PASSWORD_DEFAULT);
        // The sign-in is recorded in a throwaway database (H2): the token must name its record.
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE app_token_sessions (jti TEXT PRIMARY KEY, user_id INTEGER NOT NULL, platform TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $token = generateMobileJwt(['id' => 7, 'email' => 'owner@example.com', 'full_name' => 'Ada Lovelace', 'role' => 'admin', 'password_hash' => $hash], 120, $db);
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)), true);
        $this->assertSame(['iss', 'aud', 'sub', 'email', 'name', 'role', 'iat', 'exp', 'jti', 'pwd_v'], array_keys($payload));
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM app_token_sessions WHERE jti = '" . $payload['jti'] . "' AND user_id = 7")->fetchColumn());
        $this->assertSame('mowology', $payload['iss']);
        $this->assertSame('ios', $payload['aud']);
        $this->assertSame('7', $payload['sub']);
        $this->assertSame('owner@example.com', $payload['email']);
        $this->assertSame('Ada Lovelace', $payload['name']);
        $this->assertSame('admin', $payload['role']);
        $this->assertSame(120, $payload['exp'] - $payload['iat']);
        $this->assertSame(substr(hash('sha256', $hash), 0, 8), $payload['pwd_v']);
    }
}
