<?php
/**
 * SamBrainService — what Sam has learned, for his brain on the dashboard (HeadBrain).
 *
 *   follow-ups — one triangle per situation Tim rewrote (Sam now writes it Tim's way),
 *               coloured by strength: sends unchanged since, a rewrite drops a tier
 *               (HeadBrain::templateItems; replaces the old "lessons" count)
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
        'timing'  => ['service timing learned', 'service timings learned'],
        'wins'    => ['follow-up that won', 'follow-ups that won'],
        'answers' => ['quote cleared up', 'quotes cleared up'],
        'badges'  => ['badge', 'badges'],
    ];

    /** Sam's situations (sam_followups.template_key) in plain words. */
    public const SITUATIONS = [
        'first_nudge'  => 'First nudge',
        'second_nudge' => 'Second nudge',
        'last_call'    => 'Last call',
        'multi'        => 'Several quotes waiting',
        'viewed'       => 'Viewed, no reply',
        'reply'        => 'Reply to a customer',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function learned(): array
    {
        $desk = new SalesDeskService($this->db);
        $raw = [
            'timing'  => count($desk->learnedWaits()),
            'wins'    => $desk->wonByFollowup()['n'],
            'answers' => (new SamQuestionService($this->db))->answeredCount(),
            'badges'  => count((new SamBadgeService($this->db))->badges()['earned']),
        ];
        $hb = new HeadBrain($this->db, 'sam');
        return HeadBrain::withItems($hb->learned($raw, self::LABELS), $hb->templateItems('sam_followups', 'Follow-ups', self::SITUATIONS));
    }
}
