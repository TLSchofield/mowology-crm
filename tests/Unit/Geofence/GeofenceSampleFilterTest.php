<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Geofence/Services/GeofenceSampleFilter.php';

final class GeofenceSampleFilterTest extends TestCase
{
    private const NOW = 1790000000;

    public function testOutOfBoundsAndMissingTimesAreRejected(): void
    {
        $r = GeofenceSampleFilter::filter([
            ['lat' => 49.2, 'lng' => -123.1, 'timestamp' => (self::NOW - 60) * 1000],
            ['lat' => 49.2, 'lng' => -123.1, 'timestamp' => (self::NOW + 3600) * 1000],      // an hour ahead
            ['lat' => 49.2, 'lng' => -123.1, 'timestamp' => (self::NOW - 4 * 86400) * 1000], // 4 days old
            ['lat' => 49.2, 'lng' => -123.1],                                                 // no time
        ], self::NOW);
        $this->assertCount(1, $r['keep']);
        $this->assertSame(3, $r['rejected']);
        $this->assertSame((self::NOW - 60) * 1000, $r['keep'][0]['timestamp']);
    }

    public function testRepeatsInTheBatchAndAlreadyStoredSecondsAreDuplicates(): void
    {
        $stored = date('Y-m-d H:i:s', self::NOW - 120);
        $r = GeofenceSampleFilter::filter([
            ['lat' => 1, 'lng' => 1, 'timestamp' => (self::NOW - 120) * 1000],   // already stored
            ['lat' => 1, 'lng' => 1, 'timestamp' => self::NOW - 30],             // seconds are accepted too
            ['lat' => 1, 'lng' => 1, 'timestamp' => (self::NOW - 30) * 1000],    // repeat in the batch
        ], self::NOW, [$stored]);
        $this->assertCount(1, $r['keep']);
        $this->assertSame(2, $r['duplicates']);
    }
}
