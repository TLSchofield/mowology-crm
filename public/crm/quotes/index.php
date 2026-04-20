<?php
/**
 * Quotes Management - List View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Handle filters
$statusFilter  = $_GET['status'] ?? '';
$customFilter  = $_GET['filter'] ?? '';  // 'followup' for Needs Follow-Up view
$searchQuery   = trim($_GET['search'] ?? '');

$db = getDB();

// ── Detect whether follow_up columns exist (post-migration) ──────────────
$hasFollowupCols = false;
try {
    $db->query("SELECT follow_up_count FROM quotes LIMIT 0");
    $hasFollowupCols = true;
} catch (PDOException $e) {
    // columns not yet added; follow-up features gracefully hidden
}

// ── Count "Needs Follow-Up" for the badge ───────────────────────────────
$needsFollowupCount = 0;
if ($hasFollowupCols) {
    try {
        $nfRow = $db->query("
            SELECT COUNT(*) FROM quotes
            WHERE status = 'sent'
              AND sent_at IS NOT NULL
              AND sent_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
              AND follow_up_count = 0
        ")->fetchColumn();
        $needsFollowupCount = (int)$nfRow;
    } catch (PDOException $e) { /* ignore */ }
}

// ── Pagination + Sorting params ──────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$sortCol  = $_GET['sort'] ?? 'created_at';
$sortDir  = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Whitelist allowed sort columns
$allowedSorts = [
    'quote_number' => 'q.quote_number',
    'client'       => 'client_display_name',
    'amount'       => 'q.amount',
    'status'       => 'q.status',
    'created_at'   => 'q.created_at',
    'valid_until'  => 'q.valid_until',
];
$orderBy = $allowedSorts[$sortCol] ?? 'q.created_at';

// ── Build main query ─────────────────────────────────────────────────────
$params = [];
$whereConditions = ['1=1'];

