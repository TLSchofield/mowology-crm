<?php
/**
 * ImapTidyPort — the real mailbox behind MailTidyService.
 *
 * Reads are READ-ONLY (ImapReader::open = OP_READONLY / EXAMINE): envelopes come in bulk
 * from imap_fetch_overview(…, FT_UID) — one Apple round trip per chunk, which is what makes
 * a 71,827-message INBOX previewable — headers without FT_PREFETCHTEXT, bodies with FT_PEEK.
 * Every change (create a folder, move) goes through ImapWriter, the one writing class.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MailTidyPort.php';
require_once __DIR__ . '/../../../Services/Mail/ImapReader.php';
require_once __DIR__ . '/../../../Services/Mail/ImapWriter.php';

class ImapTidyPort implements MailTidyPort
{
    private array $mb;
    private ImapWriter $writer;
    /** @var resource|\IMAP\Connection|null */
    private $conn = null;
    private string $current = '';
    private ?array $folderList = null;

    public function __construct(array $mb, ?callable $log = null)
    {
        $this->mb = $mb;
        $this->writer = new ImapWriter($mb, $log);
    }

    /** Read-only connection to a folder. */
    private function ro(string $folder)
    {
        if ($this->conn !== null && $this->current === $folder) return $this->conn;
        if ($this->conn !== null) @imap_close($this->conn);
        ImapReader::timeouts();
        $c = ImapReader::open($this->mb, $folder);
        if ($c === false) { $this->conn = null; $this->current = ''; throw new RuntimeException('could not open ' . $folder); }
        $this->conn = $c;
        $this->current = $folder;
        return $c;
    }

    public function folders(): array
    {
        if ($this->folderList === null) $this->folderList = ImapReader::folders($this->ro('INBOX'), $this->mb);
        return $this->folderList;
    }

    public function folderInfo(string $folder): ?array
    {
        $c = $this->ro($this->current !== '' ? $this->current : 'INBOX');
        $st = @imap_status($c, ImapReader::serverRef($this->mb) . $folder, SA_MESSAGES | SA_UIDVALIDITY | SA_UIDNEXT);
        if (!$st) return null;
        return ['messages' => (int)$st->messages, 'uidvalidity' => (int)$st->uidvalidity, 'uidnext' => (int)$st->uidnext];
    }

    public function scan(string $folder, int $afterUid, int $limit): array
    {
        $info = $this->folderInfo($folder);
        if ($info === null) return [];
        $c = $this->ro($folder);
        $out = [];
        $start = $afterUid + 1;
        // UIDs are sparse: walk windows until we have enough or pass UIDNEXT.
        while (count($out) < $limit && $start < $info['uidnext']) {
            $end = min($info['uidnext'] - 1, $start + max($limit, 200) - 1);
            $rows = @imap_fetch_overview($c, $start . ':' . $end, FT_UID);
            foreach (is_array($rows) ? $rows : [] as $o) {
                $uid = (int)($o->uid ?? 0);
                if ($uid <= $afterUid) continue;
                $out[$uid] = [
                    'uid' => $uid,
                    'from' => isset($o->from) ? self::decode((string)$o->from) : '',
                    'to' => isset($o->to) ? self::decode((string)$o->to) : '',
                    'subject' => isset($o->subject) ? self::decode((string)$o->subject) : '',
                    'date' => (string)($o->date ?? ''),
                    'udate' => (int)($o->udate ?? 0),
                    'message_id' => isset($o->message_id) ? trim((string)$o->message_id) : null,
                ];
            }
            $start = $end + 1;
        }
        ksort($out);
        return array_slice(array_values($out), 0, $limit);
    }

    private static function decode(string $s): string
    {
        return function_exists('imap_utf8') ? (string)imap_utf8($s) : $s;
    }

    private function msgno(string $folder, int $uid): int
    {
        return (int)@imap_msgno($this->ro($folder), $uid);
    }

    public function headers(string $folder, int $uid): string
    {
        $no = $this->msgno($folder, $uid);
        return $no > 0 ? (string)@imap_fetchheader($this->conn, $no) : '';
    }

    public function body(string $folder, int $uid): string
    {
        $no = $this->msgno($folder, $uid);
        return $no > 0 ? ImapReader::textBody($this->conn, $no) : '';
    }

    public function messageIds(string $folder, array $uids): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        if (!$uids) return [];
        $rows = @imap_fetch_overview($this->ro($folder), implode(',', $uids), FT_UID);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $o) {
            $out[(int)$o->uid] = isset($o->message_id) ? trim((string)$o->message_id) : '';
        }
        return $out;
    }

    public function findByMessageId(string $folder, string $messageId): array
    {
        if ($messageId === '' || preg_match('/["\\\\\r\n]/', $messageId)) return [];
        $found = @imap_search($this->ro($folder), 'HEADER Message-ID "' . $messageId . '"', SE_UID);
        return is_array($found) ? array_map('intval', $found) : [];
    }

    public function createFolder(string $name): bool
    {
        $ok = $this->writer->createFolder($name, $this->folders());
        if ($ok && !in_array($name, $this->folderList ?? [], true)) $this->folderList[] = $name;
        return $ok;
    }

    public function move(string $from, array $uids, string $to): array
    {
        // Our read-only view of $from is stale after a move — drop it.
        if ($this->current === $from && $this->conn !== null) { @imap_close($this->conn); $this->conn = null; $this->current = ''; }
        return $this->writer->move($from, $uids, $to);
    }

    public function close(): void
    {
        if ($this->conn !== null) @imap_close($this->conn);
        $this->conn = null;
        $this->current = '';
        $this->writer->close();
    }
}
