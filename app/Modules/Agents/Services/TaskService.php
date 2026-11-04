<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Services/TenantContext.php';

/**
 * TaskService — the durable task layer under the heads (Ittai audit 2026-10-27, stage 4).
 *
 * A task is one piece of work a head owns: "chase this overdue invoice", "follow up this quote". It lives in
 * agent_tasks (migration 1402), so it survives a restart, is created at most once (dedupe_key is UNIQUE),
 * is claimed atomically, retries a bounded number of times, expires, and records every change in
 * agent_task_events. Detection stays deterministic SQL in the desks; nothing here calls a language model.
 *
 * Guards against runaway work:
 *   - a task may be delegated at most MAX_DEPTH levels deep;
 *   - a delegation chain may not hand a task back to an (owner_head, kind) it already passed through;
 *   - attempts are capped (max_attempts, default 3) — then the task is failed and escalated;
 *   - tasks past expires_at become 'expired'; a task stuck 'working' too long is reopened (counts an attempt).
 *
 * No namespace / no autoloader: require_once and `new TaskService($db)`.
 */
class TaskService
{
    public const MAX_DEPTH = 3;
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_TTL_DAYS = 14;
    public const STUCK_MINUTES = 30;
    public const OPEN_STATES = ['open', 'working', 'waiting_approval'];
    public const MAX_CLOSE_SHARE = 0.5;
    public const MIN_CLOSE_FLOOR = 5;

    private PDO $db;
    private int $site;
    /** @var callable fn(): string  "now" in 'Y-m-d H:i:s' — injectable for tests */
    private $clock;

