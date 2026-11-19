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
        $token = generateMobileJwt(['id' => 7, 'email' => 'owner@example.com', 'full_name' => 'Ada Lovelace', 'role' => 'admin', 'password_hash' => $hash], 120);
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)), true);
        $this->assertSame(['iss', 'aud', 'sub', 'email', 'name', 'role', 'iat', 'exp', 'pwd_v'], array_keys($payload));
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
