<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PlantWorkServiceTest extends TestCase
{
    private function card(int $id, string $common, array $tasks, string $status = 'approved'): array
    {
        return ['id' => $id, 'status' => $status, 'common_name' => $common, 'card' => ['tasks' => $tasks]];
    }

    private function maples(): array
    {
        return [
            ['id' => 51, 'scientific_name' => 'Acer palmatum', 'common_name' => 'Japanese maple'],
            ['id' => 52, 'scientific_name' => 'Acer palmatum', 'common_name' => 'Japanese maple'],
            ['id' => 60, 'scientific_name' => 'Acer circinatum', 'common_name' => 'Vine maple'],
            ['id' => 70, 'scientific_name' => 'Taxus baccata', 'common_name' => ''],
        ];
    }

    public function test_window_includes_the_ends_and_wraps_the_year(): void
    {
        $this->assertTrue(PlantWorkService::inWindow(11, 11, 2));
        $this->assertTrue(PlantWorkService::inWindow(1, 11, 2));
        $this->assertTrue(PlantWorkService::inWindow(2, 11, 2));
        $this->assertFalse(PlantWorkService::inWindow(3, 11, 2));
        $this->assertTrue(PlantWorkService::inWindow(5, 4, 6));
        $this->assertFalse(PlantWorkService::inWindow(7, 4, 6));
        $this->assertFalse(PlantWorkService::inWindow(5, 0, 0), 'a task with no months is never due');
    }

    public function test_care_lines_group_by_card_task_and_count_plants(): void
    {
        $genus = $this->card(9, 'Maples', [['task' => 'Thin crossing shoots', 'from' => 11, 'to' => 2], ['task' => 'Mulch', 'from' => 4, 'to' => 5]]);
        $yew = $this->card(12, 'Yew', [['task' => 'Clip', 'from' => 8, 'to' => 9]]);
        $cards = [51 => $genus, 52 => $genus, 60 => $genus, 70 => $yew];
        $out = PlantWorkService::compose($this->maples(), $cards, [], 11);
        $this->assertCount(1, $out['lines'], 'only November tasks; the yew clip and the mulch are not due');
        $l = $out['lines'][0];
        $this->assertSame('Thin crossing shoots — Maples (×3)', $l['item'], 'two species on one genus card are named by the card');
        $this->assertSame('care:9:1', $l['key']);
        $this->assertSame([51, 52, 60], $l['plant_ids']);
        $this->assertFalse($l['checked']);
    }

    public function test_one_species_is_named_by_the_plant_and_unapproved_cards_are_ignored(): void
    {
        $card = $this->card(4, 'Japanese maples (general)', [['task' => 'Thin', 'from' => 11, 'to' => 11]]);
        $draft = $this->card(5, 'Vine maple', [['task' => 'Thin', 'from' => 11, 'to' => 11]], 'draft');
        $out = PlantWorkService::compose($this->maples(), [51 => $card, 52 => $card, 60 => $draft], [], 11);
        $this->assertSame(['Thin — Japanese maple (×2)'], array_column($out['lines'], 'item'));
    }

    public function test_plan_lines_come_first_scheduled_before_approved_and_approved_only_in_window(): void
    {
        $plans = [
            ['id' => 3, 'status' => 'approved', 'plant_record_id' => 51, 'plan' => ['now' => ['from' => 11, 'to' => 11], 'main' => ['from' => 1, 'to' => 2], 'crew_checklist' => ['Remove dead wood', '']]],
            ['id' => 8, 'status' => 'scheduled', 'plant_record_id' => 70, 'plan' => ['now' => ['from' => 6, 'to' => 6], 'main' => ['from' => 6, 'to' => 6], 'crew_checklist' => ['Clip the top']]],
            ['id' => 9, 'status' => 'approved', 'plant_record_id' => 60, 'plan' => ['now' => ['from' => 6, 'to' => 6], 'main' => ['from' => 7, 'to' => 7], 'crew_checklist' => ['Out of season']]],
            ['id' => 10, 'status' => 'draft', 'plant_record_id' => 52, 'plan' => ['crew_checklist' => ['Draft line']]],
            ['id' => 11, 'status' => 'scheduled', 'plant_record_id' => 999, 'plan' => ['crew_checklist' => ['Removed plant']]],
        ];
        $care = [51 => $this->card(4, '', [['task' => 'Thin', 'from' => 11, 'to' => 11]])];
        $out = PlantWorkService::compose($this->maples(), $care, $plans, 11);
        $this->assertSame(['Clip the top — Taxus baccata', 'Remove dead wood — Japanese maple', 'Thin — Japanese maple'], array_column($out['lines'], 'item'));
        $this->assertSame(['plan:8:1', 'plan:3:1', 'care:4:1'], array_column($out['lines'], 'key'));
    }

    public function test_lines_are_capped_with_a_count_of_the_rest(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 8; $i++) $tasks[] = ['task' => 'Task ' . $i, 'from' => 1, 'to' => 12];
        $out = PlantWorkService::compose($this->maples(), [51 => $this->card(1, '', $tasks), 60 => $this->card(2, '', $tasks)], [], 5);
        $this->assertCount(PlantWorkService::MAX_LINES, $out['lines']);
        $this->assertSame(4, $out['more']);
    }

    public function test_seeding_is_idempotent_by_key_and_by_text(): void
    {
        $lines = [
            ['item' => 'Thin — Japanese maple', 'checked' => false, 'note' => '', 'key' => 'care:4:1', 'plant_ids' => [51]],
            ['item' => 'Mulch — Japanese maple', 'checked' => false, 'note' => '', 'key' => 'care:4:2', 'plant_ids' => [51]],
        ];
        $existing = [['item' => 'Mow', 'checked' => true, 'note' => ''], ['item' => 'mulch — japanese maple', 'checked' => false, 'note' => '']];
        $once = PlantWorkService::seedChecklist($existing, $lines);
        $this->assertSame(['Mow', 'mulch — japanese maple', 'Thin — Japanese maple'], array_column($once, 'item'));
        $this->assertSame($once, PlantWorkService::seedChecklist($once, $lines));
    }

    public function test_reattach_gives_keys_back_by_text_and_plant_ids_by_key(): void
    {
        $known = [['item' => 'Thin — Japanese maple (×2)', 'key' => 'care:4:1', 'plant_ids' => [51, 52]], ['item' => 'Old', 'key' => 'bogus', 'plant_ids' => [1]]];
        $posted = [
            ['item' => 'Thin — Japanese maple (×2)', 'checked' => true, 'note' => ''],   // iPhone: key dropped
            ['item' => 'Old', 'checked' => true, 'note' => ''],                          // known key is malformed: not attached
            ['item' => 'Renamed by crew', 'checked' => true, 'note' => '', 'key' => 'care:4:1'],
            ['item' => 'Mow', 'checked' => false, 'note' => ''],
        ];
        $out = PlantWorkService::reattach($posted, $known);
        $this->assertSame('care:4:1', $out[0]['key']);
        $this->assertSame([51, 52], $out[0]['plant_ids']);
        $this->assertArrayNotHasKey('key', $out[1]);
        $this->assertSame([51, 52], $out[2]['plant_ids']);
        $this->assertArrayNotHasKey('key', $out[3]);
    }

    public function test_next_task_is_the_one_due_now_else_the_soonest_to_start(): void
    {
        $tasks = [['task' => 'Mulch', 'from' => 4, 'to' => 5], ['task' => 'Thin', 'from' => 11, 'to' => 2], ['task' => '', 'from' => 6, 'to' => 6]];
        $this->assertSame(['task' => 'Thin', 'when' => 'Nov–Feb', 'now' => true], PlantWorkService::nextTask($tasks, 1));
        $this->assertSame(['task' => 'Mulch', 'when' => 'Apr–May', 'now' => false], PlantWorkService::nextTask($tasks, 3));
        $this->assertSame('Thin', PlantWorkService::nextTask($tasks, 7)['task'], 'wraps forward to November');
        $this->assertNull(PlantWorkService::nextTask([], 5));
    }

    public function test_a_plan_is_complete_only_when_every_real_line_is_ticked(): void
    {
        $lines = ['Remove dead wood', '', 'Shorten tips'];
        $this->assertTrue(PlantWorkService::planComplete($lines, [1 => true, 3 => true]), 'the blank line 2 is not needed');
        $this->assertFalse(PlantWorkService::planComplete($lines, [1 => true, 1 => true]), 'one line repeated does not stand in for another');
        $this->assertFalse(PlantWorkService::planComplete($lines, [1 => true, 3 => false]));
        $this->assertFalse(PlantWorkService::planComplete([], [1 => true]), 'an empty plan is never closed by a checklist');
    }

    public function test_task_only_strips_the_plant_part(): void
    {
        $this->assertSame('Thin crossing shoots', PlantWorkService::taskOnly('Thin crossing shoots — Japanese maple (×6)'));
        $this->assertSame('Mow', PlantWorkService::taskOnly('Mow'));
    }

    public function test_visit_checklist_keeps_plant_keys_and_seeds_plant_lines_after_the_template(): void
    {
        $lines = [['item' => 'Thin — Japanese maple', 'checked' => false, 'note' => '', 'key' => 'care:4:1', 'plant_ids' => [51, '52', -1]]];
        $out = VisitWorkService::resolveChecklist(null, json_encode(['Mow']), $lines);
        $this->assertSame(['Mow', 'Thin — Japanese maple'], array_column($out, 'item'));
        $this->assertSame(['item' => 'Mow', 'checked' => false, 'note' => ''], $out[0], 'ordinary rows keep their three keys');
        $this->assertSame('care:4:1', $out[1]['key']);
        $this->assertSame([51, 52], $out[1]['plant_ids'], 'ids are ints, bad ones dropped');

        $saved = json_encode([['item' => 'Mow', 'checked' => true, 'note' => '']]);
        $this->assertCount(1, VisitWorkService::resolveChecklist($saved, null, $lines), 'once saved, the checklist is the crew\'s: no re-seeding');

        $bad = VisitWorkService::sanitizeChecklist([['item' => 'X', 'checked' => false, 'key' => 'drop table', 'plant_ids' => [1]]]);
        $this->assertSame([['item' => 'X', 'checked' => false, 'note' => '']], $bad, 'an unknown key shape is dropped with its plant ids');
    }

    public function test_seeding_is_off_for_snow_and_salt_visits(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new PlantWorkService($db, $this->createMock(PlantCareService::class), new PruningPlanService($db));
        $this->assertFalse($svc->seedsFor(5, 'snow_removal'));
        $this->assertFalse($svc->seedsFor(5, 'salt_application'));
        $this->assertFalse($svc->seedsFor(0, 'maintenance'));
    }
}
