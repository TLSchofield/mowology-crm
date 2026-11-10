<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PennyJobAdapter::fromTransfer — a pending e-Transfer (listPending() row + EtransferDeskService::read()) becomes the
 * job strip's shape: the "2/3" fraction, the label naming the match, the e-Transfer above the rail and the invoice(s)
 * below it, and Record payment carrying the exact form fields the Invoices card posts to etransfer-confirm.php.
 */
class PennyJobAdapterEtransferTest extends TestCase
{
    private function row(array $over = []): array
    {
        return $over + [
            'id' => 512, 'sender_name' => 'John Hughes', 'amount' => '1890.00', 'allocated_amount' => '0.00',
            'memo' => 'INV-2026-0465 thanks', 'email_date' => '2026-11-03 14:14:00', 'transfer_type' => 'autodeposit',
            'match_method' => 'invoice_number', 'match_confidence' => 'high', 'matched_invoice_number' => 'INV-2026-0465',
            'bank_transaction_id' => 77, 'bank_transaction_date' => '2026-11-04', 'likely_deposit_date' => null,
        ];
    }

    private function read(array $over = []): array
    {
        return $over + [
            'id' => 512, 'active' => 1890.0, 'kind' => 'match', 'confirmed' => 3,
            'lines' => [['invoice' => 'INV-2026-0465', 'amount' => 1890.0]],
            'say' => 'John Hughes paid $1,890.00 for INV-2026-0465 — the invoice number is on the transfer. Record it?',
        ];
    }

    private function facts(): array
    {
        return ['INV-2026-0465' => ['invoice_number' => 'INV-2026-0465', 'invoice_date' => '2026-10-20', 'balance_due' => 1890.0, 'payer_name' => 'John Hughes']];
    }

    public function testSingleInvoiceFullyInHand(): void
    {
        $j = PennyJobAdapter::fromTransfer($this->row(), $this->read(), $this->facts(), 2, 3);
        $this->assertSame('penny', $j['head']);
        $this->assertSame('etransfer', $j['kind']);
        $this->assertSame('et:512', $j['key']);
        $this->assertSame(['matched' => 3, 'total' => 3], $j['pieces']);
        $this->assertSame('1 invoice + payment', $j['label']);
        $this->assertSame('earned', $j['sure'], 'the invoice number is on the transfer: solid');
        $this->assertSame([2, 3], [$j['index'], $j['total']]);
        $this->assertSame('e-Transfer', $j['left']['label']);
        $this->assertSame('John Hughes', $j['left']['who']);
        $this->assertSame('Interac email Nov 3, 2:14 pm', $j['left']['when']);
        $this->assertSame('INV-2026-0465', $j['right']['label']);
        $this->assertSame('John Hughes', $j['right']['who']);
        $this->assertSame('Bank', $j['start']['label']);
        $this->assertSame('/crm/ledger/', $j['anchor']['href']);
        $this->assertNull($j['undo'], 'the card offers no undo for a recorded payment, so neither does the strip');
    }

    public function testColumnsCompareTheTwoSides(): void
    {
        $cols = PennyJobAdapter::fromTransfer($this->row(), $this->read(), $this->facts(), 1, 1)['columns'];
        $this->assertSame(['Amount', 'Payer', 'Dates', 'Memo'], array_column($cols, 'label'));
        $this->assertSame(['label' => 'Amount', 'top' => '$1,890.00', 'bottom' => '$1,890.00 owing', 'state' => 'same'], $cols[0]);
        $this->assertSame('same', $cols[1]['state']);
        $this->assertSame(['label' => 'Dates', 'top' => 'Banked Nov 4', 'bottom' => 'Issued Oct 20', 'state' => 'info'], $cols[2]);
        $this->assertSame(['label' => 'Memo', 'top' => 'INV-2026-0465 thanks', 'bottom' => 'Invoice 0465', 'state' => 'same'], $cols[3]);
    }

    /** Record payment is the card's own request: action=record, notification_id, invoice_numbers[], amounts[] to etransfer-confirm.php. */
    public function testRecordPaymentIsTheCardsExactRequest(): void
    {
        $j = PennyJobAdapter::fromTransfer($this->row(), $this->read(), $this->facts(), 1, 1);
        $this->assertSame('/crm/api/etransfer-confirm.php', $j['api']);
        $this->assertSame('form', $j['transport']);
        $this->assertSame('Record payment', $j['primary']['label']);
        $this->assertSame('INV-2026-0465', $j['primary']['sub'], 'the amount is already on the rail');
        $this->assertSame(['action' => 'record', 'notification_id' => 512, 'invoice_numbers' => ['INV-2026-0465'], 'amounts' => ['1890.00']], $j['primary']['request']);
        $this->assertSame('Not this invoice', $j['secondary']['label']);
        $this->assertSame(['mode' => 'pick', 'notification_id' => 512, 'href' => '/crm/invoices/#etransfers'], $j['secondary']['request']);
    }

