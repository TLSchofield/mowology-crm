<?php
/**
 * Media Library
 * Mowology CRM — AppStack layout
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();

$csrfToken  = generateCSRFToken();
$pageTitle  = 'Media Library';
$activePage = 'cms';
?>
<?php include 'includes/appstack_head.php'; ?>

          <h1 class="h3 mb-3">Media Library</h1>
          <p class="text-muted mb-4">Upload and manage images for bundles, CMS pages, and product listings.</p>

          <!-- Upload Card -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Upload Media</h5>
            </div>
            <div class="card-body">
              <form id="mediaUploadForm" enctype="multipart/form-data">
                <div class="row align-items-end">
                  <div class="col-md-5 mb-3 mb-md-0">
                    <label for="mediaFile" class="mb-1"><strong>File</strong></label>
                    <input type="file" class="form-control-file" id="mediaFile"
                           name="media_file" accept="image/*,.pdf,.doc,.docx" required>
                    <small class="form-text text-muted">JPG, PNG, GIF, WebP — max 5 MB</small>
                  </div>
                  <div class="col-md-4 mb-3 mb-md-0">
                    <label for="mediaAltText" class="mb-1"><strong>Alt Text / Label</strong></label>
                    <input type="text" class="form-control" id="mediaAltText"
                           placeholder="e.g. Fertilizer icon">
                  </div>
                  <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-block">
                      <i data-feather="upload-cloud" style="width:14px;height:14px;vertical-align:middle;"></i>
                      Upload
                    </button>
                  </div>
                </div>
                <div id="uploadResult" class="mt-2" style="display:none;"></div>
              </form>
            </div>
          </div>

          <!-- Search / Filter Bar -->
          <div class="card mb-4">
            <div class="card-body py-2">
              <div class="row align-items-center">
                <div class="col-md-6 mb-2 mb-md-0">
                  <input type="text" id="mediaSearch" class="form-control form-control-sm"
                         placeholder="Search by filename or alt text…"
                         oninput="debounceSearch()">
                </div>
                <div class="col-md-3 mb-2 mb-md-0">
                  <select id="mediaTypeFilter" class="form-control form-control-sm"
                          onchange="loadMedia(1)">
                    <option value="">All types</option>
                    <option value="image">Images</option>
                    <option value="document">Documents</option>
                  </select>
                </div>
                <div class="col-md-3 text-right">
                  <span id="mediaCount" class="text-muted small"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Media Grid -->
          <div class="card">
            <div class="card-header">
              <h5 class="mb-0">Media Files</h5>
            </div>
            <div class="card-body">
              <div id="mediaGrid" class="mw-media-grid">
                <p class="text-muted">Loading&hellip;</p>
              </div>
              <div id="mediaPagination" class="d-flex justify-content-center mt-3"></div>
            </div>
          </div>

          <!-- Delete Confirm Modal -->
          <div class="modal fade" id="deleteMediaModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-sm" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Delete Image?</h5>
                  <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <p class="mb-0">Delete <strong id="deleteMediaName"></strong>?
                  This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-sm btn-danger" id="confirmDeleteBtn"
                          onclick="confirmDeleteMedia()">Delete</button>
                </div>
              </div>
            </div>
          </div>

          <script>
          const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
          const LIST_API   = '/crm/api/cms_media_list.php';
          const UPLOAD_API = '/crm/api/upload-media.php';
          const DELETE_API = '/crm/api/delete-media.php';

          let currentPage    = 1;
          let deleteTargetId = null;
          let searchTimer    = null;

          // ── Load & Render ─────────────────────────────────────────────────────
          async function loadMedia(page) {
            page = page || 1;
            currentPage = page;

            const search = document.getElementById('mediaSearch').value.trim();
            const type   = document.getElementById('mediaTypeFilter').value;
            const grid   = document.getElementById('mediaGrid');

            grid.innerHTML = '<p class="text-muted">Loading&hellip;</p>';

            const params = new URLSearchParams({ page: page, per_page: 24 });
            if (search) params.set('search', search);
            if (type)   params.set('type', type);

            try {
              const res  = await fetch(LIST_API + '?' + params);
              const data = await res.json();

              if (!data.success) {
                grid.innerHTML = '<p class="text-danger">Failed to load: ' + escHtml(data.error || 'Unknown error') + '</p>';
                return;
              }

              const items      = data.data || [];
              const pagination = data.pagination || {};

              document.getElementById('mediaCount').textContent =
                pagination.total_items + ' file' + (pagination.total_items !== 1 ? 's' : '');

              if (items.length === 0) {
                grid.innerHTML = '<p class="text-muted text-center py-4">No media files yet. Upload your first image above.</p>';
                document.getElementById('mediaPagination').innerHTML = '';
                return;
              }

              grid.innerHTML = items.map(renderMediaCard).join('');
              hydrateFeatherIcons(grid);
              renderPagination(pagination);

            } catch (err) {
              grid.innerHTML = '<p class="text-danger">Error loading media. Please refresh.</p>';
              console.error('[Media Library]', err);
            }
          }

          function renderMediaCard(item) {
            var isImage   = (item.type === 'image');
            var name      = item.filename || 'Untitled';
            var shortName = name.length > 24 ? name.substring(0, 22) + '…' : name;
            var sizeStr   = formatFileSize(item.size);
            var dimStr    = (item.width && item.height) ? item.width + '×' + item.height : '';
            var altStr    = item.alt_text || '';
            var shortAlt  = altStr.length > 32 ? altStr.substring(0, 30) + '…' : altStr;

            var thumb = isImage
              ? '<img src="' + escHtml(item.file_path) + '" alt="' + escHtml(altStr || name) + '" class="mw-media-thumb" loading="lazy" onerror="this.parentNode.innerHTML=\'<div class=mw-media-no-thumb><i data-feather=file style=width:28px;height:28px;></i></div>\'">'
              : '<div class="mw-media-no-thumb"><i data-feather="file" style="width:28px;height:28px;"></i></div>';

            var metaParts = [];
            if (sizeStr) metaParts.push(sizeStr);
            if (dimStr)  metaParts.push(dimStr);

            return '<div class="mw-media-card" data-id="' + item.id + '">' +
              '<div class="mw-media-thumb-wrap">' + thumb + '</div>' +
              '<div class="mw-media-card-body">' +
                '<div class="mw-media-name" title="' + escHtml(name) + '">' + escHtml(shortName) + '</div>' +
                (metaParts.length ? '<div class="mw-media-meta">' + escHtml(metaParts.join(' · ')) + '</div>' : '') +
                (altStr ? '<div class="mw-media-alt" title="' + escHtml(altStr) + '">' + escHtml(shortAlt) + '</div>' : '') +
              '</div>' +
              '<div class="mw-media-actions">' +
                '<a href="' + escHtml(item.file_path) + '" target="_blank" rel="noopener" class="btn btn-xs btn-outline-secondary" title="View full size">' +
                  '<i data-feather="external-link" style="width:11px;height:11px;"></i>' +
                '</a>' +
                '<button type="button" class="btn btn-xs btn-outline-danger ml-1"' +
                  ' onclick="promptDeleteMedia(' + item.id + ', \'' + escHtml(name).replace(/'/g, '&#39;') + '\')"' +
                  ' title="Delete">' +
                  '<i data-feather="trash-2" style="width:11px;height:11px;"></i>' +
                '</button>' +
              '</div>' +
            '</div>';
          }

          // ── Pagination ────────────────────────────────────────────────────────
          function renderPagination(pagination) {
            var el = document.getElementById('mediaPagination');
            if (!pagination || pagination.total_pages <= 1) {
              el.innerHTML = '';
              return;
            }
            var html = '<nav><ul class="pagination pagination-sm">';
            for (var i = 1; i <= pagination.total_pages; i++) {
              html += '<li class="page-item' + (i === pagination.page ? ' active' : '') + '">' +
                '<button type="button" class="page-link" onclick="loadMedia(' + i + ')">' + i + '</button>' +
                '</li>';
            }
            html += '</ul></nav>';
            el.innerHTML = html;
          }

          // ── Upload ────────────────────────────────────────────────────────────
          document.getElementById('mediaUploadForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            var fileInput = document.getElementById('mediaFile');
            var altText   = document.getElementById('mediaAltText').value.trim();
            var btn       = this.querySelector('button[type=submit]');

            if (!fileInput.files || !fileInput.files.length) {
              showUploadResult('error', 'Please select a file to upload.');
              return;
            }

            var formData = new FormData();
            formData.append('media_file', fileInput.files[0]);
            formData.append('csrf_token', CSRF_TOKEN);
            if (altText) formData.append('alt_text', altText);

            btn.disabled = true;
            btn.innerHTML = '<i data-feather="loader" style="width:14px;height:14px;vertical-align:middle;"></i> Uploading…';
            hydrateFeatherIcons(btn.parentNode);
            showUploadResult('info', 'Uploading…');

            try {
              var res  = await fetch(UPLOAD_API, { method: 'POST', body: formData });
              var data = await res.json();

              if (data.success) {
                showUploadResult('success', '✓ ' + escHtml(data.filename || 'File') + ' uploaded successfully');
                fileInput.value = '';
                document.getElementById('mediaAltText').value = '';
                loadMedia(1);
              } else {
                showUploadResult('error', data.error || 'Upload failed');
              }
            } catch (err) {
              showUploadResult('error', 'Network error — please try again');
              console.error('[Media Upload]', err);
            } finally {
              btn.disabled = false;
              btn.innerHTML = '<i data-feather="upload-cloud" style="width:14px;height:14px;vertical-align:middle;"></i> Upload';
              hydrateFeatherIcons(btn.parentNode);
            }
          });

          function showUploadResult(type, msg) {
            var el  = document.getElementById('uploadResult');
            var cls = type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : 'alert-info';
            el.innerHTML = '<div class="alert ' + cls + ' py-2 mb-0">' + msg + '</div>';
            el.style.display = 'block';
            if (type === 'success') {
              setTimeout(function() { el.style.display = 'none'; }, 4000);
            }
          }

          // ── Delete ────────────────────────────────────────────────────────────
          function promptDeleteMedia(id, name) {
            deleteTargetId = id;
            document.getElementById('deleteMediaName').textContent = name;
            $('#deleteMediaModal').modal('show');
          }

          async function confirmDeleteMedia() {
            if (!deleteTargetId) return;

            var btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.textContent = 'Deleting…';

            try {
              var res  = await fetch(DELETE_API, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ id: deleteTargetId })
              });
              var data = await res.json();

              $('#deleteMediaModal').modal('hide');

              if (data.success) {
                var card = document.querySelector('.mw-media-card[data-id="' + deleteTargetId + '"]');
                if (card) card.remove();
                // If grid is now empty, reload (to show the empty message and update count)
                if (!document.querySelector('.mw-media-card')) {
                  loadMedia(currentPage);
                } else {
                  // Update count manually
                  var countEl = document.getElementById('mediaCount');
                  var match = (countEl.textContent || '').match(/(\d+)/);
                  if (match) {
                    var newCount = parseInt(match[1]) - 1;
                    countEl.textContent = newCount + ' file' + (newCount !== 1 ? 's' : '');
                  }
                }
              } else {
                alert('Delete failed: ' + (data.error || 'Unknown error'));
              }
            } catch (err) {
              alert('Network error — please try again');
              console.error('[Media Delete]', err);
            } finally {
              btn.disabled = false;
              btn.textContent = 'Delete';
              deleteTargetId = null;
            }
          }

          // ── Utilities ─────────────────────────────────────────────────────────
          function escHtml(str) {
            return String(str)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
          }

          function formatFileSize(bytes) {
            if (!bytes || bytes <= 0) return '';
            if (bytes < 1024)       return bytes + ' B';
            if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
          }

          function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { loadMedia(1); }, 350);
          }

          // ── Init ──────────────────────────────────────────────────────────────
          loadMedia(1);
          </script>

<?php include 'includes/appstack_footer.php'; ?>
