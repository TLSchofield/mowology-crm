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
        $this->assertSame(24, PennyBrainService::shapeNumber(23));
        $this->assertSame(500, PennyBrainService::shapeNumber(9999));
    }
}
