<?php
/**
 * TeamCardService — one department head's card for the iOS Team tab (Charlie, Otto, Mia,
 * Yui): who they are, their brain, and their top 3 items ranked exactly as the dashboard's
 * Action Board ranks them (public/crm/js/action-board.js).
 *
 * The items come from Charlie's ranked view (CharlieDeskService::today(), the same call the
 * web's /crm/api/charlie.php?mode=today makes for the Action Board). column() is the board's
 * take() + render() for one column: Charlie's one + rest + every head's top items, de-duplicated,
 * sorted by score, grouped by head (the Work Queue's 'house' items sit in Charlie's column),
 * first PER_COL of the head's column.
 *
 * Nothing here reads the web session: the caller passes the owner's first name, and the
 * owner check (CharlieDeskService::isOwner) is done by the caller with the JWT user.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class TeamCardService
{
    /** As action-board.js PER_COL. */
    public const PER_COL = 3;

    /** The heads with a generic phone card. Face = /crm/img/heads/<slug>.jpg (as the board). */
    public const HEADS = [
        'charlie' => ['name' => 'Charlie', 'role' => 'Chief of Staff'],
        'otto'    => ['name' => 'Otto',    'role' => 'Operations'],
        'mia'     => ['name' => 'Mia',     'role' => 'Marketing'],
        'yui'     => ['name' => 'Yui',     'role' => 'Communications'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isHead(string $head): bool
    {
        return isset(self::HEADS[$head]);
    }

    public static function faceUrl(string $head): string
    {
        return '/crm/img/heads/' . preg_replace('/[^a-z]/', '', $head) . '.jpg';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Items — pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** As action-board.js headKey(): unknown heads and the Work Queue ('house') are Charlie's. */
    public static function headKey(?string $slug): string
    {
        $k = strtolower((string)$slug);
        return in_array($k, ['penny', 'sam', 'otto', 'mia', 'yui', 'charlie'], true) ? $k : 'charlie';
    }

    /**
     * One head's column of the Action Board from Charlie's today() view.
     * @param array $view {one: ?item, rest: item[], heads: head => {items: item[], waiting: int}}
     * @return array{items: array, waiting: int, headline: string}
     */
    public static function column(array $view, string $head, int $n = self::PER_COL): array
    {
        $all = array_merge([$view['one'] ?? null], (array)($view['rest'] ?? []));
        foreach ((array)($view['heads'] ?? []) as $h) {
            $all = array_merge($all, (array)($h['items'] ?? []));
        }
        $seen = [];
        $items = [];
        foreach ($all as $i => $it) {
            if (!is_array($it) || empty($it['key']) || isset($seen[$it['key']])) continue;
            $seen[$it['key']] = true;
            $items[] = $it + ['_i' => $i];
        }
        // Score descending; ties keep the board's order (JS sort is stable).
        usort($items, fn($a, $b) => [(float)($b['score'] ?? 0), $a['_i']] <=> [(float)($a['score'] ?? 0), $b['_i']]);
        $mine = array_values(array_filter($items, fn($it) => self::headKey($it['head'] ?? null) === $head));

        $waiting = 0;
        $headline = '';
        foreach ((array)($view['heads'] ?? []) as $slug => $h) {
            $slug = (string)($h['head'] ?? $slug);
            if (self::headKey($slug) !== $head) continue;
            $waiting += (int)($h['waiting'] ?? 0);
            if ($headline === '' && $slug === $head) $headline = trim((string)($h['headline'] ?? ''));
        }
        return [
            'items'    => array_map([self::class, 'slim'], array_slice($mine, 0, max(0, $n))),
            'waiting'  => max($waiting, count($mine)),
            'headline' => $headline,
        ];
    }

    /** The fields the phone needs (as charlie.php's $slim, plus yes). */
    public static function slim(array $it): array
    {
        return [
            'key'        => (string)$it['key'],
            'head'       => (string)($it['head'] ?? ''),
            'kind'       => $it['kind'] ?? null,
            'text'       => (string)($it['text'] ?? ''),
            'url'        => self::safeUrl($it['url'] ?? null),
            'priority'   => (int)($it['priority'] ?? 2),
            'value'      => isset($it['value']) && is_numeric($it['value']) ? (float)$it['value'] : null,
            'since'      => $it['since'] ?? null,
            'first_seen' => $it['first_seen'] ?? null,
            'score'      => isset($it['score']) ? (float)$it['score'] : null,
            'yes'        => !empty($it['yes']),
        ];
    }

    /** As action-board.js safeUrl(): a site path, a #hash or https only. */
    public static function safeUrl($u): ?string
    {
        return is_string($u) && preg_match('#^(/(?!/)|\#|https://)#', $u) ? $u : null;
    }

    /** Mia's Google post draft id from her brief item key ("mia:gbp-post:12" → 12). */
    public static function gbpPostId(string $key): ?int
    {
        return preg_match('/^mia:gbp-post:(\d+)$/', $key, $m) ? (int)$m[1] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Brain
    // ─────────────────────────────────────────────────────────────────────────

    /** The head's brain (the same service and call the dashboard card makes), or null. */
    public function brain(string $head): ?array
    {
        try {
            require_once dirname(__DIR__, 3) . '/Services/HeadBrain.php';
            switch ($head) {
                case 'charlie':
                    require_once __DIR__ . '/CharlieDeskService.php';
                    require_once __DIR__ . '/CharlieBadgeService.php';
                    $badges = (new CharlieBadgeService($this->db))->badges();
                    // As charlie-card.php: Charlie + each department desk deployed here.
                    $heads = 1 + count(array_filter(['Sales/Services/SalesDeskService.php', 'Operations/Services/OpsDeskService.php', 'Marketing/Services/MiaDeskService.php'],
                        static fn($f) => is_file(dirname(__DIR__, 2) . '/' . $f)));
                    $desk = new CharlieDeskService($this->db);
                    $b = (new HeadBrain($this->db, 'charlie'))->learned($desk->brainCounts(count($badges['earned']), $heads), CharlieDeskService::BRAIN_LABELS);
                    break;
                case 'otto':
                    require_once dirname(__DIR__, 2) . '/Operations/Services/OttoBrainService.php';
                    $b = (new OttoBrainService($this->db))->learned(null);   // as the property card
                    break;
                case 'mia':
                    require_once dirname(__DIR__, 2) . '/Marketing/Services/MiaBrainService.php';
                    $b = (new MiaBrainService($this->db))->learned();
                    break;
                case 'yui':
                    require_once dirname(__DIR__, 2) . '/Comms/Services/YuiBrainService.php';
                    $b = (new YuiBrainService($this->db))->learned();
                    break;
                default:
                    return null;
            }
            return self::brainSummary($b);
        } catch (Throwable $e) {
            error_log('Team card brain (' . $head . '): ' . $e->getMessage());
            return null;   // the brain is a bonus — never block the card
        }
    }

    /**
     * Pure: a brain (HeadBrain learned()/withItems() result) as the phone shows it — how many
     * things learned, the shape number, the start line, the strongest few things and how many
     * learned things sit on each strength tier (HeadBrain::TIERS).
     * @return array{units: int, shape: int, since: ?string, parts: array, tiers: array}
     */
    public static function brainSummary(array $b): array
    {
        $units = (int)($b['units'] ?? 0);
        $parts = [];
        $tiers = [];
        foreach ((array)($b['parts'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $hasStrength = array_key_exists('strength', $p);
            $tier = $hasStrength ? HeadBrain::tier($p['strength'] === null ? null : (int)$p['strength']) : null;
            if ($tier) $tiers[$tier['slug']] = ($tiers[$tier['slug']] ?? 0) + 1;
            if (count($parts) < 4) {
                $parts[] = ['label' => (string)($p['label'] ?? ''), 'tier' => $tier ? $tier['name'] : null];
            }
        }
        $tierList = [];
        foreach (HeadBrain::TIERS as [$slug, $name]) {
            if (!empty($tiers[$slug])) $tierList[] = ['slug' => $slug, 'name' => $name, 'n' => $tiers[$slug]];
        }
        return [
            'units' => $units,
            'shape' => HeadBrain::shapeNumber($units),
            'since' => $b['since'] ?? null,
            'parts' => $parts,
            'tiers' => array_reverse($tierList),   // strongest first
        ];
    }
}
