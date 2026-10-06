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

    public function test_question_wording_is_friendly_and_plain(): void
    {
        $q = PennyQuestionService::wording(86.40, ['LAWNBOY FERTILIZER'], 'Lawnboy', 'Lawn care', '2492 W 8th Ave', '2026-10-02', true, 'Tim');
        $this->assertSame("Hey Tim — you picked up Lawnboy Fertilizer (\$86.40) at Lawnboy on Oct 2 for Lawn care at 2492 W 8th Ave, and I can't find it on any invoice. Did it slip through, or is it covered by their contract?", $q);
        $q = PennyQuestionService::wording(11.32, [], 'HUNTERS GARDEN CENTRE', 'chk', '2845 W 15th Avenue', '2026-09-05', false, 'Tim');
        $this->assertSame("Hey Tim — you spent \$11.32 at Hunters Garden Centre on Sep 5 for the job at 2845 W 15th Avenue, and I can't find it on any invoice. Did it slip through, or isn't it billable?", $q);
    }

    public function test_totals_and_tax_lines_are_not_items(): void
    {
        $this->assertSame(['MULCH BLACK 2CF'], PennyQuestionService::realItems(['Sub Total', 'Sub Total', 'MULCH BLACK 2CF', 'GST', 'VISA', 'Total']));
    }

    public function test_first_name_from_profile_or_full_name(): void
    {
        $this->assertSame('Tim', PennyQuestionService::firstName(['first_name' => '', 'full_name' => 'TIM SCHOFIELD']));
        $this->assertSame('Nigel', PennyQuestionService::firstName(['first_name' => 'Nigel']));
        $this->assertSame('', PennyQuestionService::firstName([]));
    }
}
