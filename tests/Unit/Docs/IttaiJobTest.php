<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Static guards for the job strip (Penny's duplicate receipt across the top of her pages): safe DOM use, gates, tokens only, wiring. */
class IttaiJobTest extends TestCase
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
        $js = $this->src('public/crm/js/ittai-job.js');
        $this->assertStringContainsString("'use strict'", $js);
        $this->assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|\beval\s*\(|new Function/', $js);
        $this->assertStringContainsString('X-CSRF-Token', $js);
        $this->assertStringContainsString('get-csrf.php', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('/crm/api/bookkeeper.php', $js);
    }

    public function testIncludeIsGatedAndHasOneFilledButton(): void
    {
        $inc = $this->src('public/crm/includes/ittai-job.php');
        foreach (['itShell', 'expenses.view', 'isOwner', 'hasWork', 'mwIttaiNavBlocks'] as $needle) {
            $this->assertStringContainsString($needle, $inc, $needle);
        }
        $this->assertSame(1, substr_count($inc, 'it-btn-fill'));
        $this->assertStringContainsString('JSON_HEX_TAG', $inc);
        $this->assertStringContainsString('ittai-job.js', $inc);
        $this->assertStringNotContainsString('<style', $inc);
    }

    public function testCssUsesTokensAndBigTargets(): void
    {
        $css = $this->src('public/crm/css/ittai-shell.css');
        $a = strpos($css, '.it-job {');
        $this->assertNotFalse($a);
        $block = substr($css, $a);
        $this->assertStringContainsString('min-height: 56px', $block);
        $this->assertStringContainsString('min-height: 44px', $block);
        $this->assertStringContainsString('@container (max-width: 900px)', $block);
        $this->assertStringContainsString('.it-job-rail.is-enter { animation: none; }', $block);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $block);
    }

    public function testIconsExistInTheSprite(): void
    {
        $icons = $this->src('public/crm/includes/ittai-icons.php');
        foreach (['check', 'neq', 'x', 'info', 'merge', 'split', 'book'] as $id) {
            $this->assertStringContainsString('id="it-w-' . $id . '"', $icons, $id);
        }
        foreach (['approval', 'approval-held', 'approval-forming'] as $id) {
            $this->assertStringContainsString('id="it-st-' . $id . '"', $icons, $id);
        }
        $inc = $this->src('public/crm/includes/ittai-job.php');
        $js = $this->src('public/crm/js/ittai-job.js');
        foreach (["'forming', 'held', 'earned'", 'First time with this vendor.', 'it-st-approval-'] as $n) $this->assertStringContainsString($n, $inc, $n);
        foreach (['is-forming', 'is-held', 'is-earned', 'First time with this vendor.'] as $n) $this->assertStringContainsString($n, $js, $n);
    }

    public function testHeadIncludesTheStripOnlyInTheIttaiShell(): void
    {
        $head = $this->src('public/crm/includes/appstack_head.php');
        $this->assertMatchesRegularExpression('/if \(!empty\(\$itShell\)\) \{ include __DIR__ \. \'\/ittai-job\.php\'; include __DIR__ \. \'\/ittai-job-yui\.php\'; \}/', $head, "the head's job strips, only in the shell (Kaname's band lives in the topbar now)");
        $this->assertStringNotContainsString('ittai-kaname-space', $head, 'the page band is gone: one Kaname, in the topbar');
        $this->assertGreaterThan(strpos($head, '<div class="container-fluid p-0">'), strpos($head, 'ittai-job.php'));
    }
}
