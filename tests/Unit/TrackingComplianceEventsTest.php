<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrackingComplianceEventsTest extends TestCase
{
    private const NOW = 1790000000;

    public function testEventsAreShapedAndMillisecondTimestampsAccepted(): void
    {
        $out = TrackingIngestService::normalizeEvents(['events' => [
            ['id' => '7', 'type' => 'tracking_setup_skipped', 't' => (self::NOW - 60) * 1000, 'lat' => 49.25, 'lng' => -123.1, 'acc' => 12.6, 'reason' => '{"location":"when_in_use"}'],
            ['id' => '8', 'type' => 'MANUAL_OVERRIDE', 't' => self::NOW - 30, 'visit_id' => '2072'],
        ]], self::NOW);

        $this->assertCount(2, $out);
        $this->assertSame(['7', 'tracking_setup_skipped', self::NOW - 60, 13], [$out[0]['id'], $out[0]['type'], $out[0]['ts'], $out[0]['acc']]);
        $this->assertSame(2072, $out[1]['visit_id']);
        $this->assertNull($out[1]['acc']);
    }

    public function testEventsWithoutIdTypeOrSaneTimestampAreDropped(): void
    {
        $out = TrackingIngestService::normalizeEvents(['events' => [
            ['type' => 'x', 't' => self::NOW],                                   // no id
            ['id' => '1', 't' => self::NOW],                                     // no type
            ['id' => '2', 'type' => 'x'],                                        // no time
            ['id' => '3', 'type' => 'x', 't' => self::NOW + 3600],               // an hour in the future
            ['id' => '4', 'type' => 'x', 't' => self::NOW - 40 * 86400],         // 40 days old
            'not an array',
        ]], self::NOW);
        $this->assertSame([], $out);
        $this->assertSame([], TrackingIngestService::normalizeEvents([], self::NOW));
    }
}
