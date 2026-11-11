<?php
/**
 * HeadNavService — per-head state for the desktop sidebar, read from the ready copies.
 *
 * Never calls a head's brief() (~7 s): Charlie's saved brief carries HeadStateService's rows and the
 * saved "today" payload carries each head's headline. Two SELECTs, no building. Unknown stays blank.
 *
 * Global-namespace service: require_once the file. shape() is pure and tested.
 */
declare(strict_types=1);

class HeadNavService
{
    public const HEADS = ['charlie', 'yui', 'sam', 'penny', 'otto', 'mia', 'ada'];
    /** Heads that have a workspace page (the rest open their card). */
    public const WORKSPACE = ['charlie' => '/crm/kaname_appstack.php'];

    private const WORDS = [
        'working' => 'Working', 'approval' => 'Needs approval', 'blocked' => 'Blocked',
        'idle' => 'Idle', 'unavailable' => 'Not set up',
    ];

    /**
     * @return array<string, array{state:string, word:string, needs_you:int, open:int, line:string, reason:string, workspace:?string}>
     */
    public static function shape(?array $charlieBrief, ?array $today): array
    {
        $states = is_array($charlieBrief['heads'] ?? null) ? $charlieBrief['heads'] : null;
        $lines = [];
        foreach ((array)($today['heads'] ?? []) as $h) {
            if (is_array($h) && isset($h['head'])) $lines[(string)$h['head']] = trim((string)($h['headline'] ?? ''));
        }
        $out = [];
        foreach (self::HEADS as $slug) {
            $row = $states !== null && is_array($states[$slug] ?? null) ? $states[$slug] : null;
            $state = $row !== null && isset(self::WORDS[(string)($row['state'] ?? '')]) ? (string)$row['state'] : '';
            $needs = $row !== null ? (int)($row['needs_you'] ?? 0) : 0;
            $word = $state === '' ? '' : self::WORDS[$state];
            if ($state !== '' && $slug === 'charlie' && $needs > 0) {
                $word = $needs === 1 ? '1 needs you' : $needs . ' need you';
            }
            if ($slug === 'charlie') {
                // 'say' is the dashboard's opening (lead / thing / after): the thing is the one line that matters.
                $say = $today['say'] ?? null;
                if (is_array($say)) $say = $say['thing'] ?? '';
                $line = is_string($say) && trim($say) !== '' ? trim($say) : trim((string)($today['status']['sentence'] ?? ''));
            } else {
                $line = $lines[$slug] ?? '';
            }
            $out[$slug] = [
                'state'     => $state,
                'word'      => $word,
                'needs_you' => $needs,
                'open'      => $row !== null ? (int)($row['open'] ?? 0) : 0,
                'line'      => $line,
                'reason'    => $row !== null ? trim((string)($row['reason'] ?? '')) : '',
                'workspace' => self::WORKSPACE[$slug] ?? null,
            ];
        }
        return $out;
    }

    /**
     * The brief is the owner's (the same gate as the dashboard's Action Board): anyone else gets the
     * menu with no state words or lines. A copy older than HeadBriefReadyService::MAX_AGE_SECONDS
     * (the cron has stopped) is not shown either — a day-old "Needs approval" is a guess.
     * @return array{heads: array, built_at: ?string}
     */
    public static function forShell(PDO $db, bool $owner = true): array
    {
        if (!$owner) return ['heads' => self::shape(null, null), 'built_at' => null];
        try {
            require_once __DIR__ . '/HeadBriefReadyService.php';
            $svc = new HeadBriefReadyService($db);
            $c = $svc->get('charlie');
            if ($c !== null && HeadBriefReadyService::state($c['built_at'], time()) === 'stale') $c = null;
            return ['heads' => self::shape($c['brief'] ?? null, $svc->today()), 'built_at' => $c['built_at'] ?? null];
        } catch (Throwable $e) {
            error_log('HeadNavService: ' . get_class($e));
            return ['heads' => self::shape(null, null), 'built_at' => null];
        }
    }
}
