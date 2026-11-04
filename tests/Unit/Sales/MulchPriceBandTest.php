<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's three prices for a yard of mulch: the floor, what Tim was paid, what the market charges (2026-11-03).
 * Figures are the real ones: cost $86, floor $132, accepted $175 twice, My Garden Bag $208 a yard.
 */
class MulchPriceBandTest extends TestCase
{
    private function in(array $over = []): array
    {
        return $over + [
            'floor' => ['sell' => 132.0, 'cost' => 86.0, 'margin_pct' => 35.0],
            'yards_per_load' => 2.0,
            'product_name' => 'Black Composted Bark Mulch',
            'yard_names' => ['black composted bark mulch', 'cedar mulch'],
            'catalogue' => ['black composted bark mulch' => 175.0, 'cedar mulch' => 65.0],
            'quotes' => [
                ['quote' => 'QUO-1', 'status' => 'accepted', 'date' => '2026-10-06', 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 175.0],
                ['quote' => 'QUO-2', 'status' => 'accepted', 'date' => '2026-03-24', 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 175.0],
                ['quote' => 'QUO-3', 'status' => 'accepted', 'date' => '2026-06-18', 'service_type' => 'Mulching', 'unit_type' => 'each', 'unit_price' => 250.0],
                ['quote' => 'QUO-4', 'status' => 'accepted', 'date' => '2026-02-17', 'service_type' => 'Cedar Mulch', 'unit_type' => '', 'unit_price' => 65.0],
            ],
            'market' => [
                ['source' => 'My Garden Bag', 'what' => 'Delivered in a reusable bag, not spread.', 'product' => 'Bark Mulch (Composted)', 'sizes' => ['1' => 208.0, '0.5' => 178.0, '0.25' => 158.0], 'seen_on' => '2026-10-19'],
                ['source' => 'My Garden Bag', 'what' => 'Delivered in a reusable bag, not spread.', 'product' => 'Playground Woodchips', 'sizes' => ['1' => 210.0], 'seen_on' => '2026-10-19'],
            ],
            'market_read' => '2026-11-02', 'own_rate' => 0.0, 'today' => '2026-11-03',
        ];
    }

    public function test_the_floor_says_what_it_is_made_of(): void
    {
        $b = MulchPriceBand::build($this->in());
        $this->assertSame(132.0, $b['floor']['price']);
        $this->assertStringContainsString('cost is $86 a yard', $b['floor']['text']);
        $this->assertStringContainsString('35%', $b['floor']['text']);
    }

    public function test_a_missing_floor_is_not_a_zero_price(): void
    {
        $b = MulchPriceBand::build($this->in(['floor' => null]));
        $this->assertNull($b['floor']['price']);
        $this->assertStringContainsString("can't work out the floor", $b['floor']['text']);
    }

    public function test_paid_uses_this_products_accepted_per_yard_lines_only(): void
    {
        $p = MulchPriceBand::build($this->in())['paid'];
        $this->assertSame(175.0, $p['price']);
        $this->assertSame(2, $p['won'], 'the $250 flat job and the $65 cedar are not this product at a per-yard price');
        $this->assertStringContainsString('2 accepted quotes (latest Oct 6, QUO-1)', $p['text']);
    }

    public function test_margin_and_profit_at_the_paid_price(): void
    {
        $t = MulchPriceBand::build($this->in())['paid']['text'];
        $this->assertStringContainsString('$89 a yard, 51% margin, $178 on a 2-yard trip', $t);
    }

    public function test_two_accepted_quotes_are_not_a_ceiling(): void
    {
        $this->assertStringContainsString('too few quotes to call it your ceiling', MulchPriceBand::build($this->in())['paid']['text']);
    }

    public function test_lost_quotes_are_counted_and_change_the_reading(): void
    {
        $in = $this->in();
        for ($i = 0; $i < 2; $i++) $in['quotes'][] = ['quote' => 'Q' . $i, 'status' => 'accepted', 'date' => '2026-01-0' . ($i + 1), 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 175.0];
        $in['quotes'][] = ['quote' => 'QL', 'status' => 'declined', 'date' => '2026-01-09', 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 190.0];
        $p = MulchPriceBand::build($in)['paid'];
        $this->assertSame(1, $p['lost']);
        $this->assertStringContainsString('1 declined', $p['text']);
        $this->assertStringContainsString('may be near the limit', $p['text']);
    }

