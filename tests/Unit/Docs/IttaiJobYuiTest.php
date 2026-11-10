<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Static guards for Yui's job strip: safe DOM use, gates, demo fixture only on localhost, tokens only, wiring. */
class IttaiJobYuiTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = realpath(__DIR__ . '/../../..');
    }

    private function src(string $rel): string
    {
        return (string)file_get_contents($this->repo . '/' . $rel);
    }

    public function testScriptIsSafeAndHonoursTheRules(): void
    {
        $js = $this->src('public/crm/js/ittai-job-yui.js');
        $this->assertStringContainsString("'use strict'", $js);
        $this->assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\s*\(|new Function/', $js);
        foreach (['X-CSRF-Token', 'get-csrf.php', 'mode=jobs', 'who_accounts', 'prefers-reduced-motion', '6000'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertMatchesRegularExpression("/trim\(\) === ''/", $js, 'primary stays off while the message is empty');
    }

    public function testIncludeGatesAndMarkup(): void
    {
        $inc = $this->src('public/crm/includes/ittai-job-yui.php');
        foreach (['itShell', 'isOwner', 'mwIttaiNavBlocks', 'Not on file for this account. Check before sending',
                  'it-job-tpl-statement', 'it-job-tpl-who', 'it-job-tpl-reply', 'ittai-job-yui.js'] as $needle) {
            $this->assertStringContainsString($needle, $inc, $needle);
        }
        $this->assertSame(1, substr_count($inc, 'it-btn-fill'), 'one filled button');
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b(?!;)/', preg_replace('/&#\d+;|#it-[a-z-]+/', '', $inc));
    }

    public function testDemoFixtureIsLocalOnly(): void
    {
        $lines = explode("\n", $this->src('public/crm/includes/ittai-job-yui.php'));
        $demo = $host = null;
        foreach ($lines as $i => $l) {
            if ($demo === null && strpos($l, "'it_job_demo'") !== false) $demo = $i;
            if ($host === null && strpos($l, "'localhost'") !== false && strpos($l, '127.0.0.1') !== false) $host = $i;
        }
        $this->assertNotNull($demo, 'demo gate');
        $this->assertNotNull($host, 'localhost check');
        $this->assertLessThanOrEqual(3, abs($demo - $host), 'both checks sit together');
    }

    public function testStylesAndHeadWiring(): void
    {
        $css = $this->src('public/crm/css/ittai-shell.css');
        foreach (['.it-job-form', '.it-ctl', '.it-job-warn'] as $needle) {
            $this->assertStringContainsString($needle, $css, $needle);
        }
        $head = $this->src('public/crm/includes/appstack_head.php');
        $this->assertStringContainsString("include __DIR__ . '/ittai-job-yui.php'", $head);
    }
}
