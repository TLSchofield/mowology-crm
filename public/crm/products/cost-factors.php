<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Cost Factors';
$activePage = 'products';
$csrfToken = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="index.php" class="mw-back-link">&larr; Back to Products</a>

          <h1 class="h3 mb-1">Cost Factors Management</h1>
          <p class="text-muted mb-4">Configure labor rates, equipment costs, and overhead to calculate accurate product costs</p>

          <div id="pageAlert" class="alert d-none mb-3" role="alert"></div>

          <!-- Tabs -->
          <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" id="labor-tab" data-toggle="tab" href="#labor" role="tab">Labor Rates</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="equipment-tab" data-toggle="tab" href="#equipment" role="tab">Equipment</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="overhead-tab" data-toggle="tab" href="#overhead" role="tab">Overhead &amp; Margins</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="materials-tab" data-toggle="tab" href="#materials" role="tab">Materials</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="calculator-tab" data-toggle="tab" href="#calculator" role="tab">Cost Calculator</a>
            </li>
          </ul>

          <div class="tab-content">

            <!-- LABOR RATES TAB -->
            <div class="tab-pane fade show active" id="labor" role="tabpanel">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Labor Rates</h5>
                  <button class="btn btn-primary btn-sm" onclick="openAddModal('labor')">+ Add Labor Rate</button>
                </div>
                <div class="card-body">
                  <div class="alert alert-info">
                    These rates are used to calculate the cost of services that require labor. Update these when wage rates change.
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover" id="laborTable">
                      <thead>
                        <tr>
                          <th>Position</th>
                          <th>Hourly Rate</th>
                          <th>With Benefits</th>
                          <th>Description</th>
                          <th>Status</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="laborBody">
                        <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- EQUIPMENT TAB -->
            <div class="tab-pane fade" id="equipment" role="tabpanel">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Equipment Costs</h5>
                  <button class="btn btn-primary btn-sm" onclick="openAddModal('equipment')">+ Add Equipment</button>
                </div>
                <div class="card-body">
                  <div class="alert alert-info">
                    Equipment costs include fuel, maintenance, and depreciation. These are added to service costs.
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead>
                        <tr>
                          <th>Equipment</th>
                          <th>Cost Per Hour</th>
                          <th>Type</th>
                          <th>Description</th>
                          <th>Status</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="equipmentBody">
                        <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- OVERHEAD TAB -->
            <div class="tab-pane fade" id="overhead" role="tabpanel">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Overhead &amp; Profit Margins</h5>
                </div>
                <div class="card-body">
                  <div class="alert alert-warning">
                    These percentages affect all calculated product costs. Changes will update all products using cost calculation.
                  </div>

                  <div class="row mt-3">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>General Overhead %</label>
                        <input type="number" class="form-control" id="overheadPercent" value="20" step="0.5" min="0" max="100">
                        <small class="form-text text-muted">Insurance, office, utilities, etc.</small>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Target Profit Margin %</label>
                        <input type="number" class="form-control" id="profitMargin" value="35" step="0.5" min="0" max="100">
                        <small class="form-text text-muted">Applied on top of cost + overhead</small>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>GST Rate %</label>
                        <input type="number" class="form-control" id="gstRate" value="5" step="0.1" min="0" max="20">
                        <small class="form-text text-muted">Applied to final selling price</small>
                      </div>
                    </div>
                  </div>

                  <div class="mw-calc-box mt-4" id="overheadPreview">
                    <h4>Pricing Example</h4>
                    <div class="mw-calc-row">
                      <span>Base Cost</span>
                      <span>$100.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span id="ohOverheadLabel">+ Overhead (20%)</span>
                      <span id="ohOverheadVal">$20.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>= Total Cost</span>
                      <span id="ohTotalCost">$120.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span id="ohProfitLabel">+ Profit (35% of $120)</span>
                      <span id="ohProfitVal">$42.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>= Selling Price (before tax)</span>
                      <span id="ohSellingPrice">$162.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span id="ohGstLabel">+ GST (5%)</span>
                      <span id="ohGstVal">$8.10</span>
                    </div>
                    <div class="mw-calc-row">
                      <span><strong>Final Customer Price</strong></span>
                      <span><strong id="ohFinalPrice">$170.10</strong></span>
                    </div>
                  </div>

                  <div class="mt-3">
                    <button class="btn btn-primary" onclick="saveOverhead()">Save Changes</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- MATERIALS TAB -->
            <div class="tab-pane fade" id="materials" role="tabpanel">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Material Costs</h5>
                  <button class="btn btn-primary btn-sm" onclick="openAddModal('material')">+ Add Material</button>
                </div>
                <div class="card-body">
                  <div class="alert alert-info">
                    Track your supplier costs for materials. These update your product costs automatically.
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead>
                        <tr>
                          <th>Material</th>
                          <th>Cost Per Unit</th>
                          <th>Unit</th>
                          <th>Supplier</th>
                          <th>Status</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="materialBody">
                        <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- CALCULATOR TAB -->
            <div class="tab-pane fade" id="calculator" role="tabpanel">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Service Cost Calculator</h5>
                </div>
                <div class="card-body">
                  <div class="alert alert-info">
                    Use this calculator to estimate costs for a typical service. This helps set your product prices.
                  </div>

                  <div class="row mt-3">
                    <!-- Input Factors -->
                    <div class="col-md-6">
                      <h5 class="mb-3">Input Factors</h5>

                      <div class="form-group">
                        <label>Labor Hours</label>
                        <input type="number" class="form-control calc-input" id="calcLaborHours" value="2" step="0.25" min="0">
                      </div>

                      <div class="form-group">
                        <label>Labor Type</label>
                        <select class="form-control calc-input" id="calcLaborType">
                          <option value="">Loading...</option>
                        </select>
                      </div>

                      <div class="form-group">
                        <label>Crew Size</label>
                        <input type="number" class="form-control calc-input" id="calcCrewSize" value="1" step="1" min="1" max="10">
                      </div>

                      <div class="form-group">
                        <label>Equipment Hours</label>
                        <input type="number" class="form-control calc-input" id="calcEquipHours" value="1.5" step="0.25" min="0">
                      </div>

                      <div class="form-group">
                        <label>Equipment Type</label>
                        <select class="form-control calc-input" id="calcEquipType">
                          <option value="">Loading...</option>
                        </select>
                      </div>

                      <div class="form-group">
                        <label>Materials Cost ($)</label>
                        <input type="number" class="form-control calc-input" id="calcMaterials" value="0" step="1" min="0">
                      </div>

                      <div class="form-group">
                        <label>Travel Time (hrs)</label>
                        <input type="number" class="form-control calc-input" id="calcTravel" value="0.5" step="0.25" min="0">
                      </div>

                      <button class="btn btn-primary btn-block" onclick="runCalculator()">Calculate</button>
                    </div>

                    <!-- Cost Breakdown -->
                    <div class="col-md-6">
                      <h5 class="mb-3">Cost Breakdown</h5>

                      <div class="mw-calc-box" id="calcBreakdown">
                        <div class="mw-calc-row">
                          <span id="calcLaborLabel">Labor</span>
                          <span id="calcLaborCost">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span id="calcEquipLabel">Equipment</span>
                          <span id="calcEquipCost">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span id="calcTravelLabel">Travel</span>
                          <span id="calcTravelCost">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>Materials</span>
                          <span id="calcMatCost">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>Subtotal</span>
                          <span id="calcSubtotal">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span id="calcOverheadLabel">+ Overhead</span>
                          <span id="calcOverheadCost">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span><strong>Total Cost</strong></span>
                          <span><strong id="calcTotalCost">$0.00</strong></span>
                        </div>
                      </div>

                      <h5 class="mt-4 mb-3">Recommended Pricing</h5>

                      <div class="mw-calc-box">
                        <div class="mw-calc-row">
                          <span>Cost</span>
                          <span id="calcPriceCost">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span id="calcProfitLabel">+ Profit</span>
                          <span id="calcProfitVal">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>= Price (before tax)</span>
                          <span id="calcPreTax">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span id="calcGstLabel">+ GST</span>
                          <span id="calcGstVal">$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span><strong>Customer Pays</strong></span>
                          <span><strong id="calcCustomerPays">$0.00</strong></span>
                        </div>
                      </div>

                      <div class="mw-profit-card" id="calcProfitCard">
                        <div class="mw-profit-label">Profit Per Job</div>
                        <div class="mw-profit-value" id="calcProfitPerJob">$0.00</div>
                        <div class="mw-profit-margin" id="calcProfitMarginDisplay">0% margin</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div><!-- /.tab-content -->

          <!-- Add/Edit Modal -->
          <div class="modal fade" id="factorModal" tabindex="-1" role="dialog" aria-labelledby="factorModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="factorModalLabel">Add Cost Factor</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <input type="hidden" id="factorId">
                  <input type="hidden" id="factorType">

                  <div class="form-group">
                    <label>Name *</label>
                    <input type="text" class="form-control" id="factorName" placeholder="e.g., Senior Foreman">
                  </div>

                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Rate *</label>
                        <div class="input-group">
                          <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                          <input type="number" class="form-control" id="factorRate" step="0.01" min="0">
                        </div>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Unit</label>
                        <select class="form-control" id="factorUnit">
                          <option value="per hour">per hour</option>
                          <option value="per day">per day</option>
                          <option value="per job">per job</option>
                          <option value="per yard">per yard</option>
                          <option value="per kg">per kg</option>
                          <option value="per bag">per bag</option>
                          <option value="per sqft">per sqft</option>
                          <option value="each">each</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <!-- Labor-specific: rate with burden -->
                  <div class="form-group" id="burdenGroup" style="display:none;">
                    <label>Rate With Benefits</label>
                    <div class="input-group">
                      <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                      <input type="number" class="form-control" id="factorBurden" step="0.01" min="0" placeholder="Including benefits/burden">
                    </div>
                    <small class="form-text text-muted">Total loaded rate including employer taxes, WCB, benefits</small>
                  </div>

                  <!-- Equipment-specific: equipment type -->
                  <div class="form-group" id="equipTypeGroup" style="display:none;">
                    <label>Equipment Type</label>
                    <select class="form-control" id="factorEquipType">
                      <option value="Equipment">Equipment</option>
                      <option value="Vehicle">Vehicle</option>
                      <option value="Tool">Tool</option>
                    </select>
                  </div>

                  <!-- Material-specific: supplier -->
                  <div class="form-group" id="supplierGroup" style="display:none;">
                    <label>Supplier</label>
                    <input type="text" class="form-control" id="factorSupplier" placeholder="e.g., Landscape Supply Co.">
                  </div>

                  <div class="form-group">
                    <label>Description</label>
                    <textarea class="form-control" id="factorDescription" rows="2" placeholder="Brief description"></textarea>
                  </div>

                  <div class="form-group">
                    <div class="custom-control custom-checkbox">
                      <input type="checkbox" class="custom-control-input" id="factorActive" checked>
                      <label class="custom-control-label" for="factorActive">Active</label>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-danger mr-auto" id="deleteBtn" style="display:none;" onclick="deleteFactor()">Delete</button>
                  <button type="button" class="btn btn-primary" onclick="saveFactor()">Save</button>
                </div>
              </div>
            </div>
          </div>

