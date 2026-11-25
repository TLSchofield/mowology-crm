<?php
/**
 * SamReplyCard — lets Sam's follow-up service answer a "Replies waiting" item.
 *
 * Why (2026-10-07, iOS Team tab): the app's Sam card offers "Draft reply" and "Send" on
 * the unclaimed replies (UnclaimedReplyService, 'quote' lane). Those replies have no
 * follow-up card — by construction they are the ones Sam's quote queue did NOT catch —
 * so SamFollowupService::draftReply()/send(), which take a card, had nothing to work on.
 * This builds that card from server data only (the reply, the contact row, the thread
 * SalesDeskService::touches() holds), so the SAME draftReply()/send() run unchanged:
 * Claude's daily cap, the office@ send through sendEmail(), sam_followups and the
 * sales_messages history (which is also what makes the reply count as answered).
 *
 * The card carries no quotes: the reply wasn't matched to an open quote, so no quote
 * links go under the email and no quote's follow-up count moves.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SamReplyCard
{
    /** Card keys for replies, so they can never collide with queue keys (c123 / q45). */
    public const CARD_PREFIX = 'reply:';

    /** The waiting reply with this key, from SalesDeskService::unclaimed(), or null. */
    public static function find(array $unclaimed, string $key): ?array
    {
        if ($key === '') return null;
        foreach ($unclaimed as $u) {
            if ((string)($u['key'] ?? '') === $key) return $u;
        }
        return null;
    }

    /**
     * Pure: the card shape SamFollowupService expects (see SalesDeskService::groupStale()).
     * @param array $reply   one unclaimed item (key, contact_id, name, subject, channel, at)
     * @param array $contact contacts row: first_name, last_name, email, phone
     * @param array $thread  SalesDeskService::touches()[cid]['thread'] (newest first)
     */
    public static function build(array $reply, array $contact, array $thread): array
    {
        $first = trim((string)($contact['first_name'] ?? ''));
        $name = trim($first . ' ' . trim((string)($contact['last_name'] ?? '')));
        if ($name === '') $name = (string)($reply['name'] ?? '') ?: 'Customer';
        return [
            'key'         => self::CARD_PREFIX . (string)($reply['key'] ?? ''),
            'kind'        => 'replied',
            'template'    => 'reply',
            'contact_id'  => (int)($reply['contact_id'] ?? 0) ?: null,
            'name'        => $name,
            'first_name'  => $first,
            'company'     => '',
            'email'       => trim((string)($contact['email'] ?? '')),
            'phone'       => trim((string)($contact['phone'] ?? '')),
            'amount'      => 0.0,
            'days'        => 0,
            'wait'        => 0,
            'followups'   => 0,
            'viewed'      => false,
            'valid_until' => null,
            'last_in'     => $reply['at'] ?? null,
            'thread'      => array_values($thread),
            'label'       => '',
            'quotes'      => [],
        ];
    }

    /** Pure: the subject for an answer — "Re: <their subject>", else a plain one. */
    public static function subject(array $reply): string
    {
        $s = trim((string)($reply['subject'] ?? ''));
        if (($reply['channel'] ?? '') === 'sms' || $s === '') return 'Re: your message';
        return preg_match('/^\s*re\s*:/i', $s) ? $s : 'Re: ' . $s;
    }

    /** The card for a waiting reply, read from the CRM. Null when the contact is gone. */
    public static function load(PDO $db, SalesDeskService $desk, array $reply): ?array
    {
        $cid = (int)($reply['contact_id'] ?? 0);
        if ($cid <= 0) return null;
        $s = $db->prepare("
            SELECT first_name, last_name, email, COALESCE(NULLIF(mobile, ''), phone) AS phone
            FROM contacts WHERE id = ?
        ");
        $s->execute([$cid]);
        $contact = $s->fetch(PDO::FETCH_ASSOC);
        if (!$contact) return null;
        $touches = $desk->touches([$cid]);
        return self::build($reply, $contact, $touches[$cid]['thread'] ?? []);
    }
}
