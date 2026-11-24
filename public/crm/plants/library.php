<?php
/**
 * Plant library — the care cards every property's plants are matched against (PlantCareService,
 * migration 1368). Standard Vancouver landscape plants first, then anything crews identify.
 * Cards are drafted by Claude from trusted gardening sources (links shown) and are only used as
 * care once someone here approves them. Rendering is plant-library.js over /crm/api/plants.php.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$pageTitle = 'Plant library';
$activePage = 'plants';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
            <div>
              <h1 class="h3 mb-1">Plant library</h1>
              <p class="text-muted mb-0">Care for the plants on your properties: the jobs, the months, and what to watch for. Approve each card once; every property with that plant uses it.</p>
            </div>
          </div>

          <div id="mw-plant-library" data-csrf="<?= htmlspecialchars(generateCSRFToken()) ?>">
            <div class="card"><div class="card-body text-muted">Loading the library…</div></div>
          </div>

          <script src="/crm/js/plant-library.js?v=<?= @filemtime(dirname(__DIR__) . '/js/plant-library.js') ?: '1' ?>" defer></script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