<script>
const CSRF = '<?php echo htmlspecialchars($csrfToken); ?>';
const API = 'api-cost-factors.php';

// State
let allFactors = [];
let overheadSettings = { overhead_percent: 20, profit_margin: 35, gst_rate: 5 };

// ── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    loadAllData();

    // Live-update overhead preview
    document.getElementById('overheadPercent').addEventListener('input', updateOverheadPreview);
    document.getElementById('profitMargin').addEventListener('input', updateOverheadPreview);
    document.getElementById('gstRate').addEventListener('input', updateOverheadPreview);

    // Auto-calculate on input change
    document.querySelectorAll('.calc-input').forEach(function(el) {
        el.addEventListener('input', runCalculator);
    });
});

function loadAllData() {
    Promise.all([
        fetch(API + '?action=list&inactive=1').then(function(r) { return r.json(); }),
        fetch(API + '?action=get-overhead').then(function(r) { return r.json(); })
    ]).then(function(results) {
        var factorsResult = results[0];
        var overheadResult = results[1];

        if (factorsResult.success) {
            allFactors = factorsResult.factors;
            if (allFactors.length === 0) {
                // Seed defaults on first load
                seedDefaults();
                return;
            }
            renderAllTables();
        }

        if (overheadResult.success) {
            overheadSettings = overheadResult.settings;
            document.getElementById('overheadPercent').value = overheadSettings.overhead_percent;
            document.getElementById('profitMargin').value = overheadSettings.profit_margin;
            document.getElementById('gstRate').value = overheadSettings.gst_rate;
            updateOverheadPreview();
        }

        populateCalculatorDropdowns();
    }).catch(function(err) {
        showAlert('Error loading data: ' + err.message, 'danger');
    });
}

