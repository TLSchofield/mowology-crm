<?php
/**
 * ReceiptBookkeeperRules — the owner's bookkeeping rules as deterministic code.
 *
 * Run before the AI bookkeeper, and handed to it, so a rule the owner stated is
 * applied the same way every time and every suggestion it drives can say which
 * rule fired. Rules (Tim, 2026-10-05):
 *   - Diesel is for the Dodge Ram truck          → Fuel, tag 'truck'
 *   - Regular gas, usually under $50, is for the equipment → Fuel, tag 'equipment'
 *   - EGO items, usually from Canadian Tire or Rona, are probably an equipment
 *     purchase                                    → Tools/Equipment (soft)
 * One Fuel category with a truck/equipment tag feeds the GGOB cost drill-down.
 *
 * Pure: no DB, no I/O — unit tested.
 */
class ReceiptBookkeeperRules
{
    /** What the expense was for: the truck, the equipment, or shop stock (bought to keep, not for one job). */
    public const TAGS = ['truck', 'equipment', 'stock'];

    /** Gas under this total is the equipment's (mowers, trimmers, blowers). */
    public const EQUIPMENT_GAS_MAX = 50.00;

    /**
     * @param array  $receipt   ['total' => float|string, 'vendor' => ?string]
     * @param string $ocrText   Receipt text
     * @param array  $itemNames Line-item names
     * @return list<array{field: string, value: string, reason: string, rule: string, strength: string}>
     *         strength: 'firm' (owner rule, apply) | 'soft' ("probably" — suggest, may be overruled)
     */
    public static function evaluate(array $receipt, string $ocrText, array $itemNames = []): array
    {
        $text  = strtoupper($ocrText . "\n" . implode("\n", $itemNames));
        $total = (float)($receipt['total'] ?? 0);
        $hits  = [];

        $fuel = self::fuelType($text);
        if ($fuel === 'diesel') {
            $hits[] = self::hit('accounting_category', 'Fuel', 'Diesel on the receipt', 'fuel_diesel_truck', 'firm');
            $hits[] = self::hit('asset_tag', 'truck', 'Diesel is for the Dodge Ram truck', 'fuel_diesel_truck', 'firm');
        } elseif ($fuel === 'gasoline') {
            $hits[] = self::hit('accounting_category', 'Fuel', 'Gasoline on the receipt', 'fuel_gas_equipment', 'firm');
            if ($total > 0 && $total <= self::EQUIPMENT_GAS_MAX) {
                $hits[] = self::hit('asset_tag', 'equipment', sprintf('Regular gas under $%d is for the equipment', (int)self::EQUIPMENT_GAS_MAX), 'fuel_gas_equipment', 'firm');
            } else {
                $hits[] = self::hit('asset_tag', 'equipment', sprintf('Gas is usually for the equipment, but $%.2f is more than the usual fill-up — check', $total), 'fuel_gas_equipment', 'soft');
            }
        }

        if ($fuel === null && preg_match('/\bEGO\b/', $text)) {
            $hits[] = self::hit('accounting_category', 'Tools/Equipment', 'EGO item — usually an equipment purchase', 'ego_equipment', 'soft');
        }

        return $hits;
    }

    /**
     * 'diesel' | 'gasoline' | null. Diesel wins when both appear (a truck fill-up with
     * a jerry can of gas is still a diesel receipt for the truck).
     */
    public static function fuelType(string $upperText): ?string
    {
        if (preg_match('/\b(DIESEL|DSL|ULSD|CLEAR\s+DSL|DYED\s+DSL)\b/', $upperText)) {
            return 'diesel';
        }
        if (preg_match('/\b(UNLEADED|UNL|REGULAR\s+(GAS|UNL)|REG\s+UNL|PREMIUM\s+(GAS|UNL)|SUPREME|MID-?GRADE|GASOLINE)\b/', $upperText)
            || (preg_match('/\bREG(ULAR)?\b/', $upperText) && preg_match('/\b(LITRES?|LTRS?|\d+\.\d+\s*L\b|PUMP|\/L\b)/', $upperText))) {
            return 'gasoline';
        }
        return null;
    }

    private static function hit(string $field, string $value, string $reason, string $rule, string $strength): array
    {
        return compact('field', 'value', 'reason', 'rule', 'strength');
    }
}
