<?php
/**
 * KanameOpenRule — when the opening brief card is shown (Tim, 2026-11-13: "he no longer needs to be displayed every log in",
 * for every business). Plan: docs/plans/2026-11-13-kaname-not-every-login.md.
 *
 * One rule, decided here and obeyed by the iPhone and Android alike (they only send back what they last showed):
 *   - the card plays at most once per day, on the first open that has something to say;
 *   - after that, only when something NEW and urgent has appeared since it was last shown.
 * Urgent = bad news (urgent alerts, overdue deadlines, held conflicts — heads Kaname could not reach do not count, that is
 * a fault, not a reason to interrupt) and a deadline due within DEADLINE_DAYS. Not "any priority-1 line": 29% of lines are.
 *
 * This decides the sign-in card only. The Kaname tab, the flow line and every head's cards are not touched by it.
 * Pure: no database, no clock of its own.
 */
final class KanameOpenRule
{
    /** A deadline this close (or past) is urgent enough to bring the card back. */
    public const DEADLINE_DAYS = 2;
    /** At most this many shown ids are sent back and remembered by a phone. */
    public const MAX_SHOWN = 50;
    /** An urgent list longer than this is cut: a phone remembers MAX_SHOWN ids, so a longer list could never all be recorded as shown. */
    public const MAX_URGENT = 20;

    /**
     * The urgent items of a built view, each with a stable id (the same item keeps its id from one build to the next).
     * @param array $view CharlieDeskService::today() view ('bad', 'one', 'rest', optional 'state_items')
     * @return array<int, array{id: string, text: string}>
     */
    public static function urgent(array $view, string $today): array
    {
        $out = [];
        foreach ((array)($view['bad'] ?? []) as $t) {
            if (!is_string($t) || trim($t) === '' || strpos($t, "I couldn't reach") === 0) continue;
            $out[self::id('bad:' . trim($t))] = trim($t);
        }
        $items = [];
        foreach ((array)($view['state_items'] ?? []) as $col) foreach ((array)$col as $it) $items[] = $it;
        if (!$items) {
            if (!empty($view['one'])) $items[] = $view['one'];
            foreach ((array)($view['rest'] ?? []) as $it) $items[] = $it;
        }
        foreach ($items as $it) {
            $d = BriefStatus::deadlineDays((array)$it, $today);
            if ($d !== null && $d <= self::DEADLINE_DAYS) $out[self::id('key:' . (string)$it['key'])] = (string)($it['text'] ?? 'A deadline');
        }
        $list = [];
        foreach ($out as $id => $text) $list[] = ['id' => $id, 'text' => $text];
        return array_slice($list, 0, self::MAX_URGENT);
    }

    /** A short stable id for one urgent item. */
    public static function id(string $basis): string
    {
        return substr(md5($basis), 0, 10);
    }

    /** The ids a phone sent back ("a1b2…,c3d4…"): only well-formed ones, capped. */
    public static function parseShown(?string $csv): array
    {
        $ids = [];
        foreach (explode(',', (string)$csv) as $p) {
            $p = strtolower(trim($p));
            if (preg_match('/^[a-f0-9]{10}$/', $p)) $ids[$p] = true;
            if (count($ids) >= self::MAX_SHOWN) break;
        }
        return array_keys($ids);
    }

    /**
     * The verdict for one phone, from the ready brief and what that phone last showed.
     * @param array       $brief      the ready copy ('items', 'urgent')
     * @param string      $today      today's date, yyyy-mm-dd, in the business's day
     * @param string|null $shownDay   the 'day' this phone last showed the card on (null = never / an older app)
     * @param string[]    $shownUrgent urgent ids this phone has already shown
     * @return array{open_now: bool, reason: string, day: string, urgent_ids: string[]}
     *         reason: first_open | urgent | already_shown | empty
     */
    public static function verdict(array $brief, string $today, ?string $shownDay, array $shownUrgent): array
    {
        $lines  = count((array)($brief['items'] ?? []));
        $urgent = array_values(array_filter((array)($brief['urgent'] ?? []), 'is_array'));
        $ids    = array_column($urgent, 'id');
        $fresh  = array_values(array_diff($ids, $shownUrgent));
        $firstToday = $shownDay !== $today;

        if ($firstToday) {
            $reason = ($lines > 0 || $urgent) ? 'first_open' : 'empty';
        } else {
            $reason = $fresh ? 'urgent' : 'already_shown';
        }
        return [
            'open_now'   => in_array($reason, ['first_open', 'urgent'], true),
            'reason'     => $reason,
            'day'        => $today,
            // What the phone should remember as shown once it plays the card.
            'urgent_ids' => $ids,
        ];
    }
}
