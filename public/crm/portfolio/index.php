<?php
/**
 * Portfolio Management - Dashboard (Tabbed Interface)
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/portfolio-functions.php';
require_once dirname(__DIR__) . '/includes/roi-functions.php';

requireLogin();
$user = getCurrentUser();

// Get active tab (default: upload)
$activeTab = isset($_GET['tab']) ? trim($_GET['tab']) : 'upload';
$validTabs = ['upload', 'review', 'favorites', 'items', 'insights', 'recommendations', 'roi'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'upload';
}

// Only staff can upload; others need at least one active tab
$canUpload = true;
$isAdmin = $user['role'] === 'admin';

$db = getDB();
$csrfToken = generateCSRFToken();

// Get portfolio stats
$portfolioStats = getPortfolioStats();

// Load recommendations data if viewing that tab
$recommendationsData = [];
$recommendationStats = ['total' => 0, 'new' => 0, 'accepted' => 0, 'applied' => 0];
if ($activeTab === 'recommendations' && $isAdmin) {
    $seoDataFile = dirname(__DIR__) . '/portfolio/recommendations-data.php';
    if (file_exists($seoDataFile)) {
        $recommendationsData = include $seoDataFile;
        // Calculate stats
        $recStmt = $db->query("SELECT status, COUNT(*) as cnt FROM seo_recommendations GROUP BY status");
        while ($row = $recStmt->fetch(PDO::FETCH_ASSOC)) {
            $recommendationStats[$row['status']] = $row['cnt'];
        }
        $recommendationStats['total'] = array_sum($recommendationStats);
    }
}

// Handle filters for Projects tab
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Build query for Portfolio Items tab
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(project_number LIKE ? OR project_name LIKE ? OR location LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

$stmt = $db->prepare("
    SELECT *
    FROM portfolio_projects
    WHERE {$whereClause}
    ORDER BY featured DESC, display_order ASC, created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for filter tabs
$countStmt = $db->query("
    SELECT status, COUNT(*) as count
    FROM portfolio_projects
    GROUP BY status
");
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}
$totalCount = array_sum($statusCounts);

// Get recent uploads for Review tab
$recentUploads = getRecentUploads(20);

// Get favorite media for Favorites tab
$favorites = getFavoriteMedia(100);

$pageTitle = 'Portfolio Dashboard';
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-1">Portfolio Dashboard</h1>
                  <p class="text-muted mb-0">Upload, organize, and publish marketing assets</p>
              </div>
          </div>

          <!-- Stats -->
          <div class="mw-stats-row">
              <div class="mw-stat-card">
                  <h4>Total Media</h4>
                  <div class="value"><?php echo $portfolioStats['total_media']; ?></div>
              </div>
              <div class="mw-stat-card">
                  <h4>Pending Review</h4>
                  <div class="value"><?php echo $portfolioStats['pending_review']; ?></div>
              </div>
              <div class="mw-stat-card">
                  <h4>Favorites</h4>
                  <div class="value"><?php echo $portfolioStats['favorite_media']; ?></div>
              </div>
              <div class="mw-stat-card">
                  <h4>Portfolio Items</h4>
                  <div class="value"><?php echo $portfolioStats['published_items']; ?></div>
              </div>
          </div>

          <!-- Tabs Navigation -->
          <div class="mw-portfolio-tabs card mb-4">
              <div class="card-body d-flex flex-wrap" style="gap: 8px;">
                  <a href="?tab=upload" class="mw-portfolio-tab <?php echo $activeTab === 'upload' ? 'active' : ''; ?>">
                      <i data-feather="upload" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>Upload
                  </a>
                  <?php if ($isAdmin): ?>
                      <a href="?tab=review" class="mw-portfolio-tab <?php echo $activeTab === 'review' ? 'active' : ''; ?>">
                          <i data-feather="check-circle" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>Review
                          <?php if ($portfolioStats['pending_review'] > 0): ?>
                              <span class="badge badge-warning" style="margin-left: 6px;"><?php echo $portfolioStats['pending_review']; ?></span>
                          <?php endif; ?>
                      </a>
                  <?php endif; ?>
                  <a href="?tab=favorites" class="mw-portfolio-tab <?php echo $activeTab === 'favorites' ? 'active' : ''; ?>">
                      <i data-feather="heart" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>Favorites
                  </a>
                  <a href="?tab=items" class="mw-portfolio-tab <?php echo $activeTab === 'items' ? 'active' : ''; ?>">
                      <i data-feather="folder" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>Portfolio Items
                  </a>
                  <?php if ($isAdmin): ?>
                      <a href="?tab=insights" class="mw-portfolio-tab <?php echo $activeTab === 'insights' ? 'active' : ''; ?>">
                          <i data-feather="bar-chart-2" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>GSC Insights
                      </a>
                      <a href="?tab=recommendations" class="mw-portfolio-tab <?php echo $activeTab === 'recommendations' ? 'active' : ''; ?>">
                          <i data-feather="lightbulb" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>Recommendations
                      </a>
                      <a href="?tab=roi" class="mw-portfolio-tab <?php echo $activeTab === 'roi' ? 'active' : ''; ?>">
                          <i data-feather="trending-up" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>ROI Dashboard
                      </a>
                  <?php endif; ?>
              </div>
          </div>

          <!-- TAB: UPLOAD QUEUE -->
          <?php if ($activeTab === 'upload'): ?>
              <div class="card">
                  <div class="card-body">
                      <h5 class="card-title mb-4"><i data-feather="upload"></i> Upload Photos</h5>

                      <!-- Guide Box -->
                      <div class="mw-guide-box">
                          <i data-feather="info"></i>
                          <div class="mw-guide-text">
                              <strong>Upload & Organize Marketing Assets</strong>
                              Upload photos of your work (before/after, projects, team). All uploads go through admin review before appearing in the portfolio.
                          </div>
                      </div>

                      <!-- Drag & Drop Area -->
                      <div class="mw-upload-drop" id="dropZone">
                          <i data-feather="upload-cloud" style="width: 48px; height: 48px; margin-bottom: 16px;"></i>
                          <p class="mb-2">Drag photos here or <a href="#" onclick="document.getElementById('fileInput').click(); return false;">click to select</a></p>
                          <small class="text-muted">JPEG, PNG, WebP • Max 10MB per file</small>
                          <input type="file" id="fileInput" multiple accept="image/*" style="display: none;">
                      </div>

                      <!-- Help text below upload -->
                      <div class="mw-help-text" style="margin-top: 12px;">
                          <i data-feather="lightbulb"></i>
                          <span>
                              <strong class="mw-tooltip">
                                  Tip: Upload high-quality photos
                                  <span class="mw-tooltip-text">Clear, well-lit photos with landscaping work in focus get approved faster. Avoid blurry or poorly lit images.</span>
                              </strong>
                          </span>
                      </div>

                      <!-- Recent Uploads -->
                      <hr class="my-4">
                      <div class="mw-section-title-with-help">
                          <h6>Recent Uploads</h6>
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">Shows your 20 most recent uploads and their approval status. Approved uploads are ready to use in portfolio projects.</span>
                          </div>
                      </div>
                      <?php if (empty($recentUploads)): ?>
                          <p class="text-muted text-center py-4">No uploads yet</p>
                      <?php else: ?>
                          <div class="table-responsive">
                              <table class="table table-sm table-hover mb-0">
                                  <thead class="table-light">
                                      <tr>
                                          <th style="width: 60px;">Preview</th>
                                          <th>Uploader</th>
                                          <th>Status</th>
                                          <th>Uploaded</th>
                                          <th></th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      <?php foreach ($recentUploads as $upload): ?>
                                          <tr>
                                              <td>
                                                  <?php if ($upload['thumb_path']): ?>
                                                      <img src="<?php echo h($upload['thumb_path']); ?>" alt="Upload" style="width: 50px; height: 50px; object-fit: cover; border-radius: 3px;">
                                                  <?php else: ?>
                                                      <div style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 3px;"></div>
                                                  <?php endif; ?>
                                              </td>
                                              <td><?php echo h($upload['uploader_name'] ?? 'Unknown'); ?></td>
                                              <td>
                                                  <?php
                                                  $statusBadgeClass = match($upload['status']) {
                                                      'uploaded' => 'badge-info',
                                                      'ready' => 'badge-success',
                                                      'rejected' => 'badge-danger',
                                                      default => 'badge-secondary'
                                                  };
                                                  ?>
                                                  <span class="badge <?php echo $statusBadgeClass; ?>"><?php echo ucfirst($upload['status']); ?></span>
                                              </td>
                                              <td><small><?php echo formatDate($upload['uploaded_at']); ?></small></td>
                                              <td><a href="#" onclick="viewMediaDetails(<?php echo $upload['id']; ?>); return false;" class="btn btn-sm btn-outline-secondary">View</a></td>
                                          </tr>
                                      <?php endforeach; ?>
                                  </tbody>
                              </table>
                          </div>
                      <?php endif; ?>
                  </div>
              </div>
          <?php endif; ?>

          <!-- TAB: REVIEW QUEUE (Admin) -->
          <?php if ($activeTab === 'review' && $isAdmin): ?>
              <div class="card">
                  <div class="card-body">
                      <h5 class="card-title mb-4"><i data-feather="check-circle"></i> Review & Approve</h5>

                      <!-- Guide Box -->
                      <div class="mw-guide-box">
                          <i data-feather="info"></i>
                          <div class="mw-guide-text">
                              <strong>Quality Control Workflow</strong>
                              Review each uploaded photo and approve if it meets quality standards (sharp, well-lit, shows finished work). Reject blurry or irrelevant photos.
                          </div>
                      </div>

                      <?php if ($portfolioStats['pending_review'] === 0): ?>
                          <p class="text-muted text-center py-4">No photos awaiting review</p>
                      <?php else: ?>
                          <div class="alert alert-info">
                              <strong><?php echo $portfolioStats['pending_review']; ?></strong> photos pending review
                              <div class="mw-help-text" style="margin-top: 8px;">
                                  <i data-feather="clock"></i>
                                  <span>Review and approve/reject to keep portfolio fresh with quality content.</span>
                              </div>
                          </div>

                          <div class="mw-media-grid">
                              <?php
                              $stmt = $db->prepare("
                                  SELECT mf.id, mf.thumb_path, mf.uploaded_at, u.full_name
                                  FROM media_files mf
                                  LEFT JOIN users u ON mf.owner_user_id = u.id
                                  WHERE mf.status = 'uploaded'
                                  ORDER BY mf.uploaded_at DESC
                              ");
                              $stmt->execute();
                              $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

                              foreach ($pending as $media):
                              ?>
                                  <div class="mw-media-item">
                                      <?php if ($media['thumb_path']): ?>
                                          <img src="<?php echo h($media['thumb_path']); ?>" alt="Media" loading="lazy">
                                      <?php else: ?>
                                          <div style="background: #f0f0f0;"></div>
                                      <?php endif; ?>
                                      <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.8); color: #fff; padding: 8px; font-size: 11px;">
                                          <?php echo h($media['full_name'] ?? 'Unknown'); ?><br>
                                          <small><?php echo formatDate($media['uploaded_at'], 'M j, H:i'); ?></small>
                                      </div>
                                      <div class="d-flex gap-1" style="position: absolute; top: 6px; right: 6px;">
                                          <div class="mw-tooltip">
                                              <button class="btn btn-sm btn-success" onclick="approveMedia(<?php echo $media['id']; ?>)" title="Approve">
                                                  <i data-feather="check" style="width: 14px; height: 14px;"></i>
                                              </button>
                                              <span class="mw-tooltip-text">Approve this photo for portfolio use</span>
                                          </div>
                                          <div class="mw-tooltip">
                                              <button class="btn btn-sm btn-danger" onclick="rejectMedia(<?php echo $media['id']; ?>)" title="Reject">
                                                  <i data-feather="x" style="width: 14px; height: 14px;"></i>
                                              </button>
                                              <span class="mw-tooltip-text">Reject this photo (too blurry, poor lighting, etc.)</span>
                                          </div>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      <?php endif; ?>
                  </div>
              </div>
          <?php endif; ?>

          <!-- TAB: FAVORITES -->
          <?php if ($activeTab === 'favorites'): ?>
              <div class="card">
                  <div class="card-body">
                      <h5 class="card-title mb-4"><i data-feather="heart"></i> Favorite Media</h5>

                      <!-- Guide Box -->
                      <div class="mw-guide-box">
                          <i data-feather="info"></i>
                          <div class="mw-guide-text">
                              <strong>Curated Best Work</strong>
                              Your favorite photos — high-quality images that best showcase your work. Use these for portfolio projects and marketing.
                          </div>
                      </div>

                      <?php if (empty($favorites)): ?>
                          <p class="text-muted text-center py-4">
                              No favorite photos yet.
                              <div class="mw-help-text" style="margin-top: 12px;">
                                  <i data-feather="heart"></i>
                                  <span>Click the heart icon on photos to mark them as favorites.</span>
                              </div>
                          </p>
                      <?php else: ?>
                          <div class="mw-media-grid">
                              <?php foreach ($favorites as $media): ?>
                                  <div class="mw-media-item">
                                      <img src="<?php echo h($media['web_path'] ?? $media['thumb_path']); ?>" alt="<?php echo h($media['alt_text'] ?? $media['title'] ?? 'Media'); ?>" loading="lazy">
                                      <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.8); color: #fff; padding: 8px; font-size: 12px;">
                                          <?php echo h($media['title'] ?? '(No title)'); ?><br>
                                          <small><?php echo h($media['service_type'] ?? ''); ?></small>
                                      </div>
                                      <button class="mw-media-favorite active" onclick="toggleFavorite(<?php echo $media['id']; ?>, event)" title="Remove favorite">
                                          <i data-feather="heart" style="width: 16px; height: 16px; fill: currentColor;"></i>
                                      </button>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      <?php endif; ?>
                  </div>
              </div>
          <?php endif; ?>

          <!-- TAB: PORTFOLIO ITEMS -->
          <?php if ($activeTab === 'items'): ?>
              <!-- Guide Box -->
              <div class="mw-guide-box">
                  <i data-feather="info"></i>
                  <div class="mw-guide-text">
                      <strong>Manage Published Projects</strong>
                      Create and organize portfolio projects that appear on your public website. Each project groups related photos, details, and results.
                  </div>
              </div>

              <div class="card mb-4">
                  <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="mb-0"><i data-feather="folder"></i> Portfolio Items</h5>
                      <a href="create.php" class="btn btn-sm btn-primary"><i data-feather="plus"></i> New Project</a>
                  </div>
              </div>

              <!-- Filters -->
              <div class="card mb-4">
                  <div class="card-body d-flex flex-wrap align-items-center" style="gap: 1rem;">
                      <form method="get" class="d-flex flex-wrap align-items-center" style="gap: 1rem; width: 100%;">
                          <input type="hidden" name="tab" value="items">
                          <div class="mw-search-box">
                              <input type="text" class="form-control mw-search-input" name="search" placeholder="Search projects..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                          </div>
                          <select class="form-control" name="status" style="width: auto;">
                              <option value="">All Status</option>
                              <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                              <option value="published" <?php echo $statusFilter === 'published' ? 'selected' : ''; ?>>Published</option>
                          </select>
                          <button type="submit" class="btn btn-outline-secondary">
                              <i data-feather="filter"></i> Filter
                          </button>
                          <?php if ($statusFilter || $searchQuery): ?>
                              <a href="?tab=items" class="btn btn-outline-secondary">
                                  <i data-feather="x"></i> Clear
                              </a>
                          <?php endif; ?>
                      </form>
                  </div>
              </div>

              <!-- Projects Table -->
              <div class="card">
                  <div class="table-responsive">
                      <table class="table table-hover mb-0">
                          <thead class="table-light">
                              <tr>
                                  <th>
                                      Featured
                                      <div class="mw-tooltip">
                                          <span class="mw-help-icon">?</span>
                                          <span class="mw-tooltip-text">Featured projects appear first on your public portfolio page</span>
                                      </div>
                                  </th>
                                  <th>Project Name</th>
                                  <th>Location</th>
                                  <th>Status</th>
                                  <th>
                                      Order
                                      <div class="mw-tooltip">
                                          <span class="mw-help-icon">?</span>
                                          <span class="mw-tooltip-text">Display order on the public portfolio. Lower numbers appear first.</span>
                                      </div>
                                  </th>
                                  <th>Created</th>
                                  <th>Actions</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if (empty($projects)): ?>
                                  <tr>
                                      <td colspan="7" class="text-center py-4 text-muted">
                                          No projects found. <a href="create.php">Create your first project</a>
                                      </td>
                                  </tr>
                              <?php else: ?>
                                  <?php foreach ($projects as $project): ?>
                                      <tr>
                                          <td>
                                              <?php if ($project['featured']): ?>
                                                  <span class="badge badge-warning"><i data-feather="star" style="width: 16px; height: 16px;"></i></span>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <strong><?php echo htmlspecialchars($project['project_name']); ?></strong><br>
                                              <small class="text-muted"><?php echo htmlspecialchars($project['project_number']); ?></small>
                                          </td>
                                          <td><?php echo htmlspecialchars($project['location'] ?? '-'); ?></td>
                                          <td>
                                              <?php
                                              $statusClass = $project['status'] === 'published' ? 'success' : 'secondary';
                                              echo getStatusBadge($project['status'], $statusClass);
                                              ?>
                                          </td>
                                          <td><?php echo intval($project['display_order']); ?></td>
                                          <td><?php echo formatDate($project['created_at']); ?></td>
                                          <td>
                                              <div class="mw-action-buttons">
                                                  <a href="view.php?id=<?php echo $project['id']; ?>" class="mw-action-btn mw-action-view" title="View project details">
                                                      <i data-feather="eye" style="width: 16px; height: 16px; margin-right: 4px;"></i>
                                                      <span class="mw-action-label">View</span>
                                                  </a>
                                                  <a href="edit.php?id=<?php echo $project['id']; ?>" class="mw-action-btn mw-action-edit" title="Edit project information">
                                                      <i data-feather="edit" style="width: 16px; height: 16px; margin-right: 4px;"></i>
                                                      <span class="mw-action-label">Edit</span>
                                                  </a>
                                                  <button type="button" class="mw-action-btn mw-action-delete" title="Delete this project" onclick="deleteProject(<?php echo $project['id']; ?>)">
                                                      <i data-feather="trash-2" style="width: 16px; height: 16px; margin-right: 4px;"></i>
                                                      <span class="mw-action-label">Delete</span>
                                                  </button>
                                              </div>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>
          <?php endif; ?>

          <!-- TAB: GSC INSIGHTS (Admin) -->
          <?php if ($activeTab === 'insights' && $isAdmin): ?>
              <?php
              // Load GSC data
              $gscData = include dirname(__DIR__) . '/gsc/snapshots.php';
              $latestSnapshot = $gscData['latest_snapshot'];
              $topQueries = $gscData['top_queries'];
              $topPages = $gscData['top_pages'];
              $lowCTR = $gscData['low_ctr'];
              ?>

              <!-- Guide Box -->
              <div class="mw-guide-box">
                  <i data-feather="info"></i>
                  <div class="mw-guide-text">
                      <strong>Search Performance Analytics</strong>
                      Real data from Google Search Console. See which keywords people search for, which pages rank, and improvement opportunities.
                  </div>
              </div>

              <div class="card mb-4">
                  <div class="card-body">
                      <div class="d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0"><i data-feather="bar-chart-2"></i> Google Search Console Insights</h5>
                          <div class="d-flex gap-2">
                              <div class="mw-tooltip">
                                  <button class="btn btn-sm btn-outline-secondary" id="syncGscBtn" onclick="syncGSCData()">
                                      <i data-feather="refresh-cw" style="width: 14px; height: 14px; display: inline;"></i> Sync Now
                                  </button>
                                  <span class="mw-tooltip-text">Manually pull latest data from Google Search Console</span>
                              </div>
                              <a href="/crm/gsc/connect.php" class="btn btn-sm btn-primary">Manage Connection</a>
                          </div>
                      </div>
                  </div>
              </div>

              <?php if (isset($_GET['connected'])): ?>
                  <div class="alert alert-success mb-4">
                      ✓ Google Search Console connected! Data will sync daily.
                  </div>
              <?php endif; ?>

              <?php if (!$latestSnapshot): ?>
                  <div class="card">
                      <div class="card-body text-center py-5">
                          <p class="text-muted mb-4">No GSC data available yet.</p>
                          <a href="/crm/gsc/connect.php" class="btn btn-primary">Connect Google Search Console</a>
                      </div>
                  </div>
              <?php else: ?>

                  <!-- Data Timestamp -->
                  <div class="alert alert-light border mb-4">
                      Data as of <strong><?php echo formatDate($latestSnapshot['snapshot_date']); ?></strong>
                      (Last pulled: <?php echo formatDate($latestSnapshot['pulled_at'], 'M j, H:i'); ?>)
                      <div class="mw-help-text" style="margin-top: 8px;">
                          <i data-feather="clock"></i>
                          <span>Data syncs automatically daily. Click "Sync Now" to pull fresh data manually.</span>
                      </div>
                  </div>

                  <!-- Top Queries -->
                  <div class="card mb-4">
                      <div class="card-header">
                          <div class="d-flex justify-content-between align-items-center">
                              <h6 class="mb-0">Top Search Queries (Last 28 Days)</h6>
                              <div class="mw-tooltip">
                                  <span class="mw-help-icon">?</span>
                                  <span class="mw-tooltip-text">Keywords that brought traffic to your site. Focus on queries with high impressions but low CTR.</span>
                              </div>
                          </div>
                      </div>
                      <div class="table-responsive">
                          <table class="table table-sm table-hover mb-0">
                              <thead class="table-light">
                                  <tr>
                                      <th>Query</th>
                                      <th style="width: 80px;">Impressions</th>
                                      <th style="width: 80px;">Clicks</th>
                                      <th style="width: 80px;">CTR</th>
                                      <th style="width: 100px;">Avg Position</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($topQueries)): ?>
                                      <tr>
                                          <td colspan="5" class="text-center py-4 text-muted">No query data available</td>
                                      </tr>
                                  <?php else: ?>
                                      <?php foreach ($topQueries as $query): ?>
                                          <tr>
                                              <td><strong><?php echo h($query['query']); ?></strong></td>
                                              <td><?php echo (int)$query['impressions']; ?></td>
                                              <td><?php echo (int)$query['clicks']; ?></td>
                                              <td>
                                                  <?php
                                                  $ctr = (float)$query['ctr'] * 100;
                                                  echo sprintf('%.2f%%', $ctr);
                                                  if ($ctr < 2) {
                                                      echo ' <span class="badge badge-warning">Low</span>';
                                                  }
                                                  ?>
                                              </td>
                                              <td><?php echo sprintf('%.1f', $query['position']); ?></td>
                                          </tr>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>

                  <!-- Top Pages -->
                  <div class="card mb-4">
                      <div class="card-header">
                          <div class="d-flex justify-content-between align-items-center">
                              <h6 class="mb-0">Top Performing Pages</h6>
                              <div class="mw-tooltip">
                                  <span class="mw-help-icon">?</span>
                                  <span class="mw-tooltip-text">Pages that get the most clicks from search results. These are your best-performing content.</span>
                              </div>
                          </div>
                      </div>
                      <div class="table-responsive">
                          <table class="table table-sm table-hover mb-0">
                              <thead class="table-light">
                                  <tr>
                                      <th>Page</th>
                                      <th style="width: 80px;">Clicks</th>
                                      <th style="width: 100px;">Impressions</th>
                                      <th style="width: 80px;">CTR</th>
                                      <th style="width: 100px;">Avg Position</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($topPages)): ?>
                                      <tr>
                                          <td colspan="5" class="text-center py-4 text-muted">No page data available</td>
                                      </tr>
                                  <?php else: ?>
                                      <?php foreach ($topPages as $page): ?>
                                          <tr>
                                              <td>
                                                  <a href="<?php echo h($page['page']); ?>" target="_blank" rel="noopener">
                                                      <?php echo h(parse_url($page['page'], PHP_URL_PATH)); ?>
                                                  </a>
                                              </td>
                                              <td><strong><?php echo (int)$page['clicks']; ?></strong></td>
                                              <td><?php echo (int)$page['impressions']; ?></td>
                                              <td><?php echo sprintf('%.2f%%', (float)$page['ctr'] * 100); ?></td>
                                              <td><?php echo sprintf('%.1f', $page['position']); ?></td>
                                          </tr>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>

                  <!-- Low CTR Opportunities -->
                  <?php if (!empty($lowCTR)): ?>
                      <div class="card">
                          <div class="card-header bg-warning bg-opacity-10">
                              <div class="d-flex justify-content-between align-items-center">
                                  <h6 class="mb-0" style="color: #856404;">
                                      <i data-feather="alert-circle" style="width: 16px; height: 16px; display: inline;"></i>
                                      Optimization Opportunities
                                  </h6>
                                  <div class="mw-tooltip">
                                      <span class="mw-help-icon" style="background: #856404;">?</span>
                                      <span class="mw-tooltip-text">Pages with many impressions but low click-through rate. Improve titles and meta descriptions to increase clicks.</span>
                                  </div>
                              </div>
                          </div>
                          <div class="table-responsive">
                              <table class="table table-sm table-hover mb-0">
                                  <thead class="table-light">
                                      <tr>
                                          <th>Page (High Impressions, Low CTR)</th>
                                          <th style="width: 100px;">Impressions</th>
                                          <th style="width: 80px;">CTR</th>
                                          <th style="width: 100px;">Avg Position</th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      <?php foreach ($lowCTR as $item): ?>
                                          <tr style="background: #fffbf0;">
                                              <td>
                                                  <a href="<?php echo h($item['page']); ?>" target="_blank" rel="noopener">
                                                      <?php echo h(parse_url($item['page'], PHP_URL_PATH)); ?>
                                                  </a>
                                                  <br><small class="text-muted">Optimize title/meta to improve CTR</small>
                                              </td>
                                              <td><?php echo (int)$item['impressions']; ?></td>
                                              <td><?php echo sprintf('%.2f%%', (float)$item['ctr'] * 100); ?></td>
                                              <td><?php echo sprintf('%.1f', $item['position']); ?></td>
                                          </tr>
                                      <?php endforeach; ?>
                                  </tbody>
                              </table>
                          </div>
                      </div>
                  <?php endif; ?>

              <?php endif; ?>
          <?php endif; ?>

          <!-- TAB: RECOMMENDATIONS (Admin) -->
          <?php if ($activeTab === 'recommendations' && $isAdmin): ?>

              <!-- Guide Box -->
              <div class="mw-guide-box">
                  <i data-feather="info"></i>
                  <div class="mw-guide-text">
                      <strong>SEO Content Strategy</strong>
                      AI-powered recommendations for content to create, improve, or publish. Track which suggestions you've accepted and applied.
                  </div>
              </div>

              <!-- Stats Bar -->
              <div class="mw-recommendations-stats">
                  <div class="mw-rec-stat-card">
                      <h6>
                          Total
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">Total recommendations generated</span>
                          </div>
                      </h6>
                      <div class="value"><?php echo $recommendationStats['total']; ?></div>
                  </div>
                  <div class="mw-rec-stat-card">
                      <h6>
                          New
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">Not yet reviewed</span>
                          </div>
                      </h6>
                      <div class="value"><?php echo $recommendationStats['new'] ?? 0; ?></div>
                  </div>
                  <div class="mw-rec-stat-card">
                      <h6>
                          Accepted
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">You've approved these recommendations</span>
                          </div>
                      </h6>
                      <div class="value"><?php echo $recommendationStats['accepted'] ?? 0; ?></div>
                  </div>
                  <div class="mw-rec-stat-card">
                      <h6>
                          Applied
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">Completed and published</span>
                          </div>
                      </h6>
                      <div class="value"><?php echo $recommendationStats['applied'] ?? 0; ?></div>
                  </div>
              </div>

              <!-- Filter Card -->
              <div class="card mb-4">
                  <div class="card-body mw-rec-filters">
                      <h6 class="card-title mb-3">
                          <i data-feather="filter"></i> Filter & Actions
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">Filter by status, type, target, or season to focus on specific recommendations.</span>
                          </div>
                      </h6>
                      <div class="row g-3">
                          <div class="col-md-3">
                              <label class="form-label mb-2">Status</label>
                              <select id="recStatusFilter" class="form-control form-control-sm" onchange="filterRecommendations()">
                                  <option value="">All</option>
                                  <option value="new">New</option>
                                  <option value="accepted">Accepted</option>
                                  <option value="applied">Applied</option>
                                  <option value="done">Done</option>
                                  <option value="ignored">Ignored</option>
                              </select>
                          </div>
                          <div class="col-md-3">
                              <label class="form-label mb-2">Type</label>
                              <select id="recTypeFilter" class="form-control form-control-sm" onchange="filterRecommendations()">
                                  <option value="">All</option>
                                  <option value="create_page">Create Page</option>
                                  <option value="improve_page">Improve Page</option>
                                  <option value="title_meta">Title/Meta</option>
                                  <option value="internal_links">Internal Links</option>
                                  <option value="add_photos">Add Photos</option>
                                  <option value="schema">Schema</option>
                                  <option value="seasonal">Seasonal</option>
                              </select>
                          </div>
                          <div class="col-md-3">
                              <label class="form-label mb-2">Target</label>
                              <select id="recTargetFilter" class="form-control form-control-sm" onchange="filterRecommendations()">
                                  <option value="">All</option>
                                  <?php if (!empty($recommendationsData['targets'])): ?>
                                      <?php foreach ($recommendationsData['targets'] as $target): ?>
                                          <option value="<?php echo $target['id']; ?>"><?php echo h($target['name']); ?></option>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              </select>
                          </div>
                          <div class="col-md-3">
                              <label class="form-label mb-2">Season</label>
                              <select id="recSeasonFilter" class="form-control form-control-sm" onchange="filterRecommendations()">
                                  <option value="">All</option>
                                  <?php if (!empty($recommendationsData['seasons'])): ?>
                                      <?php foreach ($recommendationsData['seasons'] as $season): ?>
                                          <option value="<?php echo $season['id']; ?>"><?php echo h($season['label']); ?></option>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              </select>
                          </div>
                      </div>
                      <div class="row g-2 mt-2">
                          <div class="col-auto">
                              <div class="mw-tooltip">
                                  <button type="button" class="btn btn-sm btn-primary" id="generateRecBtn" onclick="generateRecommendations()">
                                      <i data-feather="refresh-cw" style="width: 14px; height: 14px; display: inline; margin-right: 4px;"></i>Generate Recommendations
                                  </button>
                                  <span class="mw-tooltip-text">Analyze your site and create new SEO recommendations</span>
                              </div>
                          </div>
                          <div class="col-auto">
                              <div class="mw-tooltip">
                                  <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="collapse" data-target="#targetingSettings">
                                      <i data-feather="settings" style="width: 14px; height: 14px; display: inline; margin-right: 4px;"></i>Targeting Settings
                                  </button>
                                  <span class="mw-tooltip-text">Manage geographic and seasonal targets for recommendations</span>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Targeting Settings (Collapsible) -->
              <div class="collapse mb-4" id="targetingSettings">
                  <div class="card">
                      <div class="card-body mw-targeting-settings">
                          <h6><i data-feather="map-pin"></i> Geographic Targets</h6>
                          <p class="text-muted mb-3">Active geographic targets used for boost scoring and local intent detection.</p>
                          <div id="targetsList" style="max-height: 400px; overflow-y: auto;">
                              <?php if (!empty($recommendationsData['targets'])): ?>
                                  <?php foreach ($recommendationsData['targets'] as $target): ?>
                                      <div class="mw-target-item">
                                          <div class="mw-target-item-header">
                                              <div>
                                                  <span class="mw-target-item-name"><?php echo h($target['name']); ?></span>
                                                  <span class="mw-target-item-type"><?php echo ucfirst($target['target_type']); ?></span>
                                              </div>
                                          </div>
                                          <div class="mw-target-item-details">
                                              <strong><?php echo h($target['canonical_slug']); ?></strong>
                                          </div>
                                      </div>
                                  <?php endforeach; ?>
                              <?php else: ?>
                                  <p class="text-muted">No targets configured. Create targets to enable geographic boosting.</p>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Recommendations Table -->
              <div class="card">
                  <div class="card-body">
                      <h6 class="card-title mb-3">Recommendations</h6>
                      <?php if (empty($recommendationsData['recommendations'])): ?>
                          <div class="alert alert-info mb-0">
                              <i data-feather="info" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>
                              No recommendations yet. Click "Generate Recommendations" to analyze GSC data.
                          </div>
                      <?php else: ?>
                          <div class="table-responsive">
                              <table class="table table-hover mb-0 mw-rec-table" id="recommendationsTable">
                                  <thead class="table-light">
                                      <tr>
                                          <th style="width: 50px;">Score</th>
                                          <th>Query</th>
                                          <th style="width: 80px;">Volume</th>
                                          <th style="width: 60px;">CTR</th>
                                          <th style="width: 60px;">Pos</th>
                                          <th style="width: 100px;">Target</th>
                                          <th style="width: 100px;">Type</th>
                                          <th style="width: 80px;">Status</th>
                                          <th style="width: 120px;">Actions</th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      <?php foreach ($recommendationsData['recommendations'] as $rec): ?>
                                          <tr data-rec-id="<?php echo $rec['id']; ?>" data-status="<?php echo $rec['status']; ?>" data-type="<?php echo $rec['rec_type']; ?>" data-target="<?php echo $rec['target_id'] ?? ''; ?>" data-season="<?php echo $rec['season_id'] ?? ''; ?>">
                                              <td>
                                                  <span class="badge <?php echo $rec['priority_score'] >= 80 ? 'mw-rec-score-high' : ($rec['priority_score'] >= 60 ? 'mw-rec-score-medium' : 'mw-rec-score-low'); ?>">
                                                      <?php echo $rec['priority_score']; ?>
                                                  </span>
                                              </td>
                                              <td>
                                                  <strong><?php echo h($rec['query_text']); ?></strong><br>
                                                  <small class="text-muted"><?php echo h($rec['suggested_slug']); ?></small>
                                              </td>
                                              <td><?php echo $rec['impressions'] ?? $rec['search_volume'] ?? '-'; ?></td>
                                              <td><?php echo number_format($rec['ctr'] * 100, 1); ?>%</td>
                                              <td><?php echo $rec['avg_position'] ? number_format($rec['avg_position'], 1) : '-'; ?></td>
                                              <td>
                                                  <?php if ($rec['target_id']): ?>
                                                      <span class="mw-rec-badge mw-rec-badge-target"><?php echo h($rec['target_name'] ?? 'Unknown'); ?></span>
                                                  <?php else: ?>
                                                      <span class="text-muted">-</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td>
                                                  <span class="mw-rec-badge mw-rec-badge-type"><?php echo str_replace('_', ' ', $rec['rec_type']); ?></span>
                                              </td>
                                              <td>
                                                  <?php
                                                  $statusClass = 'status-' . $rec['status'];
                                                  ?>
                                                  <span class="mw-rec-badge mw-rec-badge-status <?php echo $statusClass; ?>"><?php echo ucfirst($rec['status']); ?></span>
                                              </td>
                                              <td>
                                                  <?php if ($rec['status'] === 'new'): ?>
                                                      <button type="button" class="btn btn-xs btn-success" onclick="acceptRecommendation(<?php echo $rec['id']; ?>)" title="Accept">✓</button>
                                                      <button type="button" class="btn btn-xs btn-secondary" onclick="ignoreRecommendation(<?php echo $rec['id']; ?>)" title="Ignore">✕</button>
                                                  <?php elseif ($rec['status'] === 'accepted'): ?>
                                                      <button type="button" class="btn btn-xs btn-primary" onclick="applyRecommendation(<?php echo $rec['id']; ?>)" title="Apply">Apply</button>
                                                  <?php elseif ($rec['status'] === 'applied'): ?>
                                                      <button type="button" class="btn btn-xs btn-success" onclick="markRecommendationDone(<?php echo $rec['id']; ?>)" title="Done">Done</button>
                                                  <?php endif; ?>
                                              </td>
                                          </tr>
                                      <?php endforeach; ?>
                                  </tbody>
                              </table>
                          </div>

                          <!-- Pagination (if applicable) -->
                          <?php if (!empty($recommendationsData['pagination']) && $recommendationsData['pagination']['total_pages'] > 1): ?>
                              <nav class="mt-3 mw-rec-pagination">
                                  <ul class="pagination pagination-sm">
                                      <?php
                                      $currentPage = $recommendationsData['pagination']['page'];
                                      $totalPages = $recommendationsData['pagination']['total_pages'];
                                      for ($p = 1; $p <= $totalPages; $p++):
                                      ?>
                                          <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                              <a class="page-link" href="?tab=recommendations&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                          </li>
                                      <?php endfor; ?>
                                  </ul>
                              </nav>
                          <?php endif; ?>
                      <?php endif; ?>
                  </div>
              </div>

              <!-- Apply Recommendation Modal -->
              <div class="modal fade" id="applyModal" tabindex="-1" role="dialog">
                  <div class="modal-dialog modal-lg" role="document">
                      <div class="modal-content">
                          <div class="modal-header">
                              <h5 class="modal-title">Apply Recommendation</h5>
                              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                          </div>
                          <div class="modal-body">
                              <div id="applyModalBody">Loading...</div>
                          </div>
                          <div class="modal-footer">
                              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                              <button type="button" class="btn btn-primary" id="applyConfirmBtn" onclick="confirmApplyRecommendation()">Create Draft</button>
                          </div>
                      </div>
                  </div>
              </div>

          <?php endif; ?>

          <!-- TAB: ROI DASHBOARD (Admin) -->
          <?php if ($activeTab === 'roi' && $isAdmin): ?>
              <?php
              // Get date range from query params
              $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
              $endDate = $_GET['end_date'] ?? date('Y-m-d');
              $source = $_GET['source'] ?? null;

              // Get funnel stats
              $funnelStats = getROIFunnelStats($startDate, $endDate, $source);
              $revenueBySource = getRevenueBySource($startDate, $endDate);
              $funnelDetails = getConversionFunnelDetails($startDate, $endDate);
              ?>

              <!-- Guide Box -->
              <div class="mw-guide-box">
                  <i data-feather="info"></i>
                  <div class="mw-guide-text">
                      <strong>Track Lead-to-Customer Conversion</strong>
                      See your sales funnel: leads → quote requests → quotes sent → closed deals. Filter by date range and traffic source.
                  </div>
              </div>

              <div class="card mb-4">
                  <div class="card-body">
                      <h5 class="card-title mb-0"><i data-feather="trending-up"></i> ROI & Funnel Dashboard</h5>
                  </div>
              </div>

              <!-- Date Range Filter -->
              <div class="card mb-4">
                  <div class="card-body">
                      <form method="get" class="d-flex flex-wrap align-items-center gap-3">
                          <input type="hidden" name="tab" value="roi">
                          <div>
                              <label class="form-label mb-1">
                                  Start Date
                                  <div class="mw-tooltip">
                                      <span class="mw-help-icon">?</span>
                                      <span class="mw-tooltip-text">Beginning date for this analysis</span>
                                  </div>
                              </label>
                              <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                          </div>
                          <div>
                              <label class="form-label mb-1">
                                  End Date
                                  <div class="mw-tooltip">
                                      <span class="mw-help-icon">?</span>
                                      <span class="mw-tooltip-text">End date for this analysis</span>
                                  </div>
                              </label>
                              <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                          </div>
                          <div>
                              <label class="form-label mb-1">
                                  Filter Source
                                  <div class="mw-tooltip">
                                      <span class="mw-help-icon">?</span>
                                      <span class="mw-tooltip-text">Show leads from a specific source (organic search, direct, ads, etc.)</span>
                                  </div>
                              </label>
                              <select name="source" class="form-control">
                                  <option value="">All Sources</option>
                                  <?php foreach ($revenueBySource as $item): ?>
                                      <option value="<?php echo h($item['source'] ?? ''); ?>" <?php echo ($source === $item['source']) ? 'selected' : ''; ?>>
                                          <?php echo h($item['source'] ?? '(direct)'); ?>
                                      </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div>
                              <label class="form-label mb-1">&nbsp;</label>
                              <button type="submit" class="btn btn-primary">Filter</button>
                          </div>
                      </form>
                  </div>
              </div>

              <!-- Conversion Funnel Chart -->
              <div class="card mb-4">
                  <div class="card-header">
                      <div class="d-flex justify-content-between align-items-center">
                          <h6 class="mb-0">Conversion Funnel (<?php echo $startDate; ?> to <?php echo $endDate; ?>)</h6>
                          <div class="mw-tooltip">
                              <span class="mw-help-icon">?</span>
                              <span class="mw-tooltip-text">Shows what % of leads move to each stage. Lower percentages indicate where to optimize.</span>
                          </div>
                      </div>
                  </div>
                  <div class="card-body">
                      <div class="mw-funnel-chart">
                          <div class="mw-funnel-stage">
                              <div class="count"><?php echo $funnelStats['leads']; ?></div>
                              <div class="label">Leads</div>
                              <div class="rate">100%</div>
                          </div>
                          <div class="mw-funnel-stage">
                              <div class="count"><?php echo $funnelStats['quote_requests']; ?></div>
                              <div class="label">Quote Requests</div>
                              <div class="rate"><?php echo sprintf('%.1f%%', $funnelStats['lead_to_quote_rate']); ?></div>
                          </div>
                          <div class="mw-funnel-stage">
                              <div class="count">-</div>
                              <div class="label">Quotes Sent</div>
                              <div class="rate">~50%*</div>
                          </div>
                          <div class="mw-funnel-stage">
                              <div class="count"><?php echo $funnelStats['jobs_completed']; ?></div>
                              <div class="label">Jobs Won</div>
                              <div class="rate"><?php echo sprintf('%.1f%%', $funnelStats['quote_to_job_rate']); ?></div>
                          </div>
                          <div class="mw-funnel-stage">
                              <div class="count">$<?php echo number_format((int)$funnelStats['revenue']); ?></div>
                              <div class="label">Revenue</div>
                              <div class="rate">$<?php echo number_format((int)($funnelStats['job_to_revenue_rate'] ?? 0)); ?>/job</div>
                          </div>
                      </div>
                      <p class="text-muted small mt-3">*Quotes sent tracking coming in next phase</p>
                  </div>
              </div>

              <!-- Revenue by Source -->
              <div class="card mb-4">
                  <div class="card-header">
                      <h6 class="mb-0">Revenue by Source</h6>
                  </div>
                  <div class="table-responsive">
                      <table class="table table-sm table-hover mb-0">
                          <thead class="table-light">
                              <tr>
                                  <th>Source</th>
                                  <th style="width: 100px;">Campaign</th>
                                  <th style="width: 80px;">Leads</th>
                                  <th style="width: 100px;">Quotes</th>
                                  <th style="width: 80px;">Jobs</th>
                                  <th style="width: 120px;">Revenue</th>
                                  <th style="width: 100px;">Conv Rate</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if (empty($revenueBySource)): ?>
                                  <tr>
                                      <td colspan="7" class="text-center py-4 text-muted">No data yet. Track UTM parameters on your marketing links.</td>
                                  </tr>
                              <?php else: ?>
                                  <?php foreach ($revenueBySource as $item): ?>
                                      <tr>
                                          <td><strong><?php echo h($item['source'] ?? '(direct)'); ?></strong></td>
                                          <td><small><?php echo h($item['campaign'] ?? '-'); ?></small></td>
                                          <td><?php echo (int)$item['leads']; ?></td>
                                          <td><?php echo (int)$item['quote_requests']; ?></td>
                                          <td><?php echo (int)$item['jobs']; ?></td>
                                          <td><strong>$<?php echo number_format((int)($item['revenue'] ?? 0)); ?></strong></td>
                                          <td>
                                              <?php
                                              $convRate = $item['leads'] > 0 ? (($item['jobs'] ?? 0) / $item['leads']) * 100 : 0;
                                              echo sprintf('%.1f%%', $convRate);
                                              ?>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>

              <!-- Recent Conversions -->
              <div class="card">
                  <div class="card-header">
                      <h6 class="mb-0">Recent Lead Journey (Last 100 Leads)</h6>
                  </div>
                  <div class="table-responsive">
                      <table class="table table-sm table-hover mb-0">
                          <thead class="table-light">
                              <tr>
                                  <th>Source/Campaign</th>
                                  <th>Landing Page</th>
                                  <th>Events</th>
                                  <th>Job</th>
                                  <th>Revenue</th>
                                  <th>Date</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if (empty($funnelDetails)): ?>
                                  <tr>
                                      <td colspan="6" class="text-center py-4 text-muted">No leads yet</td>
                                  </tr>
                              <?php else: ?>
                                  <?php foreach ($funnelDetails as $lead): ?>
                                      <tr>
                                          <td>
                                              <small>
                                                  <strong><?php echo h($lead['utm_source'] ?? '(direct)'); ?></strong><br>
                                                  <?php echo h($lead['utm_campaign'] ?? ''); ?>
                                              </small>
                                          </td>
                                          <td>
                                              <small><?php echo h(parse_url($lead['landing_page'] ?? '', PHP_URL_PATH) ?? '(referrer)'); ?></small>
                                          </td>
                                          <td>
                                              <small>
                                                  <?php
                                                  $events = explode(',', $lead['events'] ?? '');
                                                  foreach ($events as $event) {
                                                      if (trim($event)) {
                                                          echo '<span class="badge badge-primary">' . ucwords(str_replace('_', ' ', trim($event))) . '</span> ';
                                                      }
                                                  }
                                                  ?>
                                              </small>
                                          </td>
                                          <td>
                                              <?php if ($lead['job_number']): ?>
                                                  <a href="/crm/jobs/view.php?id=<?php echo urlencode($lead['job_number']); ?>" target="_blank">
                                                      <?php echo h($lead['job_number']); ?>
                                                  </a>
                                              <?php else: ?>
                                                  <span class="text-muted">-</span>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <?php if ($lead['total_paid']): ?>
                                                  <strong>$<?php echo number_format((int)$lead['total_paid']); ?></strong>
                                              <?php else: ?>
                                                  <span class="text-muted">-</span>
                                              <?php endif; ?>
                                          </td>
                                          <td><small><?php echo formatDate($lead['lead_date'], 'M j, H:i'); ?></small></td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>

          <?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<script>
const CSRF_TOKEN = '<?php echo $csrfToken; ?>';

// File upload handling
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');

    if (dropZone && fileInput) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('hover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('hover'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);
        fileInput.addEventListener('change', handleFileSelect, false);
    }

    feather.replace();
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    fileInput.files = files;
    uploadFiles(files);
}

function handleFileSelect(e) {
    uploadFiles(e.target.files);
}

function uploadFiles(files) {
    for (let file of files) {
        uploadFile(file);
    }
}

function uploadFile(file) {
    const formData = new FormData();
    formData.append('photo', file);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('service_type', 'general');

    fetch('/crm/media/upload.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Photo uploaded: ' + file.name);
            location.reload();
        } else {
            alert('Upload failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Upload error:', err);
        alert('Upload error: ' + err.message);
    });
}

function approveMedia(mediaId) {
    if (confirm('Approve this photo?')) {
        const formData = new FormData();
        formData.append('media_id', mediaId);
        formData.append('action', 'approve');
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('/crm/media/approve.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Photo approved');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => console.error(err));
    }
}

function rejectMedia(mediaId) {
    const reason = prompt('Reason for rejection (optional):');
    if (reason !== null) {
        const formData = new FormData();
        formData.append('media_id', mediaId);
        formData.append('action', 'reject');
        formData.append('reason', reason);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('/crm/media/approve.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Photo rejected');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => console.error(err));
    }
}

function toggleFavorite(mediaId, event) {
    event.preventDefault();
    const formData = new FormData();
    formData.append('media_id', mediaId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/media/favorite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => console.error(err));
}

function viewMediaDetails(mediaId) {
    alert('Media detail view coming soon (ID: ' + mediaId + ')');
}

function deleteProject(projectId) {
    if (confirm('Are you sure you want to delete this project? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + projectId;
    }
}

function syncGSCData() {
    const btn = document.getElementById('syncGscBtn');
    const originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⟳</span> Syncing...';

    const formData = new FormData();
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/gsc/sync-cron.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Sync failed: ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        if (data.success) {
            alert('✓ GSC data synced successfully! ' + (data.message || ''));
            // Reload page to show updated data
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        alert('Sync error: ' + err.message);
        console.error('GSC sync error:', err);
    });
}

// Spinner animation
const style = document.createElement('style');
style.textContent = `
  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`;
document.head.appendChild(style);

// ============================================================
// SEO Recommendations Functions (Phase 2)
// ============================================================

/**
 * Filter recommendations table based on dropdown selections
 */
