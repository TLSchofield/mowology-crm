<?php
/**
 * One-off backfill: link INV-2026-0037/0100/0225 (ids 43, 109, 235) to
 * "1355183 BC LTD" (company id 8).
 *
 * These invoices were created before the company's primary_contact_id was
 * repointed from a duplicate empty contact (1516) to the real contact used
 * on the contract (1439), so contract_billing.php's company-resolution join
 * never matched and they were created with company_id = NULL. That's now
 * fixed going forward (contact 1439 is the company's primary contact); this
 * backfills the 3 invoices that already exist so their Bill To / company
 * association render correctly and the company's invoice totals reflect them.
 *
 * Idempotent — only updates rows that are still NULL.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

$invoiceIds = [43, 109, 235];
$companyId  = 8;

foreach ($invoiceIds as $id) {
    $row = $db->prepare("SELECT id, invoice_number, company_id FROM invoices WHERE id = ?");
    $row->execute([$id]);
    $inv = $row->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        $log[] = "❌ Invoice id {$id} not found";
        continue;
    }

    if ((int)($inv['company_id'] ?? 0) === $companyId) {
        $log[] = "⏭ {$inv['invoice_number']} (id {$id}) already linked to company {$companyId}";
        continue;
    }

    if (!empty($inv['company_id']) && (int)$inv['company_id'] !== $companyId) {
        $log[] = "❌ {$inv['invoice_number']} (id {$id}) already linked to a DIFFERENT company ({$inv['company_id']}) — skipped, not overwritten";
        continue;
    }

    $db->prepare("UPDATE invoices SET company_id = ? WHERE id = ?")->execute([$companyId, $id]);
    $log[] = "✅ {$inv['invoice_number']} (id {$id}) linked to company {$companyId}";
}

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Backfill: invoice company links</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Backfill: link Sandra Bertoia's invoices to 1355183 BC LTD</h1>
<ul>
<?php foreach ($log as $line):
    $cls = 'ok';
    if (str_starts_with($line, '❌')) $cls = 'err';
    elseif (str_starts_with($line, '⏭')) $cls = 'skip';
?>
  <li class="<?= $cls ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">✅ Done.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem">❌ <?= count($failed) ?> issue(s) — see above.</p>
<?php endif; ?>
<p style="margin-top:1.5rem"><a href="/crm/companies/view.php?id=8">← 1355183 BC LTD</a></p>
</body></html>
