<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PhotoPositionServiceTest extends TestCase
{
    private const LAT = 49.2635;
    private const LNG = -123.1565;

    private function linkStmt(bool $linked): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetchColumn')->willReturn($linked ? 1 : false);
        return $s;
    }

    /** @return array{0: PDO, 1: PDOStatement} */
    private function dbWithLink(bool $linked, int $rowCount = 1): array
    {
        $upd = $this->createMock(PDOStatement::class);
        $upd->method('execute')->willReturn(true);
        $upd->method('rowCount')->willReturn($rowCount);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($this->linkStmt($linked), $upd);
        return [$db, $upd];
    }

    public function test_number_pins_only_counts_photos_with_a_position(): void
    {
        $rows = [
            ['id' => 1, 'gps_lat' => self::LAT, 'gps_lng' => self::LNG],
            ['id' => 2, 'gps_lat' => null,      'gps_lng' => null],
            ['id' => 3, 'gps_lat' => '0.00000000', 'gps_lng' => '0.00000000'],
            ['id' => 4, 'gps_lat' => self::LAT + 0.0001, 'gps_lng' => self::LNG],
        ];
        $out = PhotoPositionService::numberPins($rows);
        $this->assertSame(1, $out[0]['pin']);
        $this->assertNull($out[1]['pin'], 'no coordinates → no pin');
        $this->assertNull($out[2]['pin'], 'zeroed columns are not a place');
        $this->assertSame(2, $out[3]['pin'], 'numbering skips unplaced photos');
    }

    public function test_rejects_out_of_range_coordinates_before_touching_db(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $svc = new PhotoPositionService($db);

        $this->assertFalse($svc->setPosition(10, 5, 91.0, self::LNG));
        $this->assertFalse($svc->setPosition(10, 5, self::LAT, -181.0));
        $this->assertFalse($svc->setPosition(10, 5, 0.0, 0.0));
        $this->assertFalse($svc->setPosition(0, 5, self::LAT, self::LNG));
    }

    public function test_rejects_media_not_linked_to_the_visit(): void
    {
        [$db, $upd] = $this->dbWithLink(false);
        $upd->expects($this->never())->method('execute');
        $svc = new PhotoPositionService($db);
        $this->assertFalse($svc->setPosition(10, 5, self::LAT, self::LNG));
    }

    public function test_saves_manual_position_for_linked_media(): void
    {
        $upd = $this->createMock(PDOStatement::class);
        $upd->expects($this->once())->method('execute')
            ->with([round(self::LAT, 8), round(self::LNG, 8), 10])
            ->willReturn(true);
        $upd->method('rowCount')->willReturn(1);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($upd) {
            if (stripos($sql, 'UPDATE media_assets') !== false) {
                $this->assertStringContainsString("gps_source = 'manual'", $sql);
                return $upd;
            }
            return $this->linkStmt(true);
        });

        $svc = new PhotoPositionService($db);
        $this->assertTrue($svc->setPosition(10, 5, self::LAT, self::LNG));
    }

    public function test_set_positions_reports_saved_and_rejected(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) {
            $s = $this->createMock(PDOStatement::class);
            $s->method('execute')->willReturn(true);
            $s->method('fetchColumn')->willReturn(1);
            $s->method('rowCount')->willReturn(1);
            return $s;
        });
        $svc = new PhotoPositionService($db);

        $r = $svc->setPositions(5, [
            ['media_id' => 1, 'lat' => self::LAT, 'lng' => self::LNG],
            ['media_id' => 2, 'lat' => 'abc',     'lng' => self::LNG],
            ['media_id' => 3, 'lat' => 95,        'lng' => self::LNG],
        ]);
        $this->assertSame(1, $r['saved']);
        $this->assertSame([2, 3], $r['rejected']);
    }
}