function filterRecommendations() {
    const statusFilter = document.getElementById('recStatusFilter')?.value || '';
    const typeFilter = document.getElementById('recTypeFilter')?.value || '';
    const targetFilter = document.getElementById('recTargetFilter')?.value || '';
    const seasonFilter = document.getElementById('recSeasonFilter')?.value || '';

    const table = document.getElementById('recommendationsTable');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        const rowType = row.dataset.type;
        const rowTarget = row.dataset.target;
        const rowSeason = row.dataset.season;

        let show = true;
        if (statusFilter && rowStatus !== statusFilter) show = false;
        if (typeFilter && rowType !== typeFilter) show = false;
        if (targetFilter && rowTarget !== targetFilter) show = false;
        if (seasonFilter && rowSeason !== seasonFilter) show = false;

        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    // Show message if no rows match
    if (visibleCount === 0) {
        table.parentElement.innerHTML += '<p class="text-muted text-center py-4">No recommendations match filters</p>';
    }
}

/**
 * Generate recommendations via API
 */
function generateRecommendations() {
    const btn = document.getElementById('generateRecBtn');
    const originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⟳</span> Generating...';

    const formData = new FormData();
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/api/seo/generate.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Generation failed: ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (data.success) {
            alert('✓ Recommendations generated!\n\n' + data.message);
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        alert('Error: ' + err.message);
        console.error('Generation error:', err);
    });
}

