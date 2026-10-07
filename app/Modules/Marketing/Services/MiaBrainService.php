<?php
/**
 * MiaBrainService — what Mia has learned from Tim, counted for her 3D brain on the deck
 * (shared HeadBrain + public/crm/js/head-brain.js; start line in ops_settings mia_brain_baseline).
 *
 * Every unit is something real:
 *   templates — one triangle per kind of message where Tim's own wording is now her
 *               template (ops_settings mia_template_<kind>), coloured by strength = the
 *               results that kind has brought in (booked, quote or reply). Replaces the
 *               old "wording" count.
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
            'people'  => $count("SELECT COUNT(*) FROM mia_mutes"),
            'results' => $count("SELECT COUNT(*) FROM mia_suggestions WHERE outcome IN ('booked', 'quote', 'reply')"),
            'answers' => $count("SELECT COUNT(*) FROM mia_questions WHERE status = 'answered'"),
            'badges'  => count((new MiaBadgeService($this->db))->badges()['earned']),
        ];
        $brain = HeadBrain::withItems((new HeadBrain($this->db, 'mia'))->learned($raw, self::LABELS), $this->templateItems());
        $recent = [];
        try {
            $recent = $this->db->query("SELECT status FROM mia_suggestions WHERE status IN ('sent', 'skipped') ORDER BY decided_at DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {}
        return $brain + ['bright' => self::bright($recent)];
    }

    /** One item per learned template kind; strength = results that kind brought in. */
    private function templateItems(): array
    {
        try {
            $kinds = $this->db->query("SELECT SUBSTRING(setting_key, 14), updated_at FROM ops_settings WHERE setting_key LIKE 'mia\\_template\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            try {
                $kinds = array_fill_keys($this->db->query("SELECT SUBSTRING(setting_key, 14) FROM ops_settings WHERE setting_key LIKE 'mia\\_template\\_%'")->fetchAll(PDO::FETCH_COLUMN), null);
            } catch (Throwable $e2) {
                return [];
            }
        }
        $results = [];
        try {
            $results = $this->db->query("SELECT kind, COUNT(*) FROM mia_suggestions WHERE outcome IN ('booked', 'quote', 'reply') GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {}
        return self::templateItemsFrom($kinds, array_map('intval', $results));
    }

    public const KINDS = ['reconnect' => 'Reconnect', 'seasonal' => 'Seasonal', 'pm_quiet' => 'Quiet property manager', 'referral' => 'Referral ask'];

    /** Pure: [kind => learned_at] + [kind => results] → items. */
    public static function templateItemsFrom(array $kinds, array $results): array
    {
        $out = [];
        foreach ($kinds as $kind => $at) {
            $kind = (string)$kind;
            if ($kind === '') continue;
            $n = (int)($results[$kind] ?? 0);
            $out[] = HeadBrain::item('template:' . $kind, (self::KINDS[$kind] ?? ucfirst(str_replace('_', ' ', $kind))) . ' message', max(1, $n),
                ['group' => 'Her templates', 'note' => $n . ' result' . ($n === 1 ? '' : 's'), 'at' => $at]);
        }
        return $out;
    }

    /** Share of recent suggestions Tim sent; 0.5 until there are any. */
    public static function bright(array $statuses): float
    {
        if (!$statuses) return 0.5;
        return round(count(array_filter($statuses, fn($s) => $s === 'sent')) / count($statuses), 2);
    }
}
