<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InvoiceNoteFormatterTest extends TestCase
{
    public function testReviewLinkBecomesAClickableLink(): void
    {
        $h = InvoiceNoteFormatter::html("Thanks, Susan. A review helps: https://g.page/r/CQx6tjUVhh6yEAI/review\n\nThanks, Tim");
        $this->assertStringContainsString('<a href="https://g.page/r/CQx6tjUVhh6yEAI/review" target="_blank" rel="noopener noreferrer">https://g.page/r/CQx6tjUVhh6yEAI/review</a>', $h);
        $this->assertStringContainsString('<br', $h);
    }

    public function testTrailingPunctuationStaysOutsideTheLink(): void
    {
        $h = InvoiceNoteFormatter::html('See (https://example.com/a).');
        $this->assertStringContainsString('<a href="https://example.com/a"', $h);
        $this->assertStringContainsString('</a>).', $h);
    }

    public function testMarkupInANoteIsEscapedNotRendered(): void
    {
        $h = InvoiceNoteFormatter::html('<script>alert(1)</script> <b>x</b> https://a.test/?q=1&r="2"');
        $this->assertStringNotContainsString('<script', $h);
        $this->assertStringNotContainsString('<b>', $h);
        $this->assertStringContainsString('&lt;script&gt;', $h);
        $this->assertStringContainsString('<a href="https://a.test/?q=1&amp;r=" ', $h);   // the link stops at the quote
        $this->assertStringContainsString('&quot;2&quot;', $h);
    }

    public function testOnlyHttpAndHttpsAreLinked(): void
    {
        $h = InvoiceNoteFormatter::html("javascript:alert(1) ftp://x.test mailto:a@b.test");
        $this->assertStringNotContainsString('<a ', $h);
    }

    public function testNoBreaksModeKeepsNewlines(): void
    {
        $this->assertSame("a\nb", InvoiceNoteFormatter::html("a\nb", false));
        $this->assertSame('', InvoiceNoteFormatter::html(null));
    }
}