function seedDefaults() {
    fetch(API + '?action=seed-defaults', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showAlert('Default cost factors have been created. You can edit them to match your actual rates.', 'success');
            loadAllData();
        }
    });
}

// ── Render Tables ─────────────────────────────────────────
function renderAllTables() {
    renderLaborTable();
    renderEquipmentTable();
    renderMaterialTable();
}

function renderLaborTable() {
    var tbody = document.getElementById('laborBody');
    var items = allFactors.filter(function(f) { return f.factor_type === 'labor'; });

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No labor rates configured. Click "+ Add Labor Rate" to get started.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(function(f) {
        var statusBadge = f.active === '1' || f.active === 1
            ? '<span class="badge mw-badge-active">Active</span>'
            : '<span class="badge badge-secondary">Inactive</span>';
        var burdenRate = f.rate_with_burden ? '$' + parseFloat(f.rate_with_burden).toFixed(2) + '/hr' : '-';

        return '<tr' + (f.active !== '1' && f.active !== 1 ? ' class="text-muted"' : '') + '>' +
            '<td><strong>' + esc(f.factor_name) + '</strong></td>' +
            '<td>$' + parseFloat(f.rate).toFixed(2) + '/hr</td>' +
            '<td>' + burdenRate + '</td>' +
            '<td>' + esc(f.description || '') + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><button class="btn btn-secondary btn-sm" onclick="editFactor(' + f.id + ')">Edit</button></td>' +
            '</tr>';
    }).join('');
}

