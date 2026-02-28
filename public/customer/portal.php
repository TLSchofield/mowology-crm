<?php
/**
 * Client Portal Overview — Admin Preview
 * CRM-session-protected. Shows all portal-accessible documents for a contact.
 * URL: /customer/portal.php?contact_id=66
 */

require_once dirname(__DIR__) . '/loginAuth/auth.php';
requireLogin();

$db = getDB();
$contactId = intval($_GET['contact_id'] ?? 0);

if (!$contactId) {
    http_response_code(400);
    die('Missing contact_id.');
}

// Fetch contact
$stmt = $db->prepare("SELECT id, first_name, last_name, email, phone FROM contacts WHERE id = ? LIMIT 1");
$stmt->execute([$contactId]);
$contact = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contact) {
    http_response_code(404);
    die('Contact not found.');
}

$contactName = trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''));

// Fetch quotes with a portal token
$stmt = $db->prepare("
    SELECT id, quote_number, status, total_amount, access_token, created_at
    FROM quotes
    WHERE contact_id = ?
      AND access_token IS NOT NULL
      AND access_token != ''
    ORDER BY created_at DESC
");
$stmt->execute([$contactId]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch invoices linked to this contact (via invoice_contacts)
$stmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.status, i.total_amount, i.access_token, i.created_at
    FROM invoices i
    JOIN invoice_contacts ic ON ic.invoice_id = i.id
    WHERE ic.contact_id = ?
      AND i.access_token IS NOT NULL
      AND i.access_token != ''
    ORDER BY i.created_at DESC
");
$stmt->execute([$contactId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasAnything = !empty($quotes) || !empty($invoices);

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

function statusBadge(string $status): string {
    $map = [
        'draft'    => '#6B7280',
        'sent'     => '#3B82F6',
        'accepted' => '#2D8659',
        'declined' => '#EF4444',
        'expired'  => '#9CA3AF',
        'viewed'   => '#3B82F6',
        'paid'     => '#2D8659',
        'partial'  => '#F59E0B',
        'overdue'  => '#EF4444',
        'cancelled'=> '#6B7280',
    ];
    $color = $map[$status] ?? '#6B7280';
    return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:' . $color . ';color:#fff;">' . htmlspecialchars(ucfirst($status)) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Client Portal — <?php echo htmlspecialchars($contactName); ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f4f6; color: #1f2937; }
    .portal-bar { background: #0D3B2E; color: #fff; padding: 12px 24px; display: flex; align-items: center; gap: 16px; }
    .portal-bar a { color: #7FD858; text-decoration: none; font-size: 0.875rem; }
    .portal-bar a:hover { text-decoration: underline; }
    .portal-bar .back { margin-left: auto; }
    .container { max-width: 760px; margin: 32px auto; padding: 0 16px; }
    h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 4px; }
    .subtitle { color: #6b7280; font-size: 0.9rem; margin: 0 0 28px; }
    h2 { font-size: 1rem; font-weight: 600; color: #374151; margin: 0 0 12px; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
    .doc-list { list-style: none; margin: 0 0 28px; padding: 0; }
    .doc-list li { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
    .doc-info { flex: 1; }
    .doc-number { font-weight: 600; font-size: 0.95rem; }
    .doc-meta { font-size: 0.8rem; color: #6b7280; margin-top: 2px; }
    .doc-actions { display: flex; gap: 8px; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px; font-size: 0.85rem; font-weight: 500; text-decoration: none; border: none; cursor: pointer; }
    .btn-primary { background: #2D8659; color: #fff; }
    .btn-primary:hover { background: #1A5F4A; }
    .btn-copy { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
    .btn-copy:hover { background: #e5e7eb; }
    .empty { background: #fff; border: 2px dashed #d1d5db; border-radius: 10px; padding: 40px 24px; text-align: center; color: #9ca3af; }
    .empty svg { margin-bottom: 12px; opacity: 0.4; }
    .empty p { margin: 0; font-size: 0.9rem; }
    .amount { font-size: 0.85rem; color: #374151; }
    .toast { position: fixed; bottom: 24px; right: 24px; background: #1f2937; color: #fff; padding: 10px 18px; border-radius: 8px; font-size: 0.875rem; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
    .toast.show { opacity: 1; }
  </style>
</head>
<body>

<div class="portal-bar">
  <strong>Mowology</strong>
  <span style="opacity:0.5">|</span>
  <span>Client Portal Preview</span>
  <a href="/crm/clients_appstack.php?action=view_contact&id=<?php echo $contactId; ?>" class="back">← Back to CRM</a>
</div>

<div class="container">
  <h1><?php echo htmlspecialchars($contactName); ?></h1>
  <p class="subtitle">
    Portal documents for this contact
    <?php if ($contact['email']): ?>&nbsp;·&nbsp;<?php echo htmlspecialchars($contact['email']); ?><?php endif; ?>
  </p>

  <?php if (!$hasAnything): ?>
    <div class="empty">
      <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
      </svg>
      <p>No portal documents yet for this contact.<br>Quotes and invoices will appear here once they've been sent.</p>
    </div>
  <?php else: ?>

    <?php if (!empty($quotes)): ?>
    <h2>Quotes</h2>
    <ul class="doc-list">
      <?php foreach ($quotes as $q): ?>
        <?php $portalUrl = $baseUrl . '/customer/quote.php?token=' . urlencode($q['access_token']); ?>
        <li>
          <div class="doc-info">
            <div class="doc-number"><?php echo htmlspecialchars($q['quote_number']); ?></div>
            <div class="doc-meta">
              <?php echo statusBadge($q['status']); ?>
              <span class="amount" style="margin-left:8px;">$<?php echo number_format($q['total_amount'] ?? 0, 2); ?></span>
              &nbsp;·&nbsp;<?php echo date('M j, Y', strtotime($q['created_at'])); ?>
            </div>
          </div>
          <div class="doc-actions">
            <button class="btn btn-copy" onclick="copyLink('<?php echo htmlspecialchars($portalUrl); ?>', this)">Copy Link</button>
            <a href="<?php echo htmlspecialchars($portalUrl); ?>" class="btn btn-primary" target="_blank">View</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($invoices)): ?>
    <h2>Invoices</h2>
    <ul class="doc-list">
      <?php foreach ($invoices as $inv): ?>
        <?php $portalUrl = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']); ?>
        <li>
          <div class="doc-info">
            <div class="doc-number"><?php echo htmlspecialchars($inv['invoice_number']); ?></div>
            <div class="doc-meta">
              <?php echo statusBadge($inv['status']); ?>
              <span class="amount" style="margin-left:8px;">$<?php echo number_format($inv['total_amount'] ?? 0, 2); ?></span>
              &nbsp;·&nbsp;<?php echo date('M j, Y', strtotime($inv['created_at'])); ?>
            </div>
          </div>
          <div class="doc-actions">
            <button class="btn btn-copy" onclick="copyLink('<?php echo htmlspecialchars($portalUrl); ?>', this)">Copy Link</button>
            <a href="<?php echo htmlspecialchars($portalUrl); ?>" class="btn btn-primary" target="_blank">View</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

  <?php endif; ?>
</div>

<div class="toast" id="toast">Link copied!</div>

<script>
function copyLink(url, btn) {
  navigator.clipboard.writeText(url).then(function() {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = orig; }, 2000);
    var toast = document.getElementById('toast');
    toast.classList.add('show');
    setTimeout(function() { toast.classList.remove('show'); }, 2500);
  });
}
</script>

</body>
</html>