    public function test_no_accepted_quote_says_so_and_is_not_zero(): void
    {
        $in = $this->in(['quotes' => []]);
        $b = MulchPriceBand::build($in);
        $this->assertNull($b['paid']['price']);
        $this->assertNull($b['reading'], 'no reading without a paid price');
    }

    public function test_the_market_price_is_the_one_yard_bag_and_not_playground_chips(): void
    {
        $m = MulchPriceBand::build($this->in())['market'];
        $this->assertSame(208.0, $m['price']);
        $this->assertStringContainsString('My Garden Bag charges $208 a yard for Bark Mulch (Composted)', $m['text']);
        $this->assertStringContainsString('pays that and spreads it himself', $m['text']);
        $this->assertStringContainsString('Half-yard bags work out at $356 a yard', $m['text']);
        $this->assertStringContainsString('GST is not stated', $m['text']);
        $this->assertFalse($m['stale']);
    }

    public function test_an_old_market_read_is_flagged_and_an_unknown_one_too(): void
    {
        $old = MulchPriceBand::build($this->in(['market_read' => '2026-10-01']))['market'];
        $this->assertTrue($old['stale']);
        $this->assertStringContainsString('Out of date: last read Oct 1', $old['text']);
        $none = MulchPriceBand::build($this->in(['market_read' => null]))['market'];
        $this->assertTrue($none['stale']);
        $this->assertStringContainsString('last read is unknown', str_replace('When it was last read is unknown', 'last read is unknown', $none['text']));
    }

    public function test_no_market_rows_is_said_not_zero(): void
    {
        $m = MulchPriceBand::build($this->in(['market' => []]))['market'];
        $this->assertNull($m['price']);
        $this->assertStringContainsString('no market price', $m['text']);
    }

    public function test_bag_sizes_under_a_yard_are_never_the_headline(): void
    {
        $in = $this->in(['market' => [['source' => 'S', 'what' => '', 'product' => 'Bark Mulch', 'sizes' => ['0.5' => 178.0, '0.25' => 158.0], 'seen_on' => '2026-10-19']]]);
        $this->assertNull(MulchPriceBand::build($in)['market']['price']);
    }

    public function test_the_reading_puts_the_paid_price_against_the_other_two_and_never_names_a_price_to_charge(): void
    {
        $r = (string)MulchPriceBand::build($this->in())['reading'];
        $this->assertStringContainsString('$43 above your floor', $r);
        $this->assertStringContainsString("$33 below My Garden Bag's delivered, unspread price", $r);
        $this->assertStringContainsString('the call is yours', $r);
        $this->assertDoesNotMatchRegularExpression('/\b(charge|should)\b.{0,15}\$\d/i', $r);
    }

    public function test_a_price_below_the_floor_is_called_out(): void
    {
        $in = $this->in(['floor' => ['sell' => 190.0, 'cost' => 123.0, 'margin_pct' => 35.0]]);
        $this->assertStringContainsString('$15 BELOW your floor', (string)MulchPriceBand::build($in)['reading']);
    }

    public function test_the_own_rate_is_a_fourth_line_only_when_set(): void
    {
        $this->assertNull(MulchPriceBand::build($this->in())['own']);
        $this->assertNotEmpty(MulchPriceBand::build($this->in())['notes']);
        $b = MulchPriceBand::build($this->in(['own_rate' => 230.0]));
        $this->assertSame(230.0, $b['own']['price']);
        $this->assertStringContainsString('$55 below what you say others charge installed', (string)$b['reading']);
        $this->assertSame([], $b['notes']);
    }

    public function test_a_flat_job_under_the_products_name_is_not_a_per_yard_price(): void
    {
        $in = $this->in();
        $in['quotes'][] = ['quote' => 'FLAT', 'status' => 'accepted', 'date' => '2026-11-01', 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 900.0];
        $p = MulchPriceBand::build($in)['paid'];
        $this->assertSame(175.0, $p['price'], 'the newest line is a $900 flat job and must not be "latest"');
        $this->assertSame(2, $p['won']);
        $this->assertStringContainsString('1 line priced far above the catalogue price was left out as a flat job', $p['text']);
    }