function renderEquipmentTable() {
    var tbody = document.getElementById('equipmentBody');
    var items = allFactors.filter(function(f) { return f.factor_type === 'equipment'; });

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No equipment configured. Click "+ Add Equipment" to get started.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(function(f) {
        var statusBadge = f.active === '1' || f.active === 1
            ? '<span class="badge mw-badge-active">Active</span>'
            : '<span class="badge badge-secondary">Inactive</span>';
        var typeBadge = f.equipment_type === 'Vehicle'
            ? '<span class="badge mw-badge-vehicle">' + esc(f.equipment_type) + '</span>'
            : '<span class="badge mw-badge-equipment">' + esc(f.equipment_type || 'Equipment') + '</span>';

        return '<tr' + (f.active !== '1' && f.active !== 1 ? ' class="text-muted"' : '') + '>' +
            '<td><strong>' + esc(f.factor_name) + '</strong></td>' +
            '<td>$' + parseFloat(f.rate).toFixed(2) + '/hr</td>' +
            '<td>' + typeBadge + '</td>' +
            '<td>' + esc(f.description || '') + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><button class="btn btn-secondary btn-sm" onclick="editFactor(' + f.id + ')">Edit</button></td>' +
            '</tr>';
    }).join('');
}

function renderMaterialTable() {
    var tbody = document.getElementById('materialBody');
    var items = allFactors.filter(function(f) { return f.factor_type === 'material'; });

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No materials configured. Click "+ Add Material" to get started.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(function(f) {
        var statusBadge = f.active === '1' || f.active === 1
            ? '<span class="badge mw-badge-active">Active</span>'
            : '<span class="badge badge-secondary">Inactive</span>';
        var updated = f.updated_at ? new Date(f.updated_at).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' }) : '-';

        return '<tr' + (f.active !== '1' && f.active !== 1 ? ' class="text-muted"' : '') + '>' +
            '<td><strong>' + esc(f.factor_name) + '</strong></td>' +
            '<td>$' + parseFloat(f.rate).toFixed(2) + '</td>' +
            '<td>' + esc(f.unit || '') + '</td>' +
            '<td>' + esc(f.supplier || '-') + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><button class="btn btn-secondary btn-sm" onclick="editFactor(' + f.id + ')">Edit</button></td>' +
            '</tr>';
    }).join('');
}

