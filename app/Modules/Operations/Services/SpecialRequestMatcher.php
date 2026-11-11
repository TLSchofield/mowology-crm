<?php
/**
 * SpecialRequestMatcher — pure functions (no DB) behind special requests.
 *
 * Given a client's message and the client's properties / active plans / upcoming visits, it
 * works out WHICH scheduled visits the request is about and splits the ask into what is part of
 * the scheduled work ("included") and what is extra ("extra — decide on site").
 *
 *   "run the mower over the front lawns of the Memorial Centre, Church lawns and the two rental
 *    houses 2267 & 2279 W 45th to remove the dandelions, and rake up the leaves from the NW
 *    corner of the Memorial Centre on the boulevard into our green bins"
 *   → 2205 W 45th (property name "Memorial Centre"), 2195 W 45th ("… Church"), 2267 W 45th
 *     (its address), 2279 W 45th — no property of its own, but plan PLN-2026-0120's title
 *     "BI-WEEKLY MOWING — 2267 & 2279 W 45th" names it, so it resolves to that plan's property.
 *   → included: the mowing clause (the plans are mowing) · extra: the leaf raking.
 *
 * Matching is deliberately conservative: an address must match house number AND street, a
 * property name must share a distinctive word that the client's OTHER properties don't all share.
 * Nothing here attaches anything — SpecialRequestService proposes, Tim confirms.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class SpecialRequestMatcher
{
    /** Verbs that start a piece of outdoor work — a message with none of them is not a work request. */
    public const TASK_VERBS = [
        'run', 'mow', 'rake', 'trim', 'edge', 'blow', 'prune', 'weed', 'remove', 'clean', 'pick',
        'cut', 'spread', 'plant', 'water', 'aerate', 'fertilize', 'fertilise', 'haul', 'clear',
        'sweep', 'spray', 'hedge', 'tidy', 'collect', 'bag', 'skip', 'leave', 'avoid', 'do',
        'pull', 'top', 'lime', 'seed', 'overseed', 'salt', 'shovel', 'plow', 'plough', 'dethatch',
    ];

    /** Scope words per kind of scheduled work: an item using one is part of that visit's work. */
    public const SCOPE = [
        'mow'     => ['mow', 'mower', 'mowing', 'mowed', 'lawn', 'lawns', 'grass', 'dandelion', 'dandelions'],
        'cleanup' => ['rake', 'raking', 'leaf', 'leaves', 'blow', 'blowing', 'debris', 'cleanup', 'clean-up'],
        'hedge'   => ['hedge', 'hedges', 'shrub', 'shrubs', 'prune', 'pruning', 'trim', 'trimming'],
        'garden'  => ['weed', 'weeds', 'weeding', 'bed', 'beds', 'garden', 'mulch'],
        'snow'    => ['snow', 'salt', 'salting', 'shovel', 'plow', 'plough', 'ice'],
        'edge'    => ['edge', 'edging', 'edges', 'trim', 'whipper', 'string'],
    ];

    /** Words that never identify a property on their own. */
    private const NAME_STOP = [
        'the', 'and', 'of', 'at', 'on', 'for', 'house', 'houses', 'home', 'building', 'property',
        'unit', 'units', 'site', 'street', 'avenue', 'road', 'west', 'east', 'north', 'south',
        'front', 'back', 'rear', 'side', 'main', 'lawn', 'lawns', 'yard', 'garden', 'mowing',
        'weekly', 'bi-weekly', 'biweekly', 'monthly', 'rental', 'rentals', 'strata', 'ltd', 'inc',
    ];

    private const STREET_TYPES = 'ave|avenue|st|street|rd|road|dr|drive|blvd|boulevard|cres|crescent|pl|place|way|lane|ln|crt|ct|court|hwy|highway|terrace|ter|row|mews|close|gate|grove|walk|trail|parkway|pkwy';

    // ─────────────────────────────────────────────────────────────────────────
    // Addresses
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every street address mentioned in a text, with shared-street lists expanded:
     * "2267 & 2279 W 45th" → two mentions on the same street.
     *
     * @return list<array{number:string, street:string, dir:string, raw:string}>
     */
    public static function addresses(string $text): array
    {
        $num = '\d{2,6}[a-z]?';
        $re = '/\b(' . $num . ')((?:\s*(?:,|&|\+|\/|and)\s*' . $num . ')*)\s+'
            . '(?:(w|west|e|east|n|north|s|south)\.?\s+)?'
            . '(\d{1,3}(?:st|nd|rd|th)?|[a-z][a-z\']{2,})\b'
            . '(?:\s+(?:' . self::STREET_TYPES . ')\b\.?)?/i';
        if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $g) {
            $street = self::streetKey($g[4]);
            if ($street === '' || in_array($street, ['and', 'the', 'of', 'min', 'minutes', 'hours', 'pm', 'am', 'sq', 'ft', 'feet', 'bags', 'yards'], true)) {
                continue;
            }
            $dir = self::dirKey($g[3] ?? '');
            $nums = [$g[1]];
            if (!empty($g[2]) && preg_match_all('/' . $num . '/i', $g[2], $more)) {
                $nums = array_merge($nums, $more[0]);
            }
            foreach ($nums as $n) {
                $out[] = ['number' => strtolower($n), 'street' => $street, 'dir' => $dir, 'raw' => trim($g[0])];
            }
        }
        return $out;
    }

    /** "45th" → "45", "Avenue" stays a word, lower-cased. */
    public static function streetKey(string $s): string
    {
        $s = strtolower(trim($s));
        if (preg_match('/^(\d{1,3})(st|nd|rd|th)?$/', $s, $m)) {
            return $m[1];
        }
        return $s;
    }

    public static function dirKey(string $d): string
    {
        $d = strtolower(trim($d, " .\t"));
        return $d === '' ? '' : $d[0];
    }

    /** Same house number and street; a direction only has to agree when both sides give one. */
    public static function sameAddress(array $a, array $b): bool
    {
        if ($a['number'] !== $b['number'] || $a['street'] !== $b['street']) {
            return false;
        }
        return $a['dir'] === '' || $b['dir'] === '' || $a['dir'] === $b['dir'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Items: what's included vs extra
    // ─────────────────────────────────────────────────────────────────────────

    /** Strip quoted replies, signatures and greetings; keep the client's own words. */
    public static function clientWords(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Cut a quoted reply ("On Tue, … wrote:" / "-----Original Message-----" / "From: …").
        $text = preg_split('/\n\s*(?:On .{5,120} wrote:|-{2,}\s*Original Message|From:\s.+\nSent:)/i', $text)[0];
        $lines = [];
        foreach (explode("\n", $text) as $l) {
            if (preg_match('/^\s*>/', $l)) continue;
            $lines[] = $l;
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', implode("\n", $lines)));
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return mb_substr($text, 0, 1500);
    }

    /**
     * Split a request into separate jobs: a new sentence, or ", and <verb>" / "; <verb>" / "also <verb>".
     * "…to remove the dandelions, and rake up the leaves…" → two items; "…lawns and the two rental
     * houses…" is not split (no verb after "and").
     *
     * @return list<string>
     */
    public static function items(string $words): array
    {
        $verbs = implode('|', array_map('preg_quote', self::TASK_VERBS));
        $flat = trim(preg_replace('/\s+/', ' ', $words));
        // Drop greeting / sign-off sentences.
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $flat) ?: [];
        $out = [];
        foreach ($sentences as $s) {
            if (!preg_match('/\b(' . $verbs . ')\b/i', $s)) continue;
            if (preg_match('/^(hi|hello|hey|dear|thanks|thank you|cheers|regards|best)\b/i', $s) && str_word_count($s) < 6) continue;
            $parts = preg_split('/\s*(?:,\s*and|;|,\s*also|\.\s*also|\band also)\s+(?=(?:please\s+)?(?:' . $verbs . ')\b)/i', $s) ?: [$s];
            foreach ($parts as $p) {
                $p = trim($p, " ,.;");
                // "Could you please run the mower…" → "run the mower…"
                $p = preg_replace('/^(?:(?:hi|hello)[^,]*,\s*)?(?:could|can|would|will) you(?: please)?\s+|^please\s+/i', '', $p);
                $p = preg_replace('/^(?:also|and|then)\s+/i', '', trim($p, " ,.;?"));
                if ($p !== '' && preg_match('/\b(' . $verbs . ')\b/i', $p)) {
                    $out[] = self::ucfirst($p);
                }
            }
        }
        return $out;
    }

    /** Kinds of work a plan covers, from its title / service type / description. */
    public static function scopeOf(array $plans): array
    {
        $kinds = [];
        foreach ($plans as $p) {
            $hay = strtolower(($p['service_type'] ?? '') . ' ' . ($p['title'] ?? '') . ' ' . ($p['description'] ?? ''));
            if (preg_match('/mow|lawn|grass/', $hay)) $kinds['mow'] = true;
            if (preg_match('/clean ?up|leaf|leaves|rake|fall clean|spring clean/', $hay)) $kinds['cleanup'] = true;
            if (preg_match('/hedge|shrub|prun/', $hay)) $kinds['hedge'] = true;
            if (preg_match('/garden|weed|bed|mulch/', $hay)) $kinds['garden'] = true;
            if (preg_match('/snow|salt|ice|winter/', $hay)) $kinds['snow'] = true;
            if (preg_match('/edg|trim/', $hay)) $kinds['edge'] = true;
        }
        return array_keys($kinds);
    }

    /**
     * Included = the item uses a scope word of the work already scheduled AND no word of a kind
     * that isn't. Everything else is extra — the crew decide on site.
     *
     * @return array{included: list<string>, extra: list<string>}
     */
    public static function classify(array $items, array $scopeKinds): array
    {
        $inc = []; $ext = [];
        foreach ($items as $it) {
            $words = preg_split('/[^a-z\-]+/', strtolower($it)) ?: [];
            $hitIn = false; $hitOut = false;
            foreach (self::SCOPE as $kind => $vocab) {
                if (!array_intersect($words, $vocab)) continue;
                if (in_array($kind, $scopeKinds, true)) $hitIn = true; else $hitOut = true;
            }
            if ($hitIn && !$hitOut) $inc[] = $it; else $ext[] = $it;
        }
        return ['included' => $inc, 'extra' => $ext];
    }

    /** One line for the card / SMS: "Mow front lawns … · Extra: rake leaves …". */
    public static function summary(array $included, array $extra, int $max = 200): string
    {
        $parts = [];
        if ($included) $parts[] = implode('; ', array_map([self::class, 'short'], $included));
        if ($extra) $parts[] = 'Extra: ' . implode('; ', array_map([self::class, 'short'], $extra));
        $s = implode(' · ', $parts);
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    public static function short(string $item, int $max = 70): string
    {
        $item = trim($item);
        return mb_strlen($item) > $max ? rtrim(mb_substr($item, 0, $max - 1)) . '…' : $item;
    }

    public static function isWorkRequest(string $text): bool
    {
        $verbs = implode('|', array_map('preg_quote', self::TASK_VERBS));
        // Billing / quote questions are not work requests even if they say "do".
        if (preg_match('/\b(invoice|payment|paid|receipt|quote|estimate|price|cost|unsubscribe)\b/i', $text)
            && !preg_match('/\b(mow|rake|trim|prune|weed|blow|edge|leaves|lawn)\b/i', $text)) {
            return false;
        }
        return (bool)preg_match('/\b(' . $verbs . ')\b/i', $text) && count(self::items($text)) > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Properties and visits
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Match the text to the client's properties.
     *
     * @param list<array{id:int, address:?string, city:?string, property_name:?string}> $properties
     * @param list<array{id:int, property_id:int, plan_number:?string, title:?string, description:?string}> $plans
     * @return array{properties: array<int, list<string>>, unmatched: list<string>}
     *         properties: property_id => reasons
     */
    public static function matchProperties(string $text, array $properties, array $plans): array
    {
        $found = [];
        $mentions = self::addresses($text);
        $unmatched = [];

        // 1. House number + street against the property's own address.
        $propAddr = [];
        foreach ($properties as $p) {
            $propAddr[(int)$p['id']] = self::addresses((string)($p['address'] ?? ''));
        }
        foreach ($mentions as $mn) {
            $hit = false;
            foreach ($propAddr as $pid => $list) {
                foreach ($list as $pa) {
                    if (self::sameAddress($mn, $pa)) {
                        $found[$pid][] = 'address ' . $mn['number'] . ' ' . self::label($mn);
                        $hit = true;
                    }
                }
            }
            // 2. Not a property of its own — a plan that names it ("2267 & 2279 W 45th").
            if (!$hit) {
                foreach ($plans as $pl) {
                    $planText = ($pl['title'] ?? '') . "\n" . ($pl['description'] ?? '');
                    foreach (self::addresses($planText) as $pa) {
                        if (self::sameAddress($mn, $pa) && isset($propAddr[(int)$pl['property_id']])) {
                            $found[(int)$pl['property_id']][] = $mn['number'] . ' ' . self::label($mn)
                                . ' via plan ' . ($pl['plan_number'] ?? ('#' . $pl['id']));
                            $hit = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$hit) $unmatched[] = $mn['number'] . ' ' . self::label($mn);
        }

        // 3. A distinctive word of the property name ("Memorial Centre", "Church").
        $lower = ' ' . strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $text)) . ' ';
        $nameWords = [];
        foreach ($properties as $p) {
            $nameWords[(int)$p['id']] = self::nameWords((string)($p['property_name'] ?? ''));
        }
        $shared = count($nameWords) > 1 ? array_values(array_intersect(...array_values(array_map(fn($w) => $w ?: ['∅'], $nameWords)))) : [];
        foreach ($nameWords as $pid => $words) {
            $distinct = array_values(array_diff($words, $shared));
            // A two-word name ("Memorial Centre") must appear as a phrase when its words are common.
            $hits = [];
            foreach ($distinct as $w) {
                if (strpos($lower, ' ' . $w . ' ') !== false || strpos($lower, ' ' . $w . 's ') !== false) $hits[] = $w;
            }
            if ($hits) {
                $found[$pid][] = 'name "' . implode(' ', $hits) . '"';
            }
        }

        foreach ($found as $pid => $r) $found[$pid] = array_values(array_unique($r));
        ksort($found);
        return ['properties' => $found, 'unmatched' => array_values(array_unique($unmatched))];
    }

    /** @return list<string> */
    public static function nameWords(string $name): array
    {
        $name = strtolower(preg_replace('/\d+\s*[a-z]?\s+(?:w|e|n|s|west|east|north|south)?\.?\s*\d*(?:st|nd|rd|th)?/i', ' ', $name));
        $words = preg_split('/[^a-z]+/', $name) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (strlen($w) >= 4 && !in_array($w, self::NAME_STOP, true)) $out[] = $w;
        }
        return array_values(array_unique($out));
    }

    private static function label(array $mn): string
    {
        $dir = $mn['dir'] !== '' ? strtoupper($mn['dir']) . ' ' : '';
        $st = ctype_digit($mn['street']) ? self::ordinal((int)$mn['street']) : ucfirst($mn['street']);
        return $dir . $st;
    }

    public static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }

    /**
     * The visits to attach to: for each matched property, every open visit on its NEXT scheduled
     * date on/after $fromDate (within $horizonDays).
     *
     * @param list<array{visit_id:int, property_id:int, scheduled_date:string, status:string}> $visits
     * @return list<array> visits with 'reasons'
     */
    public static function pickVisits(array $matched, array $visits, string $fromDate, int $horizonDays = 14): array
    {
        $until = date('Y-m-d', strtotime($fromDate . ' +' . $horizonDays . ' days'));
        $byProp = [];
        foreach ($visits as $v) {
            $pid = (int)$v['property_id'];
            if (!isset($matched[$pid])) continue;
            if (!in_array($v['status'] ?? 'scheduled', ['scheduled', 'in_progress'], true)) continue;
            $d = (string)$v['scheduled_date'];
            if ($d < $fromDate || $d > $until) continue;
            $byProp[$pid][] = $v;
        }
        $out = [];
        foreach ($byProp as $pid => $list) {
            usort($list, fn($a, $b) => strcmp($a['scheduled_date'], $b['scheduled_date']) ?: ((int)$a['visit_id'] <=> (int)$b['visit_id']));
            $first = $list[0]['scheduled_date'];
            foreach ($list as $v) {
                if ($v['scheduled_date'] !== $first) break;
                $v['reasons'] = $matched[$pid];
                $out[] = $v;
            }
        }
        usort($out, fn($a, $b) => strcmp($a['scheduled_date'], $b['scheduled_date']) ?: ((int)$a['property_id'] <=> (int)$b['property_id']));
        return $out;
    }

    /**
     * Whole proposal for a message. Pure: the caller loads the client's properties, plans, visits.
     */
    public static function propose(string $text, array $properties, array $plans, array $visits, string $fromDate): array
    {
        $words = self::clientWords($text);
        $match = self::matchProperties($words, $properties, $plans);
        $matched = $match['properties'];

        // Nothing named, but the client has exactly one property with a visit coming up → that one.
        if (!$matched) {
            $upcoming = [];
            foreach ($visits as $v) {
                if ((string)$v['scheduled_date'] >= $fromDate && in_array($v['status'] ?? 'scheduled', ['scheduled', 'in_progress'], true)) {
                    $upcoming[(int)$v['property_id']] = true;
                }
            }
            if (count($upcoming) === 1) {
                $matched[(int)array_key_first($upcoming)] = ['the client\'s only property with a visit coming up'];
            }
        }

        $picked = self::pickVisits($matched, $visits, $fromDate);
        $pickedPlans = [];
        foreach ($plans as $pl) {
            foreach ($picked as $v) {
                if ((int)($v['plan_id'] ?? 0) === (int)$pl['id']) $pickedPlans[] = $pl;
            }
        }
        $items = self::items($words);
        $cls = self::classify($items, self::scopeOf($pickedPlans ?: $plans));

        // Per visit: an item that names particular properties ("the NW corner of the Memorial
        // Centre") belongs to those visits only; an item naming none belongs to every visit.
        $itemProps = [];
        foreach ($items as $it) {
            $itemProps[$it] = array_keys(self::matchProperties($it, $properties, $plans)['properties']);
        }
        foreach ($picked as &$v) {
            $pid = (int)$v['property_id'];
            $plansHere = array_values(array_filter($plans, fn($pl) => (int)$pl['id'] === (int)($v['plan_id'] ?? 0)));
            $mine = array_values(array_filter($items, fn($it) => !$itemProps[$it] || in_array($pid, $itemProps[$it], true)));
            $c = self::classify($mine, self::scopeOf($plansHere ?: $pickedPlans ?: $plans));
            $v['included'] = $c['included'];
            $v['extra'] = $c['extra'];
        }
        unset($v);

        $props = [];
        foreach ($properties as $p) {
            if (isset($matched[(int)$p['id']])) {
                $props[] = [
                    'property_id' => (int)$p['id'],
                    'address'     => trim((string)($p['address'] ?? '')),
                    'name'        => $p['property_name'] ?? null,
                    'reasons'     => $matched[(int)$p['id']],
                ];
            }
        }

        return [
            'is_work_request' => self::isWorkRequest($words),
            'client_words'    => $words,
            'properties'      => $props,
            'visits'          => array_values($picked),
            'unmatched'       => $match['unmatched'],
            'included'        => $cls['included'],
            'extra'           => $cls['extra'],
            'summary'         => self::summary($cls['included'], $cls['extra']),
        ];
    }

    private static function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
