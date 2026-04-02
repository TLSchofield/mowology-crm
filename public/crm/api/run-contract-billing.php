<?php
/**
 * Manual trigger for the contract monthly billing cron.
 * Admin-only. GET = confirmation UI, POST = run it.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<p>Admin only.</p>';
    exit;
}

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $result = ['success' => false, 'error' => 'Invalid CSRF token.'];
    } else {
        // Capture output from the cron script
        ob_start();
        $_SERVER['REQUEST_METHOD'] = 'POST'; // satisfy the cron's CLI/HTTP check
        $cronPath = APP_ROOT . '/Modules/Contracts/Cron/contract_billing.php';
        if (!file_exists($cronPath)) {
            $result = ['success' => false, 'error' => 'Cron file not found: ' . $cronPath];
        } else {
            require $cronPath;
            $json = ob_get_clean();
            $result = json_decode($json, true) ?? ['success' => false, 'error' => 'Invalid response', 'raw' => $json];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Run Contract Billing — Mowology</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 700px; margin: 60px auto; padding: 0 20px; color: #1a1a1a; }
  h1 { font-size: 1.4rem; margin-bottom: 4px; }
  .meta { color: #666; font-size: 0.875rem; margin-bottom: 32px; }
  .card { border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
  .card h2 { font-size: 1rem; margin: 0 0 12px; }
  ul { margin: 0; padding-left: 20px; }
  li { margin: 4px 0; font-size: 0.9rem; }
  .btn { display: inline-block; padding: 10px 24px; background: #2D8659; color: #fff; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; }
  .btn:hover { background: #1A5F4A; }
  .success { background: #e8f5e9; border-color: #66bb6a; }
  .warning { background: #fff8e1; border-color: #ffca28; }
  .error   { background: #ffebee; border-color: #ef5350; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; margin-left: 6px; }
  .badge-ok  { background: #2D8659; color: #fff; }
  .badge-err { background: #c62828; color: #fff; }
  .badge-skip { background: #888; color: #fff; }
  code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: 0.85rem; }
  .back { display: inline-block; margin-bottom: 24px; color: #2D8659; text-decoration: none; font-size: 0.9rem; }
  .back:hover { text-decoration: underline; }
</style>
</head>
<body>

<a href="../invoices/" class="back">← Invoices</a>

<h1>Contract Monthly Billing</h1>
<p class="meta">Generates and emails invoices for all active monthly contracts. Safe to run multiple times — already-invoiced contracts are skipped automatically.</p>

<?php if ($result): ?>
  <?php
    $cardClass = $result['success'] ? (($result['errors'] ?? 0) > 0 ? 'warning' : 'success') : 'error';
  ?>
  <div class="card <?php echo $cardClass; ?>">
    <h2>Result — <?php echo htmlspecialchars($result['period'] ?? date('F Y')); ?></h2>

    <?php if (!$result['success']): ?>
      <p><strong>Error:</strong> <?php echo htmlspecialchars($result['error'] ?? 'Unknown error'); ?></p>
      <?php if (!empty($result['raw'])): ?>
        <pre style="font-size:0.8rem;overflow:auto"><?php echo htmlspecialchars($result['raw']); ?></pre>
      <?php endif; ?>

    <?php else: ?>
      <p>
        <span class="badge badge-ok"><?php echo (int)($result['created'] ?? 0); ?> created</span>
        <span class="badge badge-skip"><?php echo (int)($result['skipped'] ?? 0); ?> skipped</span>
        <?php if (($result['errors'] ?? 0) > 0): ?>
          <span class="badge badge-err"><?php echo (int)$result['errors']; ?> errors</span>
        <?php endif; ?>
        &nbsp; <?php echo (int)($result['elapsed_ms'] ?? 0); ?> ms
      </p>

      <?php if (!empty($result['detail']['created'])): ?>
        <h2 style="margin-top:16px">Invoices sent</h2>
        <ul>
          <?php foreach ($result['detail']['created'] as $c): ?>
            <li><?php echo htmlspecialchars($c); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($result['detail']['skipped'])): ?>
        <h2 style="margin-top:16px">Skipped</h2>
        <ul>
          <?php foreach ($result['detail']['skipped'] as $s): ?>
            <li><?php echo htmlspecialchars($s); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($result['detail']['errors'])): ?>
        <h2 style="margin-top:16px">Errors</h2>
        <ul>
          <?php foreach ($result['detail']['errors'] as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="card">
    <h2>What this will do</h2>
    <ul>
      <li>Query all <code>status=active</code>, <code>billing_cycle=monthly</code> contracts</li>
      <li>Skip any contract already invoiced this month</li>
      <li>Create an invoice: <code>billing_amount + 5% GST</code>, due <?php echo date('F t, Y'); ?></li>
      <li>Email via the <code>invoice_sent</code> template</li>
      <li>Send SMS if the contact has SMS consent</li>
    </ul>
  </div>

<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
  <button type="submit" class="btn">
    <?php echo $result ? 'Run Again' : 'Run Contract Billing Now'; ?>
  </button>
</form>

</body>
</html>
