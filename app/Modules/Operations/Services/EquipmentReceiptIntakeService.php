<?php
/**
 * EquipmentReceiptIntakeService — Otto adds machines from purchase receipts by himself (owner,
 * 2026-10-19: "capital equipment should just be put in there, he can move it out if need be").
 *
 * sweep(): every approved / sent Tools/Equipment receipt with a line of at least
 * ops_settings.otto_equipment_min_line ($300) becomes an equipment row: the line's name, a class and
 * power guessed from it (Tim corrects on the machine's page), bought on the receipt's date, the receipt
 * as its purchase (expense_id). A receipt with no line items counts as one line (its pre-tax amount).
 * Skipped: consumables and add-ons (warranty, blades, line, oil…), a receipt already linked to a machine
 * by hand, and any line Otto has already looked at (equipment_receipt_intake, migration 1377).
 * moveOut(): "Not equipment" on the machine's page — the row is removed and the line is remembered
 * as moved out, so it is never added again. Refused once the machine has history (services, runs,
 * tasks, a manual read): retire it instead.
 *
 * Tenant-neutral: the category and the threshold are data. Runs in Otto's 6-hourly cron
 * (generate_visits.php). No namespace: require_once and `new`.
 */
require_once __DIR__ . '/EquipmentService.php';

class EquipmentReceiptIntakeService
{
    public const CATEGORY = 'Tools/Equipment';
    public const SETTING_MIN = 'otto_equipment_min_line';
    public const DEFAULT_MIN = 300.0;
    /** Lines that are never a machine, however big. */
    private const NOT_A_MACHINE = '/\b(warranty|protection|service plan|eco ?fee|env(iro)? ?fee|deposit|core charge|rental|blades?|line(?! ?trim)|oil|chains?|filters?|spark plugs?|fuel|gas can|discount|coupon|shipping|delivery)\b/i';
    private const CLASS_WORDS = [
        'hedge_trimmer' => '/hedge/i',
        'mower'         => '/\bmow|mower|spmw|\blm\d|self.?prop|push mower|lawn ?mower|zero.?turn/i',
        'trimmer'       => '/trimmer|string trim|\bst\d|whipper|edger/i',
        'blower'        => '/blow|\beb\d|\bbr\d/i',
        'saw'           => '/\bsaw\b|chainsaw|pole saw|\bcs\d/i',
        'aerator'       => '/aerat/i',
        'battery_pack'  => '/\bbattery\b|\bbatt\b|\d+(\.\d)?\s?ah\b/i',
        'trailer'       => '/trailer/i',
    ];

    private PDO $db;
    private EquipmentService $equipment;

