<?php
/**
 * CapitalItemRule — the standard capital / small-tools split for an equipment purchase (owner, 2026-10-19:
 * "always do what is standard and ensure the accountant can track and understand what is booked").
 *
 * Standard small-business treatment (ASPE capitalisation policy; CRA CCA classes):
 *   an item costing the threshold or more EACH (ops_settings capital_threshold, default $500, before tax)
 *     → the equipment asset account (1500 Equipment & Tools; CCA Class 8, recorded in the equipment register);
 *   everything else on the receipt → the receipt's own expense account (Tools/Equipment → 6210 Small Tools
 *     & Equipment; tools under $500 are Class 12, written off in the year).
 * A receipt with no lines: its pre-tax amount decides. A person's own split always wins (LedgerService
 * only applies this when the receipt has no split). Taxes are shared by net (ExpenseSplitService::allocate),
 * so the entry adds up to the receipt to the cent, and each debit line says what it is.
 * PURE. Global-namespace class: require_once the file.
 */
class CapitalItemRule
{
    public const DEFAULT_THRESHOLD = 500.0;
    public const CAPITAL_ACCOUNT = '1500';

    /** One line's cost each (unit price, else total ÷ quantity). */
    public static function each(array $line): float
    {
        $qty = (float)($line['quantity'] ?? 1);
        $unit = isset($line['unit_price']) && $line['unit_price'] !== null && (float)$line['unit_price'] > 0 ? (float)$line['unit_price'] : null;
        return round($unit ?? ((float)($line['line_total'] ?? 0) / ($qty > 0 ? $qty : 1)), 2);
    }

    /**
     * The posting for one receipt: null = no capital item (post as it is); else
     * ['expense_account' => …] for a receipt that is all capital, or ['allocations' => […]] for a mixed one.
     * @param array $exp   ledger args: net, gst, pst, expense_account (the receipt's own), id
     * @param array $lines expense_line_items rows: name, quantity, unit_price, line_total
     */
    public static function apply(array $exp, array $lines, float $threshold = self::DEFAULT_THRESHOLD, string $capital = self::CAPITAL_ACCOUNT): ?array
    {
        $net = round((float)$exp['net'], 2);
        $gst = round((float)($exp['gst'] ?? 0), 2);
        $pst = round((float)($exp['pst'] ?? 0), 2);
        $own = (string)$exp['expense_account'];
        if ($own === $capital) return null;
        $memo = 'Expense #' . ($exp['id'] ?? '') . ' — items of $' . number_format($threshold, 0) . '+ each to equipment (capital), the rest ' . $own;
        $lines = array_values(array_filter($lines, static fn($l) => abs((float)($l['line_total'] ?? 0)) >= 0.005));
        if (!$lines) {
            return $net >= $threshold ? ['expense_account' => $capital, 'memo' => 'Expense #' . ($exp['id'] ?? '') . ' — capital item ($' . number_format($threshold, 0) . '+)'] : null;
        }
        $cap = []; $small = []; $capNames = [];
        foreach ($lines as $l) {
            if ((float)$l['line_total'] > 0 && self::each($l) >= $threshold) { $cap[] = (float)$l['line_total']; $capNames[] = (string)$l['name']; }
            else $small[] = (float)$l['line_total'];
        }
        if (!$cap) return null;
        if (!$small) return ['expense_account' => $capital, 'memo' => 'Expense #' . ($exp['id'] ?? '') . ' — capital: ' . mb_substr(implode(', ', $capNames), 0, 180)];
        if (!class_exists('ExpenseSplitService')) require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseSplitService.php';
        $parts = ExpenseSplitService::allocate([['key' => 'capital', 'net' => array_sum($cap)], ['key' => 'small', 'net' => array_sum($small)]],
                                               round($net + $gst + $pst, 2), $gst, $pst);
        return [
            'memo' => $memo,
            'allocations' => [
                ['account' => $capital, 'net' => $parts['capital']['net'], 'gst' => $parts['capital']['gst'], 'pst' => $parts['capital']['pst'],
                 'label' => mb_substr('Capital ($' . number_format($threshold, 0) . '+ each): ' . implode(', ', $capNames), 0, 255),
                 'job_id' => $exp['job_id'] ?? null, 'cost_type_id' => null],
                ['account' => $own, 'net' => $parts['small']['net'], 'gst' => $parts['small']['gst'], 'pst' => $parts['small']['pst'],
                 'label' => 'Other items on the receipt', 'job_id' => $exp['job_id'] ?? null, 'cost_type_id' => $exp['cost_type_id'] ?? null],
            ],
        ];
    }
}
