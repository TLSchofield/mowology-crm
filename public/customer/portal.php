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
        $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token,
                                     stripe_card_brand, stripe_card_last4, stripe_card_exp, autopay_enabled
                              FROM contacts WHERE id = ? LIMIT 1");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) $error = 'Contact not found.';
    }

// ── Mode 2: Client access via portal token ───────────────────────────────────
} elseif (isset($_GET['token']) && $_GET['token'] !== '') {
    $token = trim($_GET['token']);
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token,
                                 stripe_card_brand, stripe_card_last4, stripe_card_exp, autopay_enabled
                          FROM contacts WHERE portal_token = ? LIMIT 1");
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
        SELECT i.id, i.invoice_number, i.status, i.total AS total_amount,
               i.amount_paid, i.balance_due, i.access_token, i.created_at, i.due_date,
               i.company_id
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

    // Payment history (online Stripe payments, newest first)
    $paymentHistory = [];
    try {
        if (!empty($invoices)) {
            $phInvIds = array_column($invoices, 'id');
            $phPlaceholders = implode(',', array_fill(0, count($phInvIds), '?'));
            $stmt = $db->prepare("
                SELECT sp.invoice_id, sp.amount_cents, sp.webhook_received_at,
                       sp.payment_method_type, sp.stripe_receipt_url,
                       i.invoice_number
                FROM stripe_payments sp
                JOIN invoices i ON i.id = sp.invoice_id
                WHERE sp.invoice_id IN ($phPlaceholders) AND sp.status = 'succeeded'
                ORDER BY sp.webhook_received_at DESC
                LIMIT 20
            ");
            $stmt->execute($phInvIds);
            $paymentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $paymentHistory = [];
    }

    // ── Company link detection (dual-account split view) ─────────────────────
    $linkedCompany      = null;
    $personalInvoices   = $invoices;
    $businessInvoices   = [];
    $bizBalance         = 0.0;
    $bizTotalCharged    = 0.0;
    $bizTotalPaid       = 0.0;

    try {
        $coStmt = $db->prepare("
            SELECT id, company_name,
                   stripe_card_brand, stripe_card_last4, stripe_card_exp, autopay_enabled
            FROM companies
            WHERE (primary_contact_id = ? OR billing_contact_id = ?)
            LIMIT 1
        ");
        $coStmt->execute([$contactId, $contactId]);
        $linkedCompany = $coStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { $linkedCompany = null; }

    if ($linkedCompany) {
        $coId = $linkedCompany['id'];

        // Split already-loaded invoices: personal = no company link to this company
        $personalInvoices = array_filter($invoices, fn($i) => empty($i['company_id']) || (int)$i['company_id'] !== $coId);
        $personalInvoices = array_values($personalInvoices);

        // Business invoices: direct company_id match
        try {
            $bizStmt = $db->prepare("
                SELECT i.id, i.invoice_number, i.status, i.total AS total_amount,
                       i.amount_paid, i.balance_due, i.access_token, i.created_at, i.due_date
                FROM invoices i
                WHERE i.company_id = ?
                  AND i.access_token IS NOT NULL AND i.access_token != ''
                  AND i.status NOT IN ('draft', 'cancelled')
                ORDER BY i.created_at DESC
                LIMIT 30
            ");
            $bizStmt->execute([$coId]);
            $businessInvoices = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $businessInvoices = []; }

        foreach ($businessInvoices as $inv) {
            $bizTotalCharged += floatval($inv['total_amount'] ?? 0);
            $bizTotalPaid    += floatval($inv['amount_paid'] ?? 0);
            if (in_array($inv['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
                $bizBalance += floatval($inv['balance_due'] ?? 0);
            }
        }
    }

    // Billing summary totals
    $billingTotalCharged = 0.0;
    $billingTotalPaid    = 0.0;
    $billingOutstanding  = 0.0;
    foreach ($invoices as $inv) {
        $billingTotalCharged += floatval($inv['total_amount'] ?? 0);
        $billingTotalPaid    += floatval($inv['amount_paid'] ?? 0);
        if (in_array($inv['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
            $billingOutstanding += floatval($inv['balance_due'] ?? 0);
        }
    }

    // Upcoming visits: scheduled or in-progress, today onwards
    $upcomingVisits = [];
    try {
        $stmt = $db->prepare("
            SELECT jv.id, jv.visit_number, jv.scheduled_date,
                   jv.scheduled_time_start, jv.scheduled_time_end, jv.status,
                   jp.title AS service_name, jp.service_type,
                   p.address AS property_address
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            JOIN properties p ON jp.property_id = p.id
            WHERE p.site_contact_id = ?
              AND jv.scheduled_date >= CURDATE()
              AND jv.status IN ('scheduled', 'in_progress')
            ORDER BY jv.scheduled_date ASC, jv.scheduled_time_start ASC
            LIMIT 10
        ");
        $stmt->execute([$contactId]);
        $upcomingVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $upcomingVisits = [];
    }

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

    // Winter Service Reports (salt / snow) — portal-available PDFs only
    $saltReports = [];
    try {
        $stmt = $db->prepare("
            SELECT srr.visit_id, srr.report_number, srr.pdf_path,
                   srr.pm_email_sent_at, srr.portal_available_at,
                   jv.scheduled_date,
                   pr.address AS property_address, pr.city AS property_city
            FROM salt_run_reports srr
            JOIN job_visits jv ON jv.id = srr.visit_id
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties pr ON pr.id = jp.property_id
            WHERE pr.site_contact_id = ?
              AND srr.portal_available_at IS NOT NULL
              AND srr.pdf_path IS NOT NULL
            ORDER BY jv.scheduled_date DESC
            LIMIT 50
        ");
        $stmt->execute([$contactId]);
        $saltReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $saltReports = [];
    }

    // Photos for all completed visits
    $visitPhotos = [];
    if (!empty($completedVisits)) {
        $visitIds = array_column($completedVisits, 'id');
        $placeholders = implode(',', array_fill(0, count($visitIds), '?'));
        try {
            $stmt = $db->prepare("
                SELECT visit_id, id, filename, photo_type, caption
                FROM visit_photos
                WHERE visit_id IN ($placeholders)
                ORDER BY photo_type, uploaded_at
            ");
            $stmt->execute($visitIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
                $visitPhotos[$photo['visit_id']][] = $photo;
            }
        } catch (Exception $e) {
            // photos unavailable — silently skip
        }
    }
}

$firstName   = $contact ? htmlspecialchars($contact['first_name']) : 'there';
$contactName = $contact ? trim(htmlspecialchars($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) : 'Client';
$hasAnything = !empty($quotes) || !empty($invoices) || !empty($completedVisits);
$baseUrl     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$portalUrl   = $contact && !empty($contact['portal_token'])
               ? $baseUrl . '/customer/portal.php?token=' . urlencode($contact['portal_token'])
               : '';

// ── Action bar: pin pending quotes and overdue/unpaid invoices ──────────────
$actionItems = [];
foreach ($quotes as $q) {
    if ($q['status'] === 'sent') {
        $actionItems[] = [
            'type'   => 'quote',
            'label'  => 'Quote awaiting your approval',
            'sub'    => htmlspecialchars($q['quote_number']) . ($q['total_amount'] > 0 ? ' · $' . number_format($q['total_amount'], 2) : ''),
            'href'   => $baseUrl . '/customer/quote.php?token=' . urlencode($q['access_token']),
            'urgent' => false,
        ];
    }
}
foreach ($invoices as $inv) {
    $isPastDue = $inv['status'] === 'overdue'
        || (in_array($inv['status'], ['sent', 'partial', 'viewed'])
            && !empty($inv['due_date'])
            && strtotime($inv['due_date']) < time());
    if ($isPastDue || $inv['status'] === 'sent') {
        $actionItems[] = [
            'type'   => 'invoice',
            'label'  => $isPastDue ? 'Invoice overdue — payment needed' : 'Invoice awaiting payment',
            'sub'    => htmlspecialchars($inv['invoice_number']) . ($inv['total_amount'] > 0 ? ' · $' . number_format($inv['total_amount'], 2) : ''),
            'href'   => $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']),
            'urgent' => $isPastDue,
        ];
    }
}

function statusBadge(string $status, bool $overdue = false): string {
    if ($overdue) $status = 'overdue';
    $labels = [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'accepted'  => 'Accepted',
        'declined'  => 'Declined',
        'expired'   => 'Expired',
        'viewed'    => 'Viewed',
        'paid'      => 'Paid',
        'partial'   => 'Partial',
        'overdue'   => 'Overdue',
        'cancelled' => 'Cancelled',
    ];
    $label = $labels[$status] ?? ucfirst($status);
    return '<span class="portal-status status-' . htmlspecialchars($status) . '">' . htmlspecialchars($label) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Client Portal<?php if ($contact): ?> — <?php echo $contactName; ?><?php endif; ?></title>
  <link rel="stylesheet" href="/customer/portal.css">
</head>
<body>

<header class="portal-header">
  <div class="portal-logo-text">Mowo<span>logy</span></div>
  <div class="portal-header-divider"></div>
  <span class="portal-header-label">Client Portal</span>
  <?php if ($contact): ?>
    <span class="portal-client-name"><?php echo $contactName; ?></span>
  <?php endif; ?>
</header>

<?php if ($adminMode): ?>
<div class="portal-admin-banner">
  <div style="display:flex;align-items:center;gap:8px;">
    <span class="portal-admin-tag">Admin Preview</span>
    <span>Viewing as <strong><?php echo $contactName; ?></strong></span>
  </div>
  <a href="/crm/clients_appstack.php?action=view_contact&id=<?php echo (int)$contact['id']; ?>">← Back to CRM</a>
</div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="portal-error">
    <svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Portal Unavailable</h2>
    <p><?php echo htmlspecialchars($error); ?></p>
  </div>

<?php else: ?>

  <div class="portal-container">

    <div class="portal-hero">
      <div class="portal-hero-greeting">Hello, <?php echo $firstName; ?>.</div>
      <?php
        $pendingCount = count($actionItems);
        if ($pendingCount === 1):
          $subText = 'You have 1 item that needs your attention.';
        elseif ($pendingCount > 1):
          $subText = "You have {$pendingCount} items that need your attention.";
        else:
          $subText = 'Your quotes, invoices, and service history are listed below.';
        endif;
      ?>
      <div class="portal-hero-sub"><?php echo $subText; ?></div>
      <?php if ($adminMode && $portalUrl): ?>
        <div class="portal-link-label">Client's portal link</div>
        <div class="portal-link-copy">
          <input type="text" id="portal-url-input" value="<?php echo htmlspecialchars($portalUrl); ?>" readonly>
          <button onclick="copyPortalLink()">Copy</button>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($actionItems)): ?>
    <div class="portal-action-bar">
      <?php foreach ($actionItems as $ai): ?>
      <div class="portal-action-item">
        <div class="portal-action-dot<?php echo $ai['urgent'] ? ' urgent' : ''; ?>"></div>
        <div>
          <div class="portal-action-label"><?php echo $ai['label']; ?></div>
          <div class="portal-action-sub"><?php echo $ai['sub']; ?></div>
        </div>
        <a href="<?php echo htmlspecialchars($ai['href']); ?>" class="portal-action-link">
          <?php echo $ai['type'] === 'quote' ? 'Review &amp; Accept →' : 'Pay Now →'; ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$hasAnything): ?>
      <div class="portal-empty">
        <svg class="portal-empty-icon" xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        <h3>No documents yet</h3>
        <p>Your quotes and invoices will appear here once they've been sent.<br>
           Have a question? Call us at (778) 846-9273.</p>
      </div>

    <?php else: ?>

      <?php if ($linkedCompany): ?>
        <!-- ── Dual-account split view ──────────────────────────────────── -->
        <div class="portal-accounts-split">

          <?php
          // Shared macro: render an invoice list inside a panel
          function renderPortalInvoicePanel(array $invs, string $baseUrl): void {
              $unpaidStatuses = ['sent', 'viewed', 'partial', 'overdue'];
              $unpaid = array_filter($invs, fn($i) => in_array($i['status'], $unpaidStatuses, true));
              $paid   = array_filter($invs, fn($i) => !in_array($i['status'], $unpaidStatuses, true));
              $icon   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>';
              if (empty($invs)) { echo '<p class="portal-panel-empty">No invoices yet.</p>'; return; }
              if (!empty($unpaid)):
          ?>
                <p class="portal-panel-section-label outstanding">Outstanding (<?= count($unpaid) ?>)</p>
                <ul class="portal-doc-list portal-doc-list--compact">
                  <?php foreach ($unpaid as $inv):
                      $url = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']);
                      $bal = floatval($inv['balance_due'] ?? $inv['total_amount'] ?? 0);
                      $pastDue = $inv['status'] === 'overdue' || (in_array($inv['status'], ['sent','partial','viewed']) && !empty($inv['due_date']) && strtotime($inv['due_date']) < time());
                  ?>
                    <li class="portal-doc-card portal-doc-card--outstanding">
                      <div class="portal-doc-icon"><?= $icon ?></div>
                      <div class="portal-doc-info">
                        <div class="portal-doc-number"><?= htmlspecialchars($inv['invoice_number']) ?></div>
                        <div class="portal-doc-meta">
                          <?= statusBadge($inv['status'], $pastDue) ?>
                          <?php if ($bal > 0): ?><span class="portal-doc-amount">$<?= number_format($bal, 2) ?> due</span><?php endif; ?>
                        </div>
                      </div>
                      <div class="portal-doc-actions"><a href="<?= htmlspecialchars($url) ?>" class="portal-btn">Pay Now</a></div>
                    </li>
                  <?php endforeach; ?>
                </ul>
          <?php endif; if (!empty($paid)): ?>
                <p class="portal-panel-section-label" style="<?= !empty($unpaid) ? 'margin-top:12px;' : '' ?>">Paid</p>
                <ul class="portal-doc-list portal-doc-list--compact">
                  <?php foreach ($paid as $inv):
                      $url = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']);
                  ?>
                    <li class="portal-doc-card">
                      <div class="portal-doc-icon"><?= $icon ?></div>
                      <div class="portal-doc-info">
                        <div class="portal-doc-number"><?= htmlspecialchars($inv['invoice_number']) ?></div>
                        <div class="portal-doc-meta">
                          <?= statusBadge($inv['status']) ?>
                          <?php if ($inv['total_amount'] > 0): ?><span class="portal-doc-amount">$<?= number_format($inv['total_amount'], 2) ?></span><?php endif; ?>
                        </div>
                      </div>
                      <div class="portal-doc-actions"><a href="<?= htmlspecialchars($url) ?>" class="portal-btn-outline">View</a></div>
                    </li>
                  <?php endforeach; ?>
                </ul>
          <?php endif; }
          ?>

          <!-- Personal panel -->
          <div class="portal-account-panel">
            <div class="portal-account-header">
              <span class="portal-account-badge personal">Personal</span>
              <div class="portal-account-name"><?= $contactName ?></div>
              <?php if (!empty($contact['stripe_card_last4'])): ?>
                <div class="portal-account-card">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                  <?= htmlspecialchars(ucfirst($contact['stripe_card_brand'] ?? 'Card')) ?> ···· <?= htmlspecialchars($contact['stripe_card_last4']) ?>
                  <?php if (!empty($contact['autopay_enabled'])): ?><span class="portal-autopay-tag">Autopay</span><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="portal-account-balance <?= $billingOutstanding > 0 ? 'owing' : 'clear' ?>">
                $<?= number_format($billingOutstanding, 2) ?>
                <span><?= $billingOutstanding > 0 ? 'outstanding' : 'all clear' ?></span>
              </div>
            </div>
            <?php renderPortalInvoicePanel($personalInvoices, $baseUrl); ?>
          </div>

          <!-- Business panel -->
          <div class="portal-account-panel">
            <div class="portal-account-header">
              <span class="portal-account-badge business">Business</span>
              <div class="portal-account-name"><?= htmlspecialchars($linkedCompany['company_name']) ?></div>
              <?php if (!empty($linkedCompany['stripe_card_last4'])): ?>
                <div class="portal-account-card">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                  <?= htmlspecialchars(ucfirst($linkedCompany['stripe_card_brand'] ?? 'Card')) ?> ···· <?= htmlspecialchars($linkedCompany['stripe_card_last4']) ?>
                  <?php if (!empty($linkedCompany['autopay_enabled'])): ?><span class="portal-autopay-tag">Autopay</span><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="portal-account-balance <?= $bizBalance > 0 ? 'owing' : 'clear' ?>">
                $<?= number_format($bizBalance, 2) ?>
                <span><?= $bizBalance > 0 ? 'outstanding' : 'all clear' ?></span>
              </div>
            </div>
            <?php renderPortalInvoicePanel($businessInvoices, $baseUrl); ?>
          </div>

        </div><!-- .portal-accounts-split -->

      <?php else: ?>
        <!-- ── Standard single-account view ─────────────────────────────── -->
      <?php if ($billingOutstanding > 0 || $billingTotalPaid > 0): ?>
        <div class="portal-billing-summary">
          <div class="portal-billing-stat">
            <div class="portal-billing-stat-label">Total Invoiced</div>
            <div class="portal-billing-stat-value">$<?php echo number_format($billingTotalCharged, 2); ?></div>
          </div>
          <div class="portal-billing-divider"></div>
          <div class="portal-billing-stat">
            <div class="portal-billing-stat-label">Paid</div>
            <div class="portal-billing-stat-value portal-billing-paid">$<?php echo number_format($billingTotalPaid, 2); ?></div>
          </div>
          <div class="portal-billing-divider"></div>
          <div class="portal-billing-stat">
            <div class="portal-billing-stat-label"><?php echo $billingOutstanding > 0 ? 'Balance Due' : 'Outstanding'; ?></div>
            <div class="portal-billing-stat-value <?php echo $billingOutstanding > 0 ? 'portal-billing-due' : 'portal-billing-paid'; ?>">
              $<?php echo number_format($billingOutstanding, 2); ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($quotes)): ?>
        <p class="portal-section-label">Quotes</p>
        <ul class="portal-doc-list">
          <?php foreach ($quotes as $q): ?>
            <?php $url = $baseUrl . '/customer/quote.php?token=' . urlencode($q['access_token']); ?>
            <li class="portal-doc-card">
              <div class="portal-doc-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              </div>
              <div class="portal-doc-info">
                <div class="portal-doc-number"><?php echo htmlspecialchars($q['quote_number']); ?></div>
                <?php if (!empty($q['title'])): ?>
                  <div class="portal-doc-title"><?php echo htmlspecialchars($q['title']); ?></div>
                <?php endif; ?>
                <div class="portal-doc-meta">
                  <?php echo statusBadge($q['status']); ?>
                  <?php if ($q['total_amount'] > 0): ?>
                    <span class="portal-doc-amount">$<?php echo number_format($q['total_amount'], 2); ?></span>
                  <?php endif; ?>
                  <span class="portal-doc-date"><?php echo date('M j, Y', strtotime($q['created_at'])); ?></span>
                </div>
              </div>
              <div class="portal-doc-actions">
                <button class="portal-btn-outline" onclick="copyLink('<?php echo htmlspecialchars($url, ENT_QUOTES); ?>', this)">Copy</button>
                <a href="<?php echo htmlspecialchars($url); ?>" class="portal-btn">View</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($invoices)):
          $unpaidStatuses  = ['sent', 'viewed', 'partial', 'overdue'];
          $unpaidInvoices  = array_filter($invoices, fn($i) => in_array($i['status'], $unpaidStatuses, true));
          $paidInvoices    = array_filter($invoices, fn($i) => !in_array($i['status'], $unpaidStatuses, true));
          $invoiceIcon     = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>';
      ?>

        <?php if (!empty($unpaidInvoices)): ?>
          <p class="portal-section-label portal-section-label--outstanding">
            Outstanding (<?php echo count($unpaidInvoices); ?>)
          </p>
          <ul class="portal-doc-list">
            <?php foreach ($unpaidInvoices as $inv): ?>
              <?php
                $url = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']);
                $isPastDue = $inv['status'] === 'overdue'
                    || (in_array($inv['status'], ['sent', 'partial', 'viewed'])
                        && !empty($inv['due_date'])
                        && strtotime($inv['due_date']) < time());
                $balDue  = floatval($inv['balance_due'] ?? $inv['total_amount'] ?? 0);
                $amtPaid = floatval($inv['amount_paid'] ?? 0);
              ?>
              <li class="portal-doc-card portal-doc-card--outstanding">
                <div class="portal-doc-icon">
                  <?php echo $invoiceIcon; ?>
                </div>
                <div class="portal-doc-info">
                  <div class="portal-doc-number"><?php echo htmlspecialchars($inv['invoice_number']); ?></div>
                  <div class="portal-doc-meta">
                    <?php echo statusBadge($inv['status'], $isPastDue); ?>
                    <?php if ($balDue > 0): ?>
                      <span class="portal-doc-amount">$<?php echo number_format($balDue, 2); ?> due</span>
                    <?php endif; ?>
                    <?php if (!empty($inv['due_date'])): ?>
                      <span class="portal-doc-date">Due <?php echo date('M j, Y', strtotime($inv['due_date'])); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="portal-doc-actions">
                  <a href="<?php echo htmlspecialchars($url); ?>" class="portal-btn">Pay Now</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($paidInvoices)): ?>
          <p class="portal-section-label" style="margin-top:<?php echo !empty($unpaidInvoices) ? '20px' : '0'; ?>">
            Invoices
          </p>
          <ul class="portal-doc-list">
            <?php foreach ($paidInvoices as $inv): ?>
              <?php $url = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']); ?>
              <li class="portal-doc-card">
                <div class="portal-doc-icon">
                  <?php echo $invoiceIcon; ?>
                </div>
                <div class="portal-doc-info">
                  <div class="portal-doc-number"><?php echo htmlspecialchars($inv['invoice_number']); ?></div>
                  <div class="portal-doc-meta">
                    <?php echo statusBadge($inv['status']); ?>
                    <?php if ($inv['total_amount'] > 0): ?>
                      <span class="portal-doc-amount">$<?php echo number_format($inv['total_amount'], 2); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($inv['due_date'])): ?>
                      <span class="portal-doc-date">Due <?php echo date('M j, Y', strtotime($inv['due_date'])); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="portal-doc-actions">
                  <button class="portal-btn-outline" onclick="copyLink('<?php echo htmlspecialchars($url, ENT_QUOTES); ?>', this)">Copy</button>
                  <a href="<?php echo htmlspecialchars($url); ?>" class="portal-btn-outline">View</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      <?php endif; ?>
      <?php endif; // end else (standard single-account view) ?>

      <?php if (!empty($upcomingVisits)): ?>
        <p class="portal-section-label">Upcoming Visits</p>
        <ul class="portal-visit-list">
          <?php foreach ($upcomingVisits as $uv): ?>
            <?php
              $uts     = strtotime($uv['scheduled_date']);
              $uDay    = date('j', $uts);
              $uMon    = date('M', $uts);
              $uLabel  = htmlspecialchars($uv['service_name'] ?: ucwords(str_replace('_', ' ', $uv['service_type'])));
              $uAddr   = htmlspecialchars($uv['property_address'] ?? '');
              $uTime   = '';
              if (!empty($uv['scheduled_time_start'])) {
                  $uTime = date('g:ia', strtotime($uv['scheduled_time_start']));
                  if (!empty($uv['scheduled_time_end'])) {
                      $uTime .= ' – ' . date('g:ia', strtotime($uv['scheduled_time_end']));
                  }
              }
              $uIsToday = (date('Y-m-d') === $uv['scheduled_date']);
              $uStatusLabel = $uv['status'] === 'in_progress' ? 'In Progress' : ($uIsToday ? 'Today' : 'Scheduled');
              $uStatusClass = $uv['status'] === 'in_progress' ? 'portal-visit-progress' : ($uIsToday ? 'portal-visit-today' : 'portal-visit-upcoming');
            ?>
            <li class="portal-visit-card upcoming">
              <div class="portal-visit-date">
                <div class="portal-visit-day"><?php echo $uDay; ?></div>
                <div class="portal-visit-mon"><?php echo $uMon; ?></div>
              </div>
              <div class="portal-visit-divider"></div>
              <div class="portal-visit-info">
                <div class="portal-visit-service"><?php echo $uLabel; ?></div>
                <?php if ($uAddr): ?>
                  <div class="portal-visit-address"><?php echo $uAddr; ?></div>
                <?php endif; ?>
                <div class="portal-visit-meta">
                  <span class="<?php echo $uStatusClass; ?>"><?php echo $uStatusLabel; ?></span>
                  <?php if ($uTime): ?>
                    <span class="portal-visit-stat"><?php echo htmlspecialchars($uTime); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($completedVisits)): ?>
        <p class="portal-section-label">Service History</p>
        <ul class="portal-visit-list">
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
            <li class="portal-visit-card">
              <div class="portal-visit-date">
                <div class="portal-visit-day"><?php echo $dayNum; ?></div>
                <div class="portal-visit-mon"><?php echo $mon; ?></div>
              </div>
              <div class="portal-visit-divider"></div>
              <div class="portal-visit-info">
                <div class="portal-visit-service"><?php echo $label; ?></div>
                <?php if ($addr): ?>
                  <div class="portal-visit-address"><?php echo $addr; ?></div>
                <?php endif; ?>
                <div class="portal-visit-meta">
                  <span class="portal-visit-done">Completed</span>
                  <?php if ($durStr): ?>
                    <span class="portal-visit-stat"><?php echo htmlspecialchars($durStr); ?></span>
                  <?php endif; ?>
                  <?php if ($amt): ?>
                    <span class="portal-visit-stat"><?php echo htmlspecialchars($amt); ?></span>
                  <?php endif; ?>
                </div>
                <?php
                  $vPhotos = $visitPhotos[$v['id']] ?? [];
                  $showMax = 5;
                  $extra   = count($vPhotos) - $showMax;
                ?>
                <?php if (!empty($vPhotos)): ?>
                  <?php
                    $photoUrls = array_map(fn($p) => [
                        'src'     => '/uploads/photos/' . $p['filename'],
                        'caption' => $p['caption'] ?? ucfirst($p['photo_type']),
                    ], $vPhotos);
                    $photoJson = htmlspecialchars(json_encode($photoUrls), ENT_QUOTES);
                  ?>
                  <div class="portal-photo-grid">
                    <?php foreach (array_slice($vPhotos, 0, $showMax) as $pi => $ph): ?>
                      <div class="portal-photo-thumb" onclick="openLightbox(<?php echo htmlspecialchars($photoJson, ENT_QUOTES); ?>, <?php echo $pi; ?>)">
                        <img src="/uploads/photos/<?php echo htmlspecialchars($ph['filename']); ?>"
                             alt="<?php echo htmlspecialchars($ph['photo_type']); ?>"
                             loading="lazy">
                      </div>
                    <?php endforeach; ?>
                    <?php if ($extra > 0): ?>
                      <div class="portal-photo-more" onclick="openLightbox(<?php echo htmlspecialchars($photoJson, ENT_QUOTES); ?>, <?php echo $showMax; ?>)">
                        +<?php echo $extra; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($paymentHistory)): ?>
        <p class="portal-section-label">Payment History</p>
        <ul class="portal-payment-list">
          <?php foreach ($paymentHistory as $ph): ?>
            <?php
              $pmType = $ph['payment_method_type'] ?? 'card';
              $pmLabels = ['card' => 'Credit / Debit Card', 'us_bank_account' => 'Bank Transfer'];
              $pmLabel  = $pmLabels[$pmType] ?? ucwords(str_replace('_', ' ', $pmType));
              $paidAt   = $ph['webhook_received_at'] ?? '';
            ?>
            <li class="portal-payment-item">
              <div class="portal-payment-date-block">
                <?php if ($paidAt): ?>
                  <div class="portal-payment-day"><?php echo date('j', strtotime($paidAt)); ?></div>
                  <div class="portal-payment-mon"><?php echo date('M', strtotime($paidAt)); ?></div>
                <?php else: ?>
                  <div class="portal-payment-day">—</div>
                <?php endif; ?>
              </div>
              <div class="portal-payment-divider"></div>
              <div class="portal-payment-info">
                <div class="portal-payment-amount">$<?php echo number_format($ph['amount_cents'] / 100, 2); ?></div>
                <div class="portal-payment-desc">
                  Online · <?php echo htmlspecialchars($pmLabel); ?>
                  <span class="portal-payment-inv">&middot; <?php echo htmlspecialchars($ph['invoice_number']); ?></span>
                </div>
              </div>
              <?php if (!empty($ph['stripe_receipt_url'])): ?>
                <a href="<?php echo htmlspecialchars($ph['stripe_receipt_url']); ?>" target="_blank" class="portal-btn-outline portal-btn-sm">Receipt</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php endif; ?>

    <?php if (!empty($saltReports)): ?>
      <p class="portal-section-label">Winter Service Records</p>
      <p style="font-size:12px;color:#666;margin:-8px 0 12px;">
        Proof-of-work documents for salt application and snow removal visits.
      </p>
      <ul style="list-style:none;margin:0 0 24px;padding:0;">
        <?php foreach ($saltReports as $sr):
          $srDate    = date('F j, Y', strtotime($sr['scheduled_date']));
          $srProp    = htmlspecialchars(trim($sr['property_address'] . ', ' . $sr['property_city']));
          $srNum     = htmlspecialchars($sr['report_number'] ?? '');
          $srToken   = $contact ? urlencode($contact['portal_token']) : '';
          $srLink    = $baseUrl . '/customer/salt-report.php?token=' . $srToken . '&visit_id=' . (int)$sr['visit_id'];
        ?>
        <li style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eee;">
          <div>
            <div style="font-size:13px;font-weight:600;color:#1a1a1a;"><?= $srDate ?></div>
            <div style="font-size:11px;color:#666;"><?= $srProp ?></div>
            <?php if ($srNum): ?>
            <div style="font-size:10px;color:#999;font-family:monospace;"><?= $srNum ?></div>
            <?php endif; ?>
          </div>
          <a href="<?= $srLink ?>"
             class="portal-btn-outline portal-btn-sm"
             target="_blank"
             style="white-space:nowrap;">
            View PDF
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="portal-footer">
      Questions? Call <a href="tel:7788469273">(778) 846-9273</a> or email <a href="mailto:tim@mowology.ca">tim@mowology.ca</a>
    </div>

  </div>

<?php endif; ?>

<div class="portal-toast" id="toast">Link copied!</div>

<!-- Lightbox -->
<div class="portal-lightbox" id="lb" onclick="closeLightboxOnBg(event)">
  <button class="portal-lightbox-prev" onclick="lbStep(-1)">&#8249;</button>
  <div class="portal-lightbox-inner">
    <button class="portal-lightbox-close" onclick="closeLightbox()">&#x2715;</button>
    <img id="lb-img" src="" alt="">
    <div class="portal-lightbox-caption" id="lb-cap"></div>
  </div>
  <button class="portal-lightbox-next" onclick="lbStep(1)">&#8250;</button>
</div>

<script>
var lbPhotos = [], lbIdx = 0;
function openLightbox(photosJson, idx) {
  lbPhotos = typeof photosJson === 'string' ? JSON.parse(photosJson) : photosJson;
  lbIdx = idx || 0;
  lbShow();
  document.getElementById('lb').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function lbShow() {
  var p = lbPhotos[lbIdx];
  document.getElementById('lb-img').src = p.src;
  document.getElementById('lb-cap').textContent = p.caption || '';
  document.querySelector('.portal-lightbox-prev').style.display = lbPhotos.length > 1 ? '' : 'none';
  document.querySelector('.portal-lightbox-next').style.display = lbPhotos.length > 1 ? '' : 'none';
}
function lbStep(dir) {
  lbIdx = (lbIdx + dir + lbPhotos.length) % lbPhotos.length;
  lbShow();
}
function closeLightbox() {
  document.getElementById('lb').classList.remove('open');
  document.body.style.overflow = '';
}
function closeLightboxOnBg(e) {
  if (e.target === document.getElementById('lb')) closeLightbox();
}
document.addEventListener('keydown', function(e) {
  if (!document.getElementById('lb').classList.contains('open')) return;
  if (e.key === 'ArrowLeft') lbStep(-1);
  else if (e.key === 'ArrowRight') lbStep(1);
  else if (e.key === 'Escape') closeLightbox();
});
</script>

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
