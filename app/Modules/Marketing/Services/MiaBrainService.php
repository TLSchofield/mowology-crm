<?php
/**
 * MiaBrainService — what Mia has learned from Tim, counted for her 3D brain on the deck
 * (shared HeadBrain + public/crm/js/head-brain.js; start line in ops_settings mia_brain_baseline).
 *
 * Every unit is something real:
 *   wording   — kinds of message where Tim's own edited wording is now her template
 *   people    — who to leave alone and until when (mutes and snoozes from his skips)
 *   results   — messages whose result she now knows (booked, quote or reply within 30 days)
 *   answers   — questions Tim answered
 *   badges    — earned, never claimed (MiaBadgeService)
 * Brightness: how often Tim sent what she suggested rather than skipping it (last 20).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 3) . '/Services/HeadBrain.php';
require_once __DIR__ . '/MiaBadgeService.php';

class MiaBrainService
{
    public const LABELS = [
        'wording' => ['message in your own words', 'messages in your own words'],
        'people'  => ['person to leave alone', 'people to leave alone'],
        'results' => ['result followed up', 'results followed up'],
        'answers' => ['answer from you', 'answers from you'],
        'badges'  => ['badge', 'badges'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{units: int, parts: array, since: ?string, bright: float} */
    public function learned(): array
    {
        $count = function (string $sql): int {
            try { return (int)$this->db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $raw = [
            'wording' => $count("SELECT COUNT(*) FROM ops_settings WHERE setting_key LIKE 'mia\\_template\\_%'"),
            'people'  => $count("SELECT COUNT(*) FROM mia_mutes"),
            'results' => $count("SELECT COUNT(*) FROM mia_suggestions WHERE outcome IN ('booked', 'quote', 'reply')"),
            'answers' => $count("SELECT COUNT(*) FROM mia_questions WHERE status = 'answered'"),
            'badges'  => count((new MiaBadgeService($this->db))->badges()['earned']),
        ];
        $brain = (new HeadBrain($this->db, 'mia'))->learned($raw, self::LABELS);
        $recent = [];
        try {
            $recent = $this->db->query("SELECT status FROM mia_suggestions WHERE status IN ('sent', 'skipped') ORDER BY decided_at DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {}
        return $brain + ['bright' => self::bright($recent)];
    }

    /** Share of recent suggestions Tim sent; 0.5 until there are any. */
    public static function bright(array $statuses): float
    {
        if (!$statuses) return 0.5;
        return round(count(array_filter($statuses, fn($s) => $s === 'sent')) / count($statuses), 2);
    }
}
