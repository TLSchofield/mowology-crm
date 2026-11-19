<?php
declare(strict_types=1);

/**
 * UrgencyGuard — a head cannot make everything urgent (small-business inbox-manager, 2026-11-03: "aim for about 5, 8 is fine;
 * if twenty qualify, the threshold is wrong"). Within one head at most CAP lines keep priority 1 in the RANKING; the rest are
 * ranked as priority 2 and marked `demoted`. The head's own text, its stored priority and every dismissal stay as they were:
 * nothing is hidden, only out-ranked. A customer's clear yes (×5 in CharlieRankService) is never demoted.
 * Order inside a head: a clear yes, then the larger amount, then the older line, then the key (stable).
 * Pure and static.
 */
final class UrgencyGuard
{
    public const CAP = 5;

    /**
     * @param array $items normalized lines (head, key, priority, value, since, yes)
     * @return array{items: array, inflated: array<string, array{p1: int, total: int, kept: int}>}
     */
    public static function apply(array $items, int $cap = self::CAP): array
    {
        $byHead = [];
        foreach ($items as $i => $it) {
            if ((int)($it['priority'] ?? 2) === 1) $byHead[(string)$it['head']][] = $i;
        }
        $inflated = [];
        foreach ($byHead as $head => $idx) {
            if (count($idx) <= $cap) continue;
            usort($idx, static function ($a, $b) use ($items) {
                $x = $items[$a]; $y = $items[$b];
                return [empty($x['yes']) ? 1 : 0, -(float)($x['value'] ?? 0), (string)($x['since'] ?? '9999'), (string)$x['key']]
                   <=> [empty($y['yes']) ? 1 : 0, -(float)($y['value'] ?? 0), (string)($y['since'] ?? '9999'), (string)$y['key']];
            });
            $keep = 0;
            foreach ($idx as $n => $i) {
                if (!empty($items[$i]['yes']) || $n < $cap) { $keep++; continue; }
                $items[$i]['priority'] = 2;
                $items[$i]['demoted'] = true;
            }
            $total = count(array_filter($items, static fn($it) => (string)$it['head'] === $head));
            $inflated[$head] = ['p1' => count($idx), 'total' => $total, 'kept' => $keep];
        }
        return ['items' => array_values($items), 'inflated' => $inflated];
    }

    /**
     * After ranking: give every demoted line its head's own priority back. The demotion lives in the SCORE (and so in the order) only;
     * the card, the ready brief, the frozen snapshot and the phone must keep seeing what the head said (Codex 2026-11-03).
     * @param array $ranked ranked lines (priority possibly demoted); $orig key => the head's own priority
     */
    public static function restore(array $ranked, array $orig): array
    {
        foreach ($ranked as &$r) {
            if (!empty($r['demoted']) && isset($orig[$r['key']])) $r['priority'] = (int)$orig[$r['key']];
        }
        unset($r);
        return $ranked;
    }

    /** One quiet line per head over the cap, for the card (not bad news). */
    public static function notes(array $inflated, array $names, int $cap = self::CAP): array
    {
        $out = [];
        foreach ($inflated as $head => $n) {
            $name = $names[$head] ?? ucfirst((string)$head);
            $out[] = "{$name} marks {$n['p1']} of {$n['total']} lines urgent; more than {$cap} usually means its rule is too loose, so only its top {$cap} count as urgent here.";
        }
        return $out;
    }
}
