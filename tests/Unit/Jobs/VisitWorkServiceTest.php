<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VisitWorkService's pure rules: what gets stored for a checklist
 * or materials list, how the checklist is seeded, and who may record work.
 */
class VisitWorkServiceTest extends TestCase
{
    // ---- sanitizeChecklist --------------------------------------------------

    public function test_checklist_strips_tags_and_coerces_checked(): void
    {
        $out = VisitWorkService::sanitizeChecklist([
            ['item' => '<b>Edge beds</b>', 'checked' => 1, 'note' => '<i>north side</i>'],
            ['item' => 'Blow off walks', 'checked' => '', 'note' => null],
        ]);
        $this->assertSame(
            [
                ['item' => 'Edge beds', 'checked' => true, 'note' => 'north side'],
                ['item' => 'Blow off walks', 'checked' => false, 'note' => ''],
            ],
            $out
        );
    }

    public function test_checklist_drops_blank_and_malformed_rows(): void
    {
        $out = VisitWorkService::sanitizeChecklist([['item' => '   '], 'not-an-array', ['checked' => true], ['item' => 'Mow']]);
        $this->assertCount(1, $out);
        $this->assertSame('Mow', $out[0]['item']);
    }

    public function test_checklist_truncates_long_values(): void
    {
        $out = VisitWorkService::sanitizeChecklist([['item' => str_repeat('a', 400), 'note' => str_repeat('b', 900)]]);
        $this->assertSame(255, strlen($out[0]['item']));
        $this->assertSame(500, strlen($out[0]['note']));
    }

    // ---- resolveChecklist ---------------------------------------------------

    public function test_saved_checklist_wins_over_template(): void
    {
        $saved = json_encode([['item' => 'Mow', 'checked' => true, 'note' => '']]);
        $out   = VisitWorkService::resolveChecklist($saved, json_encode(['Mow', 'Trim']));
        $this->assertCount(1, $out);
        $this->assertTrue($out[0]['checked']);
    }

    public function test_template_seeds_unchecked_rows_when_nothing_saved(): void
    {
        $out = VisitWorkService::resolveChecklist(null, json_encode(['Mow', 'Trim']));
        $this->assertSame(['Mow', 'Trim'], array_column($out, 'item'));
        $this->assertSame([false, false], array_column($out, 'checked'));
    }

    public function test_empty_saved_array_falls_back_to_template(): void
    {
        $out = VisitWorkService::resolveChecklist('[]', json_encode(['Mow']));
        $this->assertSame(['Mow'], array_column($out, 'item'));
    }

    public function test_no_checklist_anywhere_is_empty_not_an_error(): void
    {
        $this->assertSame([], VisitWorkService::resolveChecklist(null, null));
        $this->assertSame([], VisitWorkService::resolveChecklist('not json', '{bad'));
    }

    // ---- sanitizeMaterials --------------------------------------------------

    public function test_materials_round_numbers_and_null_non_numeric(): void
    {
        $out = VisitWorkService::sanitizeMaterials([
            ['name' => 'Fertilizer 20-5-10', 'qty' => '2.123456', 'unit' => 'kg', 'rate_per_unit' => '14.999', 'note' => ''],
            ['name' => 'Mulch', 'qty' => 'a few', 'unit' => 'yd', 'rate_per_unit' => null],
        ]);
        $this->assertSame(2.1235, $out[0]['qty']);
        $this->assertSame(15.0, $out[0]['rate_per_unit']);
        $this->assertNull($out[1]['qty']);
        $this->assertNull($out[1]['rate_per_unit']);
    }

    public function test_materials_without_a_name_are_dropped(): void
    {
        $this->assertSame([], VisitWorkService::sanitizeMaterials([['name' => '', 'qty' => 3], ['qty' => 1]]));
    }

    // ---- normalizeNoteType --------------------------------------------------

    public function test_unknown_note_type_becomes_general(): void
    {
        $this->assertSame('issue', VisitWorkService::normalizeNoteType('issue'));
        $this->assertSame('general', VisitWorkService::normalizeNoteType('rant'));
        $this->assertSame('general', VisitWorkService::normalizeNoteType(''));
    }

    // ---- canAccess ----------------------------------------------------------

    public function test_access_rules(): void
    {
        $visit = ['assigned_crew_id' => 7, 'stop_crew_id' => 8];

        $this->assertTrue(VisitWorkService::canAccess($visit, 99, true, []), 'office roles always');
        $this->assertTrue(VisitWorkService::canAccess($visit, 7, false, []), 'assigned crew');
        $this->assertTrue(VisitWorkService::canAccess($visit, 8, false, []), 'stop lead');
        $this->assertTrue(VisitWorkService::canAccess($visit, 9, false, [9, 10]), 'crewed onto the stop');
        $this->assertFalse(VisitWorkService::canAccess($visit, 11, false, [9, 10]), 'unrelated crew');
        $this->assertFalse(VisitWorkService::canAccess([], 0, false, []), 'no user, unassigned visit');
    }
}
