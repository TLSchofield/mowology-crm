<?php
/**
 * Quotes Management - List View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Build query
$db = getDB();
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'q.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(q.quote_number LIKE ? OR c.company_name LIKE ? OR p.address LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

$stmt = $db->prepare("
    SELECT
        q.*,
        c.company_name,
        p.address as property_address,
        p.city as property_city,
        u.full_name as created_by_name
    FROM quotes q
    LEFT JOIN properties p ON q.property_id = p.id
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN users u ON q.created_by = u.id
    WHERE {$whereClause}
    ORDER BY q.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for filter tabs
$countStmt = $db->query("
    SELECT status, COUNT(*) as count
    FROM quotes
    GROUP BY status
");
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}
$totalCount = array_sum($statusCounts);

$pageTitle = 'Quotes';
$activePage = 'quotes';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Quotes</h1>
                  <p class="text-muted mb-0">Manage and send quotes to customers</p>
              </div>
              <a href="create.php" class="btn btn-primary">
                  <i data-feather="plus"></i> Create Quote
              </a>
          </div>

          <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 16px;">
              <div class="mw-filter-tabs">
                  <a href="?status=" class="mw-filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">
                      All <span class="count"><?php echo $totalCount; ?></span>
                  </a>
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
                  <input type="text" name="search" class="mw-search-input"
                         placeholder="Search quotes, clients, addresses..."
                         value="<?php echo htmlspecialchars($searchQuery); ?>">
              </form>
          </div>

          <div class="mw-table-card">
              <?php if (empty($quotes)): ?>
                  <div class="mw-empty-state">
                      <span class="mw-empty-state-icon" data-feather="file-text"></span>
                      <p class="text-muted">No quotes found. Create your first quote to get started!</p>
                  </div>
              <?php else: ?>
                  <div class="table-responsive">
                      <table class="mw-table">
                          <thead>
                              <tr>
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
                              <?php foreach ($quotes as $quote): ?>
                                  <tr>
                                      <td>
                                          <strong><?php echo htmlspecialchars($quote['quote_number']); ?></strong>
                                      </td>
                                      <td>
                                          <div class="font-weight-bold"><?php echo htmlspecialchars($quote['company_name'] ?? 'N/A'); ?></div>
                                          <small class="text-muted"><?php echo htmlspecialchars($quote['property_address'] ?? ''); ?></small>
                                      </td>
                                      <td><?php echo ucfirst(str_replace('_', ' ', $quote['service_types'] ?? '')); ?></td>
                                      <td><strong><?php echo formatCurrency($quote['total_amount']); ?></strong></td>
                                      <td><?php echo getStatusBadge($quote['status']); ?></td>
                                      <td><?php echo formatDate($quote['created_at']); ?></td>
                                      <td><?php echo $quote['expiry_date'] ? formatDate($quote['expiry_date']) : '-'; ?></td>
                                      <td class="actions">
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
              <?php endif; ?>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
