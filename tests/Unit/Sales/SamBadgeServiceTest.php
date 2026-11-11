<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's badges: earned from Tim's decisions, lost again when Sam slips.
 */
class SamBadgeServiceTest extends TestCase
{
    private function keys(array $r): array
    {
        return array_column($r['earned'], 'key');
    }

    public function test_your_words_needs_the_last_five_sent_unchanged(): void
    {
        $this->assertContains('words', $this->keys(SamBadgeService::compute([true, true, true, true, true, false], 0)));
        $this->assertNotContains('words', $this->keys(SamBadgeService::compute([false, true, true, true, true, true], 0)), 'a rewrite breaks the streak');
    }

    public function test_wins_earn_revived_then_closer(): void
    {
        $this->assertSame(['revived'], $this->keys(SamBadgeService::compute([false], 1)));
        $this->assertSame(['revived', 'closer'], $this->keys(SamBadgeService::compute([false], 5)));
    }

    public function test_the_closest_unearned_badge_is_shown_next(): void
    {
        $r = SamBadgeService::compute([true, true, true, false], 0);
        $this->assertSame('words', $r['next']['key']);
        $this->assertSame(3, $r['next']['have']);
        $this->assertSame(5, $r['next']['need']);
    }
}
