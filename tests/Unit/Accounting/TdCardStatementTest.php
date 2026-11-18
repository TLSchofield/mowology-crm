<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TD credit-card PDFs print a row as "OCT16 OCT17<TAB>$12.34DESCRIPTION": two dates with no space, the amount
 * before the description, "-$" for a payment or refund. Invented figures only (no real statement in the repo).
 */
class TdCardStatementTest extends TestCase
{
    private function parse(string $text): array
    {
        $m = new ReflectionMethod(BankImportService::class, 'parsePdfText');
        $m->setAccessible(true);
        return $m->invoke((new ReflectionClass(BankImportService::class))->newInstanceWithoutConstructor(), $text);
    }

    private function statement(): string
    {
        return "Previous Balance\t\$1,000.00\nNEW BALANCE\t\$667.50\n"
            . "SEP22 SEP23\t\$25.00ACME HARDWARE\n"
            . "SEP25 SEP26\t\$102.50NORTHWEST CREDIT SUPPLY\n"
            . "OCT01 OCT02\t-\$500.00PAYMENT - THANK YOU\n"
            . "OCT09 OCT10\t\$40.00FUEL STOP\n";
    }

    public function testRowsAreReadWithDatesAmountsAndDescriptions(): void
    {
        $rows = $this->parse($this->statement())['rows'];
        $this->assertCount(4, $rows);
        $this->assertSame('-09-22', substr($rows[0]['date'], 4));
        $this->assertSame(25.0, $rows[0]['amount']);
        $this->assertStringContainsString('ACME HARDWARE', $rows[0]['description']);
        $this->assertSame('-10-09', substr($rows[3]['date'], 4));
    }

    public function testTheSignNotTheDescriptionDecidesPurchaseOrPayment(): void
    {
        $rows = $this->parse($this->statement())['rows'];
        // "CREDIT" in a supplier's name must not turn a purchase into income.
        $this->assertSame('expense', $rows[1]['type']);
        $this->assertSame(102.5, $rows[1]['amount']);
        // "-$" is a payment.
        $this->assertSame('income', $rows[2]['type']);
        $this->assertSame(500.0, $rows[2]['amount']);
    }

    public function testOpeningBalanceIsRead(): void
    {
        $this->assertSame(1000.0, $this->parse($this->statement())['balance_meta']['opening']);
    }

    public function testFewerThanThreeTdRowsAreLeftAlone(): void
    {
        $m = new ReflectionMethod(BankImportService::class, 'normalizeTdCardRows');
        $m->setAccessible(true);
        $svc = (new ReflectionClass(BankImportService::class))->newInstanceWithoutConstructor();
        $text = "SEP22 SEP23\t\$25.00ACME HARDWARE\nSEP25 SEP26\t\$102.50OTHER\n";
        $this->assertSame($text, $m->invoke($svc, $text));
    }

    public function testBankStatementsAreNotTreatedAsTdCard(): void
    {
        $rows = $this->parse("Jan 05 SAMPLE DEPOSIT CR 100.00\nJan 06 SAMPLE PURCHASE 20.00\nJan 07 SAMPLE FUEL 30.00\n")['rows'];
        $this->assertNotEmpty($rows);
    }
}
