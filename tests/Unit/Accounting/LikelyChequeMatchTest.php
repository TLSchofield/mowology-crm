<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Likely cheque matches (Tim, 2026-11-06): the invoices list chip and Penny's card name a cheque,
 * a cheque never gets the card-fee allowance, and one exact cheque isn't also a "partial amount"
 * of the same payer's other invoices. Numbers from production that day.
 */
class LikelyChequeMatchTest extends TestCase
{
    private function svc(): InvoiceReconciliationService
    {
        return new InvoiceReconciliationService($this->createMock(PDO::class));
    }

    private function invoice(array $o = []): array
    {
        return array_merge([
            'id' => 430, 'invoice_number' => 'INV-2026-0430', 'balance_due' => 1235.44, 'total' => 1235.44,
            'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31', 'company_name' => 'VCHA : Little Mountain Residential Care',
            'contact_name' => '', 'property_name' => '', 'bill_to_name' => '',
        ], $o);
    }

    public function testChequeShortByAFewPercentIsNotNetOfFee(): void
    {
        $dep = ['id' => 100245, 'transaction_date' => '2026-11-02', 'amount' => 1195.36,
                'description' => 'Cheque deposit-branch VANCOUVER MANAGEMENT LTD'];
        $this->assertNull($this->svc()->scoreDeposit($dep, $this->invoice()));
    }

    public function testETransferShortByAFewPercentIsNotNetOfFee(): void
    {
        $dep = ['id' => 1, 'transaction_date' => '2026-11-02', 'amount' => 378.16,
                'description' => 'e-Transfer credit Ref 202611021847358178'];
        $this->assertNull($this->svc()->scoreDeposit($dep, $this->invoice(['balance_due' => 399.00, 'total' => 399.00,
            'invoice_date' => '2026-11-01', 'due_date' => '2026-12-01'])));
    }

    public function testStripePayoutStillMatchesNetOfFee(): void
    {
        // Strata 3775: $168.87 by card, Stripe keeps 2.9% + 30¢ = $5.20.
        $dep = ['id' => 2, 'transaction_date' => '2026-11-02', 'amount' => 163.67, 'description' => 'Preauthorized credit STRIPE'];
        $s = $this->svc()->scoreDeposit($dep, $this->invoice(['balance_due' => 168.87, 'total' => 168.87,
            'invoice_date' => '2026-11-01', 'due_date' => '2026-12-01']));
        $this->assertNotNull($s);
        $this->assertContains('Amount matches (net of fee)', $s['reasons']);
    }

    public function testDepositKindsAndLabels(): void
    {
        $this->assertSame('cheque', InvoiceReconciliationService::depositKind('CHEQUEDEPOSIT-BRANCH(VANCOUVERCOASTALHEALTH)'));
        $this->assertSame('cheque', InvoiceReconciliationService::depositKind('MOBILE DEPOSIT'));
        $this->assertSame('stripe', InvoiceReconciliationService::depositKind('Preauthorized credit STRIPE'));
        $this->assertSame('eft', InvoiceReconciliationService::depositKind('Preauthorized credit DORSET REALTY GROUP'));
        $this->assertSame('Cheque deposit', InvoiceReconciliationService::kindLabel('cheque'));
        $this->assertFalse(InvoiceReconciliationService::mayCarryFee('Cheque deposit-branch'));
        $this->assertTrue(InvoiceReconciliationService::mayCarryFee('Preauthorized credit STRIPE'));
    }

    public function testChipNamesACheque(): void
    {
        $this->assertSame('Likely cheque', InvoiceReconciliationService::chipText([['kind' => 'cheque']]));
        $this->assertSame('Likely cheque +1', InvoiceReconciliationService::chipText([['kind' => 'cheque'], ['kind' => 'stripe']]));
        $this->assertSame('1 likely match', InvoiceReconciliationService::chipText([['kind' => 'stripe']]));
        $this->assertSame('2 likely matches', InvoiceReconciliationService::chipText([['kind' => 'eft'], ['kind' => 'cheque']]));
        $this->assertSame('', InvoiceReconciliationService::chipText([]));
    }

    public function testExactChequeIsNotAlsoAPartialOfOtherInvoices(): void
    {
        $exact   = ['tx_id' => 100245, 'reasons' => ['Exact amount', 'Memo names VANCOUVER MANAGEMENT LTD'], 'confidence' => 95];
        $exact2  = ['tx_id' => 100245, 'reasons' => ['Exact amount', 'Memo names VANCOUVER MANAGEMENT LTD'], 'confidence' => 89];
        $partial = ['tx_id' => 100245, 'reasons' => ['Partial amount', 'Memo names VANCOUVER MANAGEMENT LTD'], 'confidence' => 61];
        $other   = ['tx_id' => 7, 'reasons' => ['Amount matches (net of fee)'], 'confidence' => 62];
        $out = InvoiceReconciliationService::dropShadowHits([477 => [$exact], 415 => [$exact2], 476 => [$partial], 491 => [$other]]);
        $this->assertSame([477, 415, 491], array_keys($out));
    }

    public function testChequeCardNamesBothSameAmountInvoicesAndPicksTheBest(): void
    {
        $hit = fn(int $conf, array $reasons) => [
            'tx_id' => 100245, 'date' => '2026-11-02', 'description' => 'Cheque deposit-branch VANCOUVER MANAGEMENT LTD',
            'kind' => 'cheque', 'label' => 'Cheque deposit', 'payer' => 'VANCOUVER MANAGEMENT LTD', 'amount' => 1195.36,
            'suggested_amount' => 1195.36, 'confidence' => $conf, 'reasons' => $reasons,
        ];
        $map = [
            415 => [$hit(89, ['Exact amount', 'Memo names VANCOUVER MANAGEMENT LTD'])],
            477 => [$hit(95, ['Exact amount', 'Memo names VANCOUVER MANAGEMENT LTD'])],
            491 => [['tx_id' => 9, 'kind' => 'stripe'] + $hit(62, ['Amount matches (net of fee)'])],
        ];
        $vml = ['company_name' => 'VANCOUVER MANAGEMENT LTD', 'balance_due' => 1195.36, 'invoice_date' => '2026-10-01'];
        $info = [
            415 => $this->invoice(['id' => 415, 'invoice_number' => 'INV-2026-0415'] + $vml),
            477 => $this->invoice(['id' => 477, 'invoice_number' => 'INV-2026-0477', 'invoice_date' => '2026-11-01'] + $vml),
            491 => $this->invoice(['id' => 491, 'invoice_number' => 'INV-2026-0491']),
        ];
        $cards = InvoiceReconciliationService::chequeCards($map, $info);
        $this->assertCount(1, $cards);                       // the Stripe payout is not a cheque
        $c = $cards[0];
        $this->assertSame('INV-2026-0477', $c['invoice']['number']);
        $this->assertSame('This $1,195.36 cheque from VANCOUVER MANAGEMENT LTD likely pays INV-2026-0477.', $c['verdict']);
        $this->assertStringContainsString('INV-2026-0415 is the same amount', $c['confidence_line']);
        $this->assertSame('same', $c['facts'][0]['state']);
        $this->assertSame('same', $c['facts'][1]['state']);
        $this->assertSame(415, $c['others'][0]['invoice_id']);
    }
}
