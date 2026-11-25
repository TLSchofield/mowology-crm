<?php
declare(strict_types=1);

/**
 * An in-memory mailbox for MailTidyService tests. Moves give messages new UIDs in the
 * target folder (as IMAP does) and keep their Message-ID, so undo has to search for them.
 */
class FakeTidyPort implements MailTidyPort
{
    /** name => ['uidvalidity' => int, 'next' => int, 'msgs' => [uid => msg]] */
    public array $boxes = [];
    public array $moves = [];
    public array $created = [];
    public array $headerFetches = [];
    public array $bodyFetches = [];
    /** folder => error string returned by move() (simulates ImapWriter refusing) */
    public array $refuse = [];

    public function __construct(array $folders)
    {
        foreach ($folders as $f) $this->boxes[$f] = ['uidvalidity' => 1000 + count($this->boxes), 'next' => 1, 'msgs' => []];
    }

    /** Add a message; returns its UID. */
    public function add(string $folder, array $m): int
    {
        $uid = $this->boxes[$folder]['next']++;
        $this->boxes[$folder]['msgs'][$uid] = $m + ['uid' => $uid, 'to' => 'mowology@icloud.com', 'subject' => '', 'headers' => '', 'body' => '',
                                                    'udate' => strtotime('2026-01-15'), 'date' => 'Thu, 15 Jan 2026 10:00:00 -0800'];
        $this->boxes[$folder]['msgs'][$uid]['uid'] = $uid;
        return $uid;
    }

    public function folders(): array { return array_keys($this->boxes); }

    public function folderInfo(string $folder): ?array
    {
        $b = $this->boxes[$folder] ?? null;
        return $b ? ['messages' => count($b['msgs']), 'uidvalidity' => $b['uidvalidity'], 'uidnext' => $b['next']] : null;
    }

    public function scan(string $folder, int $afterUid, int $limit): array
    {
        $out = [];
        foreach ($this->boxes[$folder]['msgs'] ?? [] as $uid => $m) {
            if ($uid <= $afterUid) continue;
            $out[] = ['uid' => $uid, 'from' => $m['from'], 'to' => $m['to'], 'subject' => $m['subject'], 'date' => $m['date'],
                      'udate' => $m['udate'], 'message_id' => $m['message_id'] ?? null];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public function headers(string $folder, int $uid): string
    {
        $this->headerFetches[] = "$folder#$uid";
        return (string)($this->boxes[$folder]['msgs'][$uid]['headers'] ?? '');
    }

    public function body(string $folder, int $uid): string
    {
        $this->bodyFetches[] = "$folder#$uid";
        return (string)($this->boxes[$folder]['msgs'][$uid]['body'] ?? '');
    }

    public function messageIds(string $folder, array $uids): array
    {
        $out = [];
        foreach ($uids as $u) {
            if (isset($this->boxes[$folder]['msgs'][$u])) $out[$u] = (string)($this->boxes[$folder]['msgs'][$u]['message_id'] ?? '');
        }
        return $out;
    }

    public function findByMessageId(string $folder, string $messageId): array
    {
        $out = [];
        foreach ($this->boxes[$folder]['msgs'] ?? [] as $uid => $m) if (($m['message_id'] ?? '') === $messageId) $out[] = $uid;
        return $out;
    }

    public function createFolder(string $name): bool
    {
        if (ImapWriter::isProtected($name)) return false;
        if (!isset($this->boxes[$name])) {
            $this->boxes[$name] = ['uidvalidity' => 9000 + count($this->boxes), 'next' => 1, 'msgs' => []];
            $this->created[] = $name;
        }
        return true;
    }

    public function move(string $from, array $uids, string $to): array
    {
        if (ImapWriter::isProtected($from) || ImapWriter::isProtected($to)) return ['moved' => [], 'error' => 'protected folder'];
        if (isset($this->refuse[$from])) return ['moved' => [], 'error' => $this->refuse[$from]];
        if (!isset($this->boxes[$to])) return ['moved' => [], 'error' => 'no such folder'];
        $moved = [];
        foreach ($uids as $u) {
            $m = $this->boxes[$from]['msgs'][$u] ?? null;
            if ($m === null) continue;
            unset($this->boxes[$from]['msgs'][$u]);
            $this->add($to, $m);
            $moved[] = (int)$u;
        }
        $this->moves[] = [$from, $moved, $to];
        return ['moved' => $moved, 'error' => null];
    }

    public function close(): void {}

    /** Message-IDs in a folder (assert helper). */
    public function ids(string $folder): array
    {
        return array_values(array_map(fn($m) => $m['message_id'] ?? '', $this->boxes[$folder]['msgs'] ?? []));
    }
}
