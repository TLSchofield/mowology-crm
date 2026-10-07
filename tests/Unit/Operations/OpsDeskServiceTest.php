<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's desk: Charlie's brief contract, the brain counts, the question wording, and
 * the small helpers. The SQL itself is MySQL-only and is checked on a rendered page.
 */
class OpsDeskServiceTest extends TestCase
{
    private function sqlite(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function test_brief_before_the_migration_is_an_empty_but_valid_brief(): void
    {
        $b = (new OpsDeskService($this->sqlite()))->brief('Tim');
        $this->assertSame(['head' => 'otto', 'headline' => '', 'items' => [], 'count' => 0], $b);
    }

    public function test_brief_items_carry_stable_keys_and_come_most_urgent_first(): void
    {
        $desk = new class($this->sqlite(), '2026-10-05') extends OpsDeskService {
            public function ready(): bool { return true; }
            public function current(bool $persist = false): array
            {
                return [
                    ['key' => 'otto:visit-time:9', 'kind' => 'no_time', 'priority' => 3, 'text' => 'Mowing at 12 Oak St is done but has no time on it.', 'url' => '/crm/jobs/visit-detail.php?id=9', 'value' => 45, 'since' => '2026-10-02'],
                    ['key' => 'otto:silent:4', 'kind' => 'silent', 'priority' => 1, 'text' => "Nigel's phone has sent no location for 40 min while on the clock.", 'url' => '/crm/timeclock/crew-map.php', 'value' => 40, 'since' => '2026-10-05', 'who' => 'Nigel'],
                ];
            }
            public function stats(?array $items = null): array
            {
                return ['today' => ['stops' => 12, 'done' => 3, 'unassigned' => 1, 'crews' => [['name' => 'Nigel'], ['name' => 'Sam']], 'on_clock' => 2],
                        'weather' => 0, 'gaps' => 1, 'silent' => ['Nigel'], 'right_first_time' => null, 'outlook' => null];
            }
        };
        $b = $desk->brief('Tim');
        $this->assertSame('otto', $b['head']);
        $this->assertStringStartsWith('Hey Tim — 2 crews, 12 stops today, 3 done.', $b['headline']);
        $this->assertSame(3, $b['count']);
        $this->assertSame(['otto:silent:4', 'otto:unassigned:2026-10-05', 'otto:visit-time:9'], array_column($b['items'], 'key'));
        foreach ($b['items'] as $it) {
            $this->assertSame(['key', 'kind', 'text', 'url', 'priority', 'value', 'since'], array_keys($it));
            $this->assertContains($it['priority'], [1, 2, 3]);
        }
        $this->assertSame('1 stop today has no crew.', $b['items'][1]['text']);
    }

    public function test_the_card_stays_hidden_without_the_tables(): void
    {
        $this->assertFalse((new OpsDeskService($this->sqlite()))->ready());
    }

    public function test_helpers(): void
    {
        $this->assertSame('Nigel', OpsDeskService::firstName('Nigel Brown'));
        $this->assertSame('Someone', OpsDeskService::firstName('  '));
        $this->assertSame('1234 Main St', OpsDeskService::street('1234 Main St, Burnaby BC'));
        $this->assertSame('the property', OpsDeskService::street(''));
        $this->assertSame('40 min', OpsDeskService::hours(40));
        $this->assertSame('1 h 35 min', OpsDeskService::hours(95));
        $this->assertSame('2 h', OpsDeskService::hours(120));
        $this->assertSame('weather|visit|12|2026-10-06', OpsDeskService::rowKey('weather', 'visit', 12, '2026-10-06'));
    }

    public function test_brain_counts_a_weather_lesson_only_once_it_can_lean(): void
    {
        $raw = OttoBrainService::rawCounts([['keep' => [50, 55]], ['keep' => [50], 'move' => [80, 85]]], 2, 4, 1, 0);
        $this->assertSame(['weather' => 1, 'crew' => 2, 'durations' => 4, 'answers' => 1, 'badges' => 0], $raw);
        $b = HeadBrain::combine($raw, OttoBrainService::LABELS);
        $this->assertSame(8, $b['units']);
        $this->assertSame('1 service type with a rain call learned', $b['parts'][0]['label']);
    }

    public function test_weather_question_reads_plainly(): void
    {
        $this->assertSame('Rain 55% is in the forecast for 3 mowing visits. Do you usually keep mowing going in rain like that, or move it?',
            OttoQuestionService::weatherQuestion('mowing', 55, 3));
    }

    public function test_brain_counts_dispatcher_learning_when_given(): void
    {
        $raw = OttoBrainService::rawCounts([], 0, 0, 0, 0, ['rules' => 3, 'road' => 1, 'packs' => 6, 'intervals' => 2]);
        $b = HeadBrain::combine($raw, OttoBrainService::LABELS);
        $this->assertSame(12, $b['units']);
        $this->assertSame(['3 bylaw rules confirmed', '1 truck road factor learned', '6 battery runs logged', '2 service intervals set'], array_column($b['parts'], 'label'));
    }

    public function test_dispatcher_kinds_are_part_of_otto(): void
    {
        foreach (OpsDeskService::DISPATCH_KINDS as $k) $this->assertContains($k, OpsDeskService::KINDS);
    }

    public function test_bylaw_check_is_silent_before_the_migration(): void
    {
        $this->assertSame([[], []], (new MunicipalRuleService($this->sqlite()))->items());
        $this->assertSame([[], [], []], (new EquipmentService($this->sqlite()))->suggestionItems());
    }
}
