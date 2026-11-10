<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Yui's decisions as one queue: statements first, a yes next, then "who is this", then the rest. */
class YuiJobAdapterTest extends TestCase
{
    private function reply(string $key, array $over = []): array
    {
        return array_merge(['key' => $key, 'name' => 'Pat', 'subject' => 'Hello', 'quote' => 'Can you come Tuesday?', 'at' => '2026-11-10 09:00:00',
            'to' => ['name' => 'Pat Lee', 'email' => 'pat@x.test'], 'draft' => ['subject' => 'Re: Hello', 'body' => 'Hi Pat', 'drafted_by' => 'template']], $over);
    }

    private function question(int $id): array
    {
        return ['id' => $id, 'name' => 'Kim Fox', 'addr' => 'kim@y.test', 'subject' => 'Sprinklers', 'snippet' => 'Timing?', 'at' => '2026-11-09 10:00:00',
            'why' => 'names the address', 'suggested_client_id' => 7, 'candidates' => [['client_id' => 7, 'name' => 'Birch Strata']],
            'first_name' => 'Kim', 'last_name' => 'Fox', 'more' => 2];
    }

    public function test_the_order_and_the_count(): void
    {
        $stmt = $this->reply('s1', ['statement' => ['offer' => true, 'closing' => 420.38, 'label' => 'All outstanding', 'account' => 'Acme Ltd',
            'recipients' => [['email' => 'pat@x.test', 'name' => 'Pat', 'on_file' => false], ['email' => 'kim@x.test', 'name' => 'Kim', 'on_file' => true]]],
            'draft_statement' => ['subject' => 'Re: Amount', 'body' => 'Hi Pat']]);
        $q = YuiJobAdapter::queue([$this->reply('r1'), $this->reply('y1', ['yes' => true]), $stmt], [$this->question(4)]);
        $this->assertSame(['statement', 'reply', 'who', 'reply'], array_column($q, 'kind'));
        $this->assertSame(['s1', 'y1', 'who:4', 'r1'], array_column($q, 'key'));
        $this->assertSame([1, 2, 3, 4], array_column($q, 'index'));
        $this->assertSame(4, $q[0]['total']);
    }

    public function test_a_statement_defaults_to_the_person_on_file_and_carries_the_balance(): void
    {
        $stmt = $this->reply('s1', ['statement' => ['offer' => true, 'closing' => 420.38, 'label' => 'All outstanding', 'account' => 'Acme Ltd',
            'recipients' => [['email' => 'pat@x.test', 'name' => 'Pat', 'on_file' => false], ['email' => 'kim@x.test', 'name' => 'Kim', 'on_file' => true]]],
            'draft_statement' => ['subject' => 'Re: Amount', 'body' => 'Hi Pat']]);
        $j = YuiJobAdapter::queue([$stmt], [])[0];
        $this->assertSame('kim@x.test', $j['right']['recipient']);
        $this->assertSame('statement', $j['primary']['request']['template']);
        $this->assertSame(420.38, $j['primary']['request']['closing']);
        $this->assertSame('$420.38 · PDF attached', $j['primary']['sub']);
    }

    public function test_held_messages_and_replies_with_nobody_to_write_to_are_not_cards(): void
    {
        $q = YuiJobAdapter::queue([$this->reply('h1', ['hold' => true]), $this->reply('n1', ['to' => null])], []);
        $this->assertSame([], $q);
    }

    public function test_a_question_card_says_who_and_carries_undo(): void
    {
        $j = YuiJobAdapter::fromQuestion($this->question(9));
        $this->assertSame(['mode' => 'who_confirm', 'id' => 9], $j['primary']['request']);
        $this->assertSame(['mode' => 'who_undo', 'id' => 9], $j['undo']['request']);
        $this->assertSame(7, $j['right']['client_id']);
        $this->assertSame(2, $j['left']['more']);
    }
}
