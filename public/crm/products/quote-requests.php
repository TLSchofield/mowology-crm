<?php
/**
 * Quote Requests Viewer
 * Mowology CRM - View incoming quote requests from website
 * Secure: Requires authentication
 *
 * NEW STRUCTURE:
 * - quote_requests: The form submissions
 * - contacts: Independent people (linked via contact_id)
 * - properties: Independent locations (linked via property_id)
 */

// Load session config FIRST to set custom session path
require_once dirname(__DIR__) . '/../loginAuth/auth.php';

// Require login - redirects to login page if not authenticated
requireLogin();
$user = getCurrentUser();
$db = getDB();

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

// Build query - quote_requests with joined contact and property data
$query = "
    SELECT
        qr.id,
        qr.service_types,
        qr.urgency,
        qr.project_description,
        qr.status,
        qr.source,
        qr.created_at,
        qr.updated_at,
        qr.quote_id,
        c.id AS contact_id,
        c.first_name,
        c.last_name,
        c.email,
        c.phone,
        c.preferred_contact_method,
        p.id AS property_id,
        p.property_name,
        p.property_type,
        p.address,
        p.city,
        p.postal_code,
        p.latitude,
        p.longitude,
        q.id             AS quote_id,
        q.quote_number,
        q.status         AS quote_status,
        q.amount         AS quote_amount,
        q.accepted_at,
        q.accepted_by_name
    FROM quote_requests qr
    LEFT JOIN contacts c ON qr.contact_id = c.id
    LEFT JOIN properties p ON qr.property_id = p.id
    LEFT JOIN quotes q ON q.id = qr.quote_id
    WHERE 1=1
";
$params = [];

// Status filter
if ($status !== 'all') {
    $query .= " AND qr.status = ?";
    $params[] = $status;
}

// Search filter
if (!empty($search)) {
    $query .= " AND (
        c.first_name LIKE ?
        OR c.last_name LIKE ?
        OR c.email LIKE ?
        OR c.phone LIKE ?
        OR p.address LIKE ?
        OR p.property_name LIKE ?
    )";
    $searchTerm = "%{$search}%";
    $params = array_merge($params, array_fill(0, 6, $searchTerm));
}

$query .= " ORDER BY qr.created_at DESC LIMIT 100";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $quoteRequests = $stmt->fetchAll();

    // Get status counts
    $statusCounts = $db->query("
        SELECT status, COUNT(*) as count
        FROM quote_requests
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $totalCount = array_sum($statusCounts);

} catch(PDOException $e) {
    $quoteRequests = [];
    $statusCounts = [];
    $totalCount = 0;
    $error = "Database error: " . $e->getMessage();
    error_log("Quote requests error: " . $e->getMessage());
}

// Helper function to format service types
function formatServices($services) {
    if (empty($services)) return 'Not specified';
    $serviceLabels = [
        'maintenance' => 'Maintenance',
        'cleanup' => 'Cleanup',
        'hedge_trimming' => 'Hedge Trimming',
        'lawn_care' => 'Lawn Care',
        'snow_removal' => 'Snow Removal'
    ];
    $serviceList = explode(',', $services);
    $formatted = [];
    foreach ($serviceList as $s) {
        $s = trim($s);
        $formatted[] = $serviceLabels[$s] ?? ucwords(str_replace('_', ' ', $s));
    }
    return implode(', ', $formatted);
}

// Helper function to format urgency
function formatUrgency($urgency) {
    $labels = [
        'inquiring' => 'Just Inquiring',
        'soon' => 'Within 2 Weeks',
        'asap' => 'ASAP - Urgent'
    ];
    return $labels[$urgency] ?? ucfirst($urgency);
}

