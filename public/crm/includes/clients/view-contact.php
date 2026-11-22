            <!-- View Contact Profile -->
            <?php
              $contactName = trim(h($viewContact['first_name'] . ' ' . ($viewContact['last_name'] ?? '')));
            ?>

            <!-- Header -->
            <?php
              $stageKey   = $viewContact['lifecycle_stage'] ?? $viewContact['prospect_status'] ?? 'prospect';
              $stageLabel = ucfirst($stageKey);
              $stageColor = '#6B7280';
              try {
                  $vsStages = getLifecycleStages();
                  foreach ($vsStages as $vs) {
                      if ($vs['stage_key'] === $stageKey) {
                          $stageLabel = $vs['stage_label'];
                          $stageColor = $vs['stage_color'];
                          break;
                      }
                  }
              } catch (Exception $ignore) {
                  $fallbackColors = ['prospect' => '#3B82F6', 'client' => '#2D8659', 'inactive' => '#6B7280'];
                  $stageColor = $fallbackColors[$stageKey] ?? '#6B7280';
              }
              // Initials for avatar
              $initials = strtoupper(substr($viewContact['first_name'] ?? 'C', 0, 1) . substr($viewContact['last_name'] ?? '', 0, 1));
            ?>
            <div class="mw-contact-header">
              <div class="mw-contact-name-wrap">
                <div class="mw-contact-avatar"><?php echo h($initials); ?></div>
                <div>
                  <h3 class="mw-contact-name"><?php echo $contactName; ?></h3>
                  <span class="mw-contact-stage-badge" style="background: <?php echo h($stageColor); ?>;">
                    <?php echo h($stageLabel); ?>
                  </span>
                  <?php if (!empty($viewContact['contact_role'])): ?>
                    <?php
                      $roleLabels = [
                        'property_manager' => 'Property Manager',
                        'strata_rep'       => 'Strata Rep',
                        'owner'            => 'Owner',
                        'billing_contact'  => 'Billing Contact',
                        'site_supervisor'  => 'Site Supervisor',
                        'other'            => 'Other',
                      ];
                      $roleLabel = $roleLabels[$viewContact['contact_role']] ?? ucfirst(str_replace('_', ' ', $viewContact['contact_role']));
                    ?>
                    <span class="mw-contact-role-badge">
                      <i data-feather="briefcase" style="width:11px;height:11px;"></i>
                      <?php echo h($roleLabel); ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <a href="clients_appstack.php" class="btn btn-outline-secondary btn-sm">
                <i data-feather="arrow-left"></i> Back
              </a>
            </div>

            <div class="row">
              <!-- Left Column -->
              <div class="col-lg-7">

                <!-- Contact Details Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0"><?php echo $contactName; ?></h5>
                    <div class="d-flex" style="gap:6px;">
                      <a href="/customer/portal.php?contact_id=<?php echo (int)$viewContact['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Open client portal">
                        <i data-feather="external-link" style="width:14px;height:14px;"></i> Client Portal
                      </a>
                      <?php if (userHasPermission('clients.edit')): ?>
                      <!-- Replace a leaked / forwarded portal link (security review #14) -->
                      <form method="post" action="/crm/api/portal-link-regenerate.php" class="d-inline"
                            onsubmit="return confirm('Issue a new portal link? The old link stops working straight away.');">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                        <input type="hidden" name="contact_id" value="<?php echo (int)$viewContact['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Regenerate portal link">
                          <i data-feather="refresh-cw" style="width:14px;height:14px;"></i> Regenerate portal link
                        </button>
                      </form>
                      <?php endif; ?>
                      <a href="?action=edit_contact&id=<?php echo (int)$viewContact['id']; ?>" class="btn btn-sm btn-success" title="Edit contact">
                        <i data-feather="edit-2" style="width:14px;height:14px;"></i> Edit
                      </a>
                    </div>
                  </div>
                  <div class="card-body">

                    <?php
                      $hasEmail  = !empty($viewContact['email']);
                      $hasPhone  = !empty($viewContact['phone']);
                      $hasMobile = !empty($viewContact['mobile']);
                    ?>

                    <!-- Email -->
                    <div class="mw-contact-field">
                      <?php if ($hasEmail): ?>
                        <a href="mailto:<?php echo h($viewContact['email']); ?>" class="mw-icon-box" title="Send email">
                          <i data-feather="mail"></i>
                        </a>
                        <div class="mw-contact-field-body">
                          <a href="mailto:<?php echo h($viewContact['email']); ?>"><?php echo h($viewContact['email']); ?></a>
                        </div>
                      <?php else: ?>
                        <span class="mw-icon-box mw-icon-box--missing" title="No email address">
                          <i data-feather="mail"></i>
                        </span>
                        <div class="mw-contact-field-body mw-contact-field-missing">No email address</div>
                      <?php endif; ?>
                    </div>

                    <!-- Phone -->
                    <div class="mw-contact-field">
                      <?php if ($hasPhone): ?>
                        <a href="tel:<?php echo h($viewContact['phone']); ?>" class="mw-icon-box" title="Call">
                          <i data-feather="phone"></i>
                        </a>
                        <div class="mw-contact-field-body">
                          <a href="tel:<?php echo h($viewContact['phone']); ?>"><?php echo formatPhone($viewContact['phone']); ?></a>
                        </div>
                      <?php else: ?>
                        <span class="mw-icon-box mw-icon-box--missing" title="No phone number">
                          <i data-feather="phone"></i>
                        </span>
                        <div class="mw-contact-field-body mw-contact-field-missing">No phone number</div>
                      <?php endif; ?>
                    </div>

                    <!-- Mobile (only if present) -->
                    <?php if ($hasMobile): ?>
                    <div class="mw-contact-field">
                      <a href="tel:<?php echo h($viewContact['mobile']); ?>" class="mw-icon-box" title="Call mobile">
                        <i data-feather="smartphone"></i>
                      </a>
                      <div class="mw-contact-field-body">
                        <a href="tel:<?php echo h($viewContact['mobile']); ?>"><?php echo formatPhone($viewContact['mobile']); ?></a>
                        <span style="font-size:11px;color:#94a3b8;margin-left:5px;">mobile</span>
                      </div>
                    </div>
                    <?php endif; ?>

                    <!-- Preferred contact method -->
                    <?php $pref = $viewContact['preferred_contact_method'] ?? 'phone'; ?>
                    <div class="mw-contact-field">
                      <span class="mw-icon-box" title="Preferred contact method">
                        <i data-feather="star"></i>
                      </span>
                      <div class="mw-contact-field-body">
                        <?php echo ucfirst(h($pref)); ?>
                        <span style="font-size:11px;color:#94a3b8;margin-left:5px;">preferred</span>
                      </div>
                    </div>

                    <!-- Communication preferences -->
                    <div class="mw-contact-field">
                      <span class="mw-icon-box" title="Communication preferences">
                        <i data-feather="settings"></i>
                      </span>
                      <div class="mw-contact-field-body">
                        <span class="mw-contact-pref-badge <?php echo !empty($viewContact['receive_sms']) ? 'active' : 'inactive'; ?>">
                          <i data-feather="message-square" style="width: 12px; height: 12px;"></i> SMS
                        </span>
                        <span class="mw-contact-pref-badge <?php echo !empty($viewContact['receive_marketing']) ? 'active' : 'inactive'; ?>">
                          <i data-feather="mail" style="width: 12px; height: 12px;"></i> Marketing
                        </span>
                        <span class="mw-contact-pref-badge <?php echo !empty($viewContact['consent_quote_followup']) ? 'active' : 'inactive'; ?>">
                          <i data-feather="check-circle" style="width: 12px; height: 12px;"></i> Follow-up
                        </span>
                        <?php include dirname(__DIR__, 2) . '/includes/photo-optout-control.php'; ?>
                      </div>
                    </div>

                    <!-- Card on file -->
                    <?php if (!empty($viewContact['stripe_card_last4'])): ?>
                    <div class="mw-contact-field">
                      <span class="mw-icon-box" title="Card on file">
                        <i data-feather="credit-card"></i>
                      </span>
                      <div class="mw-contact-field-body">
                        <span class="mw-card-on-file-badge">
                          <?php echo h(ucfirst($viewContact['stripe_card_brand'] ?? 'Card')); ?> ••••<?php echo h($viewContact['stripe_card_last4']); ?>
                          <?php if (!empty($viewContact['stripe_card_exp'])): ?>
                            &middot; exp <?php echo h($viewContact['stripe_card_exp']); ?>
                          <?php endif; ?>
                        </span>
                      </div>
                    </div>
                    <?php endif; ?>

                    <!-- Notes -->
                    <?php if (!empty($viewContact['notes'])): ?>
                    <div class="mw-contact-field">
                      <span class="mw-icon-box" title="Notes">
                        <i data-feather="file-text"></i>
                      </span>
                      <div class="mw-contact-field-body text-muted" style="font-size:13px;">
                        <?php echo nl2br(h($viewContact['notes'])); ?>
                      </div>
                    </div>
                    <?php endif; ?>

                    <!-- Employer company -->
                    <?php if ($employerCompany): ?>
                    <div class="mw-contact-field">
                      <span class="mw-icon-box" title="Employer company">
                        <i data-feather="briefcase"></i>
                      </span>
                      <div class="mw-contact-field-body">
                        <a href="?action=view_company&id=<?php echo (int)$employerCompany['id']; ?>" style="color: var(--mw-green); font-weight: 600;">
                          <?php echo h($employerCompany['company_name']); ?>
                        </a>
                        <?php if ($employerCompany['company_type'] === 'property_manager'): ?>
                          <span style="font-size:11px;color:#94a3b8;margin-left:5px;">property management</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endif; ?>

                  </div>
                </div>

                <!-- Financial Summary -->
                <div class="mw-client-stats-grid mb-3">
                  <div class="mw-stat-card paid">
                    <h4>Revenue</h4>
                    <div class="value"><?php echo formatCurrency($totalRevenue); ?></div>
                  </div>
                  <div class="mw-stat-card <?php echo $totalOutstanding > 0 ? 'outstanding' : 'sent'; ?>">
                    <h4>Outstanding</h4>
                    <div class="value"><?php echo formatCurrency($totalOutstanding); ?></div>
                  </div>
                  <div class="mw-stat-card sent">
                    <h4>Active Plans</h4>
                    <div class="value"><?php echo $activePlanCount; ?></div>
                  </div>
                  <div class="mw-stat-card paid">
                    <h4>Visits Done</h4>
                    <div class="value"><?php echo $completedVisitCount; ?></div>
                  </div>
                </div>

                <!-- Service Lifecycle Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="layers"></i> Service Lifecycle
                      <span class="badge badge-primary ml-1"><?php echo count($contactProperties); ?></span>
                    </h5>
                    <div class="d-flex align-items-center">
                      <div class="mw-lifecycle-view-toggle mr-2" id="lifecycleViewToggle">
                        <button type="button" class="mw-lifecycle-view-btn active" data-view="accordion" title="Accordion view">
                          <i data-feather="list" class="mw-icon-xs"></i>
                        </button>
                        <button type="button" class="mw-lifecycle-view-btn" data-view="tabs" title="Tabbed view">
                          <i data-feather="columns" class="mw-icon-xs"></i>
                        </button>
                        <button type="button" class="mw-lifecycle-view-btn" data-view="table" title="Table view">
                          <i data-feather="grid" class="mw-icon-xs"></i>
                        </button>
                      </div>
                      <button type="button" class="btn btn-sm btn-success mr-1" data-toggle="modal" data-target="#addPropertyModal">
                        <i data-feather="plus"></i> Add Property
                      </button>
                      <?php if (!empty($contactProperties)): ?>
                        <a href="quote-workflow.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo (int)$contactProperties[0]['id']; ?>" class="btn btn-sm btn-outline-success">
                          <i data-feather="file-text"></i> New Quote
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="card-body">
                    <?php if (empty($contactProperties)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="map" style="width: 36px; height: 36px;"></i>
                        <p class="mt-2 mb-0">No properties linked yet.</p>
                        <small>Add a property address to enable scheduling and quoting.</small>
                      </div>
                    <?php else: ?>

                    <!-- ═══ VIEW A: Property Accordion ═══ -->
                    <div id="lifecycle-view-accordion" class="mw-lifecycle-view">
                      <?php foreach ($serviceLifecycle as $propId => $lc):
                        $prop = $lc['property'];
                        $propTags = $lc['tags'];
                        $propQuotes = $lc['quotes'];
                        $plansWithQuote = $lc['plans_with_quote'];
                        $plansNoQuote = $lc['plans_no_quote'];
                        $qCount = count($propQuotes);
                        $pCount = count($plansNoQuote);
                        foreach ($plansWithQuote as $pls) { $pCount += count($pls); }
                      ?>
                        <div class="mw-lifecycle-accordion-item">
                          <div class="mw-lifecycle-prop-header" data-toggle="collapse" data-target="#lcProp_<?php echo $propId; ?>" aria-expanded="true">
                            <div class="mw-lifecycle-prop-title">
                              <i data-feather="chevron-down" class="mw-icon-xs mw-lifecycle-chevron"></i>
                              <i data-feather="home" class="mw-icon-xs"></i>
                              <span><?php echo h($prop['address']); ?></span>
                              <span class="mw-lifecycle-counts">
                                <?php if ($qCount > 0): ?><span class="mw-lifecycle-count-badge"><?php echo $qCount; ?> quote<?php echo $qCount !== 1 ? 's' : ''; ?></span><?php endif; ?>
                                <?php if ($pCount > 0): ?><span class="mw-lifecycle-count-badge"><?php echo $pCount; ?> plan<?php echo $pCount !== 1 ? 's' : ''; ?></span><?php endif; ?>
                              </span>
                            </div>
                            <div class="d-flex align-items-center gap-1" onclick="event.stopPropagation();">
                              <?php if (floatval($prop['latitude'] ?? 0) == 0 || floatval($prop['longitude'] ?? 0) == 0): ?>
                                <button type="button" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary" onclick="geocodeProperty(<?php echo $propId; ?>, this)" title="Geocode">
                                  <i data-feather="crosshair" class="mw-icon-sm"></i>
                                  <span class="mw-prop-btn-label">Geocode</span>
                                </button>
                              <?php else: ?>
                                <span class="text-success mw-prop-hdr-geocoded" title="Geocoded">
                                  <i data-feather="check-circle" class="mw-icon-sm"></i>
                                  <span class="mw-prop-btn-label">Geocoded</span>
                                </span>
                              <?php endif; ?>
                              <button type="button" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary mw-property-edit-btn"
                                      onclick="showEditProperty(<?php echo $propId; ?>, '<?php echo addslashes(h($prop['address'])); ?>', '<?php echo addslashes(h($prop['city'] ?? '')); ?>', '<?php echo addslashes(h($prop['province'] ?? '')); ?>', '<?php echo addslashes(h($prop['postal_code'] ?? '')); ?>', '<?php echo addslashes(h($prop['property_name'] ?? '')); ?>')"
                                      title="Edit property">
                                <i data-feather="edit-2" class="mw-icon-sm"></i>
                                <span class="mw-prop-btn-label">Edit</span>
                              </button>
                              <button type="button" class="mw-prop-hdr-btn mw-property-unlink-btn" onclick="showUnlinkProperty(<?php echo $propId; ?>, '<?php echo addslashes(h($prop['address'])); ?>')" title="Remove or reassign">
                                <i data-feather="x-circle" class="mw-icon-sm"></i>
                                <span class="mw-prop-btn-label">Remove</span>
                              </button>
                            </div>
                          </div>
                          <div class="collapse show" id="lcProp_<?php echo $propId; ?>">
                            <!-- Property Meta -->
                            <div class="mw-lifecycle-prop-meta">
                              <div class="mw-contact-property-meta mb-1">
                                <?php echo h($prop['city'] ?? ''); ?><?php echo !empty($prop['province']) ? ', ' . h($prop['province']) : ''; ?> <?php echo h($prop['postal_code'] ?? ''); ?>
                              </div>
                              <div class="mw-property-tags-row" id="propTags_<?php echo $propId; ?>" onclick="event.stopPropagation();">
                                <?php foreach ($propTags as $pTag): ?>
                                  <span class="mw-property-tag" style="--tag-color: <?php echo h($pTag['tag_color']); ?>">
                                    <?php echo h($pTag['has_value'] && !empty($pTag['tag_value']) ? $pTag['tag_label'] . ': ' . $pTag['tag_value'] : $pTag['tag_label']); ?>
                                    <button type="button" class="mw-property-tag-remove" onclick="removePropertyTag(<?php echo $propId; ?>, <?php echo (int)$pTag['entity_tag_id']; ?>, this)" title="Remove tag">&times;</button>
                                  </span>
                                <?php endforeach; ?>
                                <button type="button" class="mw-property-tag-add-btn" onclick="showTagPicker(<?php echo $propId; ?>, this)" title="Add tag">
                                  <i data-feather="plus" class="mw-icon-xs"></i>
                                </button>
                              </div>
                              <?php
                                $mCount = (int)($prop['measurement_count'] ?? 0);
                                $lawnSqft = floatval($prop['total_lawn_sqft'] ?? 0);
                                $hardSqft = floatval($prop['total_hard_surface_sqft'] ?? 0);
                                $hedgeFt = floatval($prop['total_hedge_linear_ft'] ?? 0);
                                $otherSqft = floatval($prop['total_other_sqft'] ?? 0);
                                $measuredAt = $prop['measurements_updated_at'] ?? null;
                              ?>
                              <div class="mw-property-measurement-status" onclick="event.stopPropagation();">
                                <?php if ($mCount > 0): ?>
                                  <span class="mw-measurement-badge mw-measurement-done" title="<?php echo $mCount; ?> area(s) measured">
                                    <i data-feather="check-circle" class="mw-icon-xs"></i> Measured
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
                                    <i data-feather="alert-circle" class="mw-icon-xs"></i> Not measured
                                  </span>
                                <?php endif; ?>
                              </div>
                              <div class="mw-property-quick-actions" onclick="event.stopPropagation();">
                                <a href="quote-workflow.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo $propId; ?>" class="mw-prop-action-btn mw-prop-action-primary" title="Measure, quote & auto-fill pricing">
                                  <i data-feather="file-text" class="mw-icon-xs"></i> Quote &amp; Measure
                                </a>
                                <a href="jobs/create.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo $propId; ?>" class="mw-prop-action-btn" title="Create job plan">
                                  <i data-feather="clipboard" class="mw-icon-xs"></i> Create Plan
                                </a>
                                <button type="button" class="mw-prop-action-btn mw-prop-action-geofence" title="Draw work zones" onclick="openWorkZoneModal(<?php echo $propId; ?>, <?php echo floatval($prop['latitude'] ?? 0); ?>, <?php echo floatval($prop['longitude'] ?? 0); ?>)">
                                  <i data-feather="map-pin" class="mw-icon-xs"></i> Work Zone
                                </button>
                                <?php if (floatval($prop['latitude'] ?? 0) != 0 && floatval($prop['longitude'] ?? 0) != 0 && (int)($prop['has_arrival_border'] ?? 0) === 0): ?>
                                <a href="jobs/zone-editor.php?property_id=<?php echo $propId; ?>&return_to=<?php echo urlencode('clients_appstack.php?action=view_contact&id=' . (int)$clientId); ?>"
                                   class="mw-prop-action-btn mw-prop-action-border-missing" title="Draw arrival border">
                                  <i data-feather="alert-triangle" class="mw-icon-xs"></i> No Arrival Border
                                </a>
                                <?php endif; ?>
                              </div>
                            </div>
                            <!-- Quotes + Plans -->
                            <div class="mw-lifecycle-items">
                              <?php if (empty($propQuotes) && empty($plansNoQuote)): ?>
                                <div class="text-center text-muted py-3 small">No quotes or plans yet</div>
                              <?php else: ?>
                                <?php foreach ($propQuotes as $qId => $q): ?>
                                  <div class="mw-lifecycle-quote-row" onclick="window.location='quotes/view.php?id=<?php echo (int)$q['id']; ?>'">
                                    <i data-feather="file-text" style="width:13px;height:13px;color:var(--mw-green);"></i>
                                    <strong><?php echo h($q['quote_number']); ?></strong>
                                    <?php if (!empty($q['title'])): ?><span class="text-muted small ml-1"><?php echo h($q['title']); ?></span><?php endif; ?>
                                    <span class="ml-auto d-flex align-items-center" style="gap:8px;">
                                      <?php echo getStatusBadge($q['status'], 'quote'); ?>
                                      <span class="text-nowrap"><?php echo formatCurrency($q['total_amount'] ?? 0); ?></span>
                                      <span class="text-muted small"><?php echo formatDate($q['created_at']); ?></span>
                                    </span>
                                  </div>
                                  <?php if (!empty($plansWithQuote[$qId])): ?>
                                    <?php foreach ($plansWithQuote[$qId] as $pl): ?>
                                      <div class="mw-lifecycle-plan-row mw-lifecycle-nested" onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'">
                                        <i data-feather="corner-down-right" style="width:13px;height:13px;color:#adb5bd;"></i>
                                        <i data-feather="clipboard" style="width:13px;height:13px;color:var(--mw-green);"></i>
                                        <strong><?php echo h($pl['plan_number']); ?></strong>
                                        <span class="text-muted small ml-1">
                                          <?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?>
                                          <?php if ($pl['is_recurring']): ?>&middot; <?php echo ucfirst($pl['recurrence_pattern'] ?? 'recurring'); ?><?php endif; ?>
                                        </span>
                                        <span class="ml-auto d-flex align-items-center" style="gap:8px;">
                                          <?php echo getStatusBadge($pl['status'], 'plan'); ?>
                                          <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?></span>
                                          <span class="text-nowrap"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?></span>
                                        </span>
                                      </div>
                                    <?php endforeach; ?>
                                  <?php endif; ?>
                                <?php endforeach; ?>
                                <?php foreach ($plansNoQuote as $pl): ?>
                                  <div class="mw-lifecycle-plan-row" onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'">
                                    <i data-feather="clipboard" style="width:13px;height:13px;color:var(--mw-green);"></i>
                                    <strong><?php echo h($pl['plan_number']); ?></strong>
                                    <span class="text-muted small ml-1">
                                      <?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?>
                                      <?php if ($pl['is_recurring']): ?>&middot; <?php echo ucfirst($pl['recurrence_pattern'] ?? 'recurring'); ?><?php endif; ?>
                                    </span>
                                    <span class="ml-auto d-flex align-items-center" style="gap:8px;">
                                      <?php echo getStatusBadge($pl['status'], 'plan'); ?>
                                      <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?></span>
                                      <span class="text-nowrap"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?></span>
                                    </span>
                                  </div>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <!-- ═══ VIEW B: Property Mini-Tabs ═══ -->
                    <div id="lifecycle-view-tabs" class="mw-lifecycle-view" style="display:none;">
                      <?php foreach ($serviceLifecycle as $propId => $lc):
                        $prop = $lc['property'];
                        $propTags = $lc['tags'];
                        $propQuotes = $lc['quotes'];
                        $plansWithQuote = $lc['plans_with_quote'];
                        $plansNoQuote = $lc['plans_no_quote'];
                        $allPlans = $plansNoQuote;
                        foreach ($plansWithQuote as $pls) { $allPlans = array_merge($allPlans, $pls); }
                      ?>
                        <div class="mw-lifecycle-tabbed-card mb-3">
                          <div class="mw-lifecycle-tabbed-header">
                            <div class="mw-lifecycle-tabbed-title" onclick="focusProperty(<?php echo $propId; ?>)">
                              <i data-feather="home" class="mw-icon-xs"></i>
                              <?php echo h($prop['address']); ?>
                              <span class="text-muted small ml-1"><?php echo h($prop['city'] ?? ''); ?><?php echo !empty($prop['province']) ? ', ' . h($prop['province']) : ''; ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-1" onclick="event.stopPropagation();">
                              <?php if (floatval($prop['latitude'] ?? 0) == 0 || floatval($prop['longitude'] ?? 0) == 0): ?>
                                <button type="button" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary" onclick="geocodeProperty(<?php echo $propId; ?>, this)" title="Geocode">
                                  <i data-feather="crosshair" class="mw-icon-sm"></i>
                                  <span class="mw-prop-btn-label">Geocode</span>
                                </button>
                              <?php else: ?>
                                <span class="text-success mw-prop-hdr-geocoded" title="Geocoded">
                                  <i data-feather="check-circle" class="mw-icon-sm"></i>
                                  <span class="mw-prop-btn-label">Geocoded</span>
                                </span>
                              <?php endif; ?>
                              <button type="button" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary mw-property-edit-btn"
                                      onclick="showEditProperty(<?php echo $propId; ?>, '<?php echo addslashes(h($prop['address'])); ?>', '<?php echo addslashes(h($prop['city'] ?? '')); ?>', '<?php echo addslashes(h($prop['province'] ?? '')); ?>', '<?php echo addslashes(h($prop['postal_code'] ?? '')); ?>', '<?php echo addslashes(h($prop['property_name'] ?? '')); ?>')"
                                      title="Edit property">
                                <i data-feather="edit-2" class="mw-icon-sm"></i>
                                <span class="mw-prop-btn-label">Edit</span>
                              </button>
                              <button type="button" class="mw-prop-hdr-btn mw-property-unlink-btn" onclick="showUnlinkProperty(<?php echo $propId; ?>, '<?php echo addslashes(h($prop['address'])); ?>')" title="Remove">
                                <i data-feather="x-circle" class="mw-icon-sm"></i>
                                <span class="mw-prop-btn-label">Remove</span>
                              </button>
                            </div>
                          </div>
                          <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item">
                              <a class="nav-link active" data-toggle="tab" href="#lcTab_<?php echo $propId; ?>_details" role="tab">Details</a>
                            </li>
                            <li class="nav-item">
                              <a class="nav-link" data-toggle="tab" href="#lcTab_<?php echo $propId; ?>_quotes" role="tab">
                                Quotes <?php if (!empty($propQuotes)): ?><span class="badge badge-secondary"><?php echo count($propQuotes); ?></span><?php endif; ?>
                              </a>
                            </li>
                            <li class="nav-item">
                              <a class="nav-link" data-toggle="tab" href="#lcTab_<?php echo $propId; ?>_plans" role="tab">
                                Plans <?php if (!empty($allPlans)): ?><span class="badge badge-secondary"><?php echo count($allPlans); ?></span><?php endif; ?>
                              </a>
                            </li>
                          </ul>
                          <div class="tab-content">
                            <!-- Details Tab -->
                            <div class="tab-pane fade show active" id="lcTab_<?php echo $propId; ?>_details" role="tabpanel">
                              <div class="p-3">
                                <div class="mw-property-tags-row mb-2" id="propTagsTab_<?php echo $propId; ?>" onclick="event.stopPropagation();">
                                  <?php foreach ($propTags as $pTag): ?>
                                    <span class="mw-property-tag" style="--tag-color: <?php echo h($pTag['tag_color']); ?>">
                                      <?php echo h($pTag['has_value'] && !empty($pTag['tag_value']) ? $pTag['tag_label'] . ': ' . $pTag['tag_value'] : $pTag['tag_label']); ?>
                                      <button type="button" class="mw-property-tag-remove" onclick="removePropertyTag(<?php echo $propId; ?>, <?php echo (int)$pTag['entity_tag_id']; ?>, this)" title="Remove tag">&times;</button>
                                    </span>
                                  <?php endforeach; ?>
                                  <button type="button" class="mw-property-tag-add-btn" onclick="showTagPicker(<?php echo $propId; ?>, this)" title="Add tag">
                                    <i data-feather="plus" class="mw-icon-xs"></i>
                                  </button>
                                </div>
                                <?php
                                  $mCount = (int)($prop['measurement_count'] ?? 0);
                                  $lawnSqft = floatval($prop['total_lawn_sqft'] ?? 0);
                                  $hardSqft = floatval($prop['total_hard_surface_sqft'] ?? 0);
                                  $hedgeFt = floatval($prop['total_hedge_linear_ft'] ?? 0);
                                  $otherSqft = floatval($prop['total_other_sqft'] ?? 0);
                                  $measuredAt = $prop['measurements_updated_at'] ?? null;
                                ?>
                                <div class="mw-property-measurement-status mb-2">
                                  <?php if ($mCount > 0): ?>
                                    <span class="mw-measurement-badge mw-measurement-done" title="<?php echo $mCount; ?> area(s) measured">
                                      <i data-feather="check-circle" class="mw-icon-xs"></i> Measured
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
                                      <i data-feather="alert-circle" class="mw-icon-xs"></i> Not measured
                                    </span>
                                  <?php endif; ?>
                                </div>
                                <div class="mw-property-quick-actions">
                                  <a href="quote-workflow.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo $propId; ?>" class="mw-prop-action-btn mw-prop-action-primary">
                                    <i data-feather="file-text" class="mw-icon-xs"></i> Quote &amp; Measure
                                  </a>
                                  <a href="jobs/create.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo $propId; ?>" class="mw-prop-action-btn">
                                    <i data-feather="clipboard" class="mw-icon-xs"></i> Create Plan
                                  </a>
                                  <button type="button" class="mw-prop-action-btn mw-prop-action-geofence" onclick="openWorkZoneModal(<?php echo $propId; ?>, <?php echo floatval($prop['latitude'] ?? 0); ?>, <?php echo floatval($prop['longitude'] ?? 0); ?>)">
                                    <i data-feather="map-pin" class="mw-icon-xs"></i> Work Zone
                                  </button>
                                  <?php if (floatval($prop['latitude'] ?? 0) != 0 && floatval($prop['longitude'] ?? 0) != 0 && (int)($prop['has_arrival_border'] ?? 0) === 0): ?>
                                  <a href="jobs/zone-editor.php?property_id=<?php echo $propId; ?>&return_to=<?php echo urlencode('clients_appstack.php?action=view_contact&id=' . (int)$clientId); ?>"
                                     class="mw-prop-action-btn mw-prop-action-border-missing">
                                    <i data-feather="alert-triangle" class="mw-icon-xs"></i> No Arrival Border
                                  </a>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                            <!-- Quotes Tab -->
                            <div class="tab-pane fade" id="lcTab_<?php echo $propId; ?>_quotes" role="tabpanel">
                              <?php if (empty($propQuotes)): ?>
                                <div class="text-center text-muted py-3 small">No quotes yet</div>
                              <?php else: ?>
                                <div class="table-responsive">
                                  <table class="table table-sm table-hover mb-0">
                                    <thead><tr><th>Quote</th><th>Status</th><th class="text-right">Amount</th><th>Date</th></tr></thead>
                                    <tbody>
                                      <?php foreach ($propQuotes as $q): ?>
                                        <tr onclick="window.location='quotes/view.php?id=<?php echo (int)$q['id']; ?>'" style="cursor:pointer;">
                                          <td>
                                            <strong><?php echo h($q['quote_number']); ?></strong>
                                            <?php if (!empty($q['title'])): ?><br><small class="text-muted"><?php echo h($q['title']); ?></small><?php endif; ?>
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
                            <!-- Plans Tab -->
                            <div class="tab-pane fade" id="lcTab_<?php echo $propId; ?>_plans" role="tabpanel">
                              <?php if (empty($allPlans)): ?>
                                <div class="text-center text-muted py-3 small">No plans yet</div>
                              <?php else: ?>
                                <div class="table-responsive">
                                  <table class="table table-sm table-hover mb-0">
                                    <thead><tr><th>Plan</th><th>Status</th><th>Visits</th><th class="text-right">$/Visit</th></tr></thead>
                                    <tbody>
                                      <?php foreach ($allPlans as $pl): ?>
                                        <tr onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'" style="cursor:pointer;">
                                          <td>
                                            <strong><?php echo h($pl['plan_number']); ?></strong>
                                            <br><small class="text-muted">
                                              <?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?>
                                              <?php if ($pl['is_recurring']): ?>&middot; <?php echo ucfirst($pl['recurrence_pattern'] ?? 'recurring'); ?><?php endif; ?>
                                            </small>
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
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <!-- ═══ VIEW C: Linked Table ═══ -->
                    <div id="lifecycle-view-table" class="mw-lifecycle-view" style="display:none;">
                      <div class="table-responsive">
                        <table class="table table-sm mb-0 mw-lifecycle-linked-table">
                          <thead>
                            <tr>
                              <th>Item</th>
                              <th>Type</th>
                              <th>Status</th>
                              <th class="text-right">Amount</th>
                              <th>Progress</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($serviceLifecycle as $propId => $lc):
                              $prop = $lc['property'];
                              $propQuotes = $lc['quotes'];
                              $plansWithQuote = $lc['plans_with_quote'];
                              $plansNoQuote = $lc['plans_no_quote'];
                            ?>
                              <tr class="mw-lifecycle-group-header" onclick="focusProperty(<?php echo $propId; ?>)">
                                <td colspan="4">
                                  <i data-feather="home" class="mw-icon-xs"></i>
                                  <strong><?php echo h($prop['address']); ?></strong>
                                  <span class="text-muted small ml-1"><?php echo h($prop['city'] ?? ''); ?></span>
                                  <?php
                                    $mCount = (int)($prop['measurement_count'] ?? 0);
                                    if ($mCount > 0): ?>
                                    <span class="mw-measurement-badge mw-measurement-done ml-2" style="font-size:0.7rem;padding:1px 6px;">
                                      <i data-feather="check-circle" style="width:9px;height:9px;"></i> Measured
                                    </span>
                                  <?php endif; ?>
                                  <?php foreach ($lc['tags'] as $pTag): ?>
                                    <span class="mw-property-tag ml-1" style="--tag-color:<?php echo h($pTag['tag_color']); ?>;font-size:0.65rem;padding:1px 5px;">
                                      <?php echo h($pTag['tag_label']); ?>
                                    </span>
                                  <?php endforeach; ?>
                                </td>
                                <td class="text-right text-nowrap" onclick="event.stopPropagation();">
                                  <a href="quote-workflow.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo $propId; ?>" class="mw-prop-hdr-btn btn btn-sm btn-outline-success mr-1" title="Quote & Measure">
                                    <i data-feather="file-text" class="mw-icon-sm"></i>
                                    <span class="mw-prop-btn-label">Quote</span>
                                  </a>
                                  <a href="jobs/create.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo $propId; ?>" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary mr-1" title="Create Plan">
                                    <i data-feather="clipboard" class="mw-icon-sm"></i>
                                    <span class="mw-prop-btn-label">Plan</span>
                                  </a>
                                  <button type="button" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary mr-1" title="Work Zone" onclick="openWorkZoneModal(<?php echo $propId; ?>, <?php echo floatval($prop['latitude'] ?? 0); ?>, <?php echo floatval($prop['longitude'] ?? 0); ?>)">
                                    <i data-feather="map-pin" class="mw-icon-sm"></i>
                                    <span class="mw-prop-btn-label">Zone</span>
                                  </button>
                                  <button type="button" class="mw-prop-hdr-btn btn btn-sm btn-outline-secondary mw-property-edit-btn" title="Edit property"
                                          onclick="showEditProperty(<?php echo $propId; ?>, '<?php echo addslashes(h($prop['address'])); ?>', '<?php echo addslashes(h($prop['city'] ?? '')); ?>', '<?php echo addslashes(h($prop['province'] ?? '')); ?>', '<?php echo addslashes(h($prop['postal_code'] ?? '')); ?>', '<?php echo addslashes(h($prop['property_name'] ?? '')); ?>')">
                                    <i data-feather="edit-2" class="mw-icon-sm"></i>
                                    <span class="mw-prop-btn-label">Edit</span>
                                  </button>
                                </td>
                              </tr>
                              <?php if (empty($propQuotes) && empty($plansNoQuote)): ?>
                                <tr><td colspan="5" class="text-muted small pl-4 py-2">No quotes or plans</td></tr>
                              <?php else: ?>
                                <?php foreach ($propQuotes as $qId => $q): ?>
                                  <tr class="mw-lifecycle-row-quote" onclick="window.location='quotes/view.php?id=<?php echo (int)$q['id']; ?>'" style="cursor:pointer;">
                                    <td class="pl-4">
                                      <i data-feather="file-text" style="width:12px;height:12px;color:var(--mw-green);"></i>
                                      <strong><?php echo h($q['quote_number']); ?></strong>
                                      <?php if (!empty($q['title'])): ?><span class="text-muted small ml-1"><?php echo h($q['title']); ?></span><?php endif; ?>
                                    </td>
                                    <td><span class="mw-lifecycle-type-badge mw-lifecycle-type-quote">Quote</span></td>
                                    <td><?php echo getStatusBadge($q['status'], 'quote'); ?></td>
                                    <td class="text-right"><?php echo formatCurrency($q['total_amount'] ?? 0); ?></td>
                                    <td class="text-muted small"><?php echo formatDate($q['created_at']); ?></td>
                                  </tr>
                                  <?php if (!empty($plansWithQuote[$qId])): ?>
                                    <?php foreach ($plansWithQuote[$qId] as $pl): ?>
                                      <tr class="mw-lifecycle-row-plan mw-lifecycle-nested" onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'" style="cursor:pointer;">
                                        <td class="pl-5">
                                          <i data-feather="corner-down-right" style="width:12px;height:12px;color:#adb5bd;"></i>
                                          <i data-feather="clipboard" style="width:12px;height:12px;color:var(--mw-green);"></i>
                                          <strong><?php echo h($pl['plan_number']); ?></strong>
                                          <span class="text-muted small ml-1"><?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?></span>
                                        </td>
                                        <td><span class="mw-lifecycle-type-badge mw-lifecycle-type-plan">Plan</span></td>
                                        <td><?php echo getStatusBadge($pl['status'], 'plan'); ?></td>
                                        <td class="text-right"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?>/vis</td>
                                        <td>
                                          <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?>
                                          </span>
                                        </td>
                                      </tr>
                                    <?php endforeach; ?>
                                  <?php endif; ?>
                                <?php endforeach; ?>
                                <?php foreach ($plansNoQuote as $pl): ?>
                                  <tr class="mw-lifecycle-row-plan" onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'" style="cursor:pointer;">
                                    <td class="pl-4">
                                      <i data-feather="clipboard" style="width:12px;height:12px;color:var(--mw-green);"></i>
                                      <strong><?php echo h($pl['plan_number']); ?></strong>
                                      <span class="text-muted small ml-1"><?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?></span>
                                    </td>
                                    <td><span class="mw-lifecycle-type-badge mw-lifecycle-type-plan">Plan</span></td>
                                    <td><?php echo getStatusBadge($pl['status'], 'plan'); ?></td>
                                    <td class="text-right"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?>/vis</td>
                                    <td>
                                      <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                        <?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?>
                                      </span>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>

                    <?php endif; ?>
                  </div>
                </div>

                <!-- Service Lifecycle View Toggle Script -->
                <script>
                (function() {
                  var KEY = 'clientLifecycleViewMode';
                  var saved = localStorage.getItem(KEY) || 'accordion';
                  var views = ['accordion', 'tabs', 'table'];

                  function showView(mode) {
                    views.forEach(function(v) {
                      var el = document.getElementById('lifecycle-view-' + v);
                      if (el) el.style.display = v === mode ? 'block' : 'none';
                    });
                    var btns = document.querySelectorAll('#lifecycleViewToggle .mw-lifecycle-view-btn');
                    for (var i = 0; i < btns.length; i++) {
                      if (btns[i].getAttribute('data-view') === mode) {
                        btns[i].classList.add('active');
                      } else {
                        btns[i].classList.remove('active');
                      }
                    }
                    localStorage.setItem(KEY, mode);
                    if (typeof feather !== 'undefined') feather.replace();
                  }

                  // Apply saved view on load
                  if (views.indexOf(saved) !== -1) {
                    showView(saved);
                  }

                  // Bind click handlers
                  var btns = document.querySelectorAll('#lifecycleViewToggle .mw-lifecycle-view-btn');
                  for (var i = 0; i < btns.length; i++) {
                    btns[i].addEventListener('click', function(e) {
                      e.preventDefault();
                      showView(this.getAttribute('data-view'));
                    });
                  }
                })();
                </script>

                <!-- Recent Visits Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="calendar"></i> Recent Visits</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($contactVisits)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="calendar" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No visits scheduled yet</p>
                      </div>
                    <?php else: ?>
                      <div class="mw-client-visit-list">
                        <?php foreach ($contactVisits as $v): ?>
                          <a href="jobs/view.php?id=<?php echo (int)$v['plan_id']; ?>" class="mw-client-visit-item">
                            <div class="mw-client-visit-date">
                              <div class="mw-client-visit-day"><?php echo date('j', strtotime($v['scheduled_date'])); ?></div>
                              <div class="mw-client-visit-month"><?php echo date('M', strtotime($v['scheduled_date'])); ?></div>
                            </div>
                            <div class="mw-client-visit-info">
                              <div><?php echo h(ucwords(str_replace('_', ' ', $v['service_type'] ?? $v['plan_title'] ?? ''))); ?></div>
                              <small class="text-muted"><?php echo h($v['property_address'] ?? ''); ?></small>
                            </div>
                            <div class="mw-client-visit-status">
                              <?php echo getStatusBadge($v['status'], 'visit'); ?>
                              <?php if (floatval($v['actual_amount'] ?? 0) > 0): ?>
                                <div class="small text-muted mt-1"><?php echo formatCurrency($v['actual_amount']); ?></div>
                              <?php endif; ?>
                            </div>
                          </a>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Invoices Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="dollar-sign"></i> Invoices
                      <?php if (!empty($contactInvoices)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($contactInvoices); ?></span>
                      <?php endif; ?>
                    </h5>
                    <a href="invoices/create.php?contact_id=<?php echo (int)$clientId; ?>"
                       class="btn btn-sm btn-mowology">
                      <i data-feather="plus" style="width:14px;height:14px;vertical-align:-2px;"></i> Raise Invoice
                    </a>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($contactInvoices)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="dollar-sign" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No invoices yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Invoice</th><th>Status</th><th>Payment</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Balance</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($contactInvoices as $inv): ?>
                              <?php $invMatches = $contactInvoiceMatches[(int)$inv['id']] ?? []; $invBalance = (float)($inv['balance_due'] ?? 0); ?>
                              <tr onclick="window.location='invoices/view.php?id=<?php echo (int)$inv['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($inv['invoice_number']); ?></strong>
                                  <?php if (!empty($inv['issue_date'])): ?>
                                    <br><small class="text-muted"><?php echo formatDate($inv['issue_date']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td>
                                  <?php echo getStatusBadge($inv['status'], 'invoice'); ?>
                                  <?php if (!empty($invMatches)): ?>
                                    <button type="button" class="mw-match-pill mt-1"
                                            onclick="event.stopPropagation(); mwToggleMatch(<?php echo (int)$inv['id']; ?>)"
                                            title="<?php echo ($invMatches[0]['kind'] ?? '') === 'cheque' ? 'A cheque deposit likely pays this invoice' : 'A bank deposit likely matches this invoice'; ?>">
                                      <i data-feather="link-2"></i>
                                      <?php echo htmlspecialchars(InvoiceReconciliationService::chipText($invMatches)); ?>
                                    </button>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo h(formatPaymentMethod($inv['payment_method'] ?? '', $inv['payment_reference'] ?? '')) ?: '—'; ?></td>
                                <td class="text-right"><?php echo formatCurrency($inv['total'] ?? 0); ?></td>
                                <td class="text-right"><?php echo formatCurrency($inv['amount_paid'] ?? 0); ?></td>
                                <td class="text-right <?php echo $invBalance > 0 ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                  <?php echo formatCurrency($inv['balance_due'] ?? 0); ?>
                                </td>
                              </tr>
                              <?php if (!empty($invMatches)): ?>
                              <tr class="mw-match-row" id="mw-match-row-<?php echo (int)$inv['id']; ?>" style="display:none;">
                                <td colspan="6" class="mw-match-cell">
                                  <div class="mw-match-panel">
                                    <div class="mw-match-panel-head">
                                      <i data-feather="zap"></i>
                                      <span>Possible bank deposit<?php echo count($invMatches) > 1 ? 's' : ''; ?> for <strong><?php echo h($inv['invoice_number']); ?></strong></span>
                                      <span class="mw-match-balance">Balance due <?php echo formatCurrency($invBalance); ?></span>
                                    </div>
                                    <?php foreach ($invMatches as $m): ?>
                                    <div class="mw-match-item">
                                      <span class="mw-conf-badge mw-conf-<?php echo $m['confidence'] >= 85 ? 'high' : ($m['confidence'] >= 65 ? 'med' : 'low'); ?>" title="Match confidence"><?php echo (int)$m['confidence']; ?>%</span>
                                      <div class="mw-match-info">
                                        <div class="mw-match-desc"><?php echo h($m['description'] ?: 'Bank deposit'); ?></div>
                                        <div class="mw-match-meta">
                                          <?php echo formatDate($m['date']); ?> · <?php echo formatCurrency($m['amount']); ?> available
                                          <?php foreach ($m['reasons'] as $reason): ?>
                                            <span class="mw-match-reason"><?php echo h($reason); ?></span>
                                          <?php endforeach; ?>
                                        </div>
                                        <?php if (!empty($m['covers_more'])): ?>
                                          <div class="mw-match-note">Larger than this balance — applying <?php echo formatCurrency($m['suggested_amount']); ?> here leaves <?php echo formatCurrency($m['amount'] - $m['suggested_amount']); ?> to apply to other invoices.</div>
                                        <?php endif; ?>
                                        <?php if (!empty($m['likely_recorded'])): ?>
                                          <div class="mw-match-note">⚠ Possibly already recorded — this amount exactly matches e-Transfer payment<?php echo count($m['likely_recorded']['invoice_numbers']) > 1 ? 's' : ''; ?> recorded on <?php echo formatDate($m['likely_recorded']['pay_date']); ?> against <?php echo h(implode(', ', $m['likely_recorded']['invoice_numbers'])); ?>. If so, mark it recorded instead of attaching it again.</div>
                                        <?php endif; ?>
                                      </div>
                                      <div class="mw-match-apply">
                                        <div class="mw-match-amount-field">
                                          <span>$</span>
                                          <input type="number" step="0.01" min="0.01" class="mw-match-amount"
                                                 value="<?php echo number_format($m['suggested_amount'], 2, '.', ''); ?>"
                                                 data-balance="<?php echo number_format($invBalance, 2, '.', ''); ?>"
                                                 data-remaining="<?php echo number_format($m['amount'], 2, '.', ''); ?>">
                                        </div>
                                        <button type="button" class="mw-action-btn mw-action-btn-paid"
                                                onclick="mwAttachDeposit(this, <?php echo (int)$inv['id']; ?>, <?php echo (int)$m['tx_id']; ?>, <?php echo h(json_encode($inv['invoice_number'])); ?>)">
                                          Attach
                                        </button>
                                        <button type="button" class="mw-match-dismiss" title="This deposit's money was already recorded by hand — stop suggesting it"
                                                onclick="mwMarkDepositRecorded(this, <?php echo (int)$m['tx_id']; ?>, <?php echo h(json_encode($m['description'] ?: 'Bank deposit')); ?>)">Already recorded</button>
                                        <button type="button" class="mw-match-dismiss" onclick="mwToggleMatch(<?php echo (int)$inv['id']; ?>)">Close</button>
                                      </div>
                                    </div>
                                    <?php endforeach; ?>
                                  </div>
                                </td>
                              </tr>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Billing Statement Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="book-open"></i> Billing Statement
                      <?php if ($statementBalance > 0.005): ?>
                        <span class="badge badge-danger ml-1"><?php echo formatCurrency($statementBalance); ?> due</span>
                      <?php elseif (!empty($statementLedger)): ?>
                        <span class="badge badge-success ml-1">Paid in full</span>
                      <?php endif; ?>
                    </h5>
                    <div class="d-flex gap-2">
                      <a href="/crm/invoices/statement.php?contact_id=<?php echo (int)$clientId; ?>&period=all_outstanding"
                         class="btn btn-sm btn-primary">
                        <i data-feather="file-text"></i> Statement &amp; Email
                      </a>
                      <?php if (!empty($statementLedger)): ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printStatement(<?php echo (int)$clientId; ?>)">
                        <i data-feather="printer"></i> Print
                      </button>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($statementLedger)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="book-open" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No billing history yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm mb-0 mw-statement-table">
                          <thead>
                            <tr>
                              <th style="width:110px">Date</th>
                              <th>Description</th>
                              <th class="text-right" style="width:100px">Charge</th>
                              <th class="text-right" style="width:100px">Payment</th>
                              <th class="text-right" style="width:100px">Balance</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($statementLedger as $row): ?>
                              <tr class="mw-statement-row mw-statement-<?php echo $row['type']; ?>">
                                <td class="text-muted small text-nowrap">
                                  <?php echo $row['date'] ? date('M j, Y', strtotime($row['date'])) : '—'; ?>
                                </td>
                                <td>
                                  <?php if ($row['type'] === 'charge'): ?>
                                    <a href="<?php echo h($row['link']); ?>">
                                      <strong><?php echo h($row['desc']); ?></strong>
                                    </a>
                                    <?php if (!empty($row['status'])): ?>
                                      &nbsp;<?php echo getStatusBadge($row['status'], 'invoice'); ?>
                                    <?php endif; ?>
                                  <?php else: ?>
                                    <span class="mw-statement-payment-label">
                                      <i data-feather="check-circle" style="width:12px;height:12px;color:var(--mw-green);"></i>
                                      <?php echo h($row['desc']); ?>
                                    </span>
                                    <?php if (!empty($row['receipt'])): ?>
                                      <a href="<?php echo h($row['receipt']); ?>" target="_blank" class="ml-1 small text-muted" title="Stripe receipt">
                                        <i data-feather="external-link" class="mw-icon-xs"></i>
                                      </a>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                </td>
                                <td class="text-right">
                                  <?php echo $row['charge'] > 0 ? formatCurrency($row['charge']) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td class="text-right text-success">
                                  <?php echo $row['credit'] > 0 ? formatCurrency($row['credit']) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td class="text-right <?php echo $row['balance'] > 0.005 ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                  <?php echo formatCurrency($row['balance']); ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                          <tfoot class="mw-statement-totals">
                            <tr>
                              <td colspan="2"><strong>Totals</strong></td>
                              <td class="text-right"><strong><?php echo formatCurrency($statementTotalCharged); ?></strong></td>
                              <td class="text-right text-success"><strong><?php echo formatCurrency($statementTotalPaid); ?></strong></td>
                              <td class="text-right <?php echo $statementBalance > 0.005 ? 'text-danger' : 'text-success'; ?>">
                                <strong><?php echo formatCurrency($statementBalance); ?></strong>
                              </td>
                            </tr>
                          </tfoot>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Client Notes Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Notes</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('addNoteForm').style.display = document.getElementById('addNoteForm').style.display === 'none' ? 'block' : 'none'">
                      <i data-feather="plus"></i> Add Note
                    </button>
                  </div>
                  <div class="card-body">
                    <!-- Add Note Form (hidden by default) -->
                    <div id="addNoteForm" style="display: none;" class="mb-3 p-3 bg-light rounded">
                      <div class="form-group mb-2">
                        <select class="form-control form-control-sm" id="noteType">
                          <option value="general">General</option>
                          <option value="customer_request">Customer Request</option>
                          <option value="issue">Issue</option>
                          <option value="follow_up">Follow-up</option>
                          <option value="internal">Internal</option>
                        </select>
                      </div>
                      <div class="form-group mb-2">
                        <textarea class="form-control form-control-sm" id="noteContent" rows="3" placeholder="Write a note..."></textarea>
                      </div>
                      <button type="button" class="btn btn-sm btn-primary" onclick="addClientNote()">Save Note</button>
                    </div>
                    <?php if (empty($contactNotes)): ?>
                      <p class="text-muted small mb-0">No notes yet.</p>
                    <?php else: ?>
                      <?php foreach ($contactNotes as $note): ?>
                        <div class="mw-note-item mb-2">
                          <div class="mw-note-header">
                            <span class="mw-note-type mw-note-type-<?php echo h(str_replace('_', '-', $note['note_type'] ?? 'general')); ?>">
                              <?php echo ucfirst(str_replace('_', ' ', $note['note_type'] ?? 'general')); ?>
                            </span>
                            <span class="text-muted small">
                              <?php echo h($note['created_by_name'] ?? $note['full_name'] ?? 'System'); ?>
                              &mdash; <?php echo timeAgo($note['created_at']); ?>
                            </span>
                          </div>
                          <div class="mw-note-content"><?php echo nl2br(h($note['content'])); ?></div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Company Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Company</h5>
                  </div>
                  <div class="card-body">
                    <?php if ($contactCompany): ?>
                      <div class="mw-contact-company-link">
                        <div>
                          <div class="mw-contact-company-name">
                            <a href="?action=view_company&id=<?php echo (int)$contactCompany['id']; ?>">
                              <?php echo h($contactCompany['company_name']); ?>
                            </a>
                          </div>
                          <small class="text-muted">
                            <?php echo ucwords(str_replace('_', ' ', $contactCompany['company_type'] ?? '')); ?>
                            <?php if (!empty($contactCompany['billing_email'])): ?>
                              &middot; <?php echo h($contactCompany['billing_email']); ?>
                            <?php endif; ?>
                            &middot; <?php echo ucfirst($contactCompany['account_status'] ?? 'active'); ?>
                          </small>
                        </div>
                      </div>
                    <?php else: ?>
                      <div class="d-flex align-items-center gap-2">
                        <select class="form-control form-control-sm mr-2" id="linkCompanySelect" style="max-width: 300px;">
                          <option value="">-- Select a company --</option>
                          <?php foreach ($existingCompaniesForLink as $comp): ?>
                            <option value="<?php echo (int)$comp['id']; ?>">
                              <?php echo h($comp['company_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $comp['company_type'])); ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary" onclick="linkCompanyToContact()">
                          <i data-feather="link"></i> Link
                        </button>
                      </div>
                      <?php if (empty($existingCompaniesForLink)): ?>
                        <small class="text-muted mt-2 d-block">No companies exist yet. Create one from the client list first.</small>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Also pays as (Penny, payer_aliases 1362): a contact with a company pays as the company -->
                <?php if (!$contactCompany) {
                    $payerAliasName = trim(($viewContact['first_name'] ?? '') . ' ' . ($viewContact['last_name'] ?? ''));
                    $payerAliasContactId = (int)$viewContact['id'];
                    $payerAliasCompanyId = null;
                    include dirname(__DIR__, 2) . '/includes/payer-aliases-card.php';
                } ?>

                <!-- Recent Photos Card -->
                <?php if (!empty($recentPhotos)): ?>
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="camera"></i> Recent Photos
                      <span class="badge badge-primary ml-1"><?php echo count($recentPhotos); ?></span>
                    </h5>
                    <?php
                    $timelineLink = '/crm/photos_appstack.php?contact_id=' . (int)$clientId;
                    if (count($propIds) === 1) {
                        $timelineLink = '/crm/photos_appstack.php?property_id=' . (int)$propIds[0];
                    }
                    ?>
                    <a href="<?php echo $timelineLink; ?>" class="btn btn-sm btn-outline-success">
                      View All <i data-feather="arrow-right" class="mw-icon-xs"></i>
                    </a>
                  </div>
                  <div class="card-body p-2">
                    <div class="mw-recent-photos-grid">
                      <?php foreach ($recentPhotos as $rp):
                          $origUrl = uploadsUrl('photos') . '/' . $rp['filename'];
                          $thumbUrl = $rp['thumb_path'] ?? $rp['grid_path'] ?? $origUrl;
                          $viewUrl = $rp['view_path'] ?? $origUrl;
                      ?>
                        <a href="<?php echo htmlspecialchars($viewUrl); ?>" target="_blank" class="mw-recent-photo" title="<?php echo htmlspecialchars(ucfirst($rp['photo_type']) . ' — ' . date('M j, Y', strtotime($rp['uploaded_at']))); ?>">
                          <img src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="<?php echo htmlspecialchars($rp['photo_type']); ?> photo" loading="lazy">
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

              </div><!-- end left col -->

              <!-- Right Column: Map + Route Intelligence -->
              <div class="col-lg-5">

                <!-- Team on this client + Recent conversation: filled by contact-team.js from
                     /crm/api/contact-team.php; stay hidden if the load fails. -->
                <div class="card mb-3 mw-ct-card" id="mw-ct-team" data-contact-id="<?php echo (int)$clientId; ?>" hidden>
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="users"></i> Team on this client</h5>
                  </div>
                  <div class="card-body p-0">
                    <ul class="mw-ct-list"></ul>
                    <button type="button" class="mw-ct-more" hidden></button>
                  </div>
                </div>
                <div class="card mb-3 mw-ct-card" id="mw-ct-comms" data-contact-id="<?php echo (int)$clientId; ?>" hidden>
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Recent conversation</h5>
                    <span class="mw-ct-sub">Last 90 days</span>
                  </div>
                  <div class="card-body p-0">
                    <ol class="mw-ct-timeline"></ol>
                    <button type="button" class="mw-ct-showmore" hidden>Show more</button>
                  </div>
                </div>
                <script src="<?php echo function_exists('_av') ? _av('/crm/js/contact-team.js') : '/crm/js/contact-team.js'; ?>" defer></script>

                <!-- Managed Properties panel (shown when contact has a role or employer PM) -->
                <?php if (!empty($managedProperties)): ?>
                <div class="card mb-3 mw-managed-props-card">
                  <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0">
                      <i data-feather="layers"></i> Managed Properties
                      <span class="badge badge-pill ml-1" style="background: var(--mw-light); color: var(--mw-dark); font-weight: 600;"><?php echo count($managedProperties); ?></span>
                    </h5>
                  </div>
                  <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                      <?php foreach ($managedProperties as $mp): ?>
                        <li class="list-group-item mw-managed-prop-row">
                          <div class="mw-managed-prop-address">
                            <i data-feather="home" style="width:13px;height:13px;color:var(--mw-green);flex-shrink:0;"></i>
                            <a href="/crm/properties/view.php?id=<?php echo (int)$mp['id']; ?>" style="color: var(--bs-body-color); font-weight: 500; font-size: 13px;">
                              <?php echo h($mp['address']); ?><?php if ($mp['city']): ?><span style="color:#94a3b8;font-weight:400;">, <?php echo h($mp['city']); ?></span><?php endif; ?>
                            </a>
                          </div>
                          <?php if ($mp['link_type'] === 'employer_pm'): ?>
                            <span class="mw-managed-prop-via-badge">via employer</span>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
                <?php endif; ?>

                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i data-feather="map"></i> Neighbourhood Map</h5>
                    <?php if (!empty($nearbyClients)): ?>
                      <span class="badge badge-pill" style="background: var(--mw-light); color: var(--mw-green); font-weight: 600;"><?php echo count($nearbyClients); ?> nearby</span>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if (!empty($contactProperties) && $geocodedCount > 0): ?>
                      <div id="contactMapContainer" class="mw-contact-map-container" style="min-height: 340px;"></div>
                      <?php if (!empty($nearbyClients)): ?>
                        <div class="mw-map-legend">
                          <span class="mw-map-legend-item"><span class="mw-map-legend-dot" style="background: #E53E3E;"></span>This client</span>
                          <span class="mw-map-legend-item"><span class="mw-map-legend-dot" style="background: var(--mw-green);"></span>Active client</span>
                        </div>
                      <?php endif; ?>
                      <?php if ($ungeocodedCount > 0): ?>
                        <div class="p-2 text-center">
                          <small class="text-muted"><?php echo $ungeocodedCount; ?> propert<?php echo $ungeocodedCount === 1 ? 'y needs' : 'ies need'; ?> geocoding</small>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="mw-contact-map-empty">
                        <i data-feather="map-pin" style="width: 36px; height: 36px;"></i>
                        <?php if (empty($contactProperties)): ?>
                          <p class="mb-0">Add a property to see it on the map</p>
                        <?php else: ?>
                          <p class="mb-0">Properties need geocoding to show on the map</p>
                          <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="geocodeAllProperties()">
                            <i data-feather="crosshair"></i> Geocode All
                          </button>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Route Intelligence Card -->
                <?php if (!empty($nearbyClients)): ?>
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="navigation"></i> Route Intelligence</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($smartestDay): ?>
                    <div class="mw-smartest-day">
                      <div class="mw-smartest-day-icon">
                        <i data-feather="calendar"></i>
                      </div>
                      <div class="mw-smartest-day-info">
                        <div class="mw-smartest-day-label">Best day to service</div>
                        <div class="mw-smartest-day-value"><?php echo h($smartestDay['day_name']); ?></div>
                        <div class="mw-smartest-day-detail">
                          Next: <?php echo date('M j', strtotime($smartestDay['next_date'])); ?>
                          &middot; <?php echo $smartestDay['visit_count']; ?> nearby visit<?php echo $smartestDay['visit_count'] !== 1 ? 's' : ''; ?> scheduled
                          <?php if ($smartestDay['recur_plans'] > 0): ?>
                            &middot; <?php echo $smartestDay['recur_plans']; ?> recurring plan<?php echo $smartestDay['recur_plans'] !== 1 ? 's' : ''; ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                    <?php if (count($dayBreakdown) > 1): ?>
                    <div class="mw-day-breakdown">
                      <?php foreach (array_slice($dayBreakdown, 0, 5) as $di => $day):
                        $barWidth = $dayBreakdown[0]['score'] > 0 ? round(($day['score'] / $dayBreakdown[0]['score']) * 100) : 0;
                        $isTop = ($di === 0);
                      ?>
                        <div class="mw-day-breakdown-row<?php echo $isTop ? ' mw-day-top' : ''; ?>">
                          <span class="mw-day-breakdown-name"><?php echo substr($day['day_name'], 0, 3); ?></span>
                          <div class="mw-day-breakdown-bar-bg">
                            <div class="mw-day-breakdown-bar" style="width: <?php echo $barWidth; ?>%;<?php echo $isTop ? ' background: var(--mw-green);' : ''; ?>"></div>
                          </div>
                          <span class="mw-day-breakdown-count"><?php echo $day['visit_count']; ?> visit<?php echo $day['visit_count'] !== 1 ? 's' : ''; ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="mw-route-intel-summary">
                      <?php
                        $closestClient = $nearbyClients[0] ?? null;
                        $within2km = count(array_filter($nearbyClients, function($n) { return $n['distance_km'] <= 2; }));
                        $within5km = count(array_filter($nearbyClients, function($n) { return $n['distance_km'] <= 5; }));
                        $avgDistance = count($nearbyClients) > 0 ? round(array_sum(array_column($nearbyClients, 'distance_km')) / count($nearbyClients), 1) : 0;
                        $densityScore = $within2km >= 5 ? 'High' : ($within2km >= 2 ? 'Medium' : 'Low');
                        $densityColor = $within2km >= 5 ? 'var(--mw-green)' : ($within2km >= 2 ? '#F59E0B' : '#DC2626');
                      ?>
                      <div class="mw-route-intel-stats">
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value"><?php echo $within2km; ?></span>
                          <span class="mw-route-intel-stat-label">Within 2 km</span>
                        </div>
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value"><?php echo $within5km; ?></span>
                          <span class="mw-route-intel-stat-label">Within 5 km</span>
                        </div>
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value"><?php echo $avgDistance; ?><small>km</small></span>
                          <span class="mw-route-intel-stat-label">Avg distance</span>
                        </div>
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value" style="color: <?php echo $densityColor; ?>;"><?php echo $densityScore; ?></span>
                          <span class="mw-route-intel-stat-label">Route density</span>
                        </div>
                      </div>
                    </div>
                    <div class="mw-route-intel-list">
                      <?php foreach ($nearbyClients as $i => $nc):
                        $distColor = $nc['distance_km'] <= 1 ? 'var(--mw-green)' : ($nc['distance_km'] <= 3 ? '#F59E0B' : '#9CA3AF');
                        $serviceIcons = [];
                        $svcTypes = array_map('trim', explode(',', $nc['service_types'] ?? ''));
                        foreach ($svcTypes as $svc) {
                            switch ($svc) {
                                case 'lawn_care': $serviceIcons[] = ['icon' => 'scissors', 'label' => 'Lawn']; break;
                                case 'hedge_trimming': $serviceIcons[] = ['icon' => 'git-branch', 'label' => 'Hedge']; break;
                                case 'garden_care': $serviceIcons[] = ['icon' => 'sun', 'label' => 'Garden']; break;
                                case 'snow_removal': $serviceIcons[] = ['icon' => 'cloud-snow', 'label' => 'Snow']; break;
                                case 'pressure_washing': $serviceIcons[] = ['icon' => 'droplet', 'label' => 'Pressure']; break;
                                case 'junk_removal': $serviceIcons[] = ['icon' => 'trash-2', 'label' => 'Junk']; break;
                                default: if ($svc) $serviceIcons[] = ['icon' => 'tool', 'label' => ucfirst(str_replace('_', ' ', $svc))]; break;
                            }
                        }
                      ?>
                        <a href="?action=view_contact&id=<?php echo (int)$nc['contact_id']; ?>" class="mw-route-intel-item" data-nearby-idx="<?php echo $i; ?>">
                          <div class="mw-route-intel-distance" style="color: <?php echo $distColor; ?>;">
                            <?php echo $nc['distance_km']; ?><small>km</small>
                          </div>
                          <div class="mw-route-intel-info">
                            <div class="mw-route-intel-name">
                              <?php echo h($nc['first_name'] . ' ' . $nc['last_name']); ?>
                              <span class="mw-route-intel-plans"><?php echo (int)$nc['active_plan_count']; ?> plan<?php echo (int)$nc['active_plan_count'] !== 1 ? 's' : ''; ?></span>
                            </div>
                            <div class="mw-route-intel-address"><?php echo h($nc['address'] . ', ' . $nc['city']); ?></div>
                            <div class="mw-route-intel-services">
                              <?php foreach ($serviceIcons as $si): ?>
                                <span class="mw-route-intel-svc" title="<?php echo h($si['label']); ?>"><i data-feather="<?php echo h($si['icon']); ?>"></i></span>
                              <?php endforeach; ?>
                              <?php if ($nc['completed_visits'] > 0): ?>
                                <span class="mw-route-intel-visits"><?php echo (int)$nc['completed_visits']; ?> visits done</span>
                              <?php endif; ?>
                            </div>
                          </div>
                          <div class="mw-route-intel-revenue">
                            <?php echo formatCurrency(floatval($nc['total_per_visit'] ?? 0)); ?><small>/visit</small>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Measurements Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="maximize-2"></i> Measurements</h5>
                  </div>
                  <div class="card-body py-2">
                    <?php
                      $hasAnyMeasurements = false;
                      foreach ($contactProperties as $mp) {
                          if ((int)($mp['measurement_count'] ?? 0) > 0) { $hasAnyMeasurements = true; break; }
                      }
                    ?>
                    <?php if (!$hasAnyMeasurements): ?>
                      <div class="text-center py-3">
                        <i data-feather="maximize-2" style="width: 28px; height: 28px; color: #ccc;"></i>
                        <p class="mb-0 mt-2 small text-muted">No properties measured yet.</p>
                        <p class="mb-0 small text-muted">Use "Quote &amp; Measure" on a property to start.</p>
                      </div>
                    <?php else: ?>
                      <?php foreach ($contactProperties as $mp):
                        $mAreas = $propertyMeasurements[(int)$mp['id']] ?? [];
                        $mCount = (int)($mp['measurement_count'] ?? 0);
                        if ($mCount === 0) continue;
                        $mLawn = floatval($mp['total_lawn_sqft'] ?? 0);
                        $mHard = floatval($mp['total_hard_surface_sqft'] ?? 0);
                        $mHedge = floatval($mp['total_hedge_linear_ft'] ?? 0);
                        $mOther = floatval($mp['total_other_sqft'] ?? 0);
                        $mDate = $mp['measurements_updated_at'] ?? null;
                      ?>
                        <div class="mw-measurements-property">
                          <div class="mw-measurements-property-header">
                            <span class="mw-measurements-property-addr"><?php echo h($mp['address']); ?></span>
                            <?php if ($mDate): ?>
                              <span class="mw-measurements-date"><?php echo h(timeAgo($mDate)); ?></span>
                            <?php endif; ?>
                          </div>
                          <?php
                            // Group areas by type
                            $grouped = [];
                            foreach ($mAreas as $area) {
                                $gKey = $area['measurement_group_key'] ?: 'other';
                                $grouped[$gKey][] = $area;
                            }
                            $groupLabels = ['lawn_area' => 'Lawn', 'hard_surface' => 'Hard Surface', 'hedge_linear' => 'Hedge', 'other_area' => 'Other'];
                          ?>
                          <?php foreach ($grouped as $gKey => $areas): ?>
                            <div class="mw-measurements-group">
                              <div class="mw-measurements-group-label"><?php echo h($groupLabels[$gKey] ?? ucfirst($gKey)); ?></div>
                              <?php foreach ($areas as $area): ?>
                                <div class="mw-measurements-area">
                                  <span class="mw-measurements-area-name"><?php echo h($area['measurement_name']); ?></span>
                                  <span class="mw-measurements-area-value">
                                    <?php if ($area['area_sqft'] > 0): ?>
                                      <?php echo number_format($area['area_sqft']); ?> sq ft
                                    <?php elseif ($area['linear_ft'] > 0): ?>
                                      <?php echo number_format($area['linear_ft']); ?> lin ft
                                    <?php endif; ?>
                                  </span>
                                </div>
                              <?php endforeach; ?>
                              <div class="mw-measurements-group-total">
                                Total:
                                <?php
                                  $gTotal = 0;
                                  $gUnit = 'sq ft';
                                  foreach ($areas as $a) {
                                      if ($a['linear_ft'] > 0) { $gTotal += $a['linear_ft']; $gUnit = 'lin ft'; }
                                      else { $gTotal += $a['area_sqft']; }
                                  }
                                  echo number_format($gTotal) . ' ' . $gUnit;
                                ?>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Data Health Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="activity"></i> Data Health</h5>
                  </div>
                  <div class="card-body py-2">
                    <?php if (empty($dataHealth)): ?>
                      <div class="text-center py-3">
                        <i data-feather="check-circle" style="width: 28px; height: 28px; color: var(--mw-green);"></i>
                        <p class="mb-0 mt-2 small text-success font-weight-bold">All good! Profile is complete.</p>
                      </div>
                    <?php else: ?>
                      <?php foreach ($dataHealth as $dh): ?>
                        <div class="mw-data-health-item mw-data-health-<?php echo $dh['level']; ?>">
                          <i data-feather="<?php echo h($dh['icon']); ?>"></i>
                          <span><?php echo h($dh['text']); ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Profitability Card -->
                <?php if ($totalRevenue > 0 || $totalExpenses > 0):
                  $cProfit  = $totalRevenue - $totalExpenses;
                  $cMargin  = $totalRevenue > 0 ? ($cProfit / $totalRevenue) * 100 : 0;
                  $cProfColor = $cProfit >= 0 ? '#2D8659' : '#DC2626';
                  $cSectors = [['label'=>'Expenses','amount'=>max(0,$totalExpenses),'color'=>'#F59E0B']];
                  if ($cProfit > 0) $cSectors[] = ['label'=>'Profit','amount'=>$cProfit,'color'=>'#2D8659'];
                  $cTotal = array_sum(array_column($cSectors,'amount')); if($cTotal<=0)$cTotal=1;
                  $cAngle = -90.0; $cCx=75; $cCy=75; $cIn=33; $cOut=58;
                ?>
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="trending-up"></i> Profitability</h5>
                  </div>
                  <div class="card-body py-2">
                    <!-- Mini donut chart -->
                    <svg viewBox="0 0 150 150" class="mw-radial-svg" style="max-width:150px;">
                      <circle cx="75" cy="75" r="58" fill="none" stroke="#F3F4F6" stroke-width="25"/>
                      <?php foreach ($cSectors as $ci => $cSec):
                        $cPct=$cSec['amount']/$cTotal; $cSwp=$cPct*360; $cEnd=$cAngle+$cSwp;
                        $cA1=deg2rad($cAngle); $cA2=deg2rad($cEnd); $cLg=$cSwp>180?1:0;
                        $cx1=$cCx+$cIn*cos($cA1);  $cy1=$cCy+$cIn*sin($cA1);
                        $cx2=$cCx+$cOut*cos($cA1); $cy2=$cCy+$cOut*sin($cA1);
                        $cx3=$cCx+$cOut*cos($cA2); $cy3=$cCy+$cOut*sin($cA2);
                        $cx4=$cCx+$cIn*cos($cA2);  $cy4=$cCy+$cIn*sin($cA2);
                        $cPath=sprintf("M%.2f,%.2f L%.2f,%.2f A%d,%d 0 %d,1 %.2f,%.2f L%.2f,%.2f A%d,%d 0 %d,0 %.2f,%.2f Z",
                          $cx1,$cy1,$cx2,$cy2,$cOut,$cOut,$cLg,$cx3,$cy3,$cx4,$cy4,$cIn,$cIn,$cLg,$cx1,$cy1);
                        $cAngle=$cEnd;
                      ?>
                      <path d="<?php echo $cPath; ?>" fill="<?php echo $cSec['color']; ?>" stroke="#fff" stroke-width="2"
                            class="mw-radial-sector" style="animation-delay:<?php echo number_format($ci*0.15,2); ?>s"/>
                      <?php endforeach; ?>
                      <circle cx="75" cy="75" r="31" fill="white"/>
                      <text x="75" y="68" font-size="5.5" fill="#9CA3AF" text-anchor="middle" letter-spacing="0.5">NET PROFIT</text>
                      <text x="75" y="81" font-size="11" font-weight="800" fill="<?php echo $cProfColor; ?>" text-anchor="middle"><?php echo formatCurrency($cProfit); ?></text>
                      <text x="75" y="93" font-size="8" font-weight="700" fill="<?php echo $cProfColor; ?>" text-anchor="middle"><?php echo number_format($cMargin,0); ?>%</text>
                    </svg>
                    <!-- Legend + rows -->
                    <div class="mw-radial-legend mb-2">
                      <div class="mw-radial-legend-item"><span class="mw-radial-dot" style="background:#F59E0B"></span><span>Expenses</span></div>
                      <div class="mw-radial-legend-item"><span class="mw-radial-dot" style="background:#2D8659"></span><span>Profit</span></div>
                    </div>
                    <div class="mw-detail-row">
                      <span class="mw-detail-label">Revenue</span>
                      <span class="font-weight-bold text-success"><?php echo formatCurrency($totalRevenue); ?></span>
                    </div>
                    <div class="mw-detail-row">
                      <span class="mw-detail-label">Expenses</span>
                      <span class="font-weight-bold text-danger"><?php echo formatCurrency($totalExpenses); ?></span>
                    </div>
                    <div class="mw-detail-row" style="border-top: 2px solid #dee2e6;">
                      <span class="mw-detail-label font-weight-bold">Profit</span>
                      <span class="font-weight-bold <?php echo $cProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($cProfit); ?>
                        <small class="text-muted">(<?php echo number_format($cMargin, 0); ?>%)</small>
                      </span>
                    </div>
                    <?php if ($totalQuoted > 0 && $totalQuoted != $totalRevenue): ?>
                    <div class="mw-detail-row">
                      <span class="mw-detail-label">Quoted (Accepted)</span>
                      <span class="text-muted"><?php echo formatCurrency($totalQuoted); ?></span>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Jobber price history + quotes (read-only history; fragment loaded on demand, empty when there is none) -->
                <div id="mwJobberHistory"></div>
                <script>
                (function(){fetch('/crm/api/jobber-price-history.php?contact_id=<?= (int)$clientId ?>&html=1',{credentials:'same-origin'}).then(function(r){return r.ok?r.text():''}).then(function(h){var el=document.getElementById('mwJobberHistory');if(el&&h.indexOf('card')!==-1)el.innerHTML=h}).catch(function(){})})();
                </script>

                <!-- Activity Timeline Card (Enhanced) -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i data-feather="clock"></i> Activity Timeline</h5>
                  </div>
                  <div class="card-body py-2">
                    <div class="mw-timeline-filters mb-2">
                      <?php foreach (['all'=>'All','quotes'=>'Quotes','jobs'=>'Jobs','invoices'=>'Invoices','tasks'=>'Tasks','changes'=>'Changes'] as $fk => $fl): ?>
                      <button class="mw-timeline-filter-btn <?= $fk === 'all' ? 'active' : '' ?>" onclick="mwFilterTimeline('<?= $fk ?>',this)"><?= $fl ?></button>
                      <?php endforeach; ?>
                    </div>
                    <div class="mw-timeline" id="mwTimeline"></div>
                    <div class="text-center py-2" id="mwTimelineLoading"><span class="text-muted small">Loading...</span></div>
                    <div class="text-center mt-2" id="mwTimelineMore" style="display:none;">
                      <button class="btn btn-sm btn-outline-secondary" onclick="mwLoadTimeline(true)">Load More</button>
                    </div>
                  </div>
                </div>
                <script>
                var _mwTlPage=1, _mwTlType='all', _mwTlCid=<?= (int)$clientId ?>;
                var _mwTlIcons={activity:{i:'activity',c:'#6c757d'},quote_created:{i:'file-plus',c:'#3B82F6'},quote_sent:{i:'send',c:'#F59E0B'},quote_accepted:{i:'check-circle',c:'#2D8659'},visit_completed:{i:'check-square',c:'#2D8659'},invoice_created:{i:'file-text',c:'#3B82F6'},invoice_paid:{i:'dollar-sign',c:'#2D8659'},task_created:{i:'plus-square',c:'#6366F1'},task_completed:{i:'check-square',c:'#2D8659'},field_changed:{i:'edit-3',c:'#6B7280'}};
                function mwFilterTimeline(t,btn){_mwTlType=t;_mwTlPage=1;document.querySelectorAll('.mw-timeline-filter-btn').forEach(function(b){b.classList.remove('active')});btn.classList.add('active');document.getElementById('mwTimeline').innerHTML='';mwLoadTimeline(false)}
                function mwLoadTimeline(append){if(!append)_mwTlPage=1;document.getElementById('mwTimelineLoading').style.display='';fetch('/crm/api/contact-timeline.php?contact_id='+_mwTlCid+'&type='+_mwTlType+'&page='+_mwTlPage).then(function(r){return r.json()}).then(function(data){document.getElementById('mwTimelineLoading').style.display='none';var c=document.getElementById('mwTimeline');if(!append)c.innerHTML='';var ev=data.events||[];if(!ev.length&&!append){c.innerHTML='<p class="text-muted small text-center py-3 mb-0">No activity yet.</p>';document.getElementById('mwTimelineMore').style.display='none';return}ev.forEach(function(e){var cfg=_mwTlIcons[e.event_type]||_mwTlIcons.activity;var d=document.createElement('div');d.className='mw-timeline-event';var ts=e.event_time?new Date(e.event_time).toLocaleDateString('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}):'';d.innerHTML='<div class="mw-timeline-icon" style="border-color:'+cfg.c+';color:'+cfg.c+'"><i data-feather="'+cfg.i+'" style="width:12px;height:12px"></i></div><div class="mw-timeline-title">'+(e.title||'')+'</div>'+(e.detail?'<div class="mw-timeline-detail">'+e.detail+'</div>':'')+'<div class="mw-timeline-meta">'+(e.user_name||'System')+' &middot; '+ts+'</div>';c.appendChild(d)});if(window.feather)feather.replace();document.getElementById('mwTimelineMore').style.display=ev.length>=20?'':'none';_mwTlPage++}).catch(function(){document.getElementById('mwTimelineLoading').style.display='none'})}
                document.addEventListener('DOMContentLoaded',function(){mwLoadTimeline(false)});
                </script>

              </div><!-- end right col -->
            </div><!-- end row -->

            <!-- Add Property Modal -->
            <div class="modal fade" id="addPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: var(--mw-green); color: #fff;">
                    <h5 class="modal-title"><i data-feather="plus-circle" style="width: 18px; height: 18px;"></i> Add Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <form onsubmit="addPropertyToContact(event)">
                    <div class="modal-body">
                      <div class="form-group">
                        <label>Street Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="propAddress" required placeholder="Start typing an address..." autocomplete="off">
                        <div id="propAddressDupeWarning" class="alert alert-warning mt-2" style="display:none; font-size: 0.85rem;"></div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" id="propCity" value="Vancouver">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" id="propPostalCode" placeholder="V5K 1A1">
                          </div>
                        </div>
                      </div>
                      <div class="form-group mb-0">
                        <label>Property Name <small class="text-muted">(optional, auto-generated if blank)</small></label>
                        <input type="text" class="form-control" id="propName" placeholder="e.g. Main Residence">
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-success" id="addPropBtn">
                        <i data-feather="plus"></i> Add Property
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Arrival Border Prompt Modal -->
            <div class="modal fade" id="arrivalBorderPromptModal" tabindex="-1" role="dialog">
              <div class="modal-dialog modal-sm" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: #f59e0b; color: #fff;">
                    <h5 class="modal-title"><i data-feather="shield" style="width: 18px; height: 18px;"></i> Draw Arrival Border?</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                  </div>
                  <div class="modal-body">
                    <p class="mb-2">Property added and located on the map.</p>
                    <p class="mb-0 small text-muted">Drawing an arrival border prevents wrong auto-clock-ins on dense routes. You can always draw one later from the property card.</p>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="skipArrivalBorder()">Skip for Now</button>
                    <a href="#" id="drawBorderNowBtn" class="btn btn-warning btn-sm"><i data-feather="edit-3" class="mw-icon-xs"></i> Draw Border Now</a>
                  </div>
                </div>
              </div>
            </div>

            <!-- Edit Property Modal -->
            <div class="modal fade" id="editPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title"><i data-feather="edit-2" style="width:16px;height:16px;"></i> Edit Property</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                  </div>
                  <div class="modal-body">
                    <input type="hidden" id="editPropId" value="">
                    <div class="form-group">
                      <label class="form-label font-weight-600">Street Address <span class="text-danger">*</span></label>
                      <input type="text" id="editPropAddress" class="form-control" placeholder="Start typing an address..." autocomplete="off" required>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-7">
                        <label class="form-label font-weight-600">City</label>
                        <input type="text" id="editPropCity" class="form-control" placeholder="Vancouver">
                      </div>
                      <div class="form-group col-2">
                        <label class="form-label font-weight-600">Prov.</label>
                        <input type="text" id="editPropProvince" class="form-control" placeholder="BC" maxlength="3">
                      </div>
                      <div class="form-group col-3">
                        <label class="form-label font-weight-600">Postal</label>
                        <input type="text" id="editPropPostal" class="form-control" placeholder="V6P 5X5" maxlength="10">
                      </div>
                    </div>
                    <div class="form-group">
                      <label class="form-label font-weight-600">Property Name <small class="text-muted">(optional label)</small></label>
                      <input type="text" id="editPropName" class="form-control" placeholder="e.g. Back yard only">
                    </div>
                    <p class="text-muted small mb-0"><i data-feather="info" style="width:12px;height:12px;"></i> Changing the address clears the map pin — re-geocode after saving.</p>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveEditProperty()">Save Changes</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Unlink/Reassign Property Modal -->
            <div class="modal fade" id="unlinkPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: #dc3545; color: #fff;">
                    <h5 class="modal-title"><i data-feather="x-circle" style="width: 18px; height: 18px;"></i> Remove Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body">
                    <p>Remove <strong id="unlinkPropAddress"></strong> from this contact?</p>
                    <div class="form-group">
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkAction" value="unlink" checked> Unlink only <small class="text-muted">(property keeps its data but has no contact)</small>
                      </label>
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkAction" value="reassign"> Reassign to another contact
                      </label>
                    </div>
                    <div id="reassignContactRow" style="display: none;">
                      <label>Select contact:</label>
                      <select class="form-control" id="reassignContactSelect">
                        <option value="">-- Choose a contact --</option>
                        <?php foreach ($otherContacts as $oc): ?>
                          <option value="<?php echo (int)$oc['id']; ?>">
                            <?php echo h($oc['first_name'] . ' ' . $oc['last_name']); ?><?php echo !empty($oc['email']) ? ' (' . h($oc['email']) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <input type="hidden" id="unlinkPropertyId" value="">
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmUnlinkBtn" onclick="unlinkProperty()">
                      <i data-feather="check"></i> Confirm
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <script>
            (function() {
              var CONTACT_ID = <?php echo (int)$viewContact['id']; ?>;
              var CSRF_TOKEN = '<?php echo csrf_token(); ?>';

              // ── Inline bank-deposit matching (same as /crm/invoices/index.php) ──
              function mwFmtMoney(n) {
                return '$' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
              }
              window.mwToggleMatch = function (invoiceId) {
                var row = document.getElementById('mw-match-row-' + invoiceId);
                if (!row) return;
                var open = row.style.display !== 'none' && row.style.display !== '';
                row.style.display = open ? 'none' : 'table-row';
              };

              window.mwMarkDepositRecorded = function (btn, txId, desc) {
                  if (!confirm('Mark this deposit as already recorded?\n\n' + desc + '\n\nIt will stop being suggested against invoices and be booked as a cash-clearing transfer (not income). You can reverse this later.')) { return; }
                  var origLabel = btn.textContent;
                  btn.disabled = true;
                  btn.textContent = 'Saving…';
                  fetch('/crm/api/accounting-reconciliation.php', {
                      method : 'POST',
                      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                      body   : JSON.stringify({ action: 'mark_recorded', csrf_token: CSRF_TOKEN, transaction_id: txId })
                  })
                  .then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (data.ok) {
                          mwToast('Deposit marked as already recorded.', 'success');
                          setTimeout(function () { window.location.reload(); }, 1000);
                      } else {
                          btn.disabled = false; btn.textContent = origLabel;
                          mwToast(data.error || 'Could not update the deposit.', 'error');
                      }
                  })
                  .catch(function () {
                      btn.disabled = false; btn.textContent = origLabel;
                      mwToast('Couldn\'t reach the server. Check your connection and try again.', 'error');
                  });
              };
              window.mwAttachDeposit = function (btn, invoiceId, txId, invNum) {
                var item      = btn.closest('.mw-match-item');
                var input     = item.querySelector('.mw-match-amount');
                var amount    = parseFloat(input.value);
                var balance   = parseFloat(input.dataset.balance);
                var remaining = parseFloat(input.dataset.remaining);
                if (!(amount > 0)) { mwToast('Enter a valid amount.', 'error'); return; }
                if (amount > remaining + 0.005) {
                  mwToast('Amount exceeds the deposit (' + mwFmtMoney(remaining) + ' available).', 'error');
                  return;
                }
                if (amount > balance + 0.005) {
                  if (!confirm('That is more than the invoice balance (' + mwFmtMoney(balance)
                    + '). It will be capped to the balance. Continue?')) { return; }
                }
                var origLabel = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Attaching…';
                fetch('/crm/api/accounting-reconciliation.php', {
                  method : 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                  body   : JSON.stringify({
                    action        : 'attach',
                    csrf_token    : CSRF_TOKEN,
                    transaction_id: txId,
                    allocations   : [{ invoice_id: invoiceId, amount: amount }]
                  })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                  if (data.ok) {
                    var rec = (data.result && data.result.recorded && data.result.recorded[0]) || {};
                    mwToast(invNum + ' — ' + (rec.fully_paid ? 'paid in full' : 'partial payment recorded') + '.', 'success');
                    setTimeout(function () { window.location.reload(); }, 1200);
                  } else {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                    mwToast(data.error || 'Could not attach deposit.', 'error');
                  }
                })
                .catch(function (err) {
                  btn.disabled = false;
                  btn.textContent = origLabel;
                  console.error('[mwAttachDeposit]', err);
                  mwToast('Couldn\'t reach the server. Check your connection and try again.', 'error');
                });
              };
              var propertiesData = <?php echo json_encode($contactProperties); ?>;
              var nearbyClientsData = <?php echo json_encode($nearbyClients); ?>;
              var availableTags = <?php echo json_encode($availableTags); ?>;
              var gmap = null;
              var markers = {};
              var nearbyMarkers = [];
              var openInfoWindow = null;

              // ── Google Map with Nearby Clients ────────────────────
              function initContactMap() {
                var mapEl = document.getElementById('contactMapContainer');
                if (!mapEl) return;
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') return;

                var bounds = new google.maps.LatLngBounds();
                var hasMarkers = false;

                gmap = new google.maps.Map(mapEl, {
                  gestureHandling: 'greedy',
                  zoom: 12,
                  center: { lat: 49.2827, lng: -123.1207 },
                  mapTypeId: google.maps.MapTypeId.ROADMAP,
                  mapTypeControl: false,
                  streetViewControl: false,
                  styles: [
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                    { featureType: 'transit', stylers: [{ visibility: 'simplified' }] }
                  ]
                });

                // ── This client's properties (red markers) ──
                propertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: prop.address,
                    icon: {
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 10,
                      fillColor: '#E53E3E',
                      fillOpacity: 1,
                      strokeColor: '#fff',
                      strokeWeight: 2.5
                    },
                    zIndex: 1000
                  });

                  var infoContent = '<div style="max-width:260px;font-family:system-ui,sans-serif;">' +
                    '<div style="font-weight:700;color:#E53E3E;margin-bottom:2px;">This Client</div>' +
                    '<strong>' + escHtml(prop.address) + '</strong><br>' +
                    '<small style="color:#6B7280;">' + escHtml((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() {
                    if (openInfoWindow) openInfoWindow.close();
                    infoWindow.open(gmap, marker);
                    openInfoWindow = infoWindow;
                  });

                  markers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                // ── Nearby active clients (green markers) ──
                nearbyClientsData.forEach(function(nc, idx) {
                  var lat = parseFloat(nc.latitude);
                  var lng = parseFloat(nc.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var distKm = parseFloat(nc.distance_km);
                  var scaleSize = distKm <= 1 ? 8 : (distKm <= 3 ? 7 : 6);
                  var opacity = distKm <= 2 ? 0.9 : (distKm <= 5 ? 0.7 : 0.5);

                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: nc.first_name + ' ' + nc.last_name + ' (' + nc.distance_km + ' km)',
                    icon: {
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: scaleSize,
                      fillColor: '#2D8659',
                      fillOpacity: opacity,
                      strokeColor: '#fff',
                      strokeWeight: 1.5
                    },
                    zIndex: 500 - idx
                  });

                  var services = (nc.service_types || '').split(',').map(function(s) {
                    return s.trim().replace(/_/g, ' ');
                  }).filter(Boolean).map(function(s) {
                    return '<span style="display:inline-block;background:#E8F3F0;color:#2D8659;padding:1px 6px;border-radius:3px;font-size:11px;margin:1px 2px 1px 0;">' + escHtml(s) + '</span>';
                  }).join('');

                  var infoContent = '<div style="max-width:280px;font-family:system-ui,sans-serif;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">' +
                      '<strong style="color:#2D8659;">' + escHtml(nc.first_name + ' ' + nc.last_name) + '</strong>' +
                      '<span style="background:#2D8659;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;">' + nc.distance_km + ' km</span>' +
                    '</div>' +
                    '<div style="font-size:13px;color:#374151;">' + escHtml(nc.address) + '</div>' +
                    '<div style="font-size:12px;color:#6B7280;margin-bottom:4px;">' + escHtml(nc.city || '') + '</div>' +
                    (services ? '<div style="margin-top:4px;">' + services + '</div>' : '') +
                    '<div style="margin-top:6px;font-size:12px;color:#6B7280;">' +
                      nc.active_plan_count + ' active plan' + (nc.active_plan_count != 1 ? 's' : '') +
                      (nc.total_per_visit ? ' &middot; $' + parseFloat(nc.total_per_visit).toFixed(0) + '/visit' : '') +
                    '</div>' +
                    '<a href="?action=view_contact&id=' + nc.contact_id + '" style="display:inline-block;margin-top:6px;font-size:12px;color:#2D8659;font-weight:600;text-decoration:none;">View Client &rarr;</a>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() {
                    if (openInfoWindow) openInfoWindow.close();
                    infoWindow.open(gmap, marker);
                    openInfoWindow = infoWindow;
                  });

                  nearbyMarkers.push({ marker: marker, infoWindow: infoWindow, data: nc });
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  gmap.fitBounds(bounds);
                  // Ensure we don't zoom in too much if only 1 property + few nearby
                  google.maps.event.addListenerOnce(gmap, 'bounds_changed', function() {
                    if (gmap.getZoom() > 15) gmap.setZoom(15);
                  });
                }

                // ── Hover highlight from route intel list ──
                document.querySelectorAll('.mw-route-intel-item').forEach(function(item) {
                  var idx = parseInt(item.getAttribute('data-nearby-idx'));
                  if (isNaN(idx) || !nearbyMarkers[idx]) return;
                  item.addEventListener('mouseenter', function() {
                    nearbyMarkers[idx].marker.setIcon({
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 12,
                      fillColor: '#F59E0B',
                      fillOpacity: 1,
                      strokeColor: '#fff',
                      strokeWeight: 2.5
                    });
                    nearbyMarkers[idx].marker.setZIndex(2000);
                  });
                  item.addEventListener('mouseleave', function() {
                    var nc = nearbyMarkers[idx].data;
                    var distKm = parseFloat(nc.distance_km);
                    nearbyMarkers[idx].marker.setIcon({
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: distKm <= 1 ? 8 : (distKm <= 3 ? 7 : 6),
                      fillColor: '#2D8659',
                      fillOpacity: distKm <= 2 ? 0.9 : (distKm <= 5 ? 0.7 : 0.5),
                      strokeColor: '#fff',
                      strokeWeight: 1.5
                    });
                    nearbyMarkers[idx].marker.setZIndex(500 - idx);
                  });
                });
              }

              // ── Focus Property on Map ──────────────────────────
              window.focusProperty = function(propertyId) {
                if (!gmap || !markers[propertyId]) return;
                var marker = markers[propertyId];
                gmap.panTo(marker.getPosition());
                gmap.setZoom(16);
                google.maps.event.trigger(marker, 'click');
              };

              // ── Edit Property ────────────────────────────────────
              window.showEditProperty = function(propId, address, city, province, postalCode, propName) {
                document.getElementById('editPropId').value = propId;
                document.getElementById('editPropAddress').value = address;
                document.getElementById('editPropCity').value = city;
                document.getElementById('editPropProvince').value = province;
                document.getElementById('editPropPostal').value = postalCode;
                document.getElementById('editPropName').value = propName;
                $('#editPropertyModal').modal('show');
              };

              window.saveEditProperty = function() {
                var propId   = document.getElementById('editPropId').value;
                var address  = document.getElementById('editPropAddress').value.trim();
                var city     = document.getElementById('editPropCity').value.trim();
                var province = document.getElementById('editPropProvince').value.trim();
                var postal   = document.getElementById('editPropPostal').value.trim();
                var name     = document.getElementById('editPropName').value.trim();

                if (!address) {
                  document.getElementById('editPropAddress').focus();
                  return;
                }

                var btn = document.querySelector('#editPropertyModal .btn-primary');
                btn.disabled = true;
                btn.textContent = 'Saving…';

                fetch('clients_appstack.php?action=edit_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    csrf_token:    '<?php echo generateCSRFToken(); ?>',
                    property_id:   parseInt(propId),
                    address:       address,
                    city:          city,
                    province:      province,
                    postal_code:   postal,
                    property_name: name
                  })
                })
                .then(r => r.json())
                .then(data => {
                  if (data.success) {
                    $('#editPropertyModal').modal('hide');
                    location.reload();
                  } else {
                    alert('Could not save: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = 'Save Changes';
                  }
                })
                .catch(() => {
                  alert('Couldn\'t reach the server. Check your connection and try again.');
                  btn.disabled = false;
                  btn.textContent = 'Save Changes';
                });
              };

              // ── Unlink / Reassign Property ───────────────────────
              window.showUnlinkProperty = function(propertyId, address) {
                document.getElementById('unlinkPropertyId').value = propertyId;
                document.getElementById('unlinkPropAddress').textContent = address;
                document.getElementById('reassignContactSelect').value = '';
                document.querySelector('input[name="unlinkAction"][value="unlink"]').checked = true;
                document.getElementById('reassignContactRow').style.display = 'none';
                $('#unlinkPropertyModal').modal('show');
              };

              // Toggle reassign dropdown visibility
              document.querySelectorAll('input[name="unlinkAction"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                  document.getElementById('reassignContactRow').style.display =
                    this.value === 'reassign' ? 'block' : 'none';
                });
              });

              window.unlinkProperty = function() {
                var propertyId = parseInt(document.getElementById('unlinkPropertyId').value);
                var action = document.querySelector('input[name="unlinkAction"]:checked').value;
                var newContactId = 0;

                if (action === 'reassign') {
                  newContactId = parseInt(document.getElementById('reassignContactSelect').value);
                  if (!newContactId) {
                    alert('Please select a contact to reassign to.');
                    return;
                  }
                }

                var btn = document.getElementById('confirmUnlinkBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';

                fetch('clients_appstack.php?action=unlink_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    property_id: propertyId,
                    current_contact_id: CONTACT_ID,
                    new_contact_id: newContactId,
                    csrf_token: CSRF_TOKEN
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

              // ── Add Property ────────────────────────────────────
              window.addPropertyToContact = function(event) {
                event.preventDefault();
                var address = document.getElementById('propAddress').value.trim();
                if (!address) { alert('Please enter a street address'); return; }

                var btn = document.getElementById('addPropBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

                fetch('clients_appstack.php?action=add_property_to_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    address: address,
                    city: document.getElementById('propCity').value.trim() || 'Vancouver',
                    postal_code: document.getElementById('propPostalCode').value.trim(),
                    property_name: document.getElementById('propName').value.trim(),
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    // Geocode the new property, then prompt for arrival border
                    var newPropId = data.property_id;
                    geocodePropertyAjax(newPropId).then(function() {
                      // Geocode succeeded — prompt to draw arrival border
                      $('#addPropertyModal').modal('hide');
                      var returnTo = 'clients_appstack.php?action=view_contact&id=' + CONTACT_ID;
                      var zoneUrl = 'jobs/zone-editor.php?property_id=' + newPropId + '&return_to=' + encodeURIComponent(returnTo);
                      document.getElementById('drawBorderNowBtn').href = zoneUrl;
                      $('#arrivalBorderPromptModal').modal('show');
                      feather.replace();
                    }).catch(function() {
                      // Geocode failed — just reload (can't draw border without coords)
                      location.reload();
                    });
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

              window.skipArrivalBorder = function() {
                $('#arrivalBorderPromptModal').modal('hide');
                location.reload();
              };

              // ── Link Company ────────────────────────────────────
              window.linkCompanyToContact = function() {
                var sel = document.getElementById('linkCompanySelect');
                if (!sel || !sel.value) { alert('Please select a company'); return; }

                fetch('clients_appstack.php?action=link_company_to_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    company_id: parseInt(sel.value),
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) location.reload();
                  else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              // ── Geocode Property (client-side via Google Maps JS API) ──
              var geocoder = null;
              function geocodePropertyAjax(propertyId) {
                if (!geocoder) geocoder = new google.maps.Geocoder();
                var prop = propertiesData.find(function(p) { return p.id == propertyId; });
                if (!prop) return Promise.reject(new Error('Property not found'));

                var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
                var address = parts.join(', ');

                return new Promise(function(resolve, reject) {
                  geocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                      var loc = results[0].geometry.location;
                      fetch('clients_appstack.php?action=save_property_coords', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                          property_id: propertyId,
                          lat: loc.lat(),
                          lng: loc.lng(),
                          csrf_token: CSRF_TOKEN
                        })
                      })
                      .then(function(r) { return r.json(); })
                      .then(function(data) { resolve(data); })
                      .catch(function(err) { reject(err); });
                    } else {
                      resolve({ success: false, error: 'Geocode failed: ' + status + ' for "' + address + '"' });
                    }
                  });
                });
              }

              window.geocodeProperty = function(propertyId, btn) {
                if (btn) {
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                }
                geocodePropertyAjax(propertyId)
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

              window.geocodeAllProperties = function() {
                var toGeocode = propertiesData.filter(function(p) { return !parseFloat(p.latitude) || !parseFloat(p.longitude); });
                if (toGeocode.length === 0) return;

                var chain = Promise.resolve();
                toGeocode.forEach(function(p) {
                  chain = chain.then(function() {
                    return geocodePropertyAjax(p.id);
                  }).then(function() {
                    // Small delay between geocode requests to avoid rate limiting
                    return new Promise(function(resolve) { setTimeout(resolve, 300); });
                  });
                });
                chain.then(function() { location.reload(); });
              };

              // ── Utilities ───────────────────────────────────────
              function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
              }

              // ── Property Tags ──────────────────────────────────
              var activeTagPicker = null;

              window.showTagPicker = function(propertyId, btn) {
                // Close any existing picker
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }

                var picker = document.createElement('div');
                picker.className = 'mw-tag-picker-dropdown';
                picker.onclick = function(e) { e.stopPropagation(); };

                var html = '<div class="mw-tag-picker-inner">';
                html += '<select class="mw-tag-picker-select" id="tagSelect_' + propertyId + '" onchange="onTagSelectChange(' + propertyId + ')">';
                html += '<option value="">Select tag…</option>';
                for (var i = 0; i < availableTags.length; i++) {
                  html += '<option value="' + availableTags[i].tag_id + '" data-has-value="' + availableTags[i].has_value + '">' + escHtml(availableTags[i].tag_label) + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="mw-tag-picker-value" id="tagValue_' + propertyId + '" placeholder="Value…" style="display:none;">';
                html += '<button type="button" class="mw-tag-picker-save" onclick="applyPropertyTag(' + propertyId + ')">Save</button>';
                html += '<button type="button" class="mw-tag-picker-cancel" onclick="closeTagPicker()">&times;</button>';
                html += '</div>';

                picker.innerHTML = html;

                // Insert after the add button
                var tagsRow = document.getElementById('propTags_' + propertyId);
                tagsRow.appendChild(picker);
                activeTagPicker = picker;
              };

              window.onTagSelectChange = function(propertyId) {
                var sel = document.getElementById('tagSelect_' + propertyId);
                var valInput = document.getElementById('tagValue_' + propertyId);
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-has-value') === '1') {
                  valInput.style.display = '';
                  valInput.focus();
                } else {
                  valInput.style.display = 'none';
                  valInput.value = '';
                }
              };

              window.closeTagPicker = function() {
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }
              };

              window.applyPropertyTag = function(propertyId) {
                var sel = document.getElementById('tagSelect_' + propertyId);
                var valInput = document.getElementById('tagValue_' + propertyId);
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
                    csrf_token: CSRF_TOKEN
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

              window.removePropertyTag = function(propertyId, entityTagId, btn) {
                if (!confirm('Remove this tag?')) return;
                btn.disabled = true;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'remove',
                    entity_tag_id: entityTagId,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    // Remove the tag badge from DOM
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
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }
              });

              // ── Print Billing Statement ──────────────────────────
              function printStatement(contactId) {
                window.print();
              }

              // ── Add Client Note ──────────────────────────────────
              function addClientNote() {
                var noteType = document.getElementById('noteType').value;
                var noteContent = document.getElementById('noteContent').value.trim();
                if (!noteContent) { alert('Please enter a note.'); return; }

                fetch('clients_appstack.php?action=add_client_note', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    note_type: noteType,
                    content: noteContent,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) location.reload();
                  else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              }

              // ── Init ────────────────────────────────────────────
              document.addEventListener('DOMContentLoaded', function() {
                // Wait for Google Maps to load
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                  initContactMap();
                } else {
                  // Poll until loaded
                  var attempts = 0;
                  var interval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                      clearInterval(interval);
                      initContactMap();
                    }
                    if (attempts > 50) clearInterval(interval);
                  }, 200);
                }
              });
            })();
            </script>

