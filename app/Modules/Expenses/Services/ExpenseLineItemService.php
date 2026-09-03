<?php
/**
 * ExpenseLineItemService — every mutation of a persisted expense_line_items row:
 * update (rename / qty / price), add, delete, and product linking.
 *
 * Shared by the web edit modal (expenses.php) and the iOS/JWT endpoint
 * (expense-line-items.php). Every mutation is also a LEARNING SIGNAL for the
 * receipt parser: a rename records a `line_item_name` lesson (OCR misread → what
 * it should say), a delete records `line_item_noise` (the parser captured a line
 * that isn't an item), an add records `line_item_missed`, and a product link
 * teaches the vendor catalog and the SKU → product memory. Before this, only the
 * link action taught anything, and nothing changed how the same vendor's next
 * receipt was split into items.
 *
 * Deliberately does not touch the expense header's Subtotal/Total — those are
 * independently staff-verified fields (see handleUpdate()).
 *
 * Global-namespace (no production autoloader): require_once the file and
 * `new ExpenseLineItemService($db)`.
 */

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}
require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';

class ExpenseLineItemService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure helpers (unit-tested) ─────────────────────────────────────

    /** Which lesson (if any) a rename should record. Null when nothing changed. */
    public static function lessonForRename(?string $ocrName, string $oldName, string $newName): ?array
    {
        $from = trim((string)($ocrName !== null && $ocrName !== '' ? $ocrName : $oldName));
        $to   = trim($newName);
        if ($from === '' || $to === '' || strtoupper($from) === strtoupper($to)) {
            return null;
        }
        return ['type' => 'line_item_name', 'ocr_value' => $from, 'corrected_value' => $to];
    }

    /** Resolve a line total from explicit total / qty×unit / existing, mirroring the web rules. */
    public static function resolveLineTotal($explicitTotal, bool $qtyOrPriceChanged, ?float $unitPrice, float $qty, float $existingTotal): float
    {
        if ($explicitTotal !== null && $explicitTotal !== '') {
            return (float)$explicitTotal;
        }
        if ($qtyOrPriceChanged && $unitPrice !== null) {
            return round($unitPrice * $qty, 2);
        }
        return $existingTotal;
    }

    // ── Mutations ──────────────────────────────────────────────────────

    /**
     * @param int   $lineItemId
     * @param array $input  name, quantity, unit_price (nullable), line_total (nullable)
     * @return array Updated line item row, joined with product_name/product_sku
     * @throws Exception if the row doesn't exist or the name is blank
     */
    public function update(int $lineItemId, array $input): array
    {
        $existing = $this->fetchWithVendor($lineItemId);

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new Exception('Item name required');
        }

        $qtyProvided = array_key_exists('quantity', $input);
        $qty = $qtyProvided ? (float)$input['quantity'] : (float)$existing['quantity'];

        // array_key_exists (not isset) so an explicit null/'' clears the
        // stored unit_price, while an absent key just keeps the existing one.
        $unitPriceProvided = array_key_exists('unit_price', $input);
        if ($unitPriceProvided) {
            $unitPrice = $input['unit_price'] !== '' && $input['unit_price'] !== null
                ? (float)$input['unit_price']
                : null;
        } else {
            $unitPrice = $existing['unit_price'] !== null ? (float)$existing['unit_price'] : null;
        }

        $lineTotal = self::resolveLineTotal(
            $input['line_total'] ?? null,
            $qtyProvided || $unitPriceProvided,
            $unitPrice,
            $qty,
            (float)$existing['line_total']
        );

        $this->db->prepare("
            UPDATE expense_line_items
            SET name = ?, quantity = ?, unit_price = ?, line_total = ?
            WHERE id = ?
        ")->execute([$name, $qty, $unitPrice, $lineTotal, $lineItemId]);

        // Re-sync inventory if quantity changed on a linked product
        if (!empty($existing['product_id'])) {
            $qtyDelta = $qty - (float)$existing['quantity'];
            if ($qtyDelta != 0) {
                updateProductInventory($this->db, (int)$existing['product_id'], $qtyDelta);
            }
        }

        // Learning: a rename teaches the parser what this OCR line really says.
        $lesson = self::lessonForRename($existing['ocr_name'] ?? null, (string)$existing['name'], $name);
        if ($lesson && !empty($existing['vendor_id'])) {
            recordLineItemLesson($this->db, (int)$existing['vendor_id'], $existing['vendor_name'] ?? null, $lesson['type'], $lesson['ocr_value'], $lesson['corrected_value']);
            updateLineItemProfileStats($this->db, (int)$existing['vendor_id'], 0, 1);
        }

        return $this->fetchJoined($lineItemId);
    }

    /**
     * Add a manual line item (something OCR missed). Records a `line_item_missed`
     * lesson, teaches the catalog + SKU memory when a product is supplied.
     */
    public function add(int $expenseId, array $input): array
    {
        if (!$expenseId) {
            throw new Exception('Expense ID required');
        }
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new Exception('Item name required');
        }

        $qty       = (float)($input['quantity'] ?? 1);
        $unitPrice = isset($input['unit_price']) && $input['unit_price'] !== '' && $input['unit_price'] !== null ? (float)$input['unit_price'] : null;
        $lineTotal = isset($input['line_total']) && $input['line_total'] !== '' && $input['line_total'] !== null ? (float)$input['line_total']
                   : ($unitPrice !== null ? round($unitPrice * $qty, 2) : 0.0);
        $productId = !empty($input['product_id']) ? (int)$input['product_id'] : null;
        $skuRaw    = isset($input['sku_raw']) && trim((string)$input['sku_raw']) !== '' ? mb_substr(trim((string)$input['sku_raw']), 0, 64) : null;

        $sortStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM expense_line_items WHERE expense_id = ?");
        $sortStmt->execute([$expenseId]);
        $sortOrder = (int)$sortStmt->fetchColumn();

        $this->db->prepare("
            INSERT INTO expense_line_items (expense_id, product_id, name, quantity, unit_price, line_total, sku_raw, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$expenseId, $productId, $name, $qty, $unitPrice, $lineTotal, $skuRaw, $sortOrder]);
        $newId = (int)$this->db->lastInsertId();

        if ($productId) {
            updateProductInventory($this->db, $productId, $qty);
        }

        $vendor = $this->vendorForExpense($expenseId);
        if ($vendor['vendor_id']) {
            recordLineItemLesson($this->db, $vendor['vendor_id'], $vendor['vendor_name'], 'line_item_missed', null, $name);
            updateLineItemProfileStats($this->db, $vendor['vendor_id'], 0, 1);
            if ($productId) {
                try { teachVendorProduct($this->db, $vendor['vendor_id'], $name, $productId); } catch (Throwable $e) {}
            }
            if ($skuRaw !== null) {
                recordSkuLink($this->db, $vendor['vendor_id'], $skuRaw, $productId, null, $name);
            }
        }

        return $this->fetchJoined($newId);
    }

    /**
     * Delete a line item. Reverses inventory and records a `line_item_noise` lesson
     * so the parser stops capturing this line for the vendor.
     */
    public function delete(int $lineItemId): void
    {
        if (!$lineItemId) {
            throw new Exception('Line item ID required');
        }
        $li = $this->fetchWithVendor($lineItemId);

        if (!empty($li['product_id'])) {
            updateProductInventory($this->db, (int)$li['product_id'], -(float)$li['quantity']);
        }

        $this->db->prepare("DELETE FROM expense_line_items WHERE id = ?")->execute([$lineItemId]);

        // Only OCR-derived rows teach "noise" — a manually-added row being removed
        // says nothing about the parser.
        $ocrName = trim((string)($li['ocr_name'] ?? ''));
        if ($ocrName === '' && empty($li['product_id'])) {
            $ocrName = trim((string)$li['name']);
        }
        if ($ocrName !== '' && !empty($li['vendor_id'])) {
            recordLineItemLesson($this->db, (int)$li['vendor_id'], $li['vendor_name'] ?? null, 'line_item_noise', $ocrName, null);
            updateLineItemProfileStats($this->db, (int)$li['vendor_id'], 0, 1);
        }
    }

    /**
     * Link (or unlink with null) a line item to a CRM product. Reverses/applies
     * inventory, trains the vendor catalog, and remembers the SKU → product mapping.
     */
    public function link(int $lineItemId, ?int $newProductId): array
    {
        $li = $this->fetchWithVendor($lineItemId);

        $oldProductId = !empty($li['product_id']) ? (int)$li['product_id'] : null;
        $qty = (float)$li['quantity'];

        if ($oldProductId) {
            updateProductInventory($this->db, $oldProductId, -$qty);
        }
        $this->db->prepare("UPDATE expense_line_items SET product_id = ? WHERE id = ?")->execute([$newProductId, $lineItemId]);
        if ($newProductId) {
            updateProductInventory($this->db, $newProductId, $qty);
        }

        // Train as you link — vendor catalog alias + SKU memory
        if ($newProductId && !empty($li['vendor_id'])) {
            $vendorId = (int)$li['vendor_id'];
            try {
                if (!empty($li['name'])) {
                    teachVendorProduct($this->db, $vendorId, (string)$li['name'], $newProductId);
                }
                if (!empty($li['ocr_name']) && strtoupper(trim((string)$li['ocr_name'])) !== strtoupper(trim((string)$li['name']))) {
                    teachVendorProduct($this->db, $vendorId, (string)$li['ocr_name'], $newProductId);
                }
            } catch (Throwable $e) {
                error_log('Train-as-you-link error: ' . $e->getMessage());
            }
            if (!empty($li['sku_raw'])) {
                recordSkuLink($this->db, $vendorId, (string)$li['sku_raw'], $newProductId, null, (string)$li['name']);
            }
        }

        return $this->fetchJoined($lineItemId);
    }

    /** All line items for an expense, joined with product details. */
    public function listForExpense(int $expenseId): array
    {
        $stmt = $this->db->prepare("
            SELECT eli.*, p.name AS product_name, p.sku AS product_sku, p.track_inventory
            FROM expense_line_items eli
            LEFT JOIN products p ON p.id = eli.product_id
            WHERE eli.expense_id = ?
            ORDER BY eli.sort_order, eli.id
        ");
        $stmt->execute([$expenseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function fetchWithVendor(int $lineItemId): array
    {
        if (!$lineItemId) {
            throw new Exception('Line item ID required');
        }
        $hasOcrName = expenseLineItemsHasColumn($this->db, 'ocr_name');
        $stmt = $this->db->prepare("
            SELECT eli.*, " . ($hasOcrName ? "eli.ocr_name" : "NULL AS ocr_name") . ",
                   e.vendor_id, e.vendor_name_raw AS vendor_name
            FROM expense_line_items eli
            LEFT JOIN expenses e ON e.id = eli.expense_id
            WHERE eli.id = ?
        ");
        $stmt->execute([$lineItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Line item not found');
        }
        return $row;
    }

    private function fetchJoined(int $lineItemId): array
    {
        $stmt = $this->db->prepare("
            SELECT eli.*, p.name AS product_name, p.sku AS product_sku, p.track_inventory
            FROM expense_line_items eli
            LEFT JOIN products p ON p.id = eli.product_id
            WHERE eli.id = ?
        ");
        $stmt->execute([$lineItemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function vendorForExpense(int $expenseId): array
    {
        $stmt = $this->db->prepare("SELECT vendor_id, vendor_name_raw FROM expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'vendor_id'   => !empty($row['vendor_id']) ? (int)$row['vendor_id'] : null,
            'vendor_name' => $row['vendor_name_raw'] ?? null,
        ];
    }
}
