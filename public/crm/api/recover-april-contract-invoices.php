<?php
/**
 * One-shot recovery runner — April 2026 contract billing.
 *
 * Purpose
 * ───────
 * The first manual run of contract_billing.php crashed inside the email
 * send path (undefined loadEmailTemplate), which left 6 orphan draft
 * invoices in the database — committed, line items + recipients intact,
 * but never emailed. A follow-up run found them via the idempotency
 * check and skipped them all.
 *
 * Separately, the recipient rows on those 6 invoices stored the
 * customer's PERSONAL email (contacts.email) instead of the BILLING
 * email (companies.billing_email) that the customer expects to receive
 * invoices at. Fixed in the cron code, but the existing rows still
 * carry the wrong address.
 *
 * This runner
 *   1. DELETEs any draft invoice with contract_id IS NOT NULL where
 *      invoice_date is in the current month. Line items and
 *      invoice_contacts rows cascade out.
 *   2. Immediately includes contract_billing.php so the cron recreates
 *      them with the corrected SELECT + email priority.
 *
 * Admin only. POST only. Safe to run multiple times (idempotent — a
 * second run finds nothing to delete and the cron skips what was
 * already sent on the first recovery).
 *
 * After this file is no longer needed it can be deleted from disk —
 * nothing else imports it.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<p>Admin only.</p>';
    exit;
}

$db = getDB();

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $result = ['stage' => 'csrf', 'error' => 'Invalid CSRF token.'];
    } else {
        try {
            // ── 1. Find + log the orphan drafts we're about to delete ────
            $findStmt = $db->prepare("
                SELECT i.id, i.invoice_number, i.contract_id, i.total_amount,
                       ic.email_address AS prior_email
                FROM invoices i
                LEFT JOIN invoice_contacts ic ON ic.invoice_id = i.id
                WHERE i.contract_id IS NOT NULL
                  AND i.status = 'draft'
                  AND YEAR(i.invoice_date)  = YEAR(CURDATE())
                  AND MONTH(i.invoice_date) = MONTH(CURDATE())
                ORDER BY i.id ASC
            ");
            $findStmt->execute();
            $orphans = $findStmt->fetchAll(PDO::FETCH_ASSOC);

            // ── 2. Delete them. FK cascades wipe line items + recipients. ──
            $deleted = 0;
            if (!empty($orphans)) {
                $ids = array_column($orphans, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // Explicit cascade in case FK ON DELETE CASCADE isn't set
                $db->prepare("DELETE FROM invoice_line_items WHERE invoice_id IN ({$placeholders})")->execute($ids);
                $db->prepare("DELETE FROM invoice_contacts   WHERE invoice_id IN ({$placeholders})")->execute($ids);
                $delStmt = $db->prepare("DELETE FROM invoices WHERE id IN ({$placeholders})");
                $delStmt->execute($ids);
                $deleted = $delStmt->rowCount();
            }

            // ── 3. Re-run the cron in HTTP mode so it recreates the rows
            //       with the corrected billing-email priority.
            $_SERVER['REQUEST_METHOD'] = 'POST';
            ob_start();
            require APP_ROOT . '/Modules/Contracts/Cron/contract_billing.php';
            $cronOutput = ob_get_clean();
            $cronResult = json_decode($cronOutput, true);

            $result = [
                'stage'    => 'done',
                'orphans'  => $orphans,
                'deleted'  => $deleted,
                'cron'     => $cronResult,
            ];
        } catch (Throwable $e) {
            $result = ['stage' => 'exception', 'error' => $e->getMessage()];
        }
    }
}

$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recover April Contract Invoices — Mowology</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 760px; margin: 60px auto; padding: 0 20px; color: #1a1a1a; }
  h1 { font-size: 1.4rem; margin-bottom: 6px; }
  .meta { color: #666; font-size: .875rem; margin-bottom: 28px; }
  .card { border: 1px solid #e0e0e0; border-radius: 8px; padding: 22px; margin-bottom: 20px; background: #fff; }
  .card h2 { font-size: 1rem; margin: 0 0 12px; }
  .ok { background: #e8f5e9; border-color: #66bb6a; }
  .warn { background: #fff8e1; border-color: #ffca28; }
  .err { background: #ffebee; border-color: #ef5350; }
  ul { margin: 0; padding-left: 22px; }
  li { font-size: .88rem; padding: 2px 0; }
  .btn { display: inline-block; padding: 11px 22px; background: #2D8659; color: #fff; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; }
  .btn:hover { background: #1A5F4A; }
  code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: .85rem; }
  .back { display: inline-block; margin-bottom: 20px; color: #2D8659; text-decoration: none; font-size: .9rem; }
</style>
</head>
<body>

<a href="/crm/invoices/" class="back">← Invoices</a>

<h1>Recover April Contract Invoices</h1>
<p class="meta">One-shot recovery for the orphan draft contract invoices created by the first manual run of contract billing (before the loadEmailTemplate + billing-email-priority fixes landed). Deletes the drafts and re-runs the cron with the corrected code.</p>

<?php if ($result && $result['stage'] === 'done'): ?>
  <div class="card ok">
    <h2>Step 1 — Orphan drafts deleted</h2>
    <?php if (empty($result['orphans'])): ?>
      <p>No orphan drafts found for the current month. Nothing to delete.</p>
    <?php else: ?>
      <p><strong><?= (int)$result['deleted'] ?> draft invoice(s) deleted.</strong> Prior email addresses shown for audit:</p>
      <ul>
        <?php foreach ($result['orphans'] as $o): ?>
          <li><code><?= htmlspecialchars($o['invoice_number']) ?></code> — was going to <code><?= htmlspecialchars($o['prior_email'] ?? '(none)') ?></code></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card <?= (!empty($result['cron']['success']) && ($result['cron']['errors'] ?? 0) === 0) ? 'ok' : 'warn' ?>">
    <h2>Step 2 — Cron re-run</h2>
    <?php if (empty($result['cron'])): ?>
      <p class="err">Cron returned no parseable JSON.</p>
    <?php else: ?>
      <p>
        <strong><?= (int)($result['cron']['created'] ?? 0) ?></strong> created,
        <strong><?= (int)($result['cron']['skipped'] ?? 0) ?></strong> skipped,
        <strong><?= (int)($result['cron']['errors']  ?? 0) ?></strong> errors
        <span class="meta">(<?= (int)($result['cron']['elapsed_ms'] ?? 0) ?> ms)</span>
      </p>

      <?php if (!empty($result['cron']['detail']['created'])): ?>
        <h2>Invoices sent</h2>
        <ul>
          <?php foreach ($result['cron']['detail']['created'] as $c): ?>
            <li><?= htmlspecialchars($c) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($result['cron']['detail']['skipped'])): ?>
        <h2>Skipped</h2>
        <ul>
          <?php foreach ($result['cron']['detail']['skipped'] as $s): ?>
            <li><?= htmlspecialchars($s) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($result['cron']['detail']['errors'])): ?>
        <h2>Errors</h2>
        <ul>
          <?php foreach ($result['cron']['detail']['errors'] as $e): ?>
            <li class="err"><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php elseif ($result && $result['stage'] !== 'done'): ?>
  <div class="card err">
    <h2>Recovery failed</h2>
    <p><?= htmlspecialchars($result['error'] ?? 'Unknown error') ?></p>
  </div>

<?php else: ?>
  <div class="card warn">
    <h2>What this will do</h2>
    <ul>
      <li>Find every <code>status=draft</code> invoice where <code>contract_id IS NOT NULL</code> and <code>invoice_date</code> falls in the current month</li>
      <li>Delete them (along with their line items and recipient rows)</li>
      <li>Re-run <code>contract_billing.php</code> immediately so the cron recreates each invoice with the corrected <code>companies.billing_email</code> priority</li>
    </ul>
    <p class="meta">Safe to re-run — the second pass finds no orphans and the cron's idempotency check skips anything already sent.</p>
  </div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <button type="submit" class="btn">
    <?= $result ? 'Run Again' : 'Delete Orphans &amp; Re-run Cron' ?>
  </button>
</form>

</body>
</html>
