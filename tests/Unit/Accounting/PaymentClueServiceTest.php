<?php
use PHPUnit\Framework\TestCase;

final class PaymentClueServiceTest extends TestCase
{
    public function testPaymentWordsAreRecognisedAndSmallTalkIsNot(): void
    {
        foreach (['Just sent you the e-Transfer', 'Sent $65 for the lawn', 'Interac on its way', 'Hi Tim. Just sent you two Interac payments', 'payment sent!', 'cheque is in the mailbox'] as $t) {
            $this->assertTrue(PaymentClueService::isPaymentText($t), $t);
        }
        foreach (['Can you come Tuesday?', 'The hedge looks great', 'Me and my British self after today'] as $t) {
            $this->assertFalse(PaymentClueService::isPaymentText($t), $t);
        }
    }

    public function testOneAmountIsReadAndTwoAreNot(): void
    {
        $this->assertSame(65.0, PaymentClueService::amountIn('Sent $65 for the lawn'));
        $this->assertSame(1050.95, PaymentClueService::amountIn('e-transfer of $1,050.95 sent'));
        $this->assertNull(PaymentClueService::amountIn('sent you the e-transfer'));
        $this->assertNull(PaymentClueService::amountIn('$63 for the cut and $2 tip, sent'));
    }

    public function testAnExactAmountWinsAndTwoVagueClientsAreAGuess(): void
    {
        $texts = [
            ['contact_id' => 1472, 'name' => 'Joseph Gallentine', 'sent_at' => '2026-09-17 18:30:00', 'snippet' => 'Sent you $65 e-transfer, thanks!'],
            ['contact_id' => 30,   'name' => 'Alex Wang',         'sent_at' => '2026-09-16 09:00:00', 'snippet' => 'I sent the payment'],
        ];
        $c = PaymentClueService::pick($texts, 65.00);
        $this->assertSame(1472, $c['contact_id']);
        $this->assertTrue($c['exact']);

        $vague = [
            ['contact_id' => 1, 'name' => 'A', 'sent_at' => '2026-09-16', 'snippet' => 'sent the e-transfer'],
            ['contact_id' => 2, 'name' => 'B', 'sent_at' => '2026-09-16', 'snippet' => 'interac sent'],
        ];
        $this->assertNull(PaymentClueService::pick($vague, 65.00), 'two clients, no amount: a guess');
        $this->assertSame(1, PaymentClueService::pick([$vague[0]], 65.00)['contact_id'], 'one vague client is a weak clue');
        $this->assertNull(PaymentClueService::pick([['contact_id' => 3, 'name' => 'C', 'sent_at' => '2026-09-16', 'snippet' => 'Sent $40 e-transfer']], 65.00), 'a different amount is not this deposit');
    }
}
