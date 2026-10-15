<?php
/**
 * ImapWriter — the ONLY code in the CRM allowed to change a mailbox (MailboxReadOnlyTest
 * fails if any other file opens a mailbox read-write or calls a writing imap_* function).
 * Used by the "Tidy iCloud" assistant (MailTidyService), only on Tim's click or with his
 * "Keep it tidy" switch on.
 *
 * What it can do — and nothing else:
 *   - create a folder that does not exist yet (imap_createmailbox);
 *   - MOVE messages by UID (imap_mail_move + CP_UID).
 * It never deletes a message or a folder, never renames, never sets or clears a flag
 * (read/unread/flagged are untouched — COPY keeps them), never appends.
 *
 * How MOVE works on c-client: COPY to the target, mark the source copies \Deleted, then
 * EXPUNGE. EXPUNGE removes EVERY \Deleted message in the folder, so:
 *   - before moving, the source folder must have no \Deleted messages of its own — if it
 *     has any, the move is refused (Tim's own deletions are never expunged by us);
 *   - after moving, EXPUNGE runs only if the \Deleted set is exactly the UIDs we moved.
 *
 * Protected folders (Sent, Drafts, Notes, Deleted / Trash) are never a source or a target.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/ImapReader.php';

class ImapWriter
{
    /** Never moved from or to (lower case; "Notes/…" sub-folders too). */
    public const PROTECTED = ['sent messages', 'sent', 'sent items', 'sent mail', 'drafts', 'notes',
                              'deleted items', 'deleted messages', 'trash', 'outbox'];
    public const MAX_UIDS = 500;

    private array $mb;
    /** @var callable */
    private $log;
    /** @var resource|\IMAP\Connection|null */
    private $conn = null;
    private string $current = '';

    public function __construct(array $mb, ?callable $log = null)
    {
        $this->mb = $mb;
        $this->log = $log ?? static function (string $m): void {};
    }

    public static function isProtected(string $folder): bool
    {
        $f = strtolower(trim($folder));
        if ($f === '') return true;
        if (in_array($f, self::PROTECTED, true)) return true;
        $top = explode('/', $f)[0];
        return in_array($top, self::PROTECTED, true) || strpos($f, 'inbox.sent') === 0 || strpos($f, 'inbox.drafts') === 0;
    }

    /** Read-write connection to one folder (SELECT, not EXAMINE). */
    private function select(string $folder)
    {
        if ($this->conn !== null && $this->current === $folder) return $this->conn;
        $this->close();
        ImapReader::timeouts();
        $ref = ImapReader::serverRef($this->mb);
        // Read-write on purpose: options 0 (no OP_READONLY). Only this class may do this.
        $c = @imap_open($ref . $folder, $this->mb['user'], $this->mb['pass'], 0, 1);
        if ($c === false) $c = @imap_open(ImapReader::serverRef($this->mb, true) . $folder, $this->mb['user'], $this->mb['pass'], 0, 1);
        if ($c === false) throw new RuntimeException('could not open ' . $folder . ' for filing');
        $this->conn = $c;
        $this->current = $folder;
        return $c;
    }

    public function close(): void
    {
        if ($this->conn !== null) @imap_close($this->conn);   // plain close — never expunges on close
        $this->conn = null;
        $this->current = '';
    }

    /** Create a folder unless it exists. */
    public function createFolder(string $name, array $existing): bool
    {
        if (in_array($name, $existing, true)) return true;
        if (self::isProtected($name) || !preg_match('/^[\x20-\x7e]{1,120}$/', $name)) return false;
        $c = $this->select('INBOX');
        $ok = (bool)@imap_createmailbox($c, ImapReader::serverRef($this->mb) . $name);
        ($this->log)(($ok ? 'created folder ' : 'could not create folder ') . $name);
        return $ok;
    }

    /**
     * Move UIDs from one folder to another.
     * @param int[] $uids
     * @return array{moved: int[], error: ?string}
     */
    public function move(string $from, array $uids, string $to): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (!$uids) return ['moved' => [], 'error' => null];
        if (count($uids) > self::MAX_UIDS) return ['moved' => [], 'error' => 'too many at once'];
        if (self::isProtected($from) || self::isProtected($to)) return ['moved' => [], 'error' => 'protected folder'];
        if ($from === $to) return ['moved' => [], 'error' => 'same folder'];

        $c = $this->select($from);
        $before = @imap_search($c, 'DELETED', SE_UID);
        if (is_array($before) && $before) {
            return ['moved' => [], 'error' => count($before) . ' message(s) in ' . $from . ' are already marked deleted — left alone so they are not purged'];
        }
        if (!@imap_mail_move($c, implode(',', $uids), $to, CP_UID)) {
            return ['moved' => [], 'error' => 'move refused: ' . implode('; ', imap_errors() ?: ['unknown'])];
        }
        $after = @imap_search($c, 'DELETED', SE_UID);
        $after = is_array($after) ? array_map('intval', $after) : [];
        $extra = array_diff($after, $uids);
        if ($extra) {
            ($this->log)('not expunging ' . $from . ': ' . count($extra) . ' other deleted message(s) appeared');
            return ['moved' => array_values(array_intersect($uids, $after)), 'error' => 'copied; source copies left marked (another deletion appeared)'];
        }
        @imap_expunge($c);   // removes exactly the source copies of what we just moved
        return ['moved' => $uids, 'error' => null];
    }
}
