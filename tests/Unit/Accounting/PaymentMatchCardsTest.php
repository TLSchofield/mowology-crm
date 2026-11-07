<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's likely-payment cards come from the payments matcher's own lines (Tim, 2026-11-06: the "N deposits matched"
 * line and the cards must agree). Only bookable proposals; best first; the facts say what the card shows.
 */
class PaymentMatchCardsTest extends TestCase
{
    private function line(array $o): array
    {
        return array_merge([
            'id' => 1, 'date' => '2026-10-22', 'month' => '2026-10', 'amount' => 100.0, 'description' => 'CHEQUEDEPOSIT-BRANCH',
            'outcome' => 'high', 'confidence' => 'high', 'source' => 'crm', 'action' => 'record_payment', 'targets' => [],
            'why' => 'pays it', 'named' => null, 'note' => null, 'flags' => [], 'blocked' => null, 'locked' => false,
            'signature' => 'abc123',
        ], $o);
    }

    private function target(string $num, float $amt, string $payer = 'Sample Strata', ?string $issued = '2026-10-01'): array
    {
        return ['key' => 'crm_open:' . $num, 'kind' => 'crm_open', 'number' => $num, 'payer' => $payer, 'amount' => $amt,
                'owing' => $amt, 'date' => $issued, 'issued' => $issued, 'year' => '2026'];
    }

    public function testOnlyBookableProposalsBestFirst(): void
    {
        $lines = [
            $this->line(['id' => 1, 'outcome' => 'medium', 'date' => '2026-11-06', 'targets' => [$this->target('INV-2026-0417', 100)]]),
            $this->line(['id' => 2, 'outcome' => 'needs_you', 'action' => 'none']),
            $this->line(['id' => 3, 'outcome' => 'high', 'date' => '2026-10-20', 'targets' => [$this->target('INV-2026-0240', 100)]]),
            $this->line(['id' => 4, 'outcome' => 'high', 'blocked' => 'October is locked', 'targets' => [$this->target('INV-2026-0300', 100)]]),
            $this->line(['id' => 5, 'outcome' => 'high', 'locked' => true, 'targets' => [$this->target('INV-2026-0301', 100)]]),
        ];
        $cards = PaymentMatchService::cardsFromLines($lines);
        $this->assertSame([3, 1], array_column($cards, 'tx_id'));
        $this->assertSame('abc123', $cards[0]['signature']);
    }

    public function testOneDepositSeveralInvoices(): void
    {
        $l = $this->line(['id' => 9, 'amount' => 1370.52, 'description' => 'CHEQUEDEPOSIT-BRANCH', 'outcome' => 'medium',
            'targets' => [$this->target('INV-2026-0431', 500.00), $this->target('INV-2026-0314', 470.52, 'Sample Strata', '2026-09-01'),
                          $this->target('INV-2026-0372', 400.00)]]);
        $c = PaymentMatchService::cardsFromLines([$l])[0];
        $this->assertSame('cheque', $c['kind']);
        $this->assertSame('Cheque deposit', $c['label']);
        $this->assertSame('Sample Strata', $c['payer']);                 // the memo names nobody: the invoices' payer
        $this->assertSame('$1,370.52 on 3 invoices', $c['facts'][0]['invoice']);
        $this->assertSame('same', $c['facts'][0]['state']);
        $this->assertSame('missing', $c['facts'][1]['state']);          // not on the memo
        $this->assertSame('Issued Sep 1', $c['facts'][2]['invoice']);   // the oldest invoice
        $this->assertCount(3, $c['targets']);
    }

    public function testNamedPayerAndETransferKind(): void
    {
        $l = $this->line(['id' => 7, 'amount' => 1890.0, 'description' => 'ETRANSFERCREDIT(JOHNHUGHES)', 'action' => 'link_payments',
            'named' => 'Interac e-Transfer from John Hughes paid INV-2026-0465', 'targets' => [$this->target('INV-2026-0465', 1890.0, 'John Hughes')]]);
        $c = PaymentMatchService::cardsFromLines([$l])[0];
        $this->assertSame('e_transfer', $c['kind']);
        $this->assertSame('same', $c['facts'][1]['state']);
        $this->assertSame(['Interac e-Transfer from John Hughes paid INV-2026-0465'], $c['evidence']);
        $this->assertSame('$1,890.00', $c['facts'][0]['invoice']);
    }
}
