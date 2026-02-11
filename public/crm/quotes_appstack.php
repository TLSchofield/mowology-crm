<?php
/**
 * Quotes Hub — Shows incoming quote requests and formal quotes
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Quotes', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// Initialize variables
$quoteRequests = [];
$newCount = 0;
$quotes = [];
$statusCounts = [];
$totalQuotes = 0;

// --- Incoming Quote Requests ---
try {
    $quoteRequests = $db->query("
        SELECT
            qr.id, qr.service_types, qr.urgency, qr.status, qr.created_at, qr.quote_id,
            c.first_name, c.last_name, c.email, c.phone,
            p.address, p.city, p.property_type
        FROM quote_requests qr
        LEFT JOIN contacts c ON qr.contact_id = c.id
        LEFT JOIN properties p ON qr.property_id = p.id
        WHERE qr.status IN ('new', 'reviewing')
        ORDER BY
            CASE qr.urgency WHEN 'asap' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END,
            qr.created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $requestCounts = $db->query("
        SELECT status, COUNT(*) as count
        FROM quote_requests
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $newCount = ($requestCounts['new'] ?? 0) + ($requestCounts['reviewing'] ?? 0);

} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load quote requests. Please refresh the page.');
    $quoteRequests = [];
    $newCount = 0;
}

// --- Formal Quotes ---
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Initialize arrays
$quotesByStatus = [
    'draft' => [],
    'sent' => [],
    'accepted' => [],
    'scheduled' => []
];
$statusCounts = ['draft' => 0, 'sent' => 0, 'accepted' => 0, 'scheduled' => 0];
$quotes = [];
$totalQuotes = 0;

try {
    // Get all quotes with company, property, contact, and job info in one query
    // Contact name resolution priority:
    //   1. quote_requests.contact_id → contacts (most reliable — direct from intake form)
    //   2. companies.primary_contact_id → contacts (if company_id set on quote)
    //   3. company_properties → companies → contacts (if company linked via property)
    //   4. properties.site_contact_id → contacts (fallback)
    $quotesResult = $db->query("
        SELECT
            q.id, q.quote_number, q.status,
            COALESCE(q.total_amount, q.amount, 0) AS total_amount,
            q.created_at, COALESCE(q.expiry_date, q.valid_until) AS expiry_date,
            q.property_id, q.company_id, q.created_by,
            COALESCE(q.service_types, q.service_type) AS service_types,
            COALESCE(co.company_name, cp_co.company_name) AS company_name,
            p.address AS property_address,
            p.city AS property_city,
            qrc.first_name AS qr_contact_first,
            qrc.last_name AS qr_contact_last,
            pc.first_name AS primary_contact_first,
            pc.last_name AS primary_contact_last,
            sc.first_name AS site_contact_first,
            sc.last_name AS site_contact_last,
            (SELECT j.id FROM jobs j WHERE j.quote_id = q.id LIMIT 1) AS job_id
        FROM quotes q
        LEFT JOIN properties p ON q.property_id = p.id
        LEFT JOIN companies co ON q.company_id = co.id
        LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
        LEFT JOIN companies cp_co ON cp.company_id = cp_co.id AND q.company_id IS NULL
        LEFT JOIN contacts pc ON COALESCE(co.primary_contact_id, cp_co.primary_contact_id) = pc.id
        LEFT JOIN contacts sc ON p.site_contact_id = sc.id
        LEFT JOIN quote_requests qr ON qr.quote_id = q.id
        LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
        ORDER BY q.created_at DESC
        LIMIT 500
    ");

    if (!$quotesResult) {
        throw new Exception("Failed to execute quotes query");
    }

    $allQuotes = $quotesResult->fetchAll(PDO::FETCH_ASSOC);

    // Step 3: Organize quotes by status for kanban view
    foreach ($allQuotes as $quote) {
        $status = $quote['status'] ?? '';
        $hasJob = !empty($quote['job_id']);

        if ($status === 'accepted' && $hasJob) {
            // Accepted quote with linked job goes to "scheduled" column
            $quotesByStatus['scheduled'][] = $quote;
            $statusCounts['scheduled']++;
        } elseif ($status === 'draft') {
            $quotesByStatus['draft'][] = $quote;
            $statusCounts['draft']++;
        } elseif ($status === 'sent') {
            $quotesByStatus['sent'][] = $quote;
            $statusCounts['sent']++;
        } elseif ($status === 'accepted') {
            $quotesByStatus['accepted'][] = $quote;
            $statusCounts['accepted']++;
        }
    }

    // For table view, filter by status if requested
    if ($statusFilter === 'accepted') {
        // Show all accepted quotes (both with and without jobs)
        $quotes = array_merge($quotesByStatus['accepted'], $quotesByStatus['scheduled']);
    } elseif ($statusFilter === 'draft') {
        $quotes = $quotesByStatus['draft'];
    } elseif ($statusFilter === 'sent') {
        $quotes = $quotesByStatus['sent'];
    } else {
        // Show all quotes for table view (default)
        $quotes = $allQuotes;
    }

    // Calculate total for all statuses
    $totalQuotes = array_sum($statusCounts);

} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load quotes. Please refresh the page.');
    $quotes = [];
    $quotesByStatus = ['draft' => [], 'sent' => [], 'accepted' => [], 'scheduled' => []];
    $statusCounts = ['draft' => 0, 'sent' => 0, 'accepted' => 0, 'scheduled' => 0];
    $totalQuotes = 0;
} catch (Exception $e) {
    $errorHandler->logError('Unable to load quotes', $e);
    $quotes = [];
    $quotesByStatus = ['draft' => [], 'sent' => [], 'accepted' => [], 'scheduled' => []];
    $statusCounts = ['draft' => 0, 'sent' => 0, 'accepted' => 0, 'scheduled' => 0];
    $totalQuotes = 0;
}

$pageTitle = 'Quotes';
$activePage = 'quotes';
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- Session Alert Display -->
          <?php if (isset($_SESSION['alert'])):
              $alert = $_SESSION['alert'];
              $alertClass = [
                  'error' => 'alert-danger',
                  'warning' => 'alert-warning',
                  'success' => 'alert-success',
                  'info' => 'alert-info'
              ][$alert['type']] ?? 'alert-info';
          ?>
              <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                  <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['alert']); ?>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Quotes</h1>
                  <p class="text-muted mb-0">Incoming requests &amp; quote management</p>
              </div>
              <a href="quotes/create.php" class="btn btn-primary">
                  <i data-feather="plus"></i> Create Quote
              </a>
          </div>

          <!-- Incoming Quote Requests -->
          <?php if (!empty($quoteRequests)): ?>
          <div class="card mb-4">
              <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">
                      Incoming Quote Requests
                      <span class="badge badge-warning ml-2"><?php echo $newCount; ?> pending</span>
                  </h5>
                  <a href="products/quote-requests.php" class="btn btn-sm btn-outline-secondary">View All Requests</a>
              </div>
              <div class="card-body">
                  <p class="text-muted small mb-3">👉 Click a request to create a draft quote, or use the Kanban board to manage quotes in progress.</p>
                  <div class="row">
                      <?php foreach ($quoteRequests as $qr):
                          $qrName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? ''));
                          if (empty($qrName)) $qrName = 'Unknown Contact';
                          $qrServices = formatServiceTypes($qr['service_types']);
                          $qrServicesStr = !empty($qrServices) ? implode(', ', $qrServices) : 'Not specified';
                      ?>
                          <div class="col-xl-4 col-md-6 mb-3">
                              <a href="quote-workflow.php?request_id=<?php echo (int)$qr['id']; ?>" class="mw-qr-card mw-status-<?php echo h($qr['status']); ?>">
                                  <div class="mw-qr-card-name">
                                      <?php echo h($qrName); ?>
                                      <span class="mw-urgency-badge mw-urgency-<?php echo h($qr['urgency'] ?? 'inquiring'); ?>">
                                          <?php echo h(ucfirst($qr['urgency'] ?? 'inquiring')); ?>
                                      </span>
                                  </div>
                                  <div class="mw-qr-card-services"><?php echo h($qrServicesStr); ?></div>
                                  <div class="mw-qr-card-meta">
                                      <span><?php echo h(timeAgo($qr['created_at'])); ?></span>
                                      <span class="mw-status-badge <?php echo h($qr['status']); ?>"><?php echo h(ucfirst($qr['status'])); ?></span>
                                  </div>
                                  <?php if ($qr['address']): ?>
                                      <div class="mw-qr-card-services mt-1" style="font-size: 0.8rem;">
                                          <?php echo h($qr['address']); ?><?php if ($qr['city']): ?>, <?php echo h($qr['city']); ?><?php endif; ?>
                                      </div>
                                  <?php endif; ?>
                              </a>
                          </div>
                      <?php endforeach; ?>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <!-- Formal Quotes - Kanban View & Table View Toggle -->
          <!-- View Toggle Buttons -->
          <div class="mw-view-toggle">
              <button type="button" class="mw-view-toggle-btn active" data-view="kanban">Kanban Board</button>
              <button type="button" class="mw-view-toggle-btn" data-view="table">Table View</button>
          </div>

          <!-- Search Box -->
          <form class="mw-search-box" method="GET" style="margin-bottom: 16px;">
              <input type="text" name="search" class="mw-search-input"
                     placeholder="Search quotes, clients, addresses..."
                     value="<?php echo htmlspecialchars($searchQuery); ?>">
          </form>

          <!-- KANBAN VIEW -->
          <div id="kanban-view">
              <!-- Kanban Board with 4 columns -->
              <div class="mw-kanban-board">
                  <!-- Column 1: Draft Quotes -->
                  <div class="mw-kanban-column">
                      <div class="mw-kanban-column-header">
                          <span>Draft (In Progress)</span>
                          <span class="mw-kanban-column-count"><?php echo $statusCounts['draft']; ?></span>
                      </div>
                      <div class="mw-kanban-cards">
                          <?php if (empty($quotesByStatus['draft'])): ?>
                              <div class="mw-kanban-empty">No quotes</div>
                          <?php else: ?>
                              <?php foreach ($quotesByStatus['draft'] as $quote):
                                  $clientName = '';
                                  $contactName = '';
                                  if (!empty($quote['qr_contact_first']) || !empty($quote['qr_contact_last'])) {
                                      $contactName = trim(($quote['qr_contact_first'] ?? '') . ' ' . ($quote['qr_contact_last'] ?? ''));
                                  } elseif (!empty($quote['primary_contact_first']) || !empty($quote['primary_contact_last'])) {
                                      $contactName = trim(($quote['primary_contact_first'] ?? '') . ' ' . ($quote['primary_contact_last'] ?? ''));
                                  } elseif (!empty($quote['site_contact_first']) || !empty($quote['site_contact_last'])) {
                                      $contactName = trim(($quote['site_contact_first'] ?? '') . ' ' . ($quote['site_contact_last'] ?? ''));
                                  }
                                  if (!empty($quote['company_name'])) {
                                      $clientName = $contactName ? $contactName . ' — ' . $quote['company_name'] : $quote['company_name'];
                                  } else {
                                      $clientName = $contactName;
                                  }
                                  if (empty($clientName)) $clientName = 'N/A';
                              ?>
                                  <a href="quotes/view.php?id=<?php echo $quote['id']; ?>" class="mw-kanban-card status-<?php echo h($quote['status']); ?>">
                                      <div class="mw-kanban-card-header">
                                          <span class="mw-kanban-card-number"><?php echo h($quote['quote_number']); ?></span>
                                          <span class="mw-kanban-card-amount"><?php echo formatCurrency($quote['total_amount']); ?></span>
                                      </div>
                                      <div class="mw-kanban-card-client"><?php echo h($clientName); ?></div>
                                      <div class="mw-kanban-card-meta">
                                          <span class="mw-kanban-card-date"><?php echo formatDate($quote['created_at'], 'M j'); ?></span>
                                      </div>
                                  </a>
                              <?php endforeach; ?>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Column 2: Quote Sent -->
                  <div class="mw-kanban-column">
                      <div class="mw-kanban-column-header">
                          <span>Quote Sent</span>
                          <span class="mw-kanban-column-count"><?php echo $statusCounts['sent']; ?></span>
                      </div>
                      <div class="mw-kanban-cards">
                          <?php if (empty($quotesByStatus['sent'])): ?>
                              <div class="mw-kanban-empty">No quotes</div>
                          <?php else: ?>
                              <?php foreach ($quotesByStatus['sent'] as $quote):
                                  $clientName = '';
                                  $contactName = '';
                                  if (!empty($quote['qr_contact_first']) || !empty($quote['qr_contact_last'])) {
                                      $contactName = trim(($quote['qr_contact_first'] ?? '') . ' ' . ($quote['qr_contact_last'] ?? ''));
                                  } elseif (!empty($quote['primary_contact_first']) || !empty($quote['primary_contact_last'])) {
                                      $contactName = trim(($quote['primary_contact_first'] ?? '') . ' ' . ($quote['primary_contact_last'] ?? ''));
                                  } elseif (!empty($quote['site_contact_first']) || !empty($quote['site_contact_last'])) {
                                      $contactName = trim(($quote['site_contact_first'] ?? '') . ' ' . ($quote['site_contact_last'] ?? ''));
                                  }
                                  if (!empty($quote['company_name'])) {
                                      $clientName = $contactName ? $contactName . ' — ' . $quote['company_name'] : $quote['company_name'];
                                  } else {
                                      $clientName = $contactName;
                                  }
                                  if (empty($clientName)) $clientName = 'N/A';
                              ?>
                                  <a href="quotes/view.php?id=<?php echo $quote['id']; ?>" class="mw-kanban-card status-<?php echo h($quote['status']); ?>">
                                      <div class="mw-kanban-card-header">
                                          <span class="mw-kanban-card-number"><?php echo h($quote['quote_number']); ?></span>
                                          <span class="mw-kanban-card-amount"><?php echo formatCurrency($quote['total_amount']); ?></span>
                                      </div>
                                      <div class="mw-kanban-card-client"><?php echo h($clientName); ?></div>
                                      <div class="mw-kanban-card-meta">
                                          <span class="mw-kanban-card-date"><?php echo formatDate($quote['created_at'], 'M j'); ?></span>
                                      </div>
                                  </a>
                              <?php endforeach; ?>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Column 3: Quote Approved (GOLDEN STATUS) -->
                  <div class="mw-kanban-column column-approved">
                      <div class="mw-kanban-column-header">
                          <span>Quote Approved</span>
                          <span class="mw-kanban-column-count"><?php echo $statusCounts['accepted']; ?></span>
                      </div>
                      <div class="mw-kanban-cards">
                          <?php if (empty($quotesByStatus['accepted'])): ?>
                              <div class="mw-kanban-empty">No quotes</div>
                          <?php else: ?>
                              <?php foreach ($quotesByStatus['accepted'] as $quote):
                                  $clientName = '';
                                  $contactName = '';
                                  if (!empty($quote['qr_contact_first']) || !empty($quote['qr_contact_last'])) {
                                      $contactName = trim(($quote['qr_contact_first'] ?? '') . ' ' . ($quote['qr_contact_last'] ?? ''));
                                  } elseif (!empty($quote['primary_contact_first']) || !empty($quote['primary_contact_last'])) {
                                      $contactName = trim(($quote['primary_contact_first'] ?? '') . ' ' . ($quote['primary_contact_last'] ?? ''));
                                  } elseif (!empty($quote['site_contact_first']) || !empty($quote['site_contact_last'])) {
                                      $contactName = trim(($quote['site_contact_first'] ?? '') . ' ' . ($quote['site_contact_last'] ?? ''));
                                  }
                                  if (!empty($quote['company_name'])) {
                                      $clientName = $contactName ? $contactName . ' — ' . $quote['company_name'] : $quote['company_name'];
                                  } else {
                                      $clientName = $contactName;
                                  }
                                  if (empty($clientName)) $clientName = 'N/A';
                              ?>
                                  <a href="quotes/view.php?id=<?php echo $quote['id']; ?>" class="mw-kanban-card status-<?php echo h($quote['status']); ?>">
                                      <div class="mw-kanban-card-header">
                                          <span class="mw-kanban-card-number"><?php echo h($quote['quote_number']); ?></span>
                                          <span class="mw-kanban-card-amount"><?php echo formatCurrency($quote['total_amount']); ?></span>
                                      </div>
                                      <div class="mw-kanban-card-client"><?php echo h($clientName); ?></div>
                                      <div class="mw-kanban-card-meta">
                                          <span class="mw-kanban-card-date"><?php echo formatDate($quote['created_at'], 'M j'); ?></span>
                                      </div>
                                  </a>
                              <?php endforeach; ?>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Column 4: Approved & Scheduled (with linked jobs) -->
                  <div class="mw-kanban-column">
                      <div class="mw-kanban-column-header">
                          <span>Approved & Scheduled</span>
                          <span class="mw-kanban-column-count"><?php echo $statusCounts['scheduled']; ?></span>
                      </div>
                      <div class="mw-kanban-cards">
                          <?php if (empty($quotesByStatus['scheduled'])): ?>
                              <div class="mw-kanban-empty">No quotes</div>
                          <?php else: ?>
                              <?php foreach ($quotesByStatus['scheduled'] as $quote):
                                  $clientName = '';
                                  $contactName = '';
                                  if (!empty($quote['qr_contact_first']) || !empty($quote['qr_contact_last'])) {
                                      $contactName = trim(($quote['qr_contact_first'] ?? '') . ' ' . ($quote['qr_contact_last'] ?? ''));
                                  } elseif (!empty($quote['primary_contact_first']) || !empty($quote['primary_contact_last'])) {
                                      $contactName = trim(($quote['primary_contact_first'] ?? '') . ' ' . ($quote['primary_contact_last'] ?? ''));
                                  } elseif (!empty($quote['site_contact_first']) || !empty($quote['site_contact_last'])) {
                                      $contactName = trim(($quote['site_contact_first'] ?? '') . ' ' . ($quote['site_contact_last'] ?? ''));
                                  }
                                  if (!empty($quote['company_name'])) {
                                      $clientName = $contactName ? $contactName . ' — ' . $quote['company_name'] : $quote['company_name'];
                                  } else {
                                      $clientName = $contactName;
                                  }
                                  if (empty($clientName)) $clientName = 'N/A';
                              ?>
                                  <a href="quotes/view.php?id=<?php echo $quote['id']; ?>" class="mw-kanban-card status-<?php echo h($quote['status']); ?>">
                                      <div class="mw-kanban-card-header">
                                          <span class="mw-kanban-card-number"><?php echo h($quote['quote_number']); ?></span>
                                          <span class="mw-kanban-card-amount"><?php echo formatCurrency($quote['total_amount']); ?></span>
                                      </div>
                                      <div class="mw-kanban-card-client"><?php echo h($clientName); ?></div>
                                      <div class="mw-kanban-card-meta">
                                          <span class="mw-kanban-card-date"><?php echo formatDate($quote['created_at'], 'M j'); ?></span>
                                      </div>
                                  </a>
                              <?php endforeach; ?>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>
          </div>

          <!-- TABLE VIEW (hidden by default) -->
          <div id="table-view" style="display: none;">
              <!-- Filter Tabs for Table View -->
              <div class="mw-filter-tabs" style="margin-bottom: 16px;">
                  <a href="?status=" class="mw-filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">
                      All <span class="count"><?php echo $totalQuotes; ?></span>
                  </a>
                  <a href="?status=draft" class="mw-filter-tab <?php echo $statusFilter === 'draft' ? 'active' : ''; ?>">
                      Draft <span class="count"><?php echo $statusCounts['draft']; ?></span>
                  </a>
                  <a href="?status=sent" class="mw-filter-tab <?php echo $statusFilter === 'sent' ? 'active' : ''; ?>">
                      Sent <span class="count"><?php echo $statusCounts['sent']; ?></span>
                  </a>
                  <a href="?status=accepted" class="mw-filter-tab <?php echo $statusFilter === 'accepted' ? 'active' : ''; ?>">
                      Accepted <span class="count"><?php echo ($statusCounts['accepted'] + $statusCounts['scheduled']); ?></span>
                  </a>
              </div>

              <div class="mw-table-card">
                  <?php if (empty($quotes)): ?>
                      <div class="mw-empty-state">
                          <span class="mw-empty-state-icon" data-feather="file-text"></span>
                          <p class="text-muted">No quotes yet. Create a quote from an incoming request or start a new one.</p>
                      </div>
                  <?php else: ?>
                      <div class="table-responsive">
                          <table class="mw-table">
                              <thead>
                                  <tr>
                                      <th class="mw-bulk-checkbox-cell">
                                          <input type="checkbox" class="mw-bulk-checkbox" id="mw-quotes-select-all" title="Select all">
                                      </th>
                                      <th>Quote #</th>
                                      <th>Client</th>
                                      <th>Service</th>
                                      <th>Amount</th>
                                      <th>Status</th>
                                      <th>Created</th>
                                      <th>Valid Until</th>
                                      <th>Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($quotes as $quote):
                                      $clientName = '';
                                      $contactName = '';
                                      if (!empty($quote['qr_contact_first']) || !empty($quote['qr_contact_last'])) {
                                          $contactName = trim(($quote['qr_contact_first'] ?? '') . ' ' . ($quote['qr_contact_last'] ?? ''));
                                      } elseif (!empty($quote['primary_contact_first']) || !empty($quote['primary_contact_last'])) {
                                          $contactName = trim(($quote['primary_contact_first'] ?? '') . ' ' . ($quote['primary_contact_last'] ?? ''));
                                      } elseif (!empty($quote['site_contact_first']) || !empty($quote['site_contact_last'])) {
                                          $contactName = trim(($quote['site_contact_first'] ?? '') . ' ' . ($quote['site_contact_last'] ?? ''));
                                      }
                                      if (!empty($quote['company_name'])) {
                                          $clientName = $contactName ? $contactName . ' — ' . $quote['company_name'] : $quote['company_name'];
                                      } else {
                                          $clientName = $contactName;
                                      }
                                      if (empty($clientName)) $clientName = 'N/A';
                                  ?>
                                      <tr>
                                          <td class="mw-bulk-checkbox-cell">
                                              <input type="checkbox" class="mw-bulk-checkbox mw-bulk-row-select" data-id="<?php echo (int)$quote['id']; ?>">
                                          </td>
                                          <td>
                                              <strong><?php echo htmlspecialchars($quote['quote_number']); ?></strong>
                                          </td>
                                          <td>
                                              <div class="font-weight-bold"><?php echo htmlspecialchars($clientName); ?></div>
                                              <small class="text-muted"><?php echo htmlspecialchars($quote['property_address'] ?? ''); ?></small>
                                          </td>
                                          <td><?php echo ucfirst(str_replace('_', ' ', $quote['service_types'] ?? '')); ?></td>
                                          <td><strong><?php echo formatCurrency($quote['total_amount']); ?></strong></td>
                                          <td><?php echo getStatusBadge($quote['status']); ?></td>
                                          <td><?php echo formatDate($quote['created_at']); ?></td>
                                          <td><?php echo $quote['expiry_date'] ? formatDate($quote['expiry_date']) : '-'; ?></td>
                                          <td class="actions">
                                              <a href="quotes/view.php?id=<?php echo $quote['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                              <?php if ($quote['status'] === 'accepted' && empty($quote['job_id'])): ?>
                                                  <a href="jobs/create.php?quote_id=<?php echo $quote['id']; ?>" class="mw-action-btn mw-action-btn-convert">Create Job</a>
                                              <?php endif; ?>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                  <?php endif; ?>
              </div>
          </div>

          <!-- Bulk Action Bar -->
          <div class="mw-bulk-action-bar" id="mw-quotes-bulk-bar">
            <div>
              <span class="mw-bulk-count" id="mw-quotes-bulk-count">0</span> quotes selected
              <button class="btn btn-sm mw-bulk-clear-btn ml-3" onclick="mwBulkClearQuotes()">Clear Selection</button>
            </div>
            <button class="btn btn-sm btn-danger" onclick="mwBulkDeleteQuotes()">
              <i data-feather="trash-2"></i> Delete Selected
            </button>
          </div>

          <!-- View Toggle JavaScript -->
          <script>
          document.addEventListener('DOMContentLoaded', function() {
              // Get saved view preference from localStorage
              const savedView = localStorage.getItem('quotesViewMode') || 'kanban';

              // Set initial active button and visibility
              document.querySelectorAll('.mw-view-toggle-btn').forEach(btn => {
                  if (btn.dataset.view === savedView) {
                      btn.classList.add('active');
                  } else {
                      btn.classList.remove('active');
                  }
              });

              document.getElementById('kanban-view').style.display = savedView === 'kanban' ? 'block' : 'none';
              document.getElementById('table-view').style.display = savedView === 'table' ? 'block' : 'none';

              // Attach click handlers
              document.querySelectorAll('.mw-view-toggle-btn').forEach(btn => {
                  btn.addEventListener('click', function(e) {
                      e.preventDefault();
                      const view = this.dataset.view;

                      // Update button states
                      document.querySelectorAll('.mw-view-toggle-btn').forEach(b => {
                          b.classList.remove('active');
                      });
                      this.classList.add('active');

                      // Show/hide views
                      document.getElementById('kanban-view').style.display = view === 'kanban' ? 'block' : 'none';
                      document.getElementById('table-view').style.display = view === 'table' ? 'block' : 'none';

                      // Save preference
                      localStorage.setItem('quotesViewMode', view);
                  });
              });
          });

          // ── Bulk Delete ──────────────────────────────────────
          var mwQuotesBulkSelected = new Set();

          // Select-all checkbox
          document.getElementById('mw-quotes-select-all').addEventListener('change', function() {
              var checked = this.checked;
              document.querySelectorAll('#table-view .mw-bulk-row-select').forEach(function(cb) {
                  cb.checked = checked;
                  var id = parseInt(cb.dataset.id);
                  if (checked) {
                      mwQuotesBulkSelected.add(id);
                  } else {
                      mwQuotesBulkSelected.delete(id);
                  }
              });
              mwQuotesBulkUpdateBar();
          });

          // Individual row checkbox
          document.addEventListener('change', function(e) {
              if (e.target.classList.contains('mw-bulk-row-select') && e.target.closest('#table-view')) {
                  var id = parseInt(e.target.dataset.id);
                  if (e.target.checked) {
                      mwQuotesBulkSelected.add(id);
                  } else {
                      mwQuotesBulkSelected.delete(id);
                      document.getElementById('mw-quotes-select-all').checked = false;
                  }
                  mwQuotesBulkUpdateBar();
              }
          });

          function mwQuotesBulkUpdateBar() {
              var bar = document.getElementById('mw-quotes-bulk-bar');
              var count = document.getElementById('mw-quotes-bulk-count');
              count.textContent = mwQuotesBulkSelected.size;
              if (mwQuotesBulkSelected.size > 0) {
                  bar.classList.add('mw-bulk-visible');
              } else {
                  bar.classList.remove('mw-bulk-visible');
              }
          }

          function mwBulkClearQuotes() {
              mwQuotesBulkSelected.clear();
              document.querySelectorAll('#table-view .mw-bulk-row-select').forEach(function(cb) { cb.checked = false; });
              document.getElementById('mw-quotes-select-all').checked = false;
              mwQuotesBulkUpdateBar();
          }

          function mwBulkDeleteQuotes() {
              var count = mwQuotesBulkSelected.size;
              if (count === 0) return;
              if (!confirm('Permanently delete ' + count + ' quote(s)? This will also remove their line items and notes. Jobs linked to these quotes will be unlinked. This cannot be undone.')) return;

              fetch('quotes/api-quotes.php?action=bulk-delete', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ ids: Array.from(mwQuotesBulkSelected) })
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                  if (data.success) {
                      alert(data.deleted_count + ' quote(s) deleted.');
                      location.reload();
                  } else {
                      alert('Error: ' + (data.error || 'Unknown error'));
                  }
              })
              .catch(function(err) { alert('Error: ' + err.message); });
          }
          </script>

<?php include 'includes/appstack_footer.php'; ?>
