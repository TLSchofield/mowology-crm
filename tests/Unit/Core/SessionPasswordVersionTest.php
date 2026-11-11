<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** #12 (Fable audit 2026-10-12): a password change ends existing web sessions. */
final class SessionPasswordVersionTest extends TestCase
{
    public function testSameHashKeepsSession(): void
    {
        $h = password_hash('correct horse battery', PASSWORD_DEFAULT);
        $v = AuthFreshness::passwordVersion($h);
        $this->assertSame('ok', AuthFreshness::sessionPasswordVerdict($v, $h));
    }

    public function testChangedPasswordLogsOut(): void
    {
        $old = password_hash('old password 1234', PASSWORD_DEFAULT);
        $new = password_hash('new password 5678', PASSWORD_DEFAULT);
        $this->assertSame('logout', AuthFreshness::sessionPasswordVerdict(AuthFreshness::passwordVersion($old), $new));
        $this->assertSame('logout', AuthFreshness::sessionPasswordVerdict(AuthFreshness::passwordVersion($old), ''));
    }

    public function testPreRuleSessionAdoptsCurrentVersion(): void
    {
        $h = password_hash('x', PASSWORD_DEFAULT);
        $this->assertSame('adopt', AuthFreshness::sessionPasswordVerdict(null, $h));
        $this->assertSame('adopt', AuthFreshness::sessionPasswordVerdict('', $h));
        $this->assertSame('ok', AuthFreshness::sessionPasswordVerdict(null, null));
    }

    public function testLoginAndRecheckUseTheRule(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Core/Auth/auth.php');
        $this->assertStringContainsString("\$_SESSION['pwd_v']           = AuthFreshness::passwordVersion(", $src);
        $this->assertStringContainsString('AuthFreshness::sessionPasswordVerdict(', $src);
        $this->assertMatchesRegularExpression('/SELECT role, is_active, is_driver, password_hash FROM users/', $src);
    }

    public function testLoginIpCeilingIgnoresResetAndPinRows(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Core/Auth/auth.php');
        $this->assertStringContainsString("email NOT LIKE 'reset:%' AND email NOT LIKE 'pin:%'", $src);
    }
}
