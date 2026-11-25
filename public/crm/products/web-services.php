<?php
/**
 * Website services — choose which CRM service packages appear on the public website,
 * the page name each feeds, a short headline, bullet points and whether the price shows.
 * Writes go through WebServiceCatalog::save() (validation lives there, not here).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('products.edit');
require_once APP_ROOT . '/Modules/Jobs/Services/WebServiceCatalog.php';

$db = getDB();
$errors = [];
$migrationMissing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Your session expired. Reload the page and try again.');
    }
    try {
        $errors = WebServiceCatalog::save($db, (int)($_POST['package_id'] ?? 0), $_POST);
    } catch (PDOException $e) {
        $errors = ['The website fields are not available yet. Run migration 1458, then try again.'];
    }
    if (!$errors) {
        header('Location: web-services.php?saved=1');
        exit;
    }
}

$packages = [];
try {
    $packages = $db->query("
        SELECT id, package_name, category, base_price, unit_type, is_active,
               web_visible, web_slug, web_headline, web_bullets, show_web_price
          FROM service_packages
         ORDER BY category, package_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $migrationMissing = true; // migration 1458 not run yet
}

$pageTitle = 'Website Services';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mb-3">
            <h1 class="h3 mb-1">Website Services</h1>
            <p class="text-muted mb-0">Packages checked here appear on the public service pages at mowology.ca. The page name must match the page's address (for example <code>spring-cleanup</code> for <code>/services/spring-cleanup</code>).</p>
          </div>

          <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success">Saved.</div>
          <?php endif; ?>
          <?php if ($errors): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
          <?php endif; ?>
          <?php if ($migrationMissing): ?>
            <div class="alert alert-warning">The website fields are not in the database yet. Migration 1458 must run before this page can save.</div>
          <?php endif; ?>

          <?php foreach ($packages as $pkg):
            $bullets = (string)($pkg['web_bullets'] ?? '');
            $preview = WebServiceCatalog::priceLabel([
                'show_web_price' => (bool)$pkg['show_web_price'],
                'base_price'     => (float)$pkg['base_price'],
                'unit_type'      => (string)$pkg['unit_type'],
            ]);
          ?>
          <form method="post" class="card mb-3 p-3" action="web-services.php">
            <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
            <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
            <div class="d-flex justify-content-between flex-wrap mb-2">
              <strong><?= h($pkg['package_name']) ?></strong>
              <span class="text-muted small">
                <?= h($pkg['category'] ?? '') ?> · base $<?= h(number_format((float)$pkg['base_price'], 2)) ?> <?= h($pkg['unit_type']) ?>
                <?= $pkg['is_active'] ? '' : ' · inactive' ?>
              </span>
            </div>
            <div class="row">
              <div class="col-md-3 mb-2">
                <label class="form-check-label d-block"><input type="checkbox" name="web_visible" value="1" class="form-check-input" <?= $pkg['web_visible'] ? 'checked' : '' ?>> Show on website</label>
                <label class="form-check-label d-block"><input type="checkbox" name="show_web_price" value="1" class="form-check-input" <?= $pkg['show_web_price'] ? 'checked' : '' ?>> Show price</label>
              </div>
              <div class="col-md-4 mb-2">
                <label class="small mb-1">Page name</label>
                <input type="text" name="web_slug" class="form-control form-control-sm" maxlength="100" value="<?= h($pkg['web_slug'] ?? '') ?>" placeholder="spring-cleanup">
              </div>
              <div class="col-md-5 mb-2">
                <label class="small mb-1">Headline (optional, replaces the page's short intro line)</label>
                <input type="text" name="web_headline" class="form-control form-control-sm" maxlength="255" value="<?= h($pkg['web_headline'] ?? '') ?>">
              </div>
              <div class="col-md-6 mb-2">
                <label class="small mb-1">Bullet points (one per line, max 12)</label>
                <textarea name="web_bullets" rows="3" class="form-control form-control-sm"><?= h($bullets) ?></textarea>
              </div>
              <div class="col-md-6 mb-2">
                <label class="small mb-1">Website shows</label>
                <div class="small text-muted"><?= $preview !== '' ? h($preview) : 'No price shown' ?></div>
              </div>
            </div>
            <div><button type="submit" class="btn btn-sm btn-primary">Save</button></div>
          </form>
          <?php endforeach; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
