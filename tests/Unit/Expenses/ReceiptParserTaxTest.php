<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

/**
 * Tests for extractGST()/extractPST()'s tax-line matching.
 *
 * Bug this covers: a receipt phrasing tax lines as "GST Sales Tax (5%) $2.25"
 * / "PST Sales Tax (7%) $3.15" (rather than the terser "GST $2.25") matched
 * none of the old regexes — the "Sales Tax (5%)" wording between the label
 * and the amount broke every pattern. GST then silently fell back to a
 * blind "estimate 5% of total" guess (wrong whenever PST also applies), and
 * PST — which had no dedicated extractor at all, only a column-layout
 * fallback for a completely different receipt shape — stayed null/zero.
 * See extractLabeledTax() in app/Services/Receipts/ReceiptParser.php.
 */
class ReceiptParserTaxTest extends TestCase
{
    public function test_gst_and_pst_net_correctly_with_sales_tax_wording(): void
    {
        $text = "Subtotal \$44.97\nGST Sales Tax (5%) \$2.25\nPST Sales Tax (7%) \$3.15\nTotal \$50.37";

        $this->assertSame('2.25', extractGST($text));
        $this->assertSame('3.15', extractPST($text));
    }

    public function test_full_parse_of_the_southlands_nursery_receipt(): void
    {
        // Reproduces the actual production expense (Southlands Nursery,
        // 2026-07-31, $50.37 total) that previously came out as gst=2.40
        // (estimated) / pst=null (0.00) instead of the receipt's real 2.25/3.15.
        $ocrText = "Southlands Nursery\n6550 Balaclava street\nVANCOUVER, BC\nV6N1L9\n"
            . "GST/HST # 104943949\nQST # 103-2368\nPST # 103-2368\n\n"
            . "Topsoil x4\n30L Bag\nOriginal Price \$59.96\nDiscount: Landscapers (25%) -\$14.99\n\n"
            . "Subtotal \$44.97\nGST Sales Tax (5%) \$2.25\nPST Sales Tax (7%) \$3.15\n\n"
            . "Total \$50.37\nInterac 2976 (Contactless) \$50.37\n";

        $result = parseReceiptText($ocrText);

        $this->assertSame('2.25', $result['gst']);
        $this->assertSame('3.15', $result['pst']);
        $this->assertSame('44.97', $result['subtotal']);
        $this->assertSame('50.37', $result['total']);
        $this->assertArrayNotHasKey('gst_estimated', $result, 'GST was found directly, should not fall back to the 5%-of-total estimate');
    }

    public function test_plain_gst_amount_without_sales_tax_wording_still_works(): void
    {
        $this->assertSame('1.00', extractGST("Subtotal 20.00\nGST \$1.00\nTotal 21.00"));
    }

    public function test_gst_hst_split_line_still_works(): void
    {
        $this->assertSame('2.88', extractGST("GST/HST\n2.88"));
    }

    public function test_gst_three_line_rate_dollar_amount_still_works(): void
    {
        $this->assertSame('9.04', extractGST("GST 5%\n\$\n9.04"));
    }

    public function test_fuel_receipt_gst_included_still_works(): void
    {
        $this->assertSame('0.95', extractGST('GST INCLUDED $ 0.95'));
    }

    public function test_generic_tax_label_fallback_still_works(): void
    {
        $this->assertSame('3.45', extractGST('TAX $3.45'));
    }

    public function test_generic_tax_label_does_not_preempt_a_real_gst_match(): void
    {
        // A receipt with both a real GST line and unrelated "tax" wording
        // elsewhere should still use the GST-labeled amount, not a stray
        // "TAX" match.
        $this->assertSame('2.25', extractGST("GST \$2.25\nAll prices include applicable taxes."));
    }

    public function test_pst_split_line_and_rst_alias(): void
    {
        $this->assertSame('3.15', extractPST("PST\n3.15"));
        $this->assertSame('3.15', extractPST('RST $3.15'));
    }

    public function test_no_tax_present_returns_null_for_both(): void
    {
        $this->assertNull(extractGST("Subtotal 20.00\nTotal 20.00"));
        $this->assertNull(extractPST("Subtotal 20.00\nTotal 20.00"));
    }

    public function test_gst_hst_and_pst_registration_numbers_are_not_mistaken_for_amounts(): void
    {
        // "GST/HST # 104943949" and "PST # 103-2368" have no decimal amount
        // on the line — must not be misread as a tax total.
        $this->assertNull(extractGST("GST/HST # 104943949"));
        $this->assertNull(extractPST("PST # 103-2368"));
    }
}
