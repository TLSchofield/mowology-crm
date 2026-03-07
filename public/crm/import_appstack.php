<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

if (!function_exists('userHasPermission') || !userHasPermission('settings.edit')) {
    $_SESSION['alert'] = ['type' => 'danger', 'message' => 'You do not have permission to import data.'];
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

$pageTitle  = 'Import Data';
$activePage = 'import';

$csrfToken = generateCSRFToken();
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <h1 class="h3 mb-0">Import Contacts</h1>
              <a href="/crm/clients_appstack.php" class="btn btn-outline-secondary btn-sm">Back to Contacts</a>
          </div>

          <!-- Step Indicator -->
          <div class="mw-import-steps mb-4">
              <div class="mw-import-step active" id="step1Indicator">1. Upload CSV</div>
              <div class="mw-import-step" id="step2Indicator">2. Map Fields</div>
              <div class="mw-import-step" id="step3Indicator">3. Confirm &amp; Import</div>
          </div>

          <!-- Step 1: Upload -->
          <div id="importStep1">
              <div class="card">
                  <div class="card-body text-center">
                      <div class="mw-import-dropzone" id="dropzone"
                           ondragover="event.preventDefault();this.classList.add('dragover')"
                           ondragleave="this.classList.remove('dragover')"
                           ondrop="event.preventDefault();this.classList.remove('dragover');mwHandleFile(event.dataTransfer.files[0])">
                          <i data-feather="upload" style="width:48px;height:48px;color:#9ca3af;"></i>
                          <p class="mt-3 mb-1">Drag &amp; drop a CSV file here</p>
                          <p class="text-muted small mb-3">or click to browse (max 2MB)</p>
                          <input type="file" id="csvFileInput" accept=".csv" style="display:none" onchange="mwHandleFile(this.files[0])">
                          <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('csvFileInput').click()">
                              Choose File
                          </button>
                      </div>
                      <p class="text-muted small mt-3">
                          CSV should have a header row. Supported columns: First Name, Last Name, Email, Phone, Mobile, Notes.
                      </p>
                  </div>
              </div>
          </div>

          <!-- Step 2: Map Fields -->
          <div id="importStep2" style="display:none;">
              <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="card-title mb-0">Map CSV Columns to CRM Fields</h5>
                      <span class="text-muted small" id="totalRowsLabel"></span>
                  </div>
                  <div class="card-body">
                      <div id="mappingRows"></div>
                      <hr>
                      <h6>Preview (first 5 rows)</h6>
                      <div class="table-responsive">
                          <table class="table table-sm" id="previewTable"><thead></thead><tbody></tbody></table>
                      </div>
                  </div>
                  <div class="card-footer text-right">
                      <button class="btn btn-secondary mr-2" onclick="mwGoStep(1)">Back</button>
                      <button class="btn btn-primary" onclick="mwRunImport()">Import Contacts</button>
                  </div>
              </div>
          </div>

          <!-- Step 3: Results -->
          <div id="importStep3" style="display:none;">
              <div class="card">
                  <div class="card-header"><h5 class="card-title mb-0">Import Results</h5></div>
                  <div class="card-body" id="importResults"></div>
                  <div class="card-footer text-right">
                      <a href="/crm/clients_appstack.php" class="btn btn-primary">View Contacts</a>
                      <button class="btn btn-outline-secondary ml-2" onclick="location.reload()">Import More</button>
                  </div>
              </div>
          </div>

          <script>
          var _mwCsrf = <?= json_encode($csrfToken) ?>;
          var _mwUploadData = null;

          function mwGoStep(n) {
              document.getElementById('importStep1').style.display = n===1 ? '' : 'none';
              document.getElementById('importStep2').style.display = n===2 ? '' : 'none';
              document.getElementById('importStep3').style.display = n===3 ? '' : 'none';
              ['step1Indicator','step2Indicator','step3Indicator'].forEach(function(id,i){
                  var el = document.getElementById(id);
                  el.classList.remove('active','complete');
                  if (i < n-1) el.classList.add('complete');
                  if (i === n-1) el.classList.add('active');
              });
          }

          function mwHandleFile(file) {
              if (!file || !file.name.endsWith('.csv')) {
                  alert('Please upload a .csv file');
                  return;
              }
              var fd = new FormData();
              fd.append('csv_file', file);
              fd.append('action', 'upload');

              fetch('/crm/api/import-contacts.php?action=upload', {method:'POST', body:fd})
              .then(function(r){return r.json()})
              .then(function(data) {
                  if (!data.success) { alert(data.error || 'Upload failed'); return; }
                  _mwUploadData = data;
                  document.getElementById('totalRowsLabel').textContent = data.total_rows + ' rows found';

                  // Build mapping rows
                  var html = '';
                  data.headers.forEach(function(h, i) {
                      html += '<div class="mw-import-mapping-row">';
                      html += '<div class="mw-import-mapping-csv">' + h + '</div>';
                      html += '<div class="mw-import-mapping-arrow">&rarr;</div>';
                      html += '<div class="mw-import-mapping-field"><select class="form-control form-control-sm mw-mapping-select" data-col="' + i + '">';
                      Object.keys(data.crm_fields).forEach(function(k) {
                          var sel = (data.mapping[i] === k) ? ' selected' : '';
                          html += '<option value="' + k + '"' + sel + '>' + data.crm_fields[k] + '</option>';
                      });
                      html += '</select></div></div>';
                  });
                  document.getElementById('mappingRows').innerHTML = html;

                  // Build preview table
                  var thead = '<tr>' + data.headers.map(function(h){return '<th class="small">' + h + '</th>'}).join('') + '</tr>';
                  var tbody = data.preview.map(function(row){
                      return '<tr>' + row.map(function(c){return '<td class="small">' + (c||'') + '</td>'}).join('') + '</tr>';
                  }).join('');
                  document.querySelector('#previewTable thead').innerHTML = thead;
                  document.querySelector('#previewTable tbody').innerHTML = tbody;

                  mwGoStep(2);
              });
          }

          function mwRunImport() {
              var selects = document.querySelectorAll('.mw-mapping-select');
              var mapping = {};
              selects.forEach(function(s){ mapping[s.dataset.col] = s.value; });

              var btn = event.target;
              btn.disabled = true;
              btn.textContent = 'Importing...';

              fetch('/crm/api/import-contacts.php?action=execute', {
                  method: 'POST',
                  headers: {'Content-Type':'application/json'},
                  body: JSON.stringify({csrf_token: _mwCsrf, mapping: mapping})
              })
              .then(function(r){return r.json()})
              .then(function(data) {
                  if (!data.success) { alert(data.error || 'Import failed'); btn.disabled = false; btn.textContent = 'Import Contacts'; return; }

                  var html = '<div class="row text-center mb-3">';
                  html += '<div class="col"><div class="h4 text-success">' + data.imported + '</div><div class="small text-muted">Imported</div></div>';
                  html += '<div class="col"><div class="h4 text-info">' + data.updated + '</div><div class="small text-muted">Updated</div></div>';
                  html += '<div class="col"><div class="h4 text-warning">' + data.skipped + '</div><div class="small text-muted">Skipped</div></div>';
                  html += '<div class="col"><div class="h4 text-danger">' + (data.errors||[]).length + '</div><div class="small text-muted">Errors</div></div>';
                  html += '</div>';

                  if (data.errors && data.errors.length) {
                      html += '<div class="alert alert-warning small"><strong>Errors:</strong><ul class="mb-0">';
                      data.errors.slice(0,10).forEach(function(e){
                          html += '<li>Row ' + e.row + ': ' + e.error + '</li>';
                      });
                      html += '</ul></div>';
                  }

                  document.getElementById('importResults').innerHTML = html;
                  mwGoStep(3);
              });
          }
          </script>

<?php include 'includes/appstack_footer.php'; ?>
