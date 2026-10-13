<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fable #7 (2026-10-12): CMS URL fields (cta_url, primary_url, services[].url, image…) go through
 * safeUrl() on save and on render, whatever they contain. "java\tscript:" used to pass.
 */
class CmsUrlFieldSafetyTest extends TestCase
{
    public static function badUrls(): array
    {
        return [
            'tab'            => ["java\tscript:alert(1)"],
            'newline'        => ["java\nscript:alert(1)"],
            'mixed case'     => ['JaVaScRiPt:alert(1)'],
            'leading space'  => ['  javascript:alert(1)'],
            'entity'         => ['&#106;avascript:alert(1)'],
            'entity no semi' => ['&#106avascript:alert(1)'],
            'hex entity'     => ['&#x6A;avascript:alert(1)'],
            'named colon'    => ['javascript&colon;alert(1)'],
            'named tab'      => ['java&Tab;script:alert(1)'],
            'double encoded' => ['&amp;#106;avascript:alert(1)'],
            'data html'      => ['data:text/html,<script>alert(1)</script>'],
            'vbscript'       => ['vbscript:msgbox(1)'],
            'nul byte'       => ["java\0script:alert(1)"],
        ];
    }

    /** @dataProvider badUrls */
    public function test_safe_url_refuses(string $url): void
    {
        $this->assertNull(CmsHtmlSanitizer::safeUrl($url));
        $this->assertSame('#', CmsHtmlSanitizer::href($url));
    }

    public static function goodUrls(): array
    {
        return [['/contact'], ['/quote?service=lawn-care&src=pricing'], ['https://mowology.ca/services'],
                ['tel:7788469273'], ['mailto:office@mowology.ca'], ['#quote'], ['{{quote_url}}'],
                ['/jobFlow/jobFlow-getQuote.php'], ['/book?time=10:30'], ['']];
    }

    /** @dataProvider goodUrls */
    public function test_safe_url_keeps_real_links(string $url): void
    {
        $this->assertSame($url, CmsHtmlSanitizer::safeUrl($url));
    }

    /** @dataProvider badUrls */
    public function test_clean_config_sanitises_every_url_key_whatever_it_contains(string $url): void
    {
        $c = CmsHtmlSanitizer::cleanConfig([
            'cta_url' => $url, 'primary_url' => $url, 'link' => $url, 'image' => $url,
            'services' => [['url' => $url, 'title' => 'Lawn']],
            'cta_text' => 'Get a quote', 'link_text' => 'Note: read me', 'image_alt' => 'Photo: lawn',
        ]);
        $this->assertSame('', $c['cta_url']);
        $this->assertSame('', $c['primary_url']);
        $this->assertSame('', $c['link']);
        $this->assertSame('', $c['image']);
        $this->assertSame('', $c['services'][0]['url']);
        $this->assertSame('Lawn', $c['services'][0]['title']);
        $this->assertSame('Note: read me', $c['link_text']);
        $this->assertSame('Photo: lawn', $c['image_alt']);
    }

    public function test_render_pass_turns_bad_links_into_hash_and_leaves_text(): void
    {
        $c = CmsHtmlSanitizer::cleanConfigUrls(['cta_url' => "java\tscript:x", 'secondary_url' => 'tel:7788469273', 'headline' => 'javascript: is not a link here']);
        $this->assertSame('#', $c['cta_url']);
        $this->assertSame('tel:7788469273', $c['secondary_url']);
        $this->assertSame('javascript: is not a link here', $c['headline']);
    }

    public function test_section_renderers_never_emit_a_script_href(): void
    {
        require_once APP_ROOT . '/Modules/CMS/Services/CmsSectionFunctions.php';
        foreach (['hero', 'cta_banner', 'image_text'] as $type) {
            $html = cms_renderSection(['block_type' => $type, 'data' => [
                'headline' => 'H', 'cta_text' => 'Go', 'cta_url' => "java\tscript:alert(1)",
                'image_url' => 'data:text/html,x', 'body' => '<p onclick="x()">B</p>',
            ]]);
            $this->assertStringNotContainsStringIgnoringCase('script:', $html, $type);
            $this->assertStringNotContainsString('data:text', $html, $type);
            $this->assertStringNotContainsString('onclick', $html, $type);
        }
    }

    public function test_block_dispatcher_and_previews_run_the_url_pass(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['public/crm/includes/cms-renderer.php', 'public/crm/cms/snippet-preview.php', 'public/crm/cms/cms-snippets.php'] as $f) {
            $this->assertStringContainsString('CmsHtmlSanitizer::cleanConfigUrls', (string)file_get_contents($root . '/' . $f), $f);
        }
        $sec = (string)file_get_contents($root . '/app/Modules/CMS/Services/CmsSectionFunctions.php');
        $this->assertMatchesRegularExpression('/function cms_updateSection[^{]*\{\s*require_once[^;]+;\s*\$data = CmsHtmlSanitizer::cleanConfig/', $sec);
        $this->assertMatchesRegularExpression('/function cms_createSection[^{]*\{[^}]*CmsHtmlSanitizer::cleanConfig/', $sec);
    }
}
