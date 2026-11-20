<?php
/**
 * CharlieBadgeService — the badges under Charlie's photo.
 *
 * Every badge comes from what the owner actually did with Charlie's briefs, never from
 * Charlie's own claims, and the streak badges are lost again when he slips:
 *   called it   — the owner went first to Charlie's "one thing" on 5 of the last 7 mornings
 *   clean sweep — every item in a morning's brief dealt with the same day (last 7 days)
 *   early bird  — the brief seen before 8 am, 5 mornings running
 *   tuned in    — 6 kinds of item where Charlie has learned the owner's order (5+ decisions)
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CharlieBadgeService
{
    public const CALLED_IT = 5;
    public const CALLED_WINDOW = 7;
    public const EARLY_RUN = 5;
    public const EARLY_BEFORE = '08:00';
    public const TUNED_KINDS = 6;
    public const TUNED_DECISIONS = 5;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{earned: array, next: ?array, called_rate: ?float} */
    public function badges(): array
    {
        try {
            $briefs = $this->db->query("
                SELECT brief_date, top_key, first_acted_key, opened_at, payload
                FROM charlie_briefs ORDER BY brief_date DESC LIMIT 14
            ")->fetchAll(PDO::FETCH_ASSOC);
            $days = [];
            foreach ($briefs as $b) {
                $keys = array_column((array)(json_decode((string)$b['payload'], true)['items'] ?? []), 'key');
                $done = 0;
                if ($keys) {
                    $in = implode(',', array_fill(0, count($keys), '?'));
                    $s = $this->db->prepare("
                        SELECT COUNT(*) FROM charlie_items WHERE item_key IN ({$in})
                          AND (DATE(resolved_at) = ? OR DATE(opened_at) = ?)
                    ");
                    $s->execute(array_merge($keys, [$b['brief_date'], $b['brief_date']]));
                    $done = (int)$s->fetchColumn();
                }
                $days[] = [
                    'date'            => (string)$b['brief_date'],
                    'top_key'         => $b['top_key'],
                    'first_acted_key' => $b['first_acted_key'],
                    'opened_time'     => $b['opened_at'] ? date('H:i', strtotime((string)$b['opened_at'])) : null,
                    'total'           => count($keys),
                    'done_same_day'   => $done,
                ];
            }
            $prefs = $this->db->query("SELECT kind, decisions FROM charlie_prefs")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            return ['earned' => [], 'next' => null, 'called_rate' => null];
        }
        return self::compute($days, array_map('intval', $prefs), date('Y-m-d'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $days  newest first: [date, top_key, first_acted_key, opened_time (H:i|null), total, done_same_day]
     * @param array $decisionsByKind kind => decisions
     * @return array{earned: array, next: ?array, called_rate: ?float}
     */
    public static function compute(array $days, array $decisionsByKind, string $today): array
    {
        // Called it: of the last 7 mornings where the owner acted, how often on Charlie's pick first.
        $acted = array_slice(array_values(array_filter($days, fn($d) => !empty($d['first_acted_key']))), 0, self::CALLED_WINDOW);
        $hits = count(array_filter($acted, fn($d) => $d['first_acted_key'] === $d['top_key']));
        $rate = $acted ? round($hits / count($acted), 2) : null;

        // Early bird: consecutive mornings (newest first) seen before 8; today doesn't break it until it's over.
        $run = 0;
        foreach ($days as $d) {
            if ($d['date'] === $today && $d['opened_time'] === null) continue;
            if ($d['opened_time'] === null || $d['opened_time'] >= self::EARLY_BEFORE) break;
            $run++;
        }

        $week = array_slice($days, 0, 7);
        $swept = count(array_filter($week, fn($d) => (int)$d['total'] > 0 && (int)$d['done_same_day'] >= (int)$d['total']));
        $tuned = count(array_filter($decisionsByKind, fn($n) => (int)$n >= self::TUNED_DECISIONS));

        $badges = [
            ['key' => 'called', 'icon' => '🎯', 'label' => 'Called it',
             'title' => 'You went first to my "one thing" on ' . self::CALLED_IT . ' of the last ' . self::CALLED_WINDOW . ' mornings',
             'have' => min(self::CALLED_IT, $hits), 'need' => self::CALLED_IT],
            ['key' => 'sweep', 'icon' => '🧹', 'label' => 'Clean sweep',
             'title' => 'Everything in a morning brief dealt with the same day (this week)',
             'have' => min(1, $swept), 'need' => 1],
            ['key' => 'early', 'icon' => '🌅', 'label' => 'Early bird',
             'title' => 'Brief read before 8 am, ' . self::EARLY_RUN . ' mornings running',
             'have' => min(self::EARLY_RUN, $run), 'need' => self::EARLY_RUN],
            ['key' => 'tuned', 'icon' => '🎚️', 'label' => 'Tuned in',
             'title' => 'I know your order for ' . self::TUNED_KINDS . ' kinds of item (5+ of your decisions each)',
             'have' => min(self::TUNED_KINDS, $tuned), 'need' => self::TUNED_KINDS],
        ];
        $earned = array_values(array_filter($badges, fn($b) => $b['have'] >= $b['need']));
        $open = array_values(array_filter($badges, fn($b) => $b['have'] < $b['need']));
        usort($open, fn($a, $b) => ($b['have'] / $b['need']) <=> ($a['have'] / $a['need']));
        return ['earned' => $earned, 'next' => $open[0] ?? null, 'called_rate' => $rate];
    }
}