if ($customFilter === 'followup' && $hasFollowupCols) {
    // Needs Follow-Up: sent 3+ days ago, no follow-up sent yet
    $whereConditions[] = "q.status = 'sent'";
    $whereConditions[] = "q.sent_at IS NOT NULL";
    $whereConditions[] = "q.sent_at < DATE_SUB(NOW(), INTERVAL 3 DAY)";
    $whereConditions[] = "q.follow_up_count = 0";
} elseif ($statusFilter) {
    $whereConditions[] = 'q.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(q.quote_number LIKE ? OR c.company_name LIKE ? OR p.address LIKE ? OR CONCAT(qrc.first_name," ",qrc.last_name) LIKE ? OR CONCAT(pc.first_name," ",pc.last_name) LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

// Extra columns when follow-up tracking exists
$extraCols = $hasFollowupCols
    ? ', q.follow_up_count, q.follow_up_sent_at, DATEDIFF(NOW(), q.sent_at) AS days_since_sent'
    : ', 0 AS follow_up_count, NULL AS follow_up_sent_at, NULL AS days_since_sent';

// Count total matching rows for pagination
$countParams = $params;
$countQuery = $db->prepare("
    SELECT COUNT(*) FROM quotes q
    LEFT JOIN properties p ON q.property_id = p.id
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    LEFT JOIN contacts pc ON p.site_contact_id = pc.id
    WHERE {$whereClause}
");
$countQuery->execute($countParams);
$filteredTotal = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT
        q.*,
        c.company_name,
        p.address as property_address,
        p.city as property_city,
        u.full_name as created_by_name,
        qrc.first_name as qr_first_name,
        qrc.last_name  as qr_last_name,
        pc.first_name  as prop_contact_first,
        pc.last_name   as prop_contact_last,
        COALESCE(
            NULLIF(TRIM(CONCAT(COALESCE(qrc.first_name,''),' ',COALESCE(qrc.last_name,''))), ''),
            NULLIF(TRIM(CONCAT(COALESCE(pc.first_name,''),' ',COALESCE(pc.last_name,''))), ''),
            c.company_name
        ) as client_display_name
        $extraCols
    FROM quotes q
    LEFT JOIN properties p ON q.property_id = p.id
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    LEFT JOIN contacts pc ON p.site_contact_id = pc.id
    LEFT JOIN users u ON q.created_by = u.id
    WHERE {$whereClause}
    ORDER BY {$orderBy} {$sortDir}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Status counts for tabs ───────────────────────────────────────────────
$countStmt = $db->query("SELECT status, COUNT(*) as count FROM quotes GROUP BY status");
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}
$totalCount = array_sum($statusCounts);

// Helper: build sort URL preserving current filters
function quoteSortUrl($col, $curSort, $curDir) {
    $params = $_GET;
    $params['sort'] = $col;
    $params['dir'] = ($curSort === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    unset($params['page']); // reset to page 1 on sort change
    return '?' . http_build_query($params);
}
function quoteSortClass($col, $curSort, $curDir) {
    if ($curSort !== $col) return 'mw-sortable';
    return 'mw-sortable mw-sort-' . strtolower($curDir);
}
function quotePageUrl($pageNum) {
    $params = $_GET;
    $params['page'] = $pageNum;
    return '?' . http_build_query($params);
}

$pageTitle = 'Quotes';
$activePage = 'quotes';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-page-header">
              <div class="mw-page-header-left">
                  <h1 class="mw-page-title">Quotes</h1>
                  <p class="mw-page-subtitle">Manage and send quotes to customers</p>
              </div>
              <div class="mw-page-actions">
                  <a href="create.php" class="btn btn-primary">
                      <i data-feather="plus"></i> Create Quote
                  </a>
              </div>
          </div>

          <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 16px;">
              <div class="mw-filter-tabs">
                  <a href="?status=" class="mw-filter-tab <?php echo (!$statusFilter && $customFilter !== 'followup') ? 'active' : ''; ?>">
                      All <span class="count"><?php echo $totalCount; ?></span>
                  </a>
                  <?php if ($hasFollowupCols && $needsFollowupCount > 0): ?>
                  <a href="?filter=followup" class="mw-filter-tab mw-filter-tab-alert <?php echo $customFilter === 'followup' ? 'active' : ''; ?>">
                      <i data-feather="alert-circle" class="mw-filter-icon"></i>
                      Needs Follow-Up <span class="count"><?php echo $needsFollowupCount; ?></span>
                  </a>
                  <?php endif; ?>
                  <a href="?status=draft" class="mw-filter-tab <?php echo $statusFilter === 'draft' ? 'active' : ''; ?>">
                      Draft <span class="count"><?php echo $statusCounts['draft'] ?? 0; ?></span>
                  </a>
                  <a href="?status=sent" class="mw-filter-tab <?php echo $statusFilter === 'sent' ? 'active' : ''; ?>">
                      Sent <span class="count"><?php echo $statusCounts['sent'] ?? 0; ?></span>
                  </a>
                  <a href="?status=accepted" class="mw-filter-tab <?php echo $statusFilter === 'accepted' ? 'active' : ''; ?>">
                      Accepted <span class="count"><?php echo $statusCounts['accepted'] ?? 0; ?></span>
                  </a>
                  <a href="?status=declined" class="mw-filter-tab <?php echo $statusFilter === 'declined' ? 'active' : ''; ?>">
                      Declined <span class="count"><?php echo $statusCounts['declined'] ?? 0; ?></span>
                  </a>
              </div>

              <form class="mw-search-box" method="GET">
                  <?php if ($statusFilter): ?>
                      <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                  <?php endif; ?>
                  <?php if ($customFilter): ?>
                      <input type="hidden" name="filter" value="<?php echo htmlspecialchars($customFilter); ?>">
                  <?php endif; ?>
                  <input type="text" name="search" class="mw-search-input"
                         placeholder="Search quotes, clients, addresses..."
                         value="<?php echo htmlspecialchars($searchQuery); ?>">
              </form>
          </div>

          <?php if ($customFilter === 'followup'): ?>
          <div class="alert alert-warning mw-followup-notice" role="alert">
              <i data-feather="clock" style="width:16px;height:16px;vertical-align:-2px;"></i>
              <strong>Action needed:</strong> These quotes were sent 3+ days ago with no response.
              Open each quote and click <strong>Send Follow-Up</strong> to reach back out — or let the automated follow-up emails handle it.
          </div>
          <?php endif; ?>

          <div class="mw-table-card">
              <?php if (empty($quotes)): ?>
                  <div class="mw-empty-state">
                      <span class="mw-empty-state-icon" data-feather="check-circle"></span>
                      <p class="text-muted">
                          <?php echo $customFilter === 'followup' ? 'No quotes need follow-up right now — great work!' : 'No quotes found. Create your first quote to get started!'; ?>
                      </p>
                  </div>
              <?php else: ?>
                  <div class="table-responsive">
                      <table class="mw-table">
                          <thead>
                              <tr>
                                  <th class="<?php echo quoteSortClass('quote_number', $sortCol, $sortDir); ?>"><a href="<?php echo quoteSortUrl('quote_number', $sortCol, $sortDir); ?>">Quote #</a></th>
                                  <th class="<?php echo quoteSortClass('client', $sortCol, $sortDir); ?>"><a href="<?php echo quoteSortUrl('client', $sortCol, $sortDir); ?>">Client</a></th>
                                  <th>Service</th>
                                  <th class="<?php echo quoteSortClass('amount', $sortCol, $sortDir); ?>"><a href="<?php echo quoteSortUrl('amount', $sortCol, $sortDir); ?>">Amount</a></th>
                                  <th class="<?php echo quoteSortClass('status', $sortCol, $sortDir); ?>"><a href="<?php echo quoteSortUrl('status', $sortCol, $sortDir); ?>">Status</a></th>
                                  <th class="<?php echo quoteSortClass('created_at', $sortCol, $sortDir); ?>"><a href="<?php echo quoteSortUrl('created_at', $sortCol, $sortDir); ?>">Sent</a></th>
                                  <th class="<?php echo quoteSortClass('valid_until', $sortCol, $sortDir); ?>"><a href="<?php echo quoteSortUrl('valid_until', $sortCol, $sortDir); ?>">Valid Until</a></th>
                                  <th>Actions</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php foreach ($quotes as $quote): ?>
                                  <?php
                                  $daysSinceSent = isset($quote['days_since_sent']) && $quote['sent_at']
                                      ? (int)$quote['days_since_sent'] : null;
                                  $followUpCount = (int)($quote['follow_up_count'] ?? 0);

                                  // Urgency class for the row
                                  $rowClass = '';
                                  if ($quote['status'] === 'sent' && $daysSinceSent !== null) {
                                      if ($daysSinceSent >= 7) $rowClass = 'mw-row-urgent';
                                      elseif ($daysSinceSent >= 3) $rowClass = 'mw-row-warning';
                                  }
                                  ?>
                                  <tr class="<?php echo $rowClass; ?>" data-href="view.php?id=<?php echo (int)$quote['id']; ?>">
                                      <td>
                                          <span class="mw-cell-primary"><?php echo htmlspecialchars($quote['quote_number']); ?></span>
                                      </td>
                                      <td>
                                          <span class="mw-cell-primary"><?php echo htmlspecialchars($quote['client_display_name'] ?? 'N/A'); ?></span>
                                          <span class="mw-cell-secondary"><?php echo htmlspecialchars($quote['property_address'] ?? ''); ?></span>
                                      </td>
                                      <td><?php echo ucfirst(str_replace('_', ' ', $quote['service_types'] ?? '')); ?></td>
                                      <td><strong><?php echo formatCurrency($quote['amount']); ?></strong></td>
                                      <td>
                                          <?php echo getStatusBadge($quote['status']); ?>
                                          <?php if ($quote['status'] === 'sent' && $followUpCount > 0): ?>
                                              <br><small class="mw-followup-badge"><?php echo $followUpCount; ?> follow-up<?php echo $followUpCount > 1 ? 's' : ''; ?> sent</small>
                                          <?php endif; ?>
                                      </td>
                                      <td>
                                          <?php if ($quote['sent_at']): ?>
                                              <?php echo formatDate($quote['sent_at']); ?>
                                              <?php if ($daysSinceSent !== null && $quote['status'] === 'sent'): ?>
                                                  <br>
                                                  <?php if ($daysSinceSent >= 7): ?>
                                                      <span class="mw-days-badge mw-days-urgent"><?php echo $daysSinceSent; ?>d — urgent</span>
                                                  <?php elseif ($daysSinceSent >= 3): ?>
                                                      <span class="mw-days-badge mw-days-warning"><?php echo $daysSinceSent; ?>d — follow up</span>
                                                  <?php else: ?>
                                                      <span class="mw-days-badge mw-days-ok"><?php echo $daysSinceSent; ?>d</span>
                                                  <?php endif; ?>
                                              <?php endif; ?>
                                          <?php else: ?>
                                              <?php echo formatDate($quote['created_at']); ?>
                                          <?php endif; ?>
                                      </td>
                                      <td><?php echo $quote['valid_until'] ? formatDate($quote['valid_until']) : '-'; ?></td>
                                      <td class="actions" onclick="event.stopPropagation()">
                                          <a href="view.php?id=<?php echo $quote['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                          <?php if ($quote['status'] === 'accepted'): ?>
                                              <a href="../jobs/create.php?quote_id=<?php echo $quote['id']; ?>" class="mw-action-btn mw-action-btn-convert">Create Plan</a>
                                          <?php endif; ?>
                                      </td>
                                  </tr>
                              <?php endforeach; ?>
                          </tbody>
                      </table>
                  </div>
                  <?php if ($totalPages > 1): ?>
                  <div class="mw-pagination">
                      <div class="mw-pagination-info">
                          <span>Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $filteredTotal); ?> of <?php echo $filteredTotal; ?> quotes</span>
                          <div class="mw-pagination-per-page">
                              <span>Show</span>
                              <select onchange="window.location.href=this.value;">
                                  <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                  <option value="<?php $p = $_GET; $p['per_page'] = $pp; $p['page'] = 1; echo '?' . http_build_query($p); ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                  <?php endforeach; ?>
                              </select>
                              <span>per page</span>
                          </div>
                      </div>
                      <div class="mw-pagination-pages">
                          <?php if ($page > 1): ?>
                              <a href="<?php echo quotePageUrl($page - 1); ?>">&laquo;</a>
                          <?php else: ?>
                              <span class="disabled">&laquo;</span>
                          <?php endif; ?>
                          <?php
                          $startP = max(1, $page - 2);
                          $endP = min($totalPages, $page + 2);
                          if ($startP > 1) echo '<a href="' . quotePageUrl(1) . '">1</a>';
                          if ($startP > 2) echo '<span class="disabled">&hellip;</span>';
                          for ($i = $startP; $i <= $endP; $i++):
                          ?>
                              <?php if ($i === $page): ?>
                                  <span class="active"><?php echo $i; ?></span>
                              <?php else: ?>
                                  <a href="<?php echo quotePageUrl($i); ?>"><?php echo $i; ?></a>
                              <?php endif; ?>
                          <?php endfor;
                          if ($endP < $totalPages - 1) echo '<span class="disabled">&hellip;</span>';
                          if ($endP < $totalPages) echo '<a href="' . quotePageUrl($totalPages) . '">' . $totalPages . '</a>';
                          ?>
                          <?php if ($page < $totalPages): ?>
                              <a href="<?php echo quotePageUrl($page + 1); ?>">&raquo;</a>
                          <?php else: ?>
                              <span class="disabled">&raquo;</span>
                          <?php endif; ?>
                      </div>
                  </div>
                  <?php endif; ?>
              <?php endif; ?>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
