<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The card the iOS app's Sam card answers a "Replies waiting" item with
 * (sales-head-mobile.php): SamFollowupService::draftReply()/send() must work on it unchanged.
 */
class SamReplyCardTest extends TestCase
{
    private function reply(array $over = []): array
    {
        return array_merge([
            'key' => 'sam:reply:77:ab12cd34', 'contact_id' => 77, 'name' => 'Gaby',
            'subject' => 'Fall cleanup quote', 'quote' => 'Yes please', 'channel' => 'email',
            'yes' => true, 'at' => '2026-10-06 09:14:00',
        ], $over);
    }

    public function test_find_returns_the_item_with_that_key_only(): void
    {
        $list = [$this->reply(), $this->reply(['key' => 'sam:reply:9:ff', 'contact_id' => 9])];
        $this->assertSame(9, SamReplyCard::find($list, 'sam:reply:9:ff')['contact_id']);
        $this->assertNull(SamReplyCard::find($list, 'sam:reply:404:00'));
        $this->assertNull(SamReplyCard::find($list, ''));
    }

    public function test_build_has_the_card_shape_with_no_quotes(): void
    {
        $thread = [['dir' => 'inbound', 'channel' => 'email', 'subject' => 'Re: Fall cleanup', 'snippet' => 'Yes please', 'at' => '2026-10-06 09:14:00']];
        $c = SamReplyCard::build($this->reply(), ['first_name' => 'Gaby', 'last_name' => 'Ortiz', 'email' => ' gaby@example.com ', 'phone' => '6045550101'], $thread);
        $this->assertSame('reply:sam:reply:77:ab12cd34', $c['key']);
        $this->assertSame('replied', $c['kind']);
        $this->assertSame('reply', $c['template']);
        $this->assertSame(77, $c['contact_id']);
        $this->assertSame('Gaby Ortiz', $c['name']);
        $this->assertSame('gaby@example.com', $c['email']);
        $this->assertSame([], $c['quotes']);
        $this->assertSame($thread, $c['thread']);
    }

    public function test_build_falls_back_to_the_reply_name(): void
    {
        $c = SamReplyCard::build($this->reply(), ['first_name' => '', 'last_name' => '', 'email' => ''], []);
        $this->assertSame('Gaby', $c['name']);
        $this->assertSame('', $c['email']);
    }

    public function test_followup_service_helpers_accept_the_card(): void
    {
        $c = SamReplyCard::build($this->reply(), ['first_name' => 'Gaby', 'last_name' => 'Ortiz', 'email' => 'g@example.com'],
            [['dir' => 'inbound', 'channel' => 'email', 'subject' => 'x', 'snippet' => 'Yes please', 'at' => '2026-10-06 09:14:00']]);
        $vars = SamFollowupService::vars($c, 'Tim');
        $this->assertSame('Gaby', $vars['{first_name}']);
        $this->assertSame('Re: your quote', SamFollowupService::fill(SamFollowupService::TEMPLATES['reply'][0], $vars));
        $p = SamFollowupService::replyPrompt($c, 'Tim', []);
        $this->assertStringContainsString('CUSTOMER (2026-10-06 09:14:00): Yes please', $p['user']);
        $this->assertStringContainsString('Hi Gaby,', $p['system']);
    }

    public function test_subject_answers_their_subject(): void
    {
        $this->assertSame('Re: Fall cleanup quote', SamReplyCard::subject($this->reply()));
        $this->assertSame('RE: Fall cleanup', SamReplyCard::subject($this->reply(['subject' => 'RE: Fall cleanup'])));
        $this->assertSame('Re: your message', SamReplyCard::subject($this->reply(['subject' => ''])));
        $this->assertSame('Re: your message', SamReplyCard::subject($this->reply(['channel' => 'sms'])));
    }
}
