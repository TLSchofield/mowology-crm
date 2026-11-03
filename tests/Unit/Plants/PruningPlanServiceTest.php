<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PruningPlanServiceTest extends TestCase
{
    private function fixturePlan(): array
    {
        return [
            'species' => ['scientific' => 'Hydrangea paniculata', 'common' => 'Panicle hydrangea', 'confidence' => 0.82,
                          'if_wrong' => [['instead' => 'Hydrangea macrophylla', 'what_changes' => 'Do not shorten stems in March; they carry the flower buds.']]],
            'form_goal' => 'Open vase of 8–10 strong stems clear of the windows',
            'now' => ['from' => 11, 'to' => 11, 'actions' => [1, 2], 'note' => 'Dead and rubbing only'],
            'main' => ['from' => 2, 'to' => 3, 'actions' => [3, 4, 6, 5, 99], 'note' => 'Before buds break'],
            'actions' => [
                ['n' => 1, 'type' => 'deadwood', 'what' => 'Remove the broken stem on the left', 'why' => 'Disease entry', 'anchor' => ['photo' => 0, 'x' => 0.31, 'y' => 0.72, 'confidence' => 0.7]],
                ['n' => 2, 'type' => 'clear_access', 'what' => 'Cut the stem rubbing the window frame', 'why' => 'Damages the frame', 'anchor' => ['photo' => 0, 'x' => 0.86, 'y' => 0.33, 'confidence' => 0.8]],
                ['n' => 3, 'type' => 'remove_ground', 'what' => 'Take out the two oldest stems at the base', 'why' => 'Renewal', 'anchor' => ['photo' => 0, 'x' => 0.52, 'y' => 0.93, 'confidence' => 0.5]],
                ['n' => 4, 'type' => 'shorten', 'what' => 'Shorten remaining stems by a third', 'why' => 'Stronger flowering stems', 'anchor' => ['photo' => 0, 'x' => 0, 'y' => 0, 'confidence' => 0.9]],
                ['n' => 5, 'type' => 'bogus_type', 'what' => 'ignored', 'why' => 'ignored', 'anchor' => ['photo' => 0, 'x' => 0.5, 'y' => 0.5, 'confidence' => 1]],
                ['n' => 6, 'type' => 'keep', 'what' => 'Keep the five tallest central stems', 'why' => 'Framework', 'anchor' => ['photo' => 7, 'x' => 1.4, 'y' => -0.2, 'confidence' => 0.6]],
            ],
            'multi_year' => [['year' => 1, 'aim' => 'Renewal cut'], ['year' => 2, 'aim' => 'Shape the framework']],
            'site_constraints' => ['Fire department connection at the right must stay clear', ''],
            'checks_on_site' => ['Whether the base stems are alive'],
            'client_summary' => 'We will thin the hydrangea in late winter so it flowers better and stays off the windows.',
            'crew_checklist' => ['Remove broken stem', 'Cut stem off window', 'Remove two oldest stems at base', 'Shorten rest by a third'],
        ];
    }

    private function apiBody(array $plan, array $usage = ['input_tokens' => 4000, 'output_tokens' => 900]): string
    {
        return json_encode(['content' => [['type' => 'text', 'text' => json_encode($plan)]], 'usage' => $usage]);
    }

    public function test_request_carries_photos_then_text_and_the_species_note(): void
    {
        $img = ['media_type' => 'image/jpeg', 'data' => base64_encode('x')];
        $req = PruningPlanService::request([$img, $img], ['scientific' => 'Hydrangea paniculata', 'common' => 'Panicle hydrangea', 'score' => 0.61,
            'confirmed' => false, 'bed' => 'Front bed', 'address' => '6015 Tisdall St', 'city' => 'Vancouver', 'date' => '2026-11-03', 'photos' => 2]);
        $this->assertSame(PruningPlanService::MODEL, $req['model']);
        $this->assertSame('json_schema', $req['output_config']['format']['type']);
        $content = $req['messages'][0]['content'];
        $this->assertCount(3, $content);
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('text', $content[2]['type']);
        $this->assertStringContainsString('Hydrangea paniculata (Panicle hydrangea), suggested by Pl@ntNet at 61%', $content[2]['text']);
        $this->assertStringContainsString('bed "Front bed", 6015 Tisdall St, Vancouver', $content[2]['text']);
        $this->assertStringContainsString('Species notes for Hydrangea', $req['system'][0]['text'], 'the genus note is appended');
        $this->assertStringContainsString('paniculata and arborescens flower on new wood', $req['system'][0]['text']);
    }

    public function test_request_without_a_known_genus_has_no_species_note(): void
    {
        $req = PruningPlanService::request([], ['scientific' => 'Zelkova serrata', 'common' => '', 'score' => null, 'confirmed' => true,
            'bed' => '', 'address' => '', 'city' => '', 'date' => '2026-11-03', 'photos' => 1]);
        $this->assertStringNotContainsString('Species notes for', $req['system'][0]['text']);
        $this->assertStringContainsString('confirmed by staff. Where: the property.', $req['messages'][0]['content'][0]['text']);
    }

    public function test_schema_requires_every_key_and_limits_action_types(): void
    {
        $s = PruningPlanService::schema();
        $this->assertSame(array_keys($s['properties']), $s['required']);
        $this->assertFalse($s['additionalProperties']);
        $this->assertSame(array_keys(PruningKnowledge::TYPES), $s['properties']['actions']['items']['properties']['type']['enum']);
        $this->assertSame(12, $s['properties']['main']['properties']['from']['maximum']);
    }

    public function test_clean_renumbers_drops_bad_types_and_clamps_anchors(): void
    {
        $plan = PruningPlanService::clean($this->fixturePlan());
        $this->assertNotNull($plan);
        $this->assertCount(5, $plan['actions'], 'the bogus type is dropped');
        $this->assertSame([1, 2, 3, 4, 5], array_column($plan['actions'], 'n'), 'renumbered in order');
        $keep = $plan['actions'][4];
        $this->assertSame('keep', $keep['type']);
        $this->assertSame(0, $keep['anchor']['photo'], 'every anchor lives on the whole-plant photo');
        $this->assertSame(1.0, $keep['anchor']['x']);
        $this->assertSame(0.0, $keep['anchor']['y']);
        $this->assertSame(0.0, $plan['actions'][3]['anchor']['confidence'], 'an anchor at 0,0 is "not placed" whatever the model claimed');
        $this->assertSame([1, 2], $plan['now']['actions']);
        $this->assertSame([3, 4, 5], $plan['main']['actions'], 'the model said 3,4,6,5,99 → 5 (bogus) dropped, 6 became 5, 99 unknown: windows follow the renumbering');
        $this->assertSame(['Fire department connection at the right must stay clear'], $plan['site_constraints'], 'empty strings dropped');
        $this->assertSame(0.82, $plan['species']['confidence']);
        $this->assertCount(1, $plan['species']['if_wrong']);
    }

    public function test_clean_rejects_a_plan_with_no_usable_actions(): void
    {
        $this->assertNull(PruningPlanService::clean(['actions' => [['type' => 'nope']]]));
        $this->assertNull(PruningPlanService::clean([]));
    }

    public function test_parse_reads_the_text_block_and_usage(): void
    {
        $p = PruningPlanService::parse($this->apiBody($this->fixturePlan()));
        $this->assertSame('Hydrangea paniculata', $p['plan']['species']['scientific']);
        $this->assertSame(900, $p['usage']['output_tokens']);
        $this->assertNull(PruningPlanService::parse('not json'));
        $this->assertNull(PruningPlanService::parse(json_encode(['content' => [['type' => 'text', 'text' => 'plain prose']]])));
    }

    public function test_cost_and_window_text(): void
    {
        $this->assertSame(0.0255, PruningPlanService::cost(['input_tokens' => 4000, 'output_tokens' => 900]));
        $this->assertSame('Feb–Mar', PruningPlanService::window(2, 3));
        $this->assertSame('Nov', PruningPlanService::window(11, 11));
        $this->assertSame('Nov–Feb', PruningPlanService::window(11, 2));
    }

    public function test_draft_refuses_an_unidentified_plant_before_spending_anything(): void
    {
        $ready = $this->createMock(PDOStatement::class);
        $plantStmt = $this->createMock(PDOStatement::class);
        $plantStmt->method('execute')->willReturn(true);
        $plantStmt->method('fetch')->willReturn(['id' => 5, 'property_id' => 9, 'media_ids' => '1,2', 'scientific_name' => '', 'status' => 'suggested']);
        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturn($ready);
        $db->method('prepare')->willReturn($plantStmt);
        $called = false;
        $svc = new PruningPlanService($db, function () use (&$called) { $called = true; return ['code' => 200, 'body' => '']; });
        $res = $svc->draft(5, 1);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Identify the plant first', $res['message']);
        $this->assertFalse($called, 'no API call for an unidentified plant');
    }

    public function test_knowledge_lookup_is_by_genus(): void
    {
        $this->assertNotNull(PruningKnowledge::forName('Prunus cerasifera'));
        $this->assertStringContainsString('silver leaf', PruningKnowledge::forName('Prunus cerasifera')['gaps']);
        $this->assertNull(PruningKnowledge::forName('Zelkova serrata'));
        $this->assertNull(PruningKnowledge::forName(null));
    }
}
