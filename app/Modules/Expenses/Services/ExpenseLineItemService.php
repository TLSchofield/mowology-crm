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
 * Add / update / delete go through ExpenseGate (migration 1233): the gate writes the line,
 * keeps a split receipt's shares in step, records the audit row and does the learning
 * (ExpenseGateHooks::learnLineOp). This service works out the values (resolveLineTotal…).
 * Product linking (link) stays here: it moves stock and teaches the catalogue, never money.
 *
 * Global-namespace (no production autoloader): require_once the file and
 * `new ExpenseLineItemService($db)` — `->by($actor, $source)` names who is changing it.
 */

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}
require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
require_once __DIR__ . '/ExpenseGate.php';

class ExpenseLineItemService
{
    private PDO $db;
    /** @var ExpenseGate */
    private $gate;
    private array $actor = ['id' => null, 'kind' => 'user'];
    private string $source = 'line_items';

    public function __construct(PDO $db, $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate;
    }

    /** Who is changing the lines, and from where (the gate's audit row). */
    public function by(array $actor, string $source): self
    {
        $this->actor = $actor;
        $this->source = $source;
        return $this;
    }

    private function gate(): ExpenseGate
    {
        return $this->gate ?? ($this->gate = new ExpenseGate($this->db));
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

        // Through the gate: the line, the inventory re-sync on a linked product, the split's
        // shares, the audit row, and the rename lesson (ExpenseGateHooks::learnLineOp).
        $this->gate()->apply((int)$existing['expense_id'], ['line_item' => [
            'op' => 'update', 'id' => $lineItemId, 'name' => $name, 'quantity' => $qty, 'unit_price' => $unitPrice, 'line_total' => $lineTotal,
        ]], $this->actor, $this->source);
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

        // Through the gate: insert, stock on a linked product, the audit row, and the
        // missed-line / catalogue / SKU lessons (ExpenseGateHooks::learnLineOp).
        $res = $this->gate()->apply($expenseId, ['line_item' => [
            'op' => 'add', 'name' => $name, 'quantity' => $qty, 'unit_price' => $unitPrice, 'line_total' => $lineTotal,
            'product_id' => $productId, 'sku_raw' => $skuRaw,
        ]], $this->actor, $this->source);
        return $this->fetchJoined((int)($res['line_item_id'] ?? 0));
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
        // Through the gate: stock reversed, the line removed, the split re-balanced, the
        // audit row, and the "not an item" lesson for an OCR-read line.
        $this->gate()->apply((int)$li['expense_id'], ['line_item' => ['op' => 'delete', 'id' => $lineItemId]], $this->actor, $this->source);
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
}
