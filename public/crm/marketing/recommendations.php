<?php
/**
 * Marketing - SEO Recommendations (Improved)
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required');
}

$pageTitle = 'SEO Recommendations';
$activePage = 'marketing';
$db = getDB();

// Optional filters (safe defaults)
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$type   = isset($_GET['type']) ? trim((string)$_GET['type']) : '';

$sql = "
    SELECT
        sr.id,
        sr.query_text,
        sr.search_volume as impressions,
        sr.clicks,
        sr.ctr,
        sr.avg_position,
        sr.suggested_slug,
        sr.rec_type,
        sr.priority_score,
        sr.status,
        sr.target_id,
        sr.season_id,
        sr.reason,
        sr.created_at,
        sr.updated_at,
        st.name as target_name,
        st.target_type,
        st.canonical_slug as target_slug,
        ss.label as season_label
    FROM seo_recommendations sr
    LEFT JOIN seo_targets st ON sr.target_id = st.id
    LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
    WHERE 1=1
";

$params = [];
if ($status !== '') { $sql .= " AND sr.status = ? "; $params[] = $status; }
if ($type !== '')   { $sql .= " AND sr.rec_type = ? "; $params[] = $type; }

$sql .= " ORDER BY sr.priority_score DESC, sr.created_at DESC LIMIT 10 ";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

function badgeClassForStatus(string $status): string {
    $s = strtolower(trim($status));
    return match ($s) {
        'published'   => 'bg-success',
        'draft'       => 'bg-warning',
        'monitoring'  => 'bg-info',
        'parked', 'dismissed' => 'bg-secondary',
        default       => 'bg-primary', // new/unknown
    };
}

function badgeClassForType(?string $type): string {
    $t = strtolower((string)$type);
    if (str_contains($t, 'service')) return 'bg-primary';
    if (str_contains($t, 'location')) return 'bg-dark';
    if (str_contains($t, 'blog')) return 'bg-info';
    return 'bg-light text-dark';
}

?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">SEO Recommendations</h1>
    <p class="text-muted mb-0">Top content opportunities from Google Search Console</p>
  </div>

  <form class="d-flex gap-2" method="get" action="">
    <select name="status" class="form-select form-select-sm" style="max-width: 160px;">
      <option value="">All Status</option>
      <?php foreach (['new','draft','published','monitoring','parked','dismissed'] as $opt): ?>
        <option value="<?php echo h($opt); ?>" <?php echo ($status === $opt ? 'selected' : ''); ?>>
          <?php echo ucfirst($opt); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="type" class="form-select form-select-sm" style="max-width: 190px;">
      <option value="">All Types</option>
      <?php foreach (['service_page','location_page','blog_post','meta_refresh'] as $opt): ?>
        <option value="<?php echo h($opt); ?>" <?php echo ($type === $opt ? 'selected' : ''); ?>>
          <?php echo str_replace('_',' ',ucfirst($opt)); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
  </form>
</div>

<div class="card">
  <div class="card-body">
    <?php if (empty($recommendations)): ?>
      <div class="text-center text-muted py-5">
        <p class="mb-2"><strong>No recommendations yet.</strong></p>
        <p class="mb-0">Sync Search Console in <strong>Portfolio → GSC Insights</strong>, then come back here.</p>
      </div>
    <?php else: ?>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 40px;">#</th>
              <th>Query / Topic</th>
              <th class="text-center" style="width: 90px;">Score</th>
              <th class="text-center" style="width: 90px;">Volume</th>
              <th class="text-center" style="width: 80px;">CTR</th>
              <th class="text-center" style="width: 90px;">Pos</th>
              <th style="width: 160px;">Target</th>
              <th style="width: 130px;">Type</th>
              <th style="width: 130px;">Status</th>
              <th style="width: 220px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php $counter = 1; foreach ($recommendations as $rec): ?>
              <?php
                $ctr = is_numeric($rec['ctr'] ?? null) ? (float)$rec['ctr'] : 0.0;
                $ctrPct = $ctr > 0 ? number_format($ctr * 100, 1) . '%' : '0.0%';
                $pos = isset($rec['avg_position']) && $rec['avg_position'] !== null ? number_format((float)$rec['avg_position'], 1) : '-';
                $statusVal = (string)($rec['status'] ?? 'new');
                $typeVal = (string)($rec['rec_type'] ?? '');
              ?>
              <tr>
                <td class="text-muted fw-bold"><?php echo $counter++; ?></td>

                <td>
                  <div class="fw-semibold"><?php echo h($rec['query_text']); ?></div>

                  <div class="small text-muted mt-1">
                    <?php if (!empty($rec['suggested_slug'])): ?>
                      Slug: <code><?php echo h($rec['suggested_slug']); ?></code>
                    <?php endif; ?>
                    <?php if (!empty($rec['season_label'])): ?>
                      <span class="ms-2">Season: <span class="badge bg-light text-dark"><?php echo h($rec['season_label']); ?></span></span>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($rec['reason'])): ?>
                    <div class="small mt-2">
                      <span class="text-muted">Why:</span>
                      <span><?php echo h($rec['reason']); ?></span>
                    </div>
                  <?php endif; ?>
                </td>

                <td class="text-center fw-bold text-success"><?php echo h((string)$rec['priority_score']); ?></td>
                <td class="text-center"><?php echo (int)($rec['impressions'] ?? 0); ?></td>
                <td class="text-center"><?php echo $ctrPct; ?></td>
                <td class="text-center"><?php echo $pos; ?></td>

                <td>
                  <?php if (!empty($rec['target_name'])): ?>
                    <div class="fw-semibold"><?php echo h($rec['target_name']); ?></div>
                    <div class="small text-muted">
                      <?php echo h((string)$rec['target_type']); ?>
                      <?php if (!empty($rec['target_slug'])): ?>
                        • <code><?php echo h($rec['target_slug']); ?></code>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-muted">Unassigned</span>
                  <?php endif; ?>
                </td>

                <td>
                  <span class="badge <?php echo h(badgeClassForType($typeVal)); ?>">
                    <?php echo h(str_replace('_',' ',ucfirst($typeVal))); ?>
                  </span>
                </td>

                <td>
                  <span class="badge <?php echo h(badgeClassForStatus($statusVal)); ?>">
                    <?php echo h(ucfirst($statusVal)); ?>
                  </span>
                </td>

                <td>
                  <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-primary"
                       href="seo_recommendation_view.php?id=<?php echo (int)$rec['id']; ?>">
                      Open
                    </a>

                    <a class="btn btn-sm btn-outline-success"
                       href="seo_recommendation_action.php?action=create_page&id=<?php echo (int)$rec['id']; ?>">
                      Create Page
                    </a>

                    <a class="btn btn-sm btn-outline-secondary"
                       href="seo_recommendation_action.php?action=mark_draft&id=<?php echo (int)$rec['id']; ?>">
                      Mark Draft
                    </a>

                    <a class="btn btn-sm btn-outline-danger"
                       href="seo_recommendation_action.php?action=park&id=<?php echo (int)$rec['id']; ?>"
                       onclick="return confirm('Park this recommendation?');">
                      Park
                    </a>
                  </div>

                  <div class="small text-muted mt-2">
                    Updated: <?php echo h((string)$rec['updated_at']); ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="small text-muted mt-3">
        Tip: Start with items in positions <strong>8–15</strong> (fastest wins). Then build location pages for route density.
      </div>

    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

