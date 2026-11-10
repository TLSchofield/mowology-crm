<?php
declare(strict_types=1);

/**
 * Yui's decisions as one queue of cards, one at a time (Tim, 2026-11-10: "Yui needs a decision carousel like Penny has").
 * The same shape everywhere: the desktop job strip, the web card and the iPhone carousel read this list.
 *
 * Every card: what it is and why on the left, what will happen (editable) on the right, one filled action and one outline.
 *   statement  someone asked what is owing: the statement is ready (YuiStatementOffer), Tim's click sends it
 *   who        mail Yui could not place for sure: whose is it? (InboundQuestionService)
 *   reply      a client wrote and nobody answered: the draft, Send or Handled
 * Order: statements, then replies saying yes, then "who is this", then the other replies.
 * A card's actions are the exact /crm/api/yui.php requests the web card already sends; `fields` names what the person may
 * edit and merge into the request at the click. Nothing here writes or sends anything.
 */
final class YuiJobAdapter
{
    public const API = '/crm/api/yui.php';

    /** @return array<int, array<string, mixed>> */
    public static function jobs(PDO $db, string $owner): array
    {
        try {
            require_once __DIR__ . '/YuiDeskService.php';
            require_once __DIR__ . '/InboundQuestionService.php';
            $inbox = (new YuiDeskService($db))->inboxCards($owner);
            $who = new InboundQuestionService($db);
            return self::queue($inbox, $who->ready() ? $who->questions() : []);
        } catch (Throwable $e) {
            error_log('YuiJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /** Pure: inbox cards + questions → the ordered queue with index/total. */
    public static function queue(array $inbox, array $questions): array
    {
        $stmt = $yes = $other = [];
        foreach ($inbox as $it) {
            if (!empty($it['hold'])) continue;                       // a phone call, not a card decision
            if (!empty($it['statement']['offer'])) { $stmt[] = self::fromStatement($it); continue; }
            if (empty($it['to']['email'])) continue;                 // nobody to write to: stays on the full card
            if (!empty($it['yes'])) $yes[] = self::fromReply($it); else $other[] = self::fromReply($it);
        }
        $all = array_merge($stmt, $yes, array_map([self::class, 'fromQuestion'], $questions), $other);
        $n = count($all);
        foreach ($all as $i => &$j) { $j['index'] = $i + 1; $j['total'] = $n; }
        unset($j);
        return $all;
    }

    private static function left(array $it): array
    {
        return ['name' => (string)($it['name'] ?? ''), 'subject' => (string)($it['subject'] ?? ''),
                'text' => (string)($it['quote'] ?? ($it['text'] ?? '')), 'at' => (string)($it['at'] ?? '')];
    }

    public static function fromStatement(array $it): array
    {
        $o = (array)$it['statement'];
        $d = (array)($it['draft_statement'] ?? []);
        $money = '$' . number_format((float)$o['closing'], 2);
        $first = (array)($o['recipients'] ?? []);
        $default = '';
        foreach ($first as $r) { if (!empty($r['on_file'])) { $default = (string)$r['email']; break; } }
        if ($default === '' && $first) $default = (string)$first[0]['email'];
        return [
            'head' => 'yui', 'kind' => 'statement', 'key' => (string)$it['key'],
            'title' => 'They asked what is owing. The statement is ready',
            'why' => $o['account'] . ' · ' . $o['label'] . ' · ' . $money,
            'left' => self::left($it),
            'right' => ['account' => (string)$o['account'], 'label' => (string)$o['label'], 'closing' => (float)$o['closing'],
                        'recipients' => $first, 'recipient' => $default,
                        'subject' => (string)($d['subject'] ?? ''), 'body' => (string)($d['body'] ?? '')],
            'fields' => ['recipient', 'subject', 'body'],
            'primary' => ['label' => 'Send statement', 'sub' => $money . ' · PDF attached',
                          'request' => ['mode' => 'send', 'key' => (string)$it['key'], 'template' => 'statement', 'channel' => 'email',
                                        'closing' => (float)$o['closing'], 'suggested_body' => (string)($d['body'] ?? ''), 'drafted_by' => 'template']],
            'secondary' => ['label' => 'Handled', 'sub' => 'no statement', 'request' => ['mode' => 'handled', 'key' => (string)$it['key']]],
            'api' => self::API,
        ];
    }

    public static function fromReply(array $it): array
    {
        $d = (array)($it['draft'] ?? []);
        $to = (array)$it['to'];
        return [
            'head' => 'yui', 'kind' => 'reply', 'key' => (string)$it['key'],
            'title' => !empty($it['yes']) ? 'They said yes. Answer them' : 'A client wrote. Nobody has answered',
            'why' => !empty($it['stage']) && $it['stage'] === 'late' ? 'Waited ' . (int)($it['waiting_days'] ?? 0) . ' weekdays' : '',
            'left' => self::left($it),
            'right' => ['to' => ['name' => (string)($to['name'] ?? ''), 'email' => (string)($to['email'] ?? '')],
                        'subject' => (string)($d['subject'] ?? ''), 'body' => (string)($d['body'] ?? ''), 'can_draft' => true],
            'fields' => ['subject', 'body'],
            'primary' => ['label' => 'Send', 'sub' => 'to ' . (string)($to['name'] ?? ''),
                          'request' => ['mode' => 'send', 'key' => (string)$it['key'], 'channel' => 'email', 'template' => '',
                                        'suggested_body' => (string)($d['body'] ?? ''), 'drafted_by' => (string)($d['drafted_by'] ?? 'template')]],
            'secondary' => ['label' => 'Handled', 'sub' => 'nothing to send', 'request' => ['mode' => 'handled', 'key' => (string)$it['key']]],
            'draft' => ['mode' => 'draft', 'key' => (string)$it['key']],
            'api' => self::API,
        ];
    }

    public static function fromQuestion(array $q): array
    {
        return [
            'head' => 'yui', 'kind' => 'who', 'key' => 'who:' . (int)$q['id'],
            'title' => 'Who is this? I think I know',
            'why' => (string)($q['why'] ?? ''),
            'left' => ['name' => (string)$q['name'], 'addr' => (string)$q['addr'], 'subject' => (string)$q['subject'],
                       'text' => (string)$q['snippet'], 'at' => (string)$q['at'], 'more' => (int)($q['more'] ?? 0)],
            'right' => ['first_name' => (string)$q['first_name'], 'last_name' => (string)$q['last_name'],
                        'candidates' => (array)$q['candidates'], 'client_id' => $q['suggested_client_id']],
            'fields' => ['first_name', 'last_name', 'client_id'],
            'primary' => ['label' => 'Add to account', 'sub' => 'their mail lands there', 'request' => ['mode' => 'who_confirm', 'id' => (int)$q['id']]],
            'secondary' => ['label' => 'Not a client', 'sub' => 'never ask again', 'request' => ['mode' => 'who_not', 'id' => (int)$q['id']]],
            'undo' => ['request' => ['mode' => 'who_undo', 'id' => (int)$q['id']]],
            'search' => ['mode' => 'who_accounts'],
            'api' => self::API,
        ];
    }
}