    public function test_expired_quotes_are_not_lost_quotes(): void
    {
        $in = $this->in();
        foreach (['E1', 'E2', 'E3'] as $q) $in['quotes'][] = ['quote' => $q, 'status' => 'expired', 'date' => '2026-01-01', 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 175.0];
        $p = MulchPriceBand::build($in)['paid'];
        $this->assertSame(0, $p['lost']);
        $this->assertSame(3, $p['expired']);
        $this->assertStringContainsString('none declined, 3 expired unanswered', $p['text']);
    }

    public function test_the_market_row_sharing_most_of_the_products_words_wins_over_a_cheaper_partial_match(): void
    {
        $in = $this->in(['product_name' => 'Cedar Bark Mulch', 'market' => [
            ['source' => 'S', 'what' => '', 'product' => 'Composted Bark Mulch', 'sizes' => ['1' => 80.0], 'seen_on' => '2026-10-19'],
            ['source' => 'S', 'what' => '', 'product' => 'Cedar Bark Mulch', 'sizes' => ['1' => 140.0], 'seen_on' => '2026-10-19'],
        ], 'market_read' => ['S' => '2026-11-02']]);
        $this->assertSame(140.0, MulchPriceBand::build($in)['market']['price']);
    }

    public function test_freshness_is_the_selected_sources_own_last_read(): void
    {
        $in = $this->in(['market_read' => ['My Garden Bag' => '2026-09-01', 'Another Source' => '2026-11-03']]);
        $m = MulchPriceBand::build($in)['market'];
        $this->assertTrue($m['stale'], "another source's fresh read must not make this price look current");
        $this->assertStringContainsString('last read Sep 1', $m['text']);
    }

    public function test_two_lines_on_one_quote_are_one_quote(): void
    {
        $in = $this->in();
        $in['quotes'][] = ['quote' => 'QUO-1', 'status' => 'accepted', 'date' => '2026-10-06', 'service_type' => 'Black Composted Bark Mulch', 'unit_type' => '', 'unit_price' => 175.0];
        $p = MulchPriceBand::build($in)['paid'];
        $this->assertSame(2, $p['won']);
        $this->assertStringContainsString('latest Oct 6, QUO-1', $p['text']);
    }

    public function test_a_product_with_no_comparable_market_row_gets_no_market_price(): void
    {
        $in = $this->in(['product_name' => 'Cedar Mulch', 'market' => [['source' => 'S', 'what' => '', 'product' => 'Composted Bark', 'sizes' => ['1' => 80.0], 'seen_on' => '2026-10-19']]]);
        $m = MulchPriceBand::build($in)['market'];
        $this->assertNull($m['price']);
        $this->assertStringContainsString('no published price for a product like this one', $m['text']);
    }

    public function test_an_out_of_date_market_price_is_not_evidence_of_room(): void
    {
        $r = (string)MulchPriceBand::build($this->in(['market_read' => '2026-08-01']))['reading'];
        $this->assertStringContainsString('out of date', $r);
        $this->assertStringNotContainsString('room above', $r);
    }

    public function test_an_unknown_cost_is_not_shown_as_zero(): void
    {
        $f = MulchPriceBand::build($this->in(['floor' => ['sell' => 150.0, 'margin_pct' => 35.0]]))['floor'];
        $this->assertStringNotContainsString('$0', $f['text']);
        $this->assertStringContainsString('cost behind it is not known', $f['text']);
        $this->assertNull($f['cost']);
    }

    public function test_with_no_product_named_the_one_actually_sold_most_is_used_not_the_cheapest_or_first(): void
    {
        $in = $this->in(['product_name' => null]);
        $b = MulchPriceBand::build($in);
        $this->assertSame('Black Composted Bark Mulch', $b['paid']['product']);
        $this->assertSame(175.0, $b['paid']['price']);
        $this->assertSame(208.0, $b['market']['price'], 'compared with the composted bark product, not cedar');
        $this->assertStringStartsWith('For Black Composted Bark Mulch: ', $b['paid']['text']);
    }

    public function test_a_negative_margin_reads_as_a_minus_not_a_dollar_minus(): void
    {
        $this->assertSame('-$21.31', MulchPriceBand::money(-21.31));
        $this->assertSame('$175', MulchPriceBand::money(175.0));
    }
}
