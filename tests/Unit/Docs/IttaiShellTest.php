<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Static guards for the round-6 Ittai sidebar (the heads are the navigation): safe DOM use, tokens only, icon + word, wiring. */
class IttaiShellTest extends TestCase
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

    /** The it-shell branch of the sidebar include, between its opening if and the classic branch. */
    private function itShellBlock(): string
    {
        $s = $this->src('public/crm/includes/appstack_sidebar.php');
        $a = strpos($s, 'if (!empty($itShell)):');
        $b = strpos($s, '<?php return; endif;', (int)$a);
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);
        return substr($s, $a, $b - $a);
    }

    public function testScriptNeverTurnsTextIntoMarkup(): void
    {
        $js = preg_replace('#^\s*//[^\n]*$#m', '', (string)preg_replace('#/\*.*?\*/#s', '', $this->src('public/crm/js/ittai-shell.js')));
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function'] as $bad) {
            $this->assertStringNotContainsString($bad, $js, $bad);
        }
        $this->assertStringContainsString("'use strict'", $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
    }

    public function testStylesheetUsesTokensAndKeepsFocusAndMotionRules(): void
    {
        $css = $this->src('public/crm/css/ittai-shell.css');
        $this->assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', $css), 'no colour literals, tokens only');
        foreach (['prefers-reduced-motion', ':focus-visible', '.it-hp', '.it-fly', '.sidebar.collapsed'] as $needle) {
            $this->assertStringContainsString($needle, $css, $needle);
        }
    }

    public function testSidebarBlockIsAccessibleAndWired(): void
    {
        $b = $this->itShellBlock();
        foreach (['aria-expanded', 'aria-current="page"', 'data-spotlight-open', 'HeadNavService::forShell', 'itIconSprite()'] as $needle) {
            $this->assertStringContainsString($needle, $b, $needle);
        }
    }

    public function testEveryStateWordComesWithItsGlyph(): void
    {
        $b = $this->itShellBlock();
        $n = preg_match_all('/it-s it-s-/', $b);
        $this->assertGreaterThan(0, $n);
        $this->assertSame($n, preg_match_all('/it-s it-s-[^\n]*<use href="#it-st-/', $b), 'a state is never colour alone: each state span carries its glyph');
    }

    public function testClassicAndLedgerBranchesAreUntouched(): void
    {
        $s = $this->src('public/crm/includes/appstack_sidebar.php');
        $this->assertStringContainsString('_idFromName', $s);
        $this->assertStringContainsString('mwLedgerPages', $s);
    }

    public function testKanameLivesOnlyInTheHeadBlocks(): void
    {
        $s = $this->src('public/crm/includes/nav-items.php');
        $fn = strpos($s, 'function mwIttaiNavBlocks');
        $at = strpos($s, "'key' => 'kaname'");
        $this->assertNotFalse($fn);
        $this->assertNotFalse($at);
        $this->assertGreaterThan($fn, $at, 'the Morning brief page is inside mwIttaiNavBlocks');
        $this->assertSame(1, substr_count($s, "'key' => 'kaname'"), 'and nowhere in the classic $navItems list');
    }
}
