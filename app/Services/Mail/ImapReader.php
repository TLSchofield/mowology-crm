<?php
/**
 * ImapReader — the read-only IMAP plumbing every inbox reader shares.
 *
 * READ-ONLY, always: mailboxes are opened with OP_READONLY (an IMAP EXAMINE, so the server
 * cannot set \Seen) and every body/part fetch passes FT_PEEK. Nothing here marks, moves,
 * flags, expunges or deletes mail — office@ and Tim's iCloud are read by people.
 * ImapReadOnlyTest scans the readers for any open/fetch that breaks this.
 *
 * The MIME helpers (decode / mime / param / bodyPart / attachments) used to live as
 * functions inside receipt_inbox_poll.php; they are here so the iCloud router can use the
 * same attachment walk. Pure parts take the stdClass structures imap_fetchstructure returns
 * and are unit tested without the imap extension.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class ImapReader
{
    /** OP_READONLY / FT_PEEK, with their values as a fallback where ext-imap is missing (tests). */
    public static function readOnlyFlag(): int
    {
        return defined('OP_READONLY') ? (int)OP_READONLY : 2;
    }

    public static function peekFlag(): int
    {
        return defined('FT_PEEK') ? (int)FT_PEEK : 2;
    }

    /** "{host:993/imap/ssl}" for a MailboxConfig entry. */
    public static function serverRef(array $mb, bool $novalidate = false): string
    {
        return '{' . $mb['host'] . ':' . (int)($mb['port'] ?? 993) . '/imap/ssl' . ($novalidate ? '/novalidate-cert' : '') . '}';
    }

    /** Bounded timeouts so a hung server can never hang a cron. */
    public static function timeouts(): void
    {
        if (!function_exists('imap_timeout')) return;
        imap_timeout(IMAP_OPENTIMEOUT, 15);
        imap_timeout(IMAP_READTIMEOUT, 20);
        imap_timeout(IMAP_WRITETIMEOUT, 20);
        imap_timeout(IMAP_CLOSETIMEOUT, 10);
    }

    /**
     * Open one folder of a mailbox READ-ONLY (falls back to novalidate-cert, as the readers did).
     * @return resource|\IMAP\Connection|false
     */
    public static function open(array $mb, string $folder = 'INBOX')
    {
        $flags = self::readOnlyFlag();
        $c = @imap_open(self::serverRef($mb) . $folder, $mb['user'], $mb['pass'], $flags, 1);
        if ($c === false) $c = @imap_open(self::serverRef($mb, true) . $folder, $mb['user'], $mb['pass'], $flags, 1);
        return $c;
    }

    /** Folder names (server prefix stripped). */
    public static function folders($conn, array $mb): array
    {
        $out = [];
        foreach ((array)@imap_list($conn, self::serverRef($mb), '*') as $full) {
            $out[] = (string)preg_replace('/^\{[^}]*\}/', '', (string)$full);
        }
        return $out;
    }

    /**
     * The sent folder among a server's folders: "Sent Messages" (iCloud), "Sent", "INBOX.Sent",
     * "Sent Items", "[Gmail]/Sent Mail". Exact names first, then the first that looks sent.
     */
    public static function pickSentFolder(array $names): ?string
    {
        foreach (['Sent Messages', 'Sent', 'INBOX.Sent', 'Sent Items', 'Sent Mail'] as $want) {
            foreach ($names as $n) if (strcasecmp($n, $want) === 0) return $n;
        }
        foreach ($names as $n) {
            if (preg_match('/(^|[.\/])Sent( Items| Messages| Mail)?$/i', $n)) return $n;
        }
        return null;
    }

    /** Decode a MIME part by its transfer-encoding (3 = base64, 4 = quoted-printable). */
    public static function decode(string $data, int $enc): string
    {
        if ($enc === 3) return (string)base64_decode($data);
        if ($enc === 4) return quoted_printable_decode($data);
        return $data;
    }

    /** "application/pdf" from an IMAP part's numeric type + subtype. */
    public static function mime($part): string
    {
        $primary = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
                    4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'][(int)($part->type ?? 7)] ?? 'other';
        return $primary . '/' . strtolower((string)($part->subtype ?? ''));
    }

    /** A parameter (filename, name, charset) from a part's parameter lists. */
    public static function param($part, string $key): ?string
    {
        foreach (['parameters' => 'ifparameters', 'dparameters' => 'ifdparameters'] as $list => $flag) {
            if (!empty($part->$flag) && !empty($part->$list)) {
                foreach ($part->$list as $p) {
                    if (strtolower((string)$p->attribute) === strtolower($key)) return (string)$p->value;
                }
            }
        }
        return null;
    }

    /**
     * The email's own text part — HTML preferred, else plain — never an attachment.
     * @return array{pn: string, encoding: int, charset: string, html: bool}|null
     */
    public static function bodyPart($part, string $pn = ''): ?array
    {
        if (!empty($part->parts)) {
            $plain = null;
            foreach ($part->parts as $i => $child) {
                $hit = self::bodyPart($child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1));
                if ($hit && $hit['html']) return $hit;
                if ($hit && !$plain) $plain = $hit;
            }
            return $plain;
        }
        if ((int)($part->type ?? 7) !== 0) return null;
        $sub = strtolower((string)($part->subtype ?? ''));
        if ($sub !== 'html' && $sub !== 'plain') return null;
        if (strtolower((string)($part->disposition ?? '')) === 'attachment') return null;
        return ['pn' => $pn ?: '1', 'encoding' => (int)($part->encoding ?? 0),
                'charset' => strtoupper((string)(self::param($part, 'charset') ?? 'UTF-8')), 'html' => $sub === 'html'];
    }

    /**
     * PDF + image attachments in a structure.
     * @return array<int, array{pn: string, filename: string, mime: string, encoding: int}>
     */
    public static function attachments($part, string $pn = ''): array
    {
        $acc = [];
        self::walk($part, $pn, $acc);
        return $acc;
    }

    private static function walk($part, string $pn, array &$acc): void
    {
        if (!empty($part->parts)) {
            foreach ($part->parts as $i => $child) {
                self::walk($child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1), $acc);
            }
            return;
        }
        $type = (int)($part->type ?? 7);
        $sub  = strtolower((string)($part->subtype ?? ''));
        $disp = strtolower((string)($part->disposition ?? ''));
        $name = self::param($part, 'filename') ?? self::param($part, 'name');
        $isPdf   = ($type === 3 && $sub === 'pdf');
        $isImage = ($type === 5 && in_array($sub, ['jpeg', 'jpg', 'png', 'gif', 'webp', 'heic', 'heif'], true));
        if (($isPdf || $isImage) && ($disp === 'attachment' || $disp === 'inline' || $name !== null)) {
            $acc[] = ['pn' => $pn ?: '1', 'filename' => $name ?: ($isPdf ? 'receipt.pdf' : 'receipt.jpg'),
                      'mime' => self::mime($part), 'encoding' => (int)($part->encoding ?? 0)];
        }
    }

    /** One part's decoded bytes, fetched with FT_PEEK (never marks the mail read). */
    public static function fetchPart($conn, int $no, string $pn, int $encoding): string
    {
        return self::decode((string)imap_fetchbody($conn, $no, $pn, self::peekFlag()), $encoding);
    }

    /**
     * The message's text: first text/plain, else text/html — UTF-8, fetched with FT_PEEK.
     * Pass $struct when the caller already has it.
     */
    public static function textBody($conn, int $no, $struct = null): string
    {
        $st = $struct ?: @imap_fetchstructure($conn, $no);
        if (!$st) return '';
        $acc = ['plain' => '', 'html' => ''];
        $walk = function ($part, string $pn) use (&$walk, &$acc, $conn, $no): void {
            if (!empty($part->parts)) {
                foreach ($part->parts as $i => $child) $walk($child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1));
                return;
            }
            $type = strtolower((string)($part->subtype ?? ''));
            if (!in_array($type, ['plain', 'html'], true) || $acc[$type] !== '') return;
            if (strtolower((string)($part->disposition ?? '')) === 'attachment') return;
            $data = self::fetchPart($conn, $no, $pn ?: '1', (int)($part->encoding ?? 0));
            $cs = self::param($part, 'charset') ?? '';
            if ($cs !== '' && strtoupper($cs) !== 'UTF-8') $data = (string)@mb_convert_encoding($data, 'UTF-8', $cs);
            $acc[$type] = $data;
        };
        if (!empty($st->parts)) {
            $walk($st, '');
        } else {
            $acc['plain'] = self::decode((string)imap_body($conn, $no, self::peekFlag()), (int)($st->encoding ?? 0));
            $cs = self::param($st, 'charset') ?? '';
            if ($cs !== '' && strtoupper($cs) !== 'UTF-8') $acc['plain'] = (string)@mb_convert_encoding($acc['plain'], 'UTF-8', $cs);
            if (strtolower((string)($st->subtype ?? '')) === 'html') { $acc['html'] = $acc['plain']; $acc['plain'] = ''; }
        }
        return $acc['plain'] !== '' ? $acc['plain'] : $acc['html'];
    }

    /** The raw header block (List-Unsubscribe, Precedence…). Headers never set \Seen. */
    public static function rawHeaders($conn, int $no): string
    {
        return (string)@imap_fetchheader($conn, $no);
    }

    /** One header's value from a raw header block (unfolded), or ''. */
    public static function header(string $raw, string $name): string
    {
        $raw = (string)preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/mi', $raw, $m) ? trim($m[1]) : '';
    }
}
