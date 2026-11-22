            <!-- Edit Company Form -->
            <form method="POST" id="editCompanyForm">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="update_company">
              <input type="hidden" name="company_id" value="<?php echo (int)$editCompany['id']; ?>">

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Edit Company</h5>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Company Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="company_name" required
                          value="<?php echo h($_POST['company_name'] ?? $editCompany['company_name'] ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Type</label>
                        <?php $ct = $_POST['company_type'] ?? $editCompany['company_type'] ?? 'individual'; ?>
                        <select class="form-control" name="company_type">
                          <option value="individual" <?php echo $ct === 'individual' ? 'selected' : ''; ?>>Individual</option>
                          <option value="business" <?php echo $ct === 'business' ? 'selected' : ''; ?>>Business</option>
                          <option value="strata" <?php echo $ct === 'strata' ? 'selected' : ''; ?>>Strata</option>
                          <option value="property_manager" <?php echo $ct === 'property_manager' ? 'selected' : ''; ?>>Property Manager</option>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Billing Email</label>
                        <input type="email" class="form-control" name="billing_email"
                          value="<?php echo h($_POST['billing_email'] ?? $editCompany['billing_email'] ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Billing Phone</label>
                        <input type="tel" class="form-control" name="billing_phone"
                          value="<?php echo h($_POST['billing_phone'] ?? $editCompany['billing_phone'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Account Status</label>
                        <?php $as = $_POST['account_status'] ?? $editCompany['account_status'] ?? 'active'; ?>
                        <select class="form-control" name="account_status">
                          <option value="active" <?php echo $as === 'active' ? 'selected' : ''; ?>>Active</option>
                          <option value="inactive" <?php echo $as === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                          <option value="suspended" <?php echo $as === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="form-group">
                        <label>Payment Terms</label>
                        <?php $pt = $_POST['payment_terms'] ?? $editCompany['payment_terms'] ?? 'Net 30'; ?>
                        <select class="form-control" name="payment_terms">
                          <option value="Due on receipt" <?php echo $pt === 'Due on receipt' ? 'selected' : ''; ?>>Due on receipt</option>
                          <option value="Net 15" <?php echo $pt === 'Net 15' ? 'selected' : ''; ?>>Net 15</option>
                          <option value="Net 30" <?php echo $pt === 'Net 30' ? 'selected' : ''; ?>>Net 30</option>
                          <option value="Net 60" <?php echo $pt === 'Net 60' ? 'selected' : ''; ?>>Net 60</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="form-group">
                        <label>Payment Method</label>
                        <?php $pm = $_POST['payment_method'] ?? $editCompany['payment_method'] ?? 'invoice'; ?>
                        <select class="form-control" name="payment_method">
                          <option value="invoice" <?php echo $pm === 'invoice' ? 'selected' : ''; ?>>Invoice</option>
                          <option value="credit_card" <?php echo $pm === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                          <option value="bank_transfer" <?php echo $pm === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                          <option value="cheque" <?php echo $pm === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                        </select>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="file-text"></i> Billing Address</h5>
                </div>
                <div class="card-body">
                  <div class="form-group">
                    <label>Address</label>
                    <input type="text" class="form-control" name="billing_address"
                      value="<?php echo h($_POST['billing_address'] ?? $editCompany['billing_address'] ?? ''); ?>">
                  </div>
                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>City</label>
                        <input type="text" class="form-control" name="billing_city"
                          value="<?php echo h($_POST['billing_city'] ?? $editCompany['billing_city'] ?? 'Vancouver'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Province</label>
                        <input type="text" class="form-control" name="billing_province" maxlength="2"
                          value="<?php echo h($_POST['billing_province'] ?? $editCompany['billing_province'] ?? 'BC'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Postal Code</label>
                        <input type="text" class="form-control" name="billing_postal_code"
                          value="<?php echo h($_POST['billing_postal_code'] ?? $editCompany['billing_postal_code'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="file-text"></i> Notes</h5>
                </div>
                <div class="card-body">
                  <div class="form-group mb-0">
                    <textarea class="form-control" name="notes" rows="3"><?php echo h($_POST['notes'] ?? $editCompany['notes'] ?? ''); ?></textarea>
                  </div>
                </div>
              </div>

              <div class="form-group mt-3">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i data-feather="save"></i> Update Company
                </button>
                <a href="?action=view_company&id=<?php echo (int)$editCompany['id']; ?>" class="btn btn-secondary btn-lg ml-2">
                  <i data-feather="x"></i> Cancel
                </a>
              </div>
            </form>

