<?php
/**
 * HeadBriefReadyService — Charlie's brief kept ready on the server (owner, 2026-10-10).
 *
 * Building Charlie's brief reads every head's desk (~7 s on production, measured 2026-10-10), so
 * the iPhone's opening screen gave up before it arrived. Now a finished copy is kept in
 * head_brief_ready: built by Charlie's 15-minute cron, rebuilt in the background after the app
 * reads it, and served in well under a second.
 *
 * Never shows finished work: on every read, items Tim has dismissed today, snoozed or that are
 * resolved are taken out (charlie_items), and an item acted on from the app (done / move / otto)
 * is dropped from the copy at once. The Team tab keeps building live — only the opening screen
 * (?ready=1) uses the copy.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class HeadBriefReadyService
{
    /** Older than this is too old to show; the app then waits for a live build. */
    public const MAX_AGE_SECONDS = 3600;
    /** Older than this gets rebuilt in the background after it is served. */
    public const REFRESH_AFTER_SECONDS = 120;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** 'fresh' (serve), 'refresh' (serve, then rebuild behind it) or 'stale' (don't serve). */
    public static function state(?string $builtAt, int $now): string
    {
        if ($builtAt === null || $builtAt === '') return 'stale';
        $age = $now - (int)strtotime($builtAt);
        if ($age < 0) $age = 0;
        if ($age > self::MAX_AGE_SECONDS) return 'stale';
        return $age > self::REFRESH_AFTER_SECONDS ? 'refresh' : 'fresh';
    }

    /** The brief with the hidden keys taken out; 'waiting' drops by as many as were removed. */
    public static function without(array $brief, array $hiddenKeys): array
    {
        if (!$hiddenKeys || empty($brief['items'])) return $brief;
        $hide = array_flip($hiddenKeys);
        $kept = array_values(array_filter((array)$brief['items'], fn($it) => !isset($hide[(string)($it['key'] ?? '')])));
        $removed = count($brief['items']) - count($kept);
        $brief['items'] = $kept;
        if (isset($brief['waiting'])) $brief['waiting'] = max(count($kept), (int)$brief['waiting'] - $removed);
        return $brief;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM head_brief_ready LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array{brief: array, built_at: string}|null */
    public function get(string $head): ?array
    {
        if (!$this->ready()) return null;
        $s = $this->db->prepare("SELECT payload, built_at FROM head_brief_ready WHERE head = ?");
        $s->execute([$head]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $brief = json_decode((string)$row['payload'], true);
        if (!is_array($brief)) return null;
        return ['brief' => self::without($brief, $this->hiddenNow(array_column((array)($brief['items'] ?? []), 'key'))),
                'built_at' => (string)$row['built_at']];
    }

    public function put(string $head, array $brief, int $buildMs): void
    {
        if (!$this->ready()) return;
        $this->db->prepare("
            INSERT INTO head_brief_ready (head, payload, built_at, build_ms) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE payload = VALUES(payload), built_at = VALUES(built_at), build_ms = VALUES(build_ms)
        ")->execute([$head, json_encode($brief), date('Y-m-d H:i:s'), $buildMs]);
    }

    /** An item acted on from the app comes out of every ready copy at once. */
    public function drop(string $key): void
    {
        if ($key === '' || !$this->ready()) return;
        foreach ($this->db->query("SELECT head, payload FROM head_brief_ready")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $brief = json_decode((string)$row['payload'], true);
            if (!is_array($brief)) continue;
            $after = self::without($brief, [$key]);
            if (count((array)($after['items'] ?? [])) === count((array)($brief['items'] ?? []))) continue;
            $this->db->prepare("UPDATE head_brief_ready SET payload = ? WHERE head = ?")->execute([json_encode($after), $row['head']]);
        }
    }

    /** Of these keys, the ones dismissed today, snoozed or resolved (same rule as the Action Board). */
    private function hiddenNow(array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if (!$keys) return [];
        try {
            $today = date('Y-m-d');
            $s = $this->db->prepare("
                SELECT item_key FROM charlie_items
                WHERE item_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")
                  AND (resolved_at IS NOT NULL OR dismissed_at >= ? OR (snoozed_until IS NOT NULL AND snoozed_until > ?))
            ");
            $s->execute(array_merge($keys, [$today . ' 00:00:00', $today]));
            return $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Charlie's brief exactly as team-mobile ?mode=brief&head=charlie serves it to the owner.
     * Building it is what the Action Board does on every open (asks every head, records items).
     */
    public static function buildCharlie(PDO $db, CharlieForemanService $f, string $name, ?array $view = null): array
    {
        // The cron doesn't load it (team-mobile does) — "Class TeamCardService not found", 2026-10-10.
        if (!class_exists('TeamCardService')) require_once __DIR__ . '/TeamCardService.php';
        $view = $view ?? $f->card($name)['view'];
        $col = TeamCardService::column($view, 'charlie');
        $out = [
            'ok'       => true,
            'head'     => 'charlie',
            'name'     => TeamCardService::HEADS['charlie']['name'],
            'role'     => TeamCardService::HEADS['charlie']['role'],
            'face_url' => TeamCardService::faceUrl('charlie'),
            'headline' => $col['headline'],
            'waiting'  => $col['waiting'],
            'owner'    => true,
            'brain'    => (new TeamCardService($db))->brain('charlie'),
            // Labelled with the head who owns each item (Penny for money, …); Charlie still chose them.
            // 'route' is the card that holds the item, so a tap opens it there (Fable review 2026-10-12:
            // an overdue invoice labelled Penny opened Penny's card, which doesn't hold it).
            'items'    => array_map(static fn($it) => ['head' => TeamCardService::owner($it), 'route' => self::routeOf($it)] + $it, $col['items']),
        ];
        require_once __DIR__ . '/HeadStateService.php';
        $out['heads'] = HeadStateService::fromBrief((array)($view['state_items'] ?? []), (array)($view['failed'] ?? []));
        require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieAskService.php';
        $out['ask'] = (new CharlieAskService($db))->status();
        // A line for the foot of the brief (owner, 2026-10-11) — the same for everyone today.
        require_once __DIR__ . '/DailyQuote.php';
        $out['quote'] = DailyQuote::forDate(date('Y-m-d'));
        return $out;
    }

    /** The card an item lives on: its own head's, with house items on Charlie's (pure). */
    public static function routeOf(array $item): string
    {
        $h = (string)($item['head'] ?? '');
        return ($h === '' || $h === 'house') ? 'charlie' : $h;
    }

    // ── The dashboard's copy (speed pass, 2026-10-19) ───────────────────────────────────────────────
    // charlie.php ?mode=today takes ~7 s live, so the dashboard showed "Charlie is checking with the
    // team…" for that long. The same view the cron already builds every 15 minutes is now also kept in
    // the shape the dashboard reads; action-board.php prints it into the page and the live answer
    // replaces it when it lands. One builder for both, so the two can't drift.

    public const TODAY_KEY = 'charlie_today';
    /** Older than this and the dashboard ignores the saved copy (falls back to the live fetch). */
    public const TODAY_MAX_AGE_SEC = 1800;

    /** One of Charlie's items, slimmed for the dashboard (as charlie.php always served it). */
    public static function slim(?array $it): ?array
    {
        return $it === null ? null : [
            'key' => $it['key'], 'head' => $it['head'], 'text' => $it['text'], 'url' => $it['url'],
            'priority' => $it['priority'], 'value' => $it['value'],
            // For the dashboard's Action Board: what kind it is, how long it has waited, its rank.
            'kind' => $it['kind'] ?? null, 'since' => $it['since'] ?? null,
            'first_seen' => $it['first_seen'] ?? null, 'score' => $it['score'] ?? null,
        ];
    }

    /**
     * The ?mode=today answer from a CharlieForemanService::card() result.
     * @param array $card ['view' => …, 'inbox' => …]
     */
    public static function todayPayload(array $card, CharlieDeskService $desk, string $name): array
    {
        if (!class_exists('CharlieVoice')) require_once __DIR__ . '/CharlieVoice.php';
        $view = $card['view'];
        $heads = [];
        foreach ($view['heads'] as $h) {
            $heads[] = ['head' => $h['head'], 'name' => $h['name'], 'role' => $h['role'], 'headline' => $h['headline'],
                        'waiting' => $h['waiting'], 'items' => array_map([self::class, 'slim'], $h['items'])];
        }
        return [
            'ok'        => true,
            'bad'       => $view['bad'],
            'bad_lead'  => CharlieVoice::badLead($view['bad']),
            'say'       => $view['say'],
            'one'       => self::slim($view['one']),
            'rest'      => array_map([self::class, 'slim'], $view['rest']),
            'total'     => $view['total'],
            'heads'     => $heads,
            'failed'    => array_keys($view['failed']),
            'questions' => $desk->questions($name),
            'inbox'     => $card['inbox'],
        ];
    }

    public function keepToday(array $payload): void
    {
        $this->put(self::TODAY_KEY, $payload, 0);
    }

    /**
     * The dashboard's saved copy, if fresh: items acted on since (dismissed today, snoozed, resolved)
     * are taken out, as for the iPhone's copy. Adds 'saved_at'. Null when missing or stale.
     */
    public function today(?int $now = null): ?array
    {
        if (!$this->ready()) return null;
        $s = $this->db->prepare("SELECT payload, built_at FROM head_brief_ready WHERE head = ?");
        $s->execute([self::TODAY_KEY]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $age = ($now ?? time()) - (int)strtotime((string)$row['built_at']);
        if ($age > self::TODAY_MAX_AGE_SEC) return null;
        $p = json_decode((string)$row['payload'], true);
        if (!is_array($p) || empty($p['ok'])) return null;
        $keys = [];
        foreach (array_merge([$p['one'] ?? null], (array)($p['rest'] ?? [])) as $it) if (!empty($it['key'])) $keys[] = $it['key'];
        foreach ((array)($p['heads'] ?? []) as $h) foreach ((array)($h['items'] ?? []) as $it) if (!empty($it['key'])) $keys[] = $it['key'];
        return self::withoutToday($p, $this->hiddenNow(array_values(array_unique($keys)))) + ['saved_at' => (string)$row['built_at']];
    }

    /** Pure: the today payload with these keys taken out everywhere they appear. */
    public static function withoutToday(array $p, array $hiddenKeys): array
    {
        if (!$hiddenKeys) return $p;
        $hide = array_flip(array_map('strval', $hiddenKeys));
        $keep = static fn($it) => is_array($it) && !isset($hide[(string)($it['key'] ?? '')]);
        $rest = array_values(array_filter((array)($p['rest'] ?? []), $keep));
        $one = $p['one'] ?? null;
        if ($one !== null && !$keep($one)) $one = array_shift($rest);
        $p['one'] = $one;
        $p['rest'] = $rest;
        foreach ((array)($p['heads'] ?? []) as $i => $h) {
            $p['heads'][$i]['items'] = array_values(array_filter((array)($h['items'] ?? []), $keep));
        }
        return $p;
    }

    /** Build Charlie's brief and keep it. Returns the brief. */
    public function rebuildCharlie(CharlieForemanService $f, string $name, ?array $view = null): array
    {
        $t = microtime(true);
        $brief = self::buildCharlie($this->db, $f, $name, $view);
        $this->put('charlie', $brief, (int)round((microtime(true) - $t) * 1000));
        return $brief;
    }
}
