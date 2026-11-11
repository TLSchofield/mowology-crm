<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Plants/Services/BedLocator.php';

/** Which bed a plant photo belongs to — shapes like the real "Garden Bed South West / Centre" outlines (property 76). */
class BedLocatorTest extends TestCase
{
    // Two beds along a south fence: west ~6 m wide, centre ~20 m wide, each ~2.5 m deep, 2.6 m apart.
    private function beds(): array
    {
        $box = fn($w, $e) => [['lat' => 49.259086, 'lng' => $w], ['lat' => 49.259086, 'lng' => $e], ['lat' => 49.259063, 'lng' => $e], ['lat' => 49.259063, 'lng' => $w]];
        return [
            ['id' => 1, 'name' => 'Garden Bed South West', 'ring' => BedLocator::ring(json_encode($box(-123.143434, -123.143350)))],
            ['id' => 2, 'name' => 'Garden Bed South Centre', 'ring' => BedLocator::ring(json_encode($box(-123.143313, -123.143036)))],
        ];
    }

    public function testInsideABedWithAGoodFixIsSure(): void
    {
        $r = BedLocator::locate(49.259075, -123.143400, 5.0, null, $this->beds());
        $this->assertSame([1, 'inside', true], [$r['id'], $r['how'], $r['sure']]);
    }

    public function testInsideWithARoughFixIsOnlyAGuess(): void
    {
        $r = BedLocator::locate(49.259075, -123.143400, 40.0, null, $this->beds());
        $this->assertSame([1, false], [$r['id'], $r['sure']]);
    }

    public function testFacingABedFromTheLawn(): void
    {
        // Standing ~10 m north of the centre bed, facing south (180°).
        $r = BedLocator::locate(49.259175, -123.143150, 6.0, 180.0, $this->beds());
        $this->assertSame([2, 'facing', true], [$r['id'], $r['how'], $r['sure']]);
        // Facing north, away from both: no bed, only candidates.
        $r = BedLocator::locate(49.259175, -123.143150, 6.0, 0.0, $this->beds());
        $this->assertNull($r['id']);
        $this->assertSame(2, $r['candidates'][0]['id']);
    }

    public function testNearAnEdgeIsAGuessAndFarIsNothing(): void
    {
        $r = BedLocator::locate(49.259105, -123.143400, 8.0, null, $this->beds());   // ~2 m north of the west bed
        $this->assertSame([1, 'near', false], [$r['id'], $r['how'], $r['sure']]);
        $this->assertNull(BedLocator::locate(49.259400, -123.143400, 8.0, null, $this->beds())['id'], '35 m away');
        $this->assertNull(BedLocator::locate(null, null, null, null, $this->beds())['id']);
    }

    public function testRingAndDistance(): void
    {
        $this->assertSame([], BedLocator::ring('[{"lat":1,"lng":2}]'), 'two points are not an outline');
        $this->assertEqualsWithDelta(2.1, BedLocator::distance(49.259105, -123.143400, $this->beds()[0]['ring']), 0.3);
        $this->assertEqualsWithDelta(9.9, BedLocator::rayHit(49.259175, -123.143150, 180.0, $this->beds()[1]['ring']), 0.3);
    }
}
