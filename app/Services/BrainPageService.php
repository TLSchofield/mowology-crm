<?php
/**
 * BrainPageService — a department head's brain as a full page (/crm/brain.php?head=…),
 * replacing the cramped pop-up (Tim, 2026-10-07: "this should be a full page with cleaner
 * presentation. clients would be intrigued").
 *
 * load()  reads the head's brain with the SAME service and call its dashboard card makes
 *         (PennyBrainService, SamBrainService, OttoBrainService, MiaBrainService,
 *         YuiBrainService, HeadBrain 'charlie'), plus how bright it glows.
 * view()  (pure, unit tested) turns it into what the page shows: headline stats, the tier
 *         bar, one plain sentence, and what the head knows grouped by skill and tier.
 *
 * Client view (view=client): the page is shown to clients on Tim's laptop/phone. It is
 * redacted HERE, server side, not by CSS: no item names, no raw bank wording, no notes,
 * no keys (the 3D brain gets opaque keys so it still lights one triangle per thing), only
 * counts per tier and per category, and a line about what the head does for the client.
 * Nothing in the brains carries an amount; the item list is the only place names live.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/HeadBrain.php';

class BrainPageService
{
    /** slug => name, role, pronoun, what the head does for a client (client view). */
    public const HEADS = [
        'penny'   => ['name' => 'Penny',   'role' => 'Bookkeeper',                'they' => 'she',
                      'client' => 'Penny makes sure every receipt and payment is filed to the right place, first time.'],
        'sam'     => ['name' => 'Sam',     'role' => 'Sales',                     'they' => 'he',
                      'client' => 'Sam makes sure every quote gets a timely, personal follow-up, so nothing slips through.'],
        'otto'    => ['name' => 'Otto',    'role' => 'Operations',                'they' => 'he',
                      'client' => 'Otto plans each crew day around the weather, the route and what every property needs.'],
        'mia'     => ['name' => 'Mia',     'role' => 'Marketing & relationships', 'they' => 'she',
                      'client' => 'Mia keeps in touch with clients and property managers, so nobody is forgotten between seasons.'],
        'yui'     => ['name' => 'Yui',     'role' => 'Client communications',     'they' => 'she',
                      'client' => 'Yui makes sure every client message gets a prompt, personal reply.'],
        'charlie' => ['name' => 'Charlie', 'role' => 'Chief of Staff',            'they' => 'he',
                      'client' => 'Charlie keeps the whole team pointed at what matters most, every morning.'],
    ];

    /** A head's skill (item group) as a client reads it: [one, many]. Unknown groups read as their own name. */
    public const CLIENT_GROUPS = [
        'Bank payees'     => ['supplier or payee recognised', 'suppliers and payees recognised'],
        'Receipt vendors' => ['supplier recognised from receipts', 'suppliers recognised from receipts'],
        'Follow-ups'      => ['follow-up situation learned', 'follow-up situations learned'],
        'Messages'        => ['message type learned', 'message types learned'],
        'Her templates'   => ['message template learned', 'message templates learned'],
        'Rain calls'      => ['weather decision learned', 'weather decisions learned'],
    ];

    /** Strength at which the bar is full (Platinum). */
    private const FULL = 50;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isHead(string $head): bool
    {
        return isset(self::HEADS[$head]);
    }

    /**
     * The head's brain and brightness, as its dashboard card reads them. Never throws:
     * a head whose service isn't deployed / migrated comes back empty.
     * @return array{brain: array, bright: float}
     */
    public function load(string $head): array
    {
        $app = dirname(__DIR__);
        $bright = 0.5;
        try {
            switch ($head) {
                case 'penny':
                    require_once $app . '/Modules/Expenses/Services/PennyBrainService.php';
                    $brain = (new PennyBrainService($this->db))->learned();
                    try {
                        require_once $app . '/Modules/Expenses/ExpenseConstants.php';
                        require_once $app . '/Modules/Expenses/Services/BookkeeperDeskService.php';
                        $rft = (new BookkeeperDeskService($this->db))->stats(null)['right_first_time'] ?? null;
                        if ($rft !== null) $bright = (float)$rft / 100;
                    } catch (Throwable $e) { /* brightness is a bonus */ }
                    break;
                case 'sam':
                    require_once $app . '/Modules/Sales/Services/SamBrainService.php';
                    $brain = (new SamBrainService($this->db))->learned();
                    $bright = (new SamBadgeService($this->db))->rightFirstTime() ?? 0.5;
                    break;
                case 'otto':
                    require_once $app . '/Modules/Operations/Services/OttoBrainService.php';
                    $brain = (new OttoBrainService($this->db))->learned(null);   // as the property card / Team tab
                    $bright = (float)($brain['bright'] ?? 0.5);
                    break;
                case 'mia':
                    require_once $app . '/Modules/Marketing/Services/MiaBrainService.php';
                    $brain = (new MiaBrainService($this->db))->learned();
                    $bright = (float)($brain['bright'] ?? 0.5);
                    break;
                case 'yui':
                    require_once $app . '/Modules/Comms/Services/YuiBrainService.php';
                    $brain = (new YuiBrainService($this->db))->learned();
                    $bright = (new YuiBadgeService($this->db))->rightFirstTime() ?? 0.5;
                    break;
                case 'charlie':
                    require_once $app . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
                    require_once $app . '/Modules/ChiefOfStaff/Services/CharlieBadgeService.php';
                    $badges = (new CharlieBadgeService($this->db))->badges();
                    // As charlie-card.php: Charlie + each department desk deployed here.
                    $heads = 1 + count(array_filter(['Sales/Services/SalesDeskService.php', 'Operations/Services/OpsDeskService.php', 'Marketing/Services/MiaDeskService.php'],
                        static fn($f) => is_file($app . '/Modules/' . $f)));
                    $brain = (new HeadBrain($this->db, 'charlie'))->learned(
                        (new CharlieDeskService($this->db))->brainCounts(count($badges['earned']), $heads), CharlieDeskService::BRAIN_LABELS);
                    $bright = (float)($badges['called_rate'] ?? 0.5);
                    break;
                default:
                    $brain = [];
            }
        } catch (Throwable $e) {
            error_log('Brain page (' . $head . '): ' . $e->getMessage());
            $brain = [];
        }
        return ['brain' => $brain + ['units' => 0, 'parts' => [], 'since' => null], 'bright' => max(0.0, min(1.0, (float)$bright))];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** As head-brain.js isItem(): a learned item has a key like "payee:telus mobility". */
    public static function isItem($p): bool
    {
        return is_array($p) && is_string($p['key'] ?? null) && strpos($p['key'], ':') > 0;
    }

    /**
     * What the page shows. $client = the redacted client view (see the class comment).
     * @return array
     */
    public static function view(string $head, array $brain, float $bright, bool $client): array
    {
        $cfg = self::HEADS[$head] ?? ['name' => ucfirst($head), 'role' => '', 'they' => 'they', 'client' => ''];
        $parts = array_values(array_filter((array)($brain['parts'] ?? []), 'is_array'));
        $items = array_values(array_filter($parts, [self::class, 'isItem']));
        $counted = array_values(array_filter($parts, fn($p) => !self::isItem($p)));
        $units = max((int)($brain['units'] ?? 0), count($items));
        $tiers = HeadBrain::tierCounts($items);
        $top = null;
        foreach ($tiers as $t) if ($t['n'] > 0) { $top = $t; break; }

        // Items by skill (group, in the order the head reports them), then tier, strongest first.
        $groups = [];
        foreach ($items as $p) {
            $g = (string)($p['group'] ?? 'Learned');
            $groups[$g][] = $p;
        }
        $groupViews = [];
        foreach ($groups as $g => $list) {
            $byTier = [];
            foreach ($list as $p) {
                $rank = HeadBrain::tier(array_key_exists('strength', $p) && $p['strength'] !== null ? (int)$p['strength'] : null)['rank'];
                $byTier[$rank][] = $p;
            }
            $tierViews = [];
            for ($r = count(HeadBrain::TIERS) - 1; $r >= 0; $r--) {
                if (empty($byTier[$r])) continue;
                [$slug, $name] = HeadBrain::TIERS[$r];
                $rows = $byTier[$r];
                usort($rows, fn($a, $b) => [(int)($b['strength'] ?? 0), (string)($a['label'] ?? '')] <=> [(int)($a['strength'] ?? 0), (string)($b['label'] ?? '')]);
                $tierViews[] = ['slug' => $slug, 'name' => $name, 'n' => count($rows),
                                'items' => $client ? [] : array_map([self::class, 'row'], $rows)];
            }
            $groupViews[] = [
                'id'     => 'g-' . substr(md5($g), 0, 8),
                'name'   => $client ? self::clientGroup($g, count($list)) : $g,
                'n'      => count($list),
                'tiers'  => $tierViews,
                'client' => self::clientGroup($g, count($list)),
            ];
        }

        $learning = array_values(array_filter((array)($brain['learning'] ?? []), 'is_array'));
        $k = HeadBrain::shapeNumber($units);
        return [
            'head'     => $head,
            'name'     => $cfg['name'],
            'role'     => $cfg['role'],
            'client'   => $client,
            'units'    => $units,
            'shape'    => $k,
            'shapes'   => HeadBrain::SHAPES,
            'since'    => $brain['since'] ?? null,
            'bright'   => round(max(0.0, min(1.0, $bright)), 2),
            'tiers'    => $tiers,
            'top'      => $top,
            'items_n'  => count($items),
            'groups'   => $groupViews,
            'counted'  => array_map(fn($p) => ['label' => (string)($p['label'] ?? ''), 'n' => (int)($p['n'] ?? 0)], $counted),
            'learning' => $client ? [] : array_map([self::class, 'learningRow'], $learning),
            'learning_n' => count($learning),
            'line'     => $client ? self::clientLine($cfg, $groupViews, $top) : self::ownerLine($cfg, $groupViews, $counted, $top, $units),
            'about'    => $client ? $cfg['client'] : '',
            'parts'    => self::brainParts($items, $client),
        ];
    }

    /** One item row (owner view only). */
    public static function row(array $p): array
    {
        $s = array_key_exists('strength', $p) && $p['strength'] !== null ? (int)$p['strength'] : null;
        $note = (string)($p['note'] ?? '');
        if ($note === '') {
            $note = $s === null ? 'strength not tracked' : ((int)($p['streak'] ?? $s)) . ' in a row';
        }
        return [
            'label'     => (string)($p['label'] ?? ''),
            'raw'       => (string)($p['raw'] ?? ''),
            'strength'  => $s,
            'note'      => $note,
            'corrected' => !empty($p['corrected_recently']),
            'bar'       => self::bar($s),
        ];
    }

    /** A "still learning" payee (owner view only): known from a rule the owner hasn't confirmed twice. */
    public static function learningRow(array $p): array
    {
        $s = max(0, (int)($p['strength'] ?? 0));
        return ['label' => (string)($p['label'] ?? ''), 'raw' => (string)($p['raw'] ?? ''), 'group' => (string)($p['group'] ?? ''),
                'note' => $s . ' of 2 confirmations from you'];
    }

    /** Strength as a bar, 0–100: log scale so 1 → small, 5 → about half, 50+ → full. */
    public static function bar(?int $s): int
    {
        if ($s === null || $s < 1) return 0;
        return (int)round(min(1.0, log(1 + $s) / log(1 + self::FULL)) * 100);
    }

    /**
     * What the 3D brain needs: one entry per item (its key places the triangle, its strength
     * colours it). Client view: an opaque key (same item → same triangle on every load),
     * no label, group, note or raw wording.
     */
    public static function brainParts(array $items, bool $client): array
    {
        $out = [];
        foreach ($items as $p) {
            $q = ['key' => $client ? 'i:' . substr(sha1((string)$p['key']), 0, 12) : (string)$p['key'],
                  'strength' => $p['strength'] ?? null];
            if (!empty($p['at'])) $q['at'] = (string)$p['at'];
            if (!$client) $q['label'] = (string)($p['label'] ?? '');
            $out[] = $q;
        }
        return $out;
    }

    public static function clientGroup(string $group, int $n): string
    {
        [$one, $many] = self::CLIENT_GROUPS[$group] ?? [lcfirst($group), lcfirst($group)];
        return $n === 1 ? $one : $many;
    }

    /** "Penny knows 214 bank payees and 40 receipt vendors; 7 she's handled 50+ times in a row without a correction." */
    public static function ownerLine(array $cfg, array $groups, array $counted, ?array $top, int $units): string
    {
        $name = $cfg['name'];
        if ($units === 0) return 'Nothing learned yet: every decision you make teaches ' . self::objectPronoun($cfg['they']) . ' something.';
        $bits = array_map(fn($g) => number_format($g['n']) . ' ' . strtolower($g['name']), $groups);
        if (!$bits) {
            $bits = array_map(fn($p) => (string)($p['label'] ?? ''), array_slice($counted, 0, 2));
            return $name . ' has learned ' . self::andList($bits) . '.';
        }
        $line = $name . ' knows ' . self::andList($bits);
        if ($top && $top['slug'] === 'platinum') {
            $line .= '; ' . $top['n'] . ' ' . $cfg['they'] . "'s handled 50+ times in a row without a correction";
        } elseif ($top && $top['min'] >= 5) {
            $line .= '; the strongest ' . ($top['n'] === 1 ? 'is' : $top['n'] . ' are') . ' at ' . $top['name'] . ', ' . $top['min'] . '+ in a row without a correction';
        } else {
            $line .= '; each one gets stronger every time you confirm it unchanged';
        }
        return $line . '.';
    }

    /** "214 suppliers and payees recognised · 7 at Platinum" — counts and categories only. */
    public static function clientLine(array $cfg, array $groups, ?array $top): string
    {
        $bits = array_map(fn($g) => number_format($g['n']) . ' ' . $g['client'], $groups);
        if ($top && $top['min'] >= 5) $bits[] = number_format($top['n']) . ' at ' . $top['name'];
        return implode(' · ', $bits);
    }

    private static function andList(array $bits): string
    {
        $bits = array_values(array_filter($bits, 'strlen'));
        if (count($bits) <= 1) return $bits[0] ?? '';
        $last = array_pop($bits);
        return implode(', ', $bits) . ' and ' . $last;
    }

    private static function objectPronoun(string $they): string
    {
        return ['she' => 'her', 'he' => 'him'][$they] ?? 'them';
    }
}
