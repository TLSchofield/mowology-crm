<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every head's rule (2026-10-15): once is a hint, twice in a row is a rule, newer different resets, old fades, revoke unlearns. */
class HeadLessonsRuleTest extends TestCase
{
    private static function r(string $correct, string $at): array { return ['correct' => $correct, 'created_at' => $at]; }

    public function test_once_is_a_hint_twice_is_a_rule(): void
    {
        $this->assertNull(HeadLessons::decide([self::r('Fuel', '2026-10-01 10:00:00')], 2, 365, '2026-10-15'));
        $rule = HeadLessons::decide([self::r('Fuel', '2026-10-02 10:00:00'), self::r('Fuel', '2026-10-01 10:00:00')], 2, 365, '2026-10-15');
        $this->assertSame('Fuel', $rule['value']);
        $this->assertSame(2, $rule['streak']);
        $this->assertSame('2026-10-01 10:00:00', $rule['since']);
    }

    public function test_a_newer_different_answer_resets(): void
    {
        $rows = [self::r('Meals', '2026-10-03'), self::r('Fuel', '2026-10-02'), self::r('Fuel', '2026-10-01')];
        $this->assertNull(HeadLessons::decide($rows, 2, 365, '2026-10-15'), 'Meals once, newest: no rule yet — and Fuel no longer holds');
        array_unshift($rows, self::r('Meals', '2026-10-04'));
        $this->assertSame('Meals', HeadLessons::decide($rows, 2, 365, '2026-10-15')['value']);
        $this->assertSame(0.5, HeadLessons::decide($rows, 2, 365, '2026-10-15')['confidence']);
    }

    public function test_old_lessons_fade_and_revoke_unlearns(): void
    {
        $old = [self::r('Fuel', '2024-01-02'), self::r('Fuel', '2024-01-01')];
        $this->assertNull(HeadLessons::decide($old, 2, 365, '2026-10-15'), 'older than a year');
        $this->assertNotNull(HeadLessons::decide($old, 2, 0, '2026-10-15'), '0 = never fades');
        $revoked = array_merge([self::r(HeadLessons::REVOKED, '2026-10-05')], [self::r('Fuel', '2026-10-02'), self::r('Fuel', '2026-10-01')]);
        $this->assertNull(HeadLessons::decide($revoked, 2, 365, '2026-10-15'));
    }

    public function test_a_stricter_rule(): void
    {
        $rows = [self::r('otto', '2026-10-03'), self::r('otto', '2026-10-02')];
        $this->assertNull(HeadLessons::decide($rows, 3, 365, '2026-10-15'), 'a domain rule needs three');
        $this->assertNull(HeadLessons::decide([], 2));
    }

    public function test_wording_is_learned_only_when_written_alike_twice(): void
    {
        $a = "Hi {first_name},\nJust checking you got the quote for {address}. Happy to answer any questions.\nTim";
        $b = "Hi {first_name},\nJust checking you got my quote for {address}. Happy to answer questions.\nTim";
        $c = "Hey {first_name}! Winter is coming — want us on the list for snow?";
        $this->assertNull(HeadLessons::repeatedText([$a]), 'one edit is a hint');
        $this->assertSame($b, HeadLessons::repeatedText([$b, $a]), 'the second alike becomes the wording');
        $this->assertNull(HeadLessons::repeatedText([$c, $b, $a]), 'a newer different edit resets to a hint');
        $this->assertNull(HeadLessons::repeatedText([$c, $a]));
    }

    public function test_an_agreement_is_kept_only_when_asked(): void
    {
        $l = ['head' => 'otto', 'subject_kind' => 'weather_call', 'subject_key' => 'maintenance', 'field' => 'move_or_keep',
              'said' => 'move', 'correct' => 'move', 'source' => 'otto_weather'];
        $this->assertNull(HeadLessons::row($l), 'a no-change is not a correction');
        $this->assertSame('move', HeadLessons::row($l + ['agreement' => true])['correct']);
    }

    public function test_a_lesson_too_old_does_not_complete_the_streak(): void
    {
        $rows = [self::r('not_now', '2026-10-15 09:00:00'), self::r('not_now', '2024-01-01 09:00:00')];
        $this->assertNull(HeadLessons::decide($rows, 2, 60, '2026-10-15 10:00:00'));
    }
}
