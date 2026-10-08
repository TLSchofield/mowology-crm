<?php
/**
 * ImapClient — the few IMAP calls IcloudInboxRouter makes, behind one small object so the
 * router can be run against a fake mailbox in tests (tests/Unit/Comms/FakeImapClient.php).
 *
 * READ-ONLY, always (MailboxReadOnlyTest scans this file):
 *   - folders are opened / switched with OP_READONLY (ImapReader::open / ::reopen — EXAMINE);
 *   - envelopes come in bulk from imap_fetch_overview(…, FT_UID) — one round trip per chunk;
 *   - headers via imap_fetchheader WITHOUT FT_PREFETCHTEXT (that flag would pull the text);
 *   - bodies and parts via ImapReader (FT_PEEK).
 * Nothing here marks, moves, flags or deletes mail.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/ImapReader.php';

class ImapClient
{
    /** @return resource|\IMAP\Connection|false */
    public function open(array $mb, string $folder = 'INBOX')
    {
        ImapReader::timeouts();
        return ImapReader::open($mb, $folder);
    }

    /** Switch an open connection to another folder, read-only. */
    public function reopen($conn, array $mb, string $folder): bool
    {
        return ImapReader::reopen($conn, $mb, $folder);
    }

    public function close($conn): void
    {
        @imap_close($conn);
    }

    /** @return string[] */
    public function errors(): array
    {
        return function_exists('imap_errors') ? (imap_errors() ?: []) : [];
    }

    /**
     * Every folder with its LATT_* attribute bits (server prefix stripped).
     * @return array<int, array{name: string, noselect: bool}>
     */
    public function folders($conn, array $mb): array
    {
        $out = [];
        $boxes = @imap_getmailboxes($conn, ImapReader::serverRef($mb), '*');
        if (is_array($boxes)) {
            foreach ($boxes as $b) {
                $out[] = ['name' => (string)preg_replace('/^\{[^}]*\}/', '', (string)$b->name),
                          'noselect' => defined('LATT_NOSELECT') && ((int)$b->attributes & LATT_NOSELECT) !== 0];
            }
            return $out;
        }
        foreach (ImapReader::folders($conn, $mb) as $n) $out[] = ['name' => $n, 'noselect' => false];
        return $out;
    }

    public function uidValidity($conn, array $mb, string $folder): ?int
    {
        $st = @imap_status($conn, ImapReader::serverRef($mb) . $folder, SA_UIDVALIDITY);
        return $st ? (int)$st->uidvalidity : null;
    }

    /** UIDs of the open folder's messages that arrived on/after a day (IMAP SINCE). */
    public function searchSince($conn, int $ts): array
    {
        $found = @imap_search($conn, 'SINCE "' . date('d-M-Y', $ts) . '"', SE_UID);
        return is_array($found) ? array_map('intval', $found) : [];
    }

    /**
     * Envelopes for a UID set ("1:5,9") in ONE round trip — never a body, never a flag change.
     * @return array<int, array{uid: int, msgno: int, from: string, to: string, subject: string,
     *                          date: string, message_id: ?string, udate: int, size: int}>
     */
    public function overview($conn, string $uidSet): array
    {
        $rows = @imap_fetch_overview($conn, $uidSet, FT_UID);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $o) {
            $out[] = [
                'uid'        => (int)($o->uid ?? 0),
                'msgno'      => (int)($o->msgno ?? 0),
                'from'       => isset($o->from) ? imap_utf8((string)$o->from) : '',
                'to'         => isset($o->to) ? imap_utf8((string)$o->to) : '',
                'subject'    => isset($o->subject) ? imap_utf8((string)$o->subject) : '',
                'date'       => (string)($o->date ?? ''),
                'message_id' => isset($o->message_id) ? (string)$o->message_id : null,
                'udate'      => (int)($o->udate ?? 0),
                'size'       => (int)($o->size ?? 0),
            ];
        }
        return $out;
    }

    /** The raw header block of one message (no FT_PREFETCHTEXT: headers only, never sets \Seen). */
    public function header($conn, int $msgno): string
    {
        return (string)@imap_fetchheader($conn, $msgno);
    }

    public function structure($conn, int $msgno)
    {
        return @imap_fetchstructure($conn, $msgno) ?: null;
    }

    public function textBody($conn, int $msgno, $struct): string
    {
        return ImapReader::textBody($conn, $msgno, $struct);
    }

    public function fetchPart($conn, int $msgno, string $pn, int $encoding): string
    {
        return ImapReader::fetchPart($conn, $msgno, $pn, $encoding);
    }
}
