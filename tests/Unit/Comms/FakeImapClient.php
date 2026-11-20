<?php
declare(strict_types=1);

/**
 * A mailbox in memory with ImapClient's methods. Every call is a "round trip": it is counted
 * and advances the fake clock by $cost seconds, so time budgets can be tested without a server.
 *
 * $folders: name => ['uidvalidity' => int, 'noselect' => bool, 'messages' => [uid => [
 *     'from', 'to', 'subject', 'date', 'message_id', 'udate', 'header' (raw), 'struct', 'body']]]
 */
class FakeImapClient
{
    public array $folders;
    public float $time;
    public float $cost;
    /** @var array<string, int> method => calls */
    public array $calls = [];
    /** @var string[] UID sets passed to overview() */
    public array $overviewSets = [];
    private ?string $current = null;

    public function __construct(array $folders, float $cost = 0.0)
    {
        $this->folders = $folders;
        $this->cost = $cost;
        $this->time = (float)strtotime('2026-10-07 12:00:00 UTC');
    }

    public function clock(): callable
    {
        return function (): float { return $this->time; };
    }

    private function trip(string $m): void
    {
        $this->calls[$m] = ($this->calls[$m] ?? 0) + 1;
        $this->time += $this->cost;
    }

    public function calls(string $m): int
    {
        return $this->calls[$m] ?? 0;
    }

    public function open(array $mb, string $folder = 'INBOX')
    {
        $this->trip('open');
        $this->current = $folder;
        return 'conn';
    }

    public function reopen($conn, array $mb, string $folder): bool
    {
        $this->trip('reopen');
        if (!isset($this->folders[$folder])) return false;
        $this->current = $folder;
        return true;
    }

    public function close($conn): void {}

    public function errors(): array { return []; }

    public function folders($conn, array $mb): array
    {
        $this->trip('folders');
        $out = [];
        foreach ($this->folders as $n => $f) $out[] = ['name' => (string)$n, 'noselect' => !empty($f['noselect'])];
        return $out;
    }

    public function uidValidity($conn, array $mb, string $folder): ?int
    {
        $this->trip('uidValidity');
        return $this->folders[$folder]['uidvalidity'] ?? null;
    }

    public function searchSince($conn, int $ts): array
    {
        $this->trip('searchSince');
        $out = [];
        foreach ($this->msgs() as $uid => $m) {
            if ((int)($m['udate'] ?? $this->time) >= $ts - 86400) $out[] = (int)$uid;   // IMAP SINCE is by day
        }
        return $out;
    }

    public function overview($conn, string $uidSet): array
    {
        $this->trip('overview');
        $this->overviewSets[] = $uidSet;
        $want = [];
        foreach (explode(',', $uidSet) as $part) {
            [$a, $b] = array_pad(explode(':', $part), 2, null);
            $b = $b ?? $a;
            for ($u = (int)$a; $u <= (int)$b; $u++) $want[$u] = true;
        }
        $out = [];
        $no = 0;
        foreach ($this->msgs() as $uid => $m) {
            $no++;
            if (!isset($want[(int)$uid])) continue;
            $out[] = ['uid' => (int)$uid, 'msgno' => $no, 'from' => $m['from'] ?? '', 'to' => $m['to'] ?? '',
                      'subject' => $m['subject'] ?? '', 'date' => $m['date'] ?? 'Tue, 6 Oct 2026 10:00:00 -0700',
                      'message_id' => $m['message_id'] ?? null, 'udate' => (int)($m['udate'] ?? $this->time), 'size' => 1000];
        }
        return $out;
    }

    public function header($conn, int $msgno): string
    {
        $this->trip('header');
        return (string)($this->byNo($msgno)['header'] ?? '');
    }

    public function structure($conn, int $msgno)
    {
        $this->trip('structure');
        return $this->byNo($msgno)['struct'] ?? (object)['type' => 0, 'subtype' => 'PLAIN'];
    }

    public function textBody($conn, int $msgno, $struct): string
    {
        $this->trip('textBody');
        return (string)($this->byNo($msgno)['body'] ?? '');
    }

    public function fetchPart($conn, int $msgno, string $pn, int $encoding): string
    {
        $this->trip('fetchPart');
        return '';
    }

    private function msgs(): array
    {
        $m = $this->folders[$this->current]['messages'] ?? [];
        ksort($m);
        return $m;
    }

    private function byNo(int $no): array
    {
        $i = 0;
        foreach ($this->msgs() as $m) if (++$i === $no) return $m;
        return [];
    }
}
