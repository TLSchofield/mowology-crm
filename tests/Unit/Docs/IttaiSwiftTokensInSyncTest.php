<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ItTokens.swift is GENERATED from docs/design/ittai-v1.2/tokens.json. On 2026-10-19 iOS 1.3.58 hand-edited the Swift to AAA
 * while tokens.json stayed behind, so regenerating would have silently undone the AAA colours. The generator's --check mode
 * fails when the committed Swift is not exactly what the source produces.
 */
class IttaiSwiftTokensInSyncTest extends TestCase
{
    public function test_committed_swift_tokens_match_the_source(): void
    {
        $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($node === '') $this->markTestSkipped('node is not installed');
        $root = dirname(__DIR__, 3);
        $out = [];
        exec(escapeshellarg($node) . ' ' . escapeshellarg($root . '/scripts/ittai-swift-tokens.mjs') . ' --check 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
    }
}
