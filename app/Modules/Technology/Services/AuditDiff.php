<?php
/**
 * AuditDiff — before → after lines for activity_log (Fable security audit #25, 2026-10-12).
 *
 * The audit trail recorded THAT a price changed or a payment was unapplied, never FROM what
 * TO what, so a quiet price cut or a reversed payment could not be traced. Callers snapshot
 * the fields before the write and after it; this turns the pair into one readable line:
 *
 *   "base_price 45.00 → 50.00; min_price (none) → 40.00"
 *
 * Pure, no database. No namespace / no autoloader in production: require_once it.
 */
class AuditDiff
{
    /**
     * @param array    $before  field => value as it was (missing key = no value)
     * @param array    $after   field => value as it is now
     * @param string[] $fields  the fields to compare, in the order to show them
     * @param string[] $money   fields compared and shown as money (2 decimals)
     * @return array<string, array{0: ?string, 1: ?string}>  only the fields that changed
     */
    public static function changes(array $before, array $after, array $fields, array $money = []): array
    {
        $out = [];
        foreach ($fields as $f) {
            $isMoney = in_array($f, $money, true);
            $a = self::norm($before[$f] ?? null, $isMoney);
            $b = self::norm($after[$f] ?? null, $isMoney);
            if ($a !== $b) $out[$f] = [$a, $b];
        }
        return $out;
    }

    /** "field old → new; field2 old → new" — '' when nothing changed. */
    public static function describe(array $changes): string
    {
        $parts = [];
        foreach ($changes as $f => [$a, $b]) {
            $parts[] = $f . ' ' . ($a ?? '(none)') . ' → ' . ($b ?? '(none)');
        }
        return implode('; ', $parts);
    }

    private static function norm($v, bool $money): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) $v = $v ? '1' : '0';
        $s = trim((string)$v);
        if ($s === '') return null;
        if ($money && is_numeric($s)) return number_format((float)$s, 2, '.', '');
        return $s;
    }
}
