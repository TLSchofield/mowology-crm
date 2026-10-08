<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Stored connection: what the settings card says, and that a key mismatch is never
 * reported as "not connected" (Known-Failure-Patterns, 2026-09-25).
 */
class QboConnectionServiceTest extends TestCase
{
    private function config(): QboConfig
    {
        return new QboConfig('id', 'secret', 'sandbox', 'https://x/cb');
    }

    public function test_never_connected_and_unconfigured_is_said_plainly(): void
    {
        $d = QboConnectionService::describe(null, new QboConfig('', '', 'sandbox', ''), time());
        $this->assertFalse($d['configured']);
        $this->assertFalse($d['connected']);
        $this->assertSame(['QBO_CLIENT_ID', 'QBO_CLIENT_SECRET', 'QBO_REDIRECT_URI'], $d['missing']);
        $this->assertSame('never', $d['status']);
    }

    public function test_an_active_connection_reports_days_left_on_the_refresh_token(): void
    {
        $now = time();
        $conn = ['id' => 1, 'status' => 'active', 'realm_id' => '9', 'company_name' => 'Mowology Ltd',
                 'refresh_token_enc' => SocialEncryption::encrypt('rt'),
                 'refresh_expires_at' => gmdate('Y-m-d H:i:s', $now + 40 * 86400), 'refresh_hard_expires_at' => gmdate('Y-m-d H:i:s', $now + 1000 * 86400)];
        $d = QboConnectionService::describe($conn, $this->config(), $now);
        $this->assertTrue($d['connected']);
        $this->assertSame(40, $d['refresh_days_left']);
        $this->assertSame(1000, $d['hard_days_left']);
        $this->assertNull($d['credential_problem']);
    }

    public function test_a_key_mismatch_is_named_as_such_not_as_disconnected(): void
    {
        $conn = ['id' => 1, 'status' => 'active', 'realm_id' => '9', 'refresh_token_enc' => base64_encode(random_bytes(40))];
        $d = QboConnectionService::describe($conn, $this->config(), time());
        $this->assertTrue($d['connected']);
        $this->assertStringContainsString('SOCIAL_ENCRYPTION_KEY', (string)$d['credential_problem']);
    }

    public function test_an_errored_connection_carries_its_reason(): void
    {
        $conn = ['id' => 1, 'status' => 'error', 'realm_id' => '9', 'last_error' => 'Refresh refused: invalid_grant'];
        $d = QboConnectionService::describe($conn, $this->config(), time());
        $this->assertFalse($d['connected']);
        $this->assertSame('Refresh refused: invalid_grant', $d['last_error']);
    }

    public function test_access_token_is_served_from_storage_while_fresh_and_refresh_stores_the_rotated_token(): void
    {
        $t = (new QboFakeTransport())->push(200, ['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8640000]);
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $captured = [];
        $stmt->method('execute')->willReturnCallback(function (array $params) use (&$captured) { $captured[] = $params; return true; });
        $pdo->method('prepare')->willReturn($stmt);
        $svc = new QboConnectionService($pdo, $this->config(), new QboOAuthService($this->config(), $t));

        $conn = ['id' => 3, 'status' => 'active', 'realm_id' => '9', 'access_token_enc' => SocialEncryption::encrypt('AT1'),
                 'refresh_token_enc' => SocialEncryption::encrypt('RT1'), 'access_expires_at' => gmdate('Y-m-d H:i:s', time() + 3000),
                 'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)];
        $this->assertSame('AT1', $svc->accessToken($conn), 'fresh token: no network');
        $this->assertCount(0, $t->sent);

        $this->assertSame('AT2', $svc->accessToken($conn, true));
        $this->assertCount(1, $t->sent);
        $this->assertSame('RT2', SocialEncryption::decrypt($conn['refresh_token_enc']), 'the rotated refresh token replaced the old one in memory');
        $this->assertSame('RT2', SocialEncryption::decrypt($captured[0][1]), '…and in the UPDATE sent to the database');
    }

    public function test_a_lapsed_refresh_token_is_refused_before_any_network_call(): void
    {
        $t = new QboFakeTransport();
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);
        $svc = new QboConnectionService($pdo, $this->config(), new QboOAuthService($this->config(), $t));
        $conn = ['id' => 3, 'status' => 'active', 'realm_id' => '9', 'access_token_enc' => SocialEncryption::encrypt('AT1'),
                 'refresh_token_enc' => SocialEncryption::encrypt('RT1'), 'access_expires_at' => '2000-01-01 00:00:00', 'refresh_expires_at' => '2000-01-01 00:00:00'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $svc->accessToken($conn);
    }
}
