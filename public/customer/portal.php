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
        SELECT i.id, i.invoice_number, i.status, i.total AS total_amount,
               i.amount_paid, i.balance_due, i.access_token, i.created_at, i.due_date
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

    // Photos for all completed visits — queried from the unified media system
    // (media_assets + media_links; visit_photos is the legacy table with 0 new rows)
    $visitPhotos  = [];
    $recentPhotos = [];
    $photoTotal   = 0;
    if (!empty($completedVisits)) {
        $visitIds     = array_column($completedVisits, 'id');
        $placeholders = implode(',', array_fill(0, count($visitIds), '?'));
        try {
            $stmt = $db->prepare("
                SELECT ml.context_id AS visit_id,
                       ma.id,
                       ma.file_path,
                       ml.category      AS photo_type,
                       ma.caption,
                       mv.file_path     AS thumb_path,
                       ma.created_at    AS uploaded_at
                FROM media_links ml
                JOIN media_assets ma ON ma.id = ml.media_id
                          AND ma.status != 'deleted'
                LEFT JOIN media_variants mv ON mv.media_id = ma.id
                          AND mv.variant_type = 'thumb_square'
                          AND mv.format = 'jpeg'
                WHERE ml.context_type = 'job_visit'
                  AND ml.context_id IN ($placeholders)
                ORDER BY ml.context_id ASC, ma.created_at DESC
            ");
            $stmt->execute($visitIds);
            $allRows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $photoTotal = count($allRows);
            $recentPhotos = array_slice($allRows, 0, 4);
            foreach ($allRows as $photo) {
                $visitPhotos[(int)$photo['visit_id']][] = $photo;
            }
        } catch (Exception $e) {
            // photos unavailable — silently skip
        }
    }

    // GPS pings for completed visits (crew_location_history linked by visit_id)
    $visitPings = [];
    if (!empty($completedVisits)) {
        $pingIds = array_column($completedVisits, 'id');
        $pingPH  = implode(',', array_fill(0, count($pingIds), '?'));
        try {
            $stmt = $db->prepare("
                SELECT visit_id, latitude, longitude, timestamp
                FROM crew_location_history
                WHERE visit_id IN ($pingPH)
                ORDER BY visit_id ASC, timestamp ASC
            ");
            $stmt->execute($pingIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ping) {
                $visitPings[(int)$ping['visit_id']][] = $ping;
            }
        } catch (Exception $e) {
            // pings unavailable
        }
    }
}

$hasAnyPings = !empty($visitPings);
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
      <?php if (!empty($contact['portal_token']) && !$adminMode): ?>
        <a href="/customer/profile.php?token=<?php echo urlencode($contact['portal_token']); ?>" class="portal-hero-link">
          &#9881; Update my details
        </a>
      <?php endif; ?>
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

    <?php if ($photoTotal > 0): ?>
    <?php
        $galleryUrl = !empty($contact['portal_token'])
            ? '/customer/gallery.php?token=' . urlencode($contact['portal_token'])
            : '/customer/gallery.php';
    ?>
    <a href="<?php echo htmlspecialchars($galleryUrl); ?>" class="portal-photos-summary">
      <div class="portal-photos-thumbs">
        <?php foreach ($recentPhotos as $rp):
            $url = $rp['thumb_path'] ?: $rp['file_path'];
        ?>
          <img src="<?php echo htmlspecialchars($url); ?>" alt="" loading="lazy">
        <?php endforeach; ?>
      </div>
      <div class="portal-photos-summary-text">
        <div class="portal-photos-summary-count"><?php echo $photoTotal; ?> photo<?php echo $photoTotal === 1 ? '' : 's'; ?></div>
        <div class="portal-photos-summary-sub">View your property gallery →</div>
      </div>
    </a>
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

      <?php if (!empty($invoices)): ?>
        <p class="portal-section-label">Invoices</p>
        <ul class="portal-doc-list">
          <?php foreach ($invoices as $inv): ?>
            <?php
              $url = $baseUrl . '/customer/invoice.php?token=' . urlencode($inv['access_token']);
              $isPastDue = $inv['status'] === 'overdue'
                  || (in_array($inv['status'], ['sent', 'partial', 'viewed'])
                      && !empty($inv['due_date'])
                      && strtotime($inv['due_date']) < time());
            ?>
            <li class="portal-doc-card">
              <div class="portal-doc-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              </div>
              <div class="portal-doc-info">
                <div class="portal-doc-number"><?php echo htmlspecialchars($inv['invoice_number']); ?></div>
                <div class="portal-doc-meta">
                  <?php echo statusBadge($inv['status'], $isPastDue); ?>
                  <?php if ($inv['total_amount'] > 0): ?>
                    <span class="portal-doc-amount">$<?php echo number_format($inv['total_amount'], 2); ?></span>
                  <?php endif; ?>
                  <?php
                    $balDue = floatval($inv['balance_due'] ?? 0);
                    $amtPaid = floatval($inv['amount_paid'] ?? 0);
                    if ($balDue > 0 && $amtPaid > 0):
                  ?>
                    <span class="portal-doc-balance">Balance: $<?php echo number_format($balDue, 2); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($inv['due_date'])): ?>
                    <span class="portal-doc-date">Due <?php echo date('M j, Y', strtotime($inv['due_date'])); ?></span>
                  <?php endif; ?>
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
                  <?php if (!empty($contact['portal_token']) && !$adminMode): ?>
                    <button type="button" class="portal-visit-rebook-btn" onclick="mwRebookVisit(<?php echo (int)$v['id']; ?>, '<?php echo htmlspecialchars(addslashes($label), ENT_QUOTES); ?>')">
                      &#8634; Book again
                    </button>
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
                        'src'     => $p['file_path'],
                        'caption' => $p['caption'] ?? ucfirst($p['photo_type'] ?? 'photo'),
                    ], $vPhotos);
                    $photoJson = htmlspecialchars(json_encode($photoUrls), ENT_QUOTES);
                  ?>
                  <div class="portal-photo-grid">
                    <?php foreach (array_slice($vPhotos, 0, $showMax) as $pi => $ph): ?>
                      <?php $thumbSrc = $ph['thumb_path'] ?: $ph['file_path']; ?>
                      <div class="portal-photo-thumb" onclick="openLightbox(<?php echo htmlspecialchars($photoJson, ENT_QUOTES); ?>, <?php echo $pi; ?>)">
                        <img src="<?php echo htmlspecialchars($thumbSrc); ?>"
                             alt="<?php echo htmlspecialchars($ph['photo_type'] ?? ''); ?>"
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
                <?php if (!empty($visitPings[$v['id']])): ?>
                  <?php
                    $pings      = $visitPings[$v['id']];
                    $startTime  = date('g:i a', strtotime($pings[0]['timestamp']));

                    // Sample down to ≤ 60 points so the URL stays short
                    $sampled = $pings;
                    if (count($pings) > 60) {
                        $step    = ceil(count($pings) / 60);
                        $sampled = [];
                        for ($si = 0; $si < count($pings); $si += $step) {
                            $sampled[] = $pings[$si];
                        }
                        if (end($sampled) !== end($pings)) {
                            $sampled[] = end($pings);
                        }
                    }

                    $coords    = array_map(fn($p) => $p['latitude'].','.$p['longitude'], $sampled);
                    $avgLat    = array_sum(array_column($pings, 'latitude'))  / count($pings);
                    $avgLng    = array_sum(array_column($pings, 'longitude')) / count($pings);
                    $startCoord = $pings[0]['latitude'].','.$pings[0]['longitude'];

                    $gmapUrl = 'https://maps.googleapis.com/maps/api/staticmap'
                        . '?size=640x200&scale=2&zoom=18&maptype=roadmap'
                        . '&center=' . $avgLat . ',' . $avgLng
                        . '&path=color:0x2D865988|weight:4|' . implode('|', $coords)
                        . '&markers=color:0x2D8659|size:tiny|' . implode('|', $coords)
                        . '&markers=icon:https://maps.google.com/mapfiles/ms/icons/green-dot.png|' . $startCoord
                        . '&key=' . GOOGLE_MAPS_API_KEY;
                  ?>
                  <div class="portal-visit-map-wrap">
                    <img src="<?php echo htmlspecialchars($gmapUrl); ?>"
                         alt="Service location map"
                         class="portal-visit-map-img"
                         loading="lazy">
                    <div class="portal-visit-map-label">Started <?php echo htmlspecialchars($startTime); ?></div>
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

