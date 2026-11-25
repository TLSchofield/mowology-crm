<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's read of office@: only customer conversations are kept, and only the new text.
 */
class SalesInboxServiceTest extends TestCase
{
    private const CONTACTS = ['jodi@vanmgmt.ca' => 7, 'ron@example.com' => 9];

    public function test_a_customer_reply_is_inbound_and_matched_to_the_contact(): void
    {
        $c = SalesInboxService::classify('"Jodi Peacock" <Jodi@VanMgmt.ca>', 'Mowology <office@mowology.ca>', self::CONTACTS);
        $this->assertSame('inbound', $c['direction']);
        $this->assertSame(7, $c['contact_id']);
    }

    public function test_the_office_writing_to_a_customer_is_outbound(): void
    {
        $c = SalesInboxService::classify('Tim <office@mowology.ca>', 'someone@else.com, Ron <ron@example.com>', self::CONTACTS);
        $this->assertSame(['direction' => 'outbound', 'contact_id' => 9, 'from' => 'office@mowology.ca', 'to' => 'ron@example.com'], $c);
    }

    public function test_mail_that_is_not_a_customer_conversation_is_never_kept(): void
    {
        $this->assertNull(SalesInboxService::classify('receipts@homedepot.ca', 'office@mowology.ca', self::CONTACTS));
        $this->assertNull(SalesInboxService::classify('notify@payments.interac.ca', 'office@mowology.ca', self::CONTACTS));
        $this->assertNull(SalesInboxService::classify('office@mowology.ca', 'supplier@stihl.ca', self::CONTACTS));
        $this->assertNull(SalesInboxService::classify('', 'office@mowology.ca', self::CONTACTS));
    }

    public function test_only_the_new_text_is_kept(): void
    {
        $body = "Yes please, go ahead with all five.\nCan you start Monday?\n\nSent from my iPhone\n\nOn Mon, Oct 5, 2026 at 9:00 AM Mowology <office@mowology.ca> wrote:\n> Hi Jodi,\n> Following up";
        $this->assertSame("Yes please, go ahead with all five.\nCan you start Monday?", SalesInboxService::snippet($body));
        $this->assertSame('Sounds good', SalesInboxService::snippet("<div>Sounds good</div>\n-----Original Message-----\nFrom: x"));
    }

    public function test_long_messages_are_cut_short(): void
    {
        $s = SalesInboxService::snippet(str_repeat('word ', 400));
        $this->assertLessThanOrEqual(SalesInboxService::SNIPPET_MAX, mb_strlen($s));
        $this->assertStringEndsWith('…', $s);
    }
}
