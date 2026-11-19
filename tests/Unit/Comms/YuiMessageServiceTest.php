<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The whole email behind a card: their words in full, the quoted thread folded apart, nothing stored. */
class YuiMessageServiceTest extends TestCase
{
    public function test_their_words_and_the_thread_come_apart(): void
    {
        $body = "Hi there,\r\nThanks for this. I've just e-transferred the November amount.\r\nCan I check something here, please? "
              . str_repeat('More detail. ', 100) . "\r\n\r\nOn Nov 3, 2026, at 9:00 AM, Office <office@x.test> wrote:\r\n> Your statement is attached.\r\n> Thanks";
        $r = YuiMessageService::split($body);
        $this->assertStringStartsWith('Hi there,', $r['text']);
        $this->assertGreaterThan(1200, mb_strlen($r['text']), 'nothing cut at 800 characters');
        $this->assertStringNotContainsString('wrote:', $r['text']);
        $this->assertStringContainsString('Your statement is attached.', $r['earlier']);
        $this->assertStringNotContainsString('> ', $r['earlier']);
    }

    public function test_an_html_email_reads_as_text_with_its_lines(): void
    {
        $r = YuiMessageService::split('<html><style>p{color:red}</style><body><p>Hello</p><p>Second line &amp; more</p></body></html>');
        $this->assertSame("Hello\nSecond line & more", $r['text']);
    }

    public function test_which_mailbox_a_label_means(): void
    {
        $this->assertSame(['sales', 'INBOX'], YuiMessageService::source('office@ INBOX'));
        $this->assertSame(['router', 'clients'], YuiMessageService::source('icloud clients'));
        $this->assertNull(YuiMessageService::source('yui'));
    }

    public function test_mail_with_no_message_id_shows_what_yui_kept(): void
    {
        $r = YuiMessageService::read(['message_key' => 'h-abc', 'mailbox' => 'office@ INBOX', 'sent_at' => null, 'snippet' => 'Kept text']);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['from_mailbox']);
        $this->assertSame('Kept text', $r['text']);
    }

    public function test_every_card_offers_the_whole_email(): void
    {
        $q = YuiJobAdapter::queue([['key' => 'yui:reply:1:a', 'name' => 'Pat', 'to' => ['name' => 'Pat', 'email' => 'p@x.test'], 'draft' => []]],
            [['id' => 5, 'name' => 'Kim', 'addr' => 'k@x.test', 'subject' => 'S', 'snippet' => 'T', 'at' => '', 'why' => '', 'suggested_client_id' => null,
              'candidates' => [], 'first_name' => 'Kim', 'last_name' => '', 'more' => 0]]);
        $this->assertSame(['mode' => 'message', 'id' => 5], $q[0]['read']);
        $this->assertSame(['mode' => 'message', 'key' => 'yui:reply:1:a'], $q[1]['read']);
        $this->assertSame(['mode' => 'message', 'key' => 'yui:reply:1:a', 'id' => 0], YuiActions::phoneRequest($q, ['mode' => 'message', 'key' => 'yui:reply:1:a'], []));
    }
}
