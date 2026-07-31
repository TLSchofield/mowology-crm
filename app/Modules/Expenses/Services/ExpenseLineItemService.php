<?php
/**
 * ExpenseLineItemService — user corrections to a single persisted
 * expense_line_items row (name / quantity / unit_price / line_total).
 *
 * OCR/receipt parsing is never perfect — a mis-split discount line, a
 * misread quantity, or a wrong item name previously had no fix short of
 * deleting the row and re-typing it via Add Item (which also loses any
 * CRM Product link and re-triggers an inventory adjustment). This service
 * backs the new update_line_item action in expenses.php so an existing row
 * can be corrected in place.
 *
 * Deliberately does not touch the expense header's Subtotal/Total —
 * those are independently staff-verified fields (see handleUpdate()) and
 * no other line-item action (add/delete) re-derives them either.
 */
class ExpenseLineItemService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param int   $lineItemId
     * @param array $input  name, quantity, unit_price (nullable), line_total (nullable)
     * @return array Updated line item row, joined with product_name/product_sku
     * @throws Exception if the row doesn't exist or the name is blank
     */
    public function update(int $lineItemId, array $input): array
    {
        if (!$lineItemId) {
            throw new Exception('Line item ID required');
        }

        $stmt = $this->db->prepare("SELECT * FROM expense_line_items WHERE id = ?");
        $stmt->execute([$lineItemId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new Exception('Line item not found');
        }

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

        // Only recompute line_total from qty*unit_price when one of those
        // actually changed — otherwise a pure rename would silently drift
        // the stored total via rounding. An explicit line_total always wins.
        if (isset($input['line_total']) && $input['line_total'] !== '' && $input['line_total'] !== null) {
            $lineTotal = (float)$input['line_total'];
        } elseif (($qtyProvided || $unitPriceProvided) && $unitPrice !== null) {
            $lineTotal = round($unitPrice * $qty, 2);
        } else {
            $lineTotal = (float)$existing['line_total'];
        }

        $upd = $this->db->prepare("
            UPDATE expense_line_items
            SET name = ?, quantity = ?, unit_price = ?, line_total = ?
            WHERE id = ?
        ");
        $upd->execute([$name, $qty, $unitPrice, $lineTotal, $lineItemId]);

        // Re-sync inventory if quantity changed on a linked product —
        // mirrors the reverse/reapply convention used elsewhere in this
        // module (handleDeleteLineItem, handleLinkProduct).
        if (!empty($existing['product_id'])) {
            $qtyDelta = $qty - (float)$existing['quantity'];
            if ($qtyDelta != 0) {
                require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
                updateProductInventory($this->db, (int)$existing['product_id'], $qtyDelta);
            }
        }

        $result = $this->db->prepare("
            SELECT eli.*, p.name AS product_name, p.sku AS product_sku
            FROM expense_line_items eli
            LEFT JOIN products p ON p.id = eli.product_id
            WHERE eli.id = ?
        ");
        $result->execute([$lineItemId]);
        return $result->fetch(PDO::FETCH_ASSOC);
    }
}
