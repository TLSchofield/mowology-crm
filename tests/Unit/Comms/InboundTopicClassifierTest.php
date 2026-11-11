<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Comms/Services/InboundTopicClassifier.php';

/**
 * Which head owns an inbound message. 2026-11-04: Meghan replied to her invoice email
 * ("Re: Invoice INV-2026-0447 from Mowology…") asking to book a fall cleanup; the thread
 * subject outweighed her words and it landed on Penny. On a reply, their words decide.
 */
class InboundTopicClassifierTest extends TestCase
{
    private function head(string $subject, string $body, string $from = 'Client <client@gmail.com>'): string
    {
        return InboundTopicClassifier::classify(['from' => $from, 'subject' => $subject, 'snippet' => $body])['head'];
    }

    public function testBookingReplyToAnInvoiceIsSams(): void
    {
        $this->assertSame('sam', $this->head(
            'Re: Invoice INV-2026-0447 from Mowology: $53.55 due Nov 7, 2026',
            'Hey can we book you guys in for a fall cleanup in the next couple of weeks?'
        ));
    }

    public function testPaymentReplyToAnInvoiceStaysPennys(): void
    {
        $this->assertSame('penny', $this->head(
            'Re: Invoice INV-2026-0482 from Mowology: $378.16 due Nov 30, 2026',
            "I've paid \$378.16 via interactive e-transfer. Please kindly confirm."
        ));
        $this->assertSame('penny', $this->head(
            'RE: Invoice INV-2026-0470 from Mowology',
            'We received follow up regarding this invoice. Kindly advise.'
        ));
    }

    public function testReplyWithNothingTopicalFallsBackToTheThread(): void
    {
        $this->assertSame('penny', $this->head('Re: Invoice INV-2026-0447 from Mowology', 'Thanks!'));
        $this->assertSame('sam', $this->head('Re: Quote QUO-2026-0101 from Mowology', 'Thanks, will look tonight.'));
    }

    public function testANewMessageStillWeighsItsSubject(): void
    {
        // Not a reply: they chose the subject, so it still counts double.
        $this->assertSame('penny', $this->head('Invoice question', 'Can we book a time to talk?'));
    }

    public function testBookingWordsAreSales(): void
    {
        $this->assertSame('sam', $this->head('Hello', 'Could you book us in for a spring clean-up?'));
        // Booking a visit is Otto's, not a sale (Codex review).
        $this->assertSame('otto', $this->head('Hello', 'Can we book a site visit with the crew on Tuesday?'));
        // A job's name is not a request: "fall cleanup" alone in a thread title stays with Yui.
        $this->assertSame('yui', $this->head('Re: Cambridge Apartments: fall cleanup', 'Thanks, the lawn looks great'));
    }

    public function testIsReply(): void
    {
        foreach (['Re: x', 'RE: x', 'Fwd: x', 'FW: x', 'AW: x', 'Re[2]: x', '  re:x'] as $s) {
            $this->assertTrue(InboundTopicClassifier::isReply($s), $s);
        }
        foreach (['Invoice INV-1', 'Regarding the invoice', 'Fwd x', ''] as $s) {
            $this->assertFalse(InboundTopicClassifier::isReply($s), $s);
        }
    }
}