    public function testSixInvoicesNamedInTheMemo(): void
    {
        $lines = []; $facts = []; $nums = [];
        foreach ([65, 66, 118, 440, 441, 442] as $i => $n) {
            $no = 'INV-2026-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $nums[] = $no;
            $lines[] = ['invoice' => $no, 'amount' => 58.21];
            $facts[$no] = ['invoice_number' => $no, 'invoice_date' => '2026-0' . (3 + (int)($i / 2)) . '-10', 'balance_due' => 58.21, 'payer_name' => 'Alison Birdsall'];
        }
        $row = $this->row(['sender_name' => 'STRATA PLAN BCS-2106', 'amount' => '349.26', 'memo' => 'invoices 0065, 0066, 0118, 0440, 0441 and 0442', 'match_method' => null, 'match_confidence' => 'none', 'matched_invoice_number' => null, 'bank_transaction_id' => null, 'bank_transaction_date' => null, 'likely_deposit_date' => '2026-11-05']);
        $read = $this->read(['active' => 349.26, 'kind' => 'named', 'confirmed' => 2, 'lines' => $lines, 'say' => 'named']);
        $j = PennyJobAdapter::fromTransfer($row, $read, $facts, 1, 1);
        $this->assertSame(['matched' => 2, 'total' => 3], $j['pieces']);
        $this->assertSame('6 invoices + payment', $j['label']);
        $this->assertSame('earned', $j['sure']);
        $this->assertSame('6 invoices', $j['right']['label']);
        $this->assertSame('Alison Birdsall', $j['right']['who']);
        $cols = $j['columns'];
        $this->assertSame(['$349.26', '$349.26 owing', 'same'], [$cols[0]['top'], $cols[0]['bottom'], $cols[0]['state']]);
        $this->assertSame('differs', $cols[1]['state'], 'the strata sent it; the invoices are for the owner');
        $this->assertSame(['Likely Nov 5', 'Issued Mar 10 – May 10'], [$cols[2]['top'], $cols[2]['bottom']]);
        $this->assertSame('Invoices 0065, 0066 +4', $cols[3]['bottom']);
        $this->assertSame($nums, $j['primary']['request']['invoice_numbers']);
        $this->assertSame(array_fill(0, 6, '58.21'), $j['primary']['request']['amounts']);
        $this->assertSame('6 invoices', $j['primary']['sub']);
    }

    public function testAmountDiffersAndAGuessIsHeld(): void
    {
        $row = $this->row(['amount' => '1800.00', 'memo' => '', 'match_method' => 'sender_name', 'match_confidence' => 'medium', 'bank_transaction_id' => null, 'bank_transaction_date' => null]);
        $read = $this->read(['active' => 1800.0, 'kind' => 'likely', 'confirmed' => 1, 'lines' => [['invoice' => 'INV-2026-0465', 'amount' => 1800.0]]]);
        $j = PennyJobAdapter::fromTransfer($row, $read, $this->facts(), 1, 1);
        $this->assertSame(['matched' => 1, 'total' => 3], $j['pieces']);
        $this->assertSame('held', $j['sure']);
        $cols = $j['columns'];
        $this->assertSame(['$1,800.00', '$1,890.00 owing', 'differs'], [$cols[0]['top'], $cols[0]['bottom'], $cols[0]['state']]);
        $this->assertSame(['Not banked yet', 'info'], [$cols[2]['top'], $cols[2]['state']]);
        $this->assertSame(['No memo', 'Invoice 0465', 'info'], [$cols[3]['top'], $cols[3]['bottom'], $cols[3]['state']]);
        $this->assertSame(['1800.00'], $j['primary']['request']['amounts']);
    }

    public function testUnknownInvoiceFactsStayInfo(): void
    {
        $j = PennyJobAdapter::fromTransfer($this->row(), $this->read(), [], 1, 1);
        $this->assertSame(['–', 'info'], [$j['columns'][0]['bottom'], $j['columns'][0]['state']]);
        $this->assertSame(['–', 'info'], [$j['columns'][1]['bottom'], $j['columns'][1]['state']]);
        $this->assertSame('', $j['right']['who']);
    }

    /** The strip offers Record only with a proposal: a duplicate, a leftover or a blank reading stays on the card. */
    public function testOnlyProposalsBecomeJobs(): void
    {
        $this->assertTrue(PennyJobAdapter::proposes($this->read()));
        $this->assertFalse(PennyJobAdapter::proposes($this->read(['kind' => 'duplicate'])));
        $this->assertFalse(PennyJobAdapter::proposes($this->read(['kind' => 'leftover', 'lines' => []])));
        $this->assertFalse(PennyJobAdapter::proposes($this->read(['kind' => 'none', 'lines' => []])));
        $this->assertFalse(PennyJobAdapter::proposes($this->read(['lines' => []])));
        $this->assertFalse(PennyJobAdapter::proposes($this->read(['kind' => 'named_check'])), 'memo names invoices that do not add up or are unclear: the card');
        $this->assertFalse(PennyJobAdapter::proposes($this->read(['credit' => ['amount' => 50.0, 'with_record' => true, 'already_paid' => []]])), 'more than the invoice owes: the card offers the credit');
        $this->assertTrue(PennyJobAdapter::proposes($this->read(['credit' => ['amount' => 50.0, 'with_record' => false, 'already_paid' => []]])), 'a part-recorded leftover credit does not block a proposal with lines');
        $this->assertTrue(PennyJobAdapter::proposes($this->read(['kind' => 'likely'])));
    }

    public function testSureOfMatch(): void
    {
        $this->assertSame('earned', PennyJobAdapter::sureOfMatch('match'));
        $this->assertSame('held', PennyJobAdapter::sureOfMatch('spread'));
        $this->assertSame('held', PennyJobAdapter::sureOfMatch('named_check'));
        $this->assertSame('forming', PennyJobAdapter::sureOfMatch('none'));
    }
}
