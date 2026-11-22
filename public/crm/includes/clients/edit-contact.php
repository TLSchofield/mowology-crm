            <!-- Edit Contact Form -->
            <form method="POST" id="editContactForm">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="update_contact">
              <input type="hidden" name="contact_id" value="<?php echo (int)$contact['id']; ?>">

              <div class="row">
                <!-- Left Column: Contact Info -->
                <div class="col-lg-7">
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="user"></i> Edit Contact</h5>
                    </div>
                    <div class="card-body">
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required
                              value="<?php echo h($_POST['first_name'] ?? $contact['first_name'] ?? ''); ?>">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" class="form-control" name="last_name"
                              value="<?php echo h($_POST['last_name'] ?? $contact['last_name'] ?? ''); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email"
                              value="<?php echo h($_POST['email'] ?? $contact['email'] ?? ''); ?>">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" class="form-control" name="phone"
                              value="<?php echo h($_POST['phone'] ?? $contact['phone'] ?? ''); ?>"
                              oninput="mwValidatePhone(this)">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group mb-0">
                            <label>Cell / Mobile</label>
                            <input type="tel" class="form-control" name="mobile"
                              value="<?php echo h($_POST['mobile'] ?? $contact['mobile'] ?? ''); ?>"
                              oninput="mwValidatePhone(this)">
                            <small class="form-text text-muted">Used for SMS notifications</small>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Right Column: Preferences & Notes -->
                <div class="col-lg-5">
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Communication Preferences</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select class="form-control" name="preferred_contact_method">
                          <?php $pref = $_POST['preferred_contact_method'] ?? $contact['preferred_contact_method'] ?? 'phone'; ?>
                          <option value="phone" <?php echo $pref === 'phone' ? 'selected' : ''; ?>>Phone</option>
                          <option value="email" <?php echo $pref === 'email' ? 'selected' : ''; ?>>Email</option>
                          <option value="text" <?php echo $pref === 'text' ? 'selected' : ''; ?>>Text / SMS</option>
                        </select>
                      </div>
                      <div class="mw-comm-prefs" style="grid-template-columns: 1fr;">
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveSms" name="receive_sms"
                            <?php echo (!empty($_POST['receive_sms']) || (!isset($_POST['action']) && !empty($contact['receive_sms']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveSms">
                            OK to send SMS notifications
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveMarketing" name="receive_marketing"
                            <?php echo (!empty($_POST['receive_marketing']) || (!isset($_POST['action']) && !empty($contact['receive_marketing']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveMarketing">
                            OK to send marketing emails
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="consentQuoteFollowup" name="consent_quote_followup"
                            <?php echo (!empty($_POST['consent_quote_followup']) || (!isset($_POST['action']) && !empty($contact['consent_quote_followup']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="consentQuoteFollowup">
                            Consent to quote follow-up
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="hasReviewed" name="has_reviewed"
                            <?php echo (!empty($_POST['has_reviewed']) || (!isset($_POST['action']) && !empty($contact['has_reviewed']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="hasReviewed">
                            Client has left a Google review
                            <small class="d-block text-muted">Permanently stops all future review request emails &amp; SMS</small>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Pipeline Stage -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="layers"></i> Pipeline Stage</h5>
                    </div>
                    <div class="card-body">
                      <?php
                        $storedStage   = (string)($contact['lifecycle_stage'] ?? $contact['prospect_status'] ?? 'prospect');
                        $currentStage  = $_POST['lifecycle_stage'] ?? $storedStage;
                        $editStages    = function_exists('getLifecycleStages') ? getLifecycleStages() : [];
                        $stagePinned   = array_key_exists('lifecycle_pinned', $contact) ? (int)$contact['lifecycle_pinned'] === 1 : null;
                        // Keep a stage the list doesn't offer, so saving the form can't silently change it.
                        if ($editStages && $storedStage !== '' && !in_array($storedStage, array_column($editStages, 'stage_key'), true)) {
                            $editStages[] = ['stage_key' => $storedStage, 'stage_label' => ucwords(str_replace('_', ' ', $storedStage))];
                        }
                      ?>
                      <input type="hidden" name="lifecycle_stage_was" value="<?php echo h($storedStage); ?>">
                      <div class="form-group mb-0">
                        <select class="form-control" name="lifecycle_stage" aria-describedby="mw-stage-pin-help">
                          <?php if (!empty($editStages)): ?>
                            <?php foreach ($editStages as $st): ?>
                              <option value="<?php echo h($st['stage_key']); ?>"
                                <?php echo ($currentStage === $st['stage_key']) ? 'selected' : ''; ?>>
                                <?php echo h($st['stage_label']); ?>
                              </option>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <option value="prospect"  <?php echo $currentStage === 'prospect'  ? 'selected' : ''; ?>>Prospect</option>
                            <option value="client"    <?php echo $currentStage === 'client'    ? 'selected' : ''; ?>>Client</option>
                            <option value="inactive"  <?php echo $currentStage === 'inactive'  ? 'selected' : ''; ?>>Inactive</option>
                          <?php endif; ?>
                        </select>
                      </div>
                      <?php if ($stagePinned === true): ?>
                        <div class="mw-stage-pin" id="mw-stage-pin-help">
                          <span class="mw-stage-pin-badge"><i data-feather="lock"></i> Set by hand</span>
                          <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="lifecycle_unpin" name="lifecycle_unpin" value="1">
                            <label class="custom-control-label" for="lifecycle_unpin">Unpin (let Sam manage it)</label>
                          </div>
                        </div>
                      <?php elseif ($stagePinned === false): ?>
                        <small class="form-text text-muted" id="mw-stage-pin-help">Managed by Sam from contracts, quotes and invoices. Changing it here pins it.</small>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="file-text"></i> Notes</h5>
                    </div>
                    <div class="card-body">
                      <textarea class="form-control" name="notes" rows="3"><?php echo h($_POST['notes'] ?? $contact['notes'] ?? ''); ?></textarea>
                    </div>
                  </div>

                  <!-- Role & Association -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Role &amp; Association</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Contact Role</label>
                        <?php $currentRole = $_POST['contact_role'] ?? $contact['contact_role'] ?? ''; ?>
                        <select class="form-control" name="contact_role">
                          <option value="">— No role assigned —</option>
                          <option value="property_manager" <?php echo $currentRole === 'property_manager' ? 'selected' : ''; ?>>Property Manager</option>
                          <option value="strata_rep"       <?php echo $currentRole === 'strata_rep'       ? 'selected' : ''; ?>>Strata Rep</option>
                          <option value="owner"            <?php echo $currentRole === 'owner'            ? 'selected' : ''; ?>>Owner</option>
                          <option value="billing_contact"  <?php echo $currentRole === 'billing_contact'  ? 'selected' : ''; ?>>Billing Contact</option>
                          <option value="site_supervisor"  <?php echo $currentRole === 'site_supervisor'  ? 'selected' : ''; ?>>Site Supervisor</option>
                          <option value="other"            <?php echo $currentRole === 'other'            ? 'selected' : ''; ?>>Other</option>
                        </select>
                      </div>
                      <div class="form-group mb-0">
                        <label>Employer / Company</label>
                        <?php $currentEmployerId = (int)($_POST['employer_company_id'] ?? $contact['employer_company_id'] ?? 0); ?>
                        <select class="form-control" name="employer_company_id">
                          <option value="0">— Not linked to a company —</option>
                          <?php foreach ($existingCompaniesForLink as $comp): ?>
                            <option value="<?php echo (int)$comp['id']; ?>"
                              <?php echo $currentEmployerId === (int)$comp['id'] ? 'selected' : ''; ?>>
                              <?php echo h($comp['company_name']); ?><?php echo $comp['company_type'] === 'property_manager' ? ' (PM)' : ''; ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">The company this person works for (e.g. their property management firm).</small>
                      </div>
                    </div>
                  </div>

                </div>
              </div>

              <div class="form-group mt-1">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i data-feather="save"></i> Update Contact
                </button>
                <a href="clients_appstack.php" class="btn btn-secondary btn-lg ml-2">
                  <i data-feather="x"></i> Cancel
                </a>
              </div>
            </form>