$pageTitle = 'Quote Requests';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <h1 class="h3 mb-1">Quote Requests</h1>
          <p class="text-muted mb-4">Website quote submissions ready for review</p>

          <?php if (isset($error)): ?>
              <div class="alert alert-danger"><?php echo h($error); ?></div>
          <?php endif; ?>

          <!-- Filter Bar -->
          <div class="card mb-4">
              <div class="card-body">
                  <div class="mw-filter-tabs flex-wrap mb-3">
                      <a href="?status=all" class="mw-filter-tab <?php echo $status === 'all' ? 'active' : ''; ?>">
                          All <span class="count"><?php echo $totalCount; ?></span>
                      </a>
                      <a href="?status=new" class="mw-filter-tab <?php echo $status === 'new' ? 'active' : ''; ?>">
                          New <span class="count"><?php echo $statusCounts['new'] ?? 0; ?></span>
                      </a>
                      <a href="?status=reviewing" class="mw-filter-tab <?php echo $status === 'reviewing' ? 'active' : ''; ?>">
                          Reviewing <span class="count"><?php echo $statusCounts['reviewing'] ?? 0; ?></span>
                      </a>
                      <a href="?status=quoted" class="mw-filter-tab <?php echo $status === 'quoted' ? 'active' : ''; ?>">
                          Quoted <span class="count"><?php echo $statusCounts['quoted'] ?? 0; ?></span>
                      </a>
                      <a href="?status=accepted" class="mw-filter-tab <?php echo $status === 'accepted' ? 'active' : ''; ?>">
                          Accepted <span class="count"><?php echo $statusCounts['accepted'] ?? 0; ?></span>
                      </a>
                      <a href="?status=converted" class="mw-filter-tab <?php echo $status === 'converted' ? 'active' : ''; ?>">
                          Converted <span class="count"><?php echo $statusCounts['converted'] ?? 0; ?></span>
                      </a>
                  </div>

                  <form method="GET" class="d-flex align-items-center">
                      <input type="hidden" name="status" value="<?php echo h($status); ?>">
                      <div class="mw-search-box mr-2">
                          <input type="text" name="search" class="mw-search-input" placeholder="Search by name, email, address..." value="<?php echo h($search); ?>">
                      </div>
                      <button type="submit" class="btn btn-success btn-sm">Search</button>
                      <?php if (!empty($search)): ?>
                          <a href="?status=<?php echo h($status); ?>" class="btn btn-secondary btn-sm ml-2">Clear</a>
                      <?php endif; ?>
                  </form>
              </div>
          </div>

          <!-- Quote Requests List -->
          <?php if (empty($quoteRequests)): ?>
              <div class="mw-empty-state">
                  <span class="mw-empty-state-icon" data-feather="file-text"></span>
                  <h3>No quote requests found</h3>
                  <p>Quote requests from your website will appear here.</p>
                  <p class="mt-3">
                      <a href="../jobFlow/jobFlow-getQuote.php" target="_blank" class="btn btn-success">
                          Test Quote Form
                      </a>
                  </p>
              </div>
          <?php else: ?>
              <?php foreach ($quoteRequests as $request):
                  $name = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
                  if (empty($name)) $name = $request['property_name'] ?? 'Unknown';
                  $email = $request['email'] ?? '';
                  $phone = $request['phone'] ?? '';
              ?>
                  <div class="mw-qr-card mw-status-<?php echo h($request['status']); ?> mb-3">
                      <div class="d-flex justify-content-between align-items-start mb-3">
                          <div>
                              <div class="mw-qr-card-name d-flex align-items-center flex-wrap" style="white-space:normal;">
                                  <?php echo h($name); ?>
                                  <span class="mw-status-badge <?php echo h($request['status']); ?> ml-2">
                                      <?php echo ucfirst($request['status']); ?>
                                  </span>
                                  <?php if ($request['urgency'] === 'asap'): ?>
                                      <span class="mw-urgency-badge mw-urgency-asap ml-2">URGENT</span>
                                  <?php endif; ?>
                              </div>
                              <div class="text-muted" style="font-size: 13px;">
                                  Submitted <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>
                                  | Request #<?php echo $request['id']; ?>
                                  <?php if (!empty($request['quote_number'])): ?>
                                      | <a href="../quotes/view.php?id=<?php echo $request['quote_id']; ?>"><?php echo h($request['quote_number']); ?></a>
                                      — $<?php echo number_format((float)($request['quote_amount'] ?? 0), 2); ?>
                                      <?php if ($request['quote_status'] === 'accepted'): ?>
                                          <span class="mw-status-badge accepted ml-1">Accepted<?php if ($request['accepted_by_name']): ?> by <?php echo h($request['accepted_by_name']); ?><?php endif; ?></span>
                                      <?php endif; ?>
                                  <?php endif; ?>
                              </div>
                          </div>
                      </div>

                      <div class="row mb-3">
                          <div class="col-md-4 mb-2">
                              <div class="text-muted text-uppercase" style="font-size: 12px; letter-spacing: 0.5px; margin-bottom: 4px;">Contact</div>
                              <div style="font-weight: 500;">
                                  <?php if ($email): ?><?php echo h($email); ?><br><?php endif; ?>
                                  <?php if ($phone): ?><?php echo h($phone); ?><?php endif; ?>
                                  <?php if (!$email && !$phone): ?>Not provided<?php endif; ?>
                              </div>
                          </div>
                          <div class="col-md-4 mb-2">
                              <div class="text-muted text-uppercase" style="font-size: 12px; letter-spacing: 0.5px; margin-bottom: 4px;">Property</div>
                              <div style="font-weight: 500;">
                                  <?php echo h(ucfirst($request['property_type'] ?? 'Not specified')); ?><br>
                                  <?php echo h($request['address'] ?? 'No address'); ?>
                                  <?php if ($request['city']): ?>, <?php echo h($request['city']); ?><?php endif; ?>
                              </div>
                          </div>
                          <div class="col-md-4 mb-2">
                              <div class="text-muted text-uppercase" style="font-size: 12px; letter-spacing: 0.5px; margin-bottom: 4px;">Timeline</div>
                              <div class="<?php echo $request['urgency'] === 'asap' ? 'text-danger font-weight-bold' : ($request['urgency'] === 'soon' ? 'text-warning' : ''); ?>" style="font-weight: 500;">
                                  <?php echo formatUrgency($request['urgency'] ?? 'inquiring'); ?>
                              </div>
                          </div>
                      </div>

                      <?php if ($request['service_types']): ?>
                          <div class="mw-qr-card-services p-2 rounded mb-3" style="white-space:normal; background: #f8fafc;">
                              <strong>Services Requested:</strong><br>
                              <?php echo h(formatServices($request['service_types'])); ?>
                          </div>
                      <?php endif; ?>

                      <?php if ($request['project_description']): ?>
                          <div class="p-2 rounded mb-3" style="font-size: 14px; background: #f8fafc;">
                              <strong>Notes:</strong><br>
                              <?php echo nl2br(h($request['project_description'])); ?>
                          </div>
                      <?php endif; ?>

                      <div class="d-flex flex-wrap" style="gap: 8px;">
                          <?php if ($request['address']): ?>
                              <a href="area-measurement.php?address=<?php echo urlencode($request['address'] . ', ' . ($request['city'] ?? 'Vancouver') . ', BC'); ?>" class="btn btn-success btn-sm">
                                  Measure Property
                              </a>
                          <?php endif; ?>
                          <?php if ($request['quote_id']): ?>
                              <!-- Quote already created - show view/edit button -->
                              <a href="../quotes/view.php?id=<?php echo $request['quote_id']; ?>" class="btn btn-primary btn-sm">
                                  View Quote
                              </a>
                              <a href="../quotes/create.php?id=<?php echo $request['quote_id']; ?>" class="btn btn-outline-primary btn-sm">
                                  Edit Quote
                              </a>
                          <?php else: ?>
                              <!-- No quote yet - show create button -->
                              <a href="../quotes/create.php?quote_request_id=<?php echo $request['id']; ?>" class="btn btn-primary btn-sm">
                                  Create Quote
                              </a>
                          <?php endif; ?>
                          <?php if ($email): ?>
                              <a href="mailto:<?php echo h($email); ?>" class="btn btn-secondary btn-sm">
                                  Email
                              </a>
                          <?php endif; ?>
                          <?php if ($phone): ?>
                              <a href="tel:<?php echo h($phone); ?>" class="btn btn-secondary btn-sm">
                                  Call
                              </a>
                          <?php endif; ?>
                      </div>
                  </div>
              <?php endforeach; ?>
          <?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