    public function __construct(PDO $db, ?EquipmentService $equipment = null)
    {
        $this->db = $db;
        $this->equipment = $equipment ?? new EquipmentService($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM equipment_receipt_intake LIMIT 1');
            $this->db->query('SELECT 1 FROM equipment LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function minLine(): float
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING_MIN]);
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v) && (float)$v > 0) return (float)$v;
        } catch (Throwable $e) { /* default */ }
        return self::DEFAULT_MIN;
    }

    /** A receipt line → is it a machine? PURE. */
    public static function isMachine(string $name, float $lineTotal, float $min): bool
    {
        return $lineTotal >= $min && trim($name) !== '' && !preg_match(self::NOT_A_MACHINE, $name);
    }

    /** The equipment class a line's name suggests ('other' when nothing fits). PURE. */
    public static function guessClass(string $name): string
    {
        foreach (self::CLASS_WORDS as $class => $re) if (preg_match($re, $name)) return $class;
        return 'other';
    }

    /** Gas when the line says so (cc, gas, 2-cycle), else battery — the register's default. PURE. */
    public static function guessPower(string $name): string
    {
        if (preg_match('/\bdiesel\b/i', $name)) return 'diesel';
        if (preg_match('/\bgas\b|\d\s?cc\b|2.?cycle|4.?cycle|stroke/i', $name)) return 'gas';
        if (preg_match('/\bcorded\b|\b120 ?v\b/i', $name)) return 'electric';
        return 'battery';
    }

    /** Receipt lines Otto has not looked at yet, machines only. */
    public function candidates(): array
    {
        $min = $this->minLine();
        $rows = $this->db->query("
            SELECT e.id AS expense_id, e.expense_date, COALESCE(v.name, e.vendor_name_raw, '') AS vendor,
                   COALESCE(li.id, 0) AS line_item_id,
                   COALESCE(li.name, e.description, '') AS line_name,
                   COALESCE(li.line_total, e.total - COALESCE(e.gst_amount, 0) - COALESCE(e.pst_amount, 0)) AS line_total
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN expense_line_items li ON li.expense_id = e.id
            WHERE e.accounting_category = " . $this->db->quote(self::CATEGORY) . "
              AND e.status IN ('approved', 'forwarded')
              -- linked to a machine by hand (not by Otto): that receipt is already in the register
              AND NOT EXISTS (SELECT 1 FROM equipment q WHERE q.expense_id = e.id
                              AND NOT EXISTS (SELECT 1 FROM equipment_receipt_intake r2 WHERE r2.equipment_id = q.id))
              AND NOT EXISTS (SELECT 1 FROM equipment_receipt_intake r WHERE r.expense_id = e.id AND r.line_item_id = COALESCE(li.id, 0))
            ORDER BY e.expense_date, e.id, li.sort_order, li.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static fn($r) => self::isMachine((string)$r['line_name'], (float)$r['line_total'], $min)));
    }

    /** Add every machine not yet in the register. @return array{added: int, items: list<array>} */
    public function sweep(): array
    {
        if (!$this->ready()) return ['added' => 0, 'items' => []];
        $out = ['added' => 0, 'items' => []];
        $mark = $this->db->prepare("INSERT INTO equipment_receipt_intake (expense_id, line_item_id, outcome, equipment_id, line_name, line_total)
                                    VALUES (?, ?, 'added', ?, ?, ?)");
        foreach ($this->candidates() as $c) {
            $name = trim(preg_replace('/\s+/', ' ', (string)$c['line_name']));
            $own = !$this->db->inTransaction();
            if ($own) $this->db->beginTransaction();
            try {
                $r = $this->equipment->save([
                    'name' => mb_substr($name, 0, 120),
                    'equipment_class' => self::guessClass($name),
                    'power_source' => self::guessPower($name),
                    'purchase_date' => substr((string)$c['expense_date'], 0, 10),
                    'expense_id' => (int)$c['expense_id'],
                    'notes' => mb_substr('Added by Otto from receipt #' . (int)$c['expense_id'] . ($c['vendor'] !== '' ? ' (' . $c['vendor'] . ')' : '')
                        . ', $' . number_format((float)$c['line_total'], 2) . '. Not a machine? "Not equipment" on this page moves it out.', 0, 500),
                ]);
                if (empty($r['ok'])) throw new RuntimeException((string)($r['message'] ?? 'not saved'));
                $mark->execute([(int)$c['expense_id'], (int)$c['line_item_id'], (int)$r['id'], mb_substr($name, 0, 255), round((float)$c['line_total'], 2)]);
                if ($own) $this->db->commit();
                $out['added']++;
                $out['items'][] = ['equipment_id' => (int)$r['id'], 'expense_id' => (int)$c['expense_id'], 'name' => $name];
            } catch (Throwable $e) {
                if ($own && $this->db->inTransaction()) $this->db->rollBack();
                error_log('[equipment intake] receipt #' . (int)$c['expense_id'] . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /** The intake row behind a machine Otto added, or null (added by hand). */
    public function addedBy(int $equipmentId): ?array
    {
        if (!$this->ready()) return null;
        $s = $this->db->prepare("SELECT * FROM equipment_receipt_intake WHERE equipment_id = ? AND outcome = 'added' LIMIT 1");
        $s->execute([$equipmentId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** "Not equipment": remove a machine Otto added; the line is never added again. */
    public function moveOut(int $equipmentId, int $userId): array
    {
        $intake = $this->addedBy($equipmentId);
        if (!$intake) return ['ok' => false, 'message' => 'Only a machine Otto added from a receipt can be moved out. Retire it instead.'];
        foreach (['equipment_service_log', 'battery_pack_runs', 'tasks', 'equipment_manual_reads'] as $t) {
            try {
                $s = $this->db->prepare("SELECT COUNT(*) FROM {$t} WHERE equipment_id = ?");
                $s->execute([$equipmentId]);
                if ((int)$s->fetchColumn() > 0) return ['ok' => false, 'message' => 'This machine already has history (services, runs or tasks). Retire it instead.'];
            } catch (Throwable $e) { /* table not there: nothing to keep */ }
        }
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM equipment_service_intervals WHERE equipment_id = ?")->execute([$equipmentId]);
            $this->db->prepare("DELETE FROM equipment WHERE id = ?")->execute([$equipmentId]);
            $this->db->prepare("UPDATE equipment_receipt_intake SET outcome = 'moved_out', equipment_id = NULL, decided_by = ? WHERE id = ?")
                     ->execute([$userId ?: null, (int)$intake['id']]);
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => 'Moved out of the register. Otto won\'t add it again.', 'redirect' => '/crm/ops/equipment.php'];
    }
}
