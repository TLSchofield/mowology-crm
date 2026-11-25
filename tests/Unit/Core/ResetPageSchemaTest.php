<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The iPhone "Forgot password?" pages use production's password_reset_tokens shape
 * (id, user_id, token, expires_at, created_at — checked 2026-10-12). They queried token_hash/used_at,
 * which do not exist, and fatalled on every request.
 */
final class ResetPageSchemaTest extends TestCase
{
    public function testNoPhantomColumns(): void
    {
        foreach (['public/forgot-password.php', 'public/reset-password.php'] as $f) {
            $s = (string)file_get_contents(__DIR__ . '/../../../' . $f);
            $sql = implode("\n", array_filter(explode("\n", $s), fn($l) => !preg_match('~^\s*(//|\*)~', $l)));
            $this->assertDoesNotMatchRegularExpression('/\btoken_hash\b|\bused_at\b/', $sql, $f);
        }
        $fp = (string)file_get_contents(__DIR__ . '/../../../public/forgot-password.php');
        $this->assertStringContainsString('INSERT INTO password_reset_tokens (user_id, token, expires_at)', $fp);
        $rp = (string)file_get_contents(__DIR__ . '/../../../public/reset-password.php');
        $this->assertStringContainsString('WHERE  prt.token = ?', $rp);
        $this->assertStringContainsString('DELETE FROM password_reset_tokens WHERE user_id = ?', $rp);
    }
}