// ── Modal ─────────────────────────────────────────────────
function openAddModal(type) {
    document.getElementById('factorId').value = '';
    document.getElementById('factorType').value = type;
    document.getElementById('factorName').value = '';
    document.getElementById('factorRate').value = '';
    document.getElementById('factorBurden').value = '';
    document.getElementById('factorUnit').value = type === 'material' ? 'per yard' : 'per hour';
    document.getElementById('factorEquipType').value = 'Equipment';
    document.getElementById('factorSupplier').value = '';
    document.getElementById('factorDescription').value = '';
    document.getElementById('factorActive').checked = true;
    document.getElementById('deleteBtn').style.display = 'none';

    var labels = { labor: 'Add Labor Rate', equipment: 'Add Equipment', material: 'Add Material' };
    document.getElementById('factorModalLabel').textContent = labels[type] || 'Add Cost Factor';

    toggleTypeFields(type);
    $('#factorModal').modal('show');
}

function editFactor(id) {
    var factor = allFactors.find(function(f) { return parseInt(f.id) === parseInt(id); });
    if (!factor) return;

    document.getElementById('factorId').value = factor.id;
    document.getElementById('factorType').value = factor.factor_type;
    document.getElementById('factorName').value = factor.factor_name;
    document.getElementById('factorRate').value = factor.rate;
    document.getElementById('factorBurden').value = factor.rate_with_burden || '';
    document.getElementById('factorUnit').value = factor.unit || 'per hour';
    document.getElementById('factorEquipType').value = factor.equipment_type || 'Equipment';
    document.getElementById('factorSupplier').value = factor.supplier || '';
    document.getElementById('factorDescription').value = factor.description || '';
    document.getElementById('factorActive').checked = factor.active === '1' || factor.active === 1;
    document.getElementById('deleteBtn').style.display = 'inline-block';

    var labels = { labor: 'Edit Labor Rate', equipment: 'Edit Equipment', material: 'Edit Material' };
    document.getElementById('factorModalLabel').textContent = labels[factor.factor_type] || 'Edit Cost Factor';

    toggleTypeFields(factor.factor_type);
    $('#factorModal').modal('show');
}

function toggleTypeFields(type) {
    document.getElementById('burdenGroup').style.display = type === 'labor' ? 'block' : 'none';
    document.getElementById('equipTypeGroup').style.display = type === 'equipment' ? 'block' : 'none';
    document.getElementById('supplierGroup').style.display = type === 'material' ? 'block' : 'none';
}

function saveFactor() {
    var name = document.getElementById('factorName').value.trim();
    var rate = document.getElementById('factorRate').value;

    if (!name) { alert('Name is required'); return; }
    if (!rate || parseFloat(rate) < 0) { alert('Valid rate is required'); return; }

    var payload = {
        csrf_token: CSRF,
        id: document.getElementById('factorId').value || null,
        factor_name: name,
        factor_type: document.getElementById('factorType').value,
        rate: parseFloat(rate),
        rate_with_burden: document.getElementById('factorBurden').value || null,
        unit: document.getElementById('factorUnit').value,
        equipment_type: document.getElementById('factorEquipType').value,
        supplier: document.getElementById('factorSupplier').value || null,
        description: document.getElementById('factorDescription').value || null,
        active: document.getElementById('factorActive').checked ? 1 : 0
    };

    fetch(API + '?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            $('#factorModal').modal('hide');
            showAlert(data.message, 'success');
            loadAllData();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(err) { alert('Network error: ' + err.message); });
}

