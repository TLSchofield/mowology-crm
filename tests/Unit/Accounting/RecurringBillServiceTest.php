<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny spots monthly bills from the bank lines and watches them.
 */
class RecurringBillServiceTest extends TestCase
{
    private function l(string $d, float $a, string $desc): array
    {
        return ['transaction_date' => $d, 'amount' => $a, 'description' => $desc];
    }

    public function test_the_same_payee_spelled_two_ways_is_one_bill(): void
    {
        $this->assertSame(RecurringBillService::payeeKey('PAYMENT TELUS MOBILITY 4471'), RecurringBillService::payeeKey('PREAUTHORIZEDPAYMENT TELUSMOBILITY'));
    }

    public function test_a_monthly_bill_is_found_with_its_next_date_and_flags(): void
    {
        $rows = [
            $this->l('2026-06-12', 85.10, 'PAYMENT TELUS MOBILITY'), $this->l('2026-07-12', 85.10, 'PREAUTHORIZEDPAYMENT TELUSMOBILITY'),
            $this->l('2026-08-12', 85.10, 'PAYMENT TELUS MOBILITY'), $this->l('2026-09-12', 120.40, 'PAYMENT TELUS MOBILITY'),
            $this->l('2026-07-03', 40, 'POINT OF SALE HUNTERS GARDEN'), $this->l('2026-08-19', 260, 'POINT OF SALE HUNTERS GARDEN'),
            $this->l('2026-09-02', 12, 'POINT OF SALE HUNTERS GARDEN'),
            $this->l('2026-07-01', 500, 'PAYMENT VANCITY VISA'), $this->l('2026-08-01', 500, 'PAYMENT VANCITY VISA'), $this->l('2026-09-01', 500, 'PAYMENT VANCITY VISA'),
        ];
        $b = RecurringBillService::detect($rows, '2026-10-08', '2026-10-05');
        $this->assertCount(1, $b, 'a shop visited monthly is not a bill; a card payment is a transfer');
        $this->assertSame('2026-10-12', $b[0]['next']);
        $this->assertSame(['due', 'changed'], $b[0]['status']);
        $this->assertSame(85.10, $b[0]['usual']);
    }

    public function test_late_only_when_the_statement_covers_the_date(): void
    {
        $rows = [$this->l('2026-05-28', 210, 'PAYMENT FIRST INSURANCE'), $this->l('2026-06-28', 210, 'PAYMENT FIRST INSURANCE'), $this->l('2026-07-28', 210, 'PAYMENT FIRST INSURANCE')];
        $this->assertSame(['late'], RecurringBillService::detect($rows, '2026-09-10', '2026-09-09')[0]['status']);
        $this->assertSame(['not_imported'], RecurringBillService::detect($rows, '2026-09-10', '2026-08-20')[0]['status']);
    }

    public function test_two_missed_months_means_stopped_not_late(): void
    {
        $rows = [$this->l('2026-04-16', 399, 'PREAUTHORIZEDPAYMENT FIRSTINSURANCE'), $this->l('2026-05-16', 399, 'PAYMENT FIRST INSURANCE'), $this->l('2026-06-16', 399, 'PREAUTHORIZEDPAYMENT FIRSTINSURANCE')];
        $b = RecurringBillService::detect($rows, '2026-10-05', '2026-10-01')[0];
        $this->assertSame(['stopped'], $b['status']);
        $this->assertSame('First Insurance', $b['payee'], 'the spelling with spaces is the readable one');
    }
}
