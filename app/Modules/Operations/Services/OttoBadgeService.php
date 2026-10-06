<?php
/**
 * OttoBadgeService — the badges under Otto's photo on the dashboard.
 *
 * Every badge is earned from the owner's real decisions on Otto's suggestions
 * (otto_suggestions), never from Otto's own claims:
 *   clock fixer    — his last 10 suggested clock-out times kept (within 5 minutes)
 *   weather wise   — his last 10 weather leans the owner followed
 *   quiet catcher  — 5 quiet phones the owner confirmed were a real problem
 *   gap closer     — 25 timesheet gaps closed from his suggestions
 *   in a row       — 10 suggestions in a row kept without a change
 *   bylaw keeper   — 10 bylaw / West End flags acted on (new time or a crew note)
 * Streak badges are lost again when he slips; the closest unearned one shows dimmed.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class OttoBadgeService
{
    public const CLOCK_RUN = 10;
    public const WEATHER_RUN = 10;
    public const QUIET_REAL = 5;
    public const GAPS = 25;
    public const STREAK = 10;
    public const BYLAW = 10;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{earned: array, next: ?array} */
    public function badges(): array
    {
        try {
            $rows = $this->db->query("
                SELECT kind, status, outcome_json FROM otto_suggestions
                WHERE status IN ('accepted', 'edited', 'dismissed')
                ORDER BY decided_at DESC, id DESC
                LIMIT 500
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['earned' => [], 'next' => null];
        }
        $decisions = array_map(fn($r) => [
            'kind' => (string)$r['kind'],
            'status' => (string)$r['status'],
            'outcome' => json_decode((string)$r['outcome_json'], true) ?: [],
        ], $rows);
        return self::compute($decisions);
    }

    /**
     * Pure.
     * @param array $decisions newest first: [kind, status, outcome]
     * @return array{earned: array, next: ?array}  badge: [key, icon, label, title, have, need]
     */
    public static function compute(array $decisions): array
    {
        $run = static function (array $list, callable $ok): int {
            $n = 0;
            foreach ($list as $d) {
                if (!$ok($d)) break;
                $n++;
            }
            return $n;
        };
        $of = static fn(array $kinds, bool $withDismissed = false) => array_values(array_filter($decisions,
            fn($d) => in_array($d['kind'], $kinds, true) && ($withDismissed || $d['status'] !== 'dismissed')));

        $badges = [];
        $badges[] = ['key' => 'clock', 'icon' => '⏱', 'label' => 'Clock fixer',
            'title' => 'His last ' . self::CLOCK_RUN . ' suggested clock-out times kept as he called them',
            'have' => min(self::CLOCK_RUN, $run($of(['clock_out']), fn($d) => $d['status'] === 'accepted')), 'need' => self::CLOCK_RUN];

        $weather = array_values(array_filter($of(['weather']), fn($d) => ($d['outcome']['followed'] ?? null) !== null));
        $badges[] = ['key' => 'weather', 'icon' => '🌦', 'label' => 'Weather wise',
            'title' => 'His last ' . self::WEATHER_RUN . ' calls on rain — move or keep — the ones you made too',
            'have' => min(self::WEATHER_RUN, $run($weather, fn($d) => !empty($d['outcome']['followed']))), 'need' => self::WEATHER_RUN];

        $real = count(array_filter($decisions, fn($d) => $d['kind'] === 'silent' && ($d['outcome']['choice'] ?? '') === 'real'));
        $badges[] = ['key' => 'quiet', 'icon' => '📡', 'label' => 'Quiet catcher',
            'title' => 'Caught ' . self::QUIET_REAL . ' phones that really had stopped tracking',
            'have' => min(self::QUIET_REAL, $real), 'need' => self::QUIET_REAL];

        $closed = count($of(['clock_out', 'job_timer', 'no_time']));
        $badges[] = ['key' => 'gaps', 'icon' => '🧩', 'label' => 'Gap closer',
            'title' => self::GAPS . ' timesheet gaps closed from his suggestions',
            'have' => min(self::GAPS, $closed), 'need' => self::GAPS];

        $bylaw = count(array_filter($decisions, fn($d) => in_array($d['kind'], ['bylaw', 'west_end'], true) && $d['status'] === 'accepted'));
        $badges[] = ['key' => 'bylaw', 'icon' => '📏', 'label' => 'Bylaw keeper',
            'title' => self::BYLAW . ' visits kept inside the bylaw hours from his flags',
            'have' => min(self::BYLAW, $bylaw), 'need' => self::BYLAW];

        $judged = array_values(array_filter($decisions, fn($d) => $d['kind'] !== 'silent'
            && !($d['kind'] === 'weather' && ($d['outcome']['followed'] ?? null) === null)));
        $badges[] = ['key' => 'streak', 'icon' => '🔥', 'label' => self::STREAK . ' in a row',
            'title' => self::STREAK . ' suggestions in a row kept without a change',
            'have' => min(self::STREAK, $run($judged, fn($d) => $d['status'] === 'accepted'
                && ($d['kind'] !== 'weather' || !empty($d['outcome']['followed'])))), 'need' => self::STREAK];

        $earned = array_values(array_filter($badges, fn($b) => $b['have'] >= $b['need']));
        $open = array_values(array_filter($badges, fn($b) => $b['have'] < $b['need']));
        usort($open, fn($a, $b) => ($b['have'] / $b['need']) <=> ($a['have'] / $a['need']));
        return ['earned' => $earned, 'next' => $open[0] ?? null];
    }
}
