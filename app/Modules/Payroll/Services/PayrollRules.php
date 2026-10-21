<?php
/**
 * PayrollRules — the CRA / provincial payroll rules in force on a pay date (Penny Payroll, step 1, 2026-10-21).
 *
 * Reads the versioned rule files in app/Modules/Payroll/Data/ (rules-YYYY.php). The edition is chosen by PAY
 * DATE. A pay date that no loaded edition covers is refused with a plain reason ("load the new CRA tables"):
 * a run must never be calculated on last year's rates by accident (plan v2, risk "twice-a-year updates").
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PayrollRules
{
    /** Provinces this engine calculates. Quebec is out of scope. */
    public const PROVINCES = ['BC'];

    /** @var array<int, array> */
    private array $editions;

    /** @param array<int, array>|null $editions for tests; null = every rules-*.php file */
    public function __construct(?array $editions = null)
    {
        if ($editions === null) {
            $editions = [];
            foreach (glob(dirname(__DIR__) . '/Data/rules-*.php') ?: [] as $file) {
                foreach ((array)require $file as $e) $editions[] = $e;
            }
        }
        $this->editions = $editions;
    }

    /** @return array<int, array> every loaded edition */
    public function editions(): array
    {
        return $this->editions;
    }

    /**
     * The federal (CA) and provincial editions in force on $payDate (Y-m-d).
     * @return array{federal: array, provincial: array, pay_date: string}
     * @throws RuntimeException when no edition covers the date or the province isn't supported
     */
    public function forPayDate(string $province, string $payDate): array
    {
        $province = strtoupper(trim($province));
        if (!in_array($province, self::PROVINCES, true)) {
            throw new RuntimeException("Payroll isn't set up for {$province} yet" . ($province === 'QC' ? ' (Quebec is not supported).' : '.'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) throw new RuntimeException('A pay date is needed (YYYY-MM-DD).');
        return [
            'federal'    => $this->pick('CA', $payDate),
            'provincial' => $this->pick($province, $payDate),
            'pay_date'   => $payDate,
        ];
    }

    private function pick(string $jurisdiction, string $payDate): array
    {
        $hits = array_values(array_filter($this->editions, fn($e) => ($e['jurisdiction'] ?? '') === $jurisdiction
            && $payDate >= (string)$e['effective_from'] && $payDate <= (string)$e['effective_to']));
        if (count($hits) === 1) return $hits[0];
        if (count($hits) > 1) throw new RuntimeException("Two {$jurisdiction} payroll editions overlap on {$payDate} — fix the rule files.");
        throw new RuntimeException("No {$jurisdiction} payroll rules for a pay date of {$payDate}: load the new CRA tables (T4127) before running payroll.");
    }

    /**
     * Pure: problems with the loaded editions (empty = all good). Checked by the tests and before any run:
     * a source and edition on every entry, dates in order, no overlaps, brackets ascending with constants that
     * match the rates (K_i = K_{i-1} + A_i × (R_i − R_{i-1}), as CRA builds them, to the dollar).
     * @return list<string>
     */
    public static function problems(array $editions): array
    {
        $out = [];
        $byJ = [];
        foreach ($editions as $i => $e) {
            $id = ($e['jurisdiction'] ?? '?') . ' ' . ($e['edition'] ?? "#{$i}");
            foreach (['jurisdiction', 'edition', 'effective_from', 'effective_to', 'source', 'brackets', 'lowest_rate', 'bpa'] as $k) {
                if (empty($e[$k]) && $e[$k] !== 0) $out[] = "{$id}: missing {$k}";
            }
            if (!empty($e['source']) && !preg_match('#^https://www\.canada\.ca/#', (string)$e['source'])) $out[] = "{$id}: source is not a canada.ca page";
            if (($e['effective_from'] ?? '') > ($e['effective_to'] ?? '')) $out[] = "{$id}: dates out of order";
            $byJ[$e['jurisdiction'] ?? '?'][] = $e;
            $b = (array)($e['brackets'] ?? []);
            $k = 0.0;
            foreach ($b as $n => $row) {
                if ($n === 0) { if ((float)$row['from'] !== 0.0 || (float)$row['k'] !== 0.0) $out[] = "{$id}: first bracket must start at 0 with K 0"; continue; }
                if ($row['from'] <= $b[$n - 1]['from'] || $row['rate'] <= $b[$n - 1]['rate']) $out[] = "{$id}: bracket " . ($n + 1) . ' is not above the one before';
                $k += $row['from'] * ($row['rate'] - $b[$n - 1]['rate']);
                if (abs(round($k) - (float)$row['k']) > 1) $out[] = "{$id}: constant K for bracket " . ($n + 1) . " should be about " . round($k) . ", not {$row['k']}";
            }
            if (isset($b[0]['rate']) && abs((float)$b[0]['rate'] - (float)($e['lowest_rate'] ?? 0)) > 1e-9) $out[] = "{$id}: lowest_rate differs from the first bracket";
        }
        foreach ($byJ as $j => $list) {
            usort($list, fn($a, $b) => strcmp((string)$a['effective_from'], (string)$b['effective_from']));
            for ($n = 1; $n < count($list); $n++) {
                if ($list[$n]['effective_from'] <= $list[$n - 1]['effective_to']) $out[] = "{$j}: {$list[$n - 1]['edition']} and {$list[$n]['edition']} overlap";
            }
        }
        return $out;
    }
}
