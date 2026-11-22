            <!-- View Company Profile -->
            <?php
              $primaryContact = null;
              $primaryContactId = (int)($viewCompany['primary_contact_id'] ?? 0);
              foreach ($companyContacts as $cc) {
                  if ((int)$cc['id'] === $primaryContactId) { $primaryContact = $cc; break; }
              }
              $primaryName = $primaryContact
                  ? trim(h($primaryContact['first_name'] . ' ' . ($primaryContact['last_name'] ?? '')))
                  : '';
            ?>

            <!-- Header: Contact name prominent, company secondary -->
            <div class="mw-contact-header">
              <div>
                <?php if ($primaryName): ?>
                  <h3 class="mw-contact-name">
                    <i data-feather="user" style="width: 24px; height: 24px;"></i>
                    <?php echo $primaryName; ?>
                  </h3>
                  <div class="text-muted" style="font-size: 0.95rem;">
                    <i data-feather="briefcase" style="width: 16px; height: 16px;"></i>
                    <?php echo h($viewCompany['company_name']); ?>
                    <span class="badge badge-light ml-1"><?php echo ucwords(str_replace('_', ' ', $viewCompany['company_type'] ?? 'individual')); ?></span>
                  </div>
                <?php else: ?>
                  <h3 class="mw-contact-name">
                    <i data-feather="briefcase" style="width: 24px; height: 24px;"></i>
                    <?php echo h($viewCompany['company_name']); ?>
                  </h3>
                  <div class="text-muted" style="font-size: 0.95rem;">
                    <span class="badge badge-light"><?php echo ucwords(str_replace('_', ' ', $viewCompany['company_type'] ?? 'individual')); ?></span>
                    <span class="text-warning ml-2"><i data-feather="alert-circle" style="width: 14px; height: 14px;"></i> No contact linked</span>
                  </div>
                <?php endif; ?>
                <?php
                  $statusColor = ($viewCompany['account_status'] ?? 'active') === 'active' ? 'success' : (($viewCompany['account_status'] ?? '') === 'inactive' ? 'secondary' : 'danger');
                ?>
                <span class="badge badge-<?php echo $statusColor; ?> mt-1"><?php echo ucfirst(h($viewCompany['account_status'] ?? 'active')); ?></span>
              </div>
              <div class="mw-contact-actions">
                <a href="?action=edit_company&id=<?php echo (int)$viewCompany['id']; ?>" class="btn btn-primary">
                  <i data-feather="edit-2"></i> Edit
                </a>
                <a href="clients_appstack.php" class="btn btn-secondary">
                  <i data-feather="arrow-left"></i> Back
                </a>
              </div>
            </div>

            <div class="row">
              <!-- Left Column -->
              <div class="col-lg-7">

                <!-- Contacts Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0">
                      <i data-feather="users"></i> Property Managers
                      <span class="badge badge-primary ml-1"><?php echo count($companyContacts); ?></span>
                    </h5>
                  </div>
                  <div class="card-body">
                    <?php if (empty($companyContacts)): ?>
                      <div class="text-center text-muted py-3">
                        <i data-feather="user-plus" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">No contacts linked to this company yet.</p>
                      </div>
                    <?php else: ?>
                      <?php foreach ($companyContacts as $cc):
                          $ccName = trim(h($cc['first_name'] . ' ' . ($cc['last_name'] ?? '')));
                          $isPrimary = ((int)$cc['id'] === $primaryContactId);
                          $isBilling = ((int)$cc['id'] === (int)($viewCompany['billing_contact_id'] ?? 0));
                      ?>
                        <div class="d-flex align-items-center justify-content-between py-2 <?php echo $isPrimary ? '' : 'border-top'; ?>">
                          <div>
                            <a href="?action=view_contact&id=<?php echo (int)$cc['id']; ?>" class="font-weight-bold" style="color: var(--mw-green);">
                              <?php echo $ccName; ?>
                            </a>
                            <?php if ($isPrimary): ?>
                              <span class="badge badge-success ml-1" style="font-size: 0.65rem;">Primary</span>
                            <?php endif; ?>
                            <?php if ($isBilling && !$isPrimary): ?>
                              <span class="badge badge-info ml-1" style="font-size: 0.65rem;">Billing</span>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size: 0.85rem;">
                              <?php if (!empty($cc['email'])): ?>
                                <i data-feather="mail" style="width: 12px; height: 12px;"></i> <?php echo h($cc['email']); ?>
                              <?php endif; ?>
                              <?php if (!empty($cc['phone'])): ?>
                                <span class="ml-2"><i data-feather="phone" style="width: 12px; height: 12px;"></i> <?php echo formatPhone($cc['phone']); ?></span>
                              <?php endif; ?>
                              <?php if (!empty($cc['mobile'])): ?>
                                <span class="ml-2"><i data-feather="smartphone" style="width: 12px; height: 12px;"></i> <?php echo h($cc['mobile']); ?></span>
                              <?php endif; ?>
                            </div>
                          </div>
                          <a href="?action=view_contact&id=<?php echo (int)$cc['id']; ?>" class="btn btn-sm btn-outline-secondary">
                            <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Properties Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="map-pin"></i> Properties
                      <span class="badge badge-primary ml-1"><?php echo count($companyProperties); ?></span>
                    </h5>
                    <?php if (!empty($companyContacts)): ?>
                      <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#addCompanyPropertyModal">
                        <i data-feather="plus"></i> Add Property
                      </button>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <?php if (empty($companyProperties)): ?>
                      <div class="text-center text-muted py-3">
                        <i data-feather="map" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">No properties linked yet.</p>
                        <?php if (empty($companyContacts)): ?>
                          <small>Add a contact to this company first, then you can add properties.</small>
                        <?php else: ?>
                          <small>Add a property address to enable scheduling and quoting.</small>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <?php foreach ($companyProperties as $prop):
                          $compPropTags = $companyPropertyTagMap[(int)$prop['id']] ?? [];
                          $ownerName = $companyContactNameMap[(int)($prop['site_contact_id'] ?? 0)] ?? '';
                          $mData = $compMeasurementMap[(int)$prop['id']] ?? [];
                          $mCount = (int)($mData['measurement_count'] ?? 0);
                          $lawnSqft = floatval($mData['total_lawn_sqft'] ?? 0);
                          $hardSqft = floatval($mData['total_hard_surface_sqft'] ?? 0);
                          $hedgeFt = floatval($mData['total_hedge_linear_ft'] ?? 0);
                          $otherSqft = floatval($mData['total_other_sqft'] ?? 0);
                          $measuredAt = $mData['measurements_updated_at'] ?? null;
                      ?>
                        <div class="mw-contact-property-item" onclick="focusCompanyProperty(<?php echo (int)$prop['id']; ?>)">
                          <div style="flex: 1; min-width: 0;">
                            <div class="mw-contact-property-addr">
                              <i data-feather="home" style="width: 14px; height: 14px;"></i>
                              <?php echo h($prop['address']); ?>
                            </div>
                            <div class="mw-contact-property-meta">
                              <?php echo h($prop['city'] ?? ''); ?><?php echo !empty($prop['province']) ? ', ' . h($prop['province']) : ''; ?> <?php echo h($prop['postal_code'] ?? ''); ?>
                              <?php if ($ownerName): ?>
                                &middot; <i data-feather="user" style="width: 11px; height: 11px;"></i> <?php echo h($ownerName); ?>
                              <?php endif; ?>
                            </div>
                            <!-- Property Tags -->
                            <div class="mw-property-tags-row" id="compPropTags_<?php echo (int)$prop['id']; ?>" onclick="event.stopPropagation();">
                              <?php foreach ($compPropTags as $pTag): ?>
                                <span class="mw-property-tag" style="--tag-color: <?php echo h($pTag['tag_color']); ?>">
                                  <?php echo h($pTag['has_value'] && !empty($pTag['tag_value']) ? $pTag['tag_label'] . ': ' . $pTag['tag_value'] : $pTag['tag_label']); ?>
                                  <button type="button" class="mw-property-tag-remove" onclick="removeCompanyPropertyTag(<?php echo (int)$prop['id']; ?>, <?php echo (int)$pTag['entity_tag_id']; ?>, this)" title="Remove tag">&times;</button>
                                </span>
                              <?php endforeach; ?>
                              <button type="button" class="mw-property-tag-add-btn" onclick="showCompanyTagPicker(<?php echo (int)$prop['id']; ?>, this)" title="Add tag">
                                <i data-feather="plus" style="width: 10px; height: 10px;"></i>
                              </button>
                            </div>
                            <!-- Measurement Status -->
                            <div class="mw-property-measurement-status" onclick="event.stopPropagation();">
                              <?php if ($mCount > 0): ?>
                                <span class="mw-measurement-badge mw-measurement-done" title="<?php echo $mCount; ?> area(s) measured">
                                  <i data-feather="check-circle" style="width: 11px; height: 11px;"></i> Measured
                                </span>
                                <span class="mw-measurement-summary">
                                  <?php
                                    $parts = [];
                                    if ($lawnSqft > 0) $parts[] = number_format($lawnSqft) . ' sq ft lawn';
                                    if ($hardSqft > 0) $parts[] = number_format($hardSqft) . ' sq ft hard';
                                    if ($hedgeFt > 0) $parts[] = number_format($hedgeFt) . ' lin ft hedge';
                                    if ($otherSqft > 0) $parts[] = number_format($otherSqft) . ' sq ft other';
                                    echo h(implode(' · ', $parts) ?: $mCount . ' area(s)');
                                  ?>
                                </span>
                                <?php if ($measuredAt): ?>
                                  <span class="mw-measurement-date"><?php echo h(timeAgo($measuredAt)); ?></span>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="mw-measurement-badge mw-measurement-pending">
                                  <i data-feather="alert-circle" style="width: 11px; height: 11px;"></i> Not measured
                                </span>
                              <?php endif; ?>
                            </div>
                            <!-- Quick Actions -->
                            <div class="mw-property-quick-actions" onclick="event.stopPropagation();">
                              <a href="quote-workflow.php?contact_id=<?php echo (int)($prop['site_contact_id'] ?? 0); ?>&property_id=<?php echo (int)$prop['id']; ?>" class="mw-prop-action-btn mw-prop-action-primary" title="Measure, quote & auto-fill pricing">
                                <i data-feather="file-text" style="width: 11px; height: 11px;"></i> Quote &amp; Measure
                              </a>
                              <a href="jobs/create.php?contact_id=<?php echo (int)($prop['site_contact_id'] ?? 0); ?>&property_id=<?php echo (int)$prop['id']; ?>" class="mw-prop-action-btn" title="Create job plan for this property">
                                <i data-feather="clipboard" style="width: 11px; height: 11px;"></i> Create Plan
                              </a>
                              <?php if (floatval($prop['latitude'] ?? 0) != 0 && floatval($prop['longitude'] ?? 0) != 0 && (int)($prop['has_arrival_border'] ?? 0) === 0): ?>
                              <a href="jobs/zone-editor.php?property_id=<?php echo (int)$prop['id']; ?>&return_to=<?php echo urlencode('clients_appstack.php?action=view_company&id=' . (int)$clientId); ?>"
                                 class="mw-prop-action-btn mw-prop-action-border-missing" title="Draw arrival border for accurate auto-clock-in">
                                <i data-feather="alert-triangle" style="width: 11px; height: 11px;"></i> No Arrival Border
                              </a>
                              <?php endif; ?>
                            </div>
                          </div>
                          <div class="d-flex align-items-center" onclick="event.stopPropagation();">
                            <?php if (floatval($prop['latitude'] ?? 0) == 0 || floatval($prop['longitude'] ?? 0) == 0): ?>
                              <button type="button" class="btn btn-sm btn-outline-secondary mr-1" onclick="geocodeCompanyProperty(<?php echo (int)$prop['id']; ?>, this)" title="Geocode this address">
                                <i data-feather="crosshair" style="width: 12px; height: 12px;"></i>
                              </button>
                            <?php else: ?>
                              <span class="text-success mr-1" title="Geocoded"><i data-feather="check-circle" style="width: 14px; height: 14px;"></i></span>
                            <?php endif; ?>
                            <button type="button" class="mw-property-unlink-btn" onclick="showUnlinkCompanyProperty(<?php echo (int)$prop['id']; ?>, '<?php echo addslashes(h($prop['address'])); ?>', <?php echo (int)($prop['site_contact_id'] ?? 0); ?>)" title="Remove or reassign this property">
                              <i data-feather="x-circle" style="width: 14px; height: 14px;"></i>
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

              </div><!-- end left col -->

              <!-- Right Column -->
              <div class="col-lg-5">

                <!-- Company Details Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="briefcase"></i> <?php echo h($viewCompany['company_name']); ?></h5>
                  </div>
                  <div class="card-body">
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Type</div>
                      <div class="col-sm-8"><?php echo ucwords(str_replace('_', ' ', $viewCompany['company_type'] ?? 'individual')); ?></div>
                    </div>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Status</div>
                      <div class="col-sm-8">
                        <span class="badge badge-<?php echo $statusColor; ?>"><?php echo ucfirst(h($viewCompany['account_status'] ?? 'active')); ?></span>
                      </div>
                    </div>
                    <div class="mw-contact-field mt-2 mb-1">
                      <?php if (!empty($viewCompany['billing_email'])): ?>
                        <a href="mailto:<?php echo h($viewCompany['billing_email']); ?>" class="mw-icon-box" title="Send email">
                          <i data-feather="mail"></i>
                        </a>
                        <div class="mw-contact-field-body">
                          <a href="mailto:<?php echo h($viewCompany['billing_email']); ?>"><?php echo h($viewCompany['billing_email']); ?></a>
                        </div>
                      <?php else: ?>
                        <span class="mw-icon-box mw-icon-box--missing" title="No billing email">
                          <i data-feather="mail"></i>
                        </span>
                        <div class="mw-contact-field-body mw-contact-field-missing">No billing email</div>
                      <?php endif; ?>
                    </div>
                    <div class="mw-contact-field mb-2">
                      <?php if (!empty($viewCompany['billing_phone'])): ?>
                        <a href="tel:<?php echo h($viewCompany['billing_phone']); ?>" class="mw-icon-box" title="Call">
                          <i data-feather="phone"></i>
                        </a>
                        <div class="mw-contact-field-body">
                          <a href="tel:<?php echo h($viewCompany['billing_phone']); ?>"><?php echo formatPhone($viewCompany['billing_phone']); ?></a>
                        </div>
                      <?php else: ?>
                        <span class="mw-icon-box mw-icon-box--missing" title="No billing phone">
                          <i data-feather="phone"></i>
                        </span>
                        <div class="mw-contact-field-body mw-contact-field-missing">No billing phone</div>
                      <?php endif; ?>
                    </div>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Payment</div>
                      <div class="col-sm-8"><?php echo h($viewCompany['payment_terms'] ?? 'Net 30'); ?> &middot; <?php echo ucwords(str_replace('_', ' ', $viewCompany['payment_method'] ?? 'invoice')); ?></div>
                    </div>
                    <?php if (!empty($viewCompany['notes'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Notes</div>
                      <div class="col-sm-8"><span class="text-muted"><?php echo nl2br(h($viewCompany['notes'])); ?></span></div>
                    </div>
                    <?php endif; ?>
                    <div class="row mb-0">
                      <div class="col-sm-4 text-muted">Created</div>
                      <div class="col-sm-8"><?php echo formatDate($viewCompany['created_at']); ?></div>
                    </div>
                  </div>
                </div>

                <!-- Property Map Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="map"></i> Property Map</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (!empty($companyProperties) && $compGeocodedCount > 0): ?>
                      <div id="companyMapContainer" class="mw-contact-map-container"></div>
                      <?php if ($compUngeocodedCount > 0): ?>
                        <div class="text-center py-2 border-top">
                          <small class="text-muted"><?php echo $compUngeocodedCount; ?> propert<?php echo $compUngeocodedCount === 1 ? 'y' : 'ies'; ?> not yet geocoded</small>
                          <button type="button" class="btn btn-sm btn-outline-secondary ml-2" onclick="geocodeAllCompanyProperties()">
                            <i data-feather="crosshair" style="width: 12px; height: 12px;"></i> Geocode All
                          </button>
                        </div>
                      <?php endif; ?>
                    <?php elseif (!empty($companyProperties)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="map" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">Properties not yet geocoded.</p>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="geocodeAllCompanyProperties()">
                          <i data-feather="crosshair" style="width: 12px; height: 12px;"></i> Geocode All
                        </button>
                      </div>
                    <?php else: ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="map" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">Add properties to see them on the map.</p>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Billing Address Card -->
                <?php
                  $hasBillingAddr = !empty($viewCompany['billing_address']) || !empty($viewCompany['billing_city']);
                ?>
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="file-text"></i> Billing Address</h5>
                  </div>
                  <div class="card-body">
                    <?php if ($hasBillingAddr): ?>
                      <div><?php echo h($viewCompany['billing_address'] ?? ''); ?></div>
                      <div>
                        <?php echo h($viewCompany['billing_city'] ?? ''); ?><?php echo !empty($viewCompany['billing_province']) ? ', ' . h($viewCompany['billing_province']) : ''; ?>
                        <?php echo h($viewCompany['billing_postal_code'] ?? ''); ?>
                      </div>
                    <?php else: ?>
                      <span class="text-muted">No billing address on file</span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Also pays as (Penny, payer_aliases 1362) -->
                <?php
                  $payerAliasName = (string)($viewCompany['company_name'] ?? '');
                  $payerAliasContactId = null;
                  $payerAliasCompanyId = (int)$viewCompany['id'];
                  include dirname(__DIR__, 2) . '/includes/payer-aliases-card.php';
                ?>

                <!-- Quotes Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="file-text"></i> Quotes
                      <?php if (!empty($companyQuotes)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($companyQuotes); ?></span>
                      <?php endif; ?>
                    </h5>
                    <?php if (!empty($companyProperties) && !empty($companyContacts)): ?>
                      <a href="quote-workflow.php?contact_id=<?php echo (int)($viewCompany['primary_contact_id'] ?? $companyContacts[0]['id'] ?? 0); ?>&property_id=<?php echo (int)$companyProperties[0]['id']; ?>" class="btn btn-sm btn-success">
                        <i data-feather="plus"></i> New Quote
                      </a>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($companyQuotes)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="file-text" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No quotes yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Quote</th><th>Status</th><th class="text-right">Amount</th><th>Date</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($companyQuotes as $q): ?>
                              <tr onclick="window.location='quotes/view.php?id=<?php echo (int)$q['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($q['quote_number']); ?></strong>
                                  <?php if (!empty($q['title'])): ?>
                                    <br><small class="text-muted"><?php echo h($q['title']); ?></small>
                                  <?php endif; ?>
                                  <?php if (!empty($q['property_address'])): ?>
                                    <br><small class="text-muted"><i data-feather="map-pin" style="width: 10px; height: 10px;"></i> <?php echo h($q['property_address']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($q['status'], 'quote'); ?></td>
                                <td class="text-right"><?php echo formatCurrency($q['total_amount'] ?? 0); ?></td>
                                <td class="text-muted small"><?php echo formatDate($q['created_at']); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Job Plans Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="clipboard"></i> Job Plans
                      <?php if (!empty($companyPlans)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($companyPlans); ?></span>
                      <?php endif; ?>
                    </h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($companyPlans)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="clipboard" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No job plans yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Plan</th><th>Status</th><th>Visits</th><th class="text-right">$/Visit</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($companyPlans as $pl): ?>
                              <tr onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($pl['plan_number']); ?></strong>
                                  <br><small class="text-muted">
                                    <?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?>
                                    <?php if ($pl['is_recurring']): ?>
                                      &middot; <?php echo ucfirst($pl['recurrence_pattern'] ?? 'recurring'); ?>
                                    <?php endif; ?>
                                  </small>
                                  <?php if (!empty($pl['property_address'])): ?>
                                    <br><small class="text-muted"><i data-feather="map-pin" style="width: 10px; height: 10px;"></i> <?php echo h($pl['property_address']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($pl['status'], 'plan'); ?></td>
                                <td>
                                  <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                    <?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?>
                                  </span>
                                  <?php if (!empty($pl['last_visit_date'])): ?>
                                    <br><small class="text-muted">Last: <?php echo formatDate($pl['last_visit_date']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td class="text-right"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

              </div><!-- end right col -->
            </div><!-- end row -->

            <!-- Add Property Modal (Company View) -->
            <div class="modal fade" id="addCompanyPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: var(--mw-green); color: #fff;">
                    <h5 class="modal-title"><i data-feather="plus-circle" style="width: 18px; height: 18px;"></i> Add Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <form onsubmit="addPropertyToCompany(event)">
                    <div class="modal-body">
                      <div class="form-group">
                        <label>Street Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="compPropAddress" required placeholder="Start typing an address..." autocomplete="off">
                        <div id="compPropAddressDupeWarning" class="alert alert-warning mt-2 small" style="display:none;"></div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" id="compPropCity" value="Vancouver">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" id="compPropPostalCode" placeholder="V5K 1A1">
                          </div>
                        </div>
                      </div>
                      <input type="hidden" id="compPropLat">
                      <input type="hidden" id="compPropLng">
                      <div class="form-group mb-0">
                        <label>Property Name <small class="text-muted">(optional, auto-generated if blank)</small></label>
                        <input type="text" class="form-control" id="compPropName" placeholder="e.g. Main Office">
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-success" id="addCompPropBtn">
                        <i data-feather="plus"></i> Add Property
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Unlink/Reassign Property Modal (Company View) -->
            <div class="modal fade" id="unlinkCompanyPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: #dc3545; color: #fff;">
                    <h5 class="modal-title"><i data-feather="x-circle" style="width: 18px; height: 18px;"></i> Remove Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body">
                    <p>Remove <strong id="unlinkCompPropAddress"></strong> from this company?</p>
                    <div class="form-group">
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkCompAction" value="unlink" checked> Unlink only <small class="text-muted">(property keeps its data but has no contact)</small>
                      </label>
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkCompAction" value="reassign"> Reassign to another contact
                      </label>
                    </div>
                    <div id="reassignCompContactRow" style="display: none;">
                      <label>Select contact:</label>
                      <select class="form-control" id="reassignCompContactSelect">
                        <option value="">-- Choose a contact --</option>
                        <?php foreach ($companyOtherContacts as $oc): ?>
                          <option value="<?php echo (int)$oc['id']; ?>">
                            <?php echo h($oc['first_name'] . ' ' . $oc['last_name']); ?><?php echo !empty($oc['email']) ? ' (' . h($oc['email']) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <input type="hidden" id="unlinkCompPropertyId" value="">
                    <input type="hidden" id="unlinkCompCurrentContactId" value="">
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmUnlinkCompBtn" onclick="unlinkCompanyProperty()">
                      <i data-feather="check"></i> Confirm
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <script>
            (function() {
              var COMPANY_ID = <?php echo (int)$viewCompany['id']; ?>;
              var CSRF_TOKEN_CO = '<?php echo csrf_token(); ?>';
              var companyPropertiesData = <?php echo json_encode($companyProperties); ?>;
              var companyAvailableTags = <?php echo json_encode($companyAvailableTags); ?>;
              var compGmap = null;
              var compMarkers = {};
              var compActiveTagPicker = null;

              // ── Utility ─────────────────────────────────────────────
              function escHtmlCo(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
              }

              // ── Google Places autocomplete ──────────────────────────
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof initAddressAutocomplete === 'function') {
                  initAddressAutocomplete('compPropAddress', 'compPropCity', 'compPropPostalCode', null, 'compPropLat', 'compPropLng');
                }
              });

              // ── Add Property ────────────────────────────────────────
              window.addPropertyToCompany = function(event) {
                event.preventDefault();
                var address = document.getElementById('compPropAddress').value.trim();
                if (!address) { alert('Please enter a street address'); return; }

                var btn = document.getElementById('addCompPropBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

                fetch('clients_appstack.php?action=add_property_to_company', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    company_id: COMPANY_ID,
                    address: address,
                    city: document.getElementById('compPropCity').value.trim() || 'Vancouver',
                    postal_code: document.getElementById('compPropPostalCode').value.trim(),
                    property_name: document.getElementById('compPropName').value.trim(),
                    latitude: document.getElementById('compPropLat').value || '',
                    longitude: document.getElementById('compPropLng').value || '',
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                    feather.replace();
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                  feather.replace();
                });
              };

              // ── Map ─────────────────────────────────────────────────
              function initCompanyMap() {
                var mapEl = document.getElementById('companyMapContainer');
                if (!mapEl) return;
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') return;

                var bounds = new google.maps.LatLngBounds();
                var hasMarkers = false;

                compGmap = new google.maps.Map(mapEl, {
                  gestureHandling: 'greedy',
                  zoom: 12,
                  center: { lat: 49.2827, lng: -123.1207 },
                  mapTypeId: google.maps.MapTypeId.ROADMAP,
                  mapTypeControl: false,
                  streetViewControl: false
                });

                companyPropertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: compGmap,
                    title: prop.address
                  });

                  var infoContent = '<div style="max-width: 250px;">' +
                    '<strong>' + escHtmlCo(prop.address) + '</strong><br>' +
                    '<small>' + escHtmlCo((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() { infoWindow.open(compGmap, marker); });

                  compMarkers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  compGmap.fitBounds(bounds);
                  if (Object.keys(compMarkers).length === 1) {
                    google.maps.event.addListenerOnce(compGmap, 'bounds_changed', function() {
                      compGmap.setZoom(15);
                    });
                  }
                }
              }

              window.focusCompanyProperty = function(propertyId) {
                if (!compGmap || !compMarkers[propertyId]) return;
                var marker = compMarkers[propertyId];
                compGmap.panTo(marker.getPosition());
                compGmap.setZoom(16);
                google.maps.event.trigger(marker, 'click');
              };

              // Init map on DOMContentLoaded
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                  initCompanyMap();
                } else {
                  var attempts = 0;
                  var interval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                      clearInterval(interval);
                      initCompanyMap();
                    }
                    if (attempts > 50) clearInterval(interval);
                  }, 200);
                }
              });

              // ── Geocoding ───────────────────────────────────────────
              var compGeocoder = null;

              function geocodeCompanyPropertyAjax(propertyId) {
                if (!compGeocoder) compGeocoder = new google.maps.Geocoder();
                var prop = companyPropertiesData.find(function(p) { return p.id == propertyId; });
                if (!prop) return Promise.reject(new Error('Property not found'));

                var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
                var address = parts.join(', ');

                return new Promise(function(resolve, reject) {
                  compGeocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                      var loc = results[0].geometry.location;
                      fetch('clients_appstack.php?action=save_property_coords', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                          property_id: propertyId,
                          lat: loc.lat(),
                          lng: loc.lng(),
                          csrf_token: CSRF_TOKEN_CO
                        })
                      })
                      .then(function(r) { return r.json(); })
                      .then(function(data) { resolve(data); })
                      .catch(function(err) { reject(err); });
                    } else {
                      resolve({ success: false, error: 'Geocode failed: ' + status });
                    }
                  });
                });
              }

              window.geocodeCompanyProperty = function(propertyId, btn) {
                if (btn) {
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                }
                geocodeCompanyPropertyAjax(propertyId)
                  .then(function(data) {
                    if (data.success) {
                      location.reload();
                    } else {
                      alert('Couldn\'t find this address on the map. ' + (data.error || 'Check the address and try again.'));
                      if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" class="mw-icon-xs"></i>'; feather.replace(); }
                    }
                  })
                  .catch(function(err) {
                    alert('Error: ' + err.message);
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" class="mw-icon-xs"></i>'; feather.replace(); }
                  });
              };

              window.geocodeAllCompanyProperties = function() {
                var toGeocode = companyPropertiesData.filter(function(p) { return !parseFloat(p.latitude) || !parseFloat(p.longitude); });
                if (toGeocode.length === 0) return;

                var chain = Promise.resolve();
                toGeocode.forEach(function(p) {
                  chain = chain.then(function() {
                    return geocodeCompanyPropertyAjax(p.id);
                  }).then(function() {
                    return new Promise(function(resolve) { setTimeout(resolve, 300); });
                  });
                });
                chain.then(function() { location.reload(); });
              };

              // ── Property Tags ───────────────────────────────────────
              window.showCompanyTagPicker = function(propertyId, btn) {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }

                var picker = document.createElement('div');
                picker.className = 'mw-tag-picker-dropdown';
                picker.onclick = function(e) { e.stopPropagation(); };

                var html = '<div class="mw-tag-picker-inner">';
                html += '<select class="mw-tag-picker-select" id="compTagSelect_' + propertyId + '" onchange="onCompanyTagSelectChange(' + propertyId + ')">';
                html += '<option value="">Select tag…</option>';
                for (var i = 0; i < companyAvailableTags.length; i++) {
                  html += '<option value="' + companyAvailableTags[i].tag_id + '" data-has-value="' + companyAvailableTags[i].has_value + '">' +
                          escHtmlCo(companyAvailableTags[i].tag_label) + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="mw-tag-picker-value" id="compTagValue_' + propertyId + '" placeholder="Value…" style="display:none;">';
                html += '<button type="button" class="mw-tag-picker-save" onclick="applyCompanyPropertyTag(' + propertyId + ')">Save</button>';
                html += '<button type="button" class="mw-tag-picker-cancel" onclick="closeCompanyTagPicker()">&times;</button>';
                html += '</div>';

                picker.innerHTML = html;
                var tagsRow = document.getElementById('compPropTags_' + propertyId);
                tagsRow.appendChild(picker);
                compActiveTagPicker = picker;
              };

              window.onCompanyTagSelectChange = function(propertyId) {
                var sel = document.getElementById('compTagSelect_' + propertyId);
                var valInput = document.getElementById('compTagValue_' + propertyId);
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-has-value') === '1') {
                  valInput.style.display = '';
                  valInput.focus();
                } else {
                  valInput.style.display = 'none';
                  valInput.value = '';
                }
              };

              window.closeCompanyTagPicker = function() {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }
              };

              window.applyCompanyPropertyTag = function(propertyId) {
                var sel = document.getElementById('compTagSelect_' + propertyId);
                var valInput = document.getElementById('compTagValue_' + propertyId);
                var tagId = parseInt(sel.value);
                if (!tagId) { alert('Please select a tag'); return; }

                var tagValue = valInput.value.trim() || null;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'apply',
                    entity_type: 'property',
                    entity_id: propertyId,
                    tag_id: tagId,
                    tag_value: tagValue,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                  }
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              window.removeCompanyPropertyTag = function(propertyId, entityTagId, btn) {
                if (!confirm('Remove this tag?')) return;
                btn.disabled = true;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'remove',
                    entity_tag_id: entityTagId,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    var tagBadge = btn.parentElement;
                    tagBadge.remove();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                });
              };

              // Close tag picker on outside click
              document.addEventListener('click', function() {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }
              });

              // ── Unlink / Reassign Property ──────────────────────────
              window.showUnlinkCompanyProperty = function(propertyId, address, currentContactId) {
                document.getElementById('unlinkCompPropertyId').value = propertyId;
                document.getElementById('unlinkCompCurrentContactId').value = currentContactId;
                document.getElementById('unlinkCompPropAddress').textContent = address;
                document.getElementById('reassignCompContactSelect').value = '';
                document.querySelector('input[name="unlinkCompAction"][value="unlink"]').checked = true;
                document.getElementById('reassignCompContactRow').style.display = 'none';
                $('#unlinkCompanyPropertyModal').modal('show');
              };

              // Toggle reassign dropdown visibility
              document.querySelectorAll('input[name="unlinkCompAction"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                  document.getElementById('reassignCompContactRow').style.display =
                    this.value === 'reassign' ? 'block' : 'none';
                });
              });

              window.unlinkCompanyProperty = function() {
                var propertyId = parseInt(document.getElementById('unlinkCompPropertyId').value);
                var currentContactId = parseInt(document.getElementById('unlinkCompCurrentContactId').value);
                var action = document.querySelector('input[name="unlinkCompAction"]:checked').value;
                var newContactId = 0;

                if (action === 'reassign') {
                  newContactId = parseInt(document.getElementById('reassignCompContactSelect').value);
                  if (!newContactId) {
                    alert('Please select a contact to reassign to.');
                    return;
                  }
                }

                var btn = document.getElementById('confirmUnlinkCompBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';

                fetch('clients_appstack.php?action=unlink_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    property_id: propertyId,
                    current_contact_id: currentContactId,
                    new_contact_id: newContactId,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="check"></i> Confirm';
                    feather.replace();
                  }
                })
                .catch(function() {
                  alert('Couldn\'t reach the server. Check your connection and try again.');
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="check"></i> Confirm';
                  feather.replace();
                });
              };

            })();
            </script>

