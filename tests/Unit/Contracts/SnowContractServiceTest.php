<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SnowContractService — the rules that decide what a signed snow contract becomes
 * and what one daily route stop bills. The load-bearing rule: a stop bills ONLY
 * the rate the crew recorded, never the sum of the contract's rates, and a stop
 * with nothing recorded bills nothing.
 */
class SnowContractServiceTest extends TestCase
{
    /** The four lines on a Dorset 2026-27 quote (Jobber #991, 1585 West 11th). */
    private function dorsetLines(): array
    {
        return [
            ['id' => 1, 'service_type' => 'Areas', 'description' => 'City & property sidewalks', 'unit_price' => '0.00'],
            ['id' => 2, 'service_type' => 'Salt Only Service', 'description' => 'Charged per application.', 'unit_price' => '97.60', 'product_id' => 38],
            ['id' => 3, 'service_type' => 'Snow Removal', 'description' => 'Charged per clearing.', 'unit_price' => '240.75', 'product_id' => 39],
            ['id' => 4, 'service_type' => 'Sub -5 Degrees Arctic Freeze Daily Salt Only Service', 'description' => 'Daily below -5.', 'unit_price' => '75.08', 'product_id' => 41],
            ['id' => 5, 'service_type' => 'Snow Removal Top of the list Charge', 'description' => '', 'unit_price' => '63.00'],
        ];
    }

    private function rates(): array
    {
        return SnowContractService::ratesFromQuoteLines($this->dorsetLines())['rates'];
    }

    public function test_every_dorset_line_maps_to_its_rate(): void
    {
        $roles = array_column($this->rates(), 'role');
        $this->assertSame(['areas', 'salt', 'snow', 'arctic', 'priority'], $roles);
    }

    public function test_arctic_and_top_of_list_are_not_mistaken_for_plain_salt_or_snow(): void
    {
        $this->assertSame('arctic', SnowContractService::roleForLine('Sub -5 Degrees Arctic Freeze Daily Salt Only Service'));
        $this->assertSame('priority', SnowContractService::roleForLine('Snow Removal Top of the list Charge'));
        $this->assertSame('salt', SnowContractService::roleForLine('Salt Only Service'));
        $this->assertSame('snow', SnowContractService::roleForLine('Snow Removal'));
        $this->assertNull(SnowContractService::roleForLine('Leaf cleanup'));
    }

    public function test_unrecognised_lines_are_reported_not_billed(): void
    {
        $r = SnowContractService::ratesFromQuoteLines([['service_type' => 'Hedge trim', 'unit_price' => 80]]);
        $this->assertSame([], $r['rates']);
        $this->assertSame(['Hedge trim'], $r['unknown']);
    }

    public function test_plan_lines_use_the_codes_the_weather_guard_and_salt_report_select(): void
    {
        $this->assertSame('salt_application', SnowContractService::planLineCode('salt'));
        $this->assertSame('salt_application', SnowContractService::planLineCode('arctic'));
        $this->assertSame('snow_removal', SnowContractService::planLineCode('snow'));
        $this->assertSame('snow_removal', SnowContractService::planLineCode('priority'));
        $this->assertSame('areas', SnowContractService::planLineCode('areas'));
    }

    public function test_a_salt_run_bills_the_salt_rate_only(): void
    {
        $b = SnowContractService::billingLines($this->rates(), 'salt', '2026-11-13');
        $this->assertTrue($b['ok']);
        $this->assertCount(1, $b['lines']);
        $this->assertSame(97.60, $b['lines'][0]['line_total']);
        $this->assertStringContainsString('Salt Only Service', $b['lines'][0]['description']);
        $this->assertStringContainsString('Nov 13, 2026', $b['lines'][0]['description']);
    }

    public function test_an_arctic_run_bills_the_arctic_rate_only(): void
    {
        $b = SnowContractService::billingLines($this->rates(), 'arctic', '2026-12-02');
        $this->assertSame([75.08], array_column($b['lines'], 'line_total'));
    }

    public function test_snow_clearing_carries_the_top_of_list_charge(): void
    {
        $b = SnowContractService::billingLines($this->rates(), 'snow', '2026-12-20');
        $this->assertSame([240.75, 63.00], array_column($b['lines'], 'line_total'));
    }

    public function test_snow_clearing_without_top_of_list_bills_snow_alone(): void
    {
        $rates = array_values(array_filter($this->rates(), fn($r) => $r['role'] !== 'priority'));
        $b = SnowContractService::billingLines($rates, 'snow', '2026-12-20');
        $this->assertSame([240.75], array_column($b['lines'], 'line_total'));
    }

    public function test_no_recorded_choice_is_refused_never_billed_as_the_sum(): void
    {
        $b = SnowContractService::billingLines($this->rates(), null, '2026-11-13');
        $this->assertFalse($b['ok']);
        $this->assertSame('SERVICE_CHOICE_REQUIRED', $b['code']);
    }

    public function test_nothing_needed_bills_nothing(): void
    {
        $b = SnowContractService::billingLines($this->rates(), 'none', '2026-11-13');
        $this->assertFalse($b['ok']);
        $this->assertSame('NOTHING_DONE', $b['code']);
    }

    public function test_a_choice_the_contract_has_no_rate_for_is_refused(): void
    {
        $rates = array_values(array_filter($this->rates(), fn($r) => $r['role'] !== 'arctic'));
        $b = SnowContractService::billingLines($rates, 'arctic', '2026-12-02');
        $this->assertSame('NO_RATE', $b['code']);
    }

    // ── Season ─────────────────────────────────────────────────────────────

    public function test_signed_in_october_covers_the_coming_season(): void
    {
        $this->assertSame(['start' => '2026-11-01', 'end' => '2027-03-31'], SnowContractService::seasonFor('2026-10-08'));
    }

    public function test_signed_mid_season_starts_that_day(): void
    {
        $this->assertSame(['start' => '2026-12-10', 'end' => '2027-03-31'], SnowContractService::seasonFor('2026-12-10'));
    }

    public function test_signed_in_january_belongs_to_the_season_already_running(): void
    {
        $this->assertSame(['start' => '2027-01-15', 'end' => '2027-03-31'], SnowContractService::seasonFor('2027-01-15'));
    }

    public function test_signed_in_april_waits_for_next_season(): void
    {
        $this->assertSame(['start' => '2027-11-01', 'end' => '2028-03-31'], SnowContractService::seasonFor('2027-04-02'));
    }

    // ── Which quotes ───────────────────────────────────────────────────────

    public function test_only_snow_contract_quotes_are_set_up(): void
    {
        $this->assertTrue(SnowContractService::isSnowContractQuote(['is_contract' => 1, 'service_type' => 'snow_removal']));
        $this->assertFalse(SnowContractService::isSnowContractQuote(['is_contract' => 0, 'service_type' => 'snow_removal']));
        $this->assertFalse(SnowContractService::isSnowContractQuote(['is_contract' => 1, 'service_type' => 'lawn_care']));
    }
}
