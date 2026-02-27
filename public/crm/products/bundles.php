<?php
/**
 * Service Bundles Manager
 * Create and manage bundled service packages (e.g., Fertilization Program)
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('products.view');

$pageTitle = 'Service Bundles';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
              <h1 class="h3 mb-1">Service Bundles</h1>
              <p class="text-muted mb-0">Create packages that combine multiple services at a bundle price — e.g., a Fertilization Program or Spring Cleanup Package.</p>
            </div>
            <div class="d-flex gap-2">
              <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Products Hub
              </a>
              <button class="btn btn-primary btn-sm" onclick="openBundleModal()">
                <i data-feather="plus" style="width:14px;height:14px;"></i> New Bundle
              </button>
            </div>
          </div>

          <!-- Bundle Grid -->
          <div id="bundleGrid">
            <div class="mw-bundles-loading text-center py-5">
              <div class="spinner-border text-success" role="status"></div>
              <p class="text-muted mt-2">Loading bundles…</p>
            </div>
          </div>

          <!-- Empty State (hidden by default) -->
          <div id="bundleEmptyState" class="mw-bundles-empty" style="display:none;">
            <div class="text-center py-5">
              <i data-feather="package" class="mw-bundles-empty-icon"></i>
              <h4>No service bundles yet</h4>
              <p class="text-muted">Bundle multiple services together and offer them at a combined price.<br>Example: Spring Fertilization Program = Pre-emergent + Fertilizer + Lime.</p>
              <button class="btn btn-primary" onclick="openBundleModal()">
                <i data-feather="plus" style="width:14px;height:14px;"></i> Create Your First Bundle
              </button>
            </div>
          </div>


<!-- ══════════════════════════════════════════════════════════════════════
     CREATE / EDIT BUNDLE MODAL
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="bundleModal" tabindex="-1" role="dialog" aria-labelledby="bundleModalLabel">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="bundleModalLabel">New Service Bundle</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="bundleId" value="">

        <div class="row">

          <!-- ── Left column: Bundle details ────────────────── -->
          <div class="col-md-5">

            <div class="card mw-bundle-info-card">
              <div class="card-header"><strong>Bundle Details</strong></div>
              <div class="card-body">

                <div class="form-group">
                  <label>Bundle Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="bundleName"
                         placeholder="e.g., Spring Fertilization Program">
                </div>

                <div class="form-group">
                  <label>Description</label>
                  <textarea class="form-control" id="bundleDescription" rows="3"
                            placeholder="What does this bundle include? Why should customers choose it?"></textarea>
                </div>

                <div class="form-group">
                  <label>Bundle Tier</label>
                  <select class="form-control" id="bundleTier">
                    <option value="custom">Custom Package</option>
                    <option value="good">Good — Basic Package</option>
                    <option value="better">Better — Enhanced Package</option>
                    <option value="best">Best — Complete Package</option>
                  </select>
                  <small class="form-text text-muted">Use tiers to create Good / Better / Best pricing options for the same service category.</small>
                </div>

                <div class="form-row">
                  <div class="col">
                    <div class="form-group">
                      <label>Discount Type</label>
                      <select class="form-control" id="discountType" onchange="recalcBundle()">
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed ($)</option>
                      </select>
                    </div>
                  </div>
                  <div class="col">
                    <div class="form-group">
                      <label id="discountValueLabel">Discount (%)</label>
                      <div class="input-group">
                        <input type="number" class="form-control" id="discountValue"
                               value="0" min="0" step="0.01" oninput="recalcBundle()">
                        <div class="input-group-append">
                          <span class="input-group-text" id="discountSuffix">%</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label class="mw-product-checkbox-label">
                    <input type="checkbox" id="bundleActive" checked>
                    <strong>Active</strong> — visible when adding to quotes
                  </label>
                </div>

              </div>
            </div>

            <!-- Pricing Summary -->
            <div class="card mw-bundle-pricing-card mt-3">
              <div class="card-header"><strong>Pricing Summary</strong></div>
              <div class="card-body p-0">
                <table class="table table-sm mb-0">
                  <tbody>
                    <tr>
                      <td class="text-muted">Combined List Price</td>
                      <td class="text-right font-weight-bold" id="summaryListPrice">$0.00</td>
                    </tr>
                    <tr>
                      <td class="text-muted" id="summaryDiscountLabel">Bundle Discount</td>
                      <td class="text-right text-danger" id="summaryDiscount">— $0.00</td>
                    </tr>
                    <tr class="mw-bundle-price-row">
                      <td><strong>Bundle Price</strong></td>
                      <td class="text-right"><strong id="summaryBundlePrice">$0.00</strong></td>
                    </tr>
                    <tr>
                      <td class="text-muted small">Savings per visit</td>
                      <td class="text-right text-success small" id="summarySavings">$0.00</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

          </div>

          <!-- ── Right column: Product selector ──────────────── -->
          <div class="col-md-7">

            <!-- Search & add products -->
            <div class="card mw-bundle-products-card">
              <div class="card-header d-flex align-items-center justify-content-between">
                <strong>Services / Products in This Bundle</strong>
                <span class="badge badge-secondary" id="itemCountBadge">0 items</span>
              </div>
              <div class="card-body">

                <div class="mw-bundle-search-row mb-3">
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text"><i data-feather="search" style="width:14px;height:14px;"></i></span>
                    </div>
                    <input type="text" class="form-control" id="productSearch"
                           placeholder="Search products by name or SKU…"
                           oninput="searchProducts(this.value)">
                  </div>
                  <div id="productSearchResults" class="mw-bundle-search-results" style="display:none;"></div>
                </div>

                <!-- Added items table -->
                <div id="bundleItemsContainer">
                  <div id="bundleItemsEmpty" class="text-center text-muted py-4" style="font-size:0.9em;">
                    <i data-feather="plus-circle" style="width:24px;height:24px;display:block;margin:0 auto 8px;opacity:.4;"></i>
                    Search above and click a product to add it to this bundle.
                  </div>
                  <table class="table table-sm mw-bundle-items-table" id="bundleItemsTable" style="display:none;">
                    <thead>
                      <tr>
                        <th>Service / Product</th>
                        <th style="width:100px;">Qty</th>
                        <th style="width:110px;">Price Override</th>
                        <th style="width:90px;">Line Total</th>
                        <th style="width:40px;"></th>
                      </tr>
                    </thead>
                    <tbody id="bundleItemsTbody"></tbody>
                  </table>
                </div>

              </div>
            </div>

          </div>
        </div><!-- /row -->
      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveBundle()">
          <i data-feather="save" style="width:14px;height:14px;"></i> Save Bundle
        </button>
      </div>

    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════
     DELETE CONFIRM MODAL
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete Bundle?</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <p>Delete <strong id="deleteBundleName"></strong>? This cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>
</div>


<script>
// ── State ────────────────────────────────────────────────────────────────────
const API_URL = '/crm/products/api-products.php';
let allProducts  = [];   // full product catalog (loaded once)
let bundleItems  = [];   // items currently in the open modal: [{product_id, product_name, base_price, quantity_multiplier, override_price, sort_order}]
let searchTimer  = null;
let deleteBundleId = null;

// ── Boot ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadBundles();
  loadAllProducts();
  feather.replace();

  // Discount type change → update suffix label
  document.getElementById('discountType').addEventListener('change', function () {
    const isPct = this.value === 'percentage';
    document.getElementById('discountSuffix').textContent     = isPct ? '%' : '$';
    document.getElementById('discountValueLabel').textContent = isPct ? 'Discount (%)' : 'Discount ($)';
    recalcBundle();
  });

  // Close search dropdown on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#productSearch') && !e.target.closest('#productSearchResults')) {
      document.getElementById('productSearchResults').style.display = 'none';
    }
  });

  // Delete confirmation
  document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (deleteBundleId) confirmDelete(deleteBundleId);
  });
});

// ── Load product catalog ─────────────────────────────────────────────────────
function loadAllProducts() {
  fetch(API_URL + '?action=list-products')
    .then(r => r.json())
    .then(data => {
      if (data.success) allProducts = data.products || [];
    });
}

// ── Load & render bundle list ────────────────────────────────────────────────
function loadBundles() {
  document.getElementById('bundleGrid').innerHTML =
    '<div class="mw-bundles-loading text-center py-5">' +
    '<div class="spinner-border text-success" role="status"></div>' +
    '<p class="text-muted mt-2">Loading bundles…</p></div>';
  document.getElementById('bundleEmptyState').style.display = 'none';

  fetch(API_URL + '?action=get-bundles')
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message || 'Failed to load bundles');
      renderBundles(data.bundles || []);
    })
    .catch(err => {
      document.getElementById('bundleGrid').innerHTML =
        '<div class="alert alert-danger">' + escHtml(err.message) + '</div>';
    });
}

function renderBundles(bundles) {
  const grid = document.getElementById('bundleGrid');
  const empty = document.getElementById('bundleEmptyState');

  if (!bundles.length) {
    grid.innerHTML = '';
    empty.style.display = '';
    feather.replace();
    return;
  }

  empty.style.display = 'none';

  const tierLabels = { good: 'Good', better: 'Better', best: 'Best', custom: 'Custom' };
  const tierColors = { good: 'secondary', better: 'info', best: 'warning', custom: 'primary' };

  let html = '<div class="row">';

  bundles.forEach(b => {
    const items = b.items || [];
    const listTotal = items.reduce((sum, i) => {
      const price = parseFloat(i.override_price) || parseFloat(i.base_price) || 0;
      return sum + price * (parseFloat(i.quantity_multiplier) || 1);
    }, 0);

    let bundlePrice = listTotal;
    if (b.discount_type === 'percentage') {
      bundlePrice = listTotal * (1 - parseFloat(b.discount_value) / 100);
    } else {
      bundlePrice = listTotal - parseFloat(b.discount_value);
    }
    bundlePrice = Math.max(0, bundlePrice);
    const savings = listTotal - bundlePrice;

    const tierLabel = tierLabels[b.tier] || 'Custom';
    const tierColor = tierColors[b.tier] || 'primary';

    html += `
      <div class="col-lg-4 col-md-6 mb-4">
        <div class="card mw-bundle-card h-100 ${!b.is_active ? 'mw-bundle-card--inactive' : ''}">
          <div class="card-header d-flex align-items-start justify-content-between">
            <div>
              <h5 class="card-title mb-1">${escHtml(b.bundle_name)}</h5>
              <span class="badge badge-${tierColor}">${escHtml(tierLabel)}</span>
              ${!b.is_active ? '<span class="badge badge-secondary ml-1">Inactive</span>' : ''}
            </div>
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-secondary btn-sm" onclick="editBundle(${b.id})" title="Edit">
                <i data-feather="edit-2" style="width:12px;height:12px;"></i>
              </button>
              <button class="btn btn-outline-danger btn-sm" onclick="promptDelete(${b.id}, ${JSON.stringify(escHtml(b.bundle_name))})" title="Delete">
                <i data-feather="trash-2" style="width:12px;height:12px;"></i>
              </button>
            </div>
          </div>

          <div class="card-body">
            ${b.description ? `<p class="text-muted small mb-3">${escHtml(b.description)}</p>` : ''}

            ${items.length ? `
              <div class="mw-bundle-item-list mb-3">
                ${items.map(i => `
                  <div class="mw-bundle-item-row">
                    <span class="mw-bundle-item-name">${escHtml(i.product_name)}</span>
                    <span class="mw-bundle-item-price text-muted small">
                      ${parseFloat(i.quantity_multiplier) !== 1 ? `×${i.quantity_multiplier} ` : ''}
                      ${fmtCurrency(parseFloat(i.override_price) || parseFloat(i.base_price) || 0)}
                    </span>
                  </div>
                `).join('')}
              </div>
            ` : '<p class="text-muted small mb-3">No items added yet.</p>'}
          </div>

          <div class="card-footer mw-bundle-card-footer">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="text-muted small">List: ${fmtCurrency(listTotal)}</div>
                ${savings > 0 ? `<div class="text-success small">Save ${fmtCurrency(savings)}</div>` : ''}
              </div>
              <div class="text-right">
                <div class="mw-bundle-price">${fmtCurrency(bundlePrice)}</div>
                ${b.discount_value > 0 ? `<div class="mw-bundle-discount-badge">${b.discount_type === 'percentage' ? b.discount_value + '% off' : fmtCurrency(b.discount_value) + ' off'}</div>` : ''}
              </div>
            </div>
          </div>
        </div>
      </div>`;
  });

  html += '</div>';
  grid.innerHTML = html;
  feather.replace();
}


// ── Open modal (new bundle) ───────────────────────────────────────────────────
function openBundleModal(existingBundle) {
  bundleItems = [];

  document.getElementById('bundleModalLabel').textContent = existingBundle ? 'Edit Service Bundle' : 'New Service Bundle';
  document.getElementById('bundleId').value          = existingBundle ? existingBundle.id   : '';
  document.getElementById('bundleName').value        = existingBundle ? existingBundle.bundle_name   : '';
  document.getElementById('bundleDescription').value = existingBundle ? (existingBundle.description || '') : '';
  document.getElementById('bundleTier').value        = existingBundle ? existingBundle.tier  : 'custom';
  document.getElementById('discountType').value      = existingBundle ? existingBundle.discount_type  : 'percentage';
  document.getElementById('discountValue').value     = existingBundle ? existingBundle.discount_value : '0';
  document.getElementById('bundleActive').checked    = existingBundle ? !!parseInt(existingBundle.is_active) : true;
  document.getElementById('productSearch').value     = '';

  // Trigger suffix label update
  const isPct = document.getElementById('discountType').value === 'percentage';
  document.getElementById('discountSuffix').textContent     = isPct ? '%' : '$';
  document.getElementById('discountValueLabel').textContent = isPct ? 'Discount (%)' : 'Discount ($)';

  // Populate existing items
  if (existingBundle && existingBundle.items && existingBundle.items.length) {
    existingBundle.items.forEach(i => {
      bundleItems.push({
        product_id:          i.product_id,
        product_name:        i.product_name,
        base_price:          parseFloat(i.base_price) || 0,
        quantity_multiplier: parseFloat(i.quantity_multiplier) || 1,
        override_price:      i.override_price !== null ? parseFloat(i.override_price) : null,
        sort_order:          i.sort_order || 0
      });
    });
  }

  renderBundleItems();
  recalcBundle();
  document.getElementById('productSearchResults').style.display = 'none';
  $('#bundleModal').modal('show');
  setTimeout(() => feather.replace(), 50);
}

// ── Edit bundle ───────────────────────────────────────────────────────────────
function editBundle(bundleId) {
  fetch(API_URL + '?action=get-bundles')
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message);
      const bundle = (data.bundles || []).find(b => b.id == bundleId);
      if (!bundle) throw new Error('Bundle not found');
      openBundleModal(bundle);
    })
    .catch(err => alert('Error: ' + err.message));
}


// ── Product search ────────────────────────────────────────────────────────────
function searchProducts(query) {
  clearTimeout(searchTimer);
  const resultsEl = document.getElementById('productSearchResults');

  if (!query || query.trim().length < 1) {
    resultsEl.style.display = 'none';
    return;
  }

  searchTimer = setTimeout(() => {
    const q = query.toLowerCase();
    const alreadyAdded = new Set(bundleItems.map(i => parseInt(i.product_id)));

    const matches = allProducts.filter(p =>
      !alreadyAdded.has(parseInt(p.id)) &&
      (p.name.toLowerCase().includes(q) ||
       (p.sku && p.sku.toLowerCase().includes(q)) ||
       (p.category_name && p.category_name.toLowerCase().includes(q)))
    ).slice(0, 12);

    if (!matches.length) {
      resultsEl.innerHTML = '<div class="mw-bundle-no-results">No matching products found</div>';
    } else {
      resultsEl.innerHTML = matches.map(p => `
        <div class="mw-bundle-result-row" onclick="addProductToBundle(${p.id})">
          <div class="mw-bundle-result-name">${escHtml(p.name)}</div>
          <div class="mw-bundle-result-meta">
            ${p.category_name ? `<span class="text-muted">${escHtml(p.category_name)}</span>` : ''}
            <span class="font-weight-bold">${fmtCurrency(p.base_price || 0)}</span>
          </div>
        </div>
      `).join('');
    }

    resultsEl.style.display = '';
  }, 150);
}

// ── Add product to bundle ─────────────────────────────────────────────────────
function addProductToBundle(productId) {
  const p = allProducts.find(x => x.id == productId);
  if (!p) return;

  // Prevent duplicates
  if (bundleItems.some(i => i.product_id == productId)) return;

  bundleItems.push({
    product_id:          p.id,
    product_name:        p.name,
    base_price:          parseFloat(p.base_price) || 0,
    quantity_multiplier: 1,
    override_price:      null,
    sort_order:          bundleItems.length
  });

  document.getElementById('productSearch').value = '';
  document.getElementById('productSearchResults').style.display = 'none';

  renderBundleItems();
  recalcBundle();
}

// ── Remove item ───────────────────────────────────────────────────────────────
function removeBundleItem(idx) {
  bundleItems.splice(idx, 1);
  bundleItems.forEach((item, i) => item.sort_order = i);
  renderBundleItems();
  recalcBundle();
}

// ── Update qty or override price ──────────────────────────────────────────────
function updateBundleItem(idx, field, value) {
  if (field === 'quantity_multiplier') {
    bundleItems[idx].quantity_multiplier = parseFloat(value) || 1;
  } else if (field === 'override_price') {
    const trimmed = value.trim();
    bundleItems[idx].override_price = trimmed === '' ? null : (parseFloat(trimmed) || null);
  }
  recalcBundle();
  // update line total cell without full re-render
  const row = document.querySelector(`#bundleItemsTbody tr[data-idx="${idx}"]`);
  if (row) {
    const item = bundleItems[idx];
    const price = item.override_price !== null ? item.override_price : item.base_price;
    row.querySelector('.mw-bundle-line-total').textContent = fmtCurrency(price * item.quantity_multiplier);
  }
}

// ── Render items table ────────────────────────────────────────────────────────
function renderBundleItems() {
  const tbody   = document.getElementById('bundleItemsTbody');
  const table   = document.getElementById('bundleItemsTable');
  const emptyEl = document.getElementById('bundleItemsEmpty');
  const badge   = document.getElementById('itemCountBadge');

  badge.textContent = bundleItems.length + (bundleItems.length === 1 ? ' item' : ' items');

  if (!bundleItems.length) {
    table.style.display   = 'none';
    emptyEl.style.display = '';
    feather.replace();
    return;
  }

  emptyEl.style.display = 'none';
  table.style.display   = '';

  tbody.innerHTML = bundleItems.map((item, idx) => {
    const price     = item.override_price !== null ? item.override_price : item.base_price;
    const lineTotal = price * item.quantity_multiplier;
    return `
      <tr data-idx="${idx}">
        <td>
          <div class="font-weight-medium">${escHtml(item.product_name)}</div>
          ${item.override_price !== null
            ? `<small class="text-warning">Price overridden (list: ${fmtCurrency(item.base_price)})</small>`
            : `<small class="text-muted">List: ${fmtCurrency(item.base_price)}</small>`}
        </td>
        <td>
          <input type="number" class="form-control form-control-sm"
                 value="${item.quantity_multiplier}" min="0.01" step="0.01"
                 onchange="updateBundleItem(${idx}, 'quantity_multiplier', this.value)"
                 style="width:80px;">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm"
                 value="${item.override_price !== null ? item.override_price : ''}"
                 placeholder="${fmtCurrency(item.base_price)}"
                 min="0" step="0.01"
                 onchange="updateBundleItem(${idx}, 'override_price', this.value)"
                 style="width:100px;">
        </td>
        <td class="mw-bundle-line-total font-weight-medium">${fmtCurrency(lineTotal)}</td>
        <td>
          <button class="btn btn-sm btn-outline-danger" onclick="removeBundleItem(${idx})" title="Remove">
            <i data-feather="x" style="width:12px;height:12px;"></i>
          </button>
        </td>
      </tr>`;
  }).join('');

  feather.replace();
}

// ── Recalculate pricing summary ────────────────────────────────────────────────
function recalcBundle() {
  const listTotal = bundleItems.reduce((sum, item) => {
    const price = item.override_price !== null ? item.override_price : item.base_price;
    return sum + price * item.quantity_multiplier;
  }, 0);

  const discountType  = document.getElementById('discountType').value;
  const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;

  let discountAmount = 0;
  let bundlePrice    = listTotal;
  if (discountType === 'percentage') {
    discountAmount = listTotal * (discountValue / 100);
    bundlePrice    = listTotal - discountAmount;
  } else {
    discountAmount = discountValue;
    bundlePrice    = listTotal - discountAmount;
  }
  bundlePrice = Math.max(0, bundlePrice);

  const savings = listTotal - bundlePrice;

  document.getElementById('summaryListPrice').textContent  = fmtCurrency(listTotal);
  document.getElementById('summaryDiscount').textContent   = '— ' + fmtCurrency(discountAmount);
  document.getElementById('summaryBundlePrice').textContent = fmtCurrency(bundlePrice);
  document.getElementById('summarySavings').textContent    = fmtCurrency(savings) + ' saved';

  if (discountType === 'percentage') {
    document.getElementById('summaryDiscountLabel').textContent = 'Bundle Discount (' + discountValue + '%)';
  } else {
    document.getElementById('summaryDiscountLabel').textContent = 'Bundle Discount (fixed)';
  }
}

// ── Save bundle ────────────────────────────────────────────────────────────────
function saveBundle() {
  const name = document.getElementById('bundleName').value.trim();
  if (!name) {
    document.getElementById('bundleName').focus();
    document.getElementById('bundleName').classList.add('is-invalid');
    return;
  }
  document.getElementById('bundleName').classList.remove('is-invalid');

  const payload = {
    id:             document.getElementById('bundleId').value || null,
    bundle_name:    name,
    description:    document.getElementById('bundleDescription').value.trim(),
    tier:           document.getElementById('bundleTier').value,
    discount_type:  document.getElementById('discountType').value,
    discount_value: parseFloat(document.getElementById('discountValue').value) || 0,
    is_active:      document.getElementById('bundleActive').checked ? 1 : 0,
    items:          bundleItems.map((item, idx) => ({
      product_id:          item.product_id,
      quantity_multiplier: item.quantity_multiplier,
      override_price:      item.override_price,
      sort_order:          idx
    }))
  };

  const btn = document.querySelector('#bundleModal .btn-primary');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';

  fetch(API_URL + '?action=save-bundle', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(payload)
  })
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message || 'Save failed');
      $('#bundleModal').modal('hide');
      loadBundles();
    })
    .catch(err => {
      alert('Error saving bundle: ' + err.message);
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i data-feather="save" style="width:14px;height:14px;"></i> Save Bundle';
      feather.replace();
    });
}

// ── Delete bundle ─────────────────────────────────────────────────────────────
function promptDelete(bundleId, bundleName) {
  deleteBundleId = bundleId;
  document.getElementById('deleteBundleName').textContent = bundleName;
  $('#deleteModal').modal('show');
}

function confirmDelete(bundleId) {
  fetch(API_URL + '?action=delete-bundle', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ id: bundleId })
  })
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message || 'Delete failed');
      $('#deleteModal').modal('hide');
      deleteBundleId = null;
      loadBundles();
    })
    .catch(err => alert('Error: ' + err.message));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtCurrency(n) {
  return '$' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function escHtml(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
