<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PhotoRefileServiceTest extends TestCase
{
    // Two squares about 0.0036 deg (~400 m) wide, ~2 km apart.
    private function square(float $lat, float $lng): array
    {
        $d = 0.0018;
        return [[$lat - $d, $lng - $d], [$lat - $d, $lng + $d], [$lat + $d, $lng + $d], [$lat + $d, $lng - $d]];
    }

    private function prop(int $id, float $lat, float $lng, bool $border = true): array
    {
        $d = 0.0018;
        return [
            'id' => $id, 'lat' => $lat, 'lng' => $lng,
            'polygon' => $border ? $this->square($lat, $lng) : [],
            'bbox' => $border ? ['lat_min' => $lat - $d, 'lat_max' => $lat + $d, 'lng_min' => $lng - $d, 'lng_max' => $lng + $d] : null,
        ];
    }

    private function photo(float $lat, float $lng, array $over = []): array
    {
        return array_merge([
            'gps_lat' => $lat, 'gps_lng' => $lng, 'gps_accuracy_m' => 5.0, 'gps_source' => 'exif',
            'captured_at' => '2026-11-19 10:00:00', 'created_at' => '2026-11-19 10:01:00',
        ], $over);
    }

    public function test_photo_inside_its_own_border_is_ok(): void
    {
        $filed = $this->prop(1, 49.26, -123.15);
        $v = PhotoRefileService::classify($this->photo(49.2601, -123.1501), $filed, []);
        $this->assertSame('ok', $v['verdict']);
    }

    public function test_far_photo_inside_another_border_is_wrong_with_that_candidate(): void
    {
        $filed = $this->prop(1, 49.26, -123.15);
        $other = $this->prop(2, 49.28, -123.15);
        $v = PhotoRefileService::classify($this->photo(49.2801, -123.1501), $filed, [$other]);
        $this->assertSame('wrong', $v['verdict']);
        $this->assertSame([2], $v['candidates']);
        $this->assertGreaterThan(1500, $v['distance_m']);
    }

    public function test_edge_noise_just_outside_the_border_is_not_flagged(): void
    {
        $filed = $this->prop(1, 49.26, -123.15);
        // ~170 m east of the point: outside the ~200 m-wide square, still under the 250 m rule
        $v = PhotoRefileService::classify($this->photo(49.26, -123.1478), $filed, []);
        $this->assertSame('ok', $v['verdict']);
    }

    public function test_far_photo_in_no_other_border_is_wrong_without_a_target(): void
    {
        $filed = $this->prop(1, 49.26, -123.15);
        $v = PhotoRefileService::classify($this->photo(49.30, -123.15), $filed, [$this->prop(2, 49.28, -123.15)]);
        $this->assertSame('wrong', $v['verdict']);
        $this->assertSame([], $v['candidates']);
    }

    public function test_two_overlapping_borders_give_two_candidates(): void
    {
        $filed = $this->prop(1, 49.26, -123.15);
        $v = PhotoRefileService::classify($this->photo(49.28, -123.15), $filed,
            [$this->prop(2, 49.28, -123.15), $this->prop(3, 49.2805, -123.15)]);
        $this->assertSame([2, 3], $v['candidates']);
    }

    public function test_untrustworthy_fixes_are_skipped(): void
    {
        $filed = $this->prop(1, 49.26, -123.15);
        $far = [49.30, -123.15];
        foreach ([
            ['gps_accuracy_m' => null],
            ['gps_accuracy_m' => 2000.0],
            ['gps_accuracy_m' => 0.0],
            ['gps_source' => 'manual'],
            ['gps_source' => 'none'],
            ['gps_source' => 'browser', 'captured_at' => '2026-10-20 09:00:00'],
            ['gps_source' => 'browser', 'captured_at' => null, 'created_at' => '2026-10-20 09:00:00'],
        ] as $over) {
            $v = PhotoRefileService::classify($this->photo($far[0], $far[1], $over), $filed, []);
            $this->assertSame('skip', $v['verdict'], json_encode($over));
        }
        $this->assertSame('wrong', PhotoRefileService::classify(
            $this->photo($far[0], $far[1], ['gps_source' => 'browser', 'captured_at' => '2026-11-05 09:00:00']), $filed, [])['verdict']);
    }

    public function test_no_gps_is_skipped(): void
    {
        $v = PhotoRefileService::classify($this->photo(0, 0, ['gps_lat' => null, 'gps_lng' => null]), $this->prop(1, 49.26, -123.15), []);
        $this->assertSame('skip', $v['verdict']);
    }

    public function test_metres_is_about_right(): void
    {
        $d = PhotoRefileService::metres(49.26, -123.15, 49.27, -123.15);
        $this->assertEqualsWithDelta(1112, $d, 5);
    }

    public function test_undo_refuses_when_the_photo_has_moved_again(): void
    {
        $sel = $this->createMock(PDOStatement::class);
        $sel->method('execute')->willReturn(true);
        $sel->method('fetch')->willReturn(['id' => 1, 'action' => 'move', 'from_visit_id' => 10, 'link_id' => 5, 'to_visit_id' => 11]);
        $upd = $this->createMock(PDOStatement::class);
        $upd->method('execute')->willReturn(true);
        $upd->method('rowCount')->willReturn(0);
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($sel, $upd);
        $this->assertFalse((new PhotoRefileService($db))->undo(1, 3));
    }

    public function test_undo_restores_the_link_and_stamps_the_log(): void
    {
        $sel = $this->createMock(PDOStatement::class);
        $sel->method('execute')->willReturn(true);
        $sel->method('fetch')->willReturn(['id' => 1, 'action' => 'move', 'from_visit_id' => 10, 'link_id' => 5, 'to_visit_id' => 11]);
        $upd = $this->createMock(PDOStatement::class);
        $upd->method('execute')->willReturn(true);
        $upd->method('rowCount')->willReturn(1);
        $log = $this->createMock(PDOStatement::class);
        $log->method('execute')->willReturn(true);
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($sel, $upd, $log);
        $this->assertTrue((new PhotoRefileService($db))->undo(1, 3));
    }
}
