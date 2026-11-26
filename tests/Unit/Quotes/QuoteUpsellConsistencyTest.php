<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Codex review 2026-11-19: upsell changes must drop the cached PDF and remove must take the same lock as add. */
class QuoteUpsellConsistencyTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = (string)file_get_contents(__DIR__ . '/../../../public/customer/api/quote-upsell.php');
    }

    public function test_total_recalc_clears_cached_pdf(): void
    {
        $this->assertMatchesRegularExpression('/total_amount = \?,\s*pdf_path = NULL, pdf_version = 0, pdf_generated_at = NULL/', $this->src);
    }

    public function test_remove_upsell_takes_the_add_lock(): void
    {
        $pos = strpos($this->src, "\$action === 'remove-upsell'");
        $this->assertNotFalse($pos);
        $branch = substr($this->src, $pos, 900);
        $this->assertStringContainsString("'mw_quote_upsell_' . (int)\$quoteId", $branch);
        $this->assertStringContainsString('GET_LOCK', $branch);
    }
}
