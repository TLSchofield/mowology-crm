<?php
/**
 * Client Portal Home
 * Two access modes:
 *   1. Admin preview  — ?contact_id=66  (requires CRM session)
 *   2. Client access  — ?token=XXXX     (no login, uses contacts.portal_token)
 *
 * Shows all portal-accessible quotes and invoices for a contact.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db = getDB();

$contact    = null;
$adminMode  = false;
$error      = '';

// ── Mode 1: Admin preview via CRM session ───────────────────────────────────
if (isset($_GET['contact_id'])) {
    require_once dirname(__DIR__) . '/loginAuth/auth.php';
    requireLogin();
    $adminMode = true;
    $contactId = intval($_GET['contact_id']);

    if (!$contactId) {
        $error = 'Invalid contact.';
    } else {
        $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token FROM contacts WHERE id = ? LIMIT 1");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) $error = 'Contact not found.';
    }

// ── Mode 2: Client access via portal token ───────────────────────────────────
} elseif (isset($_GET['token']) && $_GET['token'] !== '') {
    $token = trim($_GET['token']);
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token FROM contacts WHERE portal_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) $error = 'This portal link is invalid or has expired.';

} else {
    $error = 'No portal link provided.';
}

// ── Generate portal token if admin and contact has none ──────────────────────
if ($adminMode && $contact && empty($contact['portal_token'])) {
    $newToken = bin2hex(random_bytes(24)); // 48-char hex token
    $stmt = $db->prepare("UPDATE contacts SET portal_token = ? WHERE id = ?");
    $stmt->execute([$newToken, $contact['id']]);
    $contact['portal_token'] = $newToken;
}

// ── Load documents ────────────────────────────────────────────────────────────
$quotes   = [];
$invoices = [];

if ($contact && !$error) {
    $contactId = $contact['id'];

    // Quotes: link via property → site_contact_id
    $stmt = $db->prepare("
        SELECT q.id, q.quote_number, q.status, q.total_amount, q.access_token, q.created_at, q.title
        FROM quotes q
        JOIN properties pr ON q.property_id = pr.id
        WHERE pr.site_contact_id = ?
          AND q.access_token IS NOT NULL
          AND q.access_token != ''
        ORDER BY q.created_at DESC
    ");
    $stmt->execute([$contactId]);
    $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Invoices: via invoice_contacts junction table
    $stmt = $db->prepare("
        SELECT i.id, i.invoice_number, i.status, i.total AS total_amount, i.access_token, i.created_at, i.due_date
        FROM invoices i
        JOIN invoice_contacts ic ON ic.invoice_id = i.id
        WHERE ic.contact_id = ?
          AND i.access_token IS NOT NULL
          AND i.access_token != ''
        GROUP BY i.id
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$contactId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Completed visits: contact → properties → job_plans → job_visits
    $completedVisits = [];
    try {
        $stmt = $db->prepare("
            SELECT jv.id, jv.visit_number, jv.scheduled_date, jv.status,
                   jv.completed_at, jv.actual_duration_minutes, jv.actual_amount,
                   jp.title AS service_name, jp.service_type,
                   p.address AS property_address
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            JOIN properties p ON jp.property_id = p.id
            WHERE p.site_contact_id = ?
              AND jv.status = 'completed'
            ORDER BY jv.scheduled_date DESC
            LIMIT 20
        ");
        $stmt->execute([$contactId]);
        $completedVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $completedVisits = [];
    }
}

$contactName = $contact ? trim(htmlspecialchars($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) : 'Client';
$hasAnything = !empty($quotes) || !empty($invoices) || !empty($completedVisits);
$baseUrl     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$portalUrl   = $contact && !empty($contact['portal_token'])
               ? $baseUrl . '/customer/portal.php?token=' . urlencode($contact['portal_token'])
               : '';

function statusBadge(string $status, bool $overdue = false): string {
    if ($overdue) $status = 'overdue';
    $map = [
        'draft'     => ['bg' => '#9CA3AF', 'label' => 'Draft'],
        'sent'      => ['bg' => '#3B82F6', 'label' => 'Sent'],
        'accepted'  => ['bg' => '#2D8659', 'label' => 'Accepted'],
        'declined'  => ['bg' => '#EF4444', 'label' => 'Declined'],
        'expired'   => ['bg' => '#9CA3AF', 'label' => 'Expired'],
        'viewed'    => ['bg' => '#3B82F6', 'label' => 'Viewed'],
        'paid'      => ['bg' => '#2D8659', 'label' => 'Paid'],
        'partial'   => ['bg' => '#F59E0B', 'label' => 'Partial'],
        'overdue'   => ['bg' => '#EF4444', 'label' => 'Overdue'],
        'cancelled' => ['bg' => '#6B7280', 'label' => 'Cancelled'],
    ];
    $s = $map[$status] ?? ['bg' => '#6B7280', 'label' => ucfirst($status)];
    return '<span class="doc-badge" style="background:' . $s['bg'] . '">' . htmlspecialchars($s['label']) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Client Portal<?php if ($contact): ?> — <?php echo $contactName; ?><?php endif; ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #1f2937; min-height: 100vh; }

    /* Header */
    .portal-header { background: #0D3B2E; color: #fff; padding: 0 24px; height: 58px; display: flex; align-items: center; gap: 12px; }
    .portal-logo { font-size: 1.1rem; font-weight: 700; letter-spacing: -0.3px; }
    .portal-logo span { color: #7FD858; }
    .portal-header-right { margin-left: auto; display: flex; align-items: center; gap: 12px; font-size: 0.85rem; }
    .admin-tag { background: rgba(127,216,88,0.2); color: #7FD858; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .portal-header a { color: #7FD858; text-decoration: none; }
    .portal-header a:hover { text-decoration: underline; }

    /* Hero */
    .portal-hero { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 28px 24px; }
    .portal-hero-inner { max-width: 760px; margin: 0 auto; }
    .portal-welcome { font-size: 1.4rem; font-weight: 700; margin: 0 0 4px; }
    .portal-subtitle { color: #6b7280; font-size: 0.9rem; margin: 0 0 16px; }
    .portal-link-box { display: flex; align-items: center; gap: 8px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; max-width: 520px; }
    .portal-link-box input { flex: 1; border: none; background: transparent; font-size: 0.82rem; color: #6b7280; outline: none; cursor: text; }
    .portal-link-box .copy-btn { flex-shrink: 0; padding: 4px 12px; background: #2D8659; color: #fff; border: none; border-radius: 5px; font-size: 0.8rem; cursor: pointer; font-weight: 500; }
    .portal-link-box .copy-btn:hover { background: #1A5F4A; }
    .portal-link-label { font-size: 0.78rem; color: #9ca3af; margin-bottom: 4px; }

    /* Container */
    .container { max-width: 760px; margin: 28px auto; padding: 0 16px 48px; }

    /* Section */
    .section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin: 0 0 10px; }

    /* Doc cards */
    .doc-list { list-style: none; margin: 0 0 28px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .doc-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; transition: box-shadow 0.15s; }
    .doc-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
    .doc-icon { width: 38px; height: 38px; border-radius: 8px; background: #E8F3F0; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .doc-icon svg { color: #2D8659; }
    .doc-info { flex: 1; min-width: 0; }
    .doc-number { font-weight: 600; font-size: 0.95rem; }
    .doc-title { font-size: 0.82rem; color: #6b7280; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .doc-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
    .doc-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 600; color: #fff; }
    .doc-amount { font-size: 0.82rem; color: #374151; font-weight: 600; }
    .doc-date { font-size: 0.78rem; color: #9ca3af; }
    .doc-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .btn-view { display: inline-flex; align-items: center; gap: 5px; padding: 7px 16px; background: #2D8659; color: #fff; border-radius: 7px; font-size: 0.85rem; font-weight: 500; text-decoration: none; }
    .btn-view:hover { background: #1A5F4A; }
    .btn-copy-link { display: inline-flex; align-items: center; gap: 5px; padding: 7px 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 7px; font-size: 0.85rem; cursor: pointer; font-weight: 500; }
    .btn-copy-link:hover { background: #e5e7eb; }

    /* Empty state */
    .empty { background: #fff; border: 2px dashed #d1d5db; border-radius: 12px; padding: 48px 24px; text-align: center; color: #9ca3af; margin-bottom: 28px; }
    .empty-icon { width: 52px; height: 52px; margin: 0 auto 14px; opacity: 0.35; }
    .empty h3 { margin: 0 0 6px; font-size: 1rem; color: #6b7280; }
    .empty p { margin: 0; font-size: 0.87rem; line-height: 1.5; }

    /* Error */
    .error-page { max-width: 480px; margin: 80px auto; text-align: center; padding: 0 24px; }
    .error-page h2 { margin: 0 0 10px; }
    .error-page p { color: #6b7280; }

    /* Toast */
    .toast { position: fixed; bottom: 24px; right: 24px; background: #1f2937; color: #fff; padding: 10px 18px; border-radius: 8px; font-size: 0.875rem; opacity: 0; transition: opacity 0.3s; pointer-events: none; z-index: 9999; }
    .toast.show { opacity: 1; }

    /* Visit history */
    .visit-list { list-style: none; margin: 0 0 28px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .visit-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; display: flex; align-items: center; gap: 14px; }
    .visit-date-block { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 44px; flex-shrink: 0; }
    .visit-date-day { font-size: 1.3rem; font-weight: 700; color: #1f2937; line-height: 1; }
    .visit-date-mon { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; margin-top: 2px; }
    .visit-divider { width: 1px; height: 36px; background: #e5e7eb; flex-shrink: 0; }
    .visit-info { flex: 1; min-width: 0; }
    .visit-service { font-weight: 600; font-size: 0.95rem; }
    .visit-address { font-size: 0.82rem; color: #6b7280; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .visit-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
    .visit-badge-done { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 600; color: #fff; background: #2D8659; }
    .visit-duration { font-size: 0.78rem; color: #9ca3af; }
    .visit-amount { font-size: 0.82rem; color: #374151; font-weight: 600; }

    @media (max-width: 500px) {
      .doc-card { flex-wrap: wrap; }
      .doc-actions { width: 100%; justify-content: flex-end; }
      .btn-copy-link { display: none; }
      .portal-link-box { flex-wrap: wrap; }
      .visit-card { flex-wrap: wrap; }
    }
  </style>
</head>
<body>

<header class="portal-header">
  <div class="portal-logo">Mowo<span>logy</span></div>
  <?php if ($adminMode): ?>
    <span class="admin-tag">Admin Preview</span>
    <div class="portal-header-right">
      <a href="/crm/clients_appstack.php?action=view_contact&id=<?php echo (int)$contact['id']; ?>">← Back to CRM</a>
    </div>
  <?php endif; ?>
</header>

<?php if ($error): ?>
  <div class="error-page">
    <svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Portal Unavailable</h2>
    <p><?php echo htmlspecialchars($error); ?></p>
  </div>

<?php else: ?>

  <div class="portal-hero">
    <div class="portal-hero-inner">
      <h1 class="portal-welcome">Welcome, <?php echo $contactName; ?></h1>
      <p class="portal-subtitle">Your quotes and invoices from Mowology are listed below.</p>
      <?php if ($adminMode && $portalUrl): ?>
        <div class="portal-link-label">Client's portal link (copy &amp; share with client)</div>
        <div class="portal-link-box">
          <input type="text" id="portal-url-input" value="<?php echo htmlspecialchars($portalUrl); ?>" readonly>
          <button class="copy-btn" onclick="copyPortalLink()">Copy</button>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="container">

    <?php if (!$hasAnything): ?>
      <div class="empty">
        <svg class="empty-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        <h3>No documents yet</h3>
        <p>Your quotes and invoices will appear here once they've been sent.<br>
           Have a question? Call us at (778) 846-9273.</p>
      </div>

    <?php else: ?>

      <?php if (!empty($quotes)): ?>
        <p class="section-title">Quotes</p>
        <ul class="doc-list">
          <?php foreach ($quotes as $q): ?>
            <?php $url = $baseUrl . '/customer/quote.php?token=' . urlencode($q['access_token']); ?>
            <li class="doc-card">
              <div class="doc-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              </div>
              <div class="doc-info">
                <div class="doc-number"><?php echo htmlspecialchars($q['quote_number']); ?></div>
                <?php if (!empty($q['title'])): ?>
                  <div class="doc-title"><?php echo htmlspecialchars($q['title']); ?></div>
                <?php endif; ?>
                <div class="doc-meta">
                  <?php echo statusBadge($q['status']); ?>
                  <?php if ($q['total_amount'] > 0): ?>
                    <span class="doc-amount">$<?php echo number_format($q['total_amount'], 2); ?></span>
                  <?php endif; ?>
                  <span class="doc-date"><?php echo date('M j, Y', strtotime($q['created_at'])); ?></span>
                </div>
              </div>
              <div class="doc-actions">
                <button class="btn-copy-link" onclick="copyLink('<?php echo htmlspecialchars($url, ENT_QUOTES); ?>', this)">Copy</button>
                <a href="<?php echo htmlspecialchars($url); ?>" class="btn-view">View</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($invoices)): ?>
        <p class="section-title">Invoices</p>
        <ul class="doc-list">
          <?php foreach ($invoices as $inv): ?>
            <?php
              $url = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']);
              $isPastDue = $inv['status'] === 'overdue' || (in_array($inv['status'], ['sent', 'partial', 'viewed']) && strtotime($inv['due_date']) < time());
            ?>
            <li class="doc-card">
              <div class="doc-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              </div>
              <div class="doc-info">
                <div class="doc-number"><?php echo htmlspecialchars($inv['invoice_number']); ?></div>
                <div class="doc-meta">
                  <?php echo statusBadge($inv['status'], $isPastDue); ?>
                  <?php if ($inv['total_amount'] > 0): ?>
                    <span class="doc-amount">$<?php echo number_format($inv['total_amount'], 2); ?></span>
                  <?php endif; ?>
                  <span class="doc-date">Due <?php echo date('M j, Y', strtotime($inv['due_date'])); ?></span>
                </div>
              </div>
              <div class="doc-actions">
                <button class="btn-copy-link" onclick="copyLink('<?php echo htmlspecialchars($url, ENT_QUOTES); ?>', this)">Copy</button>
                <a href="<?php echo htmlspecialchars($url); ?>" class="btn-view">View</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($completedVisits)): ?>
        <p class="section-title">Service History</p>
        <ul class="visit-list">
          <?php foreach ($completedVisits as $v): ?>
            <?php
              $ts       = strtotime($v['scheduled_date']);
              $dayNum   = date('j', $ts);
              $mon      = date('M', $ts);
              $label    = htmlspecialchars($v['service_name'] ?: ucwords(str_replace('_', ' ', $v['service_type'])));
              $addr     = htmlspecialchars($v['property_address'] ?? '');
              $durMins  = (int)($v['actual_duration_minutes'] ?? 0);
              if ($durMins > 0) {
                  $durHrs  = intdiv($durMins, 60);
                  $durRem  = $durMins % 60;
                  $durStr  = $durHrs > 0
                    ? ($durHrs . 'h' . ($durRem > 0 ? ' ' . $durRem . 'm' : ''))
                    : ($durRem . 'm');
              } else {
                  $durStr = '';
              }
              $amt = $v['actual_amount'] !== null && $v['actual_amount'] > 0
                     ? '$' . number_format((float)$v['actual_amount'], 2)
                     : '';
            ?>
            <li class="visit-card">
              <div class="visit-date-block">
                <div class="visit-date-day"><?php echo $dayNum; ?></div>
                <div class="visit-date-mon"><?php echo $mon; ?></div>
              </div>
              <div class="visit-divider"></div>
              <div class="visit-info">
                <div class="visit-service"><?php echo $label; ?></div>
                <?php if ($addr): ?>
                  <div class="visit-address"><?php echo $addr; ?></div>
                <?php endif; ?>
                <div class="visit-meta">
                  <span class="visit-badge-done">Completed</span>
                  <?php if ($durStr): ?>
                    <span class="visit-duration"><?php echo htmlspecialchars($durStr); ?></span>
                  <?php endif; ?>
                  <?php if ($amt): ?>
                    <span class="visit-amount"><?php echo htmlspecialchars($amt); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php endif; ?>

    <p style="font-size:0.8rem;color:#9ca3af;text-align:center;margin:0;">
      Questions? Call <a href="tel:7788469273" style="color:#2D8659;">(778) 846-9273</a> or email <a href="mailto:tim@mowology.ca" style="color:#2D8659;">tim@mowology.ca</a>
    </p>

  </div>

<?php endif; ?>

<div class="toast" id="toast">Link copied!</div>

<script>
function copyLink(url, btn) {
  navigator.clipboard.writeText(url).then(function() {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = orig; }, 2000);
    showToast();
  });
}
function copyPortalLink() {
  var input = document.getElementById('portal-url-input');
  navigator.clipboard.writeText(input.value).then(function() {
    showToast('Portal link copied!');
  });
}
function showToast(msg) {
  var toast = document.getElementById('toast');
  toast.textContent = msg || 'Link copied!';
  toast.classList.add('show');
  setTimeout(function() { toast.classList.remove('show'); }, 2500);
}
</script>

</body>
</html>