    public function __construct(PDO $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->site = TenantContext::siteId($db);
        $this->clock = $clock ?? static fn() => date('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return ($this->clock)();
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a task, or return the one that already exists for the same dedupe_key.
     *
     * @param array{kind:string, owner_head:string, origin_head?:string, entity_type?:string, entity_id?:string|int,
     *              title?:string, priority?:string, due_at?:?string, ttl_days?:int, max_attempts?:int,
     *              payload?:array, dedupe_key?:string, correlation_id?:string, parent_task_id?:int} $t
     * @return array{ok:bool, created?:bool, task?:array, error?:string}
     */
    public function create(array $t): array
    {
        $kind = trim((string)($t['kind'] ?? ''));
        $owner = strtolower(trim((string)($t['owner_head'] ?? '')));
        if ($kind === '' || $owner === '') {
            return ['ok' => false, 'error' => 'kind and owner_head are required'];
        }
        $entityType = (string)($t['entity_type'] ?? '');
        $entityId = (string)($t['entity_id'] ?? '');
        $dedupe = trim((string)($t['dedupe_key'] ?? ''));
        if ($dedupe === '') {
            $dedupe = $kind . ':' . $entityType . ':' . $entityId;
        }
        $dedupe = mb_substr($dedupe, 0, 120);

        $depth = 0;
        $corr = (string)($t['correlation_id'] ?? '');
        $parentId = (int)($t['parent_task_id'] ?? 0);
        if ($parentId > 0) {
            $chain = $this->chain($parentId);
            if (!$chain) {
                return ['ok' => false, 'error' => 'parent task not found'];
            }
            $depth = (int)$chain[0]['depth'] + 1;
            if ($depth > self::MAX_DEPTH) {
                return ['ok' => false, 'error' => 'delegation too deep (max ' . self::MAX_DEPTH . ')'];
            }
            foreach ($chain as $anc) {
                if ($anc['owner_head'] === $owner && $anc['kind'] === $kind) {
                    return ['ok' => false, 'error' => 'delegation loop: ' . $owner . '/' . $kind . ' is already in this chain'];
                }
            }
            if ($corr === '') {
                $corr = (string)($chain[0]['correlation_id'] ?? '');
            }
        }
        if ($corr === '') {
            $corr = 'wf_' . bin2hex(random_bytes(6));
        }

        $existing = $this->byDedupe($dedupe);
        if ($existing) {
            return ['ok' => true, 'created' => false, 'task' => $this->reopenIfResolved($existing)];
        }

        $now = $this->now();
        $priority = in_array(($t['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true) ? ($t['priority'] ?? 'normal') : 'normal';
        $ttl = max(1, (int)($t['ttl_days'] ?? self::DEFAULT_TTL_DAYS));
        try {
            $this->db->prepare("INSERT INTO agent_tasks (site_id, kind, owner_head, origin_head, entity_type, entity_id, title, priority, state,
                                    attempts, max_attempts, due_at, expires_at, correlation_id, parent_task_id, depth, dedupe_key, payload_json, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $this->site, mb_substr($kind, 0, 48), mb_substr($owner, 0, 24),
                   mb_substr(strtolower((string)($t['origin_head'] ?? 'system')), 0, 24),
                   mb_substr($entityType, 0, 32), mb_substr($entityId, 0, 64), mb_substr((string)($t['title'] ?? ''), 0, 200),
                   $priority,
                   max(1, min(10, (int)($t['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS))),
                   $t['due_at'] ?? null, date('Y-m-d H:i:s', strtotime($now) + $ttl * 86400),
                   $corr, $parentId ?: null, $depth, $dedupe,
                   isset($t['payload']) ? json_encode($t['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                   $now, $now,
               ]);
        } catch (PDOException $e) {
            // Lost a race with another run: the row exists now, which is the answer.
            $existing = $this->byDedupe($dedupe);
            if ($existing) {
                return ['ok' => true, 'created' => false, 'task' => $this->reopenIfResolved($existing)];
            }
            throw $e;
        }
        $id = (int)$this->db->lastInsertId();
        $this->event($id, 'created', null, 'open', (string)($t['origin_head'] ?? 'system'), null);
        return ['ok' => true, 'created' => true, 'task' => $this->get($id)];
    }

    // ── Transitions ──────────────────────────────────────────────────────────

    /** Atomic: only the owner head, only from 'open'. Counts an attempt. */
    public function claim(int $id, string $head): bool
    {
        $now = $this->now();
        $s = $this->db->prepare("UPDATE agent_tasks SET state = 'working', attempts = attempts + 1, claimed_at = ?, updated_at = ?
                                  WHERE id = ? AND site_id = ? AND state = 'open' AND owner_head = ?");
        $s->execute([$now, $now, $id, $this->site, strtolower($head)]);
        if ($s->rowCount() !== 1) {
            return false;
        }
        $this->event($id, 'claimed', 'open', 'working', $head, null);
        return true;
    }

    public function requestApproval(int $id, string $head, ?string $note = null): bool
    {
        return $this->move($id, ['working', 'open'], 'waiting_approval', 'approval_requested', $head, $note);
    }

    public function complete(int $id, string $head, array $result = []): bool
    {
        $now = $this->now();
        $s = $this->db->prepare("UPDATE agent_tasks SET state = 'done', completed_at = ?, updated_at = ?, result_json = ?, error = NULL
                                  WHERE id = ? AND site_id = ? AND state IN ('open','working','waiting_approval')");
        $s->execute([$now, $now, $result ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $id, $this->site]);
        if ($s->rowCount() !== 1) {
            return false;
        }
        $this->event($id, 'completed', null, 'done', $head, null);
        return true;
    }

    /**
     * A failed attempt of a task a head is WORKING on (claim() counted the attempt): back to 'open' for another try,
     * or 'failed' + escalated once attempts are used up. The update only applies if the task is still 'working', so a
     * concurrent complete/cancel is never overwritten.
     * @return string the new state ('open' | 'failed'), or '' when the task was not in the 'working' state
     */
    public function fail(int $id, string $head, string $error): string
    {
        $t = $this->get($id);
        if (!$t || $t['state'] !== 'working') {
            return '';
        }
        $exhausted = (int)$t['attempts'] >= (int)$t['max_attempts'];
        $to = $exhausted ? 'failed' : 'open';
        $s = $this->db->prepare("UPDATE agent_tasks SET state = ?, error = ?, escalated = ?, updated_at = ? WHERE id = ? AND site_id = ? AND state = 'working'");
        $s->execute([$to, mb_substr($error, 0, 255), $exhausted ? 1 : (int)$t['escalated'], $this->now(), $id, $this->site]);
        if ($s->rowCount() !== 1) {
            return '';
        }
        $this->event($id, $exhausted ? 'failed' : 'retry', 'working', $to, $head, mb_substr($error, 0, 255));
        return $to;
    }

    public function cancel(int $id, string $head, string $reason): bool
    {
        return $this->move($id, self::OPEN_STATES, 'cancelled', 'cancelled', $head, $reason);
    }

    /** Raise it to the owner: flagged, priority high at least. Works in any open state. */
    public function escalate(int $id, string $head, string $reason): bool
    {
        $now = $this->now();
        $s = $this->db->prepare("UPDATE agent_tasks SET escalated = 1, priority = CASE WHEN priority IN ('low','normal') THEN 'high' ELSE priority END,
                                        error = ?, updated_at = ? WHERE id = ? AND site_id = ? AND state IN ('open','working','waiting_approval','failed')");
        $s->execute([mb_substr($reason, 0, 255), $now, $id, $this->site]);
        if ($s->rowCount() !== 1) {
            return false;
        }
        $this->event($id, 'escalated', null, null, $head, mb_substr($reason, 0, 255));
        return true;
    }

    // ── Housekeeping (run by the cron) ───────────────────────────────────────

    /** Tasks past expires_at become 'expired'. @return int how many */
    public function expire(): int
    {
        $now = $this->now();
        $ids = $this->ids("SELECT id FROM agent_tasks WHERE site_id = ? AND state IN ('open','working','waiting_approval') AND expires_at IS NOT NULL AND expires_at < ?", [$this->site, $now]);
        foreach ($ids as $id) {
            $this->move($id, self::OPEN_STATES, 'expired', 'expired', 'system', 'past its time limit');
        }
        return count($ids);
    }

    /** A task stuck 'working' longer than STUCK_MINUTES is treated as a failed attempt. @return int how many */
    public function reapStuck(): int
    {
        $cut = date('Y-m-d H:i:s', strtotime($this->now()) - self::STUCK_MINUTES * 60);
        $ids = $this->ids("SELECT id FROM agent_tasks WHERE site_id = ? AND state = 'working' AND claimed_at IS NOT NULL AND claimed_at < ?", [$this->site, $cut]);
        foreach ($ids as $id) {
            $this->fail($id, 'system', 'stuck working for over ' . self::STUCK_MINUTES . ' minutes');
        }
        return count($ids);
    }

    /**
     * Close open tasks of $kind whose source no longer lists them (the invoice got paid, the customer replied).
     * Only 'open' tasks are closed; one a head is working on or waiting for approval is left alone.
     *
     * Safety valve: if one pass would close more than MAX_CLOSE_SHARE of the open tasks of this kind (and more than
     * MIN_CLOSE_FLOOR of them), a desk that silently returned a short or empty list is far likelier than that many
     * real resolutions at once — close nothing and log it.
     * @param string[] $liveDedupeKeys keys still present at the source
     */
    public function closeResolved(string $kind, array $liveDedupeKeys): int
    {
        $live = array_flip($liveDedupeKeys);
        $ids = $this->ids("SELECT id FROM agent_tasks WHERE site_id = ? AND kind = ? AND state = 'open'", [$this->site, $kind]);
        $gone = [];
        foreach ($ids as $id) {
            $t = $this->get($id);
            if ($t && !isset($live[$t['dedupe_key']])) {
                $gone[] = $id;
            }
        }
        if (count($gone) > self::MIN_CLOSE_FLOOR && count($gone) > self::MAX_CLOSE_SHARE * count($ids)) {
            error_log("TaskService: refusing to close " . count($gone) . " of " . count($ids) . " open '{$kind}' tasks in one pass — the source list looks short");
            return 0;
        }
        $n = 0;
        foreach ($gone as $id) {
            if ($this->move($id, ['open'], 'cancelled', 'resolved', 'system', 'no longer listed at its source')) {
                $n++;
            }
        }
        return $n;
    }

    /** A cancelled or expired task whose source item has come back is reopened, with a fresh time limit and attempts. */
    private function reopenIfResolved(array $t): array
    {
        if (!in_array($t['state'], ['cancelled', 'expired'], true)) {
            return $t;
        }
        $now = $this->now();
        $s = $this->db->prepare("UPDATE agent_tasks SET state = 'open', attempts = 0, escalated = 0, error = NULL, completed_at = NULL,
                                        expires_at = ?, updated_at = ? WHERE id = ? AND site_id = ? AND state IN ('cancelled','expired')");
        $s->execute([date('Y-m-d H:i:s', strtotime($now) + self::DEFAULT_TTL_DAYS * 86400), $now, (int)$t['id'], $this->site]);
        if ($s->rowCount() === 1) {
            $this->event((int)$t['id'], 'reopened', $t['state'], 'open', 'system', 'listed at its source again');
        }
        return $this->get((int)$t['id']) ?? $t;
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    public function get(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM agent_tasks WHERE id = ? AND site_id = ?");
        $s->execute([$id, $this->site]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function byDedupe(string $key): ?array
    {
        $s = $this->db->prepare("SELECT * FROM agent_tasks WHERE dedupe_key = ? AND site_id = ?");
        $s->execute([$key, $this->site]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array[] a head's open work, most urgent first */
    public function openFor(string $head, int $limit = 50): array
    {
        $s = $this->db->prepare("SELECT * FROM agent_tasks WHERE site_id = ? AND owner_head = ? AND state IN ('open','working','waiting_approval')
                                 ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END, COALESCE(due_at, created_at), id LIMIT " . max(1, min(200, $limit)));
        $s->execute([$this->site, strtolower($head)]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array[] what the owner should hear about: failed, escalated, or open past its due time */
    public function escalations(int $limit = 20): array
    {
        $s = $this->db->prepare("SELECT * FROM agent_tasks
                                 WHERE site_id = ? AND (state = 'failed' OR (escalated = 1 AND state IN ('open','working','waiting_approval'))
                                    OR (state IN ('open','working','waiting_approval') AND due_at IS NOT NULL AND due_at < ?))
                                 ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
        $s->execute([$this->site, $this->now()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function events(int $taskId): array
    {
        if (!$this->get($taskId)) {
            return [];
        }
        $s = $this->db->prepare("SELECT * FROM agent_task_events WHERE task_id = ? ORDER BY id");
        $s->execute([$taskId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Count by owner and state, for the Studio. @return array<string, array<string,int>> */
    public function summary(): array
    {
        $out = [];
        $q = $this->db->prepare("SELECT owner_head, state, COUNT(*) n FROM agent_tasks WHERE site_id = ? GROUP BY owner_head, state");
        $q->execute([$this->site]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['owner_head']][$r['state']] = (int)$r['n'];
        }
        return $out;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return array[] the task and its ancestors, nearest first (so [0] is the task itself) */
    private function chain(int $id): array
    {
        $out = [];
        $seen = [];
        while ($id > 0 && !isset($seen[$id]) && count($out) < 10) {
            $seen[$id] = true;
            $t = $this->get($id);
            if (!$t) {
                break;
            }
            $out[] = $t;
            $id = (int)($t['parent_task_id'] ?? 0);
        }
        return $out;
    }

    private function move(int $id, array $from, string $to, string $event, string $head, ?string $note): bool
    {
        $in = implode(',', array_fill(0, count($from), '?'));
        $now = $this->now();
        $s = $this->db->prepare("UPDATE agent_tasks SET state = ?, updated_at = ?" . ($to === 'cancelled' || $to === 'expired' ? ", completed_at = ?" : "") . " WHERE id = ? AND site_id = ? AND state IN ($in)");
        $args = [$to, $now];
        if ($to === 'cancelled' || $to === 'expired') {
            $args[] = $now;
        }
        $args[] = $id;
        $args[] = $this->site;
        $s->execute(array_merge($args, $from));
        if ($s->rowCount() !== 1) {
            return false;
        }
        $this->event($id, $event, null, $to, $head, $note);
        return true;
    }

    private function event(int $id, string $event, ?string $from, ?string $to, string $head, ?string $note): void
    {
        try {
            $this->db->prepare("INSERT INTO agent_task_events (task_id, event, from_state, to_state, head, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                     ->execute([$id, $event, $from, $to, mb_substr($head, 0, 24), $note !== null ? mb_substr($note, 0, 255) : null, $this->now()]);
        } catch (Throwable $e) {
            error_log('TaskService event: ' . $e->getMessage());   // history is a bonus; the task change stands
        }
    }

    /** @return int[] */
    private function ids(string $sql, array $args): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
}
