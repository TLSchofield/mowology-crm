            <!-- New Contact Form -->
            <form method="POST" id="contactForm">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="save_client">

              <div class="row">
                <!-- Left Column: Contact Info + Property -->
                <div class="col-lg-7">
                  <!-- Card 1: Contact Information -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="user"></i> Contact Information</h5>
                    </div>
                    <div class="card-body">
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required
                              value="<?php echo h($_POST['first_name'] ?? ''); ?>"
                              placeholder="e.g. John">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" required
                              value="<?php echo h($_POST['last_name'] ?? ''); ?>"
                              placeholder="e.g. Smith">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email"
                              value="<?php echo h($_POST['email'] ?? ''); ?>"
                              placeholder="john@example.com">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" class="form-control" name="phone"
                              value="<?php echo h($_POST['phone'] ?? ''); ?>"
                              placeholder="604-555-1234"
                              oninput="mwValidatePhone(this)">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group mb-0">
                            <label>Cell / Mobile</label>
                            <input type="tel" class="form-control" name="mobile"
                              value="<?php echo h($_POST['mobile'] ?? ''); ?>"
                              placeholder="604-555-5678"
                              oninput="mwValidatePhone(this)">
                            <small class="form-text text-muted">Used for SMS notifications</small>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Card 2: Property Address -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="map-pin"></i> Property Address</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Street Address</label>
                        <input type="text" class="form-control" name="property_address" id="propertyAddress"
                          value="<?php echo h($_POST['property_address'] ?? ''); ?>"
                          placeholder="Start typing an address..." autocomplete="off">
                        <input type="hidden" name="property_latitude" id="propertyLatitude" value="<?php echo h($_POST['property_latitude'] ?? ''); ?>">
                        <input type="hidden" name="property_longitude" id="propertyLongitude" value="<?php echo h($_POST['property_longitude'] ?? ''); ?>">
                        <div id="propertyAddressDupeWarning" class="alert alert-warning mt-2" style="display:none; font-size: 0.85rem;"></div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" name="property_city" id="propertyCity"
                              value="<?php echo h($_POST['property_city'] ?? 'Vancouver'); ?>"
                              placeholder="Vancouver">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group mb-0">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" name="property_postal_code" id="propertyPostalCode"
                              value="<?php echo h($_POST['property_postal_code'] ?? ''); ?>"
                              placeholder="V5K 1A1">
                          </div>
                        </div>
                      </div>
                      <small class="form-text text-muted">Links this contact to a service property for scheduling and quoting.</small>
                    </div>
                  </div>
                </div>

                <!-- Right Column: Preferences + Notes + Company -->
                <div class="col-lg-5">
                  <!-- Communication Preferences -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Communication Preferences</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select class="form-control" name="preferred_contact_method">
                          <option value="phone" <?php echo ($_POST['preferred_contact_method'] ?? 'phone') === 'phone' ? 'selected' : ''; ?>>Phone</option>
                          <option value="email" <?php echo ($_POST['preferred_contact_method'] ?? '') === 'email' ? 'selected' : ''; ?>>Email</option>
                          <option value="text" <?php echo ($_POST['preferred_contact_method'] ?? '') === 'text' ? 'selected' : ''; ?>>Text / SMS</option>
                        </select>
                      </div>
                      <div class="mw-comm-prefs" style="grid-template-columns: 1fr;">
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveSms" name="receive_sms"
                            <?php echo isset($_POST['receive_sms']) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveSms">
                            OK to send SMS notifications
                            <small class="d-block text-muted">Job reminders, schedule changes, etc.</small>
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveMarketing" name="receive_marketing"
                            <?php echo isset($_POST['receive_marketing']) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveMarketing">
                            OK to send marketing emails
                            <small class="d-block text-muted">Seasonal offers, newsletters</small>
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="consentQuoteFollowup" name="consent_quote_followup"
                            <?php echo isset($_POST['consent_quote_followup']) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="consentQuoteFollowup">
                            Consent to quote follow-up
                            <small class="d-block text-muted">Allow follow-up after sending a quote</small>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Notes -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="file-text"></i> Notes</h5>
                    </div>
                    <div class="card-body">
                      <textarea class="form-control" name="notes" rows="3" placeholder="Any additional info..."><?php echo h($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                  </div>

                  <!-- Company (Optional) -->
                  <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Company</h5>
                      <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="linkCompanyToggle" name="link_company"
                          <?php echo isset($_POST['link_company']) ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="linkCompanyToggle">Link to a company</label>
                      </div>
                    </div>
                    <div class="card-body mw-company-section" id="companySection" style="display: none;">
                      <!-- Company mode: existing or new -->
                      <div class="row mb-3">
                        <div class="col-md-12">
                          <div class="btn-group btn-block" role="group">
                            <input type="radio" class="btn-check" name="company_mode" id="company_mode_existing" value="existing">
                            <label class="btn btn-outline-secondary" for="company_mode_existing">
                              Link to Existing Company
                            </label>
                            <input type="radio" class="btn-check" name="company_mode" id="company_mode_new" value="new" checked>
                            <label class="btn btn-outline-secondary" for="company_mode_new">
                              Create New Company
                            </label>
                          </div>
                        </div>
                      </div>

                      <!-- Existing Company Selection -->
                      <div id="existing-company-section" style="display: none;">
                        <div class="form-group">
                          <label>Select Company</label>
                          <select class="form-control" name="company_id" id="company_id">
                            <option value="">-- Select a company --</option>
                            <?php
                              $existingCompanies = $db->query("SELECT id, company_name, company_type FROM companies ORDER BY company_name")->fetchAll();
                              foreach ($existingCompanies as $comp): ?>
                              <option value="<?php echo (int)$comp['id']; ?>"
                                <?php echo (intval($_POST['company_id'] ?? 0) === (int)$comp['id']) ? 'selected' : ''; ?>>
                                <?php echo h($comp['company_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $comp['company_type'])); ?>)
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>

                      <!-- New Company Information -->
                      <div id="new-company-section">
                        <div class="form-group">
                          <label>Company Name</label>
                          <input type="text" class="form-control" name="company_name"
                            value="<?php echo h($_POST['company_name'] ?? ''); ?>"
                            placeholder="e.g. Smith Landscaping Ltd.">
                        </div>
                        <div class="form-group">
                          <label>Type</label>
                          <select class="form-control" name="company_type">
                            <option value="individual" <?php echo ($_POST['company_type'] ?? 'individual') === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="business" <?php echo ($_POST['company_type'] ?? '') === 'business' ? 'selected' : ''; ?>>Business</option>
                            <option value="strata" <?php echo ($_POST['company_type'] ?? '') === 'strata' ? 'selected' : ''; ?>>Strata</option>
                            <option value="property_manager" <?php echo ($_POST['company_type'] ?? '') === 'property_manager' ? 'selected' : ''; ?>>Property Manager</option>
                          </select>
                        </div>
                      </div>

                      <hr>

                      <!-- Billing / Account info -->
                      <h6 class="mb-3"><strong>Billing &amp; Account</strong></h6>
                      <div class="form-group">
                        <label>Billing Address</label>
                        <input type="text" class="form-control" name="billing_address" id="billingAddress"
                          value="<?php echo h($_POST['billing_address'] ?? ''); ?>"
                          placeholder="Start typing an address..." autocomplete="off">
                      </div>
                      <div class="row">
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" name="billing_city" id="billingCity"
                              value="<?php echo h($_POST['billing_city'] ?? 'Vancouver'); ?>">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Province</label>
                            <input type="text" class="form-control" name="billing_province" id="billingProvince" maxlength="2"
                              value="<?php echo h($_POST['billing_province'] ?? 'BC'); ?>">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" name="billing_postal_code" id="billingPostalCode"
                              value="<?php echo h($_POST['billing_postal_code'] ?? ''); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Account Status</label>
                            <select class="form-control" name="account_status">
                              <option value="active">Active</option>
                              <option value="inactive">Inactive</option>
                              <option value="suspended">Suspended</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Payment Terms</label>
                            <input type="text" class="form-control" name="payment_terms"
                              value="<?php echo h($_POST['payment_terms'] ?? 'Net 30'); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="prefAttachPdf" name="pref_attach_pdf" checked>
                        <label class="custom-control-label" for="prefAttachPdf">Attach PDF to quote emails</label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Submit -->
              <div class="form-group mt-1">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i data-feather="save"></i> Save Contact
                </button>
                <a href="clients_appstack.php" class="btn btn-secondary btn-lg ml-2">
                  <i data-feather="x"></i> Cancel
                </a>
              </div>
            </form>

