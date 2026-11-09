<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Static guards for the Mia workspace page (Phase 1.13): safe DOM use, design-system rules, wiring. */
class MiaWorkspacePageTest extends TestCase
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

    private function css(): string
    {
        $c = $this->src('public/crm/css/mowology-brand.css');
        $i = strpos($c, 'Mia: Search & Website workspace');
        $this->assertNotFalse($i, 'workspace CSS block is in mowology-brand.css');
        $j = strpos($c, 'Ittai Design System v1.2 (Kanso) on the Mia flow pages');
        $this->assertNotFalse($j, 'the Ittai block follows the workspace block');
        return substr($c, $i, $j - $i);
    }

    /** The Ittai v1.2 block that restyles the Mia workspace and the Portfolio project page. */
    private function ittaiCss(): string
    {
        $c = $this->src('public/crm/css/mowology-brand.css');
        $j = strpos($c, 'Ittai Design System v1.2 (Kanso) on the Mia flow pages');
        $this->assertNotFalse($j);
        $rest = substr($c, $j);
        return substr($rest, strpos($rest, '*/') + 2);                      // the rules after the block's own header comment
    }

    public function testScriptNeverTurnsServerTextIntoMarkup(): void
    {
        $js = preg_replace('#^\s*//[^\n]*$#m', '', (string)preg_replace('#/\*.*?\*/#s', '', $this->src('public/crm/js/mia-workspace.js')));      // code only, not the comments
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'srcdoc'] as $bad) {
            $this->assertStringNotContainsString($bad, $js, $bad);
        }
        $this->assertStringContainsString('textContent', $js);
        $this->assertStringContainsString("'use strict'", $js);
    }

    public function testScriptSendsCsrfOnWritesAndTheSeenHashOnApproval(): void
    {
        $js = $this->src('public/crm/js/mia-workspace.js');
        $this->assertStringContainsString('csrf_token: csrf()', $js);
        $this->assertStringContainsString('seen_hash: it.seen_hash', $js);
        $this->assertStringContainsString("'same-origin'", $js);
        $this->assertStringContainsString("res.status === 409 && res.body.code === 'changed'", $js, 'a stale approval re-shows the wording instead of retrying blindly');
        $this->assertStringNotContainsString('localStorage', $js, 'no state that must be reliable lives in the browser');
    }

    public function testPageIsAThinShellBehindLoginAndPermission(): void
    {
        $php = $this->src('public/crm/marketing/mia.php');
        $this->assertStringContainsString('requireLogin();', $php);
        $this->assertStringContainsString("requirePermission('marketing.approve');", $php);
        $this->assertLessThan(strpos($php, 'appstack_head.php'), strpos($php, "requirePermission('marketing.approve')"), 'the gate runs before any output');
        $this->assertStringContainsString("\$activePage = 'mia';", $php);
        $this->assertStringContainsString('appstack_footer.php', $php);
        foreach (['<style', ' style=', 'getDB(', 'SELECT ', 'INSERT '] as $bad) { $this->assertStringNotContainsString($bad, $php, $bad); }
        foreach (['<!DOCTYPE', '<html', '<body'] as $bad) { $this->assertStringNotContainsString($bad, $php, $bad . ' comes from appstack_head.php'); }
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $php), 'no inline script');
    }


    public function testEveryClassTheScriptAndPageUseHasStyles(): void
    {
        $used = [];
        foreach (['public/crm/js/mia-workspace.js', 'public/crm/marketing/mia.php'] as $f) {
            preg_match_all('/mw-mws[a-z0-9-]*/', $this->src($f), $m);
            $used = array_merge($used, $m[0]);
        }
        $css = $this->css() . $this->ittaiCss();                                     // a class may be styled in the workspace block or the Ittai block
        $missing = [];
        foreach (array_unique($used) as $cls) {
            if ($cls === 'mw-mws' || strpos($css, $cls) !== false) { continue; }
            $missing[] = $cls;
        }
        $this->assertSame([], $missing, 'classes used but never styled');
    }

    public function testNoClassClashWithMiasDashboardCard(): void
    {
        $all = $this->src('public/crm/css/mowology-brand.css');
        $workspace = $this->css();
        $before = substr($all, 0, strpos($all, 'Mia: Search & Website workspace'));
        preg_match_all('/\.(mw-mws[a-z0-9-]*)/', $before, $m);
        $this->assertSame([], array_unique($m[1]), 'the prefix was free before this block');
        $this->assertStringNotContainsString('.mw-mia-', $workspace);
    }

    public function testPhoneFriendlyBasics(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('min-height: var(--mw-space-12)', $css, '48px touch targets');
        $this->assertStringContainsString('@media (max-width: 575.98px)', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $js = $this->src('public/crm/js/mia-workspace.js');
        $this->assertStringContainsString("role: 'group'", $js);
        $this->assertStringContainsString("'aria-expanded'", $js);
    }

    public function testNavigationEntryIsPermissionGated(): void
    {
        $nav = $this->src('public/crm/includes/nav-items.php');
        $this->assertSame(1, preg_match("/\\['key' => 'mia',[^\\n]*'href' => '\\/crm\\/marketing\\/mia\\.php',\\s*'perm' => 'marketing\\.approve'\\]/", $nav));
        $this->assertFileExists($this->repo . '/public/crm/marketing/mia.php');
    }

    public function testEveryModeTheScriptCallsIsServedByTheHandler(): void
    {
        $js = $this->src('public/crm/js/mia-workspace.js');
        preg_match_all("/request\\('(GET|POST)', '([a-z_]+)'/", $js, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as $call) {
            $list = $call[1] === 'GET' ? MiaApiHandler::MODES_GET : MiaApiHandler::MODES_POST;
            $this->assertContains($call[2], $list, "{$call[1]} {$call[2]}");
        }
    }


    public function testThePortfolioPageUsesTheScopedClassesAndKeepsItsSafeguards(): void
    {
        $v = $this->src('public/crm/portfolio/view.php');
        $this->assertStringContainsString('class="it-pf"', $v);
        foreach (['<style', ' style='] as $bad) { $this->assertStringNotContainsString($bad, $v, $bad . ' (AppStack pages take no inline styling)'); }
        $this->assertStringContainsString('htmlspecialchars($project[\'project_name\'])', $v);
        $this->assertStringContainsString('function deleteProject', $v);
        $this->assertStringContainsString('function setProjectStatus', $v);
        $this->assertStringContainsString('class="mw-mws it-mws"', $this->src('public/crm/marketing/mia.php'));
    }
}