// ── Rebook (request service again) ────────────────────────────────────
function mwRebookVisit(visitId, serviceLabel) {
  var note = prompt(
    'Request another ' + serviceLabel + ' service?\n\n' +
    'Add a note if you\'d like (optional — e.g. preferred time, extra items, access instructions):'
  );
  if (note === null) return; // cancelled

  var btns = document.querySelectorAll('button[onclick*="mwRebookVisit(' + visitId + ',');
  btns.forEach(function(b) { b.disabled = true; b.textContent = 'Sending...'; });

  var formData = new FormData();
  formData.append('token', <?php echo json_encode($contact['portal_token'] ?? ''); ?>);
  formData.append('visit_id', visitId);
  formData.append('notes', note || '');

  fetch('/customer/api/rebook.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        btns.forEach(function(b) {
          b.textContent = d.duplicate ? '\u2713 Already requested' : '\u2713 Request sent';
          b.style.background = '#2D8659';
          b.style.color = '#fff';
          b.style.borderColor = '#2D8659';
        });
        alert(d.message || 'Request sent!');
      } else {
        btns.forEach(function(b) { b.disabled = false; b.textContent = '\u21BB Book again'; });
        alert('Error: ' + (d.error || 'Unknown'));
      }
    })
    .catch(function() {
      btns.forEach(function(b) { b.disabled = false; b.textContent = '\u21BB Book again'; });
      alert('Network error — please try again.');
    });
}
</script>

</body>
</html>