/**
 * Accept a recommendation (change status from 'new' to 'accepted')
 */
function acceptRecommendation(recId) {
    if (!confirm('Accept this recommendation?')) return;

    const formData = new FormData();
    formData.append('recommendation_id', recId);
    formData.append('status', 'accepted');
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/api/seo/status.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Recommendation accepted');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        console.error(err);
    });
}

/**
 * Ignore a recommendation (change status to 'ignored')
 */
function ignoreRecommendation(recId) {
    if (!confirm('Ignore this recommendation?')) return;

    const formData = new FormData();
    formData.append('recommendation_id', recId);
    formData.append('status', 'ignored');
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/api/seo/status.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Recommendation ignored');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        console.error(err);
    });
}

/**
 * Apply a recommendation (create draft page)
 */
function applyRecommendation(recId) {
    const modalBody = document.getElementById('applyModalBody');
    modalBody.innerHTML = '<p class="text-center"><span style="display: inline-block; animation: spin 1s linear infinite;">⟳</span> Loading preview...</p>';

    fetch(`/crm/api/seo/apply-preview.php?id=${recId}`)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const preview = data.content;
            modalBody.innerHTML = `
                <div class="mb-3">
                    <h6>SEO Title</h6>
                    <p class="text-monospace small" style="background: #f5f5f5; padding: 8px; border-radius: 3px;">
                        ${escapeHtml(preview.title)}
                    </p>
                </div>
                <div class="mb-3">
                    <h6>Meta Description</h6>
                    <p class="text-monospace small" style="background: #f5f5f5; padding: 8px; border-radius: 3px;">
                        ${escapeHtml(preview.meta_description)}
                    </p>
                </div>
                <div class="mb-3">
                    <h6>H1 Heading</h6>
                    <p class="text-monospace small" style="background: #f5f5f5; padding: 8px; border-radius: 3px;">
                        ${escapeHtml(preview.h1)}
                    </p>
                </div>
                <div class="mb-3">
                    <h6>Suggested Slug</h6>
                    <p class="text-monospace small" style="background: #f5f5f5; padding: 8px; border-radius: 3px;">
                        /${escapeHtml(preview.slug)}
                    </p>
                </div>
                <div class="alert alert-info mb-0">
                    <strong>Content:</strong> ${preview.sections_count} sections + ${preview.images_count} images + schema markup
                </div>
            `;
            document.getElementById('applyConfirmBtn').dataset.recId = recId;
            $('#applyModal').modal('show');
        } else {
            modalBody.innerHTML = '<div class="alert alert-danger">Error: ' + (data.message || 'Unknown') + '</div>';
        }
    })
    .catch(err => {
        modalBody.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
        console.error(err);
    });
}

/**
 * Confirm apply and create draft
 */
function confirmApplyRecommendation() {
    const recId = document.getElementById('applyConfirmBtn').dataset.recId;
    const btn = document.getElementById('applyConfirmBtn');
    const originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⟳</span> Creating...';

    const formData = new FormData();
    formData.append('recommendation_id', recId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/api/seo/apply.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (data.success) {
            alert('✓ Draft page created (ID: ' + data.draft_id + ')');
            $('#applyModal').modal('hide');
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.message || 'Unknown'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        alert('Error: ' + err.message);
        console.error(err);
    });
}

/**
 * Mark recommendation as done
 */
function markRecommendationDone(recId) {
    if (!confirm('Mark as done?')) return;

    const formData = new FormData();
    formData.append('recommendation_id', recId);
    formData.append('status', 'done');
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/crm/api/seo/status.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Recommendation marked as done');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        console.error(err);
    });
}

/**
 * Escape HTML for safe display
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
