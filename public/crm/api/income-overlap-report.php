<?php
/**
 * READ-ONLY: how much income is counted twice because bank deposits are still booked
 * as income alongside the invoices they paid (2026-10-06).
 *
 * The Accounting page's profit and the GST report's "total sales" sum every
 * accounting_transactions row of type 'income' (invoices AND bank deposits). The journal
 * (balance sheet / journal-based reports) skips revenue deposits, and Owner Freedom reads
 * invoices — those are not affected. For each month, bank deposits still booked as income:
 *   stripe        — Stripe payouts (their invoices are already income: double counted)
 *   invoice_match — another deposit whose amount equals an invoice's income row within
 *                   14 days (very likely the same money: double counted)
 *   no_match      — not matched to any invoice: unrecorded income, or not income at all
 *                   (loan, owner money, refund) — needs a look.
 * Nothing is written. Admin only.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
requireLogin();
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$rows = $db->query("
    SELECT DATE_FORMAT(t.transaction_date, '%Y-%m') AS month,
           CASE WHEN t.description LIKE '%STRIPE%' THEN 'stripe'
                WHEN EXISTS (SELECT 1 FROM accounting_transactions v
                             WHERE v.reference_type = 'invoice' AND v.type = 'income'
                               AND ABS(v.amount - t.amount) < 0.01   -- invoice rows carry the amount paid (tax included)
                               AND v.transaction_date BETWEEN DATE_SUB(t.transaction_date, INTERVAL 14 DAY) AND DATE_ADD(t.transaction_date, INTERVAL 14 DAY))
                     THEN 'invoice_match'
                ELSE 'no_match' END AS kind,
           COUNT(*) AS n, ROUND(SUM(t.amount), 2) AS total
    FROM accounting_transactions t
    WHERE t.reference_type = 'bank_import' AND t.type = 'income'
      AND t.matched_invoice_id IS NULL
      AND t.status IN ('cleared', 'reconciled')
    GROUP BY month, kind
    ORDER BY month, kind
")->fetchAll(PDO::FETCH_ASSOC);

$invoiceIncome = $db->query("
    SELECT YEAR(transaction_date) AS yr, ROUND(SUM(amount), 2) AS total
    FROM accounting_transactions
    WHERE reference_type = 'invoice' AND type = 'income' AND status IN ('cleared', 'reconciled')
    GROUP BY yr ORDER BY yr
")->fetchAll(PDO::FETCH_KEY_PAIR);

$locked = [];
try {
    foreach ($db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $locked[sprintf('%04d-%02d', $p['year'], $p['month'])] = true;
    }
} catch (Throwable $e) { /* no periods table */ }

$byYear = [];
foreach ($rows as $r) {
    $y = substr($r['month'], 0, 4);
    $byYear[$y][$r['kind']]['n'] = ($byYear[$y][$r['kind']]['n'] ?? 0) + (int)$r['n'];
    $byYear[$y][$r['kind']]['total'] = round(($byYear[$y][$r['kind']]['total'] ?? 0) + (float)$r['total'], 2);
}
foreach ($byYear as $y => &$k) {
    $k['invoice_income'] = (float)($invoiceIncome[$y] ?? 0);
    $k['double_counted'] = round(($k['stripe']['total'] ?? 0) + ($k['invoice_match']['total'] ?? 0), 2);
}
unset($k);

echo json_encode(['read_only' => true, 'by_year' => $byYear, 'by_month' => $rows, 'locked_months' => array_keys($locked)], JSON_PRETTY_PRINT);
