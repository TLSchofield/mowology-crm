<?php
/**
 * SamBrainService — what Sam has learned, for his brain on the dashboard (HeadBrain).
 *
 *   lessons   — situations where Tim rewrote Sam's draft and Sam now writes it Tim's way
 *   timing    — services whose follow-up wait Sam learned from how long quotes took to accept
 *   wins      — follow-ups that ended in an accepted quote
 *   answers   — questions Tim answered (expired quotes cleared up)
 *   badges    — badges earned
 * Counted from Sam's start line (ops_settings sam_brain_baseline), so he starts at shape 1.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 3) . '/Services/HeadBrain.php';
require_once __DIR__ . '/SalesDeskService.php';
require_once __DIR__ . '/SamBadgeService.php';
require_once __DIR__ . '/SamQuestionService.php';

class SamBrainService
{
    public const LABELS = [
        'lessons' => ['situation written your way', 'situations written your way'],
        'timing'  => ['service timing learned', 'service timings learned'],
        'wins'    => ['follow-up that won', 'follow-ups that won'],
        'answers' => ['quote cleared up', 'quotes cleared up'],
        'badges'  => ['badge', 'badges'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function learned(): array
    {
        $desk = new SalesDeskService($this->db);
        $lessons = 0;
        try {
            $lessons = (int)$this->db->query("
                SELECT COUNT(DISTINCT CONCAT(template_key, ':', channel)) FROM sam_followups
                WHERE status = 'edited' AND learned_body IS NOT NULL AND learned_body <> ''
            ")->fetchColumn();
        } catch (Throwable $e) { /* not yet */ }
        $raw = [
            'lessons' => $lessons,
            'timing'  => count($desk->learnedWaits()),
            'wins'    => $desk->wonByFollowup()['n'],
            'answers' => (new SamQuestionService($this->db))->answeredCount(),
            'badges'  => count((new SamBadgeService($this->db))->badges()['earned']),
        ];
        return (new HeadBrain($this->db, 'sam'))->learned($raw, self::LABELS);
    }
}
