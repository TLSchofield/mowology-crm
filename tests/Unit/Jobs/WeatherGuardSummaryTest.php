<?php
use PHPUnit\Framework\TestCase;

class WeatherGuardSummaryTest extends TestCase
{
    public function testReadsTheKeysTheGuardActuallyCounts(): void
    {
        $line = WeatherGuardSummary::line(['auto_moved' => 2, 'action_list' => 5, 'total_visits' => 31]);
        $this->assertSame('2 rescheduled, 5 flagged, 31 visits checked', $line);
    }

    public function testMissingCountsReadAsZero(): void
    {
        $this->assertSame('0 rescheduled, 0 flagged, 0 visits checked', WeatherGuardSummary::line([]));
    }
}
