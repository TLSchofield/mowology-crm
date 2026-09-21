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
}
