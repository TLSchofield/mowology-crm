<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Joined mail: an email from someone who isn't a contact joins a quote's conversation by what
 * it is about. The case that started it (2026-10-10): Monica at Macdonald PM wrote "council
 * approved" about Linda's QUO-2026-0073 and Sam never saw it.
 */
class SalesInboxJoinTest extends TestCase
{
    private function join(): array
    {
        return [
            'quotes' => [
                ['id' => 99, 'number' => 'QUO-2026-0080', 'contact_id' => 12, 'address_key' => SalesInboxService::addressKey('1003 Wolfe Avenue')],
                ['id' => 73, 'number' => 'QUO-2026-0073', 'contact_id' => 41, 'address_key' => SalesInboxService::addressKey('1685 West 14th Avenue')],
                ['id' => 60, 'number' => 'QUO-2026-0060', 'contact_id' => 41, 'address_key' => SalesInboxService::addressKey('1685 West 14th Avenue')],
            ],
            'domains' => ['macdonaldpm.com' => 41],
        ];
    }

    private function contacts(): array
    {
        return ['linda@macdonaldpm.com' => 41, 'kim@dorset.ca' => 12];
    }

    public function test_a_reply_naming_the_quote_number_joins_that_quote(): void
    {
        $c = SalesInboxService::classifyAny('Monica Nicule <mnicule@othermail.ca>', 'office@mowology.ca',
            'Re: Your quote from Mowology: QUO-2026-0073', $this->contacts(), $this->join());
        $this->assertSame('inbound', $c['direction']);
        $this->assertSame(41, $c['contact_id']);
        $this->assertSame(73, $c['quote_id']);
        $this->assertSame('quote_number', $c['joined_by']);
    }

    public function test_a_reply_starting_with_the_property_address_joins_the_newest_quote_there(): void
    {
        $c = SalesInboxService::classifyAny('someone@strata-council.org', 'office@mowology.ca',
            'RE: 1685 W 14th Ave - your quote', $this->contacts(), $this->join());
        $this->assertSame(73, $c['quote_id']);
        $this->assertSame('address', $c['joined_by']);
    }

    public function test_a_colleague_at_the_same_company_joins_the_contact(): void
    {
        $c = SalesInboxService::classifyAny('Monica Nicule <mnicule@macdonaldpm.com>', 'office@mowology.ca',
            'Council decision', $this->contacts(), $this->join());
        $this->assertSame(41, $c['contact_id']);
        $this->assertSame(73, $c['quote_id']);
        $this->assertSame('domain', $c['joined_by']);
    }

    public function test_a_colleague_of_a_contact_with_no_quote_is_not_joined(): void
    {
        $join = $this->join();
        $join['domains']['macdonaldpm.com'] = 1514;   // "Valued Customer" placeholder, no quotes
        $this->assertNull(SalesInboxService::classifyAny('Monica Nicule <mnicule@macdonaldpm.com>', 'office@mowology.ca',
            'Town Villa quote', $this->contacts(), $join));
    }

    public function test_the_buildings_property_manager_joins_the_strata_reps_open_quote(): void
    {
        $join = $this->join();
        $join['pm'][77] = [['id' => 73, 'number' => 'QUO-2026-0073', 'contact_id' => 41, 'address_key' => SalesInboxService::addressKey('1685 West 14th Avenue')]];
        $contacts = $this->contacts() + ['mnicule@macdonaldpm.com' => 77];
        $c = SalesInboxService::classifyAny('Monica Nicule <mnicule@macdonaldpm.com>', 'office@mowology.ca', 'Town Villa quote', $contacts, $join);
        $this->assertSame(41, $c['contact_id']);
        $this->assertSame(73, $c['quote_id']);
        $this->assertSame('manager', $c['joined_by']);
    }

    public function test_a_manager_of_several_buildings_needs_the_subject_to_say_which(): void
    {
        $join = $this->join();
        $join['pm'][77] = [
            ['id' => 80, 'number' => 'QUO-2026-0080', 'contact_id' => 12, 'address_key' => SalesInboxService::addressKey('1003 Wolfe Avenue')],
            ['id' => 73, 'number' => 'QUO-2026-0073', 'contact_id' => 41, 'address_key' => SalesInboxService::addressKey('1685 West 14th Avenue')],
        ];
        $contacts = $this->contacts() + ['pm@firm.ca' => 77];
        $this->assertSame(77, SalesInboxService::classifyAny('pm@firm.ca', 'office@mowology.ca', 'Hello', $contacts, $join)['contact_id']);
        $this->assertSame(73, SalesInboxService::classifyAny('pm@firm.ca', 'office@mowology.ca', 'Re: 1685 W 14th Ave', $contacts, $join)['quote_id']);
    }

    public function test_free_mail_domains_never_join(): void
    {
        $join = $this->join();
        $join['domains']['gmail.com'] = 41;
        $this->assertNull(SalesInboxService::classifyAny('stranger@gmail.com', 'office@mowology.ca', 'Hello', $this->contacts(), $join));
    }

    public function test_unrelated_mail_is_still_skipped(): void
    {
        $this->assertNull(SalesInboxService::classifyAny('sales@supplier.com', 'office@mowology.ca', 'Invoice 4411', $this->contacts(), $this->join()));
    }

    public function test_a_contacts_own_mail_is_unchanged(): void
    {
        $c = SalesInboxService::classifyAny('linda@macdonaldpm.com', 'office@mowology.ca', 'QUO-2026-0080', $this->contacts(), $this->join());
        $this->assertSame(41, $c['contact_id']);
        $this->assertArrayNotHasKey('joined_by', $c);
    }

    public function test_our_reply_to_the_non_contact_joins_too(): void
    {
        $c = SalesInboxService::classifyAny('office@mowology.ca', 'Monica <mnicule@othermail.ca>',
            'Re: QUO-2026-0073', $this->contacts(), $this->join());
        $this->assertSame('outbound', $c['direction']);
        $this->assertSame(41, $c['contact_id']);
    }

    public function test_address_keys(): void
    {
        $this->assertSame('1685 w 14 ave', SalesInboxService::addressKey('1685 West 14th Avenue'));
        $this->assertSame('', SalesInboxService::addressKey('Vancouver'));
        $this->assertSame('', SalesInboxService::addressKey('14th Avenue'));
        // a longer street number never matches a shorter one
        $c = SalesInboxService::classifyAny('x@y.org', 'office@mowology.ca', '11685 W 14th Ave', $this->contacts(), $this->join());
        $this->assertNull($c);
    }

    public function test_sender_names(): void
    {
        $this->assertSame('Monica Nicule', SalesInboxService::senderName('"Monica Nicule" <mnicule@macdonaldpm.com>'));
        $this->assertSame('Monica Nicule', SalesInboxService::senderName('Nicule, Monica <mnicule@macdonaldpm.com>'));
        $this->assertSame('', SalesInboxService::senderName('mnicule@macdonaldpm.com'));
    }
}
