<?php
/**
 * /app/Services/Receipts/ExpenseLineItems.php
 * Expense Line Item Persistence — save, reverse, and inventory adjustment
 *
 * Extracted from expenses.php (desktop/Android CRUD) so the iOS JWT create endpoint
 * (expense-save.php) can save line items too, instead of silently discarding them —
 * expenses.php can't be require_once'd directly since its top-level code runs
 * session-based requireLogin() unconditionally, which would break JWT-only callers.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
 *   saveLineItems($db, $expenseId, $input['line_items']);
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Save line items array for an expense. Also applies inventory adjustments
 * for any items that already have a product_id set.
 */
function saveLineItems(PDO $db, int $expenseId, array $lineItems): void
{
    $stmt = $db->prepare("
        INSERT INTO expense_line_items
            (expense_id, product_id, name, quantity, unit_price, line_total, sku_raw, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($lineItems as $idx => $li) {
        $productId = !empty($li['product_id']) ? (int)$li['product_id'] : null;
        $qty = (float)($li['quantity'] ?? 1);
        $unitPrice = isset($li['unit_price']) && $li['unit_price'] !== null && $li['unit_price'] !== ''
            ? (float)$li['unit_price']
            : null;
        $lineTotal = (float)($li['line_total'] ?? $li['amount'] ?? 0);
        $name = $li['name'] ?? 'Unknown Item';
        $skuRaw = $li['sku_raw'] ?? null;

        $stmt->execute([
            $expenseId,
            $productId,
            $name,
            $qty,
            $unitPrice,
            $lineTotal,
            $skuRaw,
            $idx,
        ]);

        // Apply inventory adjustment for linked products
        if ($productId) {
            updateProductInventory($db, $productId, $qty);
        }
    }
}


/**
 * Reverse inventory adjustments for all linked line items of an expense.
 * Call this BEFORE deleting line items or the expense itself.
 */
function reverseLineItemInventory(PDO $db, int $expenseId): void
{
    $stmt = $db->prepare("
        SELECT product_id, quantity
        FROM expense_line_items
        WHERE expense_id = ? AND product_id IS NOT NULL
    ");
    $stmt->execute([$expenseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        updateProductInventory($db, (int)$row['product_id'], -(float)$row['quantity']);
    }
}


/**
 * Adjust current_stock on a product (only if track_inventory = 1).
 * Positive delta = purchase adds stock; negative = reversal.
 */
function updateProductInventory(PDO $db, int $productId, float $qtyDelta): void
{
    if ($qtyDelta == 0) return;

    $stmt = $db->prepare("
        UPDATE products
        SET current_stock = current_stock + ?
        WHERE id = ? AND track_inventory = 1
    ");
    $stmt->execute([$qtyDelta, $productId]);
}
