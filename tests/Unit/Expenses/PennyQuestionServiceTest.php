<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Did you forget to invoice it, or is it included in the contract?" — the decision
 * half: does an invoice line mention the purchase, and how the question reads.
 */
class PennyQuestionServiceTest extends TestCase
{
    public function test_different_is_rejected_for_an_ordinary_question_without_mutation(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, job_id INTEGER)');
        $db->exec('CREATE TABLE penny_questions (
            id INTEGER PRIMARY KEY, plan_id INTEGER, status TEXT, kind TEXT,
            expense_id INTEGER, subject TEXT, answer TEXT, answered_by INTEGER, answered_at TEXT
        )');
        $db->exec("INSERT INTO expenses (id, job_id) VALUES (9, 12)");
        $db->exec("INSERT INTO penny_questions (id, status, kind, expense_id, subject) VALUES (1, 'open', 'unbilled_materials', 9, '')");

        $result = (new PennyQuestionService($db))->answer(1, 'different', 7, 'Tim');

        $this->assertSame(['ok' => false, 'message' => 'Unknown answer'], $result);
        $row = $db->query('SELECT status, answer, answered_by, answered_at FROM penny_questions WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['status' => 'open', 'answer' => null, 'answered_by' => null, 'answered_at' => null], $row);
    }

    public function test_twin_subjects_are_parsed_and_unknown_formats_are_zero(): void
    {
        $this->assertSame(['copy_id' => 0, 'keep_id' => 102, 'line_id' => 0, 'right_id' => 0], PennyQuestionService::parseSubject('possible_twin', 'keep:102'));
        $this->assertSame(['copy_id' => 0, 'keep_id' => 108, 'line_id' => 0, 'right_id' => 0], PennyQuestionService::parseSubject('possible_twin', 'with:108'));
        $this->assertSame(['copy_id' => 0, 'keep_id' => 0, 'line_id' => 55, 'right_id' => 212], PennyQuestionService::parseSubject('bad_link', 'line:55;right:212'));
        $this->assertSame(['copy_id' => 0, 'keep_id' => 0, 'line_id' => 0, 'right_id' => 0], PennyQuestionService::parseSubject('possible_twin', 'broken'));
    }
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

    /** Lawnboy #412 (2026-10-12): seed to shop stock, mulch to Oakridge — ask only about the mulch, on Oakridge. */
    public function test_a_split_receipt_asks_only_about_the_lines_on_the_job(): void
    {
        $allocs = [
            ['job_id' => null, 'is_stock' => 1, 'net_amount' => '120.00', 'name' => 'Richardson Sun & Shade Lawn Seed 5 kg'],
            ['job_id' => 124, 'is_stock' => 0, 'net_amount' => '80.00', 'name' => 'Black Composted Bark Mulch'],
        ];
        $this->assertSame(['names' => ['Black Composted Bark Mulch'], 'amount' => 80.0], PennyQuestionService::splitPart($allocs, 124));
        $this->assertSame(0.0, PennyQuestionService::splitPart($allocs, 123)['amount']);   // nothing left on W 19th
        $this->assertNull(PennyQuestionService::splitPart([], 124));                         // not split: whole receipt
    }
}
