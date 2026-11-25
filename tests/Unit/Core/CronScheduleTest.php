<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase
{
    private string $oldTimezone;

    protected function setUp(): void
    {
        $this->oldTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->oldTimezone);
    }

    public function test_supported_field_forms(): void
    {
        $ts = mktime(4, 15, 0, 11, 2, 2026);
        $this->assertTrue(CronSchedule::matches('* * * * *', $ts));
        $this->assertTrue(CronSchedule::matches('*/15 4 2 11 1', $ts));
        $this->assertTrue(CronSchedule::matches('10,15,20 4 2 11 1', $ts));
        $this->assertTrue(CronSchedule::matches('10-20 4 2 11 1', $ts));
        $this->assertTrue(CronSchedule::matches('5-25/10 4 2 11 1', $ts));
        $this->assertFalse(CronSchedule::matches('5-25/9 4 2 11 1', $ts));
    }

    public function test_sunday_seven_and_day_or_rule(): void
    {
        $sunday = mktime(12, 0, 0, 11, 1, 2026);
        $monday = mktime(12, 0, 0, 11, 2, 2026);
        $this->assertTrue(CronSchedule::matches('0 12 * * 7', $sunday));
        $this->assertTrue(CronSchedule::matches('0 12 1 * 1', $sunday));
        $this->assertTrue(CronSchedule::matches('0 12 1 * 1', $monday));
        $this->assertFalse(CronSchedule::matches('0 12 3 * 2', $monday));
    }

    /** @dataProvider invalidExpressions */
    public function test_invalid_expressions_never_match(string $expr): void
    {
        $this->assertFalse(CronSchedule::matches($expr, mktime(12, 0, 0, 11, 1, 2026)));
    }

    public static function invalidExpressions(): array
    {
        return [[''], ['* * * *'], ['60 * * * *'], ['*/0 * * * *'], ['1-0 * * * *'],
                ['* * * 13 *'], ['* * * * 8'], ['1,,2 * * * *'], ['1/2 * * * *']];
    }

    public function test_due_window_boundaries_and_cap(): void
    {
        $tenFiftyNine = mktime(10, 59, 0, 11, 2, 2026);
        $eleven = mktime(11, 0, 0, 11, 2, 2026);
        $this->assertTrue(CronSchedule::dueBetween('0 11 * * *', $tenFiftyNine, $eleven));
        $this->assertFalse(CronSchedule::dueBetween('59 10 * * *', $tenFiftyNine, $eleven));
        $this->assertTrue(CronSchedule::dueBetween('0 11 * * *', mktime(10, 30, 0, 11, 2, 2026), mktime(11, 1, 0, 11, 2, 2026)));
        $this->assertFalse(CronSchedule::dueBetween('0 9 * * *', mktime(6, 0, 0, 11, 2, 2026), mktime(11, 0, 0, 11, 2, 2026)));
    }
}
