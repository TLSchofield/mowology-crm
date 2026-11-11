<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailWrapper::renderPaymentInstructions() — the pure "How to Pay"
 * block renderer used in invoice emails (info from business_settings).
 */
class EmailWrapperTest extends TestCase
{
    public function test_empty_input_renders_nothing(): void
    {
        $this->assertSame('', EmailWrapper::renderPaymentInstructions(''));
        $this->assertSame('', EmailWrapper::renderPaymentInstructions('   '));
        $this->assertSame('', EmailWrapper::renderPaymentInstructions("\n\t "));
    }

    public function test_renders_how_to_pay_block(): void
    {
        $html = EmailWrapper::renderPaymentInstructions('Pay by e-Transfer to info@mowology.ca.');
        $this->assertStringContainsString('How to Pay', $html);
        $this->assertStringContainsString('info@mowology.ca', $html);
    }

    public function test_escapes_html_and_preserves_newlines(): void
    {
        $html = EmailWrapper::renderPaymentInstructions("Line 1 <b>x</b>\nLine 2");
        $this->assertStringContainsString('&lt;b&gt;', $html);   // escaped
        $this->assertStringNotContainsString('<b>x</b>', $html); // no raw injection
        $this->assertStringContainsString('<br', $html);          // newline → <br>
    }
}
