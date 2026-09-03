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
    // ocr_name arrives with migration 1115; degrade gracefully until it has run on prod
    // (same not-run-on-prod pattern as the media_assets archival columns).
    $hasOcrName = expenseLineItemsHasColumn($db, 'ocr_name');
    $stmt = $db->prepare($hasOcrName
        ? "INSERT INTO expense_line_items
               (expense_id, product_id, name, ocr_name, quantity, unit_price, original_unit_price, is_adjustment, line_total, sku_raw, sort_order)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        : "INSERT INTO expense_line_items
               (expense_id, product_id, name, quantity, unit_price, original_unit_price, is_adjustment, line_total, sku_raw, sort_order)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $idx = -1;
    foreach ($lineItems as $li) {
        // "Not an item" rows from the review card are recorded as lessons by
        // ReceiptLearning, never persisted.
        if (!empty($li['removed'])) continue;
        $idx++;
        $productId = !empty($li['product_id']) ? (int)$li['product_id'] : null;
        $qty = (float)($li['quantity'] ?? 1);
        $unitPrice = isset($li['unit_price']) && $li['unit_price'] !== null && $li['unit_price'] !== ''
            ? (float)$li['unit_price']
            : null;
        $originalUnitPrice = isset($li['original_unit_price']) && $li['original_unit_price'] !== null && $li['original_unit_price'] !== ''
            ? (float)$li['original_unit_price']
            : null;
        $isAdjustment = !empty($li['is_adjustment']) ? 1 : 0;
        $lineTotal = (float)($li['line_total'] ?? $li['amount'] ?? 0);
        $name = $li['name'] ?? 'Unknown Item';
        $skuRaw = $li['sku_raw'] ?? null;
        $ocrName = isset($li['ocr_name']) && $li['ocr_name'] !== '' ? mb_substr((string)$li['ocr_name'], 0, 255) : null;

        $params = [$expenseId, $productId, $name];
        if ($hasOcrName) $params[] = $ocrName;
        array_push($params, $qty, $unitPrice, $originalUnitPrice, $isAdjustment, $lineTotal, $skuRaw, $idx);
        $stmt->execute($params);

        // Apply inventory adjustment for linked products
        if ($productId) {
            updateProductInventory($db, $productId, $qty);
        }
    }
}


/**
 * Column-existence probe (cached per request) so code can ship ahead of a migration
 * running on production without 500ing.
 */
function expenseLineItemsHasColumn(PDO $db, string $column): bool
{
    static $cache = [];
    if (isset($cache[$column])) return $cache[$column];
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM expense_line_items LIKE ?");
        $stmt->execute([$column]);
        return $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$column] = false;
    }
}


/**
 * Teach the vendor_products catalog: when office staff links a line-item name to a
 * CRM product, record it so future receipts from the same vendor auto-match (and,
 * via VendorProductMatch step 5, auto-link) without manual work.
 *
 *  - If a vendor_products row already exists for this vendor+product, add the OCR
 *    name as an alias (comma-separated in ocr_aliases).
 *  - If no row exists yet, create one.
 *
 * Shared by expenses.php (web) and ExpenseLineItemService (web + JWT).
 */
function teachVendorProduct(PDO $db, int $vendorId, string $ocrName, int $productId): void
{
    $ocrNameNorm = strtoupper(trim($ocrName));
    if (!$ocrNameNorm) return;

    $check = $db->prepare("SELECT id, ocr_aliases FROM vendor_products WHERE vendor_id = ? AND product_id = ? LIMIT 1");
    $check->execute([$vendorId, $productId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $aliases = array_filter(array_map('trim', explode(',', $existing['ocr_aliases'] ?? '')));
        if (!in_array($ocrNameNorm, array_map('strtoupper', $aliases), true)) {
            $aliases[] = $ocrNameNorm;
            $db->prepare("UPDATE vendor_products SET ocr_aliases = ? WHERE id = ?")
               ->execute([implode(',', $aliases), $existing['id']]);
        }
        return;
    }

    $pStmt = $db->prepare("SELECT name, sku FROM products WHERE id = ?");
    $pStmt->execute([$productId]);
    $product = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) return;

    $db->prepare("
        INSERT INTO vendor_products (vendor_id, product_id, name, category, ocr_aliases, is_active)
        VALUES (?, ?, ?, 'Materials', ?, 1)
    ")->execute([$vendorId, $productId, $product['name'], $ocrNameNorm]);
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
