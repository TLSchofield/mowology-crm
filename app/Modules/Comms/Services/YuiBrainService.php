<?php
/**
 * YuiBrainService — what Yui has learned, for her brain on the dashboard (HeadBrain).
 *
 *   unchanged — drafts Tim sent as written (she has his voice for those)
 *   edited    — drafts Tim rewrote before sending (each one a lesson)
 *   messages  — one triangle per template Tim rewrote (renewal, check-in, arrears…), coloured
 *               by strength: sent as written since, a rewrite drops a tier (HeadBrain::templateItems;
 *               replaces the old "lessons" count)
 *   handled   — items Tim cleared (what doesn't need a message)
 *   badges    — badges earned
 * Counted from Yui's start line (ops_settings yui_brain_baseline), so she starts at shape 1.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 3) . '/Services/HeadBrain.php';
require_once __DIR__ . '/YuiDraftService.php';
require_once __DIR__ . '/YuiBadgeService.php';

class YuiBrainService
{
    public const LABELS = [
        'unchanged' => ['draft sent as written', 'drafts sent as written'],
        'edited'    => ['draft you rewrote', 'drafts you rewrote'],
        'handled'   => ['item you cleared', 'items you cleared'],
        'badges'    => ['badge', 'badges'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function learned(): array
    {
        $c = (new YuiDraftService($this->db))->counts();
        $raw = [
            'unchanged' => $c['unchanged'],
            'edited'    => $c['edited'],
            'handled'   => $c['handled'],
            'badges'    => count((new YuiBadgeService($this->db))->badges()['earned']),
        ];
        $hb = new HeadBrain($this->db, 'yui');
        return HeadBrain::withItems($hb->learned($raw, self::LABELS), $hb->templateItems('yui_actions', 'Messages'));
    }
}
