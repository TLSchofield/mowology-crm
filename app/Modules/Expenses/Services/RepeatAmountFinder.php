<?php
declare(strict_types=1);

/**
 * RepeatAmountFinder — an approved purchase whose vendor and total repeat another approved purchase
 * 4–90 days earlier (plugin rule, ap-processor intake, harvested 2026-11-03). Inside 3 days TwinFinder
 * already asks; this catches the same bill entered twice a month apart, or a photo re-sent weeks later.
 * It only SURFACES: some vendors legitimately bill the same amount monthly, so Tim decides, and his
 * "Not the same purchase" dismisses the pair for good (expense_duplicate_dismissals).
 *
 * Pure and static. Receipts: {id, expense_date, total, vendor, status}; dismissals: [[a, b], …].
 */
final class RepeatAmountFinder
{
    public const MIN_DAYS = 4;
    public const MAX_DAYS = 90;
    public const KIND = 'repeat_amount';

    /** @return array<int,array{kind:string,newer_id:int,older_id:int,amount:float,days:int,vendor:string,newer_date:string,older_date:string}> */
    public static function find(array $receipts, array $dismissals): array
    {
        $active = array_values(array_filter($receipts, static function (array $r): bool {
            return in_array((string)($r['status'] ?? 'approved'), ['approved', 'forwarded'], true) && (float)($r['total'] ?? 0) > 0;
        }));
        usort($active, static function (array $a, array $b): int {
            return strcmp((string)$a['expense_date'], (string)$b['expense_date']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        $dismissed = [];
        foreach ($dismissals as $d) $dismissed[self::pairKey((int)$d[0], (int)$d[1])] = true;

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
