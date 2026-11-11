<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-10-12 (low): dev-context.php accepted its token in ?token=, which lands in
 * access logs and proxies. Header only now, and the hook sends the header.
 */
final class DevContextTokenTest extends TestCase
{
    public function testEndpointReadsTheTokenFromTheHeaderOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/public/crm/api/dev-context.php');
        $this->assertStringContainsString("\$_SERVER['HTTP_X_CONTEXT_TOKEN']", $src);
        $this->assertDoesNotMatchRegularExpression('/\$_(GET|REQUEST)\s*\[\s*[\'"]token[\'"]\s*\]/', $src);
        $this->assertStringContainsString('hash_equals(', $src);
    }

    public function testHookSendsTheHeaderNotAQueryString(): void
    {
        $hook = dirname(__DIR__, 3) . '/.claude/hooks/fetch-dev-context.sh';
        if (!is_file($hook)) {
            $this->markTestSkipped('hook not present');
        }
        $src = (string) file_get_contents($hook);
        $this->assertStringContainsString('X-Context-Token:', $src);
        $this->assertStringNotContainsString('?token=', $src);
    }
}
