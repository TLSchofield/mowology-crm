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
     * The same three rules as evaluate(), written as DATA — what the original business is seeded with (ReceiptProfile::mowologyOps()) and what any
     * business can have its own version of. A test proves evaluateWith(mowologyRules()) gives the same hits as evaluate() on every case.
     * Rule shape: id, hits[] (field, value, reason, strength) and conditions: fuel (diesel|gasoline), no_fuel (bool), text_regex (on the UPPER-cased
     * receipt text and item names), total_range [over, upto] (over < total <= upto) and outside (true = the total is NOT in that range).
     * A reason may carry {total} (the receipt total, two decimals).
     * @return list<array>
     */
    public static function mowologyRules(): array
    {
        return [
            ['id' => 'fuel_diesel_truck', 'fuel' => 'diesel', 'hits' => [
                ['field' => 'accounting_category', 'value' => 'Fuel', 'reason' => 'Diesel on the receipt', 'strength' => 'firm'],
                ['field' => 'asset_tag', 'value' => 'truck', 'reason' => 'Diesel is for the Dodge Ram truck', 'strength' => 'firm'],
            ]],
            ['id' => 'fuel_gas_equipment', 'fuel' => 'gasoline', 'hits' => [
                ['field' => 'accounting_category', 'value' => 'Fuel', 'reason' => 'Gasoline on the receipt', 'strength' => 'firm'],
            ]],
            ['id' => 'fuel_gas_equipment', 'fuel' => 'gasoline', 'total_range' => [0, self::EQUIPMENT_GAS_MAX], 'hits' => [
                ['field' => 'asset_tag', 'value' => 'equipment', 'reason' => sprintf('Regular gas under $%d is for the equipment', (int)self::EQUIPMENT_GAS_MAX), 'strength' => 'firm'],
            ]],
            ['id' => 'fuel_gas_equipment', 'fuel' => 'gasoline', 'total_range' => [0, self::EQUIPMENT_GAS_MAX], 'outside' => true, 'hits' => [
                ['field' => 'asset_tag', 'value' => 'equipment', 'reason' => 'Gas is usually for the equipment, but ${total} is more than the usual fill-up — check', 'strength' => 'soft'],
            ]],
            ['id' => 'ego_equipment', 'no_fuel' => true, 'text_regex' => '/\bEGO\b/', 'hits' => [
                ['field' => 'accounting_category', 'value' => 'Tools/Equipment', 'reason' => 'EGO item — usually an equipment purchase', 'strength' => 'soft'],
            ]],
        ];
    }

    /**
     * The business's own rules, evaluated. No rules, no hits: the AI proposes and nothing is forced. Pure.
     * @param list<array> $rules ReceiptProfile::decodeRules()
     * @return list<array{field: string, value: string, reason: string, rule: string, strength: string}>
     */
    public static function evaluateWith(array $rules, array $receipt, string $ocrText, array $itemNames = []): array
    {
        $text  = strtoupper($ocrText . "\n" . implode("\n", $itemNames));
        $total = (float)($receipt['total'] ?? 0);
        $fuel  = self::fuelType($text);
        $hits  = [];
        foreach ($rules as $r) {
            if (isset($r['fuel']) && $r['fuel'] !== $fuel) continue;
            if (!empty($r['no_fuel']) && $fuel !== null) continue;
            if (!empty($r['text_regex']) && @preg_match((string)$r['text_regex'], $text) !== 1) continue;
            if (isset($r['total_range']) && is_array($r['total_range']) && count($r['total_range']) === 2) {
                $in = $total > (float)$r['total_range'][0] && $total <= (float)$r['total_range'][1];
                if ($in === !empty($r['outside'])) continue;
            }
            foreach ($r['hits'] as $h) {
                $hits[] = self::hit((string)$h['field'], (string)$h['value'], str_replace('{total}', number_format($total, 2, '.', ''), (string)$h['reason']), (string)$r['id'], (string)$h['strength']);
            }
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
