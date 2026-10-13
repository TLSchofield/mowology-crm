<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthFreshnessTest extends TestCase
{
    public function testRecheckDueEveryMinute(): void
    {
        $this->assertTrue(AuthFreshness::recheckDue(0, 1000));
        $this->assertFalse(AuthFreshness::recheckDue(1000, 1059));
        $this->assertTrue(AuthFreshness::recheckDue(1000, 1060));
        $this->assertTrue(AuthFreshness::recheckDue(2000, 1000)); // clock went backwards
    }

    public function testSessionVerdict(): void
    {
        $this->assertSame('logout', AuthFreshness::sessionVerdict(null, 'staff'));
        $this->assertSame('logout', AuthFreshness::sessionVerdict(['role' => 'staff', 'is_active' => 0], 'staff'));
        $this->assertSame('ok', AuthFreshness::sessionVerdict(['role' => 'staff', 'is_active' => null], 'staff'));
        $this->assertSame('ok', AuthFreshness::sessionVerdict(['role' => 'staff', 'is_active' => 1, 'is_driver' => 0], 'staff', false));
        $this->assertSame('refresh', AuthFreshness::sessionVerdict(['role' => 'staff', 'is_active' => 1], 'admin'));
        $this->assertSame('refresh', AuthFreshness::sessionVerdict(['role' => 'staff', 'is_active' => 1, 'is_driver' => 1], 'staff', false));
        $this->assertSame('ok', AuthFreshness::sessionVerdict(['role' => '', 'is_active' => 1], 'user'));
    }

    public function testPermCacheExpires(): void
    {
        $c = ['user_id' => 7, 'version' => 5, 'permissions' => ['jobs.view'], 'at' => 1000];
        $this->assertTrue(AuthFreshness::permCacheFresh($c, 7, 5, 1299));
        $this->assertFalse(AuthFreshness::permCacheFresh($c, 7, 5, 1300));
        $this->assertFalse(AuthFreshness::permCacheFresh($c, 8, 5, 1100));
        $this->assertFalse(AuthFreshness::permCacheFresh($c, 7, 4, 1100));
        unset($c['at']); // caches written before the TTL existed are rebuilt
        $this->assertFalse(AuthFreshness::permCacheFresh($c, 7, 5, 1100));
        $this->assertFalse(AuthFreshness::permCacheFresh(null, 7, 5, 1100));
    }

    public function testJwtPasswordVersion(): void
    {
        $hash = password_hash('a-long-password', PASSWORD_DEFAULT);
        $v = AuthFreshness::passwordVersion($hash);
        $this->assertSame(8, strlen($v));
        $this->assertTrue(AuthFreshness::jwtPasswordOk(['sub' => '1'], $hash), 'old tokens without pwd_v pass');
        $this->assertTrue(AuthFreshness::jwtPasswordOk(['pwd_v' => $v], $hash));
        $newHash = password_hash('another-password', PASSWORD_DEFAULT);
        $this->assertFalse(AuthFreshness::jwtPasswordOk(['pwd_v' => $v], $newHash), 'password changed');
        $this->assertFalse(AuthFreshness::jwtPasswordOk(['pwd_v' => ''], $hash));
        $this->assertFalse(AuthFreshness::jwtPasswordOk(['pwd_v' => $v], ''));
    }
}
