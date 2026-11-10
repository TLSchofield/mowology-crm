<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Who is this email about, when the sender is not on file? The case that started it (2026-11-09):
 * a paralegal wrote to office@ asking the total owing for a numbered company at a street address.
 * Her address was not a contact, so the mail was dropped and nobody was told.
 */
class InboundIdentityServiceTest extends TestCase
{
    private const BODY = "Hi there,\n\nCan you lease give me a total amount owing for our account\n\n"
        . "1355183 B.C. Ltd.\n1626 West 10th Ave\nVancouver\n\n\nThank you! I want to catch up as I know I got behind.\n\n"
        . "Candace Sevigny\nParalegal\nOffice: 604 699 0110 | Direct: 778 870 7159\nEmail: csevigny@lotzandco.com\n"
        . "LOTZ & COMPANY\n320 Granville Street, Suite 880, Vancouver, BC V6C 1S9\nVancouver | Langley\n"
        . "Lotz & Company provides its legal services through a law corporation, Lotz Law Corporation.";

    private const FROM = 'Candace Sevigny <csevigny@lotzandco.com>';

    private function index(array $extra = []): array
    {
        return array_merge([
            ['client_id' => 2068, 'type' => 'business', 'name' => '1355183 BC LTD', 'contact_id' => 1439,
             'addresses' => ['1626 West 10th Avenue'], 'domains' => ['lotzandco.com']],
            ['client_id' => 3495, 'type' => 'individual', 'name' => 'Sandra Bertoia', 'contact_id' => 1439,
             'addresses' => [], 'domains' => ['lotzandco.com']],
            ['client_id' => 50, 'type' => 'business', 'name' => 'Dorset Strata', 'contact_id' => 12,
             'addresses' => ['500 Oak Street'], 'domains' => ['dorset.ca']],
        ], $extra);
    }

    private function identify(string $from, string $subject, string $body, ?array $index = null): array
    {
        return InboundIdentityService::identify(['from' => $from, 'subject' => $subject, 'body' => $body], $index ?? $this->index());
    }

    public function test_the_amount_owing_email_lands_on_the_numbered_company(): void
    {
        $r = $this->identify(self::FROM, 'Amount Owing', self::BODY);
        $this->assertSame('strong', $r['strength']);
        $this->assertSame(2068, $r['client_id']);
        $this->assertSame(1439, $r['contact_id']);
        $this->assertContains('company', $r['evidence']);
        $this->assertContains('address', $r['evidence']);
    }

    public function test_company_names_compare_without_punctuation(): void
    {
        $this->assertSame(InboundIdentityService::normaliseCompany('1355183 BC LTD'),
                          InboundIdentityService::normaliseCompany('1355183 B.C. Ltd.'));
    }

    public function test_the_law_firm_in_the_footer_is_not_the_account(): void
    {
        $firm = ['client_id' => 77, 'type' => 'business', 'name' => 'Lotz & Company', 'contact_id' => 70,
                 'addresses' => ['320 Granville Street'], 'domains' => []];
        $r = $this->identify(self::FROM, 'Amount Owing', self::BODY, $this->index([$firm]));
        $this->assertSame(2068, $r['client_id']);
        $this->assertNotContains(77, array_column($r['candidates'], 'client_id'));
    }

    public function test_a_person_named_in_the_text_is_not_company_evidence(): void
    {
        $r = $this->identify('A Stranger <a@elsewhere.ca>', 'Hello', "I spoke to Sandra Bertoia about a thing last week.\nPlease call me.");
        $this->assertSame('none', $r['strength']);
    }

    public function test_a_digit_run_inside_a_longer_number_does_not_match(): void
    {
        $r = $this->identify('A Stranger <a@elsewhere.ca>', 'Hello', "Regarding 91355183 BC Ltd please advise.\nThanks for your help today.");
        $this->assertSame('none', $r['strength']);
    }

    public function test_a_generic_name_is_never_enough(): void
    {
        $generic = ['client_id' => 8, 'type' => 'business', 'name' => 'Strata', 'contact_id' => 8, 'addresses' => [], 'domains' => []];
        $r = $this->identify('A Stranger <a@elsewhere.ca>', 'Our strata', "Our strata needs a landscaper this spring.\nCan you help?", $this->index([$generic]));
        $this->assertSame('none', $r['strength']);
    }

    public function test_address_alone_is_a_question_not_a_placement(): void
    {
        $r = $this->identify('A Neighbour <n@elsewhere.ca>', 'Hedge', "The hedge at 500 Oak Street is over my fence.\nPlease trim it soon.");
        $this->assertSame('medium', $r['strength']);
        $this->assertSame(50, $r['client_id']);
    }

