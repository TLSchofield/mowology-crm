<?php
/**
 * Migration 1067 — Seed FY2024 opening balances (Phase 1, Step 5)
 *
 * Posts ONE opening journal entry as at 2025-01-01 from the SIGNED FY2024
 * financial statements (Balance Sheet at Dec 31, 2024), so the double-entry
 * Balance Sheet shows real positions from day one.
 *
 * Idempotent: posted with source_type='opening', source_id=2024 → re-running
 * returns the existing entry (UNIQUE(source_type, source_id)).
 *
 * Source: "Signed FS YE2024 - Mowology.pdf" — ties to total $140,495.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

// Locate app root (works locally and on production) and load LedgerService.
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
require_once APP_ROOT . '/Modules/Accounting/Services/LedgerService.php';

$db = getDB();
$result = [];

// Signed FY2024 closing balances → 2025-01-01 opening entry.
$lines = [
    // Assets (debits)
    ['account' => '1010', 'debit'  => 4865.00],   // Cash / Chequing
    ['account' => '1100', 'debit'  => 37910.00],  // Accounts Receivable
    ['account' => '1250', 'debit'  => 466.00],    // Income Tax Receivable
    ['account' => '1500', 'debit'  => 26188.00],  // Property, Plant & Equipment
    ['account' => '1300', 'debit'  => 71066.00],  // Due from Shareholder
    // Liabilities + Equity (credits)
    ['account' => '2100', 'credit' => 10806.00],  // Accounts Payable
    ['account' => '2500', 'credit' => 71047.00],  // Due to Government Agencies
    ['account' => '2600', 'credit' => 32341.00],  // Loan Payable
    ['account' => '3100', 'credit' => 1.00],      // Share Capital
    ['account' => '3200', 'credit' => 26300.00],  // Retained Earnings
];

try {
    if (!$db->query("SHOW TABLES LIKE 'journal_entries'")->fetchColumn()) {
        throw new RuntimeException('journal_entries table missing — run migration 1066 first.');
    }
    $ledger = new LedgerService($db);
    $entryId = $ledger->postOpeningBalances([
        'date'  => '2025-01-01',
        'id'    => 2024,
        'memo'  => 'FY2024 opening balances (signed financial statements)',
        'lines' => $lines,
    ]);
    $result = ['status' => 'OK', 'entry_id' => $entryId,
               'msg' => 'Opening balances posted (or already present).'];
} catch (\Throwable $e) {
    $result = ['status' => 'error', 'msg' => $e->getMessage()];
}

$d = 0; $c = 0;
foreach ($lines as $l) { $d += $l['debit'] ?? 0; $c += $l['credit'] ?? 0; }
?>
<!DOCTYPE html><html><head><title>Migration 1067 — Opening Balances</title></head><body>
<h2>Migration 1067 — FY2024 opening balances</h2>
<p>Entry totals: <strong>DR $<?= number_format($d, 2) ?></strong> /
   <strong>CR $<?= number_format($c, 2) ?></strong>
   <?= abs($d - $c) < 0.005 ? '✓ balanced' : '✗ OUT OF BALANCE' ?></p>
<p>Status: <strong><?= htmlspecialchars($result['status']) ?></strong>
   — <?= htmlspecialchars($result['msg']) ?>
   <?php if (isset($result['entry_id'])): ?>(journal_entries.id = <?= (int)$result['entry_id'] ?>)<?php endif; ?></p>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Account</th><th align="right">Debit</th><th align="right">Credit</th></tr>
<?php foreach ($lines as $l): ?>
<tr><td><?= htmlspecialchars($l['account']) ?></td>
    <td align="right"><?= isset($l['debit'])  ? number_format($l['debit'], 2)  : '' ?></td>
    <td align="right"><?= isset($l['credit']) ? number_format($l['credit'], 2) : '' ?></td></tr>
<?php endforeach; ?>
</table>
<p><a href="/crm/accounting/balance-sheet.php">← Balance Sheet</a></p>
</body></html>