function deleteFactor() {
    var id = document.getElementById('factorId').value;
    var name = document.getElementById('factorName').value;
    if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;

    fetch(API + '?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF, id: parseInt(id) })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            $('#factorModal').modal('hide');
            showAlert('Cost factor deleted', 'success');
            loadAllData();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(err) { alert('Network error: ' + err.message); });
}

// ── Overhead Settings ─────────────────────────────────────
function updateOverheadPreview() {
    var oh = parseFloat(document.getElementById('overheadPercent').value) || 0;
    var pm = parseFloat(document.getElementById('profitMargin').value) || 0;
    var gst = parseFloat(document.getElementById('gstRate').value) || 0;
    var base = 100;

    var overhead = base * (oh / 100);
    var totalCost = base + overhead;
    var profit = totalCost * (pm / 100);
    var selling = totalCost + profit;
    var tax = selling * (gst / 100);
    var final_ = selling + tax;

    document.getElementById('ohOverheadLabel').textContent = '+ Overhead (' + oh + '%)';
    document.getElementById('ohOverheadVal').textContent = '$' + overhead.toFixed(2);
    document.getElementById('ohTotalCost').textContent = '$' + totalCost.toFixed(2);
    document.getElementById('ohProfitLabel').textContent = '+ Profit (' + pm + '% of $' + totalCost.toFixed(0) + ')';
    document.getElementById('ohProfitVal').textContent = '$' + profit.toFixed(2);
    document.getElementById('ohSellingPrice').textContent = '$' + selling.toFixed(2);
    document.getElementById('ohGstLabel').textContent = '+ GST (' + gst + '%)';
    document.getElementById('ohGstVal').textContent = '$' + tax.toFixed(2);
    document.getElementById('ohFinalPrice').textContent = '$' + final_.toFixed(2);
}

function saveOverhead() {
    var payload = {
        csrf_token: CSRF,
        overhead_percent: parseFloat(document.getElementById('overheadPercent').value) || 0,
        profit_margin: parseFloat(document.getElementById('profitMargin').value) || 0,
        gst_rate: parseFloat(document.getElementById('gstRate').value) || 0
    };

    fetch(API + '?action=save-overhead', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            overheadSettings = {
                overhead_percent: payload.overhead_percent,
                profit_margin: payload.profit_margin,
                gst_rate: payload.gst_rate
            };
            showAlert('Overhead settings saved', 'success');
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(err) { alert('Network error: ' + err.message); });
}

// ── Calculator ────────────────────────────────────────────
function populateCalculatorDropdowns() {
    var laborSelect = document.getElementById('calcLaborType');
    var equipSelect = document.getElementById('calcEquipType');

    var laborItems = allFactors.filter(function(f) { return f.factor_type === 'labor' && (f.active === '1' || f.active === 1); });
    var equipItems = allFactors.filter(function(f) { return f.factor_type === 'equipment' && (f.active === '1' || f.active === 1); });

    laborSelect.innerHTML = laborItems.map(function(f) {
        return '<option value="' + f.id + '" data-rate="' + f.rate + '" data-burden="' + (f.rate_with_burden || f.rate) + '">' +
            esc(f.factor_name) + ' ($' + parseFloat(f.rate).toFixed(0) + '/hr)</option>';
    }).join('');

    equipSelect.innerHTML = '<option value="" data-rate="0">None</option>' + equipItems.map(function(f) {
        return '<option value="' + f.id + '" data-rate="' + f.rate + '">' +
            esc(f.factor_name) + ' ($' + parseFloat(f.rate).toFixed(0) + '/hr)</option>';
    }).join('');

    runCalculator();
}

