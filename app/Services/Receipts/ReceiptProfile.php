<?php
declare(strict_types=1);

/**
 * ReceiptProfile — what the receipt AI needs to know about THIS business, so the prompt and the checks never say "Mowology" or "BC" by default.
 * (Tim, 2026-10-26: the audit found the prompt naming the Dodge Ram, mowers and BC taxes. Claudia, a gardener in Vancouver, is the second business.)
 *
 * Built from: BusinessIdentity (name, owner, province), the `tax_rates` table (GST/PST per province: the live values the regions skill points at),
 * and a few ops_settings the business owns: gst_registered, receipt_vehicle_label, receipt_equipment_label, receipt_vendor_example, receipt_owner_rules.
 * Defaults are NEUTRAL: not GST-registered, "the company vehicle", no examples, no owner rules. The original business is seeded explicitly
 * (mowologyOps()) so its prompt and rule hits are byte-for-byte what they were — a golden test holds that.
 *
 * A province without active tax_rates rows is "unsupported": tax_checked = false, the checks say so and never guess a rate.
 *
 * Global namespace; require_once it.
 */
final class ReceiptProfile
{
    public const SETTING_KEYS = ['gst_registered', 'receipt_vehicle_label', 'receipt_equipment_label', 'receipt_vendor_example', 'receipt_owner_rules'];

    private static ?array $use = null;

    /** Use exactly this profile until reset() (tests). */
    public static function use(?array $profile): void
    {
        self::$use = $profile === null ? null : array_merge(self::build(['province' => ''], [], []), $profile);   // every key always present
    }

    public static function reset(): void
    {
        self::$use = null;
    }

