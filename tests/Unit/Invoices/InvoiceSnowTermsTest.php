<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * InvoiceSnowTerms — the signed snow terms on a salt/snow invoice, and the plain-words
 * line item. Rule under test: never claim a signing date we cannot prove.
 */
class InvoiceSnowTermsTest extends TestCase
{
    public function test_no_terms_renders_nothing(): void
    {
        $this->assertSame('', InvoiceSnowTerms::html(null));
    }

    public function test_block_shows_wording_signing_date_and_acceptance_line(): void
    {
        $html = InvoiceSnowTerms::html([
            'body' => "SCOPE\nCar park <b>only</b>", 'snapshot' => true,
            'signed_at' => '2026-10-02 14:05:00', 'template_version' => 1,
        ]);
        $this->assertStringContainsString('SNOW &amp; ICE SERVICE TERMS', $html);
        $this->assertStringContainsString('signed October 2, 2026', $html);
        $this->assertStringContainsString('Car park &lt;b&gt;only&lt;/b&gt;', $html);   // escaped, not injected
        // the acceptance line lives in the payment panel (and the customer page), not in this block
        $this->assertSame('Payment of this invoice confirms acceptance of these terms.', InvoiceSnowTerms::ACCEPTANCE);
    }

    public function test_unproven_signing_date_is_never_claimed(): void
    {
        $html = InvoiceSnowTerms::html(['body' => 'TERM', 'snapshot' => false, 'signed_at' => null, 'template_version' => null]);
        $this->assertStringNotContainsString('signed', $html);
        $this->assertStringContainsString('snow &amp; ice service agreement', $html);
    }

    public function test_work_is_named_and_date_shown_once(): void
    {
        $this->assertSame(['Salt application', 'Thu, Nov 19, 2026'],
            InvoiceSnowTerms::workAndDay('Salt Only Service - Thu Nov 19, 2026'));
        $this->assertSame(['Salt application', 'Thu, Nov 19, 2026'],
            InvoiceSnowTerms::workAndDay('Salt application', '2026-11-19'));
        $this->assertSame(['Arctic salt application', 'Fri, Nov 20, 2026'],
            InvoiceSnowTerms::workAndDay('Sub -5 Degrees Arctic Freeze Daily Salt Only Service - Fri Nov 20, 2026'));
        $this->assertSame(['Snow clearing', ''], InvoiceSnowTerms::workAndDay('Snow Removal'));
        // extra wording is never swallowed
        $this->assertSame(['Salt and hand-shovel front stairs', 'Thu, Nov 19, 2026'],
            InvoiceSnowTerms::workAndDay('Salt and hand-shovel front stairs - Thu Nov 19, 2026'));
        $this->assertSame(['Snow removal and salting', ''], InvoiceSnowTerms::workAndDay('Snow removal and salting'));
        $this->assertSame(['Hedge trim', ''], InvoiceSnowTerms::workAndDay('Hedge trim'));
    }

    public function test_non_snow_invoice_has_no_terms(): void
    {
        $db = new PDO('sqlite::memory:');
        $this->assertFalse(InvoiceSnowTerms::isSnowInvoice($db, ['plan_id' => 0]));
        $this->assertNull(InvoiceSnowTerms::forInvoice($db, ['plan_id' => 0]));
        // plan lookup fails (no tables) → treated as not snow, never throws
        $this->assertNull(InvoiceSnowTerms::forInvoice($db, ['plan_id' => 5]));
    }

    public function test_columns_split_between_paragraphs_and_lose_no_text(): void
    {
        $body = "TERM\n\nOne two three.\n\nSCOPE\n\nFour five six seven.\n\nLIMITS\n\nEight nine.";
        foreach ([2, 3] as $n) {
            $cols = InvoiceSnowTerms::columns($body, $n);
            $this->assertCount($n, $cols);
            $this->assertSame($body, implode("\n\n", $cols));
        }
        $this->assertSame(['One paragraph only'], InvoiceSnowTerms::columns('One paragraph only', 3));
        $this->assertSame(['one a', 'two b'], InvoiceSnowTerms::columns("one a\n\ntwo b", 3)); // never an empty column
    }
}