function runCalculator() {
    var laborHours = parseFloat(document.getElementById('calcLaborHours').value) || 0;
    var crewSize = parseInt(document.getElementById('calcCrewSize').value) || 1;
    var equipHours = parseFloat(document.getElementById('calcEquipHours').value) || 0;
    var materialsCost = parseFloat(document.getElementById('calcMaterials').value) || 0;
    var travelHours = parseFloat(document.getElementById('calcTravel').value) || 0;

    var laborOpt = document.getElementById('calcLaborType').selectedOptions[0];
    var equipOpt = document.getElementById('calcEquipType').selectedOptions[0];

    var laborRate = laborOpt ? parseFloat(laborOpt.getAttribute('data-burden') || laborOpt.getAttribute('data-rate') || 0) : 0;
    var equipRate = equipOpt ? parseFloat(equipOpt.getAttribute('data-rate') || 0) : 0;

    var laborCost = laborHours * laborRate * crewSize;
    var equipCost = equipHours * equipRate;
    var travelCost = travelHours * laborRate * crewSize;

    var subtotal = laborCost + equipCost + travelCost + materialsCost;
    var oh = overheadSettings.overhead_percent || 20;
    var pm = overheadSettings.profit_margin || 35;
    var gst = overheadSettings.gst_rate || 5;

    var overheadCost = subtotal * (oh / 100);
    var totalCost = subtotal + overheadCost;
    var profit = totalCost * (pm / 100);
    var preTax = totalCost + profit;
    var taxAmt = preTax * (gst / 100);
    var customerPays = preTax + taxAmt;

    // Update breakdown
    var laborName = laborOpt ? laborOpt.textContent.split(' (')[0] : 'Labor';
    var equipName = equipOpt ? equipOpt.textContent.split(' (')[0] : 'Equipment';

    document.getElementById('calcLaborLabel').textContent = 'Labor (' + laborHours + 'h x ' + crewSize + ' crew x $' + laborRate.toFixed(0) + ')';
    document.getElementById('calcLaborCost').textContent = '$' + laborCost.toFixed(2);
    document.getElementById('calcEquipLabel').textContent = 'Equipment (' + equipHours + 'h x $' + equipRate.toFixed(0) + ')';
    document.getElementById('calcEquipCost').textContent = '$' + equipCost.toFixed(2);
    document.getElementById('calcTravelLabel').textContent = 'Travel (' + travelHours + 'h x $' + laborRate.toFixed(0) + ' x ' + crewSize + ')';
    document.getElementById('calcTravelCost').textContent = '$' + travelCost.toFixed(2);
    document.getElementById('calcMatCost').textContent = '$' + materialsCost.toFixed(2);
    document.getElementById('calcSubtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('calcOverheadLabel').textContent = '+ Overhead (' + oh + '%)';
    document.getElementById('calcOverheadCost').textContent = '$' + overheadCost.toFixed(2);
    document.getElementById('calcTotalCost').textContent = '$' + totalCost.toFixed(2);

    // Pricing
    document.getElementById('calcPriceCost').textContent = '$' + totalCost.toFixed(2);
    document.getElementById('calcProfitLabel').textContent = '+ Profit (' + pm + '%)';
    document.getElementById('calcProfitVal').textContent = '$' + profit.toFixed(2);
    document.getElementById('calcPreTax').textContent = '$' + preTax.toFixed(2);
    document.getElementById('calcGstLabel').textContent = '+ GST (' + gst + '%)';
    document.getElementById('calcGstVal').textContent = '$' + taxAmt.toFixed(2);
    document.getElementById('calcCustomerPays').textContent = '$' + customerPays.toFixed(2);

    document.getElementById('calcProfitPerJob').textContent = '$' + profit.toFixed(2);
    document.getElementById('calcProfitMarginDisplay').textContent = pm + '% margin';
}

// ── Helpers ───────────────────────────────────────────────
function esc(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function showAlert(msg, type) {
    var el = document.getElementById('pageAlert');
    el.className = 'alert alert-' + type + ' alert-dismissible fade show mb-3';
    el.innerHTML = msg + '<button type="button" class="close" data-dismiss="alert">&times;</button>';
    setTimeout(function() { $(el).alert('close'); }, 5000);
}

function fmt(n) {
    return '$' + parseFloat(n).toFixed(2);
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
