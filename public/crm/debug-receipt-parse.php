<?php
/**
 * Debug tool: Re-parse a stored receipt's OCR text and show results.
 * Usage: /crm/debug-receipt-parse.php?id=N
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';

requireLogin();
requirePermission('expenses.edit');

header('Content-Type: text/html; charset=utf-8');

$db = getDB();

// If no ID, list recent expenses
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo '<h2>Recent Expenses</h2>';
    $stmt = $db->query("
        SELECT e.id, e.expense_date, e.total, e.vendor_name_raw,
               v.name AS vendor_name,
               LENGTH(e.raw_ocr_json) AS ocr_length
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        ORDER BY e.created_at DESC
        LIMIT 20
    ");
    echo '<table border=1 cellpadding=5>';
    echo '<tr><th>ID</th><th>Date</th><th>Vendor</th><th>Total</th><th>OCR Length</th><th>Action</th></tr>';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vendor = htmlspecialchars($row['vendor_name'] ?? $row['vendor_name_raw'] ?? '—');
        echo "<tr><td>{$row['id']}</td><td>{$row['expense_date']}</td><td>{$vendor}</td>";
        echo "<td>\${$row['total']}</td><td>{$row['ocr_length']}</td>";
        echo "<td><a href='?id={$row['id']}'>Debug Parse</a></td></tr>";
    }
    echo '</table>';
    exit;
}

// Load the expense
$stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
$stmt->execute([$id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    die('Expense not found');
}

$ocrText = $expense['raw_ocr_json'] ?? '';

echo '<h2>Receipt Parse Debug — Expense #' . $id . '</h2>';
echo '<p><strong>Vendor:</strong> ' . htmlspecialchars($expense['vendor_name_raw'] ?? '—') . '</p>';
echo '<p><strong>Stored Total:</strong> $' . $expense['total'] . '</p>';
echo '<p><strong>Date:</strong> ' . $expense['expense_date'] . '</p>';

// Show receipt image
if ($expense['receipt_media_id']) {
    $imgStmt = $db->prepare("SELECT file_path FROM media_assets WHERE id = ?");
    $imgStmt->execute([$expense['receipt_media_id']]);
    $imgPath = $imgStmt->fetchColumn();
    if ($imgPath) {
        echo '<p><strong>Receipt Image:</strong></p>';
        echo '<img src="' . htmlspecialchars($imgPath) . '" style="max-width:400px; border:1px solid #ccc;">';
    }
}

echo '<hr>';

if (empty($ocrText)) {
    echo '<p style="color:red;"><strong>No OCR text stored for this expense.</strong></p>';
    exit;
}

// Show raw OCR text
echo '<h3>Raw OCR Text</h3>';
echo '<pre style="background:#f5f5f5; padding:10px; border:1px solid #ddd; max-height:400px; overflow:auto; white-space:pre-wrap;">';
echo htmlspecialchars($ocrText);
echo '</pre>';

// Split into lines
$lines = preg_split('/\r?\n/', $ocrText);
echo '<h3>Lines (' . count($lines) . ')</h3>';
echo '<table border=1 cellpadding=3>';
echo '<tr><th>#</th><th>Line</th><th>Length</th></tr>';
foreach ($lines as $i => $line) {
    echo '<tr><td>' . $i . '</td><td><code>' . htmlspecialchars($line) . '</code></td><td>' . strlen($line) . '</td></tr>';
}
echo '</table>';

// Column parser debug
echo '<h3>Column Parser Debug</h3>';
$columnResult = extractColumnSeparatedAmounts($lines);
echo '<pre style="background:#fff3cd; padding:10px; border:1px solid #ffc107;">';
echo htmlspecialchars(json_encode($columnResult, JSON_PRETTY_PRINT));
echo '</pre>';

// Individual extractor debug
echo '<h3>Individual Extractor Debug</h3>';
echo '<p><strong>extractTotal():</strong> ' . (extractTotal($ocrText, $lines) ?? '<em>null</em>') . '</p>';
echo '<p><strong>extractGST():</strong> ' . (extractGST($ocrText) ?? '<em>null</em>') . '</p>';
echo '<p><strong>extractSubtotal():</strong> ' . (extractSubtotal($ocrText) ?? '<em>null</em>') . '</p>';

// Re-parse
echo '<h3>Parse Results (no raw_response, text only)</h3>';
$parsed = parseReceiptText($ocrText);
echo '<pre style="background:#f5f5f5; padding:10px; border:1px solid #ddd;">';
echo htmlspecialchars(json_encode($parsed, JSON_PRETTY_PRINT));
echo '</pre>';

// Show line items specifically
echo '<h3>Line Items (' . count($parsed['line_items']) . ')</h3>';
if (empty($parsed['line_items'])) {
    echo '<p style="color:orange;">No line items extracted.</p>';
} else {
    echo '<table border=1 cellpadding=5>';
    echo '<tr><th>#</th><th>Name</th><th>Amount</th><th>Qty</th><th>Unit Price</th><th>SKU</th></tr>';
    foreach ($parsed['line_items'] as $i => $item) {
        echo '<tr>';
        echo '<td>' . $i . '</td>';
        echo '<td>' . htmlspecialchars($item['name'] ?? '—') . '</td>';
        echo '<td>' . ($item['amount'] ?? '—') . '</td>';
        echo '<td>' . ($item['quantity'] ?? '—') . '</td>';
        echo '<td>' . ($item['unit_price'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($item['sku_raw'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

// Check stored line items
$liStmt = $db->prepare("SELECT * FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
$liStmt->execute([$id]);
$storedItems = $liStmt->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>Stored Line Items (' . count($storedItems) . ')</h3>';
if (empty($storedItems)) {
    echo '<p style="color:orange;">No stored line items in expense_line_items table.</p>';
} else {
    echo '<table border=1 cellpadding=5>';
    echo '<tr><th>ID</th><th>Name</th><th>Line Total</th><th>Qty</th><th>Unit Price</th><th>SKU</th><th>Product ID</th></tr>';
    foreach ($storedItems as $item) {
        echo '<tr>';
        echo '<td>' . $item['id'] . '</td>';
        echo '<td>' . htmlspecialchars($item['name'] ?? '—') . '</td>';
        echo '<td>' . ($item['line_total'] ?? '—') . '</td>';
        echo '<td>' . ($item['quantity'] ?? '—') . '</td>';
        echo '<td>' . ($item['unit_price'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($item['sku_raw'] ?? '—') . '</td>';
        echo '<td>' . ($item['product_id'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
