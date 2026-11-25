<?php
declare(strict_types=1);

/**
 * What counts as a won quote request (Tim, 2026-10-26: "accepted counts as won"). Pure: no database.
 * Checked on production the same day: all 37 `accepted` and both `converted` rows have `converted_at` set, so the status
 * and the timestamp agree for won. 17 of 18 `quoted` rows also carry `converted_at`, so the timestamp alone
 * is NOT the rule; the status decides.
 */
final class MiaFunnelRules
{
    public const WON_STATUSES = ['accepted', 'converted'];
    public const LOST_STATUSES = ['declined'];

    public static function isWon(?string $status): bool
    {
        return in_array(strtolower(trim((string)$status)), self::WON_STATUSES, true);
    }

    public static function isLost(?string $status): bool
    {
        return in_array(strtolower(trim((string)$status)), self::LOST_STATUSES, true);
    }

    /** SQL fragment for a status column, built from the constants so the rule lives in one place. */
    public static function wonSql(string $column): string
    {
        return $column . " IN ('" . implode("','", self::WON_STATUSES) . "')";
    }

    /**
     * Funnel counts from status => count rows. `open` is everything neither won nor lost.
     * @param array<string,int> $byStatus
     * @return array{total:int, won:int, lost:int, open:int, win_rate:?float}
     */
    public static function summarise(array $byStatus): array
    {
        $total = $won = $lost = 0;
        foreach ($byStatus as $status => $n) {
            $total += (int)$n;
            if (self::isWon((string)$status)) { $won += (int)$n; }
            elseif (self::isLost((string)$status)) { $lost += (int)$n; }
        }
        $decided = $won + $lost;
        return ['total' => $total, 'won' => $won, 'lost' => $lost, 'open' => $total - $decided,
                'win_rate' => $decided > 0 ? round($won / $decided * 100, 1) : null];
    }
}
