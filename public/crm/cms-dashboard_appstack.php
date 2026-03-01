<?php
/**
 * CMS Business Intelligence Dashboard (P1-G)
 *
 * Aggregates page health, SEO scores, block usage, media stats,
 * redirect analytics, and recent activity into one command centre.
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cms-functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle  = 'CMS Dashboard';
$activePage = 'cms';

$db = getDB();

// ============================================================================
// DATA GATHERING
// ============================================================================

// --- Page stats ---
$pageStats = $db->query("
    SELECT
        COUNT(*)                                          AS total,
        SUM(status = 'published')                         AS published,
        SUM(status = 'draft')                             AS draft,
        SUM(status = 'archived')                          AS archived,
        ROUND(AVG(COALESCE(seo_score, 0)), 1)             AS avg_seo,
        SUM(noindex = 1)                                  AS noindexed,
        SUM(view_count)                                   AS total_views
    FROM cms_pages
")->fetch(PDO::FETCH_ASSOC) ?: [];

// Scheduled (not yet published, future publish_at)
$scheduledCount = (int)$db->query("
    SELECT COUNT(*) FROM cms_pages
    WHERE status = 'published' AND publish_at IS NOT NULL AND publish_at > NOW()
")->fetchColumn();

// --- SEO score distribution ---
$seoDistribution = $db->query("
    SELECT
        SUM(COALESCE(seo_score, 0) BETWEEN 0  AND 39)  AS poor,
        SUM(COALESCE(seo_score, 0) BETWEEN 40 AND 69)  AS fair,
        SUM(COALESCE(seo_score, 0) BETWEEN 70 AND 89)  AS good,
        SUM(COALESCE(seo_score, 0) BETWEEN 90 AND 100) AS excellent
    FROM cms_pages
    WHERE status != 'archived'
")->fetch(PDO::FETCH_ASSOC) ?: [];

// --- Top 8 pages by view count ---
$topPages = $db->query("
    SELECT id, title, slug, page_type, view_count, status, COALESCE(seo_score, 0) AS seo_score
    FROM cms_pages
    WHERE status = 'published'
    ORDER BY view_count DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// --- Pages needing attention (low SEO score, published) ---
$lowScorePages = $db->query("
    SELECT id, title, slug, COALESCE(seo_score, 0) AS seo_score, page_type
    FROM cms_pages
    WHERE status = 'published' AND COALESCE(seo_score, 0) < 60
    ORDER BY seo_score ASC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// --- Block type usage frequency ---
$blockUsage = $db->query("
    SELECT b.block_type, bt.label, COUNT(*) AS cnt
    FROM cms_blocks b
    LEFT JOIN cms_block_types bt ON bt.block_type = b.block_type
    GROUP BY b.block_type, bt.label
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// --- Media stats ---
$mediaStats = $db->query("
    SELECT
        COUNT(*)                               AS total,
        SUM(file_type = 'image')               AS images,
        SUM(file_type = 'video')               AS videos,
        SUM(file_type = 'document')            AS documents,
        SUM(is_favorite = 1)                   AS favorites,
        SUM(usage_count = 0)                   AS unused,
        ROUND(SUM(file_size) / 1048576, 1)     AS total_mb
    FROM media_assets
")->fetch(PDO::FETCH_ASSOC) ?: [];

// --- Redirect stats (table may not exist yet) ---
$redirectStats = ['total' => 0, 'hits' => 0];
try {
    $redirectStats = $db->query("
        SELECT COUNT(*) AS total, SUM(hit_count) AS hits
        FROM cms_page_redirects
        WHERE is_active = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: $redirectStats;
} catch (\Throwable $e) {}

// --- Recent CMS activity ---
$recentActivity = [];
try {
    $logCols = array_column(
        $db->query("SHOW COLUMNS FROM activity_log")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    $hasSchemaB = in_array('action_type', $logCols);
    $actionCol  = $hasSchemaB ? 'action_type' : 'action';
    $textCol    = $hasSchemaB ? 'notes' : (in_array('details', $logCols) ? 'details' : (in_array('description', $logCols) ? 'description' : 'action'));

    $recentActivity = $db->query("
        SELECT al.{$actionCol} AS action, al.{$textCol} AS summary,
               al.created_at, u.full_name
        FROM activity_log al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.{$actionCol} LIKE 'page_%' OR al.{$actionCol} LIKE 'block_%'
        ORDER BY al.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

// ============================================================================
// HELPERS
// ============================================================================

function cmsDash_scoreColor(int $score): string
{
    if ($score >= 90) return '--mw-lime';
    if ($score >= 70) return '--mw-green';
    if ($score >= 40) return '--mw-orange';
    return '#dc3545';
}

function cmsDash_pageBadge(string $type): string
{
    $labels = [
        'home'            => ['label' => 'Home',    'class' => 'badge-primary'],
        'service_landing' => ['label' => 'Service',  'class' => 'badge-success'],
        'services'        => ['label' => 'Services', 'class' => 'badge-success'],
        'portfolio'       => ['label' => 'Portfolio','class' => 'badge-info'],
        'about'           => ['label' => 'About',    'class' => 'badge-secondary'],
        'contact'         => ['label' => 'Contact',  'class' => 'badge-secondary'],
        'landing'         => ['label' => 'Landing',  'class' => 'badge-warning'],
        'custom'          => ['label' => 'Custom',   'class' => 'badge-light'],
    ];
    $b = $labels[$type] ?? ['label' => ucfirst($type), 'class' => 'badge-secondary'];
    return '<span class="badge ' . $b['class'] . '">' . h($b['label']) . '</span>';
}

function cmsDash_actionIcon(string $action): string
{
    $icons = [
        'page_created'   => 'plus-circle',
        'page_updated'   => 'edit-2',
        'page_published' => 'globe',
        'page_archived'  => 'archive',
        'page_deleted'   => 'trash-2',
        'block_created'  => 'layers',
        'block_updated'  => 'sliders',
        'block_deleted'  => 'x-circle',
    ];
    return $icons[$action] ?? 'activity';
}

?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- =========================================================
     CMS DASHBOARD — P1-G
     ========================================================= -->

<!-- Page Header -->
<div class="mw-page-header mb-4">
    <div class="d-flex align-items-center justify-content-between">
        <div>
            <h1 class="h3 mb-1">
                <i data-feather="bar-chart-2" class="me-2" style="color:var(--mw-green)"></i>
                CMS Dashboard
            </h1>
            <p class="text-muted mb-0">Page health, SEO scores, content analytics, and activity at a glance.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/crm/cms-pages_appstack.php" class="btn btn-outline-secondary btn-sm">
                <i data-feather="file-text" class="feather-xs me-1"></i> All Pages
            </a>
            <a href="/cms/cms-page-editor.php" class="btn mw-btn-primary btn-sm">
                <i data-feather="plus" class="feather-xs me-1"></i> New Page
            </a>
        </div>
    </div>
</div>

<!-- ============================================================
     ROW 1 — OVERVIEW STAT CARDS
     ============================================================ -->
<div class="row g-3 mb-4">
    <!-- Total pages -->
    <div class="col-6 col-md-3">
        <div class="mw-stat-card card h-100">
            <div class="card-body">
                <div class="mw-stat-label">Total Pages</div>
                <div class="mw-stat-value"><?= (int)($pageStats['total'] ?? 0) ?></div>
                <div class="mw-stat-sub">
                    <?= (int)($pageStats['archived'] ?? 0) ?> archived
                </div>
            </div>
        </div>
    </div>

    <!-- Published -->
    <div class="col-6 col-md-3">
        <div class="mw-stat-card card h-100" style="border-top-color:var(--mw-green)">
            <div class="card-body">
                <div class="mw-stat-label">Published</div>
                <div class="mw-stat-value" style="color:var(--mw-green)"><?= (int)($pageStats['published'] ?? 0) ?></div>
                <div class="mw-stat-sub">
                    <?= $scheduledCount ?> scheduled
                    &middot; <?= (int)($pageStats['draft'] ?? 0) ?> draft
                </div>
            </div>
        </div>
    </div>

    <!-- Average SEO score -->
    <div class="col-6 col-md-3">
        <?php $avgSeo = (int)round((float)($pageStats['avg_seo'] ?? 0)); ?>
        <div class="mw-stat-card card h-100" style="border-top-color:var(--cmsDash_scoreColor); border-top-color:<?= cmsDash_scoreColor($avgSeo) ?>">
            <div class="card-body">
                <div class="mw-stat-label">Avg SEO Score</div>
                <div class="mw-stat-value" style="color:<?= cmsDash_scoreColor($avgSeo) ?>"><?= $avgSeo ?>%</div>
                <div class="progress mt-2" style="height:4px">
                    <div class="progress-bar" style="width:<?= $avgSeo ?>%;background:<?= cmsDash_scoreColor($avgSeo) ?>"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total views -->
    <div class="col-6 col-md-3">
        <div class="mw-stat-card card h-100">
            <div class="card-body">
                <div class="mw-stat-label">Total Page Views</div>
                <div class="mw-stat-value"><?= number_format((int)($pageStats['total_views'] ?? 0)) ?></div>
                <div class="mw-stat-sub"><?= (int)($pageStats['noindexed'] ?? 0) ?> noindexed pages</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     ROW 2 — SEO SCORE DISTRIBUTION + REDIRECTS + MEDIA
     ============================================================ -->
<div class="row g-3 mb-4">

    <!-- SEO Score Distribution -->
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <i data-feather="trending-up" class="feather-sm me-2 text-muted"></i>
                <strong>SEO Score Distribution</strong>
            </div>
            <div class="card-body">
                <?php
                $total = max(1,
                    (int)($seoDistribution['poor'] ?? 0) +
                    (int)($seoDistribution['fair'] ?? 0) +
                    (int)($seoDistribution['good'] ?? 0) +
                    (int)($seoDistribution['excellent'] ?? 0)
                );
                $bands = [
                    ['label' => 'Excellent (90–100)', 'key' => 'excellent', 'color' => 'var(--mw-lime)'],
                    ['label' => 'Good (70–89)',        'key' => 'good',      'color' => 'var(--mw-green)'],
                    ['label' => 'Fair (40–69)',         'key' => 'fair',      'color' => 'var(--mw-orange)'],
                    ['label' => 'Poor (0–39)',          'key' => 'poor',      'color' => '#dc3545'],
                ];
                foreach ($bands as $band):
                    $count = (int)($seoDistribution[$band['key']] ?? 0);
                    $pct   = $total > 0 ? round($count / $total * 100) : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small"><?= h($band['label']) ?></span>
                        <span class="small text-muted"><?= $count ?> pages</span>
                    </div>
                    <div class="progress" style="height:10px;border-radius:5px">
                        <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $band['color'] ?>;border-radius:5px"></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="border-top pt-3 mt-1">
                    <?php if (!empty($lowScorePages)): ?>
                        <p class="small text-muted mb-1"><strong><?= count($lowScorePages) ?></strong> published page(s) scoring below 60:</p>
                        <?php foreach ($lowScorePages as $lp): ?>
                            <a href="/cms/cms-page-editor.php?id=<?= (int)$lp['id'] ?>" class="d-flex align-items-center justify-content-between text-decoration-none mb-1 small">
                                <span class="text-truncate" style="max-width:200px"><?= h($lp['title']) ?></span>
                                <span class="badge" style="background:<?= cmsDash_scoreColor((int)$lp['seo_score']) ?>"><?= (int)$lp['seo_score'] ?>%</span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="small text-success mb-0"><i data-feather="check-circle" class="feather-xs me-1"></i> All published pages score 60+</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Media + Redirect stats -->
    <div class="col-md-7">
        <div class="row g-3 h-100">

            <!-- Media Library -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <i data-feather="image" class="feather-sm me-2 text-muted"></i>
                            <strong>Media Library</strong>
                        </div>
                        <a href="/crm/cms-media_appstack.php" class="btn btn-outline-secondary btn-xs">Manage</a>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 text-center">
                            <div class="col-3">
                                <div class="fw-bold h4 mb-0"><?= (int)($mediaStats['total'] ?? 0) ?></div>
                                <div class="small text-muted">Total</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold h4 mb-0" style="color:var(--mw-green)"><?= (int)($mediaStats['images'] ?? 0) ?></div>
                                <div class="small text-muted">Images</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold h4 mb-0" style="color:var(--mw-orange)"><?= (int)($mediaStats['unused'] ?? 0) ?></div>
                                <div class="small text-muted">Unused</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold h4 mb-0"><?= number_format((float)($mediaStats['total_mb'] ?? 0), 1) ?> MB</div>
                                <div class="small text-muted">Storage</div>
                            </div>
                        </div>
                        <?php if ((int)($mediaStats['unused'] ?? 0) > 0): ?>
                            <div class="alert alert-warning alert-sm mt-3 mb-0 py-2">
                                <i data-feather="alert-triangle" class="feather-xs me-1"></i>
                                <?= (int)$mediaStats['unused'] ?> media asset(s) have never been used in any block.
                                <a href="/crm/cms-media_appstack.php?filter=unused" class="alert-link">Review &rarr;</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Redirect stats -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i data-feather="corner-up-right" class="feather-sm me-2 text-muted"></i>
                        <strong>301 Redirects</strong>
                        <span class="badge badge-secondary ms-2"><?= (int)($redirectStats['total'] ?? 0) ?> active</span>
                    </div>
                    <div class="card-body py-2">
                        <?php if ((int)($redirectStats['total'] ?? 0) === 0): ?>
                            <p class="small text-muted mb-0">No active redirects. They are auto-created when you rename a page slug.</p>
                        <?php else: ?>
                            <div class="row g-3 text-center">
                                <div class="col-6">
                                    <div class="fw-bold h5 mb-0"><?= (int)($redirectStats['total'] ?? 0) ?></div>
                                    <div class="small text-muted">Active rules</div>
                                </div>
                                <div class="col-6">
                                    <div class="fw-bold h5 mb-0"><?= number_format((int)($redirectStats['hits'] ?? 0)) ?></div>
                                    <div class="small text-muted">Total hits</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     ROW 3 — TOP PAGES + BLOCK USAGE + RECENT ACTIVITY
     ============================================================ -->
<div class="row g-3 mb-4">

    <!-- Top Pages by Views -->
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <i data-feather="eye" class="feather-sm me-2 text-muted"></i>
                <strong>Top Pages by Views</strong>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topPages)): ?>
                    <div class="p-4 text-center text-muted">
                        <i data-feather="file-text" class="feather-lg mb-2"></i>
                        <p class="mb-0">No published pages yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Page</th>
                                    <th class="text-center">Views</th>
                                    <th class="text-center">SEO</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($topPages as $tp): ?>
                                <tr>
                                    <td>
                                        <a href="/cms/cms-page-editor.php?id=<?= (int)$tp['id'] ?>" class="text-dark text-decoration-none">
                                            <?= h($tp['title']) ?>
                                        </a>
                                        <div><?= cmsDash_pageBadge($tp['page_type']) ?></div>
                                    </td>
                                    <td class="text-center fw-bold"><?= number_format((int)$tp['view_count']) ?></td>
                                    <td class="text-center">
                                        <span class="badge" style="background:<?= cmsDash_scoreColor((int)$tp['seo_score']) ?>">
                                            <?= (int)$tp['seo_score'] ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Block Type Usage -->
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <i data-feather="layers" class="feather-sm me-2 text-muted"></i>
                <strong>Block Usage</strong>
            </div>
            <div class="card-body p-0">
                <?php if (empty($blockUsage)): ?>
                    <div class="p-4 text-center text-muted">No blocks found.</div>
                <?php else: ?>
                    <?php $maxBlocks = max(1, (int)($blockUsage[0]['cnt'] ?? 1)); ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($blockUsage as $bu): ?>
                        <?php $pct = round((int)$bu['cnt'] / $maxBlocks * 100); ?>
                        <li class="list-group-item py-2 px-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small"><?= h($bu['label'] ?: $bu['block_type']) ?></span>
                                <strong class="small"><?= (int)$bu['cnt'] ?></strong>
                            </div>
                            <div class="progress" style="height:4px">
                                <div class="progress-bar" style="width:<?= $pct ?>%;background:var(--mw-green)"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <i data-feather="activity" class="feather-sm me-2 text-muted"></i>
                    <strong>Recent Activity</strong>
                </div>
                <a href="/crm/cms-activity_appstack.php" class="btn btn-outline-secondary btn-xs">All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentActivity)): ?>
                    <div class="p-4 text-center text-muted">
                        <i data-feather="clock" class="feather-lg mb-2"></i>
                        <p class="mb-0">No CMS activity logged yet.</p>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($recentActivity as $act): ?>
                        <li class="list-group-item py-2 px-3">
                            <div class="d-flex align-items-start gap-2">
                                <div class="mt-1 flex-shrink-0" style="color:var(--mw-green)">
                                    <i data-feather="<?= h(cmsDash_actionIcon($act['action'])) ?>" class="feather-xs"></i>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="small text-truncate"><?= h($act['summary'] ?? $act['action']) ?></div>
                                    <div class="text-muted" style="font-size:.7rem">
                                        <?= h($act['full_name'] ?? 'System') ?>
                                        &middot;
                                        <?= date('M j, g:ia', strtotime($act['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     ROW 4 — QUICK ACTIONS + CACHE MANAGEMENT
     ============================================================ -->
<div class="row g-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <i data-feather="zap" class="feather-sm me-2 text-muted"></i>
                <strong>Quick Actions</strong>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <a href="/cms/cms-page-editor.php" class="btn mw-btn-primary btn-sm">
                        <i data-feather="plus" class="feather-xs me-1"></i> New Page
                    </a>
                    <a href="/crm/cms/cms-page-generator-wizard.php" class="btn btn-outline-success btn-sm">
                        <i data-feather="cpu" class="feather-xs me-1"></i> Generate from Template
                    </a>
                    <a href="/cms/cms-templates_appstack.php" class="btn btn-outline-secondary btn-sm">
                        <i data-feather="layout" class="feather-xs me-1"></i> Manage Templates
                    </a>
                    <a href="/crm/cms-media_appstack.php" class="btn btn-outline-secondary btn-sm">
                        <i data-feather="image" class="feather-xs me-1"></i> Media Library
                    </a>
                    <a href="/sitemap.php" target="_blank" class="btn btn-outline-secondary btn-sm">
                        <i data-feather="map" class="feather-xs me-1"></i> View Sitemap
                    </a>
                    <button id="btn-flush-cache" class="btn btn-outline-warning btn-sm">
                        <i data-feather="refresh-cw" class="feather-xs me-1"></i> Flush HTML Cache
                    </button>
                </div>
                <div id="cache-flush-msg" class="mt-2" style="display:none"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('btn-flush-cache').addEventListener('click', function () {
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Flushing...';
    fetch('/crm/api/cms-flush-cache.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= generateCSRFToken() ?>'
    })
    .then(r => r.json())
    .then(data => {
        const msg = document.getElementById('cache-flush-msg');
        msg.style.display = 'block';
        if (data.success) {
            msg.className = 'alert alert-success alert-sm py-2 mt-2';
            msg.textContent = '✓ HTML cache flushed — ' + (data.count || 0) + ' page(s) cleared.';
        } else {
            msg.className = 'alert alert-danger alert-sm py-2 mt-2';
            msg.textContent = data.error || 'Flush failed.';
        }
        document.getElementById('btn-flush-cache').disabled = false;
        document.getElementById('btn-flush-cache').innerHTML = '<i data-feather="refresh-cw" class="feather-xs me-1"></i> Flush HTML Cache';
        if (typeof feather !== 'undefined') feather.replace();
    });
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
