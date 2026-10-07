<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CloserFixture.php';

class CloserForQuoteTest extends TestCase
{
    private array $r;

    protected function setUp(): void
    {
        $this->r = (new FakeCloserService())->forQuote(101);
    }

    private function line(int $id): array
    {
        foreach ($this->r['lines'] as $l) if ($l['id'] === $id) return $l;
        $this->fail("line {$id} missing");
    }

    public function test_mowing_uses_the_fit_and_carries_the_drive(): void
    {
        $mow = $this->line(1);
        $this->assertSame('mow', $mow['service']);
        $this->assertSame('Your timed visits (22)', $mow['basis']);
        // 6 + 5.2 × 5.2 + 1.5 × 3 = 37.54 min
        $this->assertSame(37.5, $mow['closer']['site_minutes']);
        $this->assertTrue($mow['closer']['includes_drive']);
        $this->assertSame('route', $this->r['drive']['basis']);
        $this->assertLessThan(1.0, $this->r['drive']['minutes']); // between two neighbours on the street
        $this->assertSame('2026-10-07', $this->r['drive']['date']);
    }

    public function test_only_the_first_visit_line_pays_the_drive(): void
    {
        $this->assertFalse($this->line(2)['closer']['includes_drive']);
    }

    public function test_the_closer_flags_a_line_quoted_under_the_floor(): void
    {
        // Edging: 2 + 6 × 1.6 = 11.6 min at $52/h = $10.05 cost; quoted $12 → 16% < 25% floor.
        $edge = $this->line(2);
        $this->assertTrue($edge['closer']['quoted_below_floor']);
        $this->assertLessThan(25, $edge['closer']['margin_at_quoted']);
        $this->assertGreaterThanOrEqual(1, $this->r['below_floor']);
    }

    public function test_an_uncalibrated_service_is_seeded_from_the_price_rule(): void
    {
        $c = $this->line(3);
        $this->assertSame('Implied by the current price rule — set real minutes', $c['basis']);
        // The rule says $156 for 5,200 sq ft; the Closer's price includes disposal on top.
        $this->assertGreaterThanOrEqual(156, $c['closer']['price']);
    }

    public function test_unknown_services_are_named_not_priced(): void
    {
        $g = $this->line(5);
        $this->assertNull($g['closer']);
        $this->assertSame('Not a service the Closer prices', $g['why_not']);
    }

    public function test_a_per_yard_line_is_left_to_the_yard_rule(): void
    {
        $m = $this->line(6);
        $this->assertSame('beds', $m['service']);       // "Garden" maps to bed work…
        $this->assertNull($m['closer']);                 // …but the yard rule prices it, not minutes
        $this->assertSame('Sold by the yard (4 yd × $175.00) — the per-yard rule prices it, not site minutes', $m['why_not']);
        $this->assertTrue(CloserService::isYardLine(['unit_type' => 'YD ']));
        $this->assertFalse(CloserService::isYardLine(['unit_type' => 'each']));
        $this->assertFalse(CloserService::isYardLine([]));
    }

    public function test_a_per_yard_rule_implies_no_bed_work_minutes(): void
    {
        // The mulch rule ($175/yd) must not seed bed-work minutes: Full care still lists beds as missing.
        $this->assertContains('beds', $this->r['tiers'][2]['missing']);
        $this->assertNull($this->r['services']['beds']['basis']);
    }

    public function test_tiers_and_flags(): void
    {
        $prices = array_column($this->r['tiers'], 'price', 'label');
        $this->assertGreaterThan($prices['Basic'], $prices['Standard']);
        // Full care needs minutes for overseeding and bed work → listed as missing, still priced on the rest.
        $full = $this->r['tiers'][2];
        $this->assertContains('overseed', $full['missing']);
        $this->assertContains('Hourly cost is still the seed from one labour cost factor — it leaves out equipment and overhead', $this->r['flags']);
        $this->assertSame('Draft from the map measurement — price confirmed after the first visit.', $this->r['note']);
    }

    public function test_a_season_total_is_not_compared_with_one_visit(): void
    {
        $this->assertTrue(CloserService::looksLikeSeasonTotal('mow', 1, 1248, 52));
        $this->assertFalse(CloserService::looksLikeSeasonTotal('mow', 26, 48, 52));
        $this->assertFalse(CloserService::looksLikeSeasonTotal('cleanup', 1, 400, 60));
    }
}
