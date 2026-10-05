<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Did you forget to invoice it, or is it included in the contract?" — the decision
 * half: does an invoice line mention the purchase, and how the question reads.
 */
class PennyQuestionServiceTest extends TestCase
{
    public function test_invoice_line_naming_the_product_counts_as_billed(): void
    {
        $this->assertTrue(PennyQuestionService::lineMentions(
            ['Lawn care — monthly', 'Fertilizer application (Lawnboy 20-5-10)'],
            ['LAWNBOY FERTILIZER 20-5-10 25KG'], 'Materials'));
    }

    public function test_a_generic_materials_line_counts_as_billed(): void
    {
        $this->assertTrue(PennyQuestionService::lineMentions(['Materials & supplies'], ['MULCH BLACK 2CF'], 'Materials'));
        $this->assertTrue(PennyQuestionService::lineMentions(['Green waste dump fee'], [], 'Disposal/Dump'));
    }

    public function test_a_labour_only_invoice_does_not_count(): void
    {
        $this->assertFalse(PennyQuestionService::lineMentions(['Hedge trimming — 3 hours', 'Weekly lawn maintenance'], ['MULCH BLACK 2CF'], 'Materials'));
    }

    public function test_noise_words_do_not_match(): void
    {
        $kw = PennyQuestionService::keywords(['BLACK MULCH 2CF BAGS'], 'Other');
        $this->assertSame(['mulch'], $kw);
    }

    public function test_question_wording_with_and_without_a_contract(): void
    {
        $q = PennyQuestionService::wording(86.40, ['LAWNBOY FERTILIZER'], 'Lawnboy', 'Lawn care — 2492 W 8th Ave', '2026-10-02', true);
        $this->assertSame("You bought \$86.40 of LAWNBOY FERTILIZER from Lawnboy for Lawn care — 2492 W 8th Ave on Oct 2. I can't find it on an invoice. Did you forget to invoice it, or is it included in their contract?", $q);
        $this->assertStringEndsWith("or isn't it billable?", PennyQuestionService::wording(10, [], '', 'X', '2026-10-02', false));
    }
}
