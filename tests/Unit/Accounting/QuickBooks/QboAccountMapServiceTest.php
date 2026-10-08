<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Chart-of-accounts suggestions: numbers win, names are a guess, a different classification
 * is never auto-picked.
 */
class QboAccountMapServiceTest extends TestCase
{
    private function crm(): array
    {
        return [
            ['id' => 1, 'code' => '1010', 'name' => 'Chequing', 'type' => 'asset'],
            ['id' => 2, 'code' => '5200', 'name' => 'Materials', 'type' => 'expense'],
            ['id' => 3, 'code' => '6850', 'name' => 'Merchant Fees', 'type' => 'expense'],
            ['id' => 4, 'code' => '2610', 'name' => 'Loan Payable — RAM 3500HD', 'type' => 'liability'],
            ['id' => 5, 'code' => '4000', 'name' => 'Service Revenue', 'type' => 'revenue'],
        ];
    }

    private function qbo(): array
    {
        return [
            ['id' => '35', 'num' => '1010', 'name' => 'Vancity Chequing', 'classification' => 'Asset', 'type' => 'Bank', 'active' => true],
            ['id' => '60', 'num' => null, 'name' => 'Job Materials & Supplies', 'classification' => 'Expense', 'type' => 'Expense', 'active' => true],
            ['id' => '61', 'num' => null, 'name' => 'Merchant Fees', 'classification' => 'Expense', 'type' => 'Expense', 'active' => true],
            ['id' => '62', 'num' => null, 'name' => 'Merchant Fees', 'classification' => 'Asset', 'type' => 'Other Current Asset', 'active' => true],
            ['id' => '70', 'num' => null, 'name' => 'Materials', 'classification' => 'Revenue', 'type' => 'Income', 'active' => true],
            ['id' => '80', 'num' => '4000', 'name' => 'Sales', 'classification' => 'Revenue', 'type' => 'Income', 'active' => false],
        ];
    }

    public function test_an_account_number_match_scores_100_whatever_the_name(): void
    {
        $s = QboAccountMapService::suggest($this->crm(), $this->qbo());
        $this->assertSame('35', $s[1]['best']['qbo_id']);
        $this->assertSame(100, $s[1]['best']['score']);
        $this->assertSame('number', $s[1]['best']['matched_on']);
    }

    public function test_an_exact_name_with_the_same_classification_scores_90(): void
    {
        $s = QboAccountMapService::suggest($this->crm(), $this->qbo());
        $this->assertSame('61', $s[3]['best']['qbo_id']);
        $this->assertSame(90, $s[3]['best']['score']);
        $this->assertSame('name', $s[3]['best']['matched_on']);
        // the Asset twin with the same name is offered but marked as another type
        $other = array_values(array_filter($s[3]['candidates'], static fn($c) => $c['qbo_id'] === '62'))[0];
        $this->assertSame('name?', $other['matched_on']);
        $this->assertLessThanOrEqual(40, $other['score']);
    }

    public function test_a_similar_name_scores_between_60_and_85_and_a_different_classification_is_never_best(): void
    {
        $s = QboAccountMapService::suggest($this->crm(), $this->qbo());
        $this->assertNull($s[2]['best'], 'Materials: the only exact name is a Revenue account; the similar Expense one may or may not clear 72%');
        $ids = array_column($s[2]['candidates'], 'qbo_id');
        $this->assertContains('70', $ids);
        foreach ($s[2]['candidates'] as $c) {
            if ($c['qbo_id'] === '70') $this->assertSame('name?', $c['matched_on']);
        }
    }

    public function test_inactive_quickbooks_accounts_are_not_offered(): void
    {
        $s = QboAccountMapService::suggest($this->crm(), $this->qbo());
        $this->assertNull($s[5]['best']);
        $this->assertSame([], $s[5]['candidates']);
    }

    public function test_no_candidate_at_all_is_an_empty_suggestion_not_an_error(): void
    {
        $s = QboAccountMapService::suggest($this->crm(), $this->qbo());
        $this->assertNull($s[4]['best']);
    }

    public function test_name_normalisation_drops_noise_words_and_punctuation(): void
    {
        $this->assertSame('job materials supplies', QboAccountMapService::norm('Job Materials & Supplies (expense)'));
        $this->assertSame('merchant fees', QboAccountMapService::norm('Merchant Fees'));
        $this->assertSame(QboAccountMapService::norm('Repairs and Maintenance'), QboAccountMapService::norm('Repairs & Maintenance'));
    }
}
