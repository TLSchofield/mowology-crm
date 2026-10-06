<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SamQuestionServiceTest extends TestCase
{
    public function test_an_expired_quote_question_reads_plainly(): void
    {
        $q = SamQuestionService::wording(['quote_number' => 'QUO-2026-0041', 'first_name' => 'Ron', 'last_name' => 'Harvie', 'address' => '7 Cypress St',
                                          'amount' => 815.85, 'valid_until' => '2026-09-30'], 'Tim');
        $this->assertSame('Hey Tim — QUO-2026-0041 for Ron Harvie at 7 Cypress St ($816) ran out on Sep 30 and I never saw a yes. Lost, still alive, or did they say yes another way?', $q);
    }
}
