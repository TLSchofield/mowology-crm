<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Where the truck went after a purchase — materials are used the same day.
 */
class ReceiptTrailServiceTest extends TestCase
{
    private function pt(float $lat, float $lng, string $hm): array
    {
        return ['lat' => $lat, 'lng' => $lng, 't' => strtotime('2026-10-02 ' . $hm)];
    }

    public function test_a_dwell_of_five_minutes_or_more_is_a_stop(): void
    {
        $points = [
            $this->pt(49.2600, -123.1200, '08:05'),   // driving
            $this->pt(49.2650, -123.1300, '08:15'),   // driving
            $this->pt(49.2700, -123.1500, '08:39'),   // arrives
            $this->pt(49.27005, -123.15004, '08:45'),
            $this->pt(49.26998, -123.14998, '09:50'), // leaves after
            $this->pt(49.2800, -123.1000, '10:05'),
        ];
        $stops = ReceiptTrailService::detectStops($points);
        $this->assertCount(1, $stops);
        $this->assertSame(strtotime('2026-10-02 08:39'), $stops[0]['start']);
        $this->assertSame(strtotime('2026-10-02 09:50'), $stops[0]['end']);
    }

    public function test_a_red_light_is_not_a_stop(): void
    {
        $points = [$this->pt(49.27, -123.15, '08:39'), $this->pt(49.27001, -123.15001, '08:41'), $this->pt(49.28, -123.10, '08:50')];
        $this->assertSame([], ReceiptTrailService::detectStops($points));
    }

    public function test_nearest_property_within_radius(): void
    {
        $rows = [
            ['property_id' => 1, 'latitude' => 49.2710, 'longitude' => -123.1500],   // ~111 m
            ['property_id' => 2, 'latitude' => 49.2702, 'longitude' => -123.1500],   // ~22 m
            ['property_id' => 3, 'latitude' => 49.2900, 'longitude' => -123.1500],   // far
            ['property_id' => 4, 'latitude' => null, 'longitude' => null],
        ];
        $best = ReceiptTrailService::nearestWithin($rows, 49.2700, -123.1500, 200);
        $this->assertSame(2, $best['property_id']);
        $this->assertNull(ReceiptTrailService::nearestWithin($rows, 49.3500, -123.1500, 200));
    }

    public function test_reason_names_the_strongest_signal_first(): void
    {
        $t = strtotime('2026-10-02 08:39');
        $this->assertSame('Crew started this visit at 08:39, 34 min after the purchase (also truck GPS)',
            ReceiptTrailService::reason(['truck', 'visit'], $t, 34));
        $this->assertSame('Truck stopped here at 08:39, 1.5 h after the purchase',
            ReceiptTrailService::reason(['truck'], $t, 90));
    }
}
