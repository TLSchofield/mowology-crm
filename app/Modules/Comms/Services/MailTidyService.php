<?php
/**
 * MailTidyService — "Tidy iCloud": files Tim's mowology@icloud.com into a clean folder plan
 * and checks Junk for real work mail. Yui's area (comms).
 *
 * Safety (hard rules, enforced here AND in ImapWriter):
 *   - PREVIEW FIRST. preview steps only read (read-only IMAP, envelopes in bulk, headers for
 *     unknown senders, bodies only for Junk enquiries — peeked). Nothing is written to the
 *     mailbox by a preview.
 *   - APPLY only on Tim's click, one batch at a time (batch_max messages), in chunks of
 *     chunk_size UIDs with a pause between chunks, inside a time budget — resumable.
 *   - MOVE only. Never delete, never flag, never mark read. Folders are created only if
 *     missing. Protected folders (Sent, Drafts, Notes, Deleted) are never touched.
 *   - Before each chunk: the folder's UIDVALIDITY must match the preview and each UID must
 *     still carry the Message-ID the preview saw — otherwise that message is skipped.
 *   - Every move is logged (mail_tidy_moves); undo moves a batch back, found by Message-ID.
 *   - Mail with no Message-ID is never moved (it could not be found again to undo).
 *   - INBOX keeps the last inbox_keep_days (30) whatever they are; unsure mail stays put.
 *   - Keep it tidy (cron) is OFF by default and only runs after a first applied tidy.
 *
 * Portable SQL only (MySQL 5.7 + SQLite in tests): no INSERT IGNORE / ON DUPLICATE / NOW().
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MailTidyClassifier.php';
require_once __DIR__ . '/MailTidyPort.php';
require_once __DIR__ . '/../../../Services/Mail/ImapWriter.php';

class MailTidyService
{
    public const SAMPLES_PER_PAIR = 5;
    public const PREVIEW_MAX_AGE_DAYS = 7;
    public const SCAN_CHUNK = 200;

    private PDO $db;
    private ?MailTidyPort $port;
    /** @var callable(): float */
    private $clock;
    /** @var callable(int): void */
    private $sleep;
    /** @var callable(string): void */
    private $log;
    private ?array $plan = null;
    private ?array $ctx = null;

    public function __construct(PDO $db, ?MailTidyPort $port = null, array $opts = [])
    {
        $this->db = $db;
        $this->port = $port;
        $this->clock = $opts['clock'] ?? static fn(): float => microtime(true);
        $this->sleep = $opts['sleep'] ?? static function (int $ms): void { if ($ms > 0) usleep($ms * 1000); };
        $this->log = $opts['log'] ?? static function (string $m): void {};
        if (isset($opts['context'])) $this->ctx = $opts['context'];
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM mail_tidy_rules LIMIT 1');
            $this->db->query('SELECT 1 FROM mail_tidy_moves LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function port(): MailTidyPort
    {
        if ($this->port === null) throw new RuntimeException('mailbox not connected');
        return $this->port;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', (int)($this->clock)());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plan + settings
    // ─────────────────────────────────────────────────────────────────────────

    public function plan(): array
    {
        if ($this->plan !== null) return $this->plan;
        try {
            $rows = $this->db->query('SELECT rule_type, folder_key, match_value, label, note, is_existing AS existing, sort_order, is_active FROM mail_tidy_rules')
                             ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
        }
        if (!in_array('folder', array_column($rows, 'rule_type'), true)) {
            // No folder rows (migration seed missing): the default plan, with any saved settings on top.
            $plan = MailTidyClassifier::defaultPlan();
            foreach ($rows as $r) if ($r['rule_type'] === 'setting') $plan['settings'][(string)$r['folder_key']] = (string)$r['match_value'];
            return $this->plan = $plan;
        }
        return $this->plan = MailTidyClassifier::fromRows($rows);
    }

    public function setting(string $key): string
    {
        return (string)($this->plan()['settings'][$key] ?? MailTidyClassifier::defaultSettings()[$key] ?? '');
    }

    /** Change one setting (admin only — the API checks). */
    public function setSetting(string $key, string $value): void
    {
        if (!array_key_exists($key, MailTidyClassifier::defaultSettings())) throw new InvalidArgumentException('unknown setting');
        $u = $this->db->prepare("UPDATE mail_tidy_rules SET match_value = ? WHERE rule_uid = ?");
        $u->execute([$value, 'setting:' . $key]);
        if ($u->rowCount() === 0) {
            $this->db->prepare("INSERT INTO mail_tidy_rules (rule_uid, rule_type, folder_key, match_value, sort_order) VALUES (?, 'setting', ?, ?, 3000)")
                     ->execute(['setting:' . $key, $key, $value]);
        }
        $this->plan = null;
    }

    private function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int)$this->setting($key)));
    }

    private function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->setting($key))), 'strlen'));
    }

    /** contacts / vendors / our addresses for the classifier. */
    public function context(): array
    {
        if ($this->ctx !== null) return $this->ctx;
        require_once __DIR__ . '/../../Sales/Services/SalesInboxService.php';
        require_once __DIR__ . '/../../Expenses/Services/VendorMessageService.php';
        return $this->ctx = [
            'contacts' => (new SalesInboxService($this->db))->contactMap(),
            'vendors'  => (new VendorMessageService($this->db))->vendors(),
            'ours'     => ['mowology@icloud.com'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Preview (read-only)
    // ─────────────────────────────────────────────────────────────────────────

    /** Start a new preview: older ones go stale. Returns the preview id. */
    public function startPreview(?int $userId): int
    {
        $now = $this->now();
        $this->db->prepare("UPDATE mail_tidy_previews SET status = 'stale', updated_at = ? WHERE status IN ('scanning', 'ready')")->execute([$now]);
        $this->db->prepare("INSERT INTO mail_tidy_previews (status, created_by, created_at, updated_at) VALUES ('scanning', ?, ?, ?)")
                 ->execute([$userId, $now, $now]);
        $id = (int)$this->db->lastInsertId();
        $have = $this->port()->folders();
        $ins = $this->db->prepare("INSERT INTO mail_tidy_cursors (preview_id, folder, role, uid_validity, uid_next) VALUES (?, ?, ?, ?, ?)");
        foreach (['tidy' => $this->list('sources'), 'junk' => $this->list('junk_folders')] as $role => $folders) {
            foreach ($folders as $f) {
                if (!in_array($f, $have, true) || ImapWriter::isProtected($f)) continue;
                $info = $this->port()->folderInfo($f);
                if ($info === null) continue;
                $ins->execute([$id, $f, $role, $info['uidvalidity'], $info['uidnext']]);
            }
        }
        return $id;
    }

    /**
     * Scan until the budget runs out. Returns progress; status 'ready' when every folder is done.
     * @return array{preview_id: int, status: string, scanned: int, total: int}
     */
    public function scanStep(int $previewId, float $budgetSec = 20.0): array
    {
        $t0 = ($this->clock)();
        $plan = $this->plan();
        $ctx = $this->context();
        $keepDays = $this->int('inbox_keep_days', 0, 3650);
        $recentFloor = (int)$t0 - $keepDays * 86400;
        $pairs = $this->samplePairs($previewId);
        $sampled = [];          // pair => senders already sampled (examples show different senders)
        $senderHeaders = [];

        $cursors = $this->db->prepare("SELECT * FROM mail_tidy_cursors WHERE preview_id = ? AND done = 0 ORDER BY role, folder");
        $cursors->execute([$previewId]);
        foreach ($cursors->fetchAll(PDO::FETCH_ASSOC) as $cur) {
            $folder = (string)$cur['folder'];
            $junk = $cur['role'] === 'junk';
            // Anything stored past the saved position belongs to a step that didn't finish.
            $this->db->prepare("DELETE FROM mail_tidy_items WHERE preview_id = ? AND folder = ? AND uid > ?")
                     ->execute([$previewId, $folder, (int)$cur['last_uid']]);
            $last = (int)$cur['last_uid'];
            $scanned = (int)$cur['scanned']; $stayed = (int)$cur['stayed']; $recent = (int)$cur['recent'];
            $done = false;
            while (($this->clock)() - $t0 < $budgetSec) {
                $rows = $this->port()->scan($folder, $last, self::SCAN_CHUNK);
                if (!$rows) { $done = true; break; }
                foreach ($rows as $row) {
                    $uid = (int)$row['uid'];
                    $scanned++;
                    $mid = trim((string)($row['message_id'] ?? ''));
                    $verdict = null;
                    if ($mid === '') {
                        $stayed++;
                    } elseif ($junk) {
                        $verdict = $this->junkRow($folder, $row, $plan, $ctx);
                    } elseif ($folder === 'INBOX' && (int)$row['udate'] >= $recentFloor) {
                        $recent++;
                    } else {
                        $verdict = $this->tidyRow($folder, $row, $plan, $ctx, $senderHeaders);
                        if ($verdict === null) $stayed++;
                    }
                    if ($verdict !== null) {
                        $pair = $folder . '→' . $verdict['target'];
                        $sender = strtolower((string)(SalesInboxService::addresses((string)$row['from'])[0] ?? ''));
                        $keepSubject = $junk || ((($pairs[$pair] ?? 0) < self::SAMPLES_PER_PAIR) && !isset($sampled[$pair][$sender]));
                        if (!$junk && $keepSubject) { $pairs[$pair] = ($pairs[$pair] ?? 0) + 1; $sampled[$pair][$sender] = true; }
                        $this->insertItem($previewId, $folder, (int)$cur['uid_validity'], $row, $verdict, $keepSubject, $junk);
                    } elseif ($junk) {
                        $stayed++;
                    }
                    $last = $uid;
                }
                if (count($rows) < self::SCAN_CHUNK) { $done = true; break; }
                $this->saveCursor($previewId, $folder, $last, $scanned, $stayed, $recent, false);
            }
            $this->saveCursor($previewId, $folder, $last, $scanned, $stayed, $recent, $done);
            if (!$done) break;
        }
        return $this->refreshPreview($previewId);
    }

    /** A tidy-folder message: target or null (stays). Headers fetched once per unknown sender. */
    private function tidyRow(string $folder, array $row, array $plan, array $ctx, array &$senderHeaders): ?array
    {
        $m = ['from' => $row['from'], 'to' => $row['to'], 'subject' => $row['subject']];
        $r = MailTidyClassifier::classify($m, $ctx, $plan);
        if ($r['key'] === null && in_array($r['basis'], ['none', 'enquiry', 'pitch'], true)) {
            // Mailing-list cues live in the headers: one fetch per sender, reused for the rest.
            $from = SalesInboxService::addresses((string)$row['from'])[0] ?? '';
            if ($from !== '') {
                if (!array_key_exists($from, $senderHeaders)) {
                    $senderHeaders[$from] = self::listHeaders($this->port()->headers($folder, (int)$row['uid']));
                }
                if ($senderHeaders[$from] !== '') $r = MailTidyClassifier::classify($m + ['headers' => $senderHeaders[$from]], $ctx, $plan);
            }
        }
        if (!$r['move'] || $r['folder'] === $folder || ImapWriter::isProtected((string)$r['folder'])) return null;
        return ['target' => $r['folder'], 'key' => $r['key'], 'confidence' => $r['confidence'], 'reason' => $r['reason'], 'selected' => 1];
    }

    /** A Junk message: rescue to INBOX, or null (spam stays). */
    private function junkRow(string $folder, array $row, array $plan, array $ctx): ?array
    {
        $uid = (int)$row['uid'];
        $port = $this->port();
        $m = ['from' => $row['from'], 'to' => $row['to'], 'subject' => $row['subject'],
              'headers' => $port->headers($folder, $uid),
              'body' => static function () use ($port, $folder, $uid): string { return $port->body($folder, $uid); }];
        $v = MailTidyClassifier::junkVerdict($m, $ctx, $plan);
        if (!$v['rescue']) return null;
        return ['target' => 'INBOX', 'key' => $v['key'], 'confidence' => $v['confidence'], 'reason' => $v['reason'], 'selected' => $v['selected'] ? 1 : 0];
    }

    /** Only the mailing-list headers of a block (nothing personal is kept in memory). */
    public static function listHeaders(string $raw): string
    {
        $raw = (string)preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        preg_match_all('/^(List-Unsubscribe|List-Id|Precedence|X-Campaign(?:-?Id)?|X-Mailchimp-[A-Za-z-]+|X-SG-EID|Auto-Submitted|Feedback-ID):.*$/mi', $raw, $m);
        return implode("\r\n", array_map('rtrim', $m[0]));
    }

    private function insertItem(int $previewId, string $folder, int $uidValidity, array $row, array $v, bool $keepSubject, bool $junk): void
    {
        $from = SalesInboxService::addresses((string)$row['from'])[0] ?? '';
        $this->db->prepare("
            INSERT INTO mail_tidy_items (preview_id, kind, folder, uid, uid_validity, message_id, from_addr, subject, msg_date,
                                         target_key, target_folder, confidence, reason, selected, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ")->execute([
            $previewId, $junk ? 'rescue' : 'move', $folder, (int)$row['uid'], $uidValidity,
            mb_substr(trim((string)$row['message_id']), 0, 191), mb_substr($from, 0, 255),
            $keepSubject ? mb_substr((string)$row['subject'], 0, 255) : null,
            (int)$row['udate'] > 0 ? date('Y-m-d H:i:s', (int)$row['udate']) : null,
            $v['key'], $v['target'], (int)$v['confidence'], mb_substr((string)$v['reason'], 0, 160), (int)$v['selected'],
        ]);
    }

    private function samplePairs(int $previewId): array
    {
        $s = $this->db->prepare("SELECT folder, target_folder, COUNT(*) AS n FROM mail_tidy_items WHERE preview_id = ? AND kind = 'move' AND subject IS NOT NULL GROUP BY folder, target_folder");
        $s->execute([$previewId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['folder'] . '→' . $r['target_folder']] = (int)$r['n'];
        return $out;
    }

    private function saveCursor(int $previewId, string $folder, int $last, int $scanned, int $stayed, int $recent, bool $done): void
    {
        $this->db->prepare("UPDATE mail_tidy_cursors SET last_uid = ?, scanned = ?, stayed = ?, recent = ?, done = ? WHERE preview_id = ? AND folder = ?")
                 ->execute([$last, $scanned, $stayed, $recent, $done ? 1 : 0, $previewId, $folder]);
    }

    private function refreshPreview(int $previewId): array
    {
        $c = $this->db->prepare("SELECT COUNT(*) AS n, SUM(done) AS d, SUM(scanned) AS s, SUM(stayed + recent) AS st FROM mail_tidy_cursors WHERE preview_id = ?");
        $c->execute([$previewId]);
        $agg = $c->fetch(PDO::FETCH_ASSOC) ?: [];
        $i = $this->db->prepare("SELECT kind, COUNT(*) AS n FROM mail_tidy_items WHERE preview_id = ? GROUP BY kind");
        $i->execute([$previewId]);
        $kinds = ['move' => 0, 'rescue' => 0];
        foreach ($i->fetchAll(PDO::FETCH_ASSOC) as $r) $kinds[$r['kind']] = (int)$r['n'];
        $ready = (int)($agg['n'] ?? 0) === (int)($agg['d'] ?? 0);
        $p = $this->db->prepare("SELECT status FROM mail_tidy_previews WHERE id = ?");
        $p->execute([$previewId]);
        $status = (string)$p->fetchColumn();
        if ($status === 'scanning' && $ready) $status = 'ready';
        $this->db->prepare("UPDATE mail_tidy_previews SET status = ?, scanned = ?, to_move = ?, rescue = ?, stayed = ?, updated_at = ? WHERE id = ?")
                 ->execute([$status, (int)($agg['s'] ?? 0), $kinds['move'], $kinds['rescue'], (int)($agg['st'] ?? 0), $this->now(), $previewId]);
        return ['preview_id' => $previewId, 'status' => $status, 'scanned' => (int)($agg['s'] ?? 0), 'to_move' => $kinds['move'], 'rescue' => $kinds['rescue']];
    }

    /**
     * Everything the page shows for a preview. Subjects only as samples / the rescue list.
     * @param string[]|null $existing folder names on the server (for "folders to create")
     */
    public function summary(int $previewId, ?array $existing = null): array
    {
        $p = $this->db->prepare("SELECT * FROM mail_tidy_previews WHERE id = ?");
        $p->execute([$previewId]);
        $prev = $p->fetch(PDO::FETCH_ASSOC);
        if (!$prev) return ['ok' => false, 'error' => 'No such preview'];

        $c = $this->db->prepare("SELECT folder, role, last_uid, uid_next, scanned, stayed, recent, done FROM mail_tidy_cursors WHERE preview_id = ? ORDER BY role DESC, folder");
        $c->execute([$previewId]);
        $cursors = $c->fetchAll(PDO::FETCH_ASSOC);

        $g = $this->db->prepare("
            SELECT folder, target_folder, target_key, status, COUNT(*) AS n FROM mail_tidy_items
            WHERE preview_id = ? AND kind = 'move' GROUP BY folder, target_folder, target_key, status
        ");
        $g->execute([$previewId]);
        $pairs = [];
        foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['folder'] . '→' . $r['target_folder'];
            $pairs[$k] = $pairs[$k] ?? ['from' => $r['folder'], 'to' => $r['target_folder'], 'to_label' => $this->label($r['target_key'], $r['target_folder']),
                                        'count' => 0, 'pending' => 0, 'moved' => 0, 'samples' => []];
            $pairs[$k]['count'] += (int)$r['n'];
            if ($r['status'] === 'pending') $pairs[$k]['pending'] += (int)$r['n'];
            if ($r['status'] === 'moved') $pairs[$k]['moved'] += (int)$r['n'];
        }
        $s = $this->db->prepare("SELECT folder, target_folder, from_addr, subject, reason FROM mail_tidy_items WHERE preview_id = ? AND kind = 'move' AND subject IS NOT NULL ORDER BY id");
        $s->execute([$previewId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['folder'] . '→' . $r['target_folder'];
            if (isset($pairs[$k])) $pairs[$k]['samples'][] = ['from' => $r['from_addr'], 'subject' => $r['subject'], 'why' => $r['reason']];
        }
        uasort($pairs, fn($a, $b) => $b['count'] <=> $a['count']);

        $rq = $this->db->prepare("SELECT id, folder, from_addr, subject, msg_date, reason, confidence, selected, status FROM mail_tidy_items WHERE preview_id = ? AND kind = 'rescue' ORDER BY confidence DESC, msg_date DESC");
        $rq->execute([$previewId]);
        $rescue = $rq->fetchAll(PDO::FETCH_ASSOC);

        $t = $this->db->prepare("SELECT DISTINCT target_folder FROM mail_tidy_items WHERE preview_id = ? AND kind = 'move' AND status = 'pending'");
        $t->execute([$previewId]);
        $targets = $t->fetchAll(PDO::FETCH_COLUMN);
        if ($existing === null) {
            $existing = [];
            foreach ($this->plan()['folders'] as $f) if ($f['existing']) $existing[] = $f['imap'];
        }
        $create = array_values(array_diff($targets, $existing, ['INBOX']));

        $pending = $this->db->prepare("SELECT kind, COUNT(*) FROM mail_tidy_items WHERE preview_id = ? AND status = 'pending' AND selected = 1 GROUP BY kind");
        $pending->execute([$previewId]);
        $pend = ['move' => 0, 'rescue' => 0];
        foreach ($pending->fetchAll(PDO::FETCH_NUM) as [$k, $n]) $pend[$k] = (int)$n;

        return [
            'ok' => true,
            'preview' => $prev,
            'cursors' => $cursors,
            'pairs' => array_values($pairs),
            'rescue' => $rescue,
            'create' => array_map(fn($f) => ['imap' => $f, 'label' => MailTidyClassifier::displayFolder($f)], $create),
            'pending' => $pend,
            'batches' => $this->batches($previewId),
        ];
    }

    private function label(?string $key, string $imap): string
    {
        $f = $key !== null ? ($this->plan()['folders'][$key] ?? null) : null;
        return $f ? $f['label'] : MailTidyClassifier::displayFolder($imap);
    }

    public function latestPreviewId(): ?int
    {
        $id = $this->db->query("SELECT id FROM mail_tidy_previews WHERE status IN ('scanning', 'ready') ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** Tick / untick items (the Junk rescue list mainly). */
    public function setSelected(int $previewId, array $ids, bool $selected): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $u = $this->db->prepare("UPDATE mail_tidy_items SET selected = ? WHERE preview_id = ? AND status = 'pending' AND id IN ($in)");
        $u->execute(array_merge([$selected ? 1 : 0, $previewId], $ids));
        return $u->rowCount();
    }

    public function batches(?int $previewId = null, int $limit = 20): array
    {
        $sql = "SELECT id, preview_id, kind, undo_of, status, started_at, finished_at, target, moved, failed, note FROM mail_tidy_batches";
        $args = [];
        if ($previewId !== null) { $sql .= " WHERE preview_id = ? OR kind IN ('keep_tidy', 'undo')"; $args[] = $previewId; }
        $s = $this->db->prepare($sql . " ORDER BY id DESC LIMIT " . max(1, min(100, $limit)));
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $u = $this->db->prepare("SELECT COUNT(*) FROM mail_tidy_moves WHERE batch_id = ? AND undone_at IS NULL");
        foreach ($rows as &$r) {
            $u->execute([(int)$r['id']]);
            $r['undoable'] = $r['kind'] !== 'undo' ? (int)$u->fetchColumn() : 0;
        }
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apply (moves — Tim's click only)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One step of an Apply batch. Call with $batchId = null to start a batch (one click),
     * then with the returned batch_id until done.
     * @param string $kind 'apply' (folder moves) | 'rescue' (Junk → INBOX, ticked items only)
     */
    public function applyStep(int $previewId, string $kind, ?int $batchId, ?int $userId, float $budgetSec = 20.0): array
    {
        if (!in_array($kind, ['apply', 'rescue'], true)) throw new InvalidArgumentException('kind');
        $itemKind = $kind === 'apply' ? 'move' : 'rescue';
        $p = $this->db->prepare("SELECT status, created_at FROM mail_tidy_previews WHERE id = ?");
        $p->execute([$previewId]);
        $prev = $p->fetch(PDO::FETCH_ASSOC);
        if (!$prev || $prev['status'] !== 'ready') return ['ok' => false, 'error' => 'Run a fresh preview first — this one is ' . ($prev['status'] ?? 'missing') . '.'];
        if (strtotime((string)$prev['created_at']) < (int)($this->clock)() - self::PREVIEW_MAX_AGE_DAYS * 86400) {
            return ['ok' => false, 'error' => 'This preview is over ' . self::PREVIEW_MAX_AGE_DAYS . ' days old — run a fresh one.'];
        }

        if ($batchId === null) {
            $n = $this->db->prepare("SELECT COUNT(*) FROM mail_tidy_items WHERE preview_id = ? AND kind = ? AND status = 'pending' AND selected = 1");
            $n->execute([$previewId, $itemKind]);
            $target = min((int)$n->fetchColumn(), $this->int('batch_max', 1, 20000));
            if ($target === 0) return ['ok' => true, 'done' => true, 'batch_id' => null, 'moved' => 0, 'failed' => 0, 'target' => 0, 'message' => 'Nothing left to move.'];
            $batchId = $this->newBatch($previewId, $kind, $userId, $target);
        }
        $batch = $this->batch($batchId);
        if (!$batch || (int)$batch['preview_id'] !== $previewId || $batch['kind'] !== $kind) return ['ok' => false, 'error' => 'No such batch'];
        if ($batch['status'] !== 'running') return $this->batchOut($batchId, true);

        $t0 = ($this->clock)();
        $chunk = $this->int('chunk_size', 1, ImapWriter::MAX_UIDS);
        $pause = $this->int('pause_ms', 0, 10000);
        $first = true;
        while (($this->clock)() - $t0 < $budgetSec) {
            $b = $this->batch($batchId);
            $left = (int)$b['target'] - (int)$b['moved'] - (int)$b['failed'];
            if ($left <= 0) break;
            $q = $this->db->prepare("SELECT id, folder, uid, uid_validity, message_id, target_folder FROM mail_tidy_items
                                     WHERE preview_id = ? AND kind = ? AND status = 'pending' AND selected = 1
                                     ORDER BY folder, target_folder, uid LIMIT " . (int)min($chunk, $left));
            $q->execute([$previewId, $itemKind]);
            $items = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) break;
            // One (folder → target) pair per chunk.
            $folder = $items[0]['folder']; $to = $items[0]['target_folder'];
            $items = array_values(array_filter($items, fn($i) => $i['folder'] === $folder && $i['target_folder'] === $to));
            if (!$first) ($this->sleep)($pause);
            $first = false;
            $res = $this->moveChunk($batchId, $folder, $to, $items);
            if ($res['error'] !== null && !$res['moved']) {
                $this->finishBatch($batchId, 'stopped', $res['error']);
                return $this->batchOut($batchId, true) + ['error' => $res['error']];
            }
        }
        $b = $this->batch($batchId);
        $done = (int)$b['moved'] + (int)$b['failed'] >= (int)$b['target'] || !$this->hasPending($previewId, $itemKind);
        if ($done) $this->finishBatch($batchId, 'done', null);
        return $this->batchOut($batchId, $done);
    }

    /**
     * Verify, create the target if missing, move, log.
     * @param array<int, array{id: int, uid: int, uid_validity: int, message_id: string}> $items
     * @return array{moved: int[], error: ?string}
     */
    private function moveChunk(int $batchId, string $folder, string $to, array $items): array
    {
        $port = $this->port();
        $mark = function (array $ids, string $status) use ($batchId): void {
            if (!$ids) return;
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare("UPDATE mail_tidy_items SET status = ?, batch_id = ? WHERE id IN ($in)")->execute(array_merge([$status, $batchId], $ids));
        };
        if (ImapWriter::isProtected($folder) || ImapWriter::isProtected($to)) {
            $mark(array_column($items, 'id'), 'skipped');
            $this->bump($batchId, 0, count($items));
            return ['moved' => [], 'error' => null];
        }
        $info = $port->folderInfo($folder);
        $fresh = [];
        $stale = [];
        foreach ($items as $i) {
            ($info !== null && (int)$info['uidvalidity'] === (int)$i['uid_validity']) ? $fresh[] = $i : $stale[] = (int)$i['id'];
        }
        if ($fresh) {
            $ids = $port->messageIds($folder, array_column($fresh, 'uid'));
            $ok = [];
            foreach ($fresh as $i) {
                (isset($ids[(int)$i['uid']]) && $ids[(int)$i['uid']] === $i['message_id']) ? $ok[] = $i : $stale[] = (int)$i['id'];
            }
            $fresh = $ok;
        }
        $mark($stale, 'skipped');
        $this->bump($batchId, 0, count($stale));
        if (!$fresh) return ['moved' => [], 'error' => null];

        if ($to !== 'INBOX' && !in_array($to, $port->folders(), true) && !$port->createFolder($to)) {
            $mark(array_column($fresh, 'id'), 'failed');
            $this->bump($batchId, 0, count($fresh));
            return ['moved' => [], 'error' => 'Could not create folder ' . MailTidyClassifier::displayFolder($to)];
        }
        $res = $port->move($folder, array_map('intval', array_column($fresh, 'uid')), $to);
        $moved = array_map('intval', $res['moved']);
        $now = $this->now();
        $log = $this->db->prepare("INSERT INTO mail_tidy_moves (batch_id, message_id, uid, uid_validity, from_folder, to_folder, moved_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $movedIds = []; $failedIds = [];
        foreach ($fresh as $i) {
            if (in_array((int)$i['uid'], $moved, true)) {
                $log->execute([$batchId, $i['message_id'], (int)$i['uid'], (int)$i['uid_validity'], $folder, $to, $now]);
                $movedIds[] = (int)$i['id'];
            } else {
                $failedIds[] = (int)$i['id'];
            }
        }
        $mark($movedIds, 'moved');
        // A refused chunk stays pending (nothing happened); a partial one marks the rest failed.
        if ($movedIds) $mark($failedIds, 'failed');
        $this->bump($batchId, count($movedIds), $movedIds ? count($failedIds) : 0);
        if ($res['error'] !== null) ($this->log)('tidy: ' . $res['error']);
        return ['moved' => $moved, 'error' => $res['error']];
    }

    private function hasPending(int $previewId, string $itemKind): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM mail_tidy_items WHERE preview_id = ? AND kind = ? AND status = 'pending' AND selected = 1 LIMIT 1");
        $s->execute([$previewId, $itemKind]);
        return (bool)$s->fetchColumn();
    }

    private function newBatch(?int $previewId, string $kind, ?int $userId, int $target, ?int $undoOf = null): int
    {
        $this->db->prepare("INSERT INTO mail_tidy_batches (preview_id, kind, undo_of, status, started_by, started_at, target) VALUES (?, ?, ?, 'running', ?, ?, ?)")
                 ->execute([$previewId, $kind, $undoOf, $userId, $this->now(), $target]);
        return (int)$this->db->lastInsertId();
    }

    private function batch(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM mail_tidy_batches WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function bump(int $batchId, int $moved, int $failed): void
    {
        if ($moved === 0 && $failed === 0) return;
        $this->db->prepare("UPDATE mail_tidy_batches SET moved = moved + ?, failed = failed + ? WHERE id = ?")->execute([$moved, $failed, $batchId]);
    }

    private function finishBatch(int $batchId, string $status, ?string $note): void
    {
        $this->db->prepare("UPDATE mail_tidy_batches SET status = ?, finished_at = ?, note = ? WHERE id = ?")
                 ->execute([$status, $this->now(), $note !== null ? mb_substr($note, 0, 255) : null, $batchId]);
    }

    private function batchOut(int $batchId, bool $done): array
    {
        $b = $this->batch($batchId) ?? [];
        return ['ok' => true, 'done' => $done, 'batch_id' => $batchId, 'status' => $b['status'] ?? '',
                'moved' => (int)($b['moved'] ?? 0), 'failed' => (int)($b['failed'] ?? 0), 'target' => (int)($b['target'] ?? 0),
                'note' => $b['note'] ?? null];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Undo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Move a batch back, step by step. Each message is found in the folder it was moved to by
     * its Message-ID (UIDs change on a move). Messages Tim has since moved elsewhere are left.
     */
    public function undoStep(int $batchId, ?int $undoBatchId, ?int $userId, float $budgetSec = 20.0): array
    {
        $orig = $this->batch($batchId);
        if (!$orig) return ['ok' => false, 'error' => 'No such batch'];
        if ($orig['kind'] === 'undo') return ['ok' => false, 'error' => 'An undo cannot be undone — run a preview instead.'];
        if ($undoBatchId === null) {
            $n = $this->db->prepare("SELECT COUNT(*) FROM mail_tidy_moves WHERE batch_id = ? AND undone_at IS NULL");
            $n->execute([$batchId]);
            $target = (int)$n->fetchColumn();
            if ($target === 0) return ['ok' => true, 'done' => true, 'batch_id' => null, 'moved' => 0, 'failed' => 0, 'target' => 0, 'message' => 'Nothing to undo.'];
            $undoBatchId = $this->newBatch($orig['preview_id'] !== null ? (int)$orig['preview_id'] : null, 'undo', $userId, $target, $batchId);
        }
        $ub = $this->batch($undoBatchId);
        if (!$ub || $ub['kind'] !== 'undo' || (int)$ub['undo_of'] !== $batchId) return ['ok' => false, 'error' => 'No such undo'];
        if ($ub['status'] !== 'running') return $this->batchOut($undoBatchId, true);

        $port = $this->port();
        $t0 = ($this->clock)();
        $chunk = $this->int('chunk_size', 1, ImapWriter::MAX_UIDS);
        $pause = $this->int('pause_ms', 0, 10000);
        $first = true;
        while (($this->clock)() - $t0 < $budgetSec) {
            $q = $this->db->prepare("SELECT id, message_id, from_folder, to_folder FROM mail_tidy_moves
                                     WHERE batch_id = ? AND undone_at IS NULL AND (undo_batch_id IS NULL OR undo_batch_id <> ?)
                                     ORDER BY to_folder, from_folder, id LIMIT " . (int)$chunk);
            $q->execute([$batchId, $undoBatchId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            $now = $rows[0]['to_folder']; $back = $rows[0]['from_folder'];
            $rows = array_values(array_filter($rows, fn($r) => $r['to_folder'] === $now && $r['from_folder'] === $back));
            if (!$first) ($this->sleep)($pause);
            $first = false;

            $found = []; $missing = []; $used = [];
            foreach ($rows as $r) {
                $uids = array_values(array_diff($port->findByMessageId($now, (string)$r['message_id']), $used));
                if (!$uids) { $missing[] = (int)$r['id']; continue; }
                $uid = max($uids);
                $used[] = $uid;
                $found[$uid] = $r;
            }
            $tag = $this->db->prepare("UPDATE mail_tidy_moves SET undo_batch_id = ? WHERE id = ?");
            foreach ($missing as $id) $tag->execute([$undoBatchId, $id]);   // not there any more — leave it
            $this->bump($undoBatchId, 0, count($missing));
            if (!$found) continue;

            $res = ImapWriter::isProtected($back) || ImapWriter::isProtected($now)
                ? ['moved' => [], 'error' => 'protected folder']
                : $port->move($now, array_keys($found), $back);
            $stamp = $this->now();
            $done = $this->db->prepare("UPDATE mail_tidy_moves SET undone_at = ?, undo_batch_id = ? WHERE id = ?");
            $log = $this->db->prepare("INSERT INTO mail_tidy_moves (batch_id, message_id, uid, uid_validity, from_folder, to_folder, moved_at) VALUES (?, ?, ?, NULL, ?, ?, ?)");
            $n = 0;
            foreach (array_map('intval', $res['moved']) as $uid) {
                if (!isset($found[$uid])) continue;
                $done->execute([$stamp, $undoBatchId, (int)$found[$uid]['id']]);
                $log->execute([$undoBatchId, $found[$uid]['message_id'], $uid, $now, $back, $stamp]);
                $n++;
            }
            if ($n === 0 && $res['error'] !== null) {
                $this->finishBatch($undoBatchId, 'stopped', $res['error']);
                return $this->batchOut($undoBatchId, true) + ['error' => $res['error']];
            }
            $notMoved = array_diff(array_keys($found), array_map('intval', $res['moved']));
            foreach ($notMoved as $uid) $tag->execute([$undoBatchId, (int)$found[$uid]['id']]);
            $this->bump($undoBatchId, $n, count($notMoved));
            // The original batch's items go back to "undone" so a later Apply won't redo them.
            $this->db->prepare("UPDATE mail_tidy_items SET status = 'undone' WHERE batch_id = ? AND status = 'moved'")->execute([$batchId]);
        }
        $left = $this->db->prepare("SELECT COUNT(*) FROM mail_tidy_moves WHERE batch_id = ? AND undone_at IS NULL AND (undo_batch_id IS NULL OR undo_batch_id <> ?)");
        $left->execute([$batchId, $undoBatchId]);
        $finished = (int)$left->fetchColumn() === 0;
        if ($finished) $this->finishBatch($undoBatchId, 'done', null);
        return $this->batchOut($undoBatchId, $finished);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Keep it tidy (cron, OFF by default)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * File NEW INBOX mail older than keep_tidy_after_days by the same rules — only when the
     * switch is on and a first tidy has been applied. MOVE only, logged, undoable.
     */
    public function keepTidy(float $budgetSec = 120.0, int $max = 500): array
    {
        if ($this->setting('keep_tidy') !== '1') return ['ok' => true, 'ran' => false, 'message' => 'Keep it tidy is off.'];
        $first = $this->db->query("SELECT id FROM mail_tidy_batches WHERE kind = 'apply' AND moved > 0 ORDER BY id LIMIT 1")->fetchColumn();
        if (!$first) return ['ok' => true, 'ran' => false, 'message' => 'No tidy applied yet — keep it tidy waits for the first one.'];

        $port = $this->port();
        $info = $port->folderInfo('INBOX');
        if ($info === null) return ['ok' => false, 'ran' => false, 'message' => 'Could not read INBOX.'];
        $cur = $this->db->query("SELECT * FROM mail_tidy_cursors WHERE preview_id = 0 AND folder = 'INBOX'")->fetch(PDO::FETCH_ASSOC);
        if (!$cur || (int)$cur['uid_validity'] !== (int)$info['uidvalidity']) {
            // Start where the last preview's INBOX scan stopped (that mail was the tidy's job),
            // or — if UIDs were reset — from now on.
            $from = $this->db->query("SELECT c.last_uid, c.uid_validity FROM mail_tidy_cursors c JOIN mail_tidy_previews p ON p.id = c.preview_id
                                      WHERE c.preview_id > 0 AND c.folder = 'INBOX' ORDER BY c.preview_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $start = ($from && (int)$from['uid_validity'] === (int)$info['uidvalidity']) ? (int)$from['last_uid'] : (int)$info['uidnext'] - 1;
            $this->db->prepare("DELETE FROM mail_tidy_cursors WHERE preview_id = 0 AND folder = 'INBOX'")->execute();
            $this->db->prepare("INSERT INTO mail_tidy_cursors (preview_id, folder, role, uid_validity, uid_next, last_uid) VALUES (0, 'INBOX', 'keep', ?, ?, ?)")
                     ->execute([$info['uidvalidity'], $info['uidnext'], $start]);
            $cur = ['last_uid' => $start, 'scanned' => 0];
        }

        $t0 = ($this->clock)();
        $cutoff = (int)$t0 - $this->int('keep_tidy_after_days', 1, 365) * 86400;
        $plan = $this->plan();
        $ctx = $this->context();
        $last = (int)$cur['last_uid'];
        $byTarget = [];
        $headers = [];
        $seen = 0;
        $young = false;
        while (!$young && $seen < $max && ($this->clock)() - $t0 < $budgetSec) {
            $rows = $port->scan('INBOX', $last, self::SCAN_CHUNK);
            if (!$rows) break;
            foreach ($rows as $row) {
                if ((int)$row['udate'] > $cutoff) { $young = true; break; }   // wait for it to age
                $seen++;
                $last = (int)$row['uid'];
                $mid = trim((string)($row['message_id'] ?? ''));
                if ($mid === '') continue;
                $v = $this->tidyRow('INBOX', $row, $plan, $ctx, $headers);
                if ($v !== null) $byTarget[$v['target']][] = ['id' => 0, 'uid' => (int)$row['uid'], 'uid_validity' => (int)$info['uidvalidity'], 'message_id' => $mid];
                if ($seen >= $max) break;
            }
            if (count($rows) < self::SCAN_CHUNK) break;
        }
        $moved = 0; $failed = 0; $batchId = null;
        if ($byTarget) {
            $total = array_sum(array_map('count', $byTarget));
            $batchId = $this->newBatch(null, 'keep_tidy', null, $total);
            $chunk = $this->int('chunk_size', 1, ImapWriter::MAX_UIDS);
            $firstChunk = true;
            foreach ($byTarget as $to => $items) {
                foreach (array_chunk($items, $chunk) as $part) {
                    if (!$firstChunk) ($this->sleep)($this->int('pause_ms', 0, 10000));
                    $firstChunk = false;
                    $res = $this->moveChunk($batchId, 'INBOX', $to, $part);
                    if ($res['error'] !== null && !$res['moved']) { $failed += count($part); $last = min($last, (int)$part[0]['uid'] - 1); break 2; }
                }
            }
            $b = $this->batch($batchId);
            $moved = (int)$b['moved'];
            $failed += (int)$b['failed'];
            $this->finishBatch($batchId, 'done', null);
        }
        $this->db->prepare("UPDATE mail_tidy_cursors SET last_uid = ?, scanned = scanned + ? WHERE preview_id = 0 AND folder = 'INBOX'")->execute([$last, $seen]);
        return ['ok' => true, 'ran' => true, 'batch_id' => $batchId, 'scanned' => $seen, 'moved' => $moved, 'failed' => $failed,
                'message' => "Keep it tidy: {$seen} checked, {$moved} filed" . ($failed ? ", {$failed} not moved" : '') . '.'];
    }
}
