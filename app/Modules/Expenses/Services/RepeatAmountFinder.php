<?php
declare(strict_types=1);

/**
 * RepeatAmountFinder — an approved purchase whose vendor and total repeat another approved purchase
 * 4–90 days earlier (plugin rule, ap-processor intake, harvested 2026-11-03). Inside 3 days TwinFinder
 * already asks; this catches the same bill entered twice a month apart, or a photo re-sent weeks later.
 * It only SURFACES: some vendors legitimately bill the same amount monthly, so Tim decides, and his
 * "Not the same purchase" dismisses the pair for good (expense_duplicate_dismissals).
 *
 * Two cuts found on the first live dry run (2026-11-03: 48 questions, mostly $200 fuel prepays and $84 Lawnboy
 * loads): an amount a vendor charges HABITUALLY (HABIT_COUNT or more identical totals in the window) is a pattern,
 * not a duplicate, and is never asked about; and nothing under MIN_AMOUNT is worth a question (an owner answers
 * four questions and ignores forty — plugin coding rule). TwinFinder still catches true doubles within 3 days.
 *
 * Pure and static. Receipts: {id, expense_date, total, vendor, status}; dismissals: rows {expense_a, expense_b} or [a, b].
 */
final class RepeatAmountFinder
{
    public const MIN_DAYS = 4;
    public const MAX_DAYS = 90;
    public const KIND = 'repeat_amount';
    public const HABIT_COUNT = 3;     // this many identical vendor+total in the window = a habitual amount, not a duplicate
    public const MIN_AMOUNT = 25.0;

    /** @return array<int,array{kind:string,newer_id:int,older_id:int,amount:float,days:int,vendor:string,newer_date:string,older_date:string}> */
    public static function find(array $receipts, array $dismissals): array
    {
        $active = array_values(array_filter($receipts, static function (array $r): bool {
            return in_array((string)($r['status'] ?? 'approved'), ['approved', 'forwarded'], true) && (float)($r['total'] ?? 0) >= self::MIN_AMOUNT;
        }));
        // Habitual = HABIT_COUNT or more identical vendor+total within MAX_DAYS either side of this receipt
        // (not across all time: three purchases a year apart are not a habit — Codex review; both sides, because
        // Penny asks daily and the later charges exist by then).
        $dates = [];
        foreach ($active as $r) $dates[self::vendorKey((string)$r['vendor']) . '|' . self::cents($r['total'])][] = (string)$r['expense_date'];
        $active = array_values(array_filter($active, static function (array $r) use ($dates): bool {
            $g = self::vendorKey((string)$r['vendor']) . '|' . self::cents($r['total']);
            $n = 0;
            foreach ($dates[$g] as $d) {
                if (abs(self::days($d, (string)$r['expense_date'])) <= self::MAX_DAYS) $n++;
            }
            return $n < self::HABIT_COUNT;
        }));
        usort($active, static function (array $a, array $b): int {
            return strcmp((string)$a['expense_date'], (string)$b['expense_date']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        $dismissed = [];
        foreach ($dismissals as $d) {
            $a = (int)($d['expense_a'] ?? $d[0] ?? 0); $b = (int)($d['expense_b'] ?? $d[1] ?? 0);
            if ($a && $b) $dismissed[self::pairKey($a, $b)] = true;
        }

        $finds = [];
        $taken = [];   // newer id → one question only (its nearest earlier match)
        $n = count($active);
        for ($i = 0; $i < $n; $i++) {
            $newer = $active[$i];
            for ($j = $i - 1; $j >= 0; $j--) {
                $older = $active[$j];
                $days = self::days((string)$older['expense_date'], (string)$newer['expense_date']);
                if ($days > self::MAX_DAYS) break;                 // sorted: nothing earlier is closer
                if ($days < self::MIN_DAYS) continue;              // TwinFinder's range
                if (self::cents($older['total']) !== self::cents($newer['total'])) continue;
                if (self::vendorKey((string)$older['vendor']) !== self::vendorKey((string)$newer['vendor'])) continue;
                $key = self::pairKey((int)$older['id'], (int)$newer['id']);
                if (isset($dismissed[$key]) || isset($taken[(int)$newer['id']])) continue;
                $taken[(int)$newer['id']] = true;
                $finds[] = ['kind' => self::KIND, 'newer_id' => (int)$newer['id'], 'older_id' => (int)$older['id'],
                            'amount' => round((float)$newer['total'], 2), 'days' => $days, 'vendor' => (string)$newer['vendor'],
                            'newer_date' => (string)$newer['expense_date'], 'older_date' => (string)$older['expense_date']];
                break;
            }
        }
        usort($finds, static function (array $a, array $b): int {
            return ($b['amount'] <=> $a['amount']) ?: ($a['newer_id'] <=> $b['newer_id']);
        });
        return $finds;
    }

    public static function wording(array $find): string
    {
        return sprintf('%s $%s on %s matches $%s on %s, %d days earlier. Same purchase entered twice, or a repeat bill?',
            $find['vendor'] !== '' ? $find['vendor'] : 'A receipt', number_format((float)$find['amount'], 2), $find['newer_date'],
            number_format((float)$find['amount'], 2), $find['older_date'], (int)$find['days']);
    }

    /** The same loose key TwinFinder uses: first five letters/digits of the vendor. */
    public static function vendorKey(string $vendor): string
    {
        return substr((string)preg_replace('/[^a-z0-9]/', '', strtolower($vendor)), 0, 5);
    }

    private static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    private static function days(string $from, string $to): int
    {
        return (int)round((strtotime($to) - strtotime($from)) / 86400);
    }

    private static function cents($amount): int
    {
        return (int)round((float)$amount * 100);
    }
}
