<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FieldSearchServiceTest extends TestCase
{
    public function testQueryIsTrimmedAndCollapsed(): void
    {
        $this->assertSame('3400 balaclava', FieldSearchService::normalize("  3400   balaclava \n"));
        $this->assertSame('', FieldSearchService::normalize(null));
    }

    public function testPhoneSearchNeedsFiveDigitsAndNoLetters(): void
    {
        $this->assertSame('7788469273', FieldSearchService::phoneDigits('(778) 846-9273'));
        $this->assertSame('778846', FieldSearchService::phoneDigits('778 846'));
        $this->assertNull(FieldSearchService::phoneDigits('2845'));          // a street number
        $this->assertNull(FieldSearchService::phoneDigits('2845 W 15th'));   // an address
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        $this->assertSame('%100\\% off%', FieldSearchService::likeTerm('100% off'));
        $this->assertSame('%a\\_b%', FieldSearchService::likeTerm('a_b'));
    }

    public function testFieldRankingTodayThenNearestThenName(): void
    {
        $rows = [
            ['label' => 'Zed far, not today',   'on_today' => false, 'distance_m' => 9000],
            ['label' => 'No location',          'on_today' => false, 'distance_m' => null],
            ['label' => 'Near, not today',      'on_today' => false, 'distance_m' => 40],
            ['label' => 'Far but on today',     'on_today' => true,  'distance_m' => 12000],
            ['label' => 'Alpha no location',    'on_today' => false, 'distance_m' => null],
        ];
        usort($rows, static fn ($a, $b) => FieldSearchService::rankKey($a) <=> FieldSearchService::rankKey($b));

        $this->assertSame(
            ['Far but on today', 'Near, not today', 'Zed far, not today', 'Alpha no location', 'No location'],
            array_column($rows, 'label')
        );
    }

    public function testDistanceIsRoughlyRight(): void
    {
        // 3400 Balaclava → 2845 W 15th, Vancouver: about 430 m.
        $d = FieldSearchService::distanceM(49.2563265, -123.1738451, 49.2588654, -123.1693297);
        $this->assertGreaterThan(380, $d);
        $this->assertLessThan(480, $d);
        $this->assertSame(0, FieldSearchService::distanceM(49.25, -123.1, 49.25, -123.1));
    }

    public function testZeroZeroIsNotALocation(): void
    {
        $this->assertFalse(FieldSearchService::hasCoords(0.0, 0.0));
        $this->assertFalse(FieldSearchService::hasCoords(null, -123.1));
        $this->assertTrue(FieldSearchService::hasCoords(49.25, -123.1));
    }
}
