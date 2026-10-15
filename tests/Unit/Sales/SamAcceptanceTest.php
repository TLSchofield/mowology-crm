<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** VML's Alena returned 9 signed snow quotes by email (2026-10-15): find them, match each file to its quote. */
class SamAcceptanceTest extends TestCase
{
    private const VML = [
        ['id' => 84, 'number' => 'QUO-2026-0059'], ['id' => 85, 'number' => 'QUO-2026-0060'], ['id' => 86, 'number' => 'QUO-2026-0061'],
        ['id' => 87, 'number' => 'QUO-2026-0062'], ['id' => 88, 'number' => 'QUO-2026-0063'], ['id' => 89, 'number' => 'QUO-2026-0064'],
        ['id' => 90, 'number' => 'QUO-2026-0065'], ['id' => 91, 'number' => 'QUO-2026-0066'], ['id' => 93, 'number' => 'QUO-2026-0068'],
        ['id' => 94, 'number' => 'QUO-2026-0069'],
    ];

    public function test_one_organisation_across_mail_domains(): void
    {
        $this->assertSame('vml.ca', SamAcceptanceService::orgOf('alena@vml.ca'));
        $this->assertSame('vml.ca', SamAcceptanceService::orgOf('rharvie@vml.bc.ca'));
        $this->assertSame('dorsetrealty.com', SamAcceptanceService::orgOf('invoices@dorsetrealty.com'));
        $this->assertNotSame(SamAcceptanceService::orgOf('a@acme.com'), SamAcceptanceService::orgOf('b@acme.net'), 'different organisations');
        $this->assertNull(SamAcceptanceService::orgOf('linnim@telus.net'), 'free mail is nobody\'s company');
        $this->assertNull(SamAcceptanceService::orgOf('someone@gmail.com'));
        $this->assertNull(SamAcceptanceService::orgOf('not an email'));
    }

    public function test_signal(): void
    {
        $this->assertTrue(SamAcceptanceService::hasSignal('Snow Removal - 2025/2026 - Signed quotes', '', []));
        $this->assertTrue(SamAcceptanceService::hasSignal('Re: quotes', 'Looks good, please go ahead.', []));
        $this->assertTrue(SamAcceptanceService::hasSignal('Re: quotes', '', ['KENWOOD - MOWOLOGY - SNOW 0060 - SIGNED.pdf']));
        $this->assertFalse(SamAcceptanceService::hasSignal('EFT Direct deposit form', 'Please find the form attached.', ['EFT form.pdf']));
    }

    public function test_each_signed_file_matches_its_quote(): void
    {
        $files = [101 => 'KPC - MOWOLOGY - SNOW 0069 - SIGNED.pdf', 102 => 'VILLAGE GREEN - MOWOLOGY - SNOW 0066 - SIGNED.pdf',
                  103 => 'KENWOOD - MOWOLOGY - SNOW 0060 - SIGNED.pdf', 104 => 'BRANDON - MOWOLOGY - SNOW 0068 - SIGNED.pdf'];
        $m = SamAcceptanceService::match(self::VML, 'Snow Removal - 2025/2026 - Signed quotes', 'Please see attached signed quotes.', $files);
        $byQuote = array_column($m, 'attachment_id', 'quote_id');
        $this->assertSame([94 => 101, 91 => 102, 85 => 103, 93 => 104], $byQuote);
        $this->assertArrayNotHasKey(84, $byQuote, 'Kerrisdale Landing (0059) was not returned');
    }

    public function test_a_full_quote_number_in_the_text(): void
    {
        $m = SamAcceptanceService::match(self::VML, 'Re: QUO-2026-0062', 'Approved, thanks', []);
        $this->assertSame([87], array_column($m, 'quote_id'));
    }

    public function test_no_number_and_several_quotes_matches_nothing(): void
    {
        $this->assertSame([], SamAcceptanceService::match(self::VML, 'Re: your quote', 'Approved', []));
        $one = SamAcceptanceService::match([['id' => 120, 'number' => 'QUO-2026-0093']], 'Re: your quote', 'Yes please go ahead', []);
        $this->assertSame([120], array_column($one, 'quote_id'), 'one open quote: that one');
    }

    public function test_a_reply_from_the_company_pauses_the_chase(): void
    {
        $touches = [32 => ['last_in' => null, 'last_out' => '2026-10-04 20:02:00', 'last_sam' => null, 'snooze_until' => null, 'thread' => []]];
        $quotes = [['id' => 85, 'contact_id' => 32, 'company_id' => 4, 'contact_company_id' => 4, 'email' => 'rharvie@vml.bc.ca']];
        $org = ['company' => [], 'org' => ['vml.ca' => ['at' => '2026-10-14 10:14:46', 'by' => 'Alena Radosovska', 'contact_id' => 1535]]];
        $t = SalesDeskService::mergeOrgReplies($touches, $quotes, $org);
        $this->assertSame('2026-10-14 10:14:46', $t[32]['last_in']);
        $this->assertSame('Alena Radosovska', $t[32]['last_in_by']);

        $own = ['company' => [4 => ['at' => '2026-10-14 10:14:46', 'by' => 'Ron', 'contact_id' => 32]], 'org' => []];
        $this->assertNull(SalesDeskService::mergeOrgReplies($touches, $quotes, $own)[32]['last_in'], 'his own mail is counted elsewhere');
    }

    public function test_a_year_in_a_file_name_is_not_a_quote_number(): void
    {
        $m = SamAcceptanceService::match([['id' => 7, 'number' => 'QUO-2025-2026'], ['id' => 8, 'number' => 'QUO-2026-0070']],
                                         'Signed', '', [201 => 'Signed 2026.pdf']);
        $this->assertSame([], $m);
    }
}
