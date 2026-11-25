<?php
/**
 * KanameWorkspaceService — Kaname's rest posture on the desktop: the morning brief.
 *
 * Built from the saved copy of Charlie's brief (never a live build) plus what was handled today
 * (charlie_items resolved since midnight). brief() is pure and tested.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

class KanameWorkspaceService
{
    private const CAP = 8;
    private const HANDLED_CAP = 6;
    private const FALLBACK_URL = '/crm/dashboard_appstack.php';

    private static function name(string $head): string
    {
        if (!class_exists('TeamCardService')) require_once __DIR__ . '/TeamCardService.php';
        if ($head === 'charlie') {
            if (!class_exists('HeadIdentity')) require_once dirname(__DIR__, 3) . '/Services/HeadIdentity.php';
            return class_exists('HeadIdentity') ? HeadIdentity::chiefName() : 'Kaname';
        }
        return TeamCardService::meta($head)['name'];
    }

    private static function slug($head): string
    {
        $h = strtolower(trim((string)$head));
        return ($h === '' || $h === 'house') ? 'charlie' : $h;
    }

    /**
     * @param array $handled rows of ['head' => , 'text' => ]
     */
    public static function brief(?array $charlieBrief, ?string $builtAt, array $handled, int $now): array
    {
        $need = [];
        $also = [];
        foreach ((array)($charlieBrief['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $head = self::slug($it['head'] ?? '');
            $row = [
                'head' => $head,
                'name' => self::name($head),
                'text' => (string)($it['text'] ?? ''),
                'url'  => (string)($it['url'] ?? '') !== '' ? (string)$it['url'] : self::FALLBACK_URL,
                'key'  => (string)($it['key'] ?? ''),
            ];
            if (!empty($it['yes'])) $need[] = $row; else $also[] = $row;
        }
        $total = count($need);
        $done = [];
        foreach ($handled as $r) {
            if (!is_array($r)) continue;
            $done[] = ['name' => self::name(self::slug($r['head'] ?? '')), 'text' => (string)($r['text'] ?? '')];
            if (count($done) >= self::HANDLED_CAP) break;
        }
        $ts = $builtAt !== null && $builtAt !== '' ? strtotime($builtAt) : false;
        // An old copy (the cron stopped) says so: the date joins the time when it is not today's.
        $asOf = '';
        if ($ts) $asOf = 'as of ' . (date('Y-m-d', $ts) === date('Y-m-d', $now) ? '' : date('D j M', $ts) . ', ') . date('g:i a', $ts);
        return [
            'heading'    => 'Morning brief · ' . date('D j M', $now),
            'as_of'      => $asOf,
            'need_you'   => array_slice($need, 0, self::CAP),
            'also'       => array_slice($also, 0, self::CAP),
            'handled'    => $done,
            'count_line' => $total === 0 ? '' : ($total === 1 ? '1 needs you' : $total . ' need you'),
            'empty'      => !$need && !$also && !$done,
        ];
    }

    public static function handledToday(PDO $db, int $now): array
    {
        try {
            $s = $db->prepare('SELECT head, text, resolved_at FROM charlie_items WHERE resolved_at >= ? ORDER BY resolved_at DESC LIMIT 6');
            $s->execute([date('Y-m-d 00:00:00', $now)]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function load(PDO $db, int $now): array
    {
        require_once __DIR__ . '/HeadBriefReadyService.php';
        $c = (new HeadBriefReadyService($db))->get('charlie');
        return self::brief($c['brief'] ?? null, $c['built_at'] ?? null, self::handledToday($db, $now), $now);
    }
}
