<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireSiteLogin();
$siteUser = getSiteUser();

// Load CMS functions for stats
$cmsFuncs = dirname(__DIR__) . '/app/Modules/CMS/Services/CmsFunctions.php';
if (is_file($cmsFuncs)) require_once $cmsFuncs;

// Gather stats
$db = getDB();
$siteId = CMS_SITE_ID;

$totalPages = 0;
$publishedPages = 0;
$draftPages = 0;
$totalViews = 0;
$recentPages = [];

try {
    $row = $db->prepare("SELECT
        COUNT(*) as total,
        SUM(status = 'published') as published,
        SUM(status = 'draft') as draft,
        COALESCE(SUM(view_count), 0) as views
        FROM cms_pages WHERE site_id = ?");
    $row->execute([$siteId]);
    $stats = $row->fetch(PDO::FETCH_ASSOC);
    $totalPages    = (int)($stats['total'] ?? 0);
    $publishedPages = (int)($stats['published'] ?? 0);
    $draftPages    = (int)($stats['draft'] ?? 0);
    $totalViews    = (int)($stats['views'] ?? 0);

    $stmt = $db->prepare("SELECT id, title, slug, status, updated_at, view_count
        FROM cms_pages WHERE site_id = ?
        ORDER BY updated_at DESC LIMIT 5");
    $stmt->execute([$siteId]);
    $recentPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Tables not yet created — show zeros
}

$adminTitle = 'Dashboard';
$activePage = 'dashboard';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/nav.php';
?>

<div class="sa-page-header">
  <h1>Dashboard</h1>
  <a href="/site-admin/pages.php?action=new" class="sa-btn sa-btn-primary">
    <i data-feather="plus"></i> New Page
  </a>
</div>

<!-- Stats -->
<div class="sa-stats">
  <div class="sa-stat">
    <div class="sa-stat-value"><?= $totalPages ?></div>
    <div class="sa-stat-label">Total Pages</div>
  </div>
  <div class="sa-stat">
    <div class="sa-stat-value" style="color:var(--sa-success)"><?= $publishedPages ?></div>
    <div class="sa-stat-label">Published</div>
  </div>
  <div class="sa-stat">
    <div class="sa-stat-value" style="color:var(--sa-text-muted)"><?= $draftPages ?></div>
    <div class="sa-stat-label">Drafts</div>
  </div>
  <div class="sa-stat">
    <div class="sa-stat-value"><?= number_format($totalViews) ?></div>
    <div class="sa-stat-label">Total Views</div>
  </div>
</div>

<!-- Recent pages -->
<div class="sa-card">
  <div class="sa-card-header">
    <h2>Recent Pages</h2>
    <a href="/site-admin/pages.php" class="sa-btn sa-btn-secondary sa-btn-sm">View all</a>
  </div>
  <?php if (empty($recentPages)): ?>
    <div class="sa-empty">
      <i data-feather="file-text"></i>
      <h3>No pages yet</h3>
      <p>Create your first page to get started.</p>
      <a href="/site-admin/pages.php?action=new" class="sa-btn sa-btn-primary">Create Page</a>
    </div>
  <?php else: ?>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Status</th>
            <th>Views</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentPages as $p): ?>
            <tr>
              <td>
                <a href="/site-admin/pages.php?action=edit&id=<?= (int)$p['id'] ?>" style="font-weight:500;color:var(--sa-primary);text-decoration:none;">
                  <?= h($p['title']) ?>
                </a>
                <div style="font-size:12px;color:var(--sa-text-muted)">/<?= h($p['slug']) ?></div>
              </td>
              <td><span class="sa-badge sa-badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
              <td><?= number_format((int)$p['view_count']) ?></td>
              <td style="color:var(--sa-text-muted)"><?= date('M j, Y', strtotime($p['updated_at'])) ?></td>
              <td>
                <a href="/<?= h($p['slug']) ?>" target="_blank" class="sa-btn sa-btn-secondary sa-btn-sm">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
