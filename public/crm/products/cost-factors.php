<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Cost Factors';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="index.php" class="mw-back-link">&larr; Back to Products</a>

          <h1 class="h3 mb-1">Cost Factors Management</h1>
          <p class="text-muted mb-4">Configure labor rates, equipment costs, and overhead to calculate accurate product costs</p>

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
                  <button class="btn btn-primary btn-sm" onclick="openModal('addLabor')">+ Add Labor Rate</button>
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
                      <tbody>
                        <tr>
                          <td><strong>Owner/Manager</strong></td>
                          <td>$45.00/hr</td>
                          <td>$52.00/hr</td>
                          <td>Owner or manager hourly rate</td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm" onclick="editFactor(1)">Edit</button>
                          </td>
                        </tr>
                        <tr>
                          <td><strong>Foreman</strong></td>
                          <td>$35.00/hr</td>
                          <td>$40.50/hr</td>
                          <td>Lead crew member rate</td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm" onclick="editFactor(2)">Edit</button>
                          </td>
                        </tr>
                        <tr>
                          <td><strong>Laborer</strong></td>
                          <td>$25.00/hr</td>
                          <td>$29.00/hr</td>
                          <td>General laborer rate</td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm" onclick="editFactor(3)">Edit</button>
                          </td>
                        </tr>
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
                  <button class="btn btn-primary btn-sm" onclick="openModal('addEquipment')">+ Add Equipment</button>
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
                          <th>Status</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td><strong>Riding Mower</strong></td>
                          <td>$15.00/hr</td>
                          <td><span class="badge mw-badge-equipment">Equipment</span></td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm">Edit</button>
                          </td>
                        </tr>
                        <tr>
                          <td><strong>Walk-Behind Mower</strong></td>
                          <td>$8.00/hr</td>
                          <td><span class="badge mw-badge-equipment">Equipment</span></td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm">Edit</button>
                          </td>
                        </tr>
                        <tr>
                          <td><strong>Pickup Truck</strong></td>
                          <td>$12.00/hr</td>
                          <td><span class="badge mw-badge-vehicle">Vehicle</span></td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm">Edit</button>
                          </td>
                        </tr>
                        <tr>
                          <td><strong>Dump Truck</strong></td>
                          <td>$25.00/hr</td>
                          <td><span class="badge mw-badge-vehicle">Vehicle</span></td>
                          <td><span class="badge mw-badge-active">Active</span></td>
                          <td>
                            <button class="btn btn-secondary btn-sm">Edit</button>
                          </td>
                        </tr>
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
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>General Overhead %</label>
                        <input type="number" class="form-control" value="20" step="0.5" min="0" max="100">
                        <small class="form-text text-muted">Insurance, office, utilities, etc.</small>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Target Profit Margin %</label>
                        <input type="number" class="form-control" value="35" step="0.5" min="0" max="100">
                        <small class="form-text text-muted">Applied on top of cost + overhead</small>
                      </div>
                    </div>
                  </div>

                  <div class="mw-calc-box mt-4">
                    <h4>Pricing Example</h4>
                    <div class="mw-calc-row">
                      <span>Base Cost</span>
                      <span>$100.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>+ Overhead (20%)</span>
                      <span>$20.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>= Total Cost</span>
                      <span>$120.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>+ Profit (35% of $120)</span>
                      <span>$42.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>= Selling Price (before tax)</span>
                      <span>$162.00</span>
                    </div>
                    <div class="mw-calc-row">
                      <span>+ GST (5%)</span>
                      <span>$8.10</span>
                    </div>
                    <div class="mw-calc-row">
                      <span><strong>Final Customer Price</strong></span>
                      <span><strong>$170.10</strong></span>
                    </div>
                  </div>

                  <div class="mt-3">
                    <button class="btn btn-primary">Save Changes</button>
                    <button class="btn btn-secondary ml-2">Reset to Defaults</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- MATERIALS TAB -->
            <div class="tab-pane fade" id="materials" role="tabpanel">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Material Costs</h5>
                  <button class="btn btn-primary btn-sm">+ Add Material</button>
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
                          <th>Last Updated</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td><strong>Cedar Mulch</strong></td>
                          <td>$35.00</td>
                          <td>per yard</td>
                          <td>Landscape Supply Co.</td>
                          <td>Jan 15, 2026</td>
                          <td>
                            <button class="btn btn-secondary btn-sm">Edit</button>
                          </td>
                        </tr>
                        <tr>
                          <td><strong>Topsoil</strong></td>
                          <td>$25.00</td>
                          <td>per yard</td>
                          <td>Soil Depot</td>
                          <td>Jan 10, 2026</td>
                          <td>
                            <button class="btn btn-secondary btn-sm">Edit</button>
                          </td>
                        </tr>
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
                        <label>Service Type</label>
                        <select class="form-control">
                          <option>Weekly Lawn Maintenance</option>
                          <option>Mulch Installation</option>
                          <option>Spring Cleanup</option>
                          <option>Custom</option>
                        </select>
                      </div>

                      <div class="form-group">
                        <label>Labor Hours</label>
                        <input type="number" class="form-control" value="2" step="0.25" min="0">
                      </div>

                      <div class="form-group">
                        <label>Labor Type</label>
                        <select class="form-control">
                          <option>Laborer ($25/hr)</option>
                          <option>Foreman ($35/hr)</option>
                          <option>Owner ($45/hr)</option>
                        </select>
                      </div>

                      <div class="form-group">
                        <label>Equipment Hours</label>
                        <input type="number" class="form-control" value="1.5" step="0.25" min="0">
                      </div>

                      <div class="form-group">
                        <label>Equipment Type</label>
                        <select class="form-control">
                          <option>Walk-Behind Mower ($8/hr)</option>
                          <option>Riding Mower ($15/hr)</option>
                        </select>
                      </div>

                      <div class="form-group">
                        <label>Materials Cost</label>
                        <input type="number" class="form-control" value="0" step="1" min="0" placeholder="$">
                      </div>

                      <button class="btn btn-primary btn-block">Calculate</button>
                    </div>

                    <!-- Cost Breakdown -->
                    <div class="col-md-6">
                      <h5 class="mb-3">Cost Breakdown</h5>

                      <div class="mw-calc-box">
                        <div class="mw-calc-row">
                          <span>Labor (2 hrs @ $25)</span>
                          <span>$50.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>Equipment (1.5 hrs @ $8)</span>
                          <span>$12.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>Fuel &amp; Supplies</span>
                          <span>$3.50</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>Materials</span>
                          <span>$0.00</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>Subtotal</span>
                          <span>$65.50</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>+ Overhead (20%)</span>
                          <span>$13.10</span>
                        </div>
                        <div class="mw-calc-row">
                          <span><strong>Total Cost</strong></span>
                          <span><strong>$78.60</strong></span>
                        </div>
                      </div>

                      <h5 class="mt-4 mb-3">Recommended Pricing</h5>

                      <div class="mw-calc-box">
                        <div class="mw-calc-row">
                          <span>Cost</span>
                          <span>$78.60</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>+ Profit (35%)</span>
                          <span>$27.51</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>= Price (before tax)</span>
                          <span>$106.11</span>
                        </div>
                        <div class="mw-calc-row">
                          <span>+ GST (5%)</span>
                          <span>$5.31</span>
                        </div>
                        <div class="mw-calc-row">
                          <span><strong>Customer Pays</strong></span>
                          <span><strong>$111.42</strong></span>
                        </div>
                      </div>

                      <div class="mw-profit-card">
                        <div class="mw-profit-label">Profit Per Job</div>
                        <div class="mw-profit-value">$27.51</div>
                        <div class="mw-profit-margin">35% margin</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div><!-- /.tab-content -->

          <!-- Add/Edit Modal -->
          <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="addModalLabel">Add Labor Rate</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <div class="form-group">
                    <label>Position Name *</label>
                    <input type="text" class="form-control" placeholder="e.g., Senior Foreman">
                  </div>

                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Hourly Rate *</label>
                        <input type="number" class="form-control" step="0.01" min="0" placeholder="$">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Unit</label>
                        <select class="form-control">
                          <option>per hour</option>
                          <option>per day</option>
                          <option>per job</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="form-group">
                    <label>Description</label>
                    <textarea class="form-control" rows="3" placeholder="Brief description of this position"></textarea>
                  </div>

                  <div class="form-group">
                    <div class="custom-control custom-checkbox">
                      <input type="checkbox" class="custom-control-input" id="activeCheck" checked>
                      <label class="custom-control-label" for="activeCheck">Active</label>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-primary">Save</button>
                </div>
              </div>
            </div>
          </div>

<script>
// Modal functions
function openModal(type) {
    $('#addModal').modal('show');
}

function editFactor(id) {
    openModal('edit');
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
