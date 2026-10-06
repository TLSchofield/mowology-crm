<?php
/**
 * MiaBadgeService — the badges under Mia's photo on the dashboard.
 *
 * Earned only from what really happened after Tim sent her suggestions (mia_suggestions),
 * never from her own claims:
 *   rebooker      — 5 messages that led to booked work or a quote within 30 days
 *   pm whisperer  — 3 quiet property managers who came back with work or a quote
 *   word of mouth — a referral that came in from someone she suggested asking
 *   in tune       — her last 10 messages sent without Tim changing a word
 * The closest unearned one is shown dimmed with its progress.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MiaBadgeService
{
    public const REBOOK = 5;
    public const PM_BACK = 3;
    public const REFERRALS = 1;
    public const IN_TUNE = 10;

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
                SELECT kind, status, edited, outcome FROM mia_suggestions
                WHERE status = 'sent' ORDER BY decided_at DESC, id DESC LIMIT 500
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['earned' => [], 'next' => null];
        }
        return self::compute($rows, $this->referralsWon());
    }

    /** Referrals that came in from people Mia suggested asking, after the ask. */
    private function referralsWon(): int
    {
        try {
            if ($this->db->query("SHOW TABLES LIKE 'referrals'")->fetchColumn() === false) return 0;
            return (int)$this->db->query("
                SELECT COUNT(DISTINCT r.id) FROM referrals r
                JOIN mia_suggestions s ON s.contact_id = r.referrer_contact_id AND s.kind = 'referral' AND s.status = 'sent'
                WHERE r.created_at >= s.decided_at AND r.status <> 'cancelled'
            ")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $sent newest first: [kind, status, edited, outcome]
     * @return array{earned: array, next: ?array}  badge: [key, icon, label, title, have, need]
     */
    public static function compute(array $sent, int $referralsWon = 0): array
    {
        $won = fn($s) => in_array($s['outcome'] ?? null, ['booked', 'quote'], true);
        $rebooked = count(array_filter($sent, $won));
        $pmBack = count(array_filter($sent, fn($s) => $s['kind'] === 'pm_quiet' && $won($s)));
        $streak = 0;
        foreach ($sent as $s) {
            if (!empty($s['edited'])) break;
            $streak++;
        }
        $all = [
            ['rebooker', '📅', 'Rebooker', 'Messages that led to booked work or a quote within 30 days', $rebooked, self::REBOOK],
            ['pm', '🏢', 'PM whisperer', 'Quiet property managers who came back with work', $pmBack, self::PM_BACK],
            ['referral', '🤝', 'Word of mouth', 'A referral from someone I suggested asking', $referralsWon, self::REFERRALS],
            ['tune', '🎯', 'In tune', 'My last ' . self::IN_TUNE . ' messages sent without a change', $streak, self::IN_TUNE],
        ];
        $earned = [];
        $next = null;
        foreach ($all as [$key, $icon, $label, $title, $have, $need]) {
            $b = ['key' => $key, 'icon' => $icon, 'label' => $label, 'title' => $title, 'have' => min($have, $need), 'need' => $need];
            if ($have >= $need) {
                $earned[] = $b;
            } elseif ($next === null || $have / $need > $next['have'] / $next['need']) {
                $next = $b;
            }
        }
        return ['earned' => $earned, 'next' => $next];
    }
}