    /** The profile for the current business. Never throws: any trouble gives the neutral profile. */
    public static function forBusiness(?PDO $db = null): array
    {
        if (self::$use !== null) return self::$use;
        require_once dirname(__DIR__) . '/BusinessIdentity.php';
        try {
            $db = $db ?? (function_exists('getDB') ? getDB() : null);
            $identity = BusinessIdentity::get($db);
        } catch (Throwable $e) {
            return self::build(['province' => ''], [], []);
        }
        $ops = [];
        $tax = [];
        if ($db instanceof PDO) {
            try {
                $in = implode(',', array_fill(0, count(self::SETTING_KEYS), '?'));
                $s = $db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ($in)");
                $s->execute(self::SETTING_KEYS);
                $ops = $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) { /* neutral defaults */ }
            try {
                $s = $db->prepare("SELECT code, rate FROM tax_rates WHERE is_active = 1 AND province = ?");
                $s->execute([strtoupper(trim((string)($identity['province'] ?? '')))]);
                $tax = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { /* BC fallback below */ }
        }
        return self::build($identity, $ops, $tax);
    }

    /**
     * Pure.
     * @param array $identity BusinessIdentity::get()  @param array $ops setting_key => value  @param list<array{code:string,rate:mixed}> $taxRows active rows for the province
     * @return array{province:string, gst_rate:?float, pst_rate:?float, tax_checked:bool, gst_registered:bool, vehicle_label:string,
     *               equipment_label:string, vendor_example:string, rules:list<array>}
     */
    public static function build(array $identity, array $ops, array $taxRows): array
    {
        $prov = strtoupper(trim((string)($identity['province'] ?? '')));
        $gst = null;
        $pst = null;
        foreach ($taxRows as $r) {
            $code = strtoupper((string)($r['code'] ?? ''));
            if ($code === 'GST' && $gst === null) $gst = round((float)$r['rate'], 5);
            if ($code === 'PST' && $pst === null) $pst = round((float)$r['rate'], 5);
        }
        if ($gst === null && $pst === null && $prov === 'BC') {   // the one verified province (regions skill, CRA / BC): used only when the table has NO rows for it
            $gst = 0.05;
            $pst = 0.07;
        }
        $label = fn(string $k, string $default, int $max) => self::cleanText((string)($ops[$k] ?? ''), $max) !== '' ? self::cleanText((string)$ops[$k], $max) : $default;
        return [
            'province'        => $prov,
            'gst_rate'        => $gst,
            'pst_rate'        => $pst,
            'tax_checked'     => $gst !== null,
            'gst_registered'  => (string)($ops['gst_registered'] ?? '0') === '1',
            'vehicle_label'   => $label('receipt_vehicle_label', 'the company vehicle', 80),
            'equipment_label' => $label('receipt_equipment_label', 'the business equipment', 80),
            'vendor_example'  => self::cleanText((string)($ops['receipt_vendor_example'] ?? ''), 200),
            'rules'           => self::decodeRules((string)($ops['receipt_owner_rules'] ?? '')),
        ];
    }

    /**
     * Free text from a business's settings that ends up inside the system prompt: one line, plain characters, bounded (Codex 2026-10-26).
     * It is the owner describing their own business to their own AI, so this limits accidents and tidy-ups, not a hostile owner. Pure.
     */
    public static function cleanText(string $v, int $max): string
    {
        $v = (string)preg_replace('/[^\p{L}\p{N} &\',.\-();:"\/]/u', '', str_replace(["\r", "\n", "\t"], ' ', $v));
        return mb_substr(trim((string)preg_replace('/\s+/', ' ', $v)), 0, $max);
    }

    /** A rate as the prompt prints it: 0.05 → "5", 0.09975 → "9.975". Pure. */
    public static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 3, '.', ''), '0'), '.');
    }

    /** "In BC, GST is 5% and PST is 7%" — or what we can honestly say when the province has no verified rates. Pure. */
    public static function taxSentence(array $p): string
    {
        if (!$p['tax_checked']) {
            return "This business's province has no tax rates set up here, so read every tax amount exactly as printed and do not judge it against a rate";
        }
        $in = $p['province'] !== '' ? 'In ' . $p['province'] . ', ' : '';
        return $in . 'GST is ' . self::pct((float)$p['gst_rate']) . '%' . ($p['pst_rate'] !== null ? ' and PST is ' . self::pct((float)$p['pst_rate']) . '%' : '');
    }

    /** Said only when the business cannot claim GST back. Pure. */
    public static function gstNote(array $p): string
    {
        return $p['gst_registered'] ? '' : " This business is not registered for GST, so it cannot claim input tax credits: the GST it pays is part of the cost. Still read the printed GST exactly.";
    }

    /** The settings that give the original business exactly the behaviour it had before profiles existed. Pure. */
    public static function mowologyOps(): array
    {
        require_once dirname(__DIR__, 2) . '/Modules/Expenses/Services/ReceiptBookkeeperRules.php';
        return [
            'gst_registered'         => '1',
            'receipt_vehicle_label'  => 'the Dodge Ram truck',
            'receipt_equipment_label' => 'the landscaping equipment (mowers, trimmers, blowers)',
            'receipt_vendor_example' => '"Marine Dr Town Pntry" is a Chevron station, so the vendor is Chevron;',
            'receipt_owner_rules'    => json_encode(ReceiptBookkeeperRules::mowologyRules(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** The conditions a rule may use. Anything else makes the whole rule invalid: a rule is never "mostly understood". */
    private const RULE_KEYS = ['id', 'fuel', 'no_fuel', 'text_regex', 'total_range', 'outside', 'hits'];

    /** Owner rules from their stored JSON; anything malformed or outside the owner's own categories and tags is dropped. @return list<array> */
    public static function decodeRules(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d)) return [];
        $cats = defined('EXPENSE_ACCOUNTING_CATEGORIES') ? array_values((array)constant('EXPENSE_ACCOUNTING_CATEGORIES')) : null;
        $out = [];
        foreach ($d as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || $r['id'] === '' || empty($r['hits']) || !is_array($r['hits'])) continue;
            if (array_diff(array_keys($r), self::RULE_KEYS)) continue;
            if (isset($r['fuel']) && !in_array($r['fuel'], ['diesel', 'gasoline'], true)) continue;
            if (isset($r['text_regex']) && (!is_string($r['text_regex']) || strlen($r['text_regex']) > 80 || @preg_match($r['text_regex'], '') === false)) continue;
            if (isset($r['total_range']) && (!is_array($r['total_range']) || count($r['total_range']) !== 2 || !is_numeric($r['total_range'][0] ?? null) || !is_numeric($r['total_range'][1] ?? null))) continue;
            $ok = true;
            foreach ($r['hits'] as $h) {
                $f = is_array($h) ? ($h['field'] ?? '') : '';
                if (!is_array($h) || !in_array($f, ['accounting_category', 'asset_tag'], true) || !is_string($h['value'] ?? null) || !is_string($h['reason'] ?? null)
                    || !in_array($h['strength'] ?? '', ['firm', 'soft'], true)
                    || ($f === 'asset_tag' && !in_array($h['value'], ['truck', 'equipment', 'stock', 'none'], true))
                    || ($f === 'accounting_category' && $cats !== null && !in_array($h['value'], $cats, true))) { $ok = false; break; }
            }
            if ($ok) $out[] = $r;
        }
        return $out;
    }
}
