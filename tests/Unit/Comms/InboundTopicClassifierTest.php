<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Inbound customer mail goes to the head who owns it (2026-10-08: Vancouver Management Ltd's
 * "EFT Direct deposit form" showed up under Sam on the Team tab — it is Penny's).
 */
class InboundTopicClassifierTest extends TestCase
{
    public const VML_SUBJECT = 'EFT Direct deposit form';
    public const VML_BODY = "Hello,\n\nVancouver Management Ltd. offers direct deposit payments to your bank account. "
        . "Please complete, sign and submit the attached form. Once complete, please email it to vidhya@vml.ca.\n\nThank you,\nAlena Radosovska";

    private static function c(string $subject, string $body, string $from = 'person@example.com', array $learned = []): array
    {
        return InboundTopicClassifier::classify(['from' => $from, 'subject' => $subject, 'snippet' => $body], $learned);
    }

    public function test_the_vml_direct_deposit_email_is_pennys(): void
    {
        $d = self::c(self::VML_SUBJECT, self::VML_BODY, 'Alena Radosovska <alena@vml.ca>');
        $this->assertSame('penny', $d['head']);
        $this->assertSame('billing', $d['topic']);
        $this->assertSame('rule', $d['source']);
        $this->assertSame(0, $d['scores']['sales']);
        $this->assertStringContainsString('direct deposit', $d['reason']);
    }

    /** @dataProvider billingSenders */
    public function test_a_billing_department_sender_is_pennys(string $from): void
    {
        $d = self::c('Documents', 'Please see attached. Thanks!', $from);
        $this->assertSame('penny', $d['head'], $from);
    }

    public static function billingSenders(): array
    {
        return array_map(fn($f) => [$f], [
            'accounts@quaypacific.com',
            'AP@firstservice.ca',
            'payables@strata.ca',
            'billing@acme.ca',
            'invoices@acme.ca',
            'accounts.payable@acme.ca',
            '"Accounts Payable" <langleyap99@quaypacific.com>',
        ]);
    }

    public function test_a_quote_request_stays_with_sam(): void
    {
        $this->assertSame('sam', self::c('Spring cleanup', 'Hi Tim, could you send us a quote for spring cleanup at 4180 Oak Ct? Thanks')['head']);
        $this->assertSame('sam', self::c('Re: Your quote QUO-2026-0144', 'Looks good. Is there a deposit, and when can you start?')['head']);
        $this->assertSame('sam', self::c('Hedges', 'How much would it cost to trim the hedges along the back?')['head']);
        // A clear short yes to work is a sale (Gaby's "Yes" to a fall cleanup).
        $this->assertSame('sam', self::c('Re: Cambridge Apartments: fall cleanup', 'Yes')['head']);
    }

    public function test_billing_words_with_a_quote_named_still_follow_the_weight(): void
    {
        // An invoice question that mentions the quote it came from: money wins on the subject.
        $this->assertSame('penny', self::c('Invoice INV-2026-0358 overdue?', 'We paid this last week per your quote — can you check?')['head']);
    }

    public function test_site_issues_are_ottos_reviews_are_mias_the_rest_is_yuis(): void
    {
        $this->assertSame('otto', self::c('Gate code', 'The gate code changed to 4411')['head']);
        $this->assertSame('otto', self::c('Re: mowing', "The crew didn't come on Tuesday — can you reschedule?")['head']);
        $this->assertSame('mia', self::c('Review', 'I left you a Google review!')['head']);
        $this->assertSame('yui', self::c('Re: hello', 'Thanks Tim, have a great weekend')['head']);
    }

    public function test_lowercase_ap_or_check_never_makes_it_billing(): void
    {
        $this->assertSame('yui', self::c('Re: hello', 'I will check with the council and snap a photo')['head']);
    }

    public function test_a_learned_rule_wins_address_first_then_domain(): void
    {
        $learned = ['@vml.ca|billing' => 'yui', 'alena@vml.ca|billing' => 'penny'];
        $d = self::c(self::VML_SUBJECT, self::VML_BODY, 'alena@vml.ca', $learned);
        $this->assertSame(['penny', 'learned'], [$d['head'], $d['source']]);
        $d = self::c(self::VML_SUBJECT, self::VML_BODY, 'vidhya@vml.ca', $learned);
        $this->assertSame(['yui', 'learned'], [$d['head'], $d['source']]);
        // Same sender, other topic: the rule doesn't apply.
        $this->assertSame('sam', self::c('Quote please', 'Could you quote the spring cleanup?', 'alena@vml.ca', $learned)['head']);
    }

    public function test_free_mail_is_never_learned_as_a_whole_domain(): void
    {
        $this->assertSame(['bob@gmail.com'], InboundTopicClassifier::senderKeys('bob@gmail.com'));
        $this->assertSame(['alena@vml.ca', '@vml.ca'], InboundTopicClassifier::senderKeys('Alena@VML.ca'));
        $this->assertSame([], InboundTopicClassifier::senderKeys(''));
    }
}
