<?php
/**
 * MailboxPollLock — one live mailbox read at a time. A long iCloud backfill (cron, up to 10
 * minutes) and the next 15-minute run, or an admin pressing "run now" on the cron page, must
 * never read and store the same folders together.
 *
 * MySQL GET_LOCK (named, connection-scoped: it is released if PHP dies mid-run, so a crash
 * can never leave it stuck). Where GET_LOCK isn't available (SQLite in tests) it falls back to
 * an flock on a temp file — also released when the process ends.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MailboxPollLock
{
    private PDO $db;
    private string $name;
    /** 'db' | 'file' | null (not held) */
    private ?string $held = null;
    /** @var resource|null */
    private $fh = null;

    public function __construct(PDO $db, string $name)
    {
        $this->db = $db;
        $this->name = 'mowology_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    }

    /** true = we hold it now; false = another run holds it (don't wait). */
    public function acquire(): bool
    {
        if ($this->held !== null) return true;
        try {
            $s = $this->db->prepare('SELECT GET_LOCK(?, 0)');
            $s->execute([$this->name]);
            $got = $s->fetchColumn();
            if ($got !== false && $got !== null) {
                if ((int)$got !== 1) return false;
                $this->held = 'db';
                return true;
            }
        } catch (Throwable $e) {
            // No GET_LOCK here — use a lock file instead.
        }
        $fh = @fopen(sys_get_temp_dir() . '/' . $this->name . '.lock', 'c');
        if ($fh === false) return true;   // can't lock at all: don't block the run
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        $this->fh = $fh;
        $this->held = 'file';
        return true;
    }

    public function release(): void
    {
        if ($this->held === 'db') {
            try { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->name]); } catch (Throwable $e) {}
        } elseif ($this->held === 'file' && $this->fh) {
            flock($this->fh, LOCK_UN);
            fclose($this->fh);
            $this->fh = null;
        }
        $this->held = null;
    }
}
