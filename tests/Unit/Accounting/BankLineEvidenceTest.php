<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** BankLineEvidence::summarise — an empty answer is evidence too; nothing here knows any supplier by name. */
class BankLineEvidenceTest extends TestCase
{
    public function testAFirstTimePayeeWithNoReceiptSaysSo(): void
    {
        $s = BankLineEvidence::summarise(['earlier' => [], 'receipts' => [], 'recurring' => 0]);
        $this->assertSame(['First time this payee is on your bank lines', 'No receipt within 14 days', 'No vendor of yours matches'], $s);
    }

    public function testEarlierLinesAreCountedByWhereTheyWereFiled(): void
    {
        $e = ['earlier' => [
            ['date' => '2025-12-01', 'amount' => 20.0, 'account' => '6850 Meals & Entertainment'],
            ['date' => '2025-11-01', 'amount' => 25.0, 'account' => '6850 Meals & Entertainment'],
            ['date' => '2025-10-01', 'amount' => 30.0, 'account' => '6900 Miscellaneous Expenses'],
        ], 'receipts' => [], 'recurring' => 0];
        $this->assertSame('Seen 3 times before: 2 on 6850 Meals & Entertainment, 1 on not sorted', BankLineEvidence::summarise($e)[0], '6900 is a default: not sorted');
    }

    public function testAClosedYearReceiptIsShownButMarkedNotLinkable(): void
    {
        $e = ['earlier' => [], 'recurring' => 0, 'receipts' => [['id' => 5, 'vendor' => 'Tozen', 'date' => '2025-12-30', 'amount' => 295.28, 'status' => 'approved', 'closed_year' => true]]];
        $this->assertSame('Receipt: Tozen $295.28 · 2025-12-30 · approved (closed year: not linkable)', BankLineEvidence::summarise($e)[1]);
    }

    public function testARepeatingAmountIsNamedAndTheListStaysShort(): void
    {
        $e = ['earlier' => [['date' => 'd', 'amount' => 9.0, 'account' => '6850 Meals'], ['date' => 'd', 'amount' => 9.0, 'account' => '6850 Meals']],
              'receipts' => [], 'recurring' => 2];
        $s = BankLineEvidence::summarise($e);
        $this->assertContains('Same amount 3 times — it repeats', $s);
        $this->assertLessThanOrEqual(4, count($s));
    }
}
