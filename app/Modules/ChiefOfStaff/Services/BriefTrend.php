<?php
declare(strict_types=1);

/**
 * BriefTrend — every number carries its comparison (small-business monday-brief / report-builder, 2026-11-03):
 * "200 lines (▼ 14 since yesterday), 58 urgent (▲ 3)"; ▬ under 1%; "(no prior baseline)" when yesterday has no figure,
 * never a dropped field and never a made-up one. Pure and static.
 */
final class BriefTrend
{
    public static function arrow(int $now, ?int $before): string
    {
        if ($before === null) return '(no prior baseline)';
        $d = $now - $before;
        if ($d === 0 || ($before > 0 && abs($d) / $before < 0.01)) return '▬';
        return ($d > 0 ? '▲ ' : '▼ ') . abs($d) . ' since yesterday';
    }

    /**
     * What yesterday's saved snapshot knew: the total from its heads' waiting counts, the urgent count when it stored one.
     * @return array{total: ?int, urgent: ?int}
     */
    public static function fromSnapshot(?array $payload): array
    {
        if (!$payload) return ['total' => null, 'urgent' => null];
        $total = null;
        if (isset($payload['counts']['total'])) $total = (int)$payload['counts']['total'];
        elseif (!empty($payload['heads']) && is_array($payload['heads'])) {
            $total = 0;
            foreach ($payload['heads'] as $h) $total += (int)($h['waiting'] ?? count((array)($h['items'] ?? [])));
        }
        return ['total' => $total, 'urgent' => isset($payload['counts']['urgent']) ? (int)$payload['counts']['urgent'] : null];
    }

    /** @return array{total: int, urgent: int, before_total: ?int, before_urgent: ?int, line: string} */
    public static function compare(int $total, int $urgent, array $before): array
    {
        $bt = $before['total'] ?? null; $bu = $before['urgent'] ?? null;
        $line = $total . ' line' . ($total === 1 ? '' : 's') . ' ' . self::arrow($total, $bt)
              . ', ' . $urgent . ' urgent ' . self::arrow($urgent, $bu);
        if ($bt !== null && $bu !== null && $total === $bt && $urgent === $bu) $line = 'No material changes since yesterday: ' . $total . ' line' . ($total === 1 ? '' : 's') . ', ' . $urgent . ' urgent.';
        return ['total' => $total, 'urgent' => $urgent, 'before_total' => $bt, 'before_urgent' => $bu, 'line' => $line];
    }
}
