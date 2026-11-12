<?php
/**
 * MaterialDeliveryService — Otto decides how bulk material gets to a job: Lawnboy delivers it,
 * or the crew fetches it with the trailer (owner, 2026-10-10).
 *
 * What the books showed: the trailer carries 2 yards a trip (every Lawnboy mulch pickup is 2 yd);
 * Lawnboy delivered rock + crusher, 6 yards, in ONE drop for $73 (Gary Hughes, Apr 2 2026), and
 * the client was charged $150 per delivery. Lawnboy wants 2–3 days' notice — Otto uses 3.
 *
 *   - bulkLines() / totalYards(): mulch, bark, soil, compost, rock, gravel, crusher, sand… in
 *     yards. A line with no unit, or "each", counts its quantity as yards (marked approximate).
 *   - decide(): delivery when it is more than one trailer load, or when the trailer runs cost
 *     more than the delivery (Otto's median supply-run cost, CostFactsService); else trailer.
 *   - orderBy(): the job's date minus the notice days.
 *   - briefItems(): for every placed job in the next 3 weeks that needs a delivery, an Otto item
 *     "Order Lawnboy delivery by …", top priority once the order-by day has come. On a building
 *     with a property manager it adds "agree the drop spot with <PM>". "Handled" = Charlie's
 *     dismiss on the item's key, like every brief item.
 *   - unplacedItems(): the other half (Tim, 2026-11-11). A receipt for material on a job (expenses.job_id
 *     = the plan, even while still pending approval) with no visit booked: "bought, nothing scheduled —
 *     what date is the install?". It stays until a visit is placed; a job with a receipt is also no longer
 *     told to "order" (it was ordered). Otto's brief and card get both through briefItems().
 * Sam's side: TripLineSuggester suggests a "Delivery $150" line on a quote with more than a
 * trailer load of bulk material (we charge it because doing it ourselves costs crew time).
 *
 * Settings (ops_settings, migration 1317): material_delivery_vendor, material_delivery_cost,
 * material_delivery_charge, material_delivery_notice_days, trailer_capacity_yards.
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class MaterialDeliveryService
{
    public const BULK_WORDS = ['mulch', 'bark', 'soil', 'compost', 'garden mix', 'rock', 'gravel', 'crusher', 'sand', 'wood chip', 'aggregate', 'river rock'];
    public const YARD_UNITS = ['yd', 'yds', 'yard', 'yards', 'cu yd', 'cubic yard', 'cubic yards', 'cy'];
    public const DEFAULTS = [
        'material_delivery_vendor'       => 'Lawnboy',
        'material_delivery_cost'         => '73',
        'material_delivery_charge'       => '150',
        'material_delivery_notice_days'  => '3',
        'trailer_capacity_yards'         => '2',
    ];

    private PDO $db;
    private ?array $settings = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The bulk-material lines and their yards.
     * Lines: service_type / name / description, quantity, unit_type. Delivery / pickup lines are skipped.
     * @return array<int, array{label:string, yards:float, approx:bool}>
     */
    public static function bulkLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (!empty($l['client_declined'])) continue;
            $label = trim((string)($l['service_type'] ?? $l['name'] ?? '')) ?: trim((string)($l['description'] ?? ''));
            $text = strtolower($label . ' ' . (string)($l['description'] ?? ''));
            if (preg_match('/\bdeliver|pickup|pick-up|disposal|dump\b/', strtolower($label))) continue;
            $hit = false;
            foreach (self::BULK_WORDS as $w) if (strpos($text, $w) !== false) { $hit = true; break; }
            if (!$hit) continue;
            $unit = strtolower(trim((string)($l['unit_type'] ?? '')));
            $qty = (float)($l['quantity'] ?? 1);
            if ($qty <= 0) $qty = 1;
            $isYards = in_array($unit, self::YARD_UNITS, true);
            if (!$isYards && $unit !== '' && $unit !== 'each' && $unit !== 'ea') continue;   // bags, sq ft … aren't bulk yards
            $out[] = ['label' => $label, 'yards' => round($qty, 2), 'approx' => !$isYards];
        }
        return $out;
    }

    public static function totalYards(array $bulk): float
    {
        return round(array_sum(array_column($bulk, 'yards')), 2);
    }

    /**
     * Delivery or trailer, with the numbers that decided it.
     * @return array{method:string, loads:int, trailer_cost:?float, delivery_cost:float, saving:?float, why:string}
     */
    public static function decide(float $yards, float $trailerCap, ?float $tripCost, float $deliveryCost): array
    {
        $cap = $trailerCap > 0 ? $trailerCap : 2.0;
        $loads = max(1, (int)ceil(round($yards / $cap, 4)));
        $trailerCost = $tripCost !== null ? round($loads * $tripCost, 2) : null;
        $delivery = $loads > 1 || ($trailerCost !== null && $trailerCost > $deliveryCost);
        $saving = $trailerCost !== null ? round($trailerCost - $deliveryCost, 2) : null;
        $runs = $loads === 1 ? '1 trailer run' : $loads . ' trailer runs';
        $why = $delivery
            ? 'One drop ($' . number_format($deliveryCost, 0) . ') instead of ' . $runs
              . ($trailerCost !== null ? ' (about $' . number_format($trailerCost, 0) . ' of truck and crew time)' : '')
            : 'It fits in ' . $runs . ($trailerCost !== null ? ' (about $' . number_format($trailerCost, 0) . ')' : '') . ' — cheaper than a delivery';
        return ['method' => $delivery ? 'delivery' : 'trailer', 'loads' => $loads, 'trailer_cost' => $trailerCost,
                'delivery_cost' => $deliveryCost, 'saving' => $saving, 'why' => $why];
    }

    /** The last day to order: the job's date minus the notice days. */
    public static function orderBy(string $jobDate, int $noticeDays): string
    {
        return date('Y-m-d', strtotime($jobDate . ' -' . max(0, $noticeDays) . ' days'));
    }

    /** "4 yd Black Composted Bark Mulch, about 1 yd Flowerbed Soil" */
    public static function describe(array $bulk): string
    {
        return implode(', ', array_map(function ($b) {
            $y = rtrim(rtrim(number_format((float)$b['yards'], 2), '0'), '.');
            return ($b['approx'] ? 'about ' : '') . $y . ' yd ' . $b['label'];
        }, $bulk));
    }

    /** Is this receipt the bulk-material order? The material vendor (Lawnboy) or the Materials category. */
    public static function isMaterialReceipt(string $vendor, string $category, string $materialVendor): bool
    {
        if ($materialVendor !== '' && stripos($vendor, $materialVendor) !== false) return true;
        return strcasecmp(trim($category), 'Materials') === 0;
    }

    /**
     * Pure: "bought, nothing scheduled" items, one per job.
     * @param array $rows receipts on open one-off jobs with no booked visit: eid, expense_date, total, status, vendor, accounting_category, plan_id, address
     */
    public static function unplacedItems(array $rows, string $materialVendor, string $today): array
    {
        $by = [];
        foreach ($rows as $r) {
            if (!self::isMaterialReceipt((string)($r['vendor'] ?? ''), (string)($r['accounting_category'] ?? ''), $materialVendor)) continue;
            $id = (int)$r['plan_id'];
            if (!isset($by[$id])) $by[$id] = ['plan_id' => $id, 'address' => (string)($r['address'] ?? ''), 'date' => (string)$r['expense_date'], 'total' => 0.0, 'pending' => false, 'vendor' => (string)($r['vendor'] ?? '')];
            $by[$id]['total'] += (float)($r['total'] ?? 0);
            $by[$id]['pending'] = $by[$id]['pending'] || (string)($r['status'] ?? '') === 'pending_approval';
            if ((string)$r['expense_date'] < $by[$id]['date']) $by[$id]['date'] = (string)$r['expense_date'];
        }
        $out = [];
        foreach ($by as $b) {
            $days = (int)floor((strtotime($today) - strtotime($b['date'])) / 86400);
            $out[] = [
                'key'      => 'otto:ordered_unplaced:' . $b['plan_id'],
                'kind'     => 'ordered_unplaced',
                'text'     => ($b['vendor'] !== '' ? $b['vendor'] : 'Material') . ' material was bought ' . date('D M j', strtotime($b['date']))
                            . ' ($' . number_format($b['total'], 2) . ($b['pending'] ? ', receipt not approved yet' : '') . ')'
                            . ' for ' . $b['address'] . ', but no visit is booked. What date is the install?'
                            . ($days >= 3 ? ' It has been ' . $days . ' days.' : ''),
                'url'      => '/crm/jobs/view.php?id=' . $b['plan_id'],
                'priority' => $days >= 3 ? 1 : 2,   // top priority (the card's alert style) once it has sat 3 days
                'value'    => $b['total'],
                'since'    => $b['date'],
            ];
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function setting(string $key): string
    {
        if ($this->settings === null) {
            $this->settings = self::DEFAULTS;
            try {
                $in = implode(',', array_fill(0, count(self::DEFAULTS), '?'));
                $st = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ($in)");
                $st->execute(array_keys(self::DEFAULTS));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['setting_value'] !== null && $r['setting_value'] !== '') $this->settings[$r['setting_key']] = (string)$r['setting_value'];
                }
            } catch (Throwable $e) { /* defaults */ }
        }
        return $this->settings[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /** Otto's median one-man supply run (labour + truck), when he has enough runs. */
    public function tripCost(): ?float
    {
        try {
            if (!class_exists('CostFactsService')) require_once __DIR__ . '/CostFactsService.php';
            $f = (new CostFactsService($this->db))->get('run:supplier:any');
            if ($f && (int)$f['sample_n'] >= 3 && $f['median_cost'] !== null && (float)$f['median_cost'] > 0) return (float)$f['median_cost'];
        } catch (Throwable $e) { /* not enough runs */ }
        return null;
    }

    /** The decision for a set of lines (quote or plan), or null when there is no bulk material. */
    public function forLines(array $lines): ?array
    {
        $bulk = self::bulkLines($lines);
        if (!$bulk) return null;
        $yards = self::totalYards($bulk);
        $d = self::decide($yards, (float)$this->setting('trailer_capacity_yards'), $this->tripCost(), (float)$this->setting('material_delivery_cost'));
        return $d + ['bulk' => $bulk, 'yards' => $yards, 'what' => self::describe($bulk), 'vendor' => $this->setting('material_delivery_vendor')];
    }

    /**
     * Otto's brief: placed one-off jobs in the next 3 weeks whose material should be delivered.
     * @return array<int, array{key:string, kind:string, text:string, url:string, priority:int, value:?float, since:string}>
     */
    public function briefItems(string $today): array
    {
        return array_merge($this->deliveryItems($today), $this->unplacedBrief($today));
    }

    /** Receipts for material on open one-off jobs that have no visit booked (placed from today on, or done). Never rejected ones. */
    private function unplacedBrief(string $today): array
    {
        try {
            $st = $this->db->prepare("
                SELECT e.id AS eid, e.expense_date, e.total, e.status, e.accounting_category, COALESCE(v.name, e.vendor_name_raw) AS vendor,
                       jp.id AS plan_id, p.address
                FROM expenses e
                JOIN job_plans jp ON jp.id = e.job_id
                JOIN properties p ON p.id = jp.property_id
                LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.status <> 'rejected' AND e.expense_date >= DATE_SUB(?, INTERVAL 45 DAY)
                  AND jp.status = 'active' AND jp.is_recurring = 0
                  AND NOT EXISTS (
                      SELECT 1 FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status NOT IN ('cancelled', 'skipped')
                        AND (jv.status <> 'scheduled' OR (jv.stop_id IS NOT NULL AND jv.scheduled_date >= ?)))
                ORDER BY e.expense_date
            ");
            $st->execute([$today, $today]);
            return self::unplacedItems($st->fetchAll(PDO::FETCH_ASSOC) ?: [], $this->setting('material_delivery_vendor'), $today);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Plans that already have a material receipt: the order was made, so no "order by" for them. @return array<int,true> */
    private function orderedPlans(array $planIds): array
    {
        $planIds = array_values(array_unique(array_filter(array_map('intval', $planIds))));
        if (!$planIds) return [];
        try {
            $st = $this->db->query("
                SELECT e.job_id, e.accounting_category, COALESCE(v.name, e.vendor_name_raw) AS vendor
                FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.status <> 'rejected' AND e.job_id IN (" . implode(',', $planIds) . ")");
            $out = [];
            $mv = $this->setting('material_delivery_vendor');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (self::isMaterialReceipt((string)$r['vendor'], (string)$r['accounting_category'], $mv)) $out[(int)$r['job_id']] = true;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function deliveryItems(string $today): array
    {
        try {
            $rows = $this->db->prepare("
                SELECT jv.id AS visit_id, jv.scheduled_date, jp.id AS plan_id, jp.plan_number, jp.property_id, p.address
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties p ON p.id = jp.property_id
                WHERE jv.status = 'scheduled' AND jv.stop_id IS NOT NULL AND jp.status = 'active' AND jp.is_recurring = 0
                  AND jv.scheduled_date BETWEEN ? AND DATE_ADD(?, INTERVAL 21 DAY)
                ORDER BY jv.scheduled_date
            ");
            $rows->execute([$today, $today]);
            $visits = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $ordered = $this->orderedPlans(array_column($visits, 'plan_id'));
        $notice = (int)$this->setting('material_delivery_notice_days');
        $vendor = $this->setting('material_delivery_vendor');
        $out = [];
        foreach ($visits as $v) {
            if (isset($ordered[(int)$v['plan_id']])) continue;   // already ordered: a material receipt is on the job
            $lines = $this->db->prepare("SELECT service_type, description, quantity, unit_type FROM plan_line_items WHERE plan_id = ?");
            $lines->execute([(int)$v['plan_id']]);
            $d = $this->forLines($lines->fetchAll(PDO::FETCH_ASSOC) ?: []);
            if (!$d || $d['method'] !== 'delivery') continue;
            $by = self::orderBy((string)$v['scheduled_date'], $notice);
            $late = $by < $today;
            $pm = $this->propertyManager((int)$v['property_id']);
            $when = date('D M j', strtotime((string)$v['scheduled_date']));
            $text = ($late ? 'Order NOW — ' : 'Order ') . $vendor . ' delivery' . ($late ? '' : ' by ' . date('D M j', strtotime($by)))
                  . ' for ' . $when . ' at ' . $v['address'] . ': ' . $d['what'] . '. ' . $d['why'] . '.'
                  . ($late ? ' Less than ' . $notice . " days' notice — check " . $vendor . ' can make it, or move the job.' : '')
                  . ($pm !== '' ? ' Agree the drop spot with ' . $pm . ' (property manager).' : '');
            $out[] = [
                'key'      => 'otto:delivery:' . (int)$v['visit_id'],
                'kind'     => 'delivery',
                'text'     => $text,
                'url'      => '/crm/jobs/view.php?id=' . (int)$v['plan_id'],
                'priority' => $by <= $today ? 1 : ($by <= date('Y-m-d', strtotime($today . ' +3 days')) ? 2 : 3),
                'value'    => $d['delivery_cost'],
                'since'    => $today,
            ];
        }
        return $out;
    }

    private function propertyManager(int $propertyId): string
    {
        try {
            $st = $this->db->prepare("
                SELECT TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))
                FROM property_contacts pc JOIN contacts c ON c.id = pc.contact_id
                WHERE pc.property_id = ? AND pc.contact_role = 'manager' ORDER BY pc.is_primary DESC, pc.id LIMIT 1
            ");
            $st->execute([$propertyId]);
            return (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }
}
