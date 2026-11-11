<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every model call goes through LlmGateway (retry, kill switch, cost log). A new file that curls
 * the messages endpoint directly would silently skip all three, so fail the build instead.
 * Allowed: the gateway itself, and the two non-message endpoints that are not model calls
 * (the key-validity probe and the batch API, both reached by other URLs).
 */
class NoDirectAnthropicCallsTest extends TestCase
{
    public function testNoFileCallsTheMessagesEndpointDirectly(): void
    {
        $root = dirname(__DIR__, 3) . '/app';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $path = $f->getPathname();
            if (substr($path, -strlen('Llm/LlmGateway.php')) === 'Llm/LlmGateway.php') continue;
            // Known exception (2026-11-09): LookbackService has been live since 2026-10-07 from a branch that was never
            // merged; it came into git as the server runs it. Moving its one call to LlmGateway is in the backlog.
            if (substr($path, -strlen('Accounting/Services/LookbackService.php')) === 'Accounting/Services/LookbackService.php') continue;
            // Same (2026-11-09): MiaLlmGateway (Mia's website work, live since 2026-10-26) has its own model call. Backlog.
            if (substr($path, -strlen('Marketing/Services/MiaLlmGateway.php')) === 'Marketing/Services/MiaLlmGateway.php') continue;
            $src = (string)file_get_contents($path);
            // curl_init on the messages URL, or on a constant that holds it, in a file that also sets the Anthropic key.
            if (preg_match("#curl_init\\(\\s*(['\"]https://api\\.anthropic\\.com/v1/messages['\"]|self::API_URL|RECEIPT_LLM_API_URL)#", $src)
                && strpos($src, 'x-api-key') !== false
                && !preg_match('#v1/messages/batches#', $src)) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }
        $this->assertSame([], $offenders, 'These files call Anthropic directly; use LlmGateway::send(): ' . implode(', ', $offenders));
    }
}
