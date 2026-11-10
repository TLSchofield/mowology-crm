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

    /** Everything outside the it-shell block (the Ledger-only and classic menus) carries none of the new markup. */
    public function testClassicAndLedgerBranchesCarryNoneOfTheNewMarkup(): void
    {
        $s = $this->src('public/crm/includes/appstack_sidebar.php');
        $a = strpos($s, 'if (!empty($itShell)):');
        $b = strpos($s, '<?php return; endif;', $a);
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);
        $outside = substr($s, 0, $a) . substr($s, $b);
        $this->assertStringContainsString('mwLedgerPages', $outside);
        $this->assertStringContainsString('_idFromName', $outside);
        foreach (['it-grp', 'it-hp', 'it-fly', 'HeadNavService', 'itIconSprite', 'it-pcard'] as $new) {
            $this->assertStringNotContainsString($new, $outside, $new . ' must stay inside the it-shell block');
        }
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

    /** Round 7: the state lives in the ring and glyph; no state word or chevron in the head row, the spine and the live line exist. */
    public function testRowsCarryNoStateWordAndTheSpineIsDrawn(): void
    {
        $php = $this->src('public/crm/includes/appstack_sidebar.php');
        preg_match_all('#<button type="button" class="it-grow".*?</button>#s', $php, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $row) {
            $this->assertStringNotContainsString('it-s it-s-', $row, 'no state word in a head row');
            $this->assertStringNotContainsString('it-ch it-ic', $row, 'no chevron in a head row');
            $this->assertStringContainsString('$__ring', $row, 'the portrait (its ring class) stays in the row');
        }
        $css = $this->src('public/crm/css/ittai-shell.css');
        $this->assertStringContainsString('.it-grp::before', $css, 'the spine');
        $this->assertStringContainsString('--it-live', $css, 'the live page line');
        $js = $this->src('public/crm/js/ittai-shell.js');
        $this->assertStringContainsString("'--it-live'", $js);
        $this->assertStringContainsString('it-layer', $js, 'cards and flyouts are moved to <body>');
    }

    /**
     * LOCKED by Tim, 2026-11-10 ("lock that positioning"): the head card floats CENTRED on the rail line beside
     * the head's row, a spacer in the sidebar keeps its room so the pages move down beneath it, the row's portrait
     * hides while the card is open (one face), and the live page's line ends at the card's bottom edge.
     * Change this geometry only on Tim's word, and change this test in the same commit.
     */
    public function testHeadCardGeometryIsLocked(): void
    {
        $js = $this->src('public/crm/js/ittai-shell.js');
        $this->assertStringContainsString("sb0.right - 28 - w / 2", $js, 'card centred on the spine (28 px in from the sidebar edge)');
        $this->assertStringContainsString("sp.getBoundingClientRect().bottom - gr.top", $js, 'the live line ends at the spacer (card) bottom');
        $this->assertStringContainsString("sp.className = 'it-hp-space'", $js, 'a spacer keeps the card\'s room in the sidebar');
        $this->assertStringContainsString("layer.appendChild(c)", $js, 'the card floats on <body>, not inside the sidebar');
        $css = $this->src('public/crm/css/ittai-shell.css');
        $this->assertMatchesRegularExpression('/\.it-hp\s*\{[^}]*position:\s*fixed[^}]*width:\s*200px/s', $css, 'floating 200 px square card');
        $this->assertStringContainsString('.it-grp.has-card .it-grow .it-pt { visibility: hidden; }', $css, 'one face while the card is open');
        $this->assertStringContainsString('.it-grp > .it-hp-space { order: 1;', $css, 'the spacer sits between the row and the pages');
    }
}
