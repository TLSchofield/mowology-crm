<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: CMS block HTML is allow-listed before it reaches the public site. */
class CmsHtmlSanitizerTest extends TestCase
{
    public function test_formatting_survives(): void
    {
        $in = '<h2>Fall cleanup</h2><p>We <strong>rake</strong> and <a href="/services/fall-cleanup">haul</a>.</p><ul><li>One</li></ul>';
        $this->assertSame($in, CmsHtmlSanitizer::clean($in));
    }

    public function test_scripts_handlers_and_js_urls_are_removed(): void
    {
        $out = CmsHtmlSanitizer::clean('<p onclick="x()">Hi<script>alert(1)</script></p><img src="x" onerror="alert(1)"><a href=" JaVaScript:alert(1)">go</a><iframe src="https://evil"></iframe><style>body{}</style>');
        $this->assertStringNotContainsStringIgnoringCase('script', $out);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $out);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $out);
        $this->assertStringNotContainsStringIgnoringCase('iframe', $out);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $out);
        $this->assertStringContainsString('<p>Hi</p>', $out);
        $this->assertStringContainsString('<a>go</a>', $out);
    }

    public function test_unknown_tags_are_unwrapped_and_entities_kept(): void
    {
        $this->assertSame('<p>Tom &amp; Jerry <em>x</em></p>', CmsHtmlSanitizer::clean('<p>Tom &amp; Jerry <blink><em>x</em></blink></p>'));
        $this->assertSame('no tags here', CmsHtmlSanitizer::clean('no tags here'));
    }

    public function test_blank_target_gets_noopener_and_tokens_survive(): void
    {
        $out = CmsHtmlSanitizer::clean('<a href="{{cta_url}}" target="_blank">Book</a>');
        $this->assertStringContainsString('href="{{cta_url}}"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function test_config_is_cleaned_recursively(): void
    {
        $c = CmsHtmlSanitizer::cleanConfig(['content' => '<p>a<script>b</script></p>', 'items' => [['body' => '<img src=x onerror=y>']], 'cta_url' => 'javascript:alert(1)', 'title' => 'Plain']);
        $this->assertSame('<p>a</p>', $c['content']);
        $this->assertSame('<img src="x">', $c['items'][0]['body']);
        $this->assertSame('', $c['cta_url']);
        $this->assertSame('Plain', $c['title']);
    }
}
