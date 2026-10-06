<?php
/**
 * CharlieRankService — how Charlie orders everything the heads hand him (pure, unit tested).
 *
 *   score = priority weight × learned weight for the item's kind × age × money
 *     priority weight  1 → 100, 2 → 40, 3 → 15 (the head's own call)
 *     learned weight   starts at 1.0, kept between 0.25 and 4 (charlie_prefs)
 *     age              +10% a day the item has waited, at most double
 *     money            1 + log10(1 + value / 100) when the head gives a dollar value
 *
 * Learning is pairwise, like a chess rating: when the owner deals with item A while B
 * (from the same morning) is still waiting, A's kind gains and B's kind loses. Kinds of
 * the same type never compete with each other.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CharlieRankService
{
    public const PRIORITY_WEIGHT = [1 => 100.0, 2 => 40.0, 3 => 15.0];
    public const MIN_PREF = 0.25;
    public const MAX_PREF = 4.0;
    /** Step size: one ordinary decision. Dismissals and direct answers are stronger. */
    public const K = 0.08;
    public const K_DISMISS = 0.12;
    public const K_ANSWER = 0.25;
    /** The top two are a "close call" when the runner-up is within this share of the leader. */
    public const CLOSE_CALL = 0.9;
    public const HEADS = ['penny', 'sam', 'otto', 'mia', 'house'];

    /**
     * Bring one head's item onto the contract, or null if there's nothing to show.
     * A missing key (contract breach) falls back to one built from the text, so the item
     * still shows; it just learns per wording instead of per record.
     */
    public static function normalize(array $item, string $head): ?array
    {
        $text = trim(preg_replace('/\s+/', ' ', (string)($item['text'] ?? '')));
        if ($text === '') return null;
        $key = trim((string)($item['key'] ?? ''));
        if ($key === '') $key = $head . ':text:' . substr(sha1($text), 0, 12);
        $key = substr($key, 0, 120);
        $kind = trim((string)($item['kind'] ?? ''));
        if ($kind === '') $kind = self::kindFromKey($key);
        elseif (strpos($kind, ':') === false) $kind = $head . ':' . $kind;
        $p = (int)($item['priority'] ?? 2);
        $since = isset($item['since']) && is_string($item['since']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $item['since'])
            ? substr($item['since'], 0, 10) : null;
        $url = isset($item['url']) && is_string($item['url']) && $item['url'] !== '' ? substr($item['url'], 0, 500) : null;
        return [
            'key'      => $key,
            'head'     => $head,
            'kind'     => substr($kind, 0, 60),
            'text'     => mb_substr($text, 0, 500),
            'url'      => $url,
            'priority' => max(1, min(3, $p ?: 2)),
            'value'    => isset($item['value']) && is_numeric($item['value']) ? round((float)$item['value'], 2) : null,
            'since'    => $since,
        ];
    }

    /** "sam:lead:12" → "sam:lead"; "house:overdue_invoices" → "house:overdue_invoices". */
    public static function kindFromKey(string $key): string
    {
        $parts = explode(':', $key);
        return count($parts) >= 3 ? $parts[0] . ':' . $parts[1] : $key;
    }

    /** @param string $today Y-m-d */
    public static function score(array $item, float $pref, string $today): float
    {
        $w = self::PRIORITY_WEIGHT[(int)($item['priority'] ?? 2)] ?? self::PRIORITY_WEIGHT[2];
        $from = $item['since'] ?? ($item['first_seen'] ?? null);
        $days = 0;
        if ($from) {
            $days = max(0, (int)floor((strtotime($today) - strtotime(substr((string)$from, 0, 10))) / 86400));
        }
        $age = min(2.0, 1 + 0.1 * $days);
        $money = (isset($item['value']) && $item['value'] !== null && (float)$item['value'] > 0)
            ? 1 + log10(1 + (float)$item['value'] / 100) : 1.0;
        return round($w * self::clamp($pref) * $age * $money, 3);
    }

    /**
     * @param array $items    normalized items (may carry first_seen)
     * @param array $prefs    kind => ['score' => float, 'muted' => bool]
     * @param array $hidden   keys not to show (snoozed / dismissed)
     * @return array items with 'score', best first; muted kinds and hidden keys left out
     */
    public static function rank(array $items, array $prefs, array $hidden, string $today): array
    {
        $hide = array_flip($hidden);
        $out = [];
        foreach ($items as $it) {
            if (isset($hide[$it['key']])) continue;
            $p = $prefs[$it['kind']] ?? null;
            if ($p && !empty($p['muted'])) continue;
            $it['score'] = self::score($it, (float)($p['score'] ?? 1.0), $today);
            $out[] = $it;
        }
        usort($out, static function ($a, $b) {
            return [$b['score'], $a['priority'], $b['value'] ?? 0, $a['key']] <=> [$a['score'], $b['priority'], $a['value'] ?? 0, $b['key']];
        });
        return $out;
    }

    /** Is the runner-up close enough to the leader to be worth asking about? */
    public static function closeCall(array $ranked): bool
    {
        if (count($ranked) < 2) return false;
        [$a, $b] = $ranked;
        return $a['kind'] !== $b['kind'] && $a['score'] > 0 && $b['score'] >= $a['score'] * self::CLOSE_CALL;
    }

    /**
     * The winner's kind gains, the loser's loses (on a log scale, so 2× and ½× are the same step).
     * @return array{0: float, 1: float} [winner, loser]
     */
    public static function learnPair(float $winner, float $loser, float $k = self::K): array
    {
        $lw = log(self::clamp($winner));
        $ll = log(self::clamp($loser));
        $expected = 1 / (1 + exp($ll - $lw));
        $step = $k * (1 - $expected) * 2;
        return [round(self::clamp(exp($lw + $step)), 3), round(self::clamp(exp($ll - $step)), 3)];
    }

    public static function clamp(float $v): float
    {
        return max(self::MIN_PREF, min(self::MAX_PREF, $v ?: 1.0));
    }
}
