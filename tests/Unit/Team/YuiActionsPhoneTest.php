<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The phone's yui_act: only listed modes, only the card's own fields, only a card the server still has. */
class YuiActionsPhoneTest extends TestCase
{
    private function jobs(): array
    {
        $reply = ['name' => 'Pat', 'subject' => 'Hi', 'quote' => 'Tuesday?', 'at' => '', 'key' => 'yui:r1',
            'to' => ['name' => 'Pat', 'email' => 'pat@x.test'], 'draft' => ['subject' => 'Re: Hi', 'body' => 'Hello', 'drafted_by' => 'template']];
        $q = ['id' => 4, 'name' => 'Kim', 'addr' => 'kim@y.test', 'subject' => 'S', 'snippet' => 'T', 'at' => '', 'why' => '',
            'suggested_client_id' => 7, 'candidates' => [], 'first_name' => 'Kim', 'last_name' => 'Fox'];
        return YuiJobAdapter::queue([$reply], [$q]);
    }

    public function test_only_listed_modes_are_allowed(): void
    {
        foreach (['jobs', 'desk', 'brief', 'delete', '', 'SEND'] as $m) {
            $this->assertNull(YuiActions::phoneRequest($this->jobs(), ['mode' => $m, 'key' => 'yui:r1'], []), $m);
        }
        $this->assertNotNull(YuiActions::phoneRequest($this->jobs(), ['mode' => 'send', 'key' => 'yui:r1'], []));
        $this->assertSame('who_accounts', YuiActions::phoneRequest($this->jobs(), ['mode' => 'who_accounts'], ['q' => 'Birch'])['mode']);
    }

    public function test_fields_merge_drops_unlisted_keys(): void
    {
        $r = YuiActions::phoneRequest($this->jobs(), ['mode' => 'send', 'key' => 'yui:r1'],
            ['subject' => 'New', 'body' => 'Text', 'recipient' => 'evil@x.test', 'channel' => 'sms', 'key' => 'other', 'closing' => 1, 'to' => 'evil@x.test']);
        $this->assertSame('New', $r['subject']);
        $this->assertSame('Text', $r['body']);
        $this->assertArrayNotHasKey('recipient', $r);   // not in a reply card's fields
        $this->assertSame('email', $r['channel']);
        $this->assertSame('yui:r1', $r['key']);
        $this->assertArrayNotHasKey('closing', $r);
        $this->assertArrayNotHasKey('to', $r);

        $w = YuiActions::phoneRequest($this->jobs(), ['mode' => 'who_confirm', 'id' => 4], ['first_name' => 'Kimberly', 'client_id' => '9', 'id' => 99, 'subject' => 'x']);
        $this->assertSame(4, $w['id']);
        $this->assertSame(9, $w['client_id']);
        $this->assertSame('Kimberly', $w['first_name']);
        $this->assertArrayNotHasKey('subject', $w);
    }

    public function test_a_request_that_is_not_a_current_card_is_refused(): void
    {
        $this->assertNull(YuiActions::phoneRequest($this->jobs(), ['mode' => 'send', 'key' => 'yui:gone'], []));
        $this->assertNull(YuiActions::phoneRequest($this->jobs(), ['mode' => 'who_not', 'id' => 99], []));
        $this->assertNull(YuiActions::phoneRequest($this->jobs(), ['mode' => 'who_not'], []));
        $this->assertNull(YuiActions::phoneRequest([], ['mode' => 'handled', 'key' => 'yui:r1'], []));
        // a mode the card does not offer for that key
        $this->assertNull(YuiActions::phoneRequest($this->jobs(), ['mode' => 'who_confirm', 'id' => 4 + 1], []));
    }

    public function test_undo_works_after_the_card_has_left_the_queue(): void
    {
        $this->assertSame(['mode' => 'who_undo', 'id' => 5], YuiActions::phoneRequest([], ['mode' => 'who_undo', 'id' => 5], []));
        $this->assertNull(YuiActions::phoneRequest([], ['mode' => 'who_undo', 'id' => 0], []));
    }

    public function test_a_statement_send_carries_the_balance_the_phone_showed(): void
    {
        $job = ['key' => 's1', 'fields' => ['recipient', 'subject', 'body'], 'primary' => ['request' => ['mode' => 'send', 'key' => 's1', 'template' => 'statement', 'closing' => 420.38]]];
        $r = YuiActions::phoneRequest([$job], ['mode' => 'send', 'key' => 's1'], ['body' => 'Hi', 'closing' => '400.00']);
        $this->assertSame(400.0, $r['closing']);
        $r = YuiActions::phoneRequest([$job], ['mode' => 'send', 'key' => 's1'], ['body' => 'Hi']);
        $this->assertSame(-1.0, $r['closing']);
    }
}
