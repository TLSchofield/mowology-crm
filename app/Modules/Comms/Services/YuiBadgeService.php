<?php
/**
 * YuiBadgeService — the badges under Yui's photo.
 *
 * Earned only from Tim's real decisions on Yui's card (yui_actions), never from her own claims:
 *   your words — Tim's last 5 messages through Yui went out as she drafted them
 *   on it      — 10 client messages sent through Yui
 *   tidy       — 10 items Tim marked handled (inbox, promises, accounts, renewals)
 * "Your words" is a streak of the most recent sends, so it's lost again when Tim has to
 * rewrite one. The closest unearned badge shows dimmed with its progress.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/YuiDraftService.php';

class YuiBadgeService
{
    public const WORDS_RUN = 5;
    public const ON_IT = 10;
    public const TIDY = 10;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{earned: array, next: ?array} */
    public function badges(): array
    {
        $d = new YuiDraftService($this->db);
        return self::compute($d->decisions(), $d->counts()['handled']);
    }

    /** Share of the last 20 sends that went out unchanged (0–1), for the brain's glow. */
    public function rightFirstTime(): ?float
    {
        $d = array_slice((new YuiDraftService($this->db))->decisions(20), 0, 20);
        return $d ? round(count(array_filter($d)) / count($d), 2) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param bool[] $unchanged newest first: was each send unchanged?
     * @return array{earned: array, next: ?array}  badge: [key, icon, label, title, have, need]
     */
    public static function compute(array $unchanged, int $handled): array
    {
        $run = 0;
        foreach ($unchanged as $u) { if (!$u) break; $run++; }
        $sent = count($unchanged);
        $all = [
            ['words', '✍️', 'Your words', 'Your last ' . self::WORDS_RUN . ' client messages went out as I drafted them', min($run, self::WORDS_RUN), self::WORDS_RUN],
            ['on_it', '💬', 'On it',      self::ON_IT . ' client messages sent — nobody left waiting',              min($sent, self::ON_IT), self::ON_IT],
            ['tidy',  '🗂️', 'Tidy',       self::TIDY . ' client items cleared',                                     min($handled, self::TIDY), self::TIDY],
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
