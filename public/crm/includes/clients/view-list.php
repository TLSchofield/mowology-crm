
          <?php if (($_GET['view'] ?? '') === 'duplicates'): ?>
            <!-- ── Duplicates Review View ─────────────────────────────────── -->
            <div class="mb-3">
              <a href="clients_appstack.php" class="btn btn-outline-secondary btn-sm">
                <i data-feather="arrow-left" class="mw-icon-xs"></i> Back to Client List
              </a>
            </div>

            <?php if (empty($duplicateGroups)): ?>
              <div class="card">
                <div class="card-body text-center py-5">
                  <i data-feather="check-circle" style="width:48px;height:48px;color:var(--mw-green);"></i>
                  <h5 class="mt-3">No Duplicates Found</h5>
                  <p class="text-muted">All contacts look clean. No potential duplicates detected.</p>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-info d-flex align-items-center mb-3">
                <i data-feather="info" style="width:18px;height:18px;margin-right:8px;flex-shrink:0;"></i>
                <span><?php echo count($duplicateGroups); ?> group<?php echo count($duplicateGroups) > 1 ? 's' : ''; ?> of potential duplicates found. Review each group and merge where appropriate.</span>
              </div>

              <?php foreach ($duplicateGroups as $groupIdx => $groupIds): ?>
                <?php
                  $reasons = $dupeMatchReasons[$groupIdx] ?? [];
                  $reasonLabels = [];
                  if (isset($reasons['email'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="mail" class="mw-icon-xs"></i> Email: ' . $reasons['email'] . '</span>';
                  if (isset($reasons['phone'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="phone" class="mw-icon-xs"></i> Phone: ' . $reasons['phone'] . '</span>';
                  if (isset($reasons['address'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="map-pin" class="mw-icon-xs"></i> Address</span>';
                  if (isset($reasons['name'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="user" class="mw-icon-xs"></i> Name: ' . $reasons['name'] . '</span>';
                ?>
                <div class="card mb-3 mw-dupe-group-card">
                  <div class="card-header d-flex justify-content-between align-items-center" style="background:var(--mw-light);">
                    <div>
                      <strong>Group <?php echo $groupIdx + 1; ?></strong>
                      <span class="text-muted ml-2"><?php echo count($groupIds); ?> contacts</span>
                    </div>
                    <div>Match: <?php echo !empty($reasonLabels) ? implode(' ', $reasonLabels) : '<span class="text-muted">unknown</span>'; ?></div>
                  </div>
                  <div class="card-body p-0">
                    <div class="row no-gutters">
                      <?php foreach ($groupIds as $ci => $cId):
                        $c = $dupeContactDetails[$cId] ?? null;
                        if (!$c) continue;
                      ?>
                        <div class="col-md<?php echo count($groupIds) === 2 ? '-6' : ''; ?> mw-dupe-contact-col <?php echo $ci > 0 ? 'mw-dupe-contact-border' : ''; ?>">
                          <div class="p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                              <div>
                                <h6 class="mb-0">
                                  <a href="?action=view_contact&id=<?php echo (int)$c['id']; ?>" class="text-dark">
                                    <?php echo h($c['first_name'] . ' ' . $c['last_name']); ?>
                                  </a>
                                </h6>
                                <small class="text-muted">ID #<?php echo (int)$c['id']; ?> &middot; Created <?php echo date('M j, Y', strtotime($c['created_at'])); ?></small>
                              </div>
                            </div>
                            <table class="table table-sm table-borderless mb-0 mw-dupe-fields">
                              <tr>
                                <td class="text-muted" style="width:100px;">Email</td>
                                <td><?php echo $c['email'] ? h($c['email']) : '<span class="text-muted">—</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Phone</td>
                                <td><?php echo $c['phone'] ? formatPhone($c['phone']) : '<span class="text-muted">—</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Mobile</td>
                                <td><?php echo $c['mobile'] ? formatPhone($c['mobile']) : '<span class="text-muted">—</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Properties</td>
                                <td><?php echo $c['property_addresses'] ? h($c['property_addresses']) : '<span class="text-muted">none</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Quotes</td>
                                <td><?php echo (int)$c['quote_request_count']; ?> request<?php echo $c['quote_request_count'] != 1 ? 's' : ''; ?></td>
                              </tr>
                              <?php if (!empty($c['notes'])): ?>
                              <tr>
                                <td class="text-muted">Notes</td>
                                <td><small><?php echo h(mb_substr($c['notes'], 0, 80)); ?><?php echo mb_strlen($c['notes']) > 80 ? '...' : ''; ?></small></td>
                              </tr>
                              <?php endif; ?>
                            </table>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div class="card-footer text-center">
                    <button class="btn btn-warning" onclick="showMergeModal(<?php echo (int)$groupIds[0]; ?>)">
                      <i data-feather="git-merge" class="mw-icon-sm"></i> Review &amp; Merge
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          <?php else: ?>
            <!-- Search Bar -->
            <div class="mb-3">
              <div class="input-group" style="max-width: 400px;">
                <div class="input-group-prepend">
                  <span class="input-group-text"><i data-feather="search" style="width: 16px; height: 16px;"></i></span>
                </div>
                <input type="text" class="form-control" id="mw-client-search" placeholder="Search clients by name, company, email, phone..." autocomplete="off">
                <div class="input-group-append" id="mw-search-clear" style="display: none;">
                  <button class="btn btn-outline-secondary" type="button" onclick="clearClientSearch()">
                    <i data-feather="x" style="width: 16px; height: 16px;"></i>
                  </button>
                </div>
              </div>
              <small class="text-muted mt-1 d-none" id="mw-search-count"></small>
            </div>

            <!-- Stats Banner -->
            <div class="mw-clients-stats-banner">
              <div class="mw-cs-stat">
                <div class="mw-cs-stat-value"><?php echo number_format($statsTotalActive); ?></div>
                <div class="mw-cs-stat-label">Active clients</div>
              </div>
              <div class="mw-cs-stat-divider"></div>
              <div class="mw-cs-stat">
                <div class="mw-cs-stat-value"><?php echo $statsContactsTotal; ?></div>
                <div class="mw-cs-stat-label">Total contacts</div>
              </div>
              <div class="mw-cs-stat-divider"></div>
              <div class="mw-cs-stat">
                <div class="mw-cs-stat-value mw-cs-accent-lime">+<?php echo $statsNewThisMonth; ?></div>
                <div class="mw-cs-stat-label">New this month</div>
              </div>
              <div class="mw-cs-stat-divider"></div>
              <div class="mw-cs-stat">
                <?php if ($statsOverdueCount > 0): ?>
                  <div class="mw-cs-stat-value mw-cs-accent-red">$<?php echo number_format($statsOverdueAmt, 0); ?></div>
                  <div class="mw-cs-stat-label"><?php echo $statsOverdueCount; ?> overdue invoice<?php echo $statsOverdueCount !== 1 ? 's' : ''; ?></div>
                <?php else: ?>
                  <div class="mw-cs-stat-value mw-cs-accent-lime">$0</div>
                  <div class="mw-cs-stat-label">Overdue</div>
                <?php endif; ?>
              </div>
            </div>

            <!-- View Toggle -->
            <div class="mb-3 d-flex justify-content-between align-items-center">
              <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="view_mode" id="view_kanban" value="kanban" checked>
                <label class="btn btn-outline-secondary" for="view_kanban">
                  <i data-feather="layout"></i> Kanban
                </label>
                <input type="radio" class="btn-check" name="view_mode" id="view_list" value="list">
                <label class="btn btn-outline-secondary" for="view_list">
                  <i data-feather="list"></i> List
                </label>
              </div>
              <button class="btn btn-outline-primary" onclick="showStageManagerModal()">
                <i data-feather="settings"></i> Manage stages
              </button>
            </div>

            <!-- Kanban View -->
            <div id="kanban-view" class="mw-kanban-container">
              <?php
                $stagesData = getCompaniesByLifecycleStage();
                $allStages = getLifecycleStages();

                // Map standalone contacts into stagesData by kanban_stage (lifecycle_stage with
                // prospect_status fallback). This preserves exact stage keys (e.g. 'opportunity',
                // 'baited_with_quote') rather than collapsing to the 3-value prospect_status enum.
                foreach ($standaloneContacts as $contact) {
                    $contactStage = $contact['kanban_stage'] ?? $contact['prospect_status'] ?? 'prospect';
                    if (!isset($stagesData[$contactStage])) {
                        $stagesData[$contactStage] = [
                            'label' => ucfirst($contactStage),
                            'color' => '#6B7280',
                            'companies' => [],
                            'contacts' => []
                        ];
                    }
                    if (!isset($stagesData[$contactStage]['contacts'])) {
                        $stagesData[$contactStage]['contacts'] = [];
                    }
                    $stagesData[$contactStage]['contacts'][] = $contact;
                }

                // Build effective columns: start with defined stages, then add any
                // stages that exist in company data but aren't in the stages table
                $kanbanColumns = [];
                $seenKeys = [];
                foreach ($allStages as $stage) {
                    $kanbanColumns[] = $stage;
                    $seenKeys[$stage['stage_key']] = true;
                }
                foreach ($stagesData as $stageKey => $data) {
                    if (!isset($seenKeys[$stageKey])) {
                        $kanbanColumns[] = [
                            'stage_key' => $stageKey,
                            'stage_label' => $data['label'] ?? ucfirst($stageKey),
                            'stage_color' => $data['color'] ?? '#6B7280',
                        ];
                        $seenKeys[$stageKey] = true;
                    }
                }

                // If no columns at all, provide default fallback columns
                if (empty($kanbanColumns)) {
                    $kanbanColumns = [
                        ['stage_key' => 'prospect', 'stage_label' => 'Prospect', 'stage_color' => '#3B82F6'],
                        ['stage_key' => 'qualified', 'stage_label' => 'Qualified', 'stage_color' => '#F59E0B'],
                        ['stage_key' => 'client', 'stage_label' => 'Client', 'stage_color' => '#2D8659'],
                        ['stage_key' => 'inactive', 'stage_label' => 'Inactive', 'stage_color' => '#6B7280'],
                    ];
                }
              ?>
              <?php foreach ($kanbanColumns as $stage): ?>
                <?php
                  $companyCount = count($stagesData[$stage['stage_key']]['companies'] ?? []);
                  $contactCount = count($stagesData[$stage['stage_key']]['contacts'] ?? []);
                  $totalCount = $companyCount + $contactCount;
                ?>
                <div class="mw-kanban-column" data-stage="<?php echo h($stage['stage_key']); ?>" style="border-top: 4px solid <?php echo h($stage['stage_color']); ?>;">
                  <div class="mw-kanban-header" style="background: <?php echo h($stage['stage_color']); ?>;">
                    <h5 class="mb-0 text-white">
                      <?php echo h($stage['stage_label']); ?>
                      <span class="badge badge-light ml-2"><?php echo $totalCount; ?></span>
                    </h5>
                  </div>
                  <div class="mw-kanban-cards" data-stage="<?php echo h($stage['stage_key']); ?>">
                    <?php foreach ($stagesData[$stage['stage_key']]['companies'] ?? [] as $company): ?>
                      <div class="mw-kanban-card" draggable="true" data-company-id="<?php echo (int)$company['id']; ?>" data-company-name="<?php echo h($company['company_name']); ?>">
                        <a href="?action=view_company&id=<?php echo (int)$company['id']; ?>" class="mw-card-link">
                          <div class="mw-card-header">
                            <?php if (!empty($company['primary_contact_name'])): ?>
                              <strong><?php echo h($company['primary_contact_name']); ?></strong>
                              <small class="text-muted d-block"><?php echo h($company['company_name']); ?></small>
                            <?php else: ?>
                              <strong><?php echo h($company['company_name']); ?></strong>
                            <?php endif; ?>
                          </div>
                          <div class="mw-card-body">
                            <small class="text-muted d-block">
                              <i data-feather="mail" style="width: 14px; height: 14px;"></i>
                              <?php echo h($company['billing_email'] ?? '—'); ?>
                            </small>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="home" style="width: 14px; height: 14px;"></i>
                              <?php echo ucwords(str_replace('_', ' ', $company['company_type'])); ?>
                            </small>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="calendar" style="width: 14px; height: 14px;"></i>
                              <?php echo formatDate($company['created_at']); ?>
                            </small>
                          </div>
                        </a>
                        <div class="mw-card-actions mt-2 pt-2 border-top">
                          <a href="?action=view_company&id=<?php echo (int)$company['id']; ?>" class="btn btn-sm btn-outline-primary" title="View">
                            <i data-feather="edit-2"></i>
                          </a>
                        </div>
                      </div>
                    <?php endforeach; ?>

                    <?php foreach ($stagesData[$stage['stage_key']]['contacts'] ?? [] as $contact): ?>
                      <div class="mw-kanban-card" draggable="true" data-contact-id="<?php echo (int)$contact['id']; ?>" data-company-name="<?php echo h(trim($contact['first_name'] . ' ' . $contact['last_name'])); ?>" style="border-left: 3px solid var(--mw-orange);">
                        <a href="?action=view_contact&id=<?php echo (int)$contact['id']; ?>" class="mw-card-link">
                          <div class="mw-card-header">
                            <strong><?php echo h(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))); ?></strong>
                            <span class="badge badge-light ml-1" style="font-size: 0.65rem;">Contact</span>
                          </div>
                          <div class="mw-card-body">
                            <small class="text-muted d-block">
                              <i data-feather="mail" style="width: 14px; height: 14px;"></i>
                              <?php echo h($contact['email'] ?? '—'); ?>
                            </small>
                            <?php if (!empty($contact['phone'])): ?>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="phone" style="width: 14px; height: 14px;"></i>
                              <?php echo formatPhone($contact['phone']); ?>
                            </small>
                            <?php endif; ?>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="calendar" style="width: 14px; height: 14px;"></i>
                              <?php echo formatDate($contact['created_at']); ?>
                            </small>
                          </div>
                        </a>
                        <?php if (!empty($duplicateMap[$contact['id']])): ?>
                        <div class="mt-2 pt-2 border-top">
                          <button type="button" class="mw-duplicate-badge" onclick="event.preventDefault(); event.stopPropagation(); showMergeModal(<?php echo (int)$contact['id']; ?>)" title="Possible duplicate contact detected — click to review and merge">
                            <i data-feather="alert-triangle" style="width: 12px; height: 12px;"></i>
                            Possible Duplicate
                          </button>
                        </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- List View -->
            <div id="list-view" style="display: none;">
            <div class="card">
              <div class="card-header">
                <h5 class="card-title mb-0">
                  All clients and contacts
                  <span class="badge badge-primary ml-2"><?php echo count($clients) + count($standaloneContacts); ?></span>
                </h5>
              </div>
              <div class="card-body">
                <?php if (!empty($duplicateGroups) && ($_GET['view'] ?? '') !== 'duplicates'): ?>
                  <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
                    <i data-feather="alert-triangle" class="mr-2 flex-shrink-0 mw-icon-md"></i>
                    <div class="flex-grow-1">
                      <strong><?php echo count($duplicateGroups); ?> potential duplicate<?php echo count($duplicateGroups) > 1 ? 's' : ''; ?> found</strong>
                      — merge duplicates to keep your client list clean.
                    </div>
                    <a href="?view=duplicates" class="btn btn-sm btn-outline-warning ml-3 flex-shrink-0">Review</a>
                  </div>
                <?php endif; ?>
                <?php if (empty($clients) && empty($standaloneContacts) && empty($unconvertedRequests)): ?>
                  <div class="text-center text-muted py-5">
                    <i data-feather="inbox" style="width: 48px; height: 48px;"></i>
                    <p class="mt-3 mb-0">You haven't added a client yet. Clients appear here once you add them. <a href="?action=new">Add client</a></p>
                  </div>
                <?php else: ?>
                  <!-- Clients & Prospects Table -->
                  <?php if (!empty($clients)): ?>
                    <div class="table-responsive mb-4">
                      <table class="mw-table" id="mw-clients-table">
                        <thead>
                          <tr>
                            <th class="mw-bulk-checkbox-cell">
                              <input type="checkbox" class="mw-bulk-checkbox" id="mw-clients-select-all" title="Select all">
                            </th>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th class="mw-last-activity-col">Last Activity</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($clients as $c): ?>
                          <tr class="mw-client-row<?php echo $c['source_type'] === 'prospect' ? ' mw-row-prospect' : ''; ?>" data-search="<?php echo h(strtolower(trim(($c['primary_contact_name'] ?? '') . ' ' . $c['company_name'] . ' ' . ($c['billing_email'] ?? '') . ' ' . ($c['primary_contact_phone'] ?? '')))); ?>" data-href="?action=view_company&id=<?php echo (int)$c['id']; ?>">
                            <td class="mw-bulk-checkbox-cell">
                              <input type="checkbox" class="mw-bulk-checkbox mw-bulk-row-select" data-id="<?php echo (int)$c['id']; ?>">
                            </td>
                            <td>
                              <a href="?action=view_company&id=<?php echo (int)$c['id']; ?>" class="mw-client-name-link" style="text-decoration:none;">
                                <span class="mw-cell-primary"><?php echo h($c['company_name']); ?></span>
                                <?php if (!empty(trim($c['primary_contact_name'] ?? ''))): ?>
                                  <span class="mw-cell-secondary"><?php echo h($c['primary_contact_name']); ?><?php echo $c['source_type'] === 'prospect' ? ' · Prospect' : ''; ?></span>
                                <?php elseif ($c['source_type'] === 'prospect'): ?>
                                  <span class="mw-cell-secondary">Prospect</span>
                                <?php endif; ?>
                              </a>
                            </td>
                            <td>
                              <span class="badge badge-secondary">
                                <?php echo ucwords(str_replace('_', ' ', $c['company_type'])); ?>
                              </span>
                            </td>
                            <td>
                              <span class="mw-cell-primary"><?php echo h($c['billing_email'] ?? '—'); ?></span>
                              <span class="mw-cell-secondary"><?php echo ($c['primary_contact_phone'] ?? '') ? formatPhone($c['primary_contact_phone']) : ''; ?></span>
                            </td>
                            <td>
                              <?php
                                if ($c['source_type'] === 'prospect') {
                                  $statusColor = 'info';
                                  $statusText = 'Prospect';
                                } else {
                                  $statusColor = $c['account_status'] === 'active' ? 'success' : ($c['account_status'] === 'inactive' ? 'secondary' : 'danger');
                                  $statusText = ucfirst($c['account_status']);
                                }
                              ?>
                              <span class="badge badge-<?php echo $statusColor; ?>">
                                <?php echo $statusText; ?>
                              </span>
                            </td>
                            <td class="mw-last-activity-col">
                              <?php if (!empty($c['last_activity_at'])): ?>
                                <span class="mw-last-activity" title="<?php echo h(date('M j, Y g:i A', strtotime($c['last_activity_at']))); ?>">
                                  <?php echo timeAgo($c['last_activity_at']); ?>
                                </span>
                              <?php else: ?>
                                <span class="text-muted" style="font-size:12px;">—</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <a href="?action=view_company&id=<?php echo (int)$c['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>

                  <!-- Standalone Contacts (not linked to a company) -->
                  <?php if (!empty($standaloneContacts)): ?>
                    <h6 class="mb-2 mt-2" id="mw-standalone-header">
                      <i data-feather="user" class="feather-inline"></i>
                      Standalone Contacts
                      <span class="badge badge-secondary ml-1"><?php echo count($standaloneContacts); ?></span>
                    </h6>
                    <p class="text-muted small mb-2" id="mw-standalone-desc">Contacts not yet linked to a company.</p>
                    <div class="table-responsive mb-4">
                      <table class="table table-sm table-hover" id="mw-standalone-table">
                        <thead>
                          <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Created</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($standaloneContacts as $ct): ?>
                          <tr class="mw-client-row" data-search="<?php echo h(strtolower(trim(($ct['first_name'] ?? '') . ' ' . ($ct['last_name'] ?? '') . ' ' . ($ct['email'] ?? '') . ' ' . ($ct['phone'] ?? '')))); ?>" data-href="?action=view_contact&id=<?php echo (int)$ct['id']; ?>">
                            <td>
                              <a href="?action=view_contact&id=<?php echo (int)$ct['id']; ?>" class="mw-client-name-link">
                                <strong><?php echo h(trim($ct['first_name'] . ' ' . ($ct['last_name'] ?? ''))); ?></strong>
                              </a>
                              <?php if (!empty($ct['stripe_card_last4'])): ?>
                                <span class="mw-card-on-file-badge" title="Card on file: <?php echo h(ucfirst($ct['stripe_card_brand'] ?? 'Card')); ?> ••••<?php echo h($ct['stripe_card_last4']); ?>">
                                  <i data-feather="credit-card"></i> ••••<?php echo h($ct['stripe_card_last4']); ?>
                                </span>
                              <?php endif; ?>
                            </td>
                            <td><?php echo h($ct['email'] ?? '—'); ?></td>
                            <td><?php echo ($ct['phone'] ?? '') ? formatPhone($ct['phone']) : '—'; ?></td>
                            <td>
                              <span class="badge badge-<?php echo $ct['prospect_status'] === 'client' ? 'success' : ($ct['prospect_status'] === 'inactive' ? 'secondary' : 'info'); ?>">
                                <?php echo ucfirst($ct['prospect_status'] ?? 'prospect'); ?>
                              </span>
                            </td>
                            <td>
                              <small class="text-muted"><?php echo formatDate($ct['created_at']); ?></small>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>

                  <!-- Unconverted Quote Requests -->
                  <?php if (!empty($unconvertedRequests)): ?>
                    <div class="alert alert-info">
                      <h6 class="mb-2">
                        <i data-feather="inbox" class="feather-inline"></i>
                        New Quote Requests (<?php echo count($unconvertedRequests); ?>)
                      </h6>
                      <p class="mb-2 small">These are new inquiries that haven't been converted to clients yet.</p>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover">
                        <thead>
                          <tr>
                            <th>Contact</th>
                            <th>Property</th>
                            <th>Services</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($unconvertedRequests as $req): ?>
                          <tr class="mw-row-quote-request">
                            <td>
                              <strong><?php echo h($req['contact_name'] ?? 'Unknown'); ?></strong>
                            </td>
                            <td>
                              <small><?php echo h($req['property_address'] ?? '—'); ?></small>
                              <?php if ($req['city']): ?>
                                <br><small class="text-muted"><?php echo h($req['city']); ?></small>
                              <?php endif; ?>
                            </td>
                            <td>
                              <small><?php echo h(substr($req['service_types'] ?? 'Not specified', 0, 40)); ?></small>
                            </td>
                            <td>
                              <span class="badge badge-<?php echo $req['urgency'] === 'asap' ? 'danger' : ($req['urgency'] === 'soon' ? 'warning' : 'info'); ?>">
                                <?php echo ucfirst($req['urgency']); ?>
                              </span>
                            </td>
                            <td>
                              <span class="badge badge-light"><?php echo ucfirst($req['status']); ?></span>
                            </td>
                            <td>
                              <small class="text-muted"><?php echo formatDate($req['created_at']); ?></small>
                            </td>
                            <td>
                              <a href="products/quote-requests.php?id=<?php echo (int)$req['id']; ?>" class="btn btn-sm btn-outline-primary" title="View request">
                                <i data-feather="eye"></i>
                              </a>
                              <button class="btn btn-sm btn-outline-success" onclick="convertToProspect(<?php echo (int)$req['id']; ?>, '<?php echo h(addslashes($req['contact_name'] ?? 'New Prospect')); ?>')" title="Convert to prospect">
                                <i data-feather="plus"></i>
                              </button>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            </div><!-- end list-view -->
          <?php endif; ?><!-- end duplicates vs normal view -->
