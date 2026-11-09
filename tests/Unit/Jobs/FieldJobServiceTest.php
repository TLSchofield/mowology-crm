<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FieldJobServiceTest extends TestCase
{
    private const TODAY = '2026-09-21'; // a Monday

    public function testDateFallsBackToTodayWhenMissingOrMalformed(): void
    {
        $this->assertSame('2026-10-02', FieldJobService::resolveDate('2026-10-02', self::TODAY));
        $this->assertSame(self::TODAY, FieldJobService::resolveDate('', self::TODAY));
        $this->assertSame(self::TODAY, FieldJobService::resolveDate('next tuesday', self::TODAY));
        $this->assertSame(self::TODAY, FieldJobService::resolveDate(null, self::TODAY));
    }

    public function testLastPriceIsTheNewestMatchingInvoiceLine(): void
    {
        $lines = [
            ['text' => 'Fertilizer program, full season', 'price' => '650.00', 'date' => '2026-11-07'],
            ['text' => 'Lawn cuts, 11 visits (May 7 to Sep 24)', 'price' => '90.00', 'date' => '2026-11-07'],
            ['text' => 'Lawn cuts, 4 visits', 'price' => '80.00', 'date' => '2026-05-01'],
        ];
        $plans = [['text' => 'Lawn Cuts lawn_care', 'price' => '93.46', 'date' => '2026-04-30']];
        $hit = FieldJobService::pickLastPrice($lines, $plans, 'Lawn Cut');
        $this->assertSame(90.0, $hit['price']);
        $this->assertSame('invoice', $hit['source']);
    }

    public function testLastPriceNeedsEveryWordOfTheJob(): void
    {
        $lines = [['text' => 'Lawn & Garden — October 2026', 'price' => '405.00', 'date' => '2026-10-01']];
        $this->assertNull(FieldJobService::pickLastPrice($lines, [], 'Lawn Cut'));
    }

    public function testLastPriceFallsBackToAPlan(): void
    {
        $plans = [['text' => 'Lawn Cuts lawn_care', 'price' => '93.46', 'date' => '2026-04-30']];
        $hit = FieldJobService::pickLastPrice([], $plans, 'Lawn Cut');
        $this->assertSame(93.46, $hit['price']);
        $this->assertSame('plan', $hit['source']);
    }

    public function testLastPriceIgnoresCreditsAndOther(): void
    {
        $lines = [['text' => 'Lawn cut credit', 'price' => '-50.00', 'date' => '2026-10-01'],
                  ['text' => 'Deposit for lawn cuts', 'price' => '200.00', 'date' => '2026-10-01']];
        $this->assertNull(FieldJobService::pickLastPrice($lines, [], 'Lawn Cut'));
        $this->assertNull(FieldJobService::pickLastPrice([['text' => 'Other work', 'price' => '50', 'date' => '2026-10-01']], [], 'Other'));
    }

    public function testRadiusIsBounded(): void
    {
        $this->assertSame(400, FieldJobService::resolveRadius('400'));
        $this->assertSame(250, FieldJobService::resolveRadius(0));
        $this->assertSame(250, FieldJobService::resolveRadius(99999));
        $this->assertSame(250, FieldJobService::resolveRadius('abc'));
    }

    public function testOneOffJobIsAssignedToItsCreatorAndTitledByService(): void
    {
        $plan = FieldJobService::buildPlanData(['service_type' => 'Salt Application'], 22, 6, self::TODAY);

        $this->assertSame(22, $plan['property_id']);
        $this->assertSame('Salt Application', $plan['title']);
        $this->assertSame(6, $plan['default_crew_id']);
        $this->assertSame([6], $plan['crew_ids']);
        $this->assertSame(self::TODAY, $plan['plan_start_date']);
        $this->assertSame(0, $plan['is_recurring']);
        $this->assertNull($plan['price_per_visit']);
        $this->assertArrayNotHasKey('recurrence_pattern', $plan);
    }

    public function testRecurringJobRepeatsOnTheWeekdayItStarted(): void
    {
        $plan = FieldJobService::buildPlanData(
            ['service_type' => 'Lawn Cut', 'recurring' => 1, 'frequency' => 'biweekly', 'date' => '2026-09-23'],
            5, 1, self::TODAY
        );
        $this->assertSame(1, $plan['is_recurring']);
        $this->assertSame('biweekly', $plan['recurrence_pattern']);
        $this->assertSame('weeks', $plan['recurrence_interval_unit']);
        $this->assertSame(3, $plan['recurrence_day_of_week']); // Wednesday
    }

    public function testMonthlyUsesMonthsAndUnknownFrequencyBecomesWeekly(): void
    {
        $monthly = FieldJobService::buildPlanData(['service_type' => 'X', 'recurring' => 1, 'frequency' => 'monthly'], 1, 1, self::TODAY);
        $this->assertSame('months', $monthly['recurrence_interval_unit']);

        $odd = FieldJobService::buildPlanData(['service_type' => 'X', 'recurring' => 1, 'frequency' => 'hourly'], 1, 1, self::TODAY);
        $this->assertSame('weekly', $odd['recurrence_pattern']);
    }

    public function testPriceIsOptionalRoundedAndNeverNegative(): void
    {
        $this->assertSame(82.22, FieldJobService::buildPlanData(['service_type' => 'X', 'price' => '82.219'], 1, 1, self::TODAY)['price_per_visit']);
        $this->assertNull(FieldJobService::buildPlanData(['service_type' => 'X', 'price' => '-5'], 1, 1, self::TODAY)['price_per_visit']);
        $this->assertNull(FieldJobService::buildPlanData(['service_type' => 'X', 'price' => 'call'], 1, 1, self::TODAY)['price_per_visit']);
    }

    public function testMissingFieldsAreNamed(): void
    {
        $this->assertNull(FieldJobService::missingForJob(['service_type' => 'Lawn Cut'], false));
        $this->assertNotNull(FieldJobService::missingForJob(['service_type' => ' '], false));
        $this->assertNotNull(FieldJobService::missingForJob(['service_type' => 'Lawn Cut', 'first_name' => 'Ann'], true));
        $this->assertNull(FieldJobService::missingForJob(['service_type' => 'Lawn Cut', 'first_name' => 'Ann', 'property_address' => '1 Main St'], true));
    }

    public function testCreateClientNeedsOnlyNameAndAddress(): void
    {
        $this->assertNotNull(FieldJobService::missingForClient([]));
        $this->assertNotNull(FieldJobService::missingForClient(['first_name' => 'Ann']));
        $this->assertNotNull(FieldJobService::missingForClient(['property_address' => '1 Main St']));
        $this->assertNull(FieldJobService::missingForClient(['first_name' => 'Ann', 'property_address' => '1 Main St']));
        $this->assertSame('Vancouver', FieldJobService::cityOrDefault(['property_city' => '  ']));
        $this->assertSame('Burnaby', FieldJobService::cityOrDefault(['property_city' => ' Burnaby ']));
    }

    public function testCreateClientRejectsBeforeTouchingTheDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $res = (new FieldJobService($pdo))->createClient(['first_name' => 'Ann'], 'Crew');
        $this->assertFalse($res['success']);
        $this->assertSame(400, $res['status']);
    }
}
