<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireSiteLogin();
$siteUser = getSiteUser();

$cmsFuncs = dirname(__DIR__) . '/app/Modules/CMS/Services/CmsFunctions.php';
if (is_file($cmsFuncs)) require_once $cmsFuncs;

$db     = getDB();
$siteId = CMS_SITE_ID;

// ── Status filter ─────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'published', 'draft', 'archived'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'all';

// ── Fetch pages ───────────────────────────────────────────────────────────────
$pages = [];
$counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0];

try {
    // Counts per status
    $stmt = $db->prepare("SELECT status, COUNT(*) as n FROM cms_pages WHERE site_id = ? GROUP BY status");
    $stmt->execute([$siteId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['status']] = (int)$row['n'];
        $counts['all'] += (int)$row['n'];
    }

    $sql = "SELECT id, slug, title, page_type, status, updated_at, view_count, seo_score
            FROM cms_pages WHERE site_id = ?";
    $params = [$siteId];
    if ($filterStatus !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $filterStatus;
    }
    $sql .= " ORDER BY updated_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Tables not yet created
}

$adminTitle = 'Pages';
$activePage = 'pages';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/nav.php';
?>

<div class="sa-page-header">
  <h1>Pages</h1>
  <a href="/site-admin/page-edit.php" class="sa-btn sa-btn-primary">
    <i data-feather="plus"></i> New Page
  </a>
</div>

<!-- Status filter tabs -->
<div class="sa-filter-tabs" style="display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--sa-border);padding-bottom:0;">
  <?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts', 'archived' => 'Archived'] as $key => $label): ?>
    <a href="?status=<?= $key ?>"
       style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-bottom:2px solid <?= $filterStatus === $key ? 'var(--sa-primary)' : 'transparent' ?>;color:<?= $filterStatus === $key ? 'var(--sa-primary)' : 'var(--sa-text-muted)' ?>;white-space:nowrap;">
      <?= $label ?>
      <span style="background:var(--sa-bg);border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?= $counts[$key] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="sa-card">
  <?php if (empty($pages)): ?>
    <div class="sa-empty">
      <i data-feather="file-text"></i>
      <h3>No pages <?= $filterStatus !== 'all' ? "with status &ldquo;{$filterStatus}&rdquo;" : 'yet' ?></h3>
      <p>Pages you create will appear here.</p>
      <?php if ($filterStatus === 'all'): ?>
        <a href="/site-admin/page-edit.php" class="sa-btn sa-btn-primary">Create your first page</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Type</th>
            <th>Status</th>
            <th>SEO</th>
            <th>Views</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pages as $p): ?>
            <tr>
              <td>
                <div style="font-weight:500"><?= h($p['title']) ?></div>
                <div style="font-size:12px;color:var(--sa-text-muted)">/<?= h($p['slug']) ?></div>
              </td>
              <td style="color:var(--sa-text-muted);font-size:12.5px"><?= h(str_replace('_', ' ', $p['page_type'])) ?></td>
              <td><span class="sa-badge sa-badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
              <td>
                <?php if ($p['seo_score'] !== null): ?>
                  <?php
                    $score = (int)$p['seo_score'];
                    $color = $score >= 70 ? 'var(--sa-success)' : ($score >= 40 ? 'var(--sa-warning)' : 'var(--sa-danger)');
                  ?>
                  <span style="font-weight:600;color:<?= $color ?>"><?= $score ?></span>
                <?php else: ?>
                  <span style="color:var(--sa-text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--sa-text-muted)"><?= number_format((int)$p['view_count']) ?></td>
              <td style="color:var(--sa-text-muted);font-size:12.5px"><?= date('M j, Y', strtotime($p['updated_at'])) ?></td>
              <td>
                <div style="display:flex;gap:6px;align-items:center">
                  <a href="/site-admin/page-edit.php?id=<?= (int)$p['id'] ?>" class="sa-btn sa-btn-secondary sa-btn-sm">Edit</a>
                  <?php if ($p['status'] === 'published'): ?>
                    <a href="/<?= h($p['slug']) ?>" target="_blank" class="sa-btn sa-btn-secondary sa-btn-sm">View</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