    public function test_two_accounts_at_one_address_ask_rather_than_guess(): void
    {
        $twin = ['client_id' => 51, 'type' => 'business', 'name' => 'Oak Tenants Group', 'contact_id' => 13, 'addresses' => ['500 Oak St'], 'domains' => []];
        $r = $this->identify('A Neighbour <n@elsewhere.ca>', 'Hedge', "The hedge at 500 Oak Street is over my fence.\nPlease trim it soon.", $this->index([$twin]));
        $this->assertSame('weak', $r['strength']);
        $this->assertNull($r['client_id']);
        $this->assertCount(2, $r['candidates']);
    }

    public function test_company_and_address_pointing_at_different_accounts_is_not_strong(): void
    {
        $r = $this->identify('A Stranger <a@elsewhere.ca>', 'Account', "1355183 BC Ltd\n500 Oak Street\nWhat do we owe?\nThanks for checking.");
        $this->assertNotSame('strong', $r['strength']);
        $this->assertNull($r['client_id']);
    }

    public function test_same_company_domain_alone_is_a_question(): void
    {
        $r = $this->identify('Someone New <new@dorset.ca>', 'Hello', "Please call me about next year.\nThanks very much.");
        $this->assertSame('medium', $r['strength']);
        $this->assertSame(50, $r['client_id']);
        $this->assertSame(['domain'], $r['evidence']);
    }

    public function test_one_domain_on_two_records_of_one_person_stays_one_contact(): void
    {
        $r = $this->identify('New Person <np@lotzandco.com>', 'Hello', "Please call me about the account.\nThanks very much.");
        $this->assertSame('medium', $r['strength']);
        $this->assertSame(1439, $r['contact_id']);
    }

    public function test_free_mail_domains_never_join(): void
    {
        $gmail = ['client_id' => 9, 'type' => 'business', 'name' => 'Acme Gardens Ltd', 'contact_id' => 9, 'addresses' => [], 'domains' => ['gmail.com']];
        $r = $this->identify('Spam Person <spam@gmail.com>', 'Estimates', "We provide estimating services.\nReply for rates.", $this->index([$gmail]));
        $this->assertSame('none', $r['strength']);
    }

    public function test_text_quoted_from_an_earlier_message_does_not_count(): void
    {
        $body = "Thanks for that.\n\nOn Nov 3, 2026, at 9:00 AM, Office wrote:\n> Your invoice for 500 Oak Street is attached.\n> Dorset Strata";
        $r = $this->identify('A Stranger <a@elsewhere.ca>', 'Re: invoice', $body);
        $this->assertSame('none', $r['strength']);
    }

    public function test_a_stranger_selling_something_matches_nothing(): void
    {
        $r = $this->identify('Robyn <robyn.bidestimated@gmail.com>', 'Any upcoming projects?', "We do material and labor breakdowns.\nSend your plans.");
        $this->assertSame('none', $r['strength']);
        $this->assertNull($r['client_id']);
        $this->assertSame([], $r['candidates']);
    }

    /** Production data, found by the replay: the same client on file twice, the property on the person's own account. */
    public function test_the_same_client_on_file_twice_still_lands_on_the_account_named(): void
    {
        $idx = [
            ['client_id' => 2068, 'type' => 'business', 'name' => '1355183 BC LTD', 'contact_id' => 1439,
             'addresses' => ['1626 West 10th Avenue'], 'domains' => ['lotzandco.com']],
            ['client_id' => 3495, 'type' => 'individual', 'name' => 'Sandra Bertoia', 'contact_id' => 1439,
             'addresses' => ['1626 West 10th Avenue'], 'domains' => ['lotzandco.com']],
        ];
        $r = $this->identify(self::FROM, 'Amount Owing', self::BODY, $idx);
        $this->assertSame('strong', $r['strength']);
        $this->assertSame(2068, $r['client_id']);
    }

    public function test_our_invoice_number_places_the_mail_whatever_the_sender(): void
    {
        $idx = $this->index([['client_id' => 60, 'type' => 'business', 'name' => 'Health Authority', 'contact_id' => 61,
            'addresses' => [], 'domains' => ['vch.ca'], 'invoices' => ['INV-2026-0430']]]);
        $r = $this->identify('Accounts Payable <ap@phs.example>', 'FW: Invoice INV-2026-0430 is 1 days past due', "Please see below.\nWe will look into this.", $idx);
        $this->assertSame('strong', $r['strength']);
        $this->assertSame(60, $r['client_id']);
        $this->assertSame(['invoice'], $r['evidence']);
    }

    public function test_invoice_number_written_with_a_hash_is_the_same_number(): void
    {
        $this->assertSame(['INV-2026-0413'], InboundIdentityService::invoiceRefs('Mowology Landscaping - INV# 2026-0413'));
    }

    public function test_an_invoice_number_that_is_not_ours_places_nothing(): void
    {
        $r = $this->identify('A Vendor <a@elsewhere.ca>', 'Invoice INV-2026-9999', "Your invoice is attached.\nThanks for your business.");
        $this->assertSame('none', $r['strength']);
    }
}
