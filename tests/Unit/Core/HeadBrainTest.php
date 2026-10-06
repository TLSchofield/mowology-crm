<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A department head's brain: what counts as learned, and which of the 500 shapes that makes them.
 */
class HeadBrainTest extends TestCase
{
    private const LABELS = ['wins' => ['follow-up won', 'follow-ups won'], 'lessons' => ['lesson', 'lessons']];

    public function test_every_kind_of_learning_counts_once_and_reads_plainly_in_label_order(): void
    {
        $b = HeadBrain::combine(['lessons' => 3, 'wins' => 1, 'unknown' => 9], self::LABELS);
        $this->assertSame(4, $b['units']);
        $this->assertSame(['1 follow-up won', '3 lessons'], array_column($b['parts'], 'label'));
    }

    public function test_one_new_shape_per_thing_learned_up_to_500(): void
    {
        $this->assertSame(1, HeadBrain::shapeNumber(0));
        $this->assertSame(24, HeadBrain::shapeNumber(23));
        $this->assertSame(500, HeadBrain::shapeNumber(9999));
    }

    public function test_only_what_was_learned_since_the_start_line_counts(): void
    {
        $this->assertSame(['wins' => 2, 'lessons' => 0], HeadBrain::sinceBaseline(['wins' => 5, 'lessons' => 1], ['wins' => 3, 'lessons' => 4]));
    }
}
