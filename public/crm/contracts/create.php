<?php
/**
 * Create Contract
 * Can be reached from an accepted quote (quote_id in GET), directly from a client
 * page (?contact_id=X&property_id=Y), or manually from the Contracts list.
 *
 * Wizard flow (manual): 1) pick contact  2) pick property  3) fill details
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

$db = getDB();

// ── Pre-fill from quote if provided ──────────────────────────────────────
$quoteId = intval($_GET['quote_id'] ?? 0);
$quote   = null;

if ($quoteId) {
    $stmt = $db->prepare("
        SELECT q.*,
               p.address AS property_address, p.city AS property_city,
               p.site_contact_id,
               COALESCE(qr.contact_id, p.site_contact_id) AS resolved_contact_id,
               COALESCE(qrc.first_name, pc.first_name) AS contact_first,
               COALESCE(qrc.last_name,  pc.last_name)  AS contact_last
        FROM quotes q
        JOIN properties p ON q.property_id = p.id
        LEFT JOIN quote_requests qr  ON qr.quote_id  = q.id
        LEFT JOIN contacts qrc       ON qrc.id = qr.contact_id
        LEFT JOIN contacts pc        ON pc.id  = p.site_contact_id
        WHERE q.id = ? AND q.status = 'accepted'
    ");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($quote) {
        $existing = $db->prepare("SELECT id, contract_number FROM contracts WHERE quote_id = ? LIMIT 1");
        $existing->execute([$quoteId]);
        $existingContract = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingContract) {
            header("Location: view.php?id={$existingContract['id']}&from=quote");
            exit;
        }
    }
}

// ── Pre-fill from direct URL params (?contact_id=X&property_id=Y) ────────
$directContactId  = intval($_GET['contact_id']  ?? 0);
$directPropertyId = intval($_GET['property_id'] ?? 0);

$prefilledContactId   = 0;
$prefilledContactName = '';
$prefilledPropertyId  = 0;
$prefilledProperties  = [];

if ($quote) {
    $prefilledContactId   = intval($quote['resolved_contact_id'] ?? 0);
    $prefilledContactName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''));
    $prefilledPropertyId  = intval($quote['property_id'] ?? 0);
} elseif ($directContactId) {
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM contacts WHERE id = ?");
    $stmt->execute([$directContactId]);
    $dc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dc) {
        $prefilledContactId   = $directContactId;
        $prefilledContactName = trim($dc['first_name'] . ' ' . $dc['last_name']);
        $prefilledPropertyId  = $directPropertyId;
        $stmt2 = $db->prepare("SELECT id, address, city, province, postal_code, property_type FROM properties WHERE site_contact_id = ? AND status = 'active' ORDER BY address");
        $stmt2->execute([$directContactId]);
        $prefilledProperties = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── POST: create contract ─────────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $data = [
        'property_id'    => intval($_POST['property_id'] ?? 0),
        'contact_id'     => intval($_POST['contact_id'] ?? 0),
        'quote_id'       => intval($_POST['quote_id'] ?? 0) ?: null,
        'title'          => trim($_POST['title'] ?? ''),
        'billing_cycle'  => $_POST['billing_cycle'] ?? 'monthly',
        'billing_amount' => $_POST['billing_amount'] ?? '',
        'invoice_timing' => in_array($_POST['invoice_timing'] ?? '', ['after_visit','end_of_month','upfront'], true) ? $_POST['invoice_timing'] : 'after_visit',
        'start_date'     => $_POST['start_date'] ?? '',
        'end_date'       => $_POST['end_date'] ?? '',
        'renewal_date'   => $_POST['renewal_date'] ?? '',
        'notes'          => trim($_POST['notes'] ?? ''),
    ];

    $result = createContract($data, (int)$user['id']);

    if ($result['success']) {
        $contractId = $result['contract_id'];
        if (!empty($data['quote_id'])) {
            header("Location: ../jobs/create-from-quote.php?quote_id={$data['quote_id']}&contract_id={$contractId}");
        } else {
            header("Location: view.php?id={$contractId}&created=1");
        }
        exit;
    } else {
        $error = implode(' ', $result['errors']);
    }
}

$csrfToken = generateCSRFToken();

// Defaults from quote
$defaultPropertyId  = $quote['property_id']  ?? $directPropertyId;
$defaultContactId   = $prefilledContactId;
$defaultTitle       = $quote ? ($quote['title'] ?: '') : '';
$defaultStartDate   = $quote && $quote['accepted_at'] ? date('Y-m-d', strtotime($quote['accepted_at'])) : date('Y-m-d');
$defaultBillingAmt  = $quote ? (number_format((float)($quote['total_amount'] ?? 0), 2)) : '';
$contactName        = $prefilledContactName;
$propertyAddress    = $quote ? trim(($quote['property_address'] ?? '') . ', ' . ($quote['property_city'] ?? '')) : '';

$pageTitle  = 'New Contract';
$activePage = 'contracts';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="<?php echo $quoteId ? '../quotes/view.php?id=' . $quoteId : '../contracts_appstack.php'; ?>" class="mw-back-link">
              &larr; <?php echo $quoteId ? 'Back to Quote' : 'Back to Contracts'; ?>
          </a>

          <h1 class="h3 mb-1">New Contract</h1>
          <p class="text-muted mb-4">
              <?php if ($quote): ?>
                  From <strong><?php echo htmlspecialchars($quote['quote_number']); ?></strong>
                  &mdash; <?php echo htmlspecialchars($contactName); ?>
                  &mdash; <?php echo htmlspecialchars($propertyAddress); ?>
              <?php else: ?>
                  Select a contact and property, then fill in the contract details.
              <?php endif; ?>
          </p>

          <?php if ($error): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <form method="POST" id="contractForm">
              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
              <input type="hidden" name="quote_id"    value="<?php echo (int)$quoteId; ?>">
              <input type="hidden" name="contact_id"  id="hiddenContactId" value="<?php echo (int)$defaultContactId; ?>">
              <input type="hidden" name="property_id" id="hiddenPropertyId" value="<?php echo (int)$defaultPropertyId; ?>">

              <div class="row">
                  <div class="col-md-8">

                      <?php if ($quote): ?>
                          <!-- Quote-originated: show locked contact/property -->
                          <div class="card mb-3">
                              <div class="card-header"><h5 class="card-title mb-0">Client &amp; Property</h5></div>
                              <div class="card-body">
                                  <div class="mw-form-row">
                                      <label class="mw-form-label">Client</label>
                                      <div class="mw-form-value text-muted"><?php echo htmlspecialchars($contactName); ?></div>
                                  </div>
                                  <div class="mw-form-row">
                                      <label class="mw-form-label">Property</label>
                                      <div class="mw-form-value text-muted"><?php echo htmlspecialchars($propertyAddress); ?></div>
                                  </div>
                              </div>
                          </div>

                      <?php else: ?>
                          <!-- Manual / direct-link: wizard steps 1 & 2 -->
                          <div class="card mb-3" id="clientPropertyCard">
                              <div class="card-header"><h5 class="card-title mb-0">Step 1 — Select Client</h5></div>
                              <div class="card-body">

                                  <!-- Contact search -->
                                  <div class="mw-form-row">
                                      <label class="mw-form-label" for="clientSearchInput">Contact <span class="text-danger">*</span></label>
                                      <div class="mw-client-search-wrapper">
                                          <input type="text" id="clientSearchInput" class="form-control"
                                                 placeholder="Type to search contacts…"
                                                 autocomplete="off"
                                                 value="<?php echo htmlspecialchars($contactName); ?>">
                                          <div id="clientSearchResults" class="mw-client-search-results"></div>
                                      </div>
                                      <small class="form-text text-muted" id="contactHint">
                                          <?php echo $prefilledContactId ? '' : 'Start typing a name or email.'; ?>
                                      </small>
                                  </div>

                                  <!-- Property dropdown (step 2, revealed after contact chosen) -->
                                  <div class="mw-form-row" id="propertyRow" style="<?php echo $prefilledContactId ? '' : 'display:none'; ?>">
                                      <label class="mw-form-label" for="propertySelect">Property <span class="text-danger">*</span></label>
                                      <select id="propertySelect" class="form-control">
                                          <option value="">— select a property —</option>
                                          <?php foreach ($prefilledProperties as $prop): ?>
                                              <option value="<?php echo (int)$prop['id']; ?>"
                                                  <?php echo ($defaultPropertyId == $prop['id']) ? 'selected' : ''; ?>>
                                                  <?php echo htmlspecialchars($prop['address'] . ', ' . $prop['city']); ?>
                                                  <?php echo $prop['property_type'] ? ' (' . htmlspecialchars($prop['property_type']) . ')' : ''; ?>
                                              </option>
                                          <?php endforeach; ?>
                                      </select>
                                      <div id="addPropertyBtnRow" class="mt-2" style="display:none;">
                                          <button type="button" class="btn btn-sm btn-outline-secondary" id="addPropertyBtn">
                                              <i data-feather="plus" style="width:13px;height:13px;"></i> Add new property
                                          </button>
                                      </div>
                                      <small class="form-text text-muted" id="propertyHint"></small>
                                  </div>

                                  <!-- Selection summary chip (shows after both chosen) -->
                                  <div id="selectionSummary" class="mt-2" style="display:none;">
                                      <div class="alert alert-success py-2 px-3 mb-0 d-flex justify-content-between align-items-center">
                                          <span id="selectionText"></span>
                                          <button type="button" class="btn btn-sm btn-link text-muted p-0 ml-2" id="clearSelectionBtn"
                                                  title="Change contact/property">
                                              <i data-feather="x" style="width:14px;height:14px;"></i>
                                          </button>
                                      </div>
                                  </div>

                              </div>
                          </div>
                      <?php endif; ?>

                      <!-- Contract Details (step 3) -->
                      <div class="card mb-3" id="detailsCard"
                           style="<?php echo (!$quote && !$defaultPropertyId) ? 'display:none;' : ''; ?>">
                          <div class="card-header">
                              <h5 class="card-title mb-0"><?php echo $quote ? 'Contract Details' : 'Step 2 — Contract Details'; ?></h5>
                          </div>
                          <div class="card-body">

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="title">Contract Title <span class="text-muted">(optional)</span></label>
                                  <input type="text" id="title" name="title" class="form-control"
                                         value="<?php echo htmlspecialchars($defaultTitle); ?>"
                                         placeholder="e.g. Full-Service Landscaping 2026">
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="start_date">Start Date <span class="text-danger">*</span></label>
                                  <input type="date" id="start_date" name="start_date" class="form-control"
                                         value="<?php echo htmlspecialchars($defaultStartDate); ?>" required>
                              </div>

                              <div class="row">
                                  <div class="col-sm-6">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="end_date">End Date <span class="text-muted">(optional)</span></label>
                                          <input type="date" id="end_date" name="end_date" class="form-control">
                                      </div>
                                  </div>
                                  <div class="col-sm-6">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="renewal_date">Renewal Date <span class="text-muted">(optional)</span></label>
                                          <input type="date" id="renewal_date" name="renewal_date" class="form-control">
                                      </div>
                                  </div>
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="notes">Notes <span class="text-muted">(optional)</span></label>
                                  <textarea id="notes" name="notes" class="form-control" rows="3"
                                            placeholder="Internal notes about this contract…"></textarea>
                              </div>

                          </div>
                      </div>

                  </div><!-- /col-md-8 -->

                  <div class="col-md-4">
                      <div class="card mb-3" id="billingCard"
                           style="<?php echo (!$quote && !$defaultPropertyId) ? 'display:none;' : ''; ?>">
                          <div class="card-header"><h5 class="card-title mb-0">Billing</h5></div>
                          <div class="card-body">

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="billing_cycle">Billing Cycle</label>
                                  <select id="billing_cycle" name="billing_cycle" class="form-control">
                                      <option value="monthly">Monthly</option>
                                      <option value="per_visit">Per Visit</option>
                                      <option value="seasonal">Seasonal</option>
                                      <option value="annual">Annual</option>
                                      <option value="custom">Custom</option>
                                  </select>
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="billing_amount">Contract Value</label>
                                  <div class="input-group">
                                      <div class="input-group-prepend">
                                          <span class="input-group-text">$</span>
                                      </div>
                                      <input type="number" id="billing_amount" name="billing_amount"
                                             class="form-control" min="0" step="0.01"
                                             value="<?php echo htmlspecialchars($defaultBillingAmt); ?>"
                                             placeholder="0.00">
                                  </div>
                                  <small class="form-text text-muted">What the client pays per billing cycle.</small>
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label">Invoice Timing</label>
                                  <div class="mw-invoice-timing-selector mw-invoice-timing-compact">
                                      <?php
                                      $timingOptions = [
                                          'after_visit'  => ['label' => 'After Each Visit',  'desc' => 'Invoice per completed visit', 'icon' => 'check-circle'],
                                          'end_of_month' => ['label' => 'End of Month',       'desc' => 'Group visits, invoice monthly', 'icon' => 'calendar'],
                                          'upfront'      => ['label' => 'Upfront / Prepay',   'desc' => 'Invoice before service begins', 'icon' => 'credit-card'],
                                      ];
                                      foreach ($timingOptions as $val => $opt): ?>
                                      <label class="mw-timing-option <?php echo $val === 'after_visit' ? 'mw-timing-active' : ''; ?>">
                                          <input type="radio" name="invoice_timing" value="<?php echo $val; ?>"
                                                 <?php echo $val === 'after_visit' ? 'checked' : ''; ?>
                                                 onchange="document.querySelectorAll('[name=invoice_timing]').forEach(function(el){el.closest('.mw-timing-option').classList.remove('mw-timing-active')});this.closest('.mw-timing-option').classList.add('mw-timing-active')">
                                          <span class="mw-timing-icon"><i data-feather="<?php echo $opt['icon']; ?>"></i></span>
                                          <span class="mw-timing-text">
                                              <strong><?php echo $opt['label']; ?></strong>
                                              <small><?php echo $opt['desc']; ?></small>
                                          </span>
                                      </label>
                                      <?php endforeach; ?>
                                  </div>
                              </div>

                          </div>
                      </div>

                      <div id="submitCard" style="<?php echo (!$quote && !$defaultPropertyId) ? 'display:none;' : ''; ?>">
                          <div class="d-grid">
                              <button type="submit" class="btn btn-primary btn-block">
                                  <i data-feather="pen-tool" style="width:14px;height:14px;"></i>
                                  Create Contract<?php echo $quoteId ? ' &amp; Add Plans' : ''; ?>
                              </button>
                          </div>
                          <p class="text-muted text-center small mt-2">
                              <?php if ($quoteId): ?>
                                  You'll be taken to the plan builder next.
                              <?php else: ?>
                                  You can add service plans after creating the contract.
                              <?php endif; ?>
                          </p>
                      </div>
                  </div>

              </div><!-- /row -->
          </form>

<?php if (!$quote): ?>
<script>
(function () {
    const contactInput    = document.getElementById('clientSearchInput');
    const searchResults   = document.getElementById('clientSearchResults');
    const hiddenContactId = document.getElementById('hiddenContactId');
    const hiddenPropertyId= document.getElementById('hiddenPropertyId');
    const propertyRow     = document.getElementById('propertyRow');
    const propertySelect  = document.getElementById('propertySelect');
    const detailsCard     = document.getElementById('detailsCard');
    const billingCard     = document.getElementById('billingCard');
    const submitCard      = document.getElementById('submitCard');
    const selectionSummary= document.getElementById('selectionSummary');
    const selectionText   = document.getElementById('selectionText');
    const clearBtn        = document.getElementById('clearSelectionBtn');
    const cardHeader      = document.querySelector('#clientPropertyCard .card-header h5');

    let searchTimer = null;
    let loadedProperties = [];
    let selectedContactName = <?php echo json_encode($prefilledContactName); ?>;

    // ── Visibility helpers ────────────────────────────────────────────────
    function showDetailsSection() {
        detailsCard.style.display  = '';
        billingCard.style.display  = '';
        submitCard.style.display   = '';
        feather.replace();
    }
    function hideDetailsSection() {
        detailsCard.style.display  = 'none';
        billingCard.style.display  = 'none';
        submitCard.style.display   = 'none';
    }

    function showSelectionSummary(contactName, propertyLabel) {
        selectionText.textContent = contactName + ' — ' + propertyLabel;
        selectionSummary.style.display = '';
        propertyRow.style.display      = 'none';
        contactInput.parentElement.parentElement.style.display = 'none'; // hide contact row
        cardHeader.textContent = 'Client & Property';
        feather.replace();
        showDetailsSection();
    }

    function resetWizard() {
        contactInput.value = '';
        hiddenContactId.value  = '';
        hiddenPropertyId.value = '';
        selectedContactName    = '';
        loadedProperties       = [];
        propertySelect.innerHTML = '<option value="">— select a property —</option>';
        propertyRow.style.display      = 'none';
        selectionSummary.style.display = 'none';
        contactInput.parentElement.parentElement.style.display = ''; // show contact row
        cardHeader.textContent = 'Step 1 — Select Client';
        if (window._hideAddPropertyBtn) window._hideAddPropertyBtn();
        hideDetailsSection();
        contactInput.focus();
    }

    clearBtn.addEventListener('click', resetWizard);

    // ── Property select change ────────────────────────────────────────────
    propertySelect.addEventListener('change', function () {
        const val = parseInt(this.value, 10);
        if (!val) {
            hiddenPropertyId.value = '';
            hideDetailsSection();
            return;
        }
        hiddenPropertyId.value = val;
        const prop = loadedProperties.find(p => p.id === val);
        const propLabel = prop ? (prop.address + ', ' + prop.city) : 'Property #' + val;
        showSelectionSummary(selectedContactName, propLabel);
    });

    // ── Contact search ────────────────────────────────────────────────────
    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // SVG icons (inline, no feather dependency)
    const ICON_MAIL = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22,4 12,13 2,4"/></svg>`;
    const ICON_PHONE = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 10a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.28h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.92a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>`;
    const ICON_HOME = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>`;
    const ICON_SEARCH_OFF = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>`;

    function initials(name) {
        return name.trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase();
    }

    function metaIcon(sublabel) {
        if (!sublabel) return '';
        const isPhone = /^[\d\s\(\)\+\-]+$/.test(sublabel.trim());
        return isPhone ? ICON_PHONE : ICON_MAIL;
    }

    function renderResults(results) {
        if (!results.length) {
            searchResults.innerHTML = `
                <div class="mw-client-no-results">
                    ${ICON_SEARCH_OFF}
                    <span>No contacts found</span>
                </div>`;
        } else {
            searchResults.innerHTML = results.map(r => {
                const propLabel = r.property_count === 1 ? '1 property' : r.property_count + ' properties';
                const propPill  = r.property_count > 0
                    ? `<span class="mw-client-result-prop-pill">${ICON_HOME} ${propLabel}</span>`
                    : '';
                const meta = r.sublabel
                    ? `<span class="mw-client-result-meta">${metaIcon(r.sublabel)} ${escHtml(r.sublabel)}</span>`
                    : '';
                return `
                <div class="mw-client-result" data-id="${r.id}" data-name="${escHtml(r.label)}">
                    <div class="mw-client-result-avatar">${escHtml(initials(r.label))}</div>
                    <div class="mw-client-result-body">
                        <div class="mw-client-result-name">${escHtml(r.label)}</div>
                        ${meta}
                    </div>
                    ${propPill}
                </div>`;
            }).join('');
        }
        searchResults.style.display = 'block';

        // Attach click handlers
        searchResults.querySelectorAll('.mw-client-result[data-id]').forEach(el => {
            el.addEventListener('click', function () {
                selectContact(parseInt(this.dataset.id, 10), this.dataset.name);
            });
        });
    }

    function selectContact(contactId, contactName) {
        hiddenContactId.value  = contactId;
        contactInput.value     = contactName;
        selectedContactName    = contactName;
        searchResults.style.display = 'none';
        hiddenPropertyId.value = '';

        // Load properties
        cardHeader.textContent = 'Step 2 — Select Property';
        propertyRow.style.display = '';
        propertySelect.innerHTML = '<option value="">Loading…</option>';
        document.getElementById('contactHint').textContent = '';

        fetch('../api/client-search.php?action=properties&contact_id=' + contactId)
            .then(r => r.json())
            .then(data => {
                loadedProperties = data.properties || [];
                if (!loadedProperties.length) {
                    propertySelect.innerHTML = '<option value="">No active properties for this contact</option>';
                } else {
                    propertySelect.innerHTML = '<option value="">— select a property —</option>' +
                        loadedProperties.map(p =>
                            `<option value="${p.id}">${escHtml(p.address + ', ' + p.city)}${p.property_type ? ' (' + escHtml(p.property_type) + ')' : ''}</option>`
                        ).join('');

                    // Auto-select if only one property
                    if (loadedProperties.length === 1) {
                        propertySelect.value = loadedProperties[0].id;
                        propertySelect.dispatchEvent(new Event('change'));
                    }
                }
                if (window._showAddPropertyBtn) window._showAddPropertyBtn();
            });
    }

    contactInput.addEventListener('input', function () {
        const q = this.value.trim();
        clearTimeout(searchTimer);
        if (hiddenContactId.value) {
            // User is editing after a selection — reset
            hiddenContactId.value = '';
            hiddenPropertyId.value = '';
            propertyRow.style.display = 'none';
            hideDetailsSection();
        }
        if (q.length < 1) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
        searchTimer = setTimeout(() => {
            fetch('../api/client-search.php?action=search&q=' + encodeURIComponent(q) + '&type=contact')
                .then(r => r.json())
                .then(data => renderResults(data.results || []));
        }, 200);
    });

    contactInput.addEventListener('focus', function () {
        if (searchResults.innerHTML && !hiddenContactId.value) {
            searchResults.style.display = 'block';
        }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.mw-client-search-wrapper')) {
            searchResults.style.display = 'none';
        }
    });

    // ── If pre-filled (direct link), trigger property load ────────────────
    <?php if ($prefilledContactId && !$directPropertyId): ?>
    // Contact pre-filled but no property yet — just show property step
    (function () {
        cardHeader.textContent = 'Step 2 — Select Property';
        propertyRow.style.display = '';
        // properties already rendered server-side
        loadedProperties = <?php echo json_encode(array_values($prefilledProperties)); ?>;
        if (window._showAddPropertyBtn) window._showAddPropertyBtn();
    })();
    <?php elseif ($prefilledContactId && $directPropertyId): ?>
    // Both pre-filled — show summary immediately
    (function () {
        loadedProperties = <?php echo json_encode(array_values($prefilledProperties)); ?>;
        const prop = loadedProperties.find(p => p.id === <?php echo (int)$directPropertyId; ?>);
        const propLabel = prop ? (prop.address + ', ' + prop.city) : 'Property #<?php echo (int)$directPropertyId; ?>';
        showSelectionSummary(selectedContactName, propLabel);
    })();
    <?php endif; ?>
})();
</script>
<?php endif; ?>

<!-- Add Property Modal -->
<div class="modal fade" id="addPropertyModal" tabindex="-1" role="dialog" aria-labelledby="addPropertyModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addPropertyModalLabel">Add New Property</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="addPropertyError" class="alert alert-danger d-none mb-3"></div>
                <div class="form-group">
                    <label>Street Address <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newPropAddress" placeholder="e.g. 123 Main St" autocomplete="off">
                </div>
                <div class="row">
                    <div class="col-sm-8">
                        <div class="form-group">
                            <label>City <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="newPropCity" placeholder="e.g. Vancouver" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label>Province</label>
                            <input type="text" class="form-control" id="newPropProvince" value="BC" maxlength="2" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group mb-0">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" id="newPropPostal" placeholder="V1A 1A1" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group mb-0">
                            <label>Property Type</label>
                            <select class="form-control" id="newPropType">
                                <option value="">— select —</option>
                                <option value="residential">Residential</option>
                                <option value="commercial">Commercial</option>
                                <option value="strata">Strata</option>
                                <option value="acreage">Acreage</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveNewPropertyBtn">
                    <i data-feather="save" style="width:14px;height:14px;"></i> Save Property
                </button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const addPropertyBtnRow = document.getElementById('addPropertyBtnRow');
    const addPropertyBtn    = document.getElementById('addPropertyBtn');
    const saveBtn           = document.getElementById('saveNewPropertyBtn');
    const errEl             = document.getElementById('addPropertyError');

    if (!addPropertyBtn) return; // quote-originated page — no button

    addPropertyBtn.addEventListener('click', function () {
        errEl.classList.add('d-none');
        document.getElementById('newPropAddress').value  = '';
        document.getElementById('newPropCity').value     = '';
        document.getElementById('newPropProvince').value = 'BC';
        document.getElementById('newPropPostal').value   = '';
        document.getElementById('newPropType').value     = '';
        $('#addPropertyModal').modal('show');
        setTimeout(function () { document.getElementById('newPropAddress').focus(); }, 400);
    });

    saveBtn.addEventListener('click', function () {
        const address  = document.getElementById('newPropAddress').value.trim();
        const city     = document.getElementById('newPropCity').value.trim();
        const province = document.getElementById('newPropProvince').value.trim() || 'BC';
        const postal   = document.getElementById('newPropPostal').value.trim();
        const type     = document.getElementById('newPropType').value;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        const contactId = parseInt(document.getElementById('hiddenContactId').value, 10);

        errEl.classList.add('d-none');

        if (!address || !city) {
            errEl.textContent = 'Street address and city are required.';
            errEl.classList.remove('d-none');
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = 'Saving…';

        fetch('../api/client-search.php?action=create-property', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrfToken, contact_id: contactId, address, city, province, postal_code: postal, property_type: type })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-feather="save" style="width:14px;height:14px;"></i> Save Property';
            feather.replace();

            if (!data.success) {
                errEl.textContent = data.error || 'Failed to save property.';
                errEl.classList.remove('d-none');
                return;
            }

            // The main page script exposes loadedProperties and propertySelect via the outer IIFE.
            // We need to add the new property to the select and auto-select it.
            const p = data.property;
            const label = p.address + ', ' + p.city + (p.property_type ? ' (' + p.property_type + ')' : '');
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = label;
            const sel = document.getElementById('propertySelect');
            sel.appendChild(opt);
            sel.value = p.id;
            sel.dispatchEvent(new Event('change'));

            $('#addPropertyModal').modal('hide');
        })
        .catch(function () {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-feather="save" style="width:14px;height:14px;"></i> Save Property';
            feather.replace();
            errEl.textContent = 'Network error. Please try again.';
            errEl.classList.remove('d-none');
        });
    });

    // Expose show-button function so the main wizard JS can call it
    window._showAddPropertyBtn = function () {
        addPropertyBtnRow.style.display = '';
        feather.replace();
    };
    window._hideAddPropertyBtn = function () {
        addPropertyBtnRow.style.display = 'none';
    };
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
