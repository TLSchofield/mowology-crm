<?php
/**
 * TripLineSuggester — Sam suggests a "Material pickup" / "Disposal run" line on a quote.
 *
 * Reads Otto's shared cost facts (ops_cost_facts, CostFactsService) — one small row per kind,
 * written by the overnight cron. No trail, no AI call, nothing recomputed per page load.
 *
 *   material line (mulch, soil, compost, bark…)        → Material pickup  (fact run:supplier:any)
 *   green waste / cleanup / haul-away / debris work     → Disposal run     (fact run:dump:any)
 *
 * Price = the fact's median_cost rounded UP to the next $5, only when sample_n ≥ 3; otherwise
 * "not enough runs yet (n/3)". More than one trailer load of bulk material (MaterialDeliveryService)
 * suggests "Delivery $150" instead of a pickup: one Lawnboy drop replaces the trailer runs, and the
 * client pays for it because fetching it ourselves costs crew time (owner, 2026-10-10).
 * A suggestion only: Sam never adds a line. GST is added on top of the
 * price at invoicing, never included in it. Skipped when the quote already has that line.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Operations/Services/CostFactsService.php';
require_once dirname(__DIR__, 2) . '/Operations/Services/MaterialDeliveryService.php';

class TripLineSuggester
{
    public const MIN_SAMPLE = 3;
    public const MATERIAL_WORDS = ['mulch', 'soil', 'compost', 'bark', 'garden mix', 'wood chip', 'gravel', 'sod'];
    public const DISPOSAL_WORDS = ['green waste', 'yard waste', 'cleanup', 'clean-up', 'clean up', 'haul', 'debris', 'disposal', 'dump'];

    public const RULES = [
        'material_pickup' => ['label' => 'Material pickup', 'fact' => 'run:supplier:any', 'kind' => 'supplier',
                              'why' => 'Picking up the material is a trip of its own'],
        'disposal_run'    => ['label' => 'Disposal run', 'fact' => 'run:dump:any', 'kind' => 'dump',
                              'why' => 'The waste has to go to the transfer station'],
    ];

    private PDO $db;
    private CostFactsService $facts;

    public function __construct(PDO $db, ?CostFactsService $facts = null)
    {
        $this->db = $db;
        $this->facts = $facts ?? new CostFactsService($db);
    }

    // ── Pure (unit tested) ──────────────────────────────────────────────────

    public static function label(array $line): string
    {
        return strtolower(trim((string)($line['service_type'] ?? '') . ' ' . (string)($line['description'] ?? '') . ' ' . (string)($line['product_name'] ?? '')));
    }

    private static function has(string $text, array $words): ?string
    {
        foreach ($words as $w) if (strpos($text, $w) !== false) return $w;
        return null;
    }

    /**
     * Which trip lines this quote needs and the quote line that triggered each.
     * @return array<string, string> rule key => triggering line label (original case)
     */
    public static function needs(array $lines): array
    {
        $out = []; $already = [];
        foreach ($lines as $l) {
            $t = self::label($l);
            if (strpos($t, 'material pickup') !== false) { $already['material_pickup'] = true; continue; }
            if (strpos($t, 'disposal run') !== false) { $already['disposal_run'] = true; continue; }
            $shown = trim((string)($l['service_type'] ?? '')) ?: trim((string)($l['description'] ?? ''));
            if (!isset($out['material_pickup']) && self::has($t, self::MATERIAL_WORDS)) $out['material_pickup'] = $shown;
            if (!isset($out['disposal_run']) && self::has($t, self::DISPOSAL_WORDS)) $out['disposal_run'] = $shown;
        }
        return array_diff_key($out, $already);
    }

    /** Median cost rounded up to the next $5. */
    public static function roundUp5(float $v): float
    {
        return (float)(ceil(round($v, 2) / 5) * 5);
    }

    /**
     * One suggestion from a rule and its fact (null when the fact is missing).
     * @return array{key, label, because, price: ?float, sample_n: int, needed: int, ready: bool, text: string, basis: ?string}
     */
    public static function suggestion(string $key, string $because, ?array $fact): array
    {
        $rule = self::RULES[$key];
        $n = (int)($fact['sample_n'] ?? 0);
        $ready = $fact !== null && $n >= self::MIN_SAMPLE && $fact['median_cost'] !== null && (float)$fact['median_cost'] > 0;
        $price = $ready ? self::roundUp5((float)$fact['median_cost']) : null;
        $basis = null;
        if ($ready) {
            $basis = 'Median of ' . $n . ' one-man ' . ($rule['kind'] === 'dump' ? 'dump' : 'supply') . ' runs: '
                . (int)round((float)$fact['median_round_trip_min']) . ' min, ' . rtrim(rtrim(number_format((float)$fact['median_km'], 1), '0'), '.') . ' km'
                . ($rule['kind'] === 'dump' && $fact['median_receipt'] !== null ? ', dump fee $' . number_format((float)$fact['median_receipt'], 0) : '')
                . ' → $' . number_format((float)$fact['median_cost'], 2);
        }
        return [
            'key' => $key, 'label' => $rule['label'], 'because' => $because,
            'price' => $price, 'sample_n' => $n, 'needed' => self::MIN_SAMPLE, 'ready' => $ready,
            'text' => $ready ? $rule['label'] . ' $' . number_format((float)$price, 0) . ' + GST'
                             : $rule['label'] . ' — not enough runs yet (' . min($n, self::MIN_SAMPLE) . '/' . self::MIN_SAMPLE . ')',
            'basis' => $basis,
            'why' => $rule['why'],
        ];
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function lines(int $quoteId): array
    {
        try {
            $s = $this->db->prepare("
                SELECT q.service_type, q.description, q.quantity, q.unit_type, COALESCE(p.name, '') AS product_name
                FROM quote_line_items q LEFT JOIN products p ON p.id = q.product_id
                WHERE q.quote_id = ? ORDER BY q.sort_order, q.id
            ");
            $s->execute([$quoteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Suggestions for a quote — empty when it has no material or waste work. */
    public function forQuote(int $quoteId): array
    {
        $lines = $this->lines($quoteId);
        $out = [];
        $delivery = null;
        $hasDeliveryLine = false;
        foreach ($lines as $l) if (strpos(self::label($l), 'deliver') !== false) $hasDeliveryLine = true;
        try {
            $mds = new MaterialDeliveryService($this->db);
            $d = $mds->forLines($lines);
            if ($d && $d['method'] === 'delivery') $delivery = self::deliverySuggestion($d, (float)$mds->setting('material_delivery_charge'));
        } catch (Throwable $e) { /* fall back to the pickup suggestion */ }
        foreach (self::needs($lines) as $key => $because) {
            if ($key === 'material_pickup' && ($delivery || $hasDeliveryLine)) continue;   // delivered, not fetched
            $out[] = self::suggestion($key, $because, $this->facts->get(self::RULES[$key]['fact']));
        }
        if ($delivery && !$hasDeliveryLine) array_unshift($out, $delivery);
        return $out;
    }

    /** "Delivery $150 + GST" for a job whose material Lawnboy should drop off. */
    public static function deliverySuggestion(array $d, float $charge): array
    {
        return [
            'key' => 'delivery', 'label' => 'Delivery', 'because' => $d['what'],
            'price' => $charge, 'sample_n' => 0, 'needed' => 0, 'ready' => true,
            'text' => 'Delivery $' . number_format($charge, 0) . ' + GST',
            'basis' => $d['why'] . '. ' . $d['vendor'] . ' charges us $' . number_format((float)$d['delivery_cost'], 0) . '; we charge $' . number_format($charge, 0) . '.',
            'why' => 'More than a trailer load — ' . $d['vendor'] . ' delivers it in one drop',
        ];
    }
}
