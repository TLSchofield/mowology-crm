<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Someone asks what is owing: a statement is ready, and it never shows one client another's balance. */
class YuiStatementOfferTest extends TestCase
{
    /** @dataProvider asking */
    public function test_spots_a_request(string $subject, string $text): void
    {
        $this->assertTrue(YuiStatementOffer::asks($subject, $text));
    }

    public static function asking(): array
    {
        return [
            ['Amount Owing', 'Could you let me know the total owing for the company?'],
            ['Quick one', 'What is the outstanding balance on our account?'],
            ['Hello', 'Can you send me a statement please?'],
            ['Accounts', 'How much do we owe you at this point?'],
            ['Re: Invoice', 'Please provide a statement of account.'],
        ];
    }

    /** @dataProvider notAsking */
    public function test_leaves_everything_else_alone(string $subject, string $text): void
    {
        $this->assertFalse(YuiStatementOffer::asks($subject, $text));
    }

    public static function notAsking(): array
    {
        return [
            ['Re: Your Statement of Account', 'Thanks, got it.'],
            ['Re: Balance due on your account', 'Paid this morning.'],
            ['Mowing', 'Can you come Tuesday instead?'],
            ['Trees', 'The arborist made a statement about the hedge.'],
            ['Fw: Payment reminder: Invoice INV-1', 'Amount due: $68.25'],
        ];
    }

    private function input(array $over = []): array
    {
        return array_merge(['closing' => 420.38, 'label' => 'All outstanding', 'accounts' => ['Acme Holdings Ltd'],
            'asker' => ['name' => 'Pat Lee', 'email' => 'Pat@Firm.test'], 'on_file' => ['name' => 'Kim Fox', 'email' => 'kim@firm.test']], $over);
    }

    public function test_someone_not_on_file_is_offered_and_labelled(): void
    {
        $o = YuiStatementOffer::decide($this->input());
        $this->assertTrue($o['offer']);
        $this->assertSame('pat@firm.test', $o['recipients'][0]['email']);
        $this->assertFalse($o['recipients'][0]['on_file']);
        $this->assertSame('kim@firm.test', $o['recipients'][1]['email']);
        $this->assertTrue($o['recipients'][1]['on_file']);
    }

    public function test_the_person_on_file_appears_once(): void
    {
        $o = YuiStatementOffer::decide($this->input(['asker' => ['name' => 'Kim', 'email' => 'kim@firm.test']]));
        $this->assertCount(1, $o['recipients']);
        $this->assertTrue($o['recipients'][0]['on_file']);
    }

    public function test_one_building_filed_under_two_accounts_is_still_one_client(): void
    {
        $o = YuiStatementOffer::decide($this->input(['accounts' => ['Pat Lee', 'Acme Holdings Ltd'], 'same_place' => true, 'name' => 'Acme Holdings Ltd']));
        $this->assertTrue($o['offer']);
        $this->assertSame('Acme Holdings Ltd', $o['account']);
    }

    public function test_several_accounts_are_refused(): void
    {
        $o = YuiStatementOffer::decide($this->input(['accounts' => ['Strata One', 'Strata Two'], 'same_place' => false]));
        $this->assertFalse($o['offer']);
        $this->assertStringContainsString('2 accounts', (string)$o['why']);
        $this->assertSame([], $o['recipients']);
    }

    public function test_no_invoices_a_held_message_or_no_address_are_refused(): void
    {
        $this->assertFalse(YuiStatementOffer::decide($this->input(['accounts' => []]))['offer']);
        $this->assertFalse(YuiStatementOffer::decide($this->input(['hold' => true]))['offer']);
        $this->assertFalse(YuiStatementOffer::decide($this->input(['asker' => ['name' => '', 'email' => 'nope'], 'on_file' => ['name' => '', 'email' => '']]))['offer']);
    }

    public function test_the_draft_names_the_account_and_the_balance(): void
    {
        $d = YuiStatementOffer::draft(YuiStatementOffer::decide($this->input()), 'Pat', 'Amount Owing', 'Tim');
        $this->assertSame('Re: Amount Owing', $d['subject']);
        $this->assertStringContainsString('Hi Pat,', $d['body']);
        $this->assertStringContainsString('Acme Holdings Ltd is $420.38', $d['body']);
    }

    public function test_nothing_owing_says_so(): void
    {
        $d = YuiStatementOffer::draft(YuiStatementOffer::decide($this->input(['closing' => 0.0])), '', 'Balance', 'Tim');
        $this->assertStringContainsString('nothing owing', $d['body']);
        $this->assertStringContainsString('Hi there,', $d['body']);
    }

    public function test_a_reply_quoting_invoice_numbers_still_drafts(): void
    {
        $v = YuiRules::vars(['to' => ['first_name' => 'Pat'], 'invoices' => ['INV-1', 'INV-2'], 'subject' => 'Amount'], 'Tim');
        $this->assertStringContainsString('INV-1', implode(' ', $v));
        $d = YuiRules::draft(['template' => 'reply', 'to' => ['first_name' => 'Pat'], 'invoices' => ['INV-1'], 'subject' => 'Amount'], 'Tim');
        $this->assertStringStartsWith('Re: ', $d['subject']);
    }
}
