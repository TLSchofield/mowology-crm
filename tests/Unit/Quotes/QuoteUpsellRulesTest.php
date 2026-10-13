<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Quotes/Services/QuoteUpsellRules.php';

/** Fable review 2026-10-12: the client can add only an offered upsell, and only before approval. */
class QuoteUpsellRulesTest extends TestCase
{
    public function test_adds_only_before_approval(): void
    {
        $this->assertTrue(QuoteUpsellRules::addAllowed('sent'));
        $this->assertFalse(QuoteUpsellRules::addAllowed('accepted'));
        $this->assertFalse(QuoteUpsellRules::addAllowed('accepted', true));
        $this->assertFalse(QuoteUpsellRules::addAllowed('declined'));
        $this->assertFalse(QuoteUpsellRules::offerCards('accepted'));
    }

    public function test_only_offered_items_match(): void
    {
        $offers = [
            ['upsell_product_id' => 12, 'upsell_bundle_id' => null, 'bundled_price' => '40.00'],
            ['upsell_product_id' => null, 'upsell_bundle_id' => 7, 'bundled_price' => null],
        ];
        $this->assertSame('40.00', QuoteUpsellRules::matchOffer($offers, 12, 0)['bundled_price']);
        $this->assertNotNull(QuoteUpsellRules::matchOffer($offers, 0, 7));
        $this->assertNull(QuoteUpsellRules::matchOffer($offers, 99, 0), 'any active product used to be accepted');
        $this->assertNull(QuoteUpsellRules::matchOffer($offers, 0, 8));
        $this->assertNull(QuoteUpsellRules::matchOffer([], 12, 0));
    }

    public function test_endpoint_checks_offer_and_status_before_adding(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../public/customer/api/quote-upsell.php');
        $add = substr($src, strpos($src, "elseif (\$action === 'add-upsell')"));
        $this->assertLessThan(strpos($add, 'INSERT INTO quote_line_items'), strpos($add, 'QuoteUpsellRules::matchOffer('));
        $this->assertLessThan(strpos($add, 'INSERT INTO quote_line_items'), strpos($add, 'QuoteUpsellRules::addAllowed('));
        $this->assertStringContainsString('$upsellMeta = $offer;', $add);
    }
}
