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

    public function testTheMerchantStemSurvivesStoreNumbersAndSpacing(): void
    {
        $a = BankLineEvidence::stem('POINTOFSALE(THEHOMEDEPOT7259VANCOUVERBCCA)');
        $b = BankLineEvidence::stem('Point of sale THE HOME DEPOT 7042');
        $this->assertSame('THEHOMEDEP', $a);
        $this->assertSame($a, $b, 'one shop across store numbers and formats');
        $this->assertNotSame(BankLineEvidence::stem('POINTOFSALE(DAIRYQUEEN27339VANCOUVERBCCA)'), BankLineEvidence::stem('POINTOFSALE(SHELLC01018VANCOUVERBCCA)'),
            'two shops never collapse to "point of sale"');
        $this->assertSame('DAIRYQUEEN', BankLineEvidence::stem('POINTOFSALE(DAIRYQUEEN27339VANCOUVERBCCA)'));
        $this->assertSame('SHELLC', BankLineEvidence::stem('POINTOFSALE(SHELLC01018VANCOUVERBCCA)'));
        $this->assertSame('CANEV', BankLineEvidence::stem('e-Transfer debit Ref 20260318164949668880 Canev'), 'the payee of an e-Transfer is the name after its reference');
        $this->assertSame('', BankLineEvidence::stem('ATM 12'), 'too little to go on');
    }

    public function testAVendorOnFileIsFoundInsideTheBankText(): void
    {
        $v = [['name' => 'Shell', 'aliases' => '', 'default_accounting_category' => 'Vehicle'], ['name' => 'Home Depot', 'aliases' => 'THE HOME DEPOT', 'default_accounting_category' => 'Materials']];
        $this->assertSame(['name' => 'Shell', 'category' => 'Vehicle'], BankLineEvidence::vendorFor('POINTOFSALE(SHELLC01018VANCOUVERBCCA)', $v));
        $this->assertSame('Home Depot', BankLineEvidence::vendorFor('POINTOFSALE(THEHOMEDEPOT7259VANCOUVERBCCA)', $v)['name']);
        $this->assertNull(BankLineEvidence::vendorFor('POINTOFSALE(TOZENSUSHIBARVANCOUVERBCCA)', $v));
        $s = BankLineEvidence::summarise(['earlier' => [], 'receipts' => [], 'recurring' => 0, 'vendor' => ['name' => 'Shell', 'category' => 'Vehicle']]);
        $this->assertSame('Vendor on file: Shell (usually Vehicle)', $s[2]);
    }
}
