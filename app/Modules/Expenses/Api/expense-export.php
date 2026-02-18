<?php
/**
 * Expense CSV Export
 *
 * GET /app/Modules/Expenses/Api/expense-export.php?format=csv[&category=...&date_from=...&date_to=...&status=...]
 *
 * Accepts the same filter params as the list endpoint.
 * Returns a CSV download with all matching expenses.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    requirePermission('expenses.view');

    $db = getDB();

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['category'])) {
        $where[] = 'e.accounting_category = ?';
        $params[] = $_GET['category'];
    }
    if (!empty($_GET['vendor_id'])) {
        $where[] = 'e.vendor_id = ?';
        $params[] = (int)$_GET['vendor_id'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'e.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'e.expense_date >= ?';
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'e.expense_date <= ?';
        $params[] = $_GET['date_to'];
    }
    if (!empty($_GET['job_id'])) {
        $where[] = 'e.job_id = ?';
        $params[] = (int)$_GET['job_id'];
    }
    if (!empty($_GET['search'])) {
        $where[] = '(e.vendor_name_raw LIKE ? OR e.description LIKE ? OR e.notes LIKE ?)';
        $search = '%' . $_GET['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT e.id,
               e.expense_date,
               COALESCE(v.name, e.vendor_name_raw) AS vendor,
               e.accounting_category,
               e.description,
               e.amount AS subtotal,
               e.gst_amount AS gst,
               e.pst_amount AS pst,
               e.total,
               e.payment_method,
               e.status,
               e.anomaly_flags,
               e.anomaly_score,
               e.notes,
               jp.plan_number AS job_number,
               u.full_name AS created_by
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN job_plans jp ON jp.id = e.job_id
        LEFT JOIN users u ON u.id = e.created_by
        WHERE {$whereClause}
        ORDER BY e.expense_date DESC, e.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build filename
    $dateRange = '';
    if (!empty($_GET['date_from'])) $dateRange .= $_GET['date_from'];
    if (!empty($_GET['date_to']))   $dateRange .= '_to_' . $_GET['date_to'];
    $filename = 'expenses' . ($dateRange ? '_' . $dateRange : '_' . date('Y-m-d')) . '.csv';

    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');

    // BOM for Excel UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");

    // Header row
    fputcsv($output, [
        'ID',
        'Date',
        'Vendor',
        'Category',
        'Description',
        'Subtotal',
        'GST',
        'PST',
        'Total',
        'Payment Method',
        'Status',
        'Anomaly Flags',
        'Anomaly Score',
        'Job #',
        'Notes',
        'Created By',
    ]);

    // Data rows
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['expense_date'],
            $row['vendor'],
            $row['accounting_category'],
            $row['description'],
            number_format((float)($row['subtotal'] ?? 0), 2, '.', ''),
            number_format((float)($row['gst'] ?? 0), 2, '.', ''),
            number_format((float)($row['pst'] ?? 0), 2, '.', ''),
            number_format((float)($row['total'] ?? 0), 2, '.', ''),
            $row['payment_method'],
            $row['status'],
            $row['anomaly_flags'],
            $row['anomaly_score'],
            $row['job_number'],
            $row['notes'],
            $row['created_by'],
        ]);
    }

    fclose($output);

} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
