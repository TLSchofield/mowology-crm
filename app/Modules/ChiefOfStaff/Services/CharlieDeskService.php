<?php
/**
 * CharlieDeskService — Charlie, the Chief of Staff: picks the one thing that needs the
 * owner today, keeps the morning brief, and learns the owner's order.
 *
 * How he learns (charlie_items / charlie_prefs, migration 1170):
 *   - Every item a head shows is remembered by its stable key.
 *   - "Acted": the owner opens it from Charlie's card, or it vanishes from its head's brief
 *     (dealt with). Its kind beats the kind of every other item from this morning's brief
 *     that is still waiting.
 *   - Opened or "Not now" items leave today's list and come back tomorrow if still there.
 *   - "Not now" (dismiss): every waiting kind beats it, a bit harder. Three of one kind in
 *     30 days → Charlie asks whether to leave that kind out.
 *   - "Later" (snooze): hidden until tomorrow; teaches nothing.
 *   - A head that only sent its top few items (count > items) can't tell us an item was
 *     dealt with, so items that drop off such a head are closed without learning.
 *   - A head that failed resolves nothing.
 * The morning brief (charlie_briefs) is frozen once it's emailed or the owner acts on it,
 * so "did Tim go to Charlie's pick first?" is judged against what Charlie actually said.
 *
 * Owner only: ops_settings charlie_owner_user_id (seeded by 1170); unset → any admin.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CharlieRankService.php';
require_once __DIR__ . '/CharlieBriefService.php';
require_once __DIR__ . '/CharlieVoice.php';

class CharlieDeskService
{
    public const SNAPSHOT_ITEMS = 12;
    public const HEAD_ITEMS = 3;
    public const MUTE_AFTER = 3;
    public const MUTE_WINDOW_DAYS = 30;
    public const QUESTION_EXPIRE_DAYS = 3;
    /** Below this many decisions Charlie doesn't know the owner's order for a kind. */
    public const KNOWN_DECISIONS = 5;

    private PDO $db;
    private CharlieBriefService $briefs;
    private ?string $now;

    public function __construct(PDO $db, ?CharlieBriefService $briefs = null, ?string $now = null)
    {
        $this->db = $db;
        $this->briefs = $briefs ?? new CharlieBriefService($db);
        $this->now = $now;
    }

    private function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }
    private function day(): string { return substr($this->now(), 0, 10); }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'charlie_items'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Owner
    // ─────────────────────────────────────────────────────────────────────────

    public function ownerId(): ?int
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'charlie_owner_user_id'");
            $s->execute();
            $v = (int)$s->fetchColumn();
            return $v > 0 ? $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function isOwner(array $user): bool
    {
        $owner = $this->ownerId();
        if ($owner !== null) return (int)($user['id'] ?? 0) === $owner;
        return ($user['role'] ?? '') === 'admin';
    }

    /** The owner's users row (for the email), or null. */
    public function owner(): ?array
    {
        $id = $this->ownerId();
        try {
            if ($id === null) {
                $row = $this->db->query("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            } else {
                $s = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $s->execute([$id]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
            }
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Today
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ask every head, remember what they said, rank it, keep the morning brief.
     * @return array{one: ?array, rest: array, heads: array, total: int, failed: array, connected: string[], say: array}
     */
    public function today(string $name, array $extraBad = []): array
    {
        $c = $this->briefs->collect($name);
        $this->sync($c);
        $ranked = CharlieRankService::rank($this->withFirstSeen($c['items']), $this->prefs(), $this->hiddenKeys(), $this->day());
        $one = $ranked[0] ?? null;

        $rankOf = [];
        foreach ($ranked as $i => $r) $rankOf[$r['key']] = $r;
        $heads = [];
        foreach ($c['heads'] as $head => $h) {
            $mine = array_values(array_filter(array_map(fn($it) => $rankOf[$it['key']] ?? null, $h['items'])));
            usort($mine, fn($a, $b) => $b['score'] <=> $a['score']);
            $h['items'] = array_slice($mine, 0, self::HEAD_ITEMS);
            $h['waiting'] = count($mine);
            $heads[$head] = $h;
        }
        $withItems = count(array_filter($heads, fn($h) => $h['waiting'] > 0));
        $view = [
            'one'       => $one,
            'rest'      => array_slice($ranked, 1, self::SNAPSHOT_ITEMS - 1),
            'heads'     => $heads,
            'total'     => count($ranked),
            'failed'    => $c['failed'],
            'connected' => $c['ok'],
            'say'       => CharlieVoice::say($name, $one, max(0, count($ranked) - 1), $withItems),
            'bad'       => self::badNews($c['failed'], $ranked, $extraBad),
        ];
        $this->snapshot($view);
        $this->maybeAskWhichFirst($ranked);
        return $view;
    }

    /**
     * Pure: bad news, said first — urgent alerts, overdue deadlines, held conflicts (from
     * $extra, in the order given), then heads Charlie couldn't reach. An honest brief leads
     * with what went wrong.
     * @param array $failed head => error; $ranked: today's items; $extra: [text] from urgent/inbox
     * @return string[]
     */
    public static function badNews(array $failed, array $ranked, array $extra = []): array
    {
        $out = [];
        foreach ($extra as $t) if (is_string($t) && trim($t) !== '') $out[] = trim($t);
        foreach ($ranked as $it) {
            if (strpos($it['key'], 'charlie:deadline:') === 0 && preg_match('/— (overdue since|was due yesterday)/', $it['text'])) {
                $out[] = 'Overdue: ' . $it['text'];
            }
        }
        $names = CharlieBriefService::HEADS;
        foreach (array_keys($failed) as $h) {
            $out[] = "I couldn't reach " . ($names[$h]['name'] ?? ucfirst((string)$h)) . ' — their part of this brief is missing.';
        }
        return array_values(array_unique($out));
    }

    /** Remember what every head said; close what vanished; learn from what was dealt with. */
    public function sync(array $c): void
    {
        $now = $this->now();
        $up = $this->db->prepare("
            INSERT INTO charlie_items (item_key, head, kind, text, url, priority, value, since, first_seen, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                head = VALUES(head), kind = VALUES(kind), text = VALUES(text), url = VALUES(url),
                priority = VALUES(priority), value = VALUES(value), since = VALUES(since), last_seen = VALUES(last_seen),
                first_seen    = IF(resolved_at IS NULL, first_seen, VALUES(first_seen)),
                dismissed_at  = IF(resolved_at IS NULL, dismissed_at, NULL),
                opened_at     = IF(resolved_at IS NULL, opened_at, NULL),
                snoozed_until = IF(resolved_at IS NULL, snoozed_until, NULL),
                learned       = IF(resolved_at IS NULL, learned, 0),
                resolved_at   = NULL
        ");
        $current = [];
        foreach ($c['items'] as $it) {
            $up->execute([$it['key'], $it['head'], $it['kind'], $it['text'], $it['url'], $it['priority'], $it['value'], $it['since'], $now, $now]);
            $current[$it['key']] = true;
        }
        if (!$c['ok']) return;

        $in = implode(',', array_fill(0, count($c['ok']), '?'));
        $s = $this->db->prepare("SELECT item_key, head, kind, learned, dismissed_at FROM charlie_items WHERE resolved_at IS NULL AND head IN ({$in})");
        $s->execute($c['ok']);
        $gone = array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn($r) => !isset($current[$r['item_key']])));
        if (!$gone) return;

        $truncated = [];
        foreach ($c['heads'] as $head => $h) {
            if ((int)$h['count'] > count($h['items'])) $truncated[$head] = true;
        }
        $close = $this->db->prepare("UPDATE charlie_items SET resolved_at = ? WHERE item_key = ?");
        foreach ($gone as $g) $close->execute([$now, $g['item_key']]);
        foreach ($gone as $g) {
            if ((int)$g['learned'] === 0 && $g['dismissed_at'] === null && !isset($truncated[$g['head']])) {
                $this->learnWin($g['item_key'], $g['kind']);
            }
        }
    }

    private function withFirstSeen(array $items): array
    {
        if (!$items) return [];
        $in = implode(',', array_fill(0, count($items), '?'));
        $s = $this->db->prepare("SELECT item_key, first_seen FROM charlie_items WHERE item_key IN ({$in})");
        $s->execute(array_column($items, 'key'));
        $seen = $s->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($items as &$it) $it['first_seen'] = $seen[$it['key']] ?? null;
        return $items;
    }

    /** @return array<string, array{score: float, decisions: int, muted: bool}> */
    public function prefs(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT kind, score, decisions, muted FROM charlie_prefs")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['kind']] = ['score' => (float)$r['score'], 'decisions' => (int)$r['decisions'], 'muted' => (bool)$r['muted']];
        }
        return $out;
    }

    private function hiddenKeys(): array
    {
        $s = $this->db->prepare("
            SELECT item_key FROM charlie_items
            WHERE resolved_at IS NULL
              AND (dismissed_at >= ? OR (snoozed_until IS NOT NULL AND snoozed_until > ?))
        ");
        // Opening an item is not doing it ("Start the quote" then closing the tab): it stays on the
        // list until the head stops reporting it (the work is done) or Tim says "Not now".
        $t = $this->day();
        $s->execute([$t . ' 00:00:00', $t]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The morning brief
    // ─────────────────────────────────────────────────────────────────────────

    /** Today's brief row, kept current until it's emailed or acted on. */
    public function snapshot(array $view): void
    {
        $today = $this->day();
        $row = $this->brief($today);
        if ($row && ($row['emailed_at'] !== null || $row['first_acted_key'] !== null)) return;
        $payload = json_encode(self::payload($view));
        $top = $view['one']['key'] ?? null;
        if ($row) {
            $this->db->prepare("UPDATE charlie_briefs SET top_key = ?, payload = ? WHERE brief_date = ?")->execute([$top, $payload, $today]);
        } else {
            $this->db->prepare("INSERT IGNORE INTO charlie_briefs (brief_date, user_id, top_key, payload) VALUES (?, ?, ?, ?)")
                ->execute([$today, $this->ownerId(), $top, $payload]);
            if ($top) $this->db->prepare("UPDATE charlie_items SET times_top = times_top + 1 WHERE item_key = ?")->execute([$top]);
        }
    }

    /** Pure: what of the view is kept as the morning brief. */
    public static function payload(array $view): array
    {
        $slim = fn($it) => ['key' => $it['key'], 'head' => $it['head'], 'kind' => $it['kind'], 'text' => $it['text'],
                            'url' => $it['url'], 'priority' => $it['priority'], 'score' => $it['score'] ?? null];
        $items = array_map($slim, array_values(array_filter(array_merge([$view['one']], $view['rest']))));
        $heads = [];
        foreach ($view['heads'] as $head => $h) {
            $heads[$head] = ['name' => $h['name'], 'role' => $h['role'], 'headline' => $h['headline'],
                             'waiting' => $h['waiting'] ?? count($h['items']), 'items' => array_map($slim, $h['items'])];
        }
        return ['one' => $view['one']['key'] ?? null, 'items' => $items, 'heads' => $heads, 'bad' => array_values((array)($view['bad'] ?? []))];
    }

    /**
     * Brief time: a weekday, 07:00–09:59 local (America/Vancouver, set by config.php).
     * Hours 8–9 only catch up a missed 7 o'clock run — the brief still goes once a day.
     */
    public static function isSendTime(string $localNow): bool
    {
        $t = strtotime($localNow);
        $dow = (int)date('N', $t);
        $h = (int)date('G', $t);
        return $dow <= 5 && $h >= 7 && $h <= 9;
    }

    public function brief(string $date): ?array
    {
        $s = $this->db->prepare("SELECT * FROM charlie_briefs WHERE brief_date = ?");
        $s->execute([$date]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The owner saw today's brief on the dashboard (first time only). */
    public function markSeen(): void
    {
        $this->db->prepare("UPDATE charlie_briefs SET opened_at = ? WHERE brief_date = ? AND opened_at IS NULL")
            ->execute([$this->now(), $this->day()]);
    }

    public function markEmailed(string $date, ?string $error): void
    {
        $this->db->prepare("UPDATE charlie_briefs SET emailed_at = ?, email_error = ? WHERE brief_date = ?")
            ->execute([$error === null ? $this->now() : null, $error === null ? null : substr($error, 0, 255), $date]);
    }

    private function briefKeys(): array
    {
        $row = $this->brief($this->day());
        if (!$row) return [];
        return array_column((array)(json_decode((string)$row['payload'], true)['items'] ?? []), 'key');
    }

    private function firstActed(string $key): void
    {
        if (!in_array($key, $this->briefKeys(), true)) return;
        $this->db->prepare("UPDATE charlie_briefs SET first_acted_key = ?, first_acted_at = ? WHERE brief_date = ? AND first_acted_key IS NULL")
            ->execute([$key, $this->now(), $this->day()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Learning
    // ─────────────────────────────────────────────────────────────────────────

    /** Kinds from this morning's brief that are still waiting (not this kind). */
    private function waitingKinds(string $exceptKey, string $exceptKind): array
    {
        $keys = array_values(array_diff($this->briefKeys(), [$exceptKey]));
        if (!$keys) return [];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $s = $this->db->prepare("
            SELECT DISTINCT kind FROM charlie_items
            WHERE item_key IN ({$in}) AND kind <> ? AND resolved_at IS NULL AND dismissed_at IS NULL AND opened_at IS NULL
        ");
        $s->execute(array_merge($keys, [$exceptKind]));
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    private function learnWin(string $key, string $kind): void
    {
        foreach ($this->waitingKinds($key, $kind) as $other) $this->pair($kind, $other, CharlieRankService::K);
        $this->db->prepare("UPDATE charlie_items SET learned = 1 WHERE item_key = ?")->execute([$key]);
        $this->firstActed($key);
    }

    private function learnLoss(string $key, string $kind): void
    {
        foreach ($this->waitingKinds($key, $kind) as $other) $this->pair($other, $kind, CharlieRankService::K_DISMISS);
        $this->db->prepare("UPDATE charlie_items SET learned = 1 WHERE item_key = ?")->execute([$key]);
    }

    /** One pairwise step: $winner's kind went before $loser's. */
    private function pair(string $winner, string $loser, float $k): void
    {
        $p = $this->prefs();
        [$w, $l] = CharlieRankService::learnPair($p[$winner]['score'] ?? 1.0, $p[$loser]['score'] ?? 1.0, $k);
        $set = $this->db->prepare("
            INSERT INTO charlie_prefs (kind, score, decisions) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE score = VALUES(score), decisions = decisions + 1
        ");
        $set->execute([$winner, $w]);
        $set->execute([$loser, $l]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The owner's buttons
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, message?: string, url?: ?string} */
    public function act(string $key, string $what): array
    {
        $s = $this->db->prepare("SELECT * FROM charlie_items WHERE item_key = ?");
        $s->execute([$key]);
        $it = $s->fetch(PDO::FETCH_ASSOC);
        if (!$it) return ['ok' => false, 'message' => "I don't know that one"];
        if ($it['resolved_at'] !== null) return ['ok' => true, 'message' => 'Already dealt with', 'url' => $it['url']];
        $now = $this->now();
        switch ($what) {
            case 'open':
                if ($it['opened_at'] === null) {
                    $this->db->prepare("UPDATE charlie_items SET opened_at = ? WHERE item_key = ?")->execute([$now, $key]);
                    if ((int)$it['learned'] === 0) $this->learnWin($key, $it['kind']);
                }
                return ['ok' => true, 'url' => $it['url']];
            case 'dismiss':
                $this->db->prepare("UPDATE charlie_items SET dismissed_at = ? WHERE item_key = ?")->execute([$now, $key]);
                if ((int)$it['learned'] === 0) $this->learnLoss($key, $it['kind']);
                $this->maybeAskMute($it['kind'], $it['text']);
                return ['ok' => true, 'message' => "Got it — off today's list."];
            case 'snooze':
                $this->db->prepare("UPDATE charlie_items SET snoozed_until = ? WHERE item_key = ?")
                    ->execute([date('Y-m-d', strtotime($this->day() . ' +1 day')), $key]);
                return ['ok' => true, 'message' => "I'll bring it back tomorrow."];
        }
        return ['ok' => false, 'message' => 'Unknown action'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Questions
    // ─────────────────────────────────────────────────────────────────────────

    private function maybeAskWhichFirst(array $ranked): void
    {
        if (!CharlieRankService::closeCall($ranked)) return;
        [$a, $b] = $ranked;
        $p = $this->prefs();
        if (min($p[$a['kind']]['decisions'] ?? 0, $p[$b['kind']]['decisions'] ?? 0) >= self::KNOWN_DECISIONS) return;
        $this->db->prepare("UPDATE charlie_questions SET status = 'expired' WHERE status = 'open' AND kind = 'which_first' AND created_at < ?")
            ->execute([date('Y-m-d H:i:s', strtotime($this->now() . ' -' . self::QUESTION_EXPIRE_DAYS . ' days'))]);
        if ((int)$this->db->query("SELECT COUNT(*) FROM charlie_questions WHERE status = 'open' AND kind = 'which_first'")->fetchColumn() > 0) return;
        $kinds = [$a['kind'], $b['kind']];
        sort($kinds);
        $this->db->prepare("
            INSERT IGNORE INTO charlie_questions (kind, pair_hash, key_a, key_b, kind_a, kind_b, text_a, text_b)
            VALUES ('which_first', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([sha1('which_first|' . implode('|', $kinds)), $a['key'], $b['key'], $a['kind'], $b['kind'],
                     mb_substr($a['text'], 0, 500), mb_substr($b['text'], 0, 500)]);
    }

    private function maybeAskMute(string $kind, string $text): void
    {
        $s = $this->db->prepare("SELECT COUNT(*) FROM charlie_items WHERE kind = ? AND dismissed_at >= ?");
        $s->execute([$kind, date('Y-m-d H:i:s', strtotime($this->now() . ' -' . self::MUTE_WINDOW_DAYS . ' days'))]);
        $n = (int)$s->fetchColumn();
        if ($n < self::MUTE_AFTER) return;
        $this->db->prepare("
            INSERT IGNORE INTO charlie_questions (kind, pair_hash, kind_a, text_a, text_b) VALUES ('mute', ?, ?, ?, ?)
        ")->execute([sha1('mute|' . $kind), $kind, mb_substr($text, 0, 500), (string)$n]);
    }

    public function questions(string $name): array
    {
        $rows = $this->db->query("SELECT * FROM charlie_questions WHERE status = 'open' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $q) {
            if ($q['kind'] === 'which_first') {
                $out[] = ['id' => (int)$q['id'], 'kind' => 'which_first', 'question' => CharlieVoice::whichFirst($name),
                          'options' => [['answer' => 'a', 'label' => (string)$q['text_a']], ['answer' => 'b', 'label' => (string)$q['text_b']]]];
            } elseif ($q['kind'] === 'rule_rewrite') {
                $out[] = ['id' => (int)$q['id'], 'kind' => 'rule_rewrite', 'question' => CharlieVoice::ruleRewrite($name, (string)$q['text_a']),
                          'options' => [['answer' => 'change', 'label' => 'Yes, change the rule'], ['answer' => 'keep', 'label' => 'No, keep it as it is']]];
            } else {
                $out[] = ['id' => (int)$q['id'], 'kind' => 'mute', 'question' => CharlieVoice::mute($name, (string)$q['text_a'], (int)$q['text_b']),
                          'options' => [['answer' => 'mute', 'label' => 'Yes, leave them out'], ['answer' => 'keep', 'label' => 'No, keep showing them']]];
            }
        }
        return $out;
    }

    public function answer(int $id, string $answer): array
    {
        $s = $this->db->prepare("SELECT * FROM charlie_questions WHERE id = ?");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q || $q['status'] !== 'open') return ['ok' => false, 'message' => 'Already answered'];
        $valid = ['which_first' => ['a', 'b'], 'rule_rewrite' => ['change', 'keep']][$q['kind']] ?? ['mute', 'keep'];
        if (!in_array($answer, $valid, true)) return ['ok' => false, 'message' => 'Unknown answer'];
        $this->db->prepare("UPDATE charlie_questions SET status = 'answered', answer = ?, answered_at = ? WHERE id = ?")
            ->execute([$answer, $this->now(), $id]);
        if ($q['kind'] === 'which_first') {
            [$w, $l] = $answer === 'a' ? [$q['kind_a'], $q['kind_b']] : [$q['kind_b'], $q['kind_a']];
            $this->pair((string)$w, (string)$l, CharlieRankService::K_ANSWER);
            return ['ok' => true, 'message' => "Thanks — I'll put that kind first from now on."];
        }
        if ($q['kind'] === 'rule_rewrite') {
            if ($answer === 'keep') return ['ok' => true, 'message' => 'Okay — the rule stays as it is.'];
            $this->db->prepare("UPDATE charlie_rules SET params = ?, text = ? WHERE slug = ?")
                ->execute([json_encode(json_decode((string)$q['text_b'], true) ?: []), mb_substr((string)$q['text_a'], 0, 500), (string)$q['kind_a']]);
            return ['ok' => true, 'message' => 'Done — the rule now reads: ' . $q['text_a']];
        }
        if ($answer === 'mute') {
            $this->db->prepare("INSERT INTO charlie_prefs (kind, muted) VALUES (?, 1) ON DUPLICATE KEY UPDATE muted = 1")->execute([$q['kind_a']]);
            return ['ok' => true, 'message' => "Done — I'll leave those out."];
        }
        return ['ok' => true, 'message' => "Okay — I'll keep showing them."];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Brain
    // ─────────────────────────────────────────────────────────────────────────

    /** Raw counts for HeadBrain: things Charlie has learned about the owner. */
    public function brainCounts(int $badges, int $connectedHeads): array
    {
        $one = fn(string $sql) => (int)$this->db->query($sql)->fetchColumn();
        return [
            'order'     => $one("SELECT COUNT(*) FROM charlie_prefs WHERE decisions >= 3 AND ABS(score - 1) > 0.25"),
            'mutes'     => $one("SELECT COUNT(*) FROM charlie_prefs WHERE muted = 1"),
            'answers'   => $one("SELECT COUNT(*) FROM charlie_questions WHERE status = 'answered'"),
            'badges'    => $badges,
            'heads'     => $connectedHeads,
        ];
    }

    public const BRAIN_LABELS = [
        'order'   => ['kind of item I know your order for', 'kinds of item I know your order for'],
        'mutes'   => ['thing you told me to leave out', 'things you told me to leave out'],
        'answers' => ['question you answered', 'questions you answered'],
        'badges'  => ['badge', 'badges'],
        'heads'   => ['new head reporting to me', 'new heads reporting to me'],
    ];
}
