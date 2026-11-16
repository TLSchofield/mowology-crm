<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * H2 (Fable audit, Tim 2026-11-16, decisions D1-D3): every app sign-in is on record, and a record can be revoked.
 * A token with no record (issued before this rule, so no jti) is refused. Database tests use SQLite, which
 * has the same table shape as migration 1458 (MySQL 5.7).
 */
class AppTokenServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE app_token_sessions (
            jti TEXT PRIMARY KEY, user_id INTEGER NOT NULL, platform TEXT NOT NULL DEFAULT \'ios\',
            created_at TEXT NOT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        return $pdo;
    }

    public function testJtiIsThirtyTwoLowerHexCharacters(): void
    {
        $jti = AppTokenService::newJti();
        $this->assertTrue(AppTokenService::isJti($jti));
        $this->assertNotSame($jti, AppTokenService::newJti());
        $this->assertFalse(AppTokenService::isJti(''));
        $this->assertFalse(AppTokenService::isJti(str_repeat('A', 32)));
        $this->assertFalse(AppTokenService::isJti(str_repeat('a', 31)));
    }

    public function testMissingRecordIsRefused(): void
    {
        // A token issued before this rule has no jti, so there is no row. It must be refused.
        $this->assertSame('unknown token', AppTokenService::refusal(null, 7));
    }

    public function testLiveRecordOfTheSameUserIsAccepted(): void
    {
        $this->assertNull(AppTokenService::refusal(['user_id' => 7, 'revoked_at' => null], 7));
    }

    public function testRevokedRecordIsRefused(): void
    {
        $this->assertSame('token revoked', AppTokenService::refusal(['user_id' => 7, 'revoked_at' => '2026-11-16 10:00:00'], 7));
    }

    public function testRecordOfAnotherUserIsRefused(): void
    {
        $this->assertSame('token belongs to another user', AppTokenService::refusal(['user_id' => 8, 'revoked_at' => null], 7));
    }

    public function testIssueThenFindReturnsTheRow(): void
    {
        $db = $this->db();
        $jti = AppTokenService::newJti();
        AppTokenService::issue($db, 7, $jti, 'ios', 3600, self::NOW);
        $row = AppTokenService::find($db, $jti);
        $this->assertSame(7, (int)$row['user_id']);
        $this->assertNull($row['revoked_at']);
        $this->assertNull(AppTokenService::refusal($row, 7));
    }

    public function testFailedRecordIsAnExceptionEvenInSilentErrorMode(): void
    {
        // Codex (H2 review): a silent PDO returns false instead of throwing. No row may mean no token.
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->expectException(RuntimeException::class);
        AppTokenService::issue($db, 7, AppTokenService::newJti(), 'ios', 3600, self::NOW);
    }

    public function testIssueRejectsAMalformedJti(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AppTokenService::issue($this->db(), 7, 'not-a-jti', 'ios', 3600, self::NOW);
    }

    public function testRevokeStopsTheTokenAndOnlyForItsOwner(): void
    {
        $db = $this->db();
        $jti = AppTokenService::newJti();
        AppTokenService::issue($db, 7, $jti, 'ios', 3600, self::NOW);

        $this->assertFalse(AppTokenService::revoke($db, $jti, 8, self::NOW), 'another user cannot revoke it');
        $this->assertNull(AppTokenService::refusal(AppTokenService::find($db, $jti), 7), 'still live after the wrong user tried');

        $this->assertTrue(AppTokenService::revoke($db, $jti, 7, self::NOW + 10));
        $this->assertSame('token revoked', AppTokenService::refusal(AppTokenService::find($db, $jti), 7));
        $this->assertFalse(AppTokenService::revoke($db, $jti, 7, self::NOW + 20), 'a second revoke changes nothing');
    }

    public function testActiveForListsOnlyLiveSignIns(): void
    {
        $db = $this->db();
        $live = AppTokenService::newJti();
        $revoked = AppTokenService::newJti();
        $expired = AppTokenService::newJti();
        AppTokenService::issue($db, 7, $live, 'ios', 3600, self::NOW);
        AppTokenService::issue($db, 7, $revoked, 'ios', 3600, self::NOW);
        AppTokenService::revoke($db, $revoked, 7, self::NOW);
        AppTokenService::issue($db, 7, $expired, 'app', 60, self::NOW - 7200);
        AppTokenService::issue($db, 8, AppTokenService::newJti(), 'ios', 3600, self::NOW);

        $active = AppTokenService::activeFor($db, 7, self::NOW + 1);
        $this->assertCount(1, $active);
        $this->assertSame($live, $active[0]['jti']);
    }

    public function testPhoneSignInTokenCarriesTheJti(): void
    {
        $jti = AppTokenService::newJti();
        $user = ['id' => 7, 'email' => 'a@example.com', 'full_name' => 'A', 'role' => 'crew', 'password_hash' => 'x'];
        [$token] = PhoneSignIn::token($user, 'default', 'mobile', str_repeat('s', 40), self::NOW, $jti);
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertSame($jti, $payload['jti']);
    }
}
