<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Products Catalog';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="mw-page-header">
            <div>
              <a href="index.php" class="mw-back-link">
                <i data-feather="arrow-left"></i> Back to Products Hub
              </a>
              <h1 class="h3 mb-0">Products &amp; Services Management</h1>
              <p class="text-muted mb-0">Manage your service offerings, materials, and bundled packages</p>
            </div>
          </div>

          <!-- Filter Bar -->
          <div class="card">
            <div class="card-body d-flex flex-wrap align-items-center" style="gap: 1rem;">
              <div class="mw-search-box">
                <input type="text" class="form-control mw-search-input" placeholder="Search products..." id="searchInput">
              </div>
              <select class="form-control" id="categoryFilter" style="width: auto;">
                <option value="">All Categories</option>
              </select>
              <select class="form-control" id="statusFilter" style="width: auto;">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>
              </select>
              <button class="btn btn-outline-secondary" onclick="openCategoryManager()">
                <i data-feather="layers"></i> Manage Categories
              </button>
              <button class="btn btn-primary ml-auto" onclick="openAddProductModal()">
                <i data-feather="plus"></i> Add Product/Service
              </button>
            </div>
          </div>

          <!-- Products Grid -->
          <div class="mw-product-grid" id="productsGrid">
            <!-- Products will be loaded here -->
          </div>

          <!-- Category Management Modal -->
          <div class="modal fade" id="categoryModal" tabindex="-1" role="dialog" aria-labelledby="categoryModalTitle" aria-hidden="true">
            <div class="modal-dialog" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="categoryModalTitle">Manage Categories</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <!-- Add New Category Form -->
                  <div class="mw-category-form mb-4">
                    <h6>Add New Category</h6>
                    <div class="form-group">
                      <label>Category Name</label>
                      <input type="text" class="form-control" id="newCategoryName" placeholder="e.g., Design Services">
                    </div>
                    <div class="form-group">
                      <label>Description (Optional)</label>
                      <textarea class="form-control" id="newCategoryDesc" rows="2" placeholder="Brief description..."></textarea>
                    </div>
                    <button class="btn btn-sm btn-primary" onclick="addNewCategory()">
                      <i data-feather="plus"></i> Add Category
                    </button>
                  </div>

                  <hr>

                  <!-- Existing Categories -->
                  <div class="mw-category-list">
                    <h6>Existing Categories</h6>
                    <div id="categoryListContainer" style="max-height: 400px; overflow-y: auto;">
                      <!-- Categories will be loaded here -->
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Add/Edit Product Modal -->
          <div class="modal fade" id="productModal" tabindex="-1" role="dialog" aria-labelledby="modalTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="modalTitle">Add New Product/Service</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <form id="productForm">
                    <input type="hidden" name="id" value="">

                    <!-- Basic Information -->
                    <div class="mw-product-form-section">
                      <h4>Basic Information</h4>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Product/Service Name *</label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g., Cedar Mulch Installation">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>SKU</label>
                            <input type="text" class="form-control" name="sku" placeholder="AUTO-GENERATED">
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Category *</label>
                            <select class="form-control" name="category_id" id="productCategorySelect" required>
                              <option value="">Select Category</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Unit Type *</label>
                            <select class="form-control" name="unit_type_id" required>
                              <option value="">Select Unit</option>
                              <option value="1">Cubic Yard (yd&sup3;)</option>
                              <option value="2">Square Foot (sq ft)</option>
                              <option value="3">Hour (hr)</option>
                              <option value="4">Each (ea)</option>
                              <option value="5">Linear Foot (lin ft)</option>
                              <option value="6">Per Job (job)</option>
                              <option value="7">Per Visit (visit)</option>
                              <option value="8">Per Month (month)</option>
                              <option value="9">Per Ton (ton)</option>
                              <option value="10">Per Bag (bag)</option>
                            </select>
                          </div>
                        </div>
                      </div>

                      <div class="form-group">
                        <label>Short Description</label>
                        <input type="text" class="form-control" name="description" placeholder="Brief description for quotes">
                      </div>

                      <div class="form-group">
                        <label>Detailed Description</label>
                        <textarea class="form-control" name="long_description" rows="3" placeholder="Detailed description for internal use"></textarea>
                      </div>

                      <div class="form-group">
                        <label>Product Image</label>
                        <input type="hidden" name="image_url" id="productImageUrl" value="">
                        <div id="imagePreviewArea" style="display:flex;align-items:center;gap:1rem;margin-bottom:0.75rem;">
                          <div id="imagePreview" style="width:80px;height:80px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;overflow:hidden;border:2px dashed #cbd5e1;flex-shrink:0;">
                            <i data-feather="image" style="width:32px;height:32px;color:#94a3b8;"></i>
                          </div>
                          <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openMediaBrowser()">
                              <i data-feather="grid"></i> Choose from Media Library
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="clearImageBtn" onclick="clearProductImage()" style="display:none;">
                              <i data-feather="x"></i> Clear
                            </button>
                            <div id="imagePathDisplay" class="small text-muted mt-1" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Pricing & Costing -->
                    <div class="mw-product-form-section">
                      <h4>Pricing &amp; Costing</h4>

                      <div class="form-group">
                        <label class="mw-product-checkbox-label">
                          <input type="checkbox" name="uses_cost_calculator" id="usesCostCalc">
                          <strong>Use Cost Factor Calculator</strong> (labor + equipment + overhead)
                        </label>
                        <small class="form-text text-muted">
                          When enabled, cost is calculated from labor rates, equipment, and overhead. When disabled, enter manual cost below.
                        </small>
                      </div>

                      <div id="manualCostSection">
                        <div class="row">
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Base Cost (per unit) *</label>
                              <input type="number" class="form-control" name="base_cost" step="0.01" min="0" placeholder="Your cost">
                            </div>
                          </div>
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Selling Price (per unit) *</label>
                              <input type="number" class="form-control" name="base_price" step="0.01" min="0" placeholder="Customer price">
                            </div>
                          </div>
                        </div>
                      </div>

                      <div id="calculatedCostSection" style="display: none;">
                        <div class="alert alert-info">
                          Cost will be calculated based on the factors you select below. Selling price will include markup.
                        </div>

                        <h5 class="mb-3">Select Cost Factors:</h5>

                        <div class="mw-product-factor-grid">
                          <div>
                            <strong>Labor</strong>
                            <div class="mw-product-checkbox-group">
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="1">
                                Owner/Manager ($45/hr)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="2">
                                Foreman ($35/hr)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="3">
                                Laborer ($25/hr)
                              </label>
                            </div>
                            <div class="form-group mt-2">
                              <label>Hours per unit</label>
                              <input type="number" class="form-control" step="0.25" placeholder="e.g., 1.5">
                            </div>
                          </div>

                          <div>
                            <strong>Equipment</strong>
                            <div class="mw-product-checkbox-group">
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="4">
                                Riding Mower ($15/hr)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="5">
                                Walk Mower ($8/hr)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="6">
                                Pickup Truck ($12/hr)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="7">
                                Dump Truck ($25/hr)
                              </label>
                            </div>
                          </div>

                          <div>
                            <strong>Other</strong>
                            <div class="mw-product-checkbox-group">
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="10">
                                Fuel ($3.50/job)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="cost_factor[]" value="11" checked>
                                Overhead (20%)
                              </label>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Markup Percentage</label>
                            <input type="number" class="form-control" name="markup_percentage" value="35" step="0.5" min="0">
                            <small class="form-text text-muted">
                              Applied on top of total cost (default: 35%)
                            </small>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Estimated Profit Margin</label>
                            <input type="text" class="form-control" readonly value="35%" style="background: #f8fafc;">
                            <small class="form-text text-mowology">
                              Calculated based on markup
                            </small>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Tax Settings -->
                    <div class="mw-product-form-section">
                      <h4>Tax Settings</h4>
                      <div class="row">
                        <div class="col-md-4">
                          <div class="form-group">
                            <label class="mw-product-checkbox-label">
                              <input type="checkbox" name="taxable" checked>
                              Taxable (GST applies)
                            </label>
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>GST Rate (%)</label>
                            <input type="number" class="form-control" name="gst_rate" value="5" step="0.01">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>PST Rate (%) - BC</label>
                            <input type="number" class="form-control" name="pst_rate" value="0" step="0.01">
                            <small class="form-text text-muted">
                              Set to 7 for BC PST on applicable items
                            </small>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Service Bundling -->
                    <div class="mw-product-form-section">
                      <h4>Service Bundling (GGOB - Good, Great, Optimal, Best)</h4>
                      <div class="alert alert-info">
                        Create tiered service packages that customers can choose from. Each tier builds on the previous.
                      </div>

                      <div class="form-group">
                        <label class="mw-product-checkbox-label">
                          <input type="checkbox" name="is_bundle" id="isBundle">
                          <strong>This is a service bundle</strong>
                        </label>
                      </div>

                      <div id="bundleOptions" style="display: none; margin-top: 1rem;">
                        <div class="form-group">
                          <label>Bundle Tier</label>
                          <select class="form-control" name="bundle_tier">
                            <option value="">Not part of GGOB</option>
                            <option value="good">Good - Basic Service</option>
                            <option value="great">Great - Enhanced Service</option>
                            <option value="optimal">Optimal - Premium Service</option>
                            <option value="best">Best - Complete Package</option>
                          </select>
                        </div>

                        <div class="form-group">
                          <label>Included Products/Services</label>
                          <div class="mw-product-bundle-items">
                            <div class="mw-product-checkbox-group">
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="1">
                                Weekly Lawn Mowing
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="2">
                                Edging &amp; Trimming
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="3">
                                Hedge Trimming
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="4">
                                Garden Bed Weeding
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="5">
                                Debris Removal
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="6">
                                Fertilization (quarterly)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="7">
                                Aeration (spring/fall)
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="bundle_items[]" value="8">
                                Seasonal Cleanup
                              </label>
                            </div>
                          </div>
                        </div>

                        <div class="mw-product-cost-breakdown">
                          <div class="mw-product-cost-row">
                            <span>Bundle Base Cost:</span>
                            <span id="bundleBaseCost">$0.00</span>
                          </div>
                          <div class="mw-product-cost-row">
                            <span>Bundle Discount:</span>
                            <span><input type="number" class="form-control form-control-sm d-inline-block" name="bundle_discount" value="10" step="1" style="width: 80px; text-align: right;">%</span>
                          </div>
                          <div class="mw-product-cost-row total">
                            <span>Bundle Price:</span>
                            <span id="bundlePrice">$0.00</span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Inventory (Optional) -->
                    <div class="mw-product-form-section">
                      <h4>Inventory Tracking (Optional)</h4>
                      <div class="form-group">
                        <label class="mw-product-checkbox-label">
                          <input type="checkbox" name="track_inventory" id="trackInventory">
                          Track inventory for this product
                        </label>
                      </div>

                      <div id="inventoryOptions" style="display: none;">
                        <div class="row">
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Current Stock</label>
                              <input type="number" class="form-control" name="current_stock" step="0.01" placeholder="0">
                            </div>
                          </div>
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Reorder Point</label>
                              <input type="number" class="form-control" name="reorder_point" step="0.01" placeholder="0">
                            </div>
                          </div>
                        </div>
                        <div class="form-group">
                          <label>Supplier Information</label>
                          <textarea class="form-control" name="supplier_info" rows="2" placeholder="Supplier name, contact, lead time"></textarea>
                        </div>
                      </div>
                    </div>

                    <!-- Display Settings -->
                    <div class="mw-product-form-section">
                      <h4>Display Settings</h4>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Display Order</label>
                            <input type="number" class="form-control" name="display_order" value="0">
                            <small class="form-text text-muted">
                              Lower numbers appear first
                            </small>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Status</label>
                            <div class="d-flex align-items-center mt-2" style="gap: 1rem;">
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="active" checked>
                                Active
                              </label>
                              <label class="mw-product-checkbox-label">
                                <input type="checkbox" name="featured">
                                Featured
                              </label>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                  </form>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary" onclick="saveProduct()">Save Product</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Media Browser Modal -->
          <div class="modal fade" id="mediaBrowserModal" tabindex="-1" role="dialog" aria-labelledby="mediaBrowserTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="mediaBrowserTitle">Choose Image from Media Library</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <div class="input-group">
                      <input type="text" class="form-control" id="mediaSearchInput" placeholder="Search by filename or alt text...">
                      <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchMediaLibrary()">
                          <i data-feather="search"></i> Search
                        </button>
                        <button class="btn btn-outline-secondary" type="button" onclick="loadAllMedia()">
                          Show All
                        </button>
                      </div>
                    </div>
                    <small class="text-muted" id="mediaSuggestionHint"></small>
                  </div>
                  <div id="mediaGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;max-height:450px;overflow-y:auto;padding:4px;">
                    <div class="text-center text-muted py-5">Enter a search term or click "Show All"</div>
                  </div>
                </div>
                <div class="modal-footer">
                  <small class="text-muted mr-auto"><i data-feather="star" style="width:14px;height:14px;color:#f59e0b;"></i> = Suggested match based on product name</small>
                  <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
              </div>
            </div>
          </div>

          <script>
            // State
            let allCategories = [];
            let allProducts = [];
            let currentFilters = {
              category: '',
              search: '',
              status: ''
            };

            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
              loadCategories();
              loadProducts();
              setupEventListeners();
            });

            // Setup event listeners
            function setupEventListeners() {
              document.getElementById('usesCostCalc').addEventListener('change', function() {
                document.getElementById('manualCostSection').style.display = this.checked ? 'none' : 'block';
                document.getElementById('calculatedCostSection').style.display = this.checked ? 'block' : 'none';
              });

              document.getElementById('isBundle').addEventListener('change', function() {
                document.getElementById('bundleOptions').style.display = this.checked ? 'block' : 'none';
              });

              document.getElementById('trackInventory').addEventListener('change', function() {
                document.getElementById('inventoryOptions').style.display = this.checked ? 'block' : 'none';
              });

              document.getElementById('categoryFilter').addEventListener('change', function(e) {
                currentFilters.category = e.target.value;
                filterProducts();
              });

              document.getElementById('statusFilter').addEventListener('change', function(e) {
                currentFilters.status = e.target.value;
                filterProducts();
              });

              document.getElementById('searchInput').addEventListener('input', function(e) {
                currentFilters.search = e.target.value;
                filterProducts();
              });
            }

            // Load categories from API
            function loadCategories() {
              fetch('api-products.php?action=get-categories')
                .then(r => r.json())
                .then(data => {
                  if (data.success) {
                    allCategories = data.categories;
                    populateCategoryDropdowns();
                    displayCategoryList();
                  }
                })
                .catch(err => console.error('Error loading categories:', err));
            }

            // Populate category dropdowns
            function populateCategoryDropdowns() {
              const select = document.getElementById('productCategorySelect');
              const filterSelect = document.getElementById('categoryFilter');

              select.innerHTML = '<option value="">Select Category</option>';
              filterSelect.innerHTML = '<option value="">All Categories</option>';

              allCategories.forEach(cat => {
                const opt1 = document.createElement('option');
                opt1.value = cat.id;
                opt1.textContent = cat.name;
                select.appendChild(opt1);

                const opt2 = document.createElement('option');
                opt2.value = cat.id;
                opt2.textContent = cat.name;
                filterSelect.appendChild(opt2);
              });
            }

            // Display existing categories in category manager
            function displayCategoryList() {
              const container = document.getElementById('categoryListContainer');
              container.innerHTML = allCategories.map(cat => `
                <div class="mw-category-item card mb-2">
                  <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                      <strong>${escapeHtml(cat.name)}</strong>
                      ${cat.description ? '<br><small class="text-muted">' + escapeHtml(cat.description) + '</small>' : ''}
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="deleteCategory(${cat.id})">
                      <i data-feather="trash-2"></i>
                    </button>
                  </div>
                </div>
              `).join('');
              hydrateFeatherIcons();
            }

            // Add new category
            function addNewCategory() {
              const name = document.getElementById('newCategoryName').value.trim();
              const desc = document.getElementById('newCategoryDesc').value.trim();

              if (!name) {
                alert('Please enter a category name');
                return;
              }

              fetch('api-products.php?action=add-category', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name, description: desc || null })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  document.getElementById('newCategoryName').value = '';
                  document.getElementById('newCategoryDesc').value = '';
                  loadCategories();
                  alert('Category added successfully!');
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // Delete category
            function deleteCategory(categoryId) {
              if (!confirm('Delete this category? (Products must be archived first)')) return;

              fetch('api-products.php?action=delete-category', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: categoryId })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  loadCategories();
                  alert('Category deleted successfully!');
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // Load products from API
            function loadProducts() {
              const includeArchived = currentFilters.status === 'archived' ? 1 : 0;
              const category = currentFilters.category || null;
              const search = currentFilters.search || null;

              let url = 'api-products.php?action=list-products';
              if (includeArchived) url += '&archived=1';
              if (category) url += '&category=' + encodeURIComponent(category);
              if (search) url += '&search=' + encodeURIComponent(search);

              fetch(url)
                .then(r => r.json())
                .then(data => {
                  if (data.success) {
                    allProducts = data.products;
                    displayProducts();
                  }
                })
                .catch(err => console.error('Error loading products:', err));
            }

            // Filter products based on current filters
            function filterProducts() {
              loadProducts();
            }

            // Display products grid
            function displayProducts() {
              const grid = document.getElementById('productsGrid');
              if (allProducts.length === 0) {
                grid.innerHTML = '<div class="alert alert-info">No products found.</div>';
                return;
              }

              grid.innerHTML = allProducts.map(p => `
                <div class="mw-product-card ${p.is_archived ? 'mw-product-archived' : ''}">
                  ${p.is_archived ? '<div class="mw-product-archived-badge">ARCHIVED</div>' : ''}
                  <div class="mw-product-image">
                    ${p.image_url
                      ? `<img src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.name)}" style="width:100%;height:100%;object-fit:cover;">`
                      : `<i data-feather="package" style="width:48px;height:48px;color:#94a3b8;"></i>`
                    }
                  </div>
                  <div class="mw-product-info">
                    <div class="mw-product-category">${escapeHtml(p.category_name || 'Uncategorized')}</div>
                    <div class="mw-product-name">${escapeHtml(p.name)}</div>
                    <div class="mw-product-description">${escapeHtml(p.description || '')}</div>
                    <div class="mw-product-pricing">
                      <div class="mw-product-price-item">
                        <div class="mw-product-price-label">Cost</div>
                        <div class="mw-product-cost-value">$${parseFloat(p.base_cost).toFixed(2)}</div>
                      </div>
                      <div class="mw-product-price-item">
                        <div class="mw-product-price-label">Price</div>
                        <div class="mw-product-price-value">$${parseFloat(p.base_price).toFixed(2)}</div>
                      </div>
                    </div>
                    <div class="mw-product-actions">
                      <button class="btn btn-secondary btn-sm" onclick="editProduct(${p.id})">Edit</button>
                      ${!p.is_archived ?
                        `<button class="btn btn-danger btn-sm" onclick="archiveProduct(${p.id})">Archive</button>` :
                        `<button class="btn btn-success btn-sm" onclick="restoreProduct(${p.id})">Restore</button>`
                      }
                    </div>
                  </div>
                </div>
              `).join('');

              hydrateFeatherIcons();
            }

            // Archive a product
            function archiveProduct(productId) {
              if (!confirm('Archive this product? It will not appear in new quotes but existing quotes will be preserved.')) return;

              fetch('api-products.php?action=archive-product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: productId })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  loadProducts();
                  alert('Product archived!');
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // Restore an archived product
            function restoreProduct(productId) {
              fetch('api-products.php?action=restore-product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: productId })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  loadProducts();
                  alert('Product restored!');
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // Open category manager
            function openCategoryManager() {
              $('#categoryModal').modal('show');
            }

            // Open add product modal
            function openAddProductModal() {
              document.getElementById('modalTitle').textContent = 'Add New Product/Service';
              document.getElementById('productForm').reset();
              clearProductImage();
              $('#productModal').modal('show');
            }

            // Edit product
            function editProduct(productId) {
              const product = allProducts.find(p => p.id === productId);
              if (!product) {
                alert('Product not found');
                return;
              }

              // Populate form with product data
              const form = document.getElementById('productForm');
              form.elements['id'].value = product.id;
              form.elements['name'].value = product.name;
              form.elements['sku'].value = product.sku || '';
              form.elements['category_id'].value = product.category_id;
              form.elements['unit_type_id'].value = product.unit_type_id;
              form.elements['description'].value = product.description || '';
              form.elements['long_description'].value = product.long_description || '';
              form.elements['base_cost'].value = product.base_cost;
              form.elements['base_price'].value = product.base_price;
              form.elements['markup_percentage'].value = product.markup_percentage;
              form.elements['taxable'].checked = product.taxable;
              form.elements['gst_rate'].value = product.gst_rate;
              form.elements['pst_rate'].value = product.pst_rate;

              // Set image preview
              if (product.image_url) {
                setProductImage(product.image_url);
              } else {
                clearProductImage();
              }

              document.getElementById('modalTitle').textContent = 'Edit Product/Service';
              $('#productModal').modal('show');
            }

            // Save product
            function saveProduct() {
              const form = document.getElementById('productForm');
              const formData = new FormData(form);
              const data = Object.fromEntries(formData);

              fetch('api-products.php?action=save-product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  $('#productModal').modal('hide');
                  loadProducts();
                  alert('Product saved successfully!');
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // ============================================================
            // Media Library Browser
            // ============================================================

            // Set product image (called when selecting from media browser)
            function setProductImage(url) {
              document.getElementById('productImageUrl').value = url;
              const preview = document.getElementById('imagePreview');
              preview.innerHTML = '<img src="' + escapeHtml(url) + '" style="width:100%;height:100%;object-fit:cover;">';
              preview.style.border = '2px solid var(--mw-green, #2D8659)';
              document.getElementById('clearImageBtn').style.display = '';
              document.getElementById('imagePathDisplay').textContent = url;
            }

            // Clear product image
            function clearProductImage() {
              document.getElementById('productImageUrl').value = '';
              const preview = document.getElementById('imagePreview');
              preview.innerHTML = '<i data-feather="image" style="width:32px;height:32px;color:#94a3b8;"></i>';
              preview.style.border = '2px dashed #cbd5e1';
              document.getElementById('clearImageBtn').style.display = 'none';
              document.getElementById('imagePathDisplay').textContent = '';
              hydrateFeatherIcons();
            }

            // Open media browser modal — auto-fill search with product name
            function openMediaBrowser() {
              const productName = document.querySelector('[name="name"]').value.trim();
              const searchInput = document.getElementById('mediaSearchInput');
              searchInput.value = productName;

              if (productName) {
                document.getElementById('mediaSuggestionHint').textContent =
                  'Showing suggestions for "' + productName + '"';
                searchMediaLibrary();
              } else {
                document.getElementById('mediaSuggestionHint').textContent = '';
                loadAllMedia();
              }

              // Show on top of the product modal
              $('#mediaBrowserModal').modal({backdrop: false});
            }

            // Search media library
            function searchMediaLibrary() {
              const search = document.getElementById('mediaSearchInput').value.trim();
              fetchMediaAssets(search);
            }

            // Load all media (no search filter)
            function loadAllMedia() {
              document.getElementById('mediaSearchInput').value = '';
              document.getElementById('mediaSuggestionHint').textContent = '';
              fetchMediaAssets('');
            }

            // Fetch media assets from API
            function fetchMediaAssets(search) {
              const grid = document.getElementById('mediaGrid');
              grid.innerHTML = '<div class="text-center text-muted py-5">Loading...</div>';

              let url = 'api-media-browse.php?type=image&limit=50';
              if (search) url += '&search=' + encodeURIComponent(search);

              fetch(url)
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.media || data.media.length === 0) {
                    grid.innerHTML = '<div class="text-center text-muted py-5">No images found in media library</div>';
                    return;
                  }
                  renderMediaGrid(data.media);
                })
                .catch(err => {
                  grid.innerHTML = '<div class="text-center text-danger py-5">Error loading media: ' + escapeHtml(err.message) + '</div>';
                });
            }

            // Render the media thumbnail grid
            function renderMediaGrid(items) {
              const grid = document.getElementById('mediaGrid');
              grid.innerHTML = items.map(item => {
                const isSuggested = item.is_suggested;
                const altText = item.alt_text || '';
                const filename = item.original_filename || '';
                const dims = (item.image_width && item.image_height)
                  ? item.image_width + '×' + item.image_height
                  : '';

                return `
                  <div data-file-path="${escapeHtml(item.file_path)}" onclick="selectMediaItem(this.dataset.filePath)"
                       style="cursor:pointer;border:2px solid ${isSuggested ? '#f59e0b' : '#e2e8f0'};border-radius:8px;overflow:hidden;background:#fff;transition:border-color 0.15s, transform 0.1s;"
                       onmouseover="this.style.borderColor='var(--mw-green, #2D8659)';this.style.transform='translateY(-2px)';"
                       onmouseout="this.style.borderColor='${isSuggested ? '#f59e0b' : '#e2e8f0'}';this.style.transform='';">
                    <div style="position:relative;">
                      <img src="${escapeHtml(item.file_path)}" alt="${escapeHtml(altText)}"
                           style="width:100%;height:110px;object-fit:cover;display:block;">
                      ${isSuggested ? '<span style="position:absolute;top:4px;right:4px;background:#f59e0b;color:#fff;font-size:10px;font-weight:600;padding:2px 6px;border-radius:4px;">&#9733; Match</span>' : ''}
                    </div>
                    <div style="padding:6px 8px;">
                      <div style="font-size:11px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(filename)}">${escapeHtml(filename)}</div>
                      ${altText ? '<div style="font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(altText) + '">' + escapeHtml(altText) + '</div>' : ''}
                      ${dims ? '<div style="font-size:10px;color:#94a3b8;">' + dims + '</div>' : ''}
                    </div>
                  </div>
                `;
              }).join('');
            }

            // Select a media item and close the browser
            function selectMediaItem(filePath) {
              setProductImage(filePath);
              $('#mediaBrowserModal').modal('hide');
            }

            // Handle Enter key in media search
            document.addEventListener('DOMContentLoaded', function() {
              const msInput = document.getElementById('mediaSearchInput');
              if (msInput) {
                msInput.addEventListener('keydown', function(e) {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    searchMediaLibrary();
                  }
                });
              }
            });

            // Utility: Escape HTML
            function escapeHtml(text) {
              const div = document.createElement('div');
              div.textContent = text;
              return div.innerHTML;
            }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
