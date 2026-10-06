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
 * No namespace / no autoloader in production: require_once and `new`.
 */
class HeadBrain
{
    public const SHAPES = 500;

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

    /** Shape number (1-based) for this many things learned: one new shape per thing, up to SHAPES. */
    public static function shapeNumber(int $units): int
    {
        return max(1, min(self::SHAPES, $units + 1));
    }
}
