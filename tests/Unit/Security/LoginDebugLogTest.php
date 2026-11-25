<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-10-12 #24: login-debug.log recorded every raw login identifier, IP and the
 * password length, unbounded. The file copy now needs LOGIN_DEBUG, and loginUser() logs no input.
 */
final class LoginDebugLogTest extends TestCase
{
    private static function auth(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Core/Auth/auth.php');
    }

    private static function loginUserBody(string $src): string
    {
        $start = strpos($src, 'function loginUser(');
        self::assertNotFalse($start, 'loginUser() not found');
        $end = strpos($src, "\nfunction ", $start + 10);
        return substr($src, $start, $end === false ? null : $end - $start);
    }

    public function testFileLogIsGatedOnLoginDebugConstant(): void
    {
        $src = self::auth();
        $fn = substr($src, strpos($src, 'function _loginDebugLog('), 600);
        $gate = strpos($fn, "defined('LOGIN_DEBUG')");
        $write = strpos($fn, 'file_put_contents');
        $this->assertNotFalse($gate, '_loginDebugLog must check LOGIN_DEBUG');
        $this->assertNotFalse($write);
        $this->assertLessThan($write, $gate, 'the LOGIN_DEBUG check must come before the file write');
    }

    public function testLoginUserNeverLogsRawInputOrPasswordLength(): void
    {
        $body = self::loginUserBody(self::auth());
        $this->assertStringNotContainsString('strlen($password)', $body);
        $this->assertStringNotContainsString('pwLen', $body);
        $this->assertStringNotContainsString('[login-debug]', $body);
        $this->assertStringNotContainsString('$rawInput', $body);
        $this->assertSame(1, substr_count($body, 'password_verify('), 'one password_verify, no debug duplicate');
    }
}
