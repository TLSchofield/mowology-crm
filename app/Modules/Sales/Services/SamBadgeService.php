<?php
/**
 * SamBadgeService — the badges under Sam's photo, and the counts behind his brain.
 *
 * Every badge is earned from Tim's real decisions on Sam's follow-ups (sam_followups),
 * never from Sam's own claims:
 *   your words — Tim's last 5 follow-ups through Sam went out unchanged (Sam writes like him)
 *   revived    — a follow-up led to an accepted quote within the won window
 *   closer     — 5 follow-ups that led to accepted quotes
 *   on it      — 10 follow-ups sent through Sam
 * "Your words" is a streak of his most recent sends, so it's lost again when Tim has to
 * rewrite one. The closest unearned badge shows dimmed with its progress.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/SalesDeskService.php';

class SamBadgeService
{
    public const WORDS_RUN = 5;
    public const CLOSER = 5;
    public const ON_IT = 10;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Tim's sends through Sam, newest first: true = sent unchanged. */
    public function decisions(int $limit = 200): array
    {
        try {
            return array_map(fn($s) => $s === 'sent', $this->db->query("
                SELECT status FROM sam_followups WHERE status IN ('sent', 'edited')
                ORDER BY decided_at DESC, id DESC LIMIT " . max(1, min(500, $limit))
            )->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{earned: array, next: ?array} */
    public function badges(): array
    {
        $won = (new SalesDeskService($this->db))->wonByFollowup()['n'];
        return self::compute($this->decisions(), $won);
    }

    /** Share of the last 20 sends that went out unchanged (0–1), for the brain's glow. */
    public function rightFirstTime(): ?float
    {
        $d = array_slice($this->decisions(20), 0, 20);
        return $d ? round(count(array_filter($d)) / count($d), 2) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param bool[] $unchanged newest first: was each send unchanged?
     * @return array{earned: array, next: ?array}  badge: [key, icon, label, title, have, need]
     */
    public static function compute(array $unchanged, int $won): array
    {
        $run = 0;
        foreach ($unchanged as $u) { if (!$u) break; $run++; }
        $sent = count($unchanged);
        $all = [
            ['words',   '✍️', 'Your words', 'Your last ' . self::WORDS_RUN . ' follow-ups went out as I wrote them', min($run, self::WORDS_RUN), self::WORDS_RUN],
            ['revived', '🔥', 'Revived',    'A follow-up I suggested brought a quote back to life',                  min($won, 1), 1],
            ['closer',  '🏆', 'Closer',     self::CLOSER . ' follow-ups that ended in a yes',                        min($won, self::CLOSER), self::CLOSER],
            ['on_it',   '📨', 'On it',      self::ON_IT . ' follow-ups sent — no quote left hanging',                min($sent, self::ON_IT), self::ON_IT],
        ];
        $earned = [];
        $next = null;
        foreach ($all as [$key, $icon, $label, $title, $have, $need]) {
            $b = ['key' => $key, 'icon' => $icon, 'label' => $label, 'title' => $title, 'have' => $have, 'need' => $need];
            if ($have >= $need) { $earned[] = $b; continue; }
            if ($next === null || $have / $need > $next['have'] / $next['need']) $next = $b;
        }
        return ['earned' => $earned, 'next' => $next];
    }
}
