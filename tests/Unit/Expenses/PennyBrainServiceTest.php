<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's brain: what counts as learned, and which of the 500 shapes that makes her.
 */
class PennyBrainServiceTest extends TestCase
{
    public function test_every_kind_of_learning_counts_once_and_reads_plainly(): void
    {
        $b = PennyBrainService::combine(['trusted' => 1, 'badges' => 2, 'stores' => 0, 'category' => 3, 'lessons' => 17]);
        $this->assertSame(23, $b['units']);
        $this->assertSame(['1 vendor trusted', '2 badges', '3 vendor categories learned', '17 reading lessons remembered'], array_column($b['parts'], 'label'));
    }

    public function test_one_new_shape_per_thing_learned_up_to_500(): void
    {
        $this->assertSame(1, PennyBrainService::shapeNumber(0));
        $this->assertSame(23, PennyBrainService::shapeNumber(23));   // shape = things learned; shape 1 = one triangle
        $this->assertSame(500, PennyBrainService::shapeNumber(9999));
    }

    public function test_only_what_she_learned_since_her_start_line_counts(): void
    {
        $now = PennyBrainService::sinceBaseline(['lessons' => 370, 'badges' => 1, 'trusted' => 1], ['lessons' => 365, 'badges' => 2]);
        $this->assertSame(['lessons' => 5, 'badges' => 0, 'trusted' => 1], $now);
    }

    /** Onboarding "About you" (owner, 2026-10-20): a person who told Penny all three things is one Gold triangle. */
    public function test_a_person_who_told_her_all_three_is_one_gold_item(): void
    {
        $rows = [
            ['id' => 4, 'first_name' => 'Sam', 'preferred_name' => 'Sam', 'phone' => '', 'contact_channel' => 'text', 'quiet_start' => '20:00:00', 'quiet_end' => '07:00:00', 'quiet_weekends' => 0],
            ['id' => 1, 'first_name' => 'Tim', 'preferred_name' => 'Tim', 'phone' => '(604) 555-1212', 'contact_channel' => 'text', 'quiet_start' => '20:00:00', 'quiet_end' => '07:00:00', 'quiet_weekends' => 1],
            ['id' => 2, 'first_name' => 'Nigel', 'preferred_name' => 'Nige', 'contact_channel' => 'email', 'quiet_start' => null, 'quiet_end' => null, 'quiet_weekends' => 0],
            ['id' => 3, 'first_name' => 'Ada', 'preferred_name' => '', 'contact_channel' => 'app', 'quiet_start' => '21:00:00', 'quiet_end' => '06:30:00', 'quiet_weekends' => 0],
        ];
        $items = PennyBrainService::peopleItemsFrom($rows);
        $this->assertCount(1, $items, 'only someone with all three known; two of three is not yet learned, and "text" with no number is not known');
        $this->assertSame('person:1', $items[0]['key']);
        $this->assertSame('About Tim', $items[0]['label']);
        $this->assertSame('People', $items[0]['group']);
        $this->assertSame('By text message · quiet 8 pm–7 am and weekends', $items[0]['note']);
        $this->assertSame('gold', HeadBrain::tier($items[0]['strength'])['slug'], 'no other source for these: the owner\'s word is the fact');
    }
}
