<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Sales/Services/SalesInboxService.php';

/**
 * The whole email behind one of Yui's cards (Tim, 2026-11-10: "the email was truncated so I had to switch to my Mac").
 *
 * Yui keeps the new part of an email up to 800 characters; the mailbox keeps everything. On request this finds that one
 * message again by its Message-ID in the mailbox it came from (office@ or iCloud), READ-ONLY (ImapReader::open = EXAMINE,
 * bodies with FT_PEEK), and returns its text: what they wrote, and the earlier thread folded apart. Nothing is stored.
 * Only messages behind a card in Yui's own queue can be read this way (the caller resolves the card first).
 */
final class YuiMessageService
{
    public const MAX = 20000;

    /** Their words and the quoted thread below them, as plain text. Pure. @return array{text: string, earlier: string} */
    public static function split(string $body): array
    {
        $t = str_replace(["\r\n", "\r"], "\n", $body);
        if (preg_match('/<(html|body|div|p|br)\b/i', $t)) {
            $t = (string)preg_replace('/<(br|\/p|\/div|\/tr|\/li)\b[^>]*>/i', "\n", $t);
            $t = (string)preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $t);
        }
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = (string)preg_replace("/[ \t]+\n/", "\n", $t);
        $t = trim((string)preg_replace("/\n{3,}/", "\n\n", $t));
        $cut = ['/^On .{5,200}wrote:\s*$/m', '/^-{2,}\s*Original Message\s*-{2,}/mi', '/^From:\s.+$/m', '/^_{5,}\s*$/m'];
        $end = strlen($t);
        foreach ($cut as $re) {
            if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) $end = min($end, $m[0][1]);
        }
        $text = trim(substr($t, 0, $end));
        $earlier = trim(substr($t, $end));
        $earlier = trim((string)preg_replace('/^>\s?/m', '', $earlier));
        return ['text' => mb_substr($text, 0, self::MAX), 'earlier' => mb_substr($earlier, 0, self::MAX)];
    }

    /** "office@ INBOX" → ['sales', 'INBOX'], "icloud clients" → ['router', 'clients']; null for anything else. Pure. */
    public static function source(string $label): ?array
    {
        if (!preg_match('/^(office@|icloud)\s+(.+)$/', trim($label), $m)) return null;
        return [$m[1] === 'office@' ? 'sales' : 'router', $m[2]];
    }

    /**
     * Read one message by its key from the mailbox it came from.
     * @param array{message_key: string, mailbox: string, sent_at: ?string, snippet?: ?string} $row
     * @return array{ok: bool, text: string, earlier: string, from_mailbox: bool, message?: string}
     */
    public static function read(array $row): array
    {
        $kept = ['ok' => true, 'text' => (string)($row['snippet'] ?? ''), 'earlier' => '', 'from_mailbox' => false];
        $key = trim((string)$row['message_key']);
        $src = self::source((string)$row['mailbox']);
        if ($key === '' || strpos($key, 'h-') === 0 || $src === null || !function_exists('imap_open')) {
            return $kept + ['message' => 'This is what Yui kept; the full email is in the mailbox.'];
        }
        require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
        require_once APP_ROOT . '/Services/Mail/ImapReader.php';
        $mb = MailboxConfig::forPurpose($src[0])[0] ?? null;
        if ($mb === null) return $kept + ['message' => 'That mailbox is not connected here.'];
        ImapReader::timeouts();
        $conn = ImapReader::open($mb, $src[1]);
        if ($conn === false) return $kept + ['message' => 'The mailbox did not answer just now. This is what Yui kept.'];
        try {
            // A date window keeps the search cheap on a mailbox of 70,000 messages; the Message-ID decides.
            $t = strtotime((string)($row['sent_at'] ?? '')) ?: time();
            $q = 'SINCE "' . date('d-M-Y', $t - 3 * 86400) . '" BEFORE "' . date('d-M-Y', $t + 3 * 86400) . '" HEADER Message-ID "'
               . preg_replace('/[^\x21-\x7e]|["\\\\]/', '', $key) . '"';   // printable ASCII only, no quotes
            $hits = @imap_search($conn, $q) ?: [];
            $no = $hits ? (int)$hits[0] : self::byOverview($conn, $key, $t);   // some servers ignore HEADER searches
            if ($no <= 0) return $kept + ['message' => 'I could not find it in the mailbox. This is what Yui kept.'];
            try {
                $body = ImapReader::textBody($conn, $no);
            } catch (Throwable $e) {   // a character set PHP will not convert
                return $kept + ['message' => 'That email uses a character set I cannot read. This is what Yui kept.'];
            }
            return ['ok' => true, 'from_mailbox' => true] + self::split($body);
        } finally {
            @imap_close($conn);
        }
    }

    /** The message number whose Message-ID is $key among the mail of the days around $t (envelopes only, in bulk). */
    private static function byOverview($conn, string $key, int $t): int
    {
        $nos = @imap_search($conn, 'SINCE "' . date('d-M-Y', $t - 2 * 86400) . '" BEFORE "' . date('d-M-Y', $t + 2 * 86400) . '"') ?: [];
        $deadline = microtime(true) + 8;   // a busy iCloud window must not outlast the request
        foreach (array_chunk(array_slice($nos, -1500), 200) as $chunk) {
            if (microtime(true) > $deadline) break;
            foreach (@imap_fetch_overview($conn, implode(',', $chunk)) ?: [] as $o) {
                if (trim((string)($o->message_id ?? '')) === $key) return (int)$o->msgno;
            }
        }
        return 0;
    }

    /**
     * The row behind a card: a client reply / statement / contact card by its key, a "who is this" card by its id.
     * Only what is in Yui's queue right now. @return array<string, mixed>|null
     */
    public static function rowFor(PDO $db, string $key, int $id): ?array
    {
        if ($id > 0) {
            $st = $db->prepare("SELECT message_key, mailbox, sent_at, snippet FROM inbound_unplaced WHERE id = ? AND status = 'open' AND kind = 'person'");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($key === '') return null;
        require_once __DIR__ . '/YuiDeskService.php';
        foreach ((new YuiDeskService($db))->inbox() as $it) {
            if (($it['key'] ?? '') !== $key || empty($it['message_key'])) continue;
            $st = $db->prepare('SELECT message_key, mailbox, sent_at, snippet FROM sales_messages WHERE message_key = ? LIMIT 1');
            $st->execute([(string)$it['message_key']]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return null;
    }
}
