<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();
requirePermission('products.edit');

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
              <button class="btn btn-outline-danger mw-bulk-toggle-btn" id="bulkModeToggle" onclick="toggleBulkMode()">
                <i data-feather="check-square"></i> Select for Delete
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

          <!-- Bulk Action Bar -->
          <div class="mw-bulk-action-bar" id="mw-products-bulk-bar">
            <div>
              <span class="mw-bulk-count" id="mw-products-bulk-count">0</span> products selected
              <button class="btn btn-sm mw-bulk-clear-btn ml-3" onclick="mwBulkClearProducts()">Clear Selection</button>
            </div>
            <button class="btn btn-sm btn-danger" onclick="mwBulkDeleteProducts()">
              <i data-feather="trash-2"></i> Delete Selected
            </button>
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
            <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
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
                        <div id="imagePreviewArea" style="display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                          <div id="imagePreview" style="width:80px;height:80px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;overflow:hidden;border:2px dashed #cbd5e1;flex-shrink:0;">
                            <i data-feather="image" style="width:32px;height:32px;color:#94a3b8;"></i>
                          </div>
                          <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openMediaBrowser()">
                              <i data-feather="grid"></i> Browse All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="clearImageBtn" onclick="clearProductImage()" style="display:none;">
                              <i data-feather="x"></i> Clear
                            </button>
                            <div id="imagePathDisplay" class="small text-muted mt-1" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                          </div>
                        </div>
                        <div id="imageSuggestions" style="display:none;">
                          <small class="text-muted d-block mb-1"><i data-feather="star" style="width:12px;height:12px;color:#f59e0b;"></i> Suggested images:</small>
                          <div id="suggestionsRow" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
                        </div>
                      </div>

                      <!-- Icon Set Picker -->
                      <div class="mw-icon-set-picker">
                        <input type="hidden" name="icon_set_id" id="iconSetId" value="">

                        <!-- Current assignment preview -->
                        <div class="mw-icon-set-current" id="iconSetCurrent">
                          <div class="mw-icon-set-current-previews" id="iconSetCurrentPreviews" style="display:none;">
                            <img id="iconSetCurrentSold" src="" alt="Sold" width="48" height="48">
                            <img id="iconSetCurrentUnsold" src="" alt="Unsold" width="48" height="48">
                          </div>
                          <div class="flex-fill" id="iconSetCurrentLabel">
                            <span class="mw-icon-set-current-empty">No icon set assigned</span>
                          </div>
                          <button type="button" class="btn btn-sm btn-outline-secondary" id="clearIconSetBtn" onclick="clearIconSetAssignment()" style="display:none;" title="Remove icon set">
                            <i data-feather="x" style="width:13px;height:13px;"></i>
                          </button>
                        </div>

                        <!-- Action buttons -->
                        <div class="d-flex flex-wrap mb-2" style="gap:0.5rem;">
                          <button type="button" class="btn btn-sm btn-outline-primary" onclick="openIconSetsModal()">
                            <i data-feather="layers" style="width:13px;height:13px;"></i> Browse Sets
                          </button>
                          <button type="button" class="btn btn-sm btn-outline-success" id="toggleInlineUploadBtn" onclick="toggleInlineUpload()">
                            <i data-feather="upload-cloud" style="width:13px;height:13px;"></i> Upload &amp; Assign New
                          </button>
                        </div>

                        <!-- Inline upload form -->
                        <div id="inlineIconUploadForm" class="mw-icon-set-upload-form" style="display:none;">
                          <div class="row no-gutters" style="gap:6px;flex-wrap:wrap;">
                            <div class="col">
                              <input type="text" id="inlineIconSetName" class="form-control form-control-sm" placeholder="Icon set name…" maxlength="100">
                            </div>
                            <div class="col">
                              <input type="file" id="inlineIconSetFile" accept=".jpg,.jpeg,.png,.webp" class="form-control-file form-control-sm" style="padding-top:3px;">
                            </div>
                            <div class="col-auto">
                              <button type="button" class="btn btn-sm btn-success" onclick="uploadAndAssignIconSet()" id="inlineUploadBtn">
                                <i data-feather="zap" style="width:13px;height:13px;"></i> Generate
                              </button>
                            </div>
                          </div>
                          <div id="inlineIconUploadStatus" class="small mt-1"></div>
                        </div>

                        <small class="form-text text-muted">
                          Icon sets contain 14 sizes (sold &amp; unsold variants).
                          Manage all sets in the <a href="/cms/cms-media_appstack.php?tab=icon-sets" target="_blank">Media Library</a>.
                        </small>

                        <!-- Sold Status Toggle -->
                        <div class="mt-2 pt-2" style="border-top:1px dashed #e2e8f0;">
                          <label class="mw-product-checkbox-label">
                            <input type="checkbox" name="is_sold" id="isSoldToggle">
                            <strong>Mark as Sold / Available</strong>
                          </label>
                          <small class="form-text text-muted">
                            When checked, product cards show the full-colour icon. Unchecked shows greyscale (unavailable/pending).
                          </small>
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
                              <input type="number" class="form-control" name="base_cost" id="baseCostInput" step="0.01" min="0" placeholder="Your cost">
                            </div>
                          </div>
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Selling Price (per unit) *</label>
                              <input type="number" class="form-control" name="base_price" step="0.01" min="0" placeholder="Customer price">
                            </div>
                          </div>
                        </div>
                        <div class="row">
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Minimum Sell Price</label>
                              <input type="number" class="form-control" name="min_price" step="0.01" min="0" placeholder="Floor price — never sell below this">
                              <small class="form-text text-muted">
                                Prevents quoting below this price. Leave blank for no minimum.
                              </small>
                            </div>
                          </div>
                          <div class="col-md-6">
                            <div class="form-group">
                              <label>Suggested Retail Price</label>
                              <div id="suggestedRetailPrice" class="form-control" readonly style="background:#f0fdf4;border-color:#bbf7d0;font-weight:600;color:#166534;">
                                —
                              </div>
                              <small class="form-text text-muted">
                                Auto-calculated: Base Cost + Markup %
                              </small>
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
                            <input type="number" class="form-control" name="markup_percentage" id="markupInput" value="35" step="0.5" min="0">
                            <small class="form-text text-muted">
                              Applied on top of total cost (default: 35%)
                            </small>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Estimated Profit Margin</label>
                            <div id="profitMarginDisplay" class="form-control" readonly style="background: #f8fafc;font-weight:500;">
                              —
                            </div>
                            <small class="form-text text-mowology">
                              Calculated from selling price vs cost
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

                    <!-- Data Sheets & Application -->
                    <div class="mw-product-form-section">
                      <h4>Data Sheets &amp; Application</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        Upload safety data sheets and enter application details. Crew sees these on-site; customers receive relevant sheets with quotes.
                      </p>

                      <div class="form-group">
                        <label>Safety Data Sheet (SDS/MSDS)</label>
                        <input type="hidden" name="sds_sheet_url" id="sdsSheetUrl" value="">
                        <div id="sdsPreviewArea" style="display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                          <div id="sdsPreview" class="mw-sds-preview">
                            <i data-feather="file-text" style="width:32px;height:32px;color:#94a3b8;"></i>
                          </div>
                          <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openSdsBrowser()">
                              <i data-feather="grid"></i> Browse Documents
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="viewSdsBtn" onclick="viewSdsSheet()" style="display:none;">
                              <i data-feather="external-link"></i> View PDF
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="clearSdsBtn" onclick="clearSdsSheet()" style="display:none;">
                              <i data-feather="x"></i> Clear
                            </button>
                            <div id="sdsPathDisplay" class="small text-muted mt-1" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Dilution Rate</label>
                            <input type="text" class="form-control" name="dilution_rate" placeholder="e.g., 1:10 with water">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Application Rate <small class="text-muted">(label only)</small></label>
                            <input type="text" class="form-control" name="application_rate" placeholder="e.g., 50 lbs per 1000 sq ft">
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Coverage <small class="text-muted">(sq ft per unit)</small></label>
                            <input type="number" class="form-control" name="coverage_sqft_per_unit" id="coverageSqftPerUnit" min="0" step="1" placeholder="e.g., 1000" oninput="updateSpreaderCalc()">
                            <small class="form-text text-muted">How many sq ft does 1 bag/unit cover?</small>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="form-group">
                            <label>Scotts Spreader Setting</label>
                            <input type="number" class="form-control" name="scotts_spreader_setting" id="scottssSpreaderSetting" min="1" max="12" step="0.5" placeholder="e.g., 8" oninput="updateSpreaderCalc()">
                            <small class="form-text text-muted">Setting 1–12</small>
                          </div>
                        </div>
                        <div class="col-md-5">
                          <div class="form-group">
                            <label>Test Calculator</label>
                            <div class="input-group">
                              <input type="number" class="form-control" id="spreaderTestArea" min="0" step="100" placeholder="Lawn sq ft" oninput="updateSpreaderCalc()">
                              <div class="input-group-append">
                                <span class="input-group-text">sq ft</span>
                              </div>
                            </div>
                            <div id="spreaderCalcResult" class="mt-1" style="font-size:0.85rem;min-height:1.4rem;"></div>
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-3">
                          <div class="form-group">
                            <label>Ideal pH Min</label>
                            <input type="number" class="form-control" name="ph_range_min" step="0.1" min="0" max="14" placeholder="e.g., 6.0">
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="form-group">
                            <label>Ideal pH Max</label>
                            <input type="number" class="form-control" name="ph_range_max" step="0.1" min="0" max="14" placeholder="e.g., 7.0">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Safety Warnings</label>
                            <input type="text" class="form-control" name="safety_warnings" placeholder="e.g., Wear gloves. Keep away from waterways.">
                          </div>
                        </div>
                      </div>

                      <div class="form-group">
                        <label>Application Notes</label>
                        <textarea class="form-control" name="application_notes" rows="2" placeholder="Detailed application instructions, storage requirements, etc."></textarea>
                      </div>
                    </div>

                    <!-- Seasonality & Marketing -->
                    <div class="mw-product-form-section">
                      <h4>Seasonality &amp; Marketing</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        Define when this product is best applied and when to start marketing it. Crew sees talking points on their mobile schedule.
                      </p>

                      <div class="form-group">
                        <label>Best Season to Apply</label>
                        <div class="mw-season-grid" id="bestSeasonGrid">
                          <label class="mw-season-chip"><input type="checkbox" name="season_jan" value="jan"> Jan</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_feb" value="feb"> Feb</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_mar" value="mar"> Mar</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_apr" value="apr"> Apr</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_may" value="may"> May</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_jun" value="jun"> Jun</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_jul" value="jul"> Jul</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_aug" value="aug"> Aug</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_sep" value="sep"> Sep</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_oct" value="oct"> Oct</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_nov" value="nov"> Nov</label>
                          <label class="mw-season-chip"><input type="checkbox" name="season_dec" value="dec"> Dec</label>
                        </div>
                        <small class="form-text text-muted">Select the months when this product/service is best applied or sold.</small>
                      </div>

                      <div class="form-group">
                        <label>Marketing Trigger Month</label>
                        <select class="form-control" name="trigger_month" style="width:auto;">
                          <option value="">No automated trigger</option>
                          <option value="1">January</option>
                          <option value="2">February</option>
                          <option value="3">March</option>
                          <option value="4">April</option>
                          <option value="5">May</option>
                          <option value="6">June</option>
                          <option value="7">July</option>
                          <option value="8">August</option>
                          <option value="9">September</option>
                          <option value="10">October</option>
                          <option value="11">November</option>
                          <option value="12">December</option>
                        </select>
                        <small class="form-text text-muted">
                          On this month, the system will generate a marketing campaign targeting clients who haven't purchased this product.
                        </small>
                      </div>

                      <div class="form-group">
                        <label>Crew Talking Points</label>
                        <textarea class="form-control" name="crew_talking_points" rows="3" placeholder="What should crew say on-site when recommending this product?&#10;&#10;e.g., 'Your soil pH is low — lime application in spring will help your lawn absorb nutrients better. We can add it to your next visit for $X.'"></textarea>
                        <small class="form-text text-muted">Displayed to crew on their mobile schedule when this product is relevant.</small>
                      </div>
                    </div>

                    <!-- Vendor Supply & Cost Tracking -->
                    <div class="mw-product-form-section" id="vendorSupplySection">
                      <h4>Vendor Supply &amp; Cost Tracking</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        Vendors that supply this product, auto-populated from receipt scanning. Link vendor products to track real costs over time.
                      </p>
                      <div id="vendorSupplyList">
                        <p class="text-muted small" id="vendorSupplyEmpty">Save this product first, then vendor links will appear here.</p>
                      </div>
                      <div id="vendorSupplyActions" style="display:none;">
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="openVendorLinkModal()">
                          <i data-feather="link"></i> Link Vendor Product
                        </button>
                      </div>
                    </div>

                    <!-- Customer Reach -->
                    <div class="mw-product-form-section" id="customerReachSection">
                      <h4>Customer Reach</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        See which clients have purchased this product/service and who hasn't — useful for targeted campaigns.
                      </p>
                      <div id="customerReachContent">
                        <p class="text-muted small">Save this product first, then customer reach data will appear here.</p>
                      </div>
                    </div>

                    <!-- Weather Policy -->
                    <div class="mw-product-form-section">
                      <h4>Weather Policy</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">How does weather affect this service? Weather Guard uses this to decide if a scheduled visit should go ahead or be rescheduled.</p>
                      <div class="form-group">
                        <label>Weather Sensitivity</label>
                        <select class="form-control" name="weather_policy" id="weatherPolicy">
                          <option value="ANY">Any Weather — always good to go</option>
                          <option value="DRY_ONLY">Dry Only — needs no rain (e.g. mowing, painting)</option>
                          <option value="LIGHT_RAIN_OK">Light Rain OK — fine in drizzle, not heavy rain</option>
                          <option value="TEMP_LIMITED">Temperature Sensitive — too hot or cold is a problem</option>
                          <option value="WIND_LIMITED">Wind Sensitive — high wind is a problem (e.g. spraying)</option>
                        </select>
                        <small class="form-text text-muted">Fine-tune thresholds in <a href="/crm/admin/ops_weather.php" target="_blank">Ops Weather settings</a>.</small>
                      </div>
                    </div>

                    <!-- Schedule Rollover -->
                    <div class="mw-product-form-section">
                      <h4>Schedule Rollover</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">If a recurring visit isn't completed, should it automatically move to the next day? Visits roll forward up to a max number of days, then get auto-skipped.</p>
                      <div class="mw-tracking-flag-card mb-3" style="border-left: 3px solid var(--mw-green); background: var(--mw-light);">
                        <label class="mw-product-checkbox-label">
                          <input type="checkbox" name="auto_rollover" id="autoRollover" checked>
                          <strong>Auto-Rollover</strong>
                        </label>
                        <small class="form-text text-muted">
                          Incomplete recurring visits for this service will automatically move to the next day.
                          Disable for services like snow removal or salting that are date-specific.
                        </small>
                      </div>
                      <div class="form-group">
                        <label>Max Rollover Days</label>
                        <input type="number" class="form-control" name="max_rollover_days" id="maxRolloverDays" min="1" max="14" placeholder="Use global default (3)">
                        <small class="form-text text-muted">Leave blank to use the global default. After this many days, the visit is auto-skipped instead of rolling forward.</small>
                      </div>
                    </div>

                    <!-- Tracking & Compliance -->
                    <div class="mw-product-form-section" id="trackingSection">
                      <h4>Tracking &amp; Compliance</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        Set default tracking requirements for this service. These can be overridden on individual job plans.
                      </p>

                      <!-- Auto Clock-In (fixed-price services) -->
                      <div class="mw-tracking-flag-card mb-3" style="border-left: 3px solid var(--mw-green); background: var(--mw-light);">
                        <label class="mw-product-checkbox-label">
                          <input type="checkbox" name="auto_clock_in" id="autoClockIn">
                          <strong>Auto Clock-In</strong>
                        </label>
                        <small class="form-text text-muted">
                          Automatically start the timer when crew arrives on-site (GPS proximity).
                          Best for fixed-price services like lawn mowing, snow removal, and salting where the price doesn't change based on time.
                          Also applies to maintenance contract plans that use this service.
                        </small>
                      </div>

                      <div class="form-group">
                        <label>Tracking Level</label>
                        <select class="form-control" name="tracking_level" id="trackingLevel">
                          <option value="standard">Standard — no special requirements</option>
                          <option value="heightened">Heightened — require clock-in, GPS, and photos</option>
                          <option value="custom">Custom — set individual requirements below</option>
                        </select>
                      </div>

                      <div id="trackingCustomOptions" class="mw-tracking-flag-grid" style="display: none;">
                        <div class="mw-tracking-flag-card">
                          <label class="mw-product-checkbox-label">
                            <input type="checkbox" name="require_clock_in" id="requireClockIn">
                            <strong>Require Clock-In</strong>
                          </label>
                          <small class="form-text text-muted">Auto-prompt crew to clock in before starting this service</small>
                        </div>
                        <div class="mw-tracking-flag-card">
                          <label class="mw-product-checkbox-label">
                            <input type="checkbox" name="require_gps" id="requireGps">
                            <strong>Require GPS</strong>
                          </label>
                          <small class="form-text text-muted">GPS tracking must be active during this service</small>
                        </div>
                        <div class="mw-tracking-flag-card">
                          <label class="mw-product-checkbox-label">
                            <input type="checkbox" name="require_photos" id="requirePhotos">
                            <strong>Require Photos</strong>
                          </label>
                          <small class="form-text text-muted">Before/after photos required for completion</small>
                        </div>
                      </div>
                    </div>

                    <!-- Measurement-Based Pricing Rules -->
                    <div class="mw-product-form-section" id="pricingRulesSection">
                      <h4>Measurement-Based Pricing Rules</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        Link this product to property measurements for auto-quoting.
                        When a quote is auto-filled, the system will use these rules to calculate prices based on the property's measured areas.
                      </p>
                      <div id="pricingRulesList"></div>
                      <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addPricingRuleRow()">
                        + Add Pricing Rule
                      </button>
                    </div>

                    <!-- Upsell Recommendations -->
                    <div class="mw-product-form-section" id="upsellsSection">
                      <h4>Upsell Recommendations</h4>
                      <p class="text-muted mb-2" style="font-size: 0.85rem;">
                        When this product is on a customer quote, suggest these add-ons.
                      </p>
                      <div id="upsellsList"></div>
                      <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addUpsellRow()">
                        + Add Upsell Product
                      </button>
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
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-primary" onclick="saveProduct()">Save Product</button>
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

          <!-- Icon Sets Browse Modal -->
          <div class="modal fade" id="iconSetsBrowseModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">
                    <i data-feather="layers" style="width:16px;height:16px;vertical-align:-2px;"></i>
                    Browse Icon Sets
                  </h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <div id="iconSetsBrowseGrid" class="mw-icon-set-browse-grid">
                    <div class="text-muted text-center py-4 w-100">Loading…</div>
                  </div>
                </div>
                <div class="modal-footer">
                  <a href="/cms/cms-media_appstack.php?tab=icon-sets" target="_blank"
                     class="btn btn-sm btn-outline-secondary mr-auto">
                    <i data-feather="external-link" style="width:13px;height:13px;"></i> Manage Icon Sets
                  </a>
                  <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
              </div>
            </div>
          </div>

          <script>
            // CSRF token (PHP-injected, used by icon upload)
            const _CSRF_TOKEN = <?= json_encode(generateCSRFToken()) ?>;

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

              // Live pricing calculations
              ['baseCostInput', 'markupInput'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.addEventListener('input', updatePricingCalculations);
              });
              var basePriceEl = document.querySelector('[name="base_price"]');
              if (basePriceEl) basePriceEl.addEventListener('input', updatePricingCalculations);

              document.getElementById('isBundle').addEventListener('change', function() {
                document.getElementById('bundleOptions').style.display = this.checked ? 'block' : 'none';
              });

              document.getElementById('trackInventory').addEventListener('change', function() {
                document.getElementById('inventoryOptions').style.display = this.checked ? 'block' : 'none';
              });

              // Tracking level preset toggle
              document.getElementById('trackingLevel').addEventListener('change', function() {
                var level = this.value;
                var customDiv = document.getElementById('trackingCustomOptions');
                if (level === 'standard') {
                  customDiv.style.display = 'none';
                  document.getElementById('requireClockIn').checked = false;
                  document.getElementById('requireGps').checked = false;
                  document.getElementById('requirePhotos').checked = false;
                } else if (level === 'heightened') {
                  customDiv.style.display = 'grid';
                  document.getElementById('requireClockIn').checked = true;
                  document.getElementById('requireGps').checked = true;
                  document.getElementById('requirePhotos').checked = true;
                } else {
                  // custom — show checkboxes, don't change their state
                  customDiv.style.display = 'grid';
                }
              });

              // Auto-load image suggestions when product name changes
              let suggestTimer = null;
              document.querySelector('[name="name"]').addEventListener('input', function() {
                clearTimeout(suggestTimer);
                const name = this.value.trim();
                if (name.length >= 3) {
                  suggestTimer = setTimeout(function() { loadInlineSuggestions(name); }, 400);
                } else {
                  document.getElementById('imageSuggestions').style.display = 'none';
                }
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
                <div class="mw-product-card ${p.is_archived ? 'mw-product-archived' : ''} ${mwProductsBulkSelected.has(parseInt(p.id)) ? 'mw-bulk-selected' : ''}" data-product-id="${p.id}">
                  <input type="checkbox" class="mw-bulk-card-checkbox" data-id="${p.id}" ${mwProductsBulkSelected.has(parseInt(p.id)) ? 'checked' : ''} onchange="toggleProductSelection(${p.id}, this.checked)">
                  ${p.is_archived ? '<div class="mw-product-archived-badge">ARCHIVED</div>' : ''}
                  <div class="mw-product-image">
                    ${buildProductCardImage(p)}
                  </div>
                  <div class="mw-product-info">
                    <div class="mw-product-category">${escapeHtml(p.category_name || 'Uncategorized')}</div>
                    <div class="mw-product-name">${escapeHtml(p.name)}</div>
                    <div class="mw-product-description">${escapeHtml(p.description || '')}</div>
                    <div class="mw-product-pricing">
                      <div class="mw-product-price-item">
                        <div class="mw-product-price-label">Cost</div>
                        <div class="mw-product-cost-value">$${parseFloat(p.base_cost || 0).toFixed(2)}</div>
                      </div>
                      <div class="mw-product-price-item">
                        <div class="mw-product-price-label">Price</div>
                        <div class="mw-product-price-value">$${parseFloat(p.base_price || 0).toFixed(2)}</div>
                      </div>
                      ${p.min_price && parseFloat(p.min_price) > 0 ? `
                      <div class="mw-product-price-item">
                        <div class="mw-product-price-label">Min</div>
                        <div style="font-weight:600;color:#dc2626;font-size:0.9rem;">$${parseFloat(p.min_price).toFixed(2)}</div>
                      </div>` : ''}
                    </div>
                    ${(p.coverage_sqft_per_unit || p.scotts_spreader_setting) ? `
                    <div class="mw-spreader-info">
                      ${p.coverage_sqft_per_unit ? `<span class="mw-spreader-chip"><i data-feather="maximize-2"></i> ${parseFloat(p.coverage_sqft_per_unit).toLocaleString()} sq ft/unit</span>` : ''}
                      ${p.scotts_spreader_setting ? `<span class="mw-spreader-chip mw-spreader-chip-setting"><i data-feather="settings"></i> Scotts: ${p.scotts_spreader_setting}</span>` : ''}
                    </div>` : ''}
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
              document.getElementById('imageSuggestions').style.display = 'none';
              resetIconSetPicker();
              document.getElementById('isSoldToggle').checked = false;
              currentPricingRules = [];
              currentUpsells = [];
              renderPricingRules();
              renderUpsells();
              // Reset tracking fields
              document.getElementById('trackingLevel').value = 'standard';
              document.getElementById('trackingCustomOptions').style.display = 'none';
              // Reset product intelligence fields
              clearSdsSheet();
              document.getElementById('spreaderTestArea').value = '';
              document.getElementById('spreaderCalcResult').innerHTML = '';
              setSelectedSeasons('');
              // Reset vendor supply panel for new products
              document.getElementById('vendorSupplyList').innerHTML =
                '<p class="text-muted small">Save this product first, then vendor links will appear here.</p>';
              document.getElementById('vendorSupplyActions').style.display = 'none';
              // Reset customer reach
              document.getElementById('customerReachContent').innerHTML =
                '<p class="text-muted small">Save this product first, then customer reach data will appear here.</p>';
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
              form.elements['min_price'].value = product.min_price || '';
              form.elements['markup_percentage'].value = product.markup_percentage;
              form.elements['taxable'].checked = product.taxable;
              form.elements['gst_rate'].value = product.gst_rate;
              form.elements['pst_rate'].value = product.pst_rate;

              // Weather policy
              form.elements['weather_policy'].value = product.weather_policy || 'ANY';

              // Schedule rollover
              form.elements['auto_rollover'].checked = product.auto_rollover != 0;
              form.elements['max_rollover_days'].value = product.max_rollover_days || '';

              // Tracking flags
              form.elements['auto_clock_in'].checked = product.auto_clock_in == 1;
              var tLevel = product.tracking_level || 'standard';
              form.elements['tracking_level'].value = tLevel;
              form.elements['require_clock_in'].checked = product.require_clock_in == 1;
              form.elements['require_gps'].checked = product.require_gps == 1;
              form.elements['require_photos'].checked = product.require_photos == 1;
              document.getElementById('trackingCustomOptions').style.display =
                (tLevel === 'heightened' || tLevel === 'custom') ? 'grid' : 'none';

              // Data Sheets & Application fields
              form.elements['sds_sheet_url'].value = product.sds_sheet_url || '';
              form.elements['dilution_rate'].value = product.dilution_rate || '';
              form.elements['application_rate'].value = product.application_rate || '';
              form.elements['coverage_sqft_per_unit'].value = product.coverage_sqft_per_unit || '';
              form.elements['scotts_spreader_setting'].value = product.scotts_spreader_setting || '';
              form.elements['ph_range_min'].value = product.ph_range_min || '';
              form.elements['ph_range_max'].value = product.ph_range_max || '';
              updateSpreaderCalc();
              form.elements['safety_warnings'].value = product.safety_warnings || '';
              form.elements['application_notes'].value = product.application_notes || '';

              // SDS preview
              if (product.sds_sheet_url) {
                setSdsSheet(product.sds_sheet_url);
              } else {
                clearSdsSheet();
              }

              // Seasonality & Marketing fields
              setSelectedSeasons(product.best_season || '');
              form.elements['trigger_month'].value = product.trigger_month || '';
              form.elements['crew_talking_points'].value = product.crew_talking_points || '';

              // Refresh calculated displays
              updatePricingCalculations();

              // Load vendor supply data
              loadVendorSupply(product.id);

              // Load customer reach data
              loadCustomerReach(product.id);

              // Set image preview
              if (product.image_url) {
                setProductImage(product.image_url);
              } else {
                clearProductImage();
              }

              // Sold toggle
              document.getElementById('isSoldToggle').checked = product.is_sold == 1;

              // Icon set picker — populate from product data
              if (product.icon_set_id) {
                setIconSetPicker(product.icon_set_id, product.icon_set_name || ('Set #' + product.icon_set_id), product.icon_base_path);
              } else if (product.icon_base_path) {
                // Legacy product-specific icon (no named set)
                setIconSetPicker(null, 'Product icon (legacy)', product.icon_base_path);
              } else {
                resetIconSetPicker();
              }

              // Auto-load image suggestions from product name
              if (product.name) {
                loadInlineSuggestions(product.name);
              }

              // Load pricing rules and upsells
              loadProductExtras(productId);

              document.getElementById('modalTitle').textContent = 'Edit Product/Service';
              $('#productModal').modal('show');
            }

            // Save product
            function saveProduct() {
              const form = document.getElementById('productForm');
              const formData = new FormData(form);
              const data = Object.fromEntries(formData);

              // Convert checkbox values: FormData sends "on" for checked, omits unchecked
              data.uses_cost_calculator = form.elements['uses_cost_calculator'] && form.elements['uses_cost_calculator'].checked ? 1 : 0;
              data.taxable = form.elements['taxable'] && form.elements['taxable'].checked ? 1 : 0;
              data.track_inventory = form.elements['track_inventory'] && form.elements['track_inventory'].checked ? 1 : 0;
              data.featured = form.elements['featured'] && form.elements['featured'].checked ? 1 : 0;
              data.active = form.elements['active'] && form.elements['active'].checked ? 1 : 0;

              // Schedule rollover
              data.auto_rollover = form.elements['auto_rollover'] && form.elements['auto_rollover'].checked ? 1 : 0;
              data.max_rollover_days = form.elements['max_rollover_days'].value || null;

              // Tracking flags
              data.auto_clock_in = form.elements['auto_clock_in'] && form.elements['auto_clock_in'].checked ? 1 : 0;
              data.require_clock_in = form.elements['require_clock_in'] && form.elements['require_clock_in'].checked ? 1 : 0;
              data.require_gps = form.elements['require_gps'] && form.elements['require_gps'].checked ? 1 : 0;
              data.require_photos = form.elements['require_photos'] && form.elements['require_photos'].checked ? 1 : 0;

              // Icon system fields
              data.is_sold = form.elements['is_sold'] && form.elements['is_sold'].checked ? 1 : 0;

              // Product intelligence fields
              data.sds_sheet_url = form.elements['sds_sheet_url'].value || null;
              data.dilution_rate = form.elements['dilution_rate'].value || null;
              data.application_rate = form.elements['application_rate'].value || null;
              data.coverage_sqft_per_unit = form.elements['coverage_sqft_per_unit'].value || null;
              data.scotts_spreader_setting = form.elements['scotts_spreader_setting'].value || null;
              data.ph_range_min = form.elements['ph_range_min'].value || null;
              data.ph_range_max = form.elements['ph_range_max'].value || null;
              data.safety_warnings = form.elements['safety_warnings'].value || null;
              data.application_notes = form.elements['application_notes'].value || null;
              data.best_season = getSelectedSeasons() || null;
              data.trigger_month = form.elements['trigger_month'].value || null;
              data.crew_talking_points = form.elements['crew_talking_points'].value || null;

              // Remove individual season_* keys (they were just for the checkbox grid)
              ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].forEach(m => {
                delete data['season_' + m];
              });

              fetch('api-products.php?action=save-product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
              })
              .then(r => r.json())
              .then(result => {
                if (result.success) {
                  const productId = result.id || data.id;
                  // Always save extras (handles deletions even when arrays are empty)
                  if (productId) {
                    saveProductExtras(productId).then(() => {
                      $('#productModal').modal('hide');
                      loadProducts();
                    });
                  } else {
                    $('#productModal').modal('hide');
                    loadProducts();
                  }
                } else {
                  alert('Error: ' + (result.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // ============================================================
            // Spreader Calculator
            // ============================================================

            function updateSpreaderCalc() {
              var coverage = parseFloat(document.getElementById('coverageSqftPerUnit').value) || 0;
              var setting = parseFloat(document.getElementById('scottssSpreaderSetting').value) || 0;
              var area = parseFloat(document.getElementById('spreaderTestArea').value) || 0;
              var el = document.getElementById('spreaderCalcResult');

              if (!coverage || !area) {
                el.innerHTML = coverage && setting
                  ? '<span class="text-muted">Enter a lawn size to calculate</span>'
                  : '';
                return;
              }

              var raw = area / coverage;
              var qty = Math.round(raw * 2) / 2; // round to nearest 0.5

              var unitLabel = qty === 1 ? 'bag/unit' : 'bags/units';
              var settingHtml = setting
                ? ' — <strong>Scotts setting ' + setting + '</strong>'
                : '';

              el.innerHTML = '<span style="color:var(--mw-green);font-weight:600;">'
                + qty.toLocaleString() + ' ' + unitLabel
                + '</span> for ' + area.toLocaleString() + ' sq ft'
                + settingHtml;
            }

            // ============================================================
            // Pricing Calculations
            // ============================================================

            function updatePricingCalculations() {
              var cost = parseFloat(document.getElementById('baseCostInput').value) || 0;
              var markup = parseFloat(document.getElementById('markupInput').value) || 0;
              var price = parseFloat(document.querySelector('[name="base_price"]').value) || 0;

              // Suggested Retail Price = cost + (cost × markup / 100)
              var suggestedEl = document.getElementById('suggestedRetailPrice');
              if (cost > 0 && markup > 0) {
                var suggested = cost * (1 + markup / 100);
                suggestedEl.textContent = '$' + suggested.toFixed(2);
              } else {
                suggestedEl.textContent = '—';
              }

              // Profit Margin = (price - cost) / price × 100
              var marginEl = document.getElementById('profitMarginDisplay');
              if (price > 0 && cost > 0) {
                var margin = ((price - cost) / price) * 100;
                marginEl.textContent = margin.toFixed(1) + '%';
                marginEl.style.color = margin >= 20 ? '#166534' : margin >= 0 ? '#ca8a04' : '#dc2626';
              } else {
                marginEl.textContent = '—';
                marginEl.style.color = '';
              }
            }

            // ============================================================
            // Media Library Browser
            // ============================================================

            // Load inline suggestions (top 3) below the image field
            function loadInlineSuggestions(productName) {
              const container = document.getElementById('imageSuggestions');
              const row = document.getElementById('suggestionsRow');

              fetch('api-media-browse.php?type=image&limit=50&search=' + encodeURIComponent(productName))
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.media) return;

                  // Take top 3 that are actually suggested
                  const top = data.media.filter(m => m.is_suggested).slice(0, 3);

                  // If no suggestions, also show the 3 most recent as fallback
                  const items = top.length > 0 ? top : data.media.slice(0, 3);
                  if (items.length === 0) {
                    container.style.display = 'none';
                    return;
                  }

                  row.innerHTML = items.map(item => {
                    const alt = escapeHtml(item.alt_text || item.original_filename || '');
                    return `<div data-file-path="${escapeHtml(item.file_path)}" onclick="selectMediaItem(this.dataset.filePath)"
                                 title="${alt}"
                                 style="cursor:pointer;width:70px;flex-shrink:0;">
                      <img src="${escapeHtml(item.file_path)}" alt="${alt}"
                           style="width:70px;height:52px;object-fit:cover;border-radius:5px;border:2px solid ${item.is_suggested ? '#f59e0b' : '#e2e8f0'};display:block;"
                           onmouseover="this.style.borderColor='var(--mw-green,#2D8659)'"
                           onmouseout="this.style.borderColor='${item.is_suggested ? '#f59e0b' : '#e2e8f0'}'">
                      <div style="font-size:9px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;">${alt}</div>
                    </div>`;
                  }).join('');

                  container.style.display = '';
                })
                .catch(() => { container.style.display = 'none'; });
            }

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

            // ============================================================
            // Icon Set System
            // ============================================================

            /**
             * Build the card image HTML, preferring the icon set.
             * Priority: icon_base_path (resolved via JOIN) > image_url > placeholder
             */
            function buildProductCardImage(p) {
              if (p.icon_base_path) {
                const v   = p.is_sold ? 'sold' : 'unsold';
                const b   = escapeHtml(p.icon_base_path.replace(/\/?$/, '/'));
                const alt = escapeHtml(p.name);
                return `<img
                  src="${b}icon_256_${v}.png"
                  srcset="${b}icon_128_${v}.png 128w, ${b}icon_192_${v}.png 192w, ${b}icon_256_${v}.png 256w, ${b}icon_512_${v}.png 512w"
                  sizes="(max-width:480px) 128px, (max-width:1024px) 192px, 256px"
                  alt="${alt}"
                  loading="lazy"
                  width="256" height="256"
                  style="width:100%;height:100%;object-fit:cover;"
                  class="${p.is_sold ? '' : 'mw-icon-unsold'}">`;
              }
              if (p.image_url) {
                return `<img src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.name)}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">`;
              }
              return `<i data-feather="package" style="width:48px;height:48px;color:#94a3b8;"></i>`;
            }

            /** Return the CSRF token injected by PHP */
            function getCsrfToken() {
              return _CSRF_TOKEN || '';
            }

            // ── Picker state helpers ─────────────────────────────────────

            /** Reset the icon set picker to empty state */
            function resetIconSetPicker() {
              document.getElementById('iconSetId').value = '';
              document.getElementById('iconSetCurrentPreviews').style.display = 'none';
              document.getElementById('iconSetCurrentSold').src   = '';
              document.getElementById('iconSetCurrentUnsold').src = '';
              document.getElementById('iconSetCurrentLabel').innerHTML =
                '<span class="mw-icon-set-current-empty">No icon set assigned</span>';
              document.getElementById('clearIconSetBtn').style.display = 'none';
              document.getElementById('inlineIconUploadForm').style.display = 'none';
              document.getElementById('inlineIconSetName').value  = '';
              document.getElementById('inlineIconSetFile').value  = '';
              document.getElementById('inlineIconUploadStatus').textContent = '';
            }

            /** Show an assigned icon set in the picker */
            function setIconSetPicker(id, name, iconBasePath) {
              if (id) document.getElementById('iconSetId').value = id;
              const base = iconBasePath ? iconBasePath.replace(/\/?$/, '/') : '';
              const cb   = '?v=' + Date.now();
              if (base) {
                document.getElementById('iconSetCurrentSold').src   = base + 'icon_48_sold.png' + cb;
                document.getElementById('iconSetCurrentUnsold').src = base + 'icon_48_unsold.png' + cb;
                // Fallback to 64px if 48px doesn't exist
                document.getElementById('iconSetCurrentSold').onerror   = function () { this.src = base + 'icon_64_sold.png' + cb; };
                document.getElementById('iconSetCurrentUnsold').onerror = function () { this.src = base + 'icon_64_unsold.png' + cb; };
                document.getElementById('iconSetCurrentPreviews').style.display = '';
              }
              document.getElementById('iconSetCurrentLabel').innerHTML =
                '<span class="mw-icon-set-current-name">' + escapeHtml(name) + '</span>';
              document.getElementById('clearIconSetBtn').style.display = '';
            }

            // ── Browse modal ─────────────────────────────────────────────

            function openIconSetsModal() {
              const grid = document.getElementById('iconSetsBrowseGrid');
              grid.innerHTML = '<div class="text-muted text-center py-4 w-100">Loading…</div>';
              $('#iconSetsBrowseModal').modal('show');

              fetch('/crm/api/icon-sets.php?action=list')
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.icon_sets || !data.icon_sets.length) {
                    grid.innerHTML = '<div class="text-muted text-center py-4 w-100">No icon sets found. <a href="/cms/cms-media_appstack.php?tab=icon-sets" target="_blank">Create one</a> in the Media Library.</div>';
                    return;
                  }
                  grid.innerHTML = data.icon_sets.map(s => {
                    const base = escapeHtml(s.icon_base_path.replace(/\/?$/, '/'));
                    const name = escapeHtml(s.name);
                    const cnt  = s.product_count > 0 ? `${s.product_count} product${s.product_count > 1 ? 's' : ''}` : 'Not assigned';
                    return `<div class="mw-icon-set-card mw-icon-set-card--selectable"
                               onclick="selectIconSetFromModal(${escapeHtml(String(s.id))}, '${name}', '${base}')">
                      <div class="mw-icon-set-previews">
                        <div class="mw-icon-set-preview-item">
                          <img src="${base}icon_64_sold.png" alt="" loading="lazy">
                          <span class="mw-icon-set-preview-label mw-icon-set-preview-label--sold">Sold</span>
                        </div>
                        <div class="mw-icon-set-preview-item">
                          <img src="${base}icon_64_unsold.png" alt="" loading="lazy">
                          <span class="mw-icon-set-preview-label mw-icon-set-preview-label--unsold">Unsold</span>
                        </div>
                      </div>
                      <div class="mw-icon-set-card-body">
                        <div class="mw-icon-set-name">${name}</div>
                        <span class="mw-icon-set-badge ${s.product_count > 0 ? 'mw-icon-set-badge--used' : 'mw-icon-set-badge--unused'}">${cnt}</span>
                      </div>
                    </div>`;
                  }).join('');
                  if (typeof feather !== 'undefined') feather.replace();
                })
                .catch(err => {
                  grid.innerHTML = '<div class="text-danger text-center py-4 w-100">Error loading icon sets: ' + escapeHtml(err.message) + '</div>';
                });
            }

            function selectIconSetFromModal(id, name, basePath) {
              setIconSetPicker(id, name, basePath);
              $('#iconSetsBrowseModal').modal('hide');

              // Immediately assign via API if product is already saved
              const productId = parseInt(document.querySelector('[name="id"]').value, 10);
              if (productId) {
                const params = new URLSearchParams({
                  action: 'assign',
                  icon_set_id: id,
                  product_id: productId,
                  csrf_token: getCsrfToken()
                });
                fetch('/crm/api/icon-sets.php', { method: 'POST', body: params })
                  .then(r => r.json())
                  .then(data => { if (data.success) loadProducts(); })
                  .catch(() => {}); // silent — form save will persist it anyway
              }
            }

            // ── Clear assignment ──────────────────────────────────────────

            function clearIconSetAssignment() {
              const productId = parseInt(document.querySelector('[name="id"]').value, 10);
              resetIconSetPicker();

              if (productId) {
                const params = new URLSearchParams({
                  action: 'unassign',
                  product_id: productId,
                  csrf_token: getCsrfToken()
                });
                fetch('/crm/api/icon-sets.php', { method: 'POST', body: params })
                  .then(r => r.json())
                  .then(data => { if (data.success) loadProducts(); })
                  .catch(() => {});
              }
            }

            // ── Inline upload & assign ────────────────────────────────────

            function toggleInlineUpload() {
              const form = document.getElementById('inlineIconUploadForm');
              form.style.display = form.style.display === 'none' ? '' : 'none';
            }

            function uploadAndAssignIconSet() {
              const name     = (document.getElementById('inlineIconSetName').value || '').trim();
              const fileInput = document.getElementById('inlineIconSetFile');
              const statusEl = document.getElementById('inlineIconUploadStatus');
              const btn      = document.getElementById('inlineUploadBtn');
              const productId = parseInt(document.querySelector('[name="id"]').value, 10);

              if (!name) {
                statusEl.textContent = 'Enter a name first.';
                statusEl.style.color = '#dc2626';
                document.getElementById('inlineIconSetName').focus();
                return;
              }
              if (!fileInput.files[0]) {
                statusEl.textContent = 'Select an image file.';
                statusEl.style.color = '#dc2626';
                return;
              }
              if (fileInput.files[0].size > 5 * 1024 * 1024) {
                statusEl.textContent = 'File exceeds 5 MB.';
                statusEl.style.color = '#dc2626';
                return;
              }

              statusEl.textContent = 'Generating icon set…';
              statusEl.style.color = '#6c757d';
              if (btn) btn.disabled = true;

              const fd = new FormData();
              fd.append('action', 'upload');
              fd.append('name', name);
              fd.append('file', fileInput.files[0]);
              fd.append('csrf_token', getCsrfToken());

              fetch('/crm/api/icon-sets.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                  if (!data.success) {
                    statusEl.textContent = 'Error: ' + (data.error || 'Unknown error');
                    statusEl.style.color = '#dc2626';
                    return;
                  }
                  statusEl.textContent = '✓ Icon set created!';
                  statusEl.style.color = '#166534';
                  setIconSetPicker(data.id, data.name, data.icon_base_path);
                  document.getElementById('inlineIconUploadForm').style.display = 'none';
                  loadProducts();

                  // If product is saved, immediately assign
                  if (productId) {
                    const params = new URLSearchParams({
                      action: 'assign',
                      icon_set_id: data.id,
                      product_id: productId,
                      csrf_token: getCsrfToken()
                    });
                    fetch('/crm/api/icon-sets.php', { method: 'POST', body: params })
                      .then(r => r.json())
                      .catch(() => {});
                  }
                })
                .catch(err => {
                  statusEl.textContent = 'Upload failed: ' + err.message;
                  statusEl.style.color = '#dc2626';
                })
                .finally(() => {
                  if (btn) btn.disabled = false;
                });
            }

            // Open media browser modal — always load library, score by product name
            function openMediaBrowser() {
              const productName = document.querySelector('[name="name"]').value.trim();
              const searchInput = document.getElementById('mediaSearchInput');
              searchInput.value = productName;

              if (productName) {
                document.getElementById('mediaSuggestionHint').textContent =
                  'Suggestions for "' + productName + '" shown first';
              } else {
                document.getElementById('mediaSuggestionHint').textContent = '';
              }

              // Always load the full library — matches sort to top
              fetchMediaAssets(productName);

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

            // ── Bulk Delete ──────────────────────────────────────
            let bulkMode = false;
            const mwProductsBulkSelected = new Set();

            function toggleBulkMode() {
              bulkMode = !bulkMode;
              document.getElementById('productsGrid').classList.toggle('mw-bulk-mode', bulkMode);
              document.getElementById('bulkModeToggle').classList.toggle('active', bulkMode);
              if (!bulkMode) {
                mwProductsBulkSelected.clear();
                document.querySelectorAll('.mw-bulk-card-checkbox').forEach(function(cb) { cb.checked = false; });
                document.querySelectorAll('.mw-product-card').forEach(function(c) { c.classList.remove('mw-bulk-selected'); });
                mwBulkUpdateBar('products');
              }
            }

            function toggleProductSelection(id, checked) {
              id = parseInt(id);
              if (checked) {
                mwProductsBulkSelected.add(id);
              } else {
                mwProductsBulkSelected.delete(id);
              }
              var card = document.querySelector('.mw-product-card[data-product-id="' + id + '"]');
              if (card) card.classList.toggle('mw-bulk-selected', checked);
              mwBulkUpdateBar('products');
            }

            function mwBulkUpdateBar(entity) {
              var bar = document.getElementById('mw-' + entity + '-bulk-bar');
              var count = document.getElementById('mw-' + entity + '-bulk-count');
              if (!bar || !count) return;
              var size = mwProductsBulkSelected.size;
              count.textContent = size;
              if (size > 0) {
                bar.classList.add('mw-bulk-visible');
              } else {
                bar.classList.remove('mw-bulk-visible');
              }
            }

            function mwBulkClearProducts() {
              mwProductsBulkSelected.clear();
              document.querySelectorAll('.mw-bulk-card-checkbox').forEach(function(cb) { cb.checked = false; });
              document.querySelectorAll('.mw-product-card').forEach(function(c) { c.classList.remove('mw-bulk-selected'); });
              mwBulkUpdateBar('products');
            }

            function mwBulkDeleteProducts() {
              var count = mwProductsBulkSelected.size;
              if (count === 0) return;
              if (!confirm('Permanently delete ' + count + ' product(s)? Existing quote line items will have their product link removed. This cannot be undone.')) return;

              fetch('api-products.php?action=bulk-delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: Array.from(mwProductsBulkSelected) })
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.success) {
                  alert(data.deleted_count + ' product(s) deleted.');
                  mwProductsBulkSelected.clear();
                  bulkMode = false;
                  document.getElementById('productsGrid').classList.remove('mw-bulk-mode');
                  document.getElementById('bulkModeToggle').classList.remove('active');
                  mwBulkUpdateBar('products');
                  loadProducts();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(function(err) { alert('Error: ' + err.message); });
            }

            // ============================================================
            // Pricing Rules Management
            // ============================================================
            let measurementGroups = [];
            let currentPricingRules = [];
            let currentUpsells = [];

            // Load measurement groups on page load
            function loadMeasurementGroups() {
              fetch('api-products.php?action=get-measurement-groups')
                .then(r => r.json())
                .then(data => {
                  if (data.success) measurementGroups = data.groups;
                })
                .catch(err => console.error('Error loading measurement groups:', err));
            }
            loadMeasurementGroups();

            function renderPricingRules() {
              const container = document.getElementById('pricingRulesList');
              if (currentPricingRules.length === 0) {
                container.innerHTML = '<p class="text-muted small">No pricing rules configured. This product will use manual pricing only.</p>';
                return;
              }
              container.innerHTML = currentPricingRules.map((rule, idx) => `
                <div class="mw-pricing-rule-row" data-idx="${idx}">
                  <div class="row">
                    <div class="col-md-4">
                      <label class="small">Measurement Group</label>
                      <select class="form-control form-control-sm" onchange="currentPricingRules[${idx}].measurement_group_id=this.value">
                        ${measurementGroups.map(g => `<option value="${g.id}" ${g.id == rule.measurement_group_id ? 'selected' : ''}>${escapeHtml(g.group_label)}</option>`).join('')}
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="small">Pricing Model</label>
                      <select class="form-control form-control-sm" onchange="currentPricingRules[${idx}].pricing_model=this.value;renderPricingRules()">
                        <option value="flat" ${rule.pricing_model==='flat'?'selected':''}>Flat Price</option>
                        <option value="per_sqft" ${rule.pricing_model==='per_sqft'?'selected':''}>Per Sq Ft</option>
                        <option value="per_linear_ft" ${rule.pricing_model==='per_linear_ft'?'selected':''}>Per Linear Ft</option>
                        <option value="min_plus_sqft" ${rule.pricing_model==='min_plus_sqft'?'selected':''}>Min + Per Sq Ft</option>
                        <option value="min_plus_linear_ft" ${rule.pricing_model==='min_plus_linear_ft'?'selected':''}>Min + Per Linear Ft</option>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="small">Frequency</label>
                      <select class="form-control form-control-sm" onchange="currentPricingRules[${idx}].default_frequency=this.value">
                        <option value="one_off" ${rule.default_frequency==='one_off'?'selected':''}>One-off</option>
                        <option value="daily" ${rule.default_frequency==='daily'?'selected':''}>Daily</option>
                        <option value="7_day" ${rule.default_frequency==='7_day'?'selected':''}>Weekly</option>
                        <option value="14_day" ${rule.default_frequency==='14_day'?'selected':''}>Bi-weekly</option>
                        <option value="21_day" ${rule.default_frequency==='21_day'?'selected':''}>Every 3 wks</option>
                        <option value="monthly" ${rule.default_frequency==='monthly'?'selected':''}>Monthly</option>
                        <option value="seasonal" ${rule.default_frequency==='seasonal'?'selected':''}>Seasonal</option>
                      </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removePricingRule(${idx})" title="Remove">
                        &times;
                      </button>
                    </div>
                  </div>
                  ${rule.pricing_model !== 'flat' ? `
                  <div class="row mt-2">
                    <div class="col-md-3">
                      <label class="small">Price/Unit ($)</label>
                      <input type="number" class="form-control form-control-sm" step="0.0001" value="${rule.price_per_unit||0}"
                             onchange="currentPricingRules[${idx}].price_per_unit=this.value">
                    </div>
                    <div class="col-md-3">
                      <label class="small">Minimum ($)</label>
                      <input type="number" class="form-control form-control-sm" step="0.01" value="${rule.minimum_price||0}"
                             onchange="currentPricingRules[${idx}].minimum_price=this.value">
                    </div>
                    ${rule.pricing_model.startsWith('min_plus') ? `
                    <div class="col-md-3">
                      <label class="small">Included Units</label>
                      <input type="number" class="form-control form-control-sm" step="0.01" value="${rule.included_units||0}"
                             onchange="currentPricingRules[${idx}].included_units=this.value">
                    </div>` : ''}
                    <div class="col-md-3 d-flex align-items-end">
                      <label class="mw-product-checkbox-label small">
                        <input type="checkbox" ${rule.is_default_for_group == 1 ? 'checked' : ''}
                               onchange="currentPricingRules[${idx}].is_default_for_group=this.checked?1:0">
                        Default for group
                      </label>
                    </div>
                  </div>` : ''}
                </div>
              `).join('');
            }

            function addPricingRuleRow() {
              if (measurementGroups.length === 0) {
                alert('No measurement groups available. Please run the database migration first.');
                return;
              }
              currentPricingRules.push({
                id: null,
                measurement_group_id: measurementGroups[0].id,
                pricing_model: 'per_sqft',
                price_per_unit: 0,
                minimum_price: 0,
                included_units: 0,
                default_frequency: 'one_off',
                is_default_for_group: 0,
                priority: 0,
                is_active: 1,
              });
              renderPricingRules();
            }

            function removePricingRule(idx) {
              currentPricingRules.splice(idx, 1);
              renderPricingRules();
            }

            // ============================================================
            // Upsells Management
            // ============================================================

            function renderUpsells() {
              const container = document.getElementById('upsellsList');
              if (currentUpsells.length === 0) {
                container.innerHTML = '<p class="text-muted small">No upsells configured.</p>';
                return;
              }
              container.innerHTML = currentUpsells.map((up, idx) => `
                <div class="mw-upsell-row" data-idx="${idx}">
                  <div class="row">
                    <div class="col-md-4">
                      <label class="small">Upsell Product</label>
                      <select class="form-control form-control-sm" onchange="currentUpsells[${idx}].upsell_product_id=this.value">
                        ${allProducts.filter(p => !p.is_archived).map(p => `<option value="${p.id}" ${p.id == up.upsell_product_id ? 'selected' : ''}>${escapeHtml(p.name)}</option>`).join('')}
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="small">Type</label>
                      <select class="form-control form-control-sm" onchange="currentUpsells[${idx}].upsell_type=this.value">
                        <option value="recommended" ${up.upsell_type==='recommended'?'selected':''}>Recommended</option>
                        <option value="addon" ${up.upsell_type==='addon'?'selected':''}>Add-on</option>
                        <option value="upgrade" ${up.upsell_type==='upgrade'?'selected':''}>Upgrade</option>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="small">Display Text</label>
                      <input type="text" class="form-control form-control-sm" value="${escapeHtml(up.display_text||'')}"
                             placeholder="Optional override"
                             onchange="currentUpsells[${idx}].display_text=this.value">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                      <label class="mw-product-checkbox-label small" title="Pre-selected on customer view">
                        <input type="checkbox" ${up.default_checked == 1 ? 'checked' : ''}
                               onchange="currentUpsells[${idx}].default_checked=this.checked?1:0">
                      </label>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeUpsell(${idx})">&times;</button>
                    </div>
                  </div>
                </div>
              `).join('');
            }

            function addUpsellRow() {
              if (allProducts.length === 0) {
                alert('No products loaded to select as upsells.');
                return;
              }
              const currentProductId = document.getElementById('productForm').elements['id'].value;
              const firstOther = allProducts.find(p => !p.is_archived && p.id != currentProductId);
              currentUpsells.push({
                id: null,
                upsell_product_id: firstOther ? firstOther.id : allProducts[0].id,
                upsell_type: 'recommended',
                display_text: '',
                default_checked: 0,
                sort_order: currentUpsells.length,
                is_active: 1,
              });
              renderUpsells();
            }

            function removeUpsell(idx) {
              currentUpsells.splice(idx, 1);
              renderUpsells();
            }

            // Load pricing rules and upsells for a product
            function loadProductExtras(productId) {
              // Load pricing rules
              fetch('api-products.php?action=get-pricing-rules&product_id=' + productId)
                .then(r => r.json())
                .then(data => {
                  currentPricingRules = data.success ? data.rules : [];
                  renderPricingRules();
                })
                .catch(() => { currentPricingRules = []; renderPricingRules(); });

              // Load upsells
              fetch('api-products.php?action=get-upsells&product_id=' + productId)
                .then(r => r.json())
                .then(data => {
                  currentUpsells = data.success ? data.upsells : [];
                  renderUpsells();
                })
                .catch(() => { currentUpsells = []; renderUpsells(); });
            }

            // Save pricing rules and upsells after product save
            function saveProductExtras(productId) {
              // Delete all existing pricing rules first, then re-create remaining ones
              const rulesChain = fetch('api-products.php?action=delete-all-pricing-rules', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ product_id: productId })
              })
              .then(r => r.json())
              .then(() => {
                // Re-create each remaining rule (strip id so they insert fresh)
                return Promise.all(currentPricingRules.map(rule => {
                  const saveData = Object.assign({}, rule, { product_id: productId });
                  delete saveData.id;
                  return fetch('api-products.php?action=save-pricing-rule', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(saveData)
                  }).then(r => r.json());
                }));
              });

              // Delete all existing upsells first, then re-create remaining ones
              const upsellsChain = fetch('api-products.php?action=delete-all-upsells', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ product_id: productId })
              })
              .then(r => r.json())
              .then(() => {
                return Promise.all(currentUpsells.map(up => {
                  const saveData = Object.assign({}, up, { base_product_id: productId });
                  delete saveData.id;
                  return fetch('api-products.php?action=save-upsell', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(saveData)
                  }).then(r => r.json());
                }));
              });

              return Promise.all([rulesChain, upsellsChain]);
            }

            // ============================================================
            // SDS Document Browser
            // ============================================================

            function setSdsSheet(url) {
              document.getElementById('sdsSheetUrl').value = url;
              const preview = document.getElementById('sdsPreview');
              const filename = url.split('/').pop();
              preview.innerHTML = '<i data-feather="file-text" style="width:32px;height:32px;color:var(--mw-green);"></i>';
              preview.style.border = '2px solid var(--mw-green, #2D8659)';
              document.getElementById('viewSdsBtn').style.display = '';
              document.getElementById('clearSdsBtn').style.display = '';
              document.getElementById('sdsPathDisplay').textContent = filename;
              hydrateFeatherIcons();
            }

            function clearSdsSheet() {
              document.getElementById('sdsSheetUrl').value = '';
              const preview = document.getElementById('sdsPreview');
              preview.innerHTML = '<i data-feather="file-text" style="width:32px;height:32px;color:#94a3b8;"></i>';
              preview.style.border = '2px dashed #cbd5e1';
              document.getElementById('viewSdsBtn').style.display = 'none';
              document.getElementById('clearSdsBtn').style.display = 'none';
              document.getElementById('sdsPathDisplay').textContent = '';
              hydrateFeatherIcons();
            }

            function viewSdsSheet() {
              const url = document.getElementById('sdsSheetUrl').value;
              if (url) window.open(url, '_blank');
            }

            function openSdsBrowser() {
              // Reuse the media browser modal but filter for documents
              const searchInput = document.getElementById('mediaSearchInput');
              const productName = document.querySelector('[name="name"]').value.trim();
              searchInput.value = productName || 'sds';
              document.getElementById('mediaSuggestionHint').textContent = 'Showing documents (PDF, DOC)';

              // Fetch documents instead of images
              const grid = document.getElementById('mediaGrid');
              grid.innerHTML = '<div class="text-center text-muted py-5">Loading documents...</div>';

              let url = 'api-media-browse.php?type=document&limit=50';
              if (productName) url += '&search=' + encodeURIComponent(productName);

              fetch(url)
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.media || data.media.length === 0) {
                    grid.innerHTML = '<div class="text-center text-muted py-5">No documents found in media library.<br><small>Upload SDS PDFs via <a href="/cms/cms-media_appstack.php" target="_blank">Media Library</a> first.</small></div>';
                    return;
                  }
                  renderSdsGrid(data.media);
                })
                .catch(err => {
                  grid.innerHTML = '<div class="text-center text-danger py-5">Error loading documents</div>';
                });

              // Override the title and show the modal
              document.getElementById('mediaBrowserTitle').textContent = 'Choose SDS/Document from Media Library';
              window._sdsBrowseMode = true;
              $('#mediaBrowserModal').modal({backdrop: false});
            }

            function renderSdsGrid(items) {
              const grid = document.getElementById('mediaGrid');
              grid.innerHTML = items.map(item => {
                const filename = item.original_filename || item.file_path.split('/').pop();
                const altText = item.alt_text || '';
                return `
                  <div data-file-path="${escapeHtml(item.file_path)}" onclick="selectSdsItem(this.dataset.filePath)"
                       style="cursor:pointer;border:2px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;transition:border-color 0.15s;padding:12px;text-align:center;"
                       onmouseover="this.style.borderColor='var(--mw-green, #2D8659)'"
                       onmouseout="this.style.borderColor='#e2e8f0'">
                    <div style="font-size:32px;margin-bottom:8px;">📄</div>
                    <div style="font-size:11px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(filename)}">${escapeHtml(filename)}</div>
                    ${altText ? '<div style="font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(altText) + '</div>' : ''}
                  </div>
                `;
              }).join('');
            }

            function selectSdsItem(filePath) {
              setSdsSheet(filePath);
              window._sdsBrowseMode = false;
              document.getElementById('mediaBrowserTitle').textContent = 'Choose Image from Media Library';
              $('#mediaBrowserModal').modal('hide');
            }

            // Override selectMediaItem to check if we're in SDS browse mode
            const _originalSelectMediaItem = selectMediaItem;
            selectMediaItem = function(filePath) {
              if (window._sdsBrowseMode) {
                selectSdsItem(filePath);
              } else {
                _originalSelectMediaItem(filePath);
              }
            };

            // ============================================================
            // Season Grid Helpers
            // ============================================================

            function getSelectedSeasons() {
              const months = [];
              document.querySelectorAll('#bestSeasonGrid input[type="checkbox"]').forEach(cb => {
                if (cb.checked) months.push(cb.value);
              });
              return months.join(',');
            }

            function setSelectedSeasons(seasonStr) {
              // Clear all first
              document.querySelectorAll('#bestSeasonGrid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
              });
              if (!seasonStr) return;
              const months = seasonStr.split(',').map(s => s.trim().toLowerCase());
              months.forEach(m => {
                const cb = document.querySelector('#bestSeasonGrid input[value="' + m + '"]');
                if (cb) cb.checked = true;
              });
            }

            // ============================================================
            // Vendor Supply Panel
            // ============================================================

            function loadVendorSupply(productId) {
              if (!productId) {
                document.getElementById('vendorSupplyList').innerHTML =
                  '<p class="text-muted small">Save this product first, then vendor links will appear here.</p>';
                document.getElementById('vendorSupplyActions').style.display = 'none';
                return;
              }

              document.getElementById('vendorSupplyActions').style.display = '';

              fetch('api-products.php?action=get-vendor-supply&product_id=' + productId)
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.vendors || data.vendors.length === 0) {
                    document.getElementById('vendorSupplyList').innerHTML =
                      '<p class="text-muted small">No vendor products linked yet. Link a vendor product from your expense receipts.</p>';
                    return;
                  }
                  renderVendorSupply(data.vendors);
                })
                .catch(() => {
                  document.getElementById('vendorSupplyList').innerHTML =
                    '<p class="text-muted small">Unable to load vendor data.</p>';
                });
            }

            function renderVendorSupply(vendors) {
              const container = document.getElementById('vendorSupplyList');
              container.innerHTML = `
                <table class="table table-sm mb-0" style="font-size:0.85rem;">
                  <thead>
                    <tr>
                      <th>Vendor</th>
                      <th>Product</th>
                      <th class="text-right">Last Price</th>
                      <th class="text-right">Prev Price</th>
                      <th class="text-center">Trend</th>
                      <th class="text-right">Last Bought</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    ${vendors.map(v => {
                      const trend = v.price_change > 0 ? '↑' : v.price_change < 0 ? '↓' : '→';
                      const trendColor = v.price_change > 0 ? '#dc2626' : v.price_change < 0 ? '#16a34a' : '#64748b';
                      const trendPct = v.price_change ? (Math.abs(v.price_change) * 100).toFixed(0) + '%' : '';
                      return `<tr>
                        <td><strong>${escapeHtml(v.vendor_name)}</strong></td>
                        <td>${escapeHtml(v.vendor_product_name)}</td>
                        <td class="text-right">$${parseFloat(v.last_price || 0).toFixed(2)}</td>
                        <td class="text-right text-muted">$${parseFloat(v.prev_price || 0).toFixed(2)}</td>
                        <td class="text-center" style="color:${trendColor};font-weight:600;">${trend} ${trendPct}</td>
                        <td class="text-right text-muted">${v.last_purchased || '—'}</td>
                        <td><button class="btn btn-sm btn-outline-danger" onclick="unlinkVendorProduct(${v.vendor_product_id})" title="Unlink">&times;</button></td>
                      </tr>`;
                    }).join('')}
                  </tbody>
                </table>
              `;
            }

            function unlinkVendorProduct(vendorProductId) {
              if (!confirm('Unlink this vendor product?')) return;
              const productId = document.getElementById('productForm').elements['id'].value;
              fetch('api-products.php?action=unlink-vendor-product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ vendor_product_id: vendorProductId })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) loadVendorSupply(productId);
                else alert('Error: ' + (data.error || 'Unknown'));
              });
            }

            function openVendorLinkModal() {
              const productId = document.getElementById('productForm').elements['id'].value;
              if (!productId) { alert('Save the product first.'); return; }

              const query = prompt('Search vendor products by name (e.g., "mulch", "lime"):');
              if (!query) return;

              fetch('api-products.php?action=search-vendor-products&search=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.results || data.results.length === 0) {
                    alert('No unlinked vendor products found for "' + query + '".\nVendor products are created automatically from receipt scanning in Expenses.');
                    return;
                  }
                  // Build a simple selection list
                  const options = data.results.map(r => r.vendor_name + ' — ' + r.name + ' ($' + parseFloat(r.price_per_unit || 0).toFixed(2) + ')');
                  const choice = prompt(
                    'Select a vendor product to link (enter number):\n\n' +
                    options.map((o, i) => (i + 1) + '. ' + o).join('\n')
                  );
                  const idx = parseInt(choice) - 1;
                  if (isNaN(idx) || idx < 0 || idx >= data.results.length) return;

                  // Link it
                  fetch('api-products.php?action=link-vendor-product', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ vendor_product_id: data.results[idx].id, product_id: productId })
                  })
                  .then(r => r.json())
                  .then(result => {
                    if (result.success) {
                      loadVendorSupply(productId);
                    } else {
                      alert('Error: ' + (result.error || 'Unknown'));
                    }
                  });
                });
            }

            // ── Customer Reach ──────────────────────────────────────

            function loadCustomerReach(productId) {
              const container = document.getElementById('customerReachContent');
              if (!productId) {
                container.innerHTML = '<p class="text-muted small">Save this product first, then customer reach data will appear here.</p>';
                return;
              }

              container.innerHTML = '<p class="text-muted small">Loading customer reach data...</p>';

              fetch('api-products.php?action=get-purchase-stats&product_id=' + productId)
                .then(r => r.json())
                .then(data => {
                  if (!data.success || !data.available) {
                    container.innerHTML = '<p class="text-muted small">' +
                      (data.message || 'Purchase history not available yet. Run the daily refresh cron.') + '</p>';
                    return;
                  }
                  renderCustomerReach(data, productId);
                })
                .catch(() => {
                  container.innerHTML = '<p class="text-muted small">Unable to load customer reach data.</p>';
                });
            }

            function renderCustomerReach(data, productId) {
              const container = document.getElementById('customerReachContent');
              const pct = data.total_contacts > 0
                ? Math.round((data.purchaser_count / data.total_contacts) * 100)
                : 0;

              let html = `
                <div class="mw-reach-stats">
                  <div class="mw-reach-stat mw-reach-purchased">
                    <span class="mw-reach-number">${data.purchaser_count}</span>
                    <span class="mw-reach-label">clients have purchased</span>
                  </div>
                  <div class="mw-reach-stat mw-reach-available">
                    <span class="mw-reach-number">${data.non_purchaser_count}</span>
                    <span class="mw-reach-label">haven't purchased yet</span>
                  </div>
                  <div class="mw-reach-stat">
                    <span class="mw-reach-number">${pct}%</span>
                    <span class="mw-reach-label">penetration</span>
                  </div>
                </div>
              `;

              // Non-purchaser list (campaign targets)
              if (data.non_purchasers && data.non_purchasers.length > 0) {
                html += `<h6 class="mt-3 mb-2" style="font-size:0.85rem;color:#64748b;">Potential Campaign Targets</h6>`;
                html += `<div class="mw-reach-list">`;
                data.non_purchasers.slice(0, 10).forEach(c => {
                  const name = escapeHtml((c.first_name || '') + ' ' + (c.last_name || '')).trim() || 'Unnamed';
                  const lastService = c.last_service_date || '—';
                  html += `<div class="mw-reach-item">
                    <span class="mw-reach-name">${name}</span>
                    <span class="mw-reach-email text-muted">${escapeHtml(c.email || '')}</span>
                    <span class="mw-reach-date text-muted">Last service: ${lastService}</span>
                  </div>`;
                });
                if (data.non_purchasers.length > 10) {
                  html += `<div class="text-muted small mt-1">+ ${data.non_purchasers.length - 10} more</div>`;
                }
                html += `</div>`;
              }

              container.innerHTML = html;
            }

            // Utility: Escape HTML
            function escapeHtml(text) {
              const div = document.createElement('div');
              div.textContent = text;
              return div.innerHTML;
            }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
