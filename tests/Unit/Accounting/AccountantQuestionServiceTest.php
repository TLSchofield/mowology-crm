<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** AccountantQuestionService — the question's words come from the books; the opening-cash gap is filed minus the Dec 31 statements. */
class AccountantQuestionServiceTest extends TestCase
{
    public function testOneKeyPerGap(): void
    {
        $this->assertSame('drift:2026-01', AccountantQuestionService::keyFor('2026-01'));
    }

    public function testOpeningEvidenceIsFiledCashMinusTheStatements(): void
    {
        $e = AccountantQuestionService::openingEvidence(11017.00, ['1010' => 2679.92, '1025' => 8329.38, '1020' => 0.69, '2400' => 5574.08]);
        $this->assertSame(['filed' => 11017.0, 'statements' => 11009.99, 'gap' => 7.01], $e, 'the card is not cash');
    }

    public function testNoEvidenceWhenAStatementBalanceIsUnknown(): void
    {
        $this->assertNull(AccountantQuestionService::openingEvidence(11017.00, ['1010' => 2679.92, '1025' => 8329.38]));
        $this->assertNull(AccountantQuestionService::openingEvidence(11017.00, ['1010' => null, '1025' => 1.0, '1020' => 1.0]));
    }

    public function testTheFirstMonthAsksAboutTheCorrectingEntry(): void
    {
        $d = AccountantQuestionService::draft('2026-01', '1010 Chequing Account', 7.01, ['filed' => 11017.0, 'statements' => 11009.99, 'gap' => 7.01]);
        $this->assertStringContainsString('January 2026: the books are $7.01 above the bank for 1010 Chequing Account', $d['question']);
        $this->assertStringContainsString('Filed FY2025 cash is $11,017.00; the Dec 31 statements add up to $11,009.99 ($7.01 less).', $d['question']);
        $this->assertStringContainsString('May we post a Jan 1 correcting entry (cash −$7.01 against retained earnings)?', $d['question']);
        $this->assertSame('Filed FY2025 cash is $11,017.00; the Dec 31 statements add up to $11,009.99 ($7.01 less).', $d['evidence']);
        $this->assertSame('Filed $11,017.00 · statements $11,009.99', $d['evidence_short']);
    }

    public function testAnyOtherGapIsAskedPlainly(): void
    {
        $d = AccountantQuestionService::draft('2026-03', '1010 Chequing Account', -15.37, null);
        $this->assertStringContainsString('March 2026: the books are $15.37 below the bank', $d['question']);
        $this->assertStringContainsString('How should we clear it?', $d['question']);
        $this->assertNull($d['evidence']);
    }

    public function testASmallOrMissingOpeningGapAddsNoEvidence(): void
    {
        $d = AccountantQuestionService::draft('2026-01', 'X', 7.01, ['filed' => 100.0, 'statements' => 100.0, 'gap' => 0.0]);
        $this->assertNull($d['evidence']);
    }
}
