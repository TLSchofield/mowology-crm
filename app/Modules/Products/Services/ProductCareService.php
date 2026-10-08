<?php
/**
 * ProductCareService — Otto's "how to look after it": what the shop holds and what the kit needs.
 *
 *   machines()   each active machine: its intervals (Tim's numbers), what is due from job hours
 *                (EquipmentService::suggestionItems — the same estimate Otto already uses), and
 *                intervals Otto read from a manual waiting for Tim's confirm.
 *   lowStock()   tracked products at or under their reorder point. Stock is never deducted
 *                automatically (there is no usage data per job); it rises when a receipt line
 *                is linked to the product.
 *   care()       products with storage / handling / safety notes — read from their LABEL only —
 *                and their SDS link. Chemicals and fertilisers without an SDS ask for one;
 *                Tim supplies the URL (saveSds), nothing is looked up.
 *
 * No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/ProductProposalService.php';

class ProductCareService
{
    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    private function hasCol(string $table, string $col): bool
    {
        try { $this->db->query("SELECT {$col} FROM {$table} LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }

    public function lowStock(int $limit = 12): array
    {
        try {
            $rows = $this->db->query("SELECT id, name, sku, current_stock, reorder_point FROM products
                                      WHERE track_inventory = 1 AND current_stock <= reorder_point ORDER BY current_stock - reorder_point, name LIMIT " . max(1, $limit))
                             ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $active = $this->hasCol('products', 'active');
        $out = [];
        foreach ($rows as $r) {
            if ($active) {
                $a = $this->db->prepare("SELECT active FROM products WHERE id = ?");
                $a->execute([(int)$r['id']]);
                if ((int)$a->fetchColumn() === 0) continue;
            }
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'sku' => $r['sku'],
                      'stock' => (float)$r['current_stock'], 'reorder_point' => (float)$r['reorder_point'],
                      'say' => self::stockLine((string)$r['name'], (float)$r['current_stock'], (float)$r['reorder_point'])];
        }
        return $out;
    }

    public static function stockLine(string $name, float $stock, float $reorder): string
    {
        $n = rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.');
        if ($stock <= 0) return $name . ': none left.';
        return $name . ': ' . $n . ' left' . ($reorder > 0 ? ' (reorder at ' . rtrim(rtrim(number_format($reorder, 2, '.', ''), '0'), '.') . ')' : '') . '.';
    }

    public function care(int $limit = 20): array
    {
        $hasCare = $this->hasCol('products', 'care_notes');
        $hasSds = $this->hasCol('products', 'sds_sheet_url');
        $hasSafe = $this->hasCol('products', 'safety_warnings');
        $hasLabel = $this->hasCol('products', 'label_media_id');
        if (!$hasCare && !$hasSds) return [];
        $where = [];
        if ($hasCare) $where[] = "(care_notes IS NOT NULL AND care_notes <> '')";
        if ($hasSds) $where[] = "(sds_sheet_url IS NOT NULL AND sds_sheet_url <> '')";
        if ($hasLabel) $where[] = "label_media_id IS NOT NULL";
        try {
            $rows = $this->db->query("SELECT * FROM products WHERE " . implode(' OR ', $where) . " ORDER BY name LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $text = (string)$r['name'] . ' ' . (string)($r['description'] ?? '');
            $sds = $hasSds ? trim((string)($r['sds_sheet_url'] ?? '')) : '';
            $out[] = [
                'id' => (int)$r['id'], 'name' => (string)$r['name'],
                'care' => $hasCare ? trim((string)($r['care_notes'] ?? '')) : '',
                'safety' => $hasSafe ? trim((string)($r['safety_warnings'] ?? '')) : '',
                'sds_url' => $sds !== '' ? $sds : null,
                'needs_sds' => $sds === '' && ProductProposalService::isChemical($text),
                'label_photo' => $hasLabel && !empty($r['label_media_id']) ? ProductProposalService::photoUrl((int)$r['label_media_id']) : null,
                'photo_marketing_ok' => (int)($r['photo_marketing_ok'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    public function saveSds(int $productId, string $url): array
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) return ['ok' => false, 'message' => 'Give a web link starting with https://'];
        if (!$this->hasCol('products', 'sds_sheet_url')) return ['ok' => false, 'message' => 'Products have no SDS column (migration 600).'];
        $this->db->prepare("UPDATE products SET sds_sheet_url = ? WHERE id = ?")->execute([$url !== '' ? mb_substr($url, 0, 500) : null, $productId]);
        return ['ok' => true, 'message' => $url !== '' ? 'SDS linked.' : 'SDS link removed.'];
    }

    public function setMarketingOk(int $productId, bool $ok): array
    {
        if (!$this->hasCol('products', 'photo_marketing_ok')) return ['ok' => false, 'message' => 'Needs migration 1225.'];
        $this->db->prepare("UPDATE products SET photo_marketing_ok = ? WHERE id = ?")->execute([$ok ? 1 : 0, $productId]);
        return ['ok' => true, 'message' => $ok ? 'Mia may use this picture.' : 'Picture kept internal.'];
    }

    /**
     * Where one interval stands: due (past it), soon (80% of the way) or ok.
     * $hours / $days = used since the last service (or since purchase).
     */
    public static function intervalState(?float $everyH, ?int $everyD, float $hours, int $days): string
    {
        if (($everyH && $hours >= $everyH) || ($everyD && $days >= $everyD)) return 'due';
        if (($everyH && $hours >= 0.8 * $everyH) || ($everyD && $days >= 0.8 * $everyD)) return 'soon';
        return 'ok';
    }

    /** Active machines with intervals and where each stands (job-timer hours), plus manual-read suggestions waiting. */
    public function machines(): array
    {
        require_once dirname(__DIR__, 2) . '/Operations/Services/EquipmentService.php';
        require_once dirname(__DIR__, 2) . '/Operations/Services/OttoManualService.php';
        $eq = new EquipmentService($this->db, $this->today);
        try { $this->db->query("SELECT 1 FROM equipment LIMIT 1"); } catch (Throwable $e) { return []; }   // migration 1155
        $today = $this->today ?? date('Y-m-d');
        try {
            $items = $eq->items(true);
            $intervals = $eq->intervals();
            $last = [];
            foreach ($this->db->query("SELECT equipment_id, task, MAX(done_on) AS d FROM equipment_service_log GROUP BY equipment_id, task")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $last[(int)$r['equipment_id']][strtolower((string)$r['task'])] = (string)$r['d'];
            }
        } catch (Throwable $e) {
            return [];
        }
        $pending = (new OttoManualService($this->db, null, $this->today))->pending();
        $out = [];
        foreach ($items as $it) {
            if ($it['equipment_class'] === 'truck') continue;
            $start = $it['purchase_date'] ?: substr((string)$it['created_at'], 0, 10);
            $rows = [];
            foreach (DispatchRules::intervalsFor($it, $intervals) as $iv) {
                $h = $iv['every_hours'] !== null ? (float)$iv['every_hours'] : null;
                $d = $iv['every_days'] !== null ? (int)$iv['every_days'] : null;
                $l = $last[(int)$it['id']][strtolower(trim((string)$iv['task']))] ?? null;
                $from = $l ?? $start;
                $hours = $eq->hoursSince($it, $from) + ($l === null ? (float)$it['hours_baseline'] : 0.0);
                $days = (int)floor((strtotime($today) - strtotime($from)) / 86400);
                $rows[] = ['task' => (string)$iv['task'], 'every' => OttoManualService::every($h, $d), 'state' => self::intervalState($h, $d, $hours, $days),
                           'hours' => round($hours, 1), 'days' => $days, 'last' => $l];
            }
            $out[] = [
                'id' => (int)$it['id'], 'name' => (string)$it['name'], 'class' => (string)$it['equipment_class'],
                'make' => $it['make'], 'model' => $it['model'], 'serial_no' => $it['serial_no'], 'intervals' => $rows,
                'manual' => array_map(fn($p) => $p + ['every' => OttoManualService::every($p['every_hours'] ?? null, $p['every_days'] ?? null)], $pending[(int)$it['id']] ?? []),
            ];
        }
        return $out;
    }

    /**
     * Otto's brief items (Action Board, phone cards): machines due / nearly due for service,
     * stock running low, machine proposals waiting. Shape: {key, kind, value, since, text, url, priority}.
     */
    public function briefItems(): array
    {
        $today = $this->today ?? date('Y-m-d');
        $out = [];
        try {
            foreach ($this->machines() as $m) {
                $due = array_values(array_filter($m['intervals'], fn($i) => $i['state'] === 'due'));
                $soon = array_values(array_filter($m['intervals'], fn($i) => $i['state'] === 'soon'));
                if (!$due && !$soon) continue;
                $list = $due ?: $soon;
                $out[] = [
                    'key' => 'otto:service:' . $m['id'] . ':' . ($due ? 'due' : 'soon'), 'kind' => $due ? 'service_due' : 'service_soon',
                    'value' => count($list), 'since' => $today, 'priority' => $due ? 2 : 3,
                    'text' => $m['name'] . ($due ? ' is due for service: ' : ' is nearly due for service: ')
                            . implode(', ', array_map(fn($i) => mb_strtolower($i['task']), $list)) . '.',
                    'url' => '/crm/ops/equipment.php#eq-' . $m['id'],
                ];
            }
        } catch (Throwable $e) { /* additive only */ }
        $low = $this->lowStock(20);
        if ($low) {
            $out[] = [
                'key' => 'otto:low_stock:' . $today, 'kind' => 'low_stock', 'value' => count($low), 'since' => $today, 'priority' => 3,
                'text' => count($low) === 1 ? 'Low stock — ' . $low[0]['say']
                        : count($low) . ' stock items are low: ' . implode(', ', array_map(fn($l) => $l['name'], array_slice($low, 0, 3))) . (count($low) > 3 ? '…' : '.'),
                'url' => '/crm/dashboard_appstack.php#mw-otto-care',
            ];
        }
        try {
            $n = (new ProductProposalService($this->db, null, $today))->count('otto');
            if ($n > 0) {
                $out[] = ['key' => 'otto:machine_proposals', 'kind' => 'machine_proposals', 'value' => $n, 'since' => $today, 'priority' => 3,
                          'text' => $n . ' machine' . ($n === 1 ? '' : 's') . ' from label photos waiting to be added to equipment.',
                          'url' => '/crm/dashboard_appstack.php#mw-otto-care'];
            }
        } catch (Throwable $e) { /* additive only */ }
        return $out;
    }
}
