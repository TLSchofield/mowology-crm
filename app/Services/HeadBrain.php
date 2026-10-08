<?php
/**
 * HeadBrain — what a department head has learned, counted for the 3D "brain" on the
 * dashboard deck. Shared by every head (Sam first; Otto, Mia and Charlie next; Penny's
 * own PennyBrainService does the same job and can move onto this later).
 *
 * A head passes its raw counts ("things I know") and its labels. Only what was learned
 * since the head's start line counts: the first time the brain is shown, today's counts
 * are saved to ops_settings under the head's own key, so every head starts at shape 1
 * and grows with what it learns from the owner from then on.
 * public/crm/js/head-brain.js draws the shape (one of 500, one new shape per thing learned).
 *
 * Per-item strength (Tim, 2026-10-07): a head may also report ITEMS — one learned thing
 * each (a vendor, a bank payee, a follow-up situation…) with a stable `key`
 * ("vendor:12", "payee:telus mobility") and a `strength`: how many times in a row the
 * owner confirmed it unchanged. Each item gets its own triangle (the key hashes to it)
 * and its colour is its tier on the 7-step ladder in TIERS below — the ONE place the
 * thresholds live; head-brain.js mirrors this table. An item with no strength shows as
 * Bronze. A correction drops an item one tier (strengthFromHistory).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class HeadBrain
{
    public const SHAPES = 500;

    /**
     * The strength ladder: [slug, name, lowest strength]. Strength = consistent
     * confirmations (approved unchanged / right first time) in a row.
     *   Obsidian 1 (first seen) · Black 2 · Bronze 3–4 · Silver 5–9 (trusted — Penny's
     *   "5 in a row" vendor trust) · Gold 10–19 · White 20–49 · Platinum 50+.
     * public/crm/js/head-brain.js TIERS mirrors these numbers — change both together.
     */
    public const TIERS = [
        ['obsidian', 'Obsidian', 1],
        ['black',    'Black',    2],
        ['bronze',   'Bronze',   3],
        ['silver',   'Silver',   5],
        ['gold',     'Gold',     10],
        ['white',    'White',    20],
        ['platinum', 'Platinum', 50],
    ];
    /** Shown for an item whose head doesn't track strength. */
    public const UNSET_TIER = 2;
    /** At most this many items light triangles (the biggest shape has ~503); strongest kept. */
    public const MAX_ITEMS = 480;

    private PDO $db;
    private string $key;

    /** @param string $head 'sam', 'otto', … — the start line is saved as "<head>_brain_baseline". */
    public function __construct(PDO $db, string $head)
    {
        $this->db = $db;
        $this->key = preg_replace('/[^a-z0-9_]/', '', strtolower($head)) . '_brain_baseline';
    }

    /**
     * @param array<string, int>             $raw    counts now, by kind
     * @param array<string, array{0: string, 1: string}> $labels kind => [singular, plural]
     * @return array{units: int, parts: array, since: ?string}
     */
    public function learned(array $raw, array $labels): array
    {
        $base = $this->baseline($raw);
        return self::combine(self::sinceBaseline($raw, $base['counts']), $labels) + ['since' => $base['since']];
    }

    /** The start line: the counts on the day the brain was first shown (saved then). */
    private function baseline(array $raw): array
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$this->key]);
            $v = $s->fetchColumn();
            $b = $v ? json_decode((string)$v, true) : null;
            if (is_array($b) && isset($b['counts'])) return $b;
            $b = ['since' => date('Y-m-d'), 'counts' => $raw];
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description)
                VALUES (?, ?, 'Department head brain start line: what was already known before the brain started counting')
                ON DUPLICATE KEY UPDATE setting_key = setting_key
            ")->execute([$this->key, json_encode($b)]);
            return $b;
        } catch (Throwable $e) {
            return ['since' => null, 'counts' => []];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** What's new since the start line (never below zero — a lost streak isn't unlearning). */
    public static function sinceBaseline(array $raw, array $base): array
    {
        $out = [];
        foreach ($raw as $k => $v) $out[$k] = max(0, (int)$v - (int)($base[$k] ?? 0));
        return $out;
    }

    /** Units and plain-English parts, in the order the labels are given. */
    public static function combine(array $n, array $labels): array
    {
        $parts = [];
        $units = 0;
        foreach ($labels as $k => [$one, $many]) {
            $c = max(0, (int)($n[$k] ?? 0));
            $units += $c;
            if ($c > 0) $parts[] = ['key' => $k, 'label' => $c . ' ' . ($c === 1 ? $one : $many), 'n' => $c];
        }
        return ['units' => $units, 'parts' => $parts];
    }

    /**
     * The tier for a strength. Null (not tracked) reads as Bronze; anything below 1 is
     * still "first seen" (Obsidian).
     * @return array{rank: int, slug: string, name: string, min: int}
     */
    public static function tier(?int $strength): array
    {
        $rank = self::UNSET_TIER;
        if ($strength !== null) {
            $rank = 0;
            foreach (self::TIERS as $i => $t) if ($strength >= $t[2]) $rank = $i;
        }
        [$slug, $name, $min] = self::TIERS[$rank];
        return ['rank' => $rank, 'slug' => $slug, 'name' => $name, 'min' => $min];
    }

    /** Strength after a correction: the bottom of the tier one below (never below first seen). */
    public static function afterCorrection(int $strength): int
    {
        $rank = self::tier(max(1, $strength))['rank'];
        return $rank === 0 ? 1 : self::TIERS[$rank - 1][2];
    }

    /**
     * Fold an item's decisions, oldest first, into its strength. Each event is
     * true (kept unchanged — one more confirmation) or false (corrected — drops one tier).
     * The first event, either way, is "first seen" (1).
     * @param bool[] $events
     * @return array{strength: int, streak: int, corrected_recently: bool}
     */
    public static function strengthFromHistory(array $events): array
    {
        $s = 0;
        $streak = 0;
        $sinceCorrection = null;
        foreach (array_values($events) as $kept) {
            if ($kept) {
                $s++;
                $streak++;
                if ($sinceCorrection !== null) $sinceCorrection++;
            } else {
                $s = $s === 0 ? 1 : self::afterCorrection($s);
                $streak = 0;
                $sinceCorrection = 0;
            }
        }
        return ['strength' => $s, 'streak' => $streak,
                'corrected_recently' => $sinceCorrection !== null && $sinceCorrection < 3];
    }

    /**
     * One learned item as a brain part (the shape head-brain.js reads).
     * @param array $extra optional: group, streak, corrected_recently, at (Y-m-d H:i:s), note,
     *                    raw (the source's own wording when the label is a cleaned/taught name)
     */
    public static function item(string $key, string $label, ?int $strength, array $extra = []): array
    {
        $p = ['key' => $key, 'label' => $label, 'strength' => $strength === null ? null : max(1, $strength)];
        foreach (['group', 'streak', 'corrected_recently', 'at', 'note', 'raw'] as $k) {
            if (array_key_exists($k, $extra) && $extra[$k] !== null) $p[$k] = $extra[$k];
        }
        return $p;
    }

    /**
     * Add items to a counted brain (learned()/combine() result): every item lights its own
     * triangle, so units grows by one per item. Items come first in parts, strongest first;
     * past MAX_ITEMS the weakest are left off.
     */
    public static function withItems(array $brain, array $items): array
    {
        usort($items, fn($a, $b) => [($b['strength'] ?? 0), $a['key']] <=> [($a['strength'] ?? 0), $b['key']]);
        $items = array_slice($items, 0, self::MAX_ITEMS);
        $brain['parts'] = array_merge($items, $brain['parts'] ?? []);
        $brain['units'] = (int)($brain['units'] ?? 0) + count($items);
        return $brain;
    }

    /**
     * Template lessons from a drafts table (Sam's sam_followups, Yui's yui_actions): one item
     * per situation (template_key + channel) the owner rewrote; strength from the drafts for
     * it since — sent as written = kept, rewritten = corrected.
     * @param array<string, string> $names template_key => plain name
     */
    public function templateItems(string $table, string $group, array $names = []): array
    {
        if (!in_array($table, ['sam_followups', 'yui_actions'], true)) return [];
        try {
            $rows = $this->db->query("
                SELECT template_key, channel, status, decided_at FROM {$table}
                WHERE status IN ('sent', 'edited') AND template_key IS NOT NULL AND template_key <> ''
                ORDER BY decided_at ASC, id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return self::templateItemsFrom($rows, $group, $names);
    }

    /** Pure: rows oldest first [template_key, channel, status, decided_at]. A situation is learned once rewritten. */
    public static function templateItemsFrom(array $rows, string $group, array $names = []): array
    {
        $by = [];
        foreach ($rows as $r) {
            $k = $r['template_key'] . ':' . ($r['channel'] ?: 'email');
            $by[$k]['events'][] = $r['status'] === 'sent';
            $by[$k]['learned'] = ($by[$k]['learned'] ?? false) || $r['status'] === 'edited';
            $by[$k]['at'] = $r['decided_at'] ?? null;
            $by[$k]['tk'] = (string)$r['template_key'];
            $by[$k]['ch'] = (string)($r['channel'] ?: 'email');
        }
        $out = [];
        foreach ($by as $k => $v) {
            if (!$v['learned']) continue;
            // Learning starts at the first rewrite: that's the lesson; what came before was the stock template.
            $first = array_search(false, $v['events'], true);
            $h = self::strengthFromHistory(array_slice($v['events'], (int)$first));
            $name = $names[$v['tk']] ?? ucfirst(str_replace('_', ' ', $v['tk']));
            $out[] = self::item('template:' . $k, $name . ($v['ch'] === 'sms' ? ' (text)' : ' (email)'), $h['strength'],
                ['group' => $group, 'streak' => $h['streak'], 'corrected_recently' => $h['corrected_recently'], 'at' => $v['at']]);
        }
        return $out;
    }

    /**
     * Pure: how many learned items sit on each tier, all seven, strongest first
     * (the full brain page's tier bar). Counts only items (parts with a strength key);
     * an item whose strength isn't tracked counts as Bronze, as it is drawn.
     * @return array<int, array{slug: string, name: string, min: int, n: int}>
     */
    public static function tierCounts(array $parts): array
    {
        $n = array_fill(0, count(self::TIERS), 0);
        foreach ($parts as $p) {
            if (!is_array($p) || !array_key_exists('strength', $p)) continue;
            $n[self::tier($p['strength'] === null ? null : (int)$p['strength'])['rank']]++;
        }
        $out = [];
        foreach (self::TIERS as $i => [$slug, $name, $min]) $out[] = ['slug' => $slug, 'name' => $name, 'min' => $min, 'n' => $n[$i]];
        return array_reverse($out);
    }

    /** Shape number (1-based) for this many things learned: one new shape per thing, up to SHAPES. */
    public static function shapeNumber(int $units): int
    {
        return max(1, min(self::SHAPES, $units + 1));
    }
}
