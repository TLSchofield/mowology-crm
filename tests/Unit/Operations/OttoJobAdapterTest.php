<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Otto's schedule strip (2026-11-10): which desk items become decisions, what each button posts, how sure he looks. */
class OttoJobAdapterTest extends TestCase
{
    private function item(array $o): array
    {
        return $o + ['id' => 11, 'key' => 'k' . ($o['kind'] ?? 'x'), 'kind' => 'weather', 'text' => 'Text', 'detail' => 'Detail', 'propose' => [], 'day' => '2026-11-12'];
    }

    public function test_weather_moves_to_the_guards_slot_through_decide_and_keep_posts_keep(): void
    {
        $j = OttoJobAdapter::queue([$this->item([])], [], [11 => ['date' => '2026-11-13', 'time_start' => '08:00:00']], [0, 0])[0];
        $this->assertSame('otto', $j['head']);
        $this->assertSame('/crm/api/otto.php?mode=decide', $j['primary']['request']['url']);
        $this->assertSame(['suggestion_id' => 11, 'choice' => 'move', 'date' => '2026-11-13', 'time' => '08:00'], $j['primary']['request']['body']);
        $this->assertSame('keep', $j['secondary']['request']['body']['choice']);
        $this->assertTrue($j['later']);
    }

    public function test_weather_with_no_slot_only_opens_the_day(): void
    {
        $j = OttoJobAdapter::queue([$this->item([])], [], [], [0, 0])[0];
        $this->assertArrayNotHasKey('request', $j['primary']);
        $this->assertSame('/crm/jobs/schedule.php?view=day&date=2026-11-12', $j['primary']['href']);
    }

    public function test_visit_date_moves_or_dismisses(): void
    {
        $j = OttoJobAdapter::queue([$this->item(['kind' => 'visit_date', 'propose' => ['to_label' => 'Tue Nov 10']])], [], [], [0, 0])[0];
        $this->assertSame('move', $j['primary']['request']['body']['choice']);
        $this->assertSame('dismiss', $j['secondary']['request']['body']['choice']);
        $this->assertStringContainsString('Tue Nov 10', $j['primary']['sub']);
    }

    public function test_unscheduled_adds_only_when_one_plan_and_times_are_known(): void
    {
        $it = $this->item(['kind' => 'unscheduled', 'propose' => ['start' => '09:10', 'end' => '10:40']]);
        $one = OttoJobAdapter::queue([$it], [11 => [['id' => 7, 'number' => 'PLN-7']]], [], [0, 0])[0];
        $this->assertSame(['suggestion_id' => 11, 'choice' => 'add_visit', 'plan_id' => 7, 'start' => '09:10', 'end' => '10:40'], $one['primary']['request']['body']);
        $this->assertSame('not_work', $one['secondary']['request']['body']['choice']);
        foreach ([[], [['id' => 7, 'number' => 'a'], ['id' => 8, 'number' => 'b']]] as $plans) {
            $j = OttoJobAdapter::queue([$it], [11 => $plans], [], [0, 0])[0];
            $this->assertArrayNotHasKey('request', $j['primary']);
            $this->assertArrayHasKey('href', $j['primary']);
        }
        $noTimes = OttoJobAdapter::queue([$this->item(['kind' => 'extra_work'])], [11 => [['id' => 7, 'number' => 'a']]], [], [0, 0])[0];
        $this->assertArrayHasKey('href', $noTimes['primary']);
    }

    public function test_overbooked_only_links_and_skips(): void
    {
        $j = OttoJobAdapter::queue([$this->item(['id' => null, 'kind' => 'overbooked'])], [], [], [0, 0])[0];
        $this->assertArrayNotHasKey('request', $j['primary']);
        $this->assertTrue($j['secondary']['skip']);
    }

    public function test_other_kinds_are_left_to_the_things_strip_and_duplicates_collapse_and_order_is_by_urgency(): void
    {
        $items = [
            $this->item(['kind' => 'no_time', 'key' => 'a']),
            $this->item(['kind' => 'unscheduled', 'key' => 'b', 'id' => 1]),
            $this->item(['kind' => 'unscheduled', 'key' => 'b', 'id' => 1]),
            $this->item(['kind' => 'weather', 'key' => 'c', 'id' => 2]),
        ];
        $jobs = OttoJobAdapter::queue($items, [], [], [0, 0]);
        $this->assertSame(['weather', 'unscheduled'], array_column($jobs, 'kind'));
        $this->assertCount(OttoJobAdapter::MAX_JOBS, OttoJobAdapter::queue(array_map(fn($i) => $this->item(['key' => 'k' . $i, 'id' => $i + 1]), range(1, 9)), [], [], [0, 0]));
    }

    public function test_confidence_is_the_triangle_from_the_record(): void
    {
        $this->assertSame('forming', OttoJobAdapter::sure([0, 0]));
        $this->assertSame('held', OttoJobAdapter::sure([3, 2]));
        $this->assertSame('held', OttoJobAdapter::sure([1, 1]));
        $this->assertSame('known', OttoJobAdapter::sure([3, 3]));
        $this->assertSame('mastered', OttoJobAdapter::sure([5, 5]));
    }

    public function test_a_full_day_or_weather_move_in_the_past_is_not_offered_but_past_work_found_is(): void
    {
        $items = [
            $this->item(['id' => null, 'kind' => 'overbooked', 'key' => 'o', 'day' => '2026-11-09']),
            $this->item(['kind' => 'weather', 'key' => 'w', 'id' => 2, 'day' => '2026-11-09']),
            $this->item(['kind' => 'visit_date', 'key' => 'v', 'id' => 3, 'day' => '2026-11-09']),
            $this->item(['id' => null, 'kind' => 'overbooked', 'key' => 'o2', 'day' => '2026-11-11']),
        ];
        $jobs = OttoJobAdapter::queue($items, [], [], [0, 0], '2026-11-11');
        $this->assertSame(['otto:o2', 'otto:v'], array_column($jobs, 'key'));
    }
}
