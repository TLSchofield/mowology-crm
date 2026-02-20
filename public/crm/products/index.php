<?php
/**
 * Products & Services Management Hub
 * Mowology CRM - AppStack layout
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();
requirePermission('products.view');

$pageTitle = 'Products & Services';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <h1 class="h3 mb-3">Products & Services</h1>
          <p class="text-muted mb-4">Manage your catalog, pricing, and quote tools</p>

          <div class="mw-tools-grid">
              <a href="cost-factors.php" class="mw-tool-card">
                  <span class="mw-tool-icon">💰</span>
                  <h3>Cost Factors</h3>
                  <p>Manage labor rates, equipment costs, overhead percentages, and profit margins.</p>
                  <span class="mw-badge-tag">Essential Setup</span>
              </a>

              <a href="products-manager.php" class="mw-tool-card">
                  <span class="mw-tool-icon">📦</span>
                  <h3>Products Catalog</h3>
                  <p>Manage services, materials, and bundles. Includes data sheets, application rates, seasonality, and vendor cost tracking.</p>
                  <span class="mw-badge-tag">Product Management</span>
              </a>

              <a href="area-measurement.php" class="mw-tool-card">
                  <span class="mw-tool-icon">🗺️</span>
                  <h3>Area Measurement</h3>
                  <p>Use Google Maps to measure lawn areas, driveways, and walkways for accurate quotes.</p>
                  <span class="mw-badge-tag">Quote Tool</span>
              </a>

              <a href="quote-requests.php" class="mw-tool-card">
                  <span class="mw-tool-icon">📊</span>
                  <h3>Quote Requests</h3>
                  <p>View all incoming quote requests from the website. Review details and create formal quotes.</p>
                  <span class="mw-badge-tag">View Requests</span>
              </a>

              <a href="recommendations.php" class="mw-tool-card">
                  <span class="mw-tool-icon">📋</span>
                  <h3>Field Recommendations</h3>
                  <p>Review crew field observations and send personalized product recommendation emails to clients.</p>
                  <span class="mw-badge-tag">Sales Engine</span>
              </a>

              <a href="categories.php" class="mw-tool-card">
                  <span class="mw-tool-icon">📁</span>
                  <h3>Categories</h3>
                  <p>Organize products into categories for better organization and reporting.</p>
                  <span class="mw-badge-tag">Product Organization</span>
              </a>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
