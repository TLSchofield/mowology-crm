<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/Csp.php';

/**
 * Fable #22 (2026-10-12): public site + customer portal run under a nonce CSP with no
 * 'unsafe-inline'/'unsafe-eval' for scripts. Every inline <script> carries the nonce, no inline
 * event handlers or javascript: URLs, no unpkg. (scripts/csp-check.py checks rendered pages.)
 */
class PublicCspTest extends TestCase
{
    private static function root(): string { return dirname(__DIR__, 3) . '/public'; }

    /** Files whose output lands on a nonce-CSP page. */
    private static function surfaces(): array
    {
        $r = self::root();
        $files = array_merge(
            glob($r . '/*.php'), glob($r . '/includes/*.php'), glob($r . '/layouts/*.php'),
            glob($r . '/services/*.php'), glob($r . '/crm/includes/blocks/*.php'),
            [$r . '/crm/includes/cms-renderer.php'], glob($r . '/customer/*.php')
        );
        // Not converted (own HTML, no head.php; keep the .htaccess policy): excluded by .htaccess too.
        $skip = ['forgot-password.php', 'reset-password.php', 'unsubscribe.php', 'optin-confirm.php', 'quote.php', 'sitemap.php', 'blog-feed.php', 'service-worker.php'];
        return array_values(array_filter($files, static function ($f) use ($r, $skip) {
            return !(dirname($f) === $r && in_array(basename($f), $skip, true));
        }));
    }

    public function test_policy_has_nonce_and_no_unsafe_script_sources(): void
    {
        $p = MwCsp::policy('abc');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-abc'/", $p);
        preg_match('/script-src ([^;]*)/', $p, $m);
        $this->assertStringNotContainsString('unsafe', $m[1]);
        $this->assertStringNotContainsString('unpkg', $p);
        $this->assertStringContainsString("script-src-attr 'none'", $p);
        $this->assertStringContainsString("object-src 'none'", $p);
        $this->assertSame(MwCsp::nonce(), MwCsp::nonce());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20,}$/', MwCsp::nonce());
    }

    public function test_every_inline_script_has_the_nonce_and_no_inline_handlers(): void
    {
        foreach (self::surfaces() as $f) {
            $src = (string)file_get_contents($f);
            $name = substr($f, strlen(self::root()) + 1);
            preg_match_all('/<script\b(?!<\?=)([^>]*)>/i', $src, $m, PREG_SET_ORDER);
            foreach ($m as $tag) {
                $attrs = $tag[1];
                if (stripos($attrs, 'src=') !== false || preg_match('/application\/(ld\+)?json/i', $attrs)) continue;
                $this->fail("$name: inline <script{$attrs}> without MwCsp::attr()");
            }
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son(click|change|submit|load|error|input|key\w+|focus|blur|mouse\w+|touch\w+)\s*=/i', $src, "$name: inline event handler");
            $this->assertDoesNotMatchRegularExpression('/["\']\s*on(click|change|submit|error)\s*=\s*\\\\?["\']/i', $src, "$name: inline handler built in a JS string");
            $this->assertStringNotContainsStringIgnoringCase('href="javascript:', $src, $name);
            $this->assertStringNotContainsString('unpkg.com', $src, $name);
        }
    }

    public function test_pages_send_the_policy(): void
    {
        $head = (string)file_get_contents(self::root() . '/includes/head.php');
        $this->assertStringContainsString('MwCsp::send()', $head);
        foreach (glob(self::root() . '/customer/*.php') as $f) {
            $this->assertStringContainsString('MwCsp::send()', (string)file_get_contents($f), basename($f));
        }
        $ht = (string)file_get_contents(self::root() . '/.htaccess');
        $this->assertMatchesRegularExpression('/Header always set Content-Security-Policy "[^"]+" env=!MW_NONCE_CSP/', $ht);
        $this->assertStringContainsString('SetEnvIf Request_URI "^/customer/" MW_NONCE_CSP=1', $ht);
    }

    public function test_vendored_assets_ship_with_licences(): void
    {
        foreach (['leaflet/leaflet.min.js', 'leaflet/leaflet.min.css', 'leaflet/LICENSE', 'signature_pad/signature_pad.umd.min.js', 'signature_pad/LICENSE'] as $f) {
            $this->assertFileExists(self::root() . '/assets/vendor/' . $f);
        }
    }
}
