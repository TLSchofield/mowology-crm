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
        $this->assertStringContainsString('function playInk()', $js, "the merge's resolution: the ink line");
        $this->assertStringContainsString("((isMerge || isRecord || isApprove) ? playInk() : Promise.resolve())", $js, 'a merge or a recorded payment draws the ink; Different and Not this invoice are immediate');
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
        $this->assertStringContainsString('.it-job-ink { display: none; }', $block, 'the ink line stays still under reduced motion');
        $this->assertStringContainsString('.it-job-ink {', $block);
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
                // the graph card's chart icon calls $__ijSvg inside a static closure: it must be captured (a missing use broke the page, 2026-11-10)
        $this->assertStringContainsString('static function (?array $p) use ($__ijSvg): string', $inc);
        foreach (["'forming', 'held', 'known', 'mastered'", 'First time with this vendor.', 'it-st-approval-'] as $n) $this->assertStringContainsString($n, $inc, $n);
        foreach (['is-forming', 'is-held', 'is-known', 'is-mastered', 'First time with this vendor.'] as $n) $this->assertStringContainsString($n, $js, $n);
    }

    /** Penny's receipt job: its own rail and skeleton, Approve posts the Expenses card's own form, Not this opens the editor and never dismisses. */
    public function testReceiptJobIsWiredToTheCardsEndpoint(): void
    {
        $inc = $this->src('public/crm/includes/ittai-job.php');
        foreach (['data-kind="receipt"', '$__ijRailReceipt', 'PennyReceiptJobAdapter::jobs', "expenses.edit"] as $n) $this->assertStringContainsString($n, $inc, $n);
        $js = $this->src('public/crm/js/ittai-job.js');
        foreach (["req.action === 'approve'", "req.mode === 'edit'", 'function editReceipt(', 'function dropFromCard('] as $n) $this->assertStringContainsString($n, $js, $n);
        $this->assertStringNotContainsString("action: 'dismiss'", $js, 'dismissing stays a deliberate click on the card');
        $ad = $this->src('app/Modules/Expenses/Services/PennyReceiptJobAdapter.php');
        $this->assertStringContainsString('/crm/api/receipt-inbox-confirm.php', $ad);
        $this->assertStringNotContainsString("'dismiss'", $ad);
    }

    /** The line is always there (Tim, 2026-11-10): no jobs renders the bare resting line, and finishing settles back to it instead of removing the strip. */
    public function testTheLineRestsInsteadOfDisappearing(): void
    {
        $inc = $this->src('public/crm/includes/ittai-job.php');
        $js = $this->src('public/crm/js/ittai-job.js');
        $this->assertStringNotContainsString('if (!$__ijJobs) return;', $inc, 'no jobs no longer means no strip');
        foreach (['$__ijRailIdle', 'Nothing to decide', 'data-kind="idle"'] as $n) $this->assertStringContainsString($n, $inc, $n);
        $this->assertStringContainsString("tplFor('idle')", $js);
        $this->assertStringNotContainsString('Penny has nothing else for you', $js);
        $this->assertStringNotContainsString("root.parentNode.removeChild(root); }, withUndo", $js, 'the strip is never removed after the last job');
    }

    /** Penny's e-Transfer job: the fraction and its label on the rail, two templates, the card's own form posted by the script. */
    public function testEtransferJobCarriesTheFractionAndTheCardsForm(): void
    {
        $inc = $this->src('public/crm/includes/ittai-job.php');
        foreach (['data-kind="etransfer"', 'data-kind="duplicate"', 'it-job-pieces', 'it-job-frac-n', 'it-job-frac-s', 'it-job-frac-d', 'it-job-side-', 'hasEtransferWork', 'billing.edit', "\$__ijSvg('bank')"] as $n) {
            $this->assertStringContainsString($n, $inc, $n);
        }
        $this->assertSame(5, substr_count($inc, '<template class="it-job-tpl"'), 'one skeleton per kind (duplicate, etransfer, month, receipt) and the resting line');
        $this->assertSame(1, substr_count($inc, 'it-btn-fill'), 'the one filled button is built in one place for both kinds');
        $js = $this->src('public/crm/js/ittai-job.js');
        foreach (['new FormData()', "k + '[]'", "transport === 'form'", "req.action === 'record'", "req.mode === 'pick'", 'fully_allocated', 'it-job-frac-n', 'tplFor('] as $n) {
            $this->assertStringContainsString($n, $js, $n);
        }
        $this->assertStringNotContainsString("'/crm/api/etransfer-confirm.php'", $js, 'the endpoint comes from the job (the adapter), not the script');
        $css = $this->src('public/crm/css/ittai-shell.css');
        $a = strpos($css, '.it-job-pieces {');
        $this->assertNotFalse($a);
        $block = substr($css, $a, strpos($css, "/* Yui's job strip") - $a);
        foreach (['.it-job-frac {', '.it-job-frac-s {', 'stroke: var(--it-rail)', '.it-job-side-top {', '.it-job-side-bot {', 'var(--it-rail)', 'var(--it-meta)', '[data-kind="etransfer"]'] as $n) {
            $this->assertStringContainsString($n, $block, $n);
        }
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $block, 'tokens only');
        $this->assertStringContainsString('id="it-w-bank"', $this->src('public/crm/includes/ittai-icons.php'));
    }

    public function testHeadIncludesTheStripOnlyInTheIttaiShell(): void
    {
        $head = $this->src('public/crm/includes/appstack_head.php');
        $this->assertMatchesRegularExpression('/if \(!empty\(\$itShell\)\) \{ include __DIR__ \. \'\/ittai-kaname-band\.php\'; include __DIR__ \. \'\/ittai-job\.php\'; include __DIR__ \. \'\/ittai-job-yui\.php\'; include __DIR__ \. \'\/ittai-job-mia\.php\'; include __DIR__ \. \'\/ittai-job-mia-ba\.php\'; \}/', $head, "Kaname's line off her row, then the head's job strips, only in the shell");
        $this->assertStringNotContainsString('ittai-kaname-space', $head, 'the page band is gone: one Kaname, in the topbar');
        $this->assertGreaterThan(strpos($head, '<div class="container-fluid p-0">'), strpos($head, 'ittai-job.php'));
    }
}
