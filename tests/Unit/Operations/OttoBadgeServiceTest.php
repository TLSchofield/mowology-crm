<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's badges come only from Tim's decisions, and streaks are lost on a slip.
 */
class OttoBadgeServiceTest extends TestCase
{
    private static function d(string $kind, string $status, array $outcome = []): array
    {
        return ['kind' => $kind, 'status' => $status, 'outcome' => $outcome];
    }

    private static function keys(array $badges): array
    {
        return array_column($badges, 'key');
    }

    public function test_nothing_earned_from_nothing(): void
    {
        $b = OttoBadgeService::compute([]);
        $this->assertSame([], $b['earned']);
        $this->assertNotNull($b['next']);
    }

    public function test_ten_kept_clock_outs_earn_clock_fixer_and_one_edit_breaks_it(): void
    {
        $ten = array_fill(0, 10, self::d('clock_out', 'accepted'));
        $this->assertContains('clock', self::keys(OttoBadgeService::compute($ten)['earned']));
        $slipped = array_merge([self::d('clock_out', 'edited')], $ten);
        $this->assertNotContains('clock', self::keys(OttoBadgeService::compute($slipped)['earned']));
    }

    public function test_weather_wise_counts_only_calls_where_otto_had_a_lean(): void
    {
        $followed = array_fill(0, 10, self::d('weather', 'accepted', ['followed' => true]));
        $noLean = array_fill(0, 5, self::d('weather', 'accepted', ['followed' => null]));
        $this->assertContains('weather', self::keys(OttoBadgeService::compute(array_merge($noLean, $followed))['earned']));
        $overruled = array_merge([self::d('weather', 'accepted', ['followed' => false])], $followed);
        $this->assertNotContains('weather', self::keys(OttoBadgeService::compute($overruled)['earned']));
    }

    public function test_quiet_catcher_needs_five_real_problems(): void
    {
        $real = array_fill(0, 5, self::d('silent', 'accepted', ['choice' => 'real']));
        $fine = array_fill(0, 9, self::d('silent', 'dismissed', ['choice' => 'fine']));
        $this->assertContains('quiet', self::keys(OttoBadgeService::compute(array_merge($fine, $real))['earned']));
        $this->assertNotContains('quiet', self::keys(OttoBadgeService::compute($fine)['earned']));
    }

    public function test_gap_closer_counts_applied_fixes_not_dismissals(): void
    {
        $fixes = array_merge(array_fill(0, 20, self::d('no_time', 'edited')), array_fill(0, 5, self::d('job_timer', 'accepted')));
        $this->assertContains('gaps', self::keys(OttoBadgeService::compute($fixes)['earned']));
        $short = array_merge(array_fill(0, 24, self::d('no_time', 'edited')), array_fill(0, 10, self::d('no_time', 'dismissed')));
        $b = OttoBadgeService::compute($short);
        $this->assertNotContains('gaps', self::keys($b['earned']));
    }

    public function test_the_closest_unearned_badge_is_next(): void
    {
        $b = OttoBadgeService::compute(array_fill(0, 4, self::d('silent', 'accepted', ['choice' => 'real'])));
        $this->assertSame('quiet', $b['next']['key']);
        $this->assertSame(4, $b['next']['have']);
    }
}
