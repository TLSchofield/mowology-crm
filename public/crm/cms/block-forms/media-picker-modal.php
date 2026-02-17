<?php
/**
 * Reusable Media Picker Modal
 *
 * Include once on any CMS page that needs media selection.
 * JavaScript API:
 *   openMediaPicker(callback)  — opens modal, calls callback({id, file_path, alt_text}) on select
 *
 * Requires: Bootstrap 4 modal, CSRF token available as window.csrfToken
 */
?>

<!-- Media Picker Modal -->
<div class="modal fade" id="mediaPickerModal" tabindex="-1" role="dialog" aria-labelledby="mediaPickerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mediaPickerModalLabel">Select Media</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <!-- Upload Tab + Browse Tab -->
        <ul class="nav nav-tabs mb-3" id="mediaPickerTabs">
          <li class="nav-item">
            <a class="nav-link active" data-toggle="tab" href="#mp-browse">Browse Library</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-toggle="tab" href="#mp-upload">Upload New</a>
          </li>
        </ul>

        <div class="tab-content">
          <!-- Browse Tab -->
          <div class="tab-pane fade show active" id="mp-browse">
            <div class="mb-3">
              <input type="text" class="form-control" id="mp-search" placeholder="Search images by filename or alt text...">
            </div>
            <div id="mp-grid" class="mw-media-grid">
              <p class="text-muted text-center py-4">Loading media...</p>
            </div>
            <div id="mp-pagination" class="text-center mt-3"></div>
          </div>

          <!-- Upload Tab -->
          <div class="tab-pane fade" id="mp-upload">
            <div class="mw-upload-zone" id="mp-drop-zone">
              <div class="mw-upload-zone-inner">
                <i data-feather="upload-cloud" style="width:48px;height:48px;" class="text-muted mb-2"></i>
                <p class="mb-1"><strong>Drag & drop an image here</strong></p>
                <p class="text-muted small mb-3">or click to browse files</p>
                <input type="file" id="mp-file-input" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('mp-file-input').click()">Choose File</button>
              </div>
            </div>
            <div class="form-group mt-3">
              <label for="mp-upload-alt">Alt Text</label>
              <input type="text" class="form-control" id="mp-upload-alt" placeholder="Describe the image for accessibility">
            </div>
            <div id="mp-upload-preview" class="d-none mt-3">
              <img id="mp-upload-preview-img" src="" alt="Preview" style="max-height:200px;border-radius:4px;">
              <p class="small text-muted mt-1" id="mp-upload-filename"></p>
            </div>
            <div id="mp-upload-progress" class="d-none mt-3">
              <div class="progress">
                <div class="progress-bar bg-success" role="progressbar" style="width: 0%"></div>
              </div>
            </div>
            <button type="button" class="btn btn-primary mt-3" id="mp-upload-btn" disabled>Upload & Select</button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
    var _mpCallback = null;
    var _mpPage = 1;
    var _mpSearchTimeout = null;
    var _mpSelectedFile = null;

    // Public API: open the picker
    window.openMediaPicker = function(callback) {
        _mpCallback = callback;
        _mpPage = 1;
        loadMediaGrid();
        $('#mediaPickerModal').modal('show');
    };

    // Load media grid via AJAX
    function loadMediaGrid(search, page) {
        search = search || '';
        page = page || 1;
        _mpPage = page;

        var url = '/crm/api/cms_media_list.php?type=image&page=' + page + '&per_page=12';
        if (search) url += '&search=' + encodeURIComponent(search);

        var grid = document.getElementById('mp-grid');
        grid.innerHTML = '<p class="text-muted text-center py-4">Loading...</p>';

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success || !res.data || res.data.length === 0) {
                    grid.innerHTML = '<p class="text-muted text-center py-4">No images found. Upload one first!</p>';
                    document.getElementById('mp-pagination').innerHTML = '';
                    return;
                }

                var html = '';
                res.data.forEach(function(item) {
                    html += '<div class="mw-media-item" data-id="' + item.id + '" data-path="' + item.file_path + '" data-alt="' + (item.alt_text || '') + '">' +
                        '<img src="' + item.file_path + '" alt="' + (item.alt_text || item.filename) + '" loading="lazy">' +
                        '<div class="mw-media-item-name">' + item.filename + '</div>' +
                    '</div>';
                });
                grid.innerHTML = html;

                // Pagination
                var pag = res.pagination;
                var pagHtml = '';
                if (pag && pag.total_pages > 1) {
                    for (var p = 1; p <= pag.total_pages; p++) {
                        pagHtml += '<button class="btn btn-sm ' + (p === pag.page ? 'btn-primary' : 'btn-outline-secondary') + ' mx-1 mp-page-btn" data-page="' + p + '">' + p + '</button>';
                    }
                }
                document.getElementById('mp-pagination').innerHTML = pagHtml;
            })
            .catch(function(err) {
                grid.innerHTML = '<p class="text-danger text-center py-4">Error loading media</p>';
                console.error('Media picker error:', err);
            });
    }

    // Click to select an image
    document.getElementById('mp-grid').addEventListener('click', function(e) {
        var item = e.target.closest('.mw-media-item');
        if (!item) return;

        var selected = {
            id: item.dataset.id,
            file_path: item.dataset.path,
            alt_text: item.dataset.alt || ''
        };

        if (_mpCallback) _mpCallback(selected);
        $('#mediaPickerModal').modal('hide');
    });

    // Search
    document.getElementById('mp-search').addEventListener('input', function() {
        var val = this.value;
        clearTimeout(_mpSearchTimeout);
        _mpSearchTimeout = setTimeout(function() {
            loadMediaGrid(val, 1);
        }, 300);
    });

    // Pagination clicks
    document.getElementById('mp-pagination').addEventListener('click', function(e) {
        var btn = e.target.closest('.mp-page-btn');
        if (!btn) return;
        var search = document.getElementById('mp-search').value;
        loadMediaGrid(search, parseInt(btn.dataset.page));
    });

    // ── Upload functionality ──

    var dropZone = document.getElementById('mp-drop-zone');
    var fileInput = document.getElementById('mp-file-input');
    var uploadBtn = document.getElementById('mp-upload-btn');

    // Drag and drop
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.classList.add('mw-upload-zone-active');
    });
    dropZone.addEventListener('dragleave', function() {
        dropZone.classList.remove('mw-upload-zone-active');
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('mw-upload-zone-active');
        if (e.dataTransfer.files.length > 0) {
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });

    // File input change
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            handleFileSelect(this.files[0]);
        }
    });

    function handleFileSelect(file) {
        if (!file.type.startsWith('image/')) {
            alert('Please select an image file (JPG, PNG, GIF, WEBP)');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be under 5MB');
            return;
        }

        _mpSelectedFile = file;
        uploadBtn.disabled = false;

        // Show preview
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('mp-upload-preview-img').src = e.target.result;
            document.getElementById('mp-upload-filename').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
            document.getElementById('mp-upload-preview').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    }

    // Upload button
    uploadBtn.addEventListener('click', function() {
        if (!_mpSelectedFile) return;

        var formData = new FormData();
        formData.append('media_file', _mpSelectedFile);
        formData.append('alt_text', document.getElementById('mp-upload-alt').value);
        formData.append('csrf_token', window.csrfToken || '');

        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';

        var progressEl = document.getElementById('mp-upload-progress');
        progressEl.classList.remove('d-none');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/crm/api/upload-media.php');

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                progressEl.querySelector('.progress-bar').style.width = pct + '%';
            }
        });

        xhr.addEventListener('load', function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    // Select the newly uploaded image
                    if (_mpCallback) {
                        _mpCallback({
                            id: res.media_id,
                            file_path: res.file_path,
                            alt_text: document.getElementById('mp-upload-alt').value
                        });
                    }
                    $('#mediaPickerModal').modal('hide');

                    // Reset upload form
                    _mpSelectedFile = null;
                    document.getElementById('mp-upload-preview').classList.add('d-none');
                    document.getElementById('mp-upload-alt').value = '';
                    progressEl.classList.add('d-none');
                    uploadBtn.textContent = 'Upload & Select';
                    uploadBtn.disabled = true;
                    fileInput.value = '';
                } else {
                    alert('Upload failed: ' + (res.error || 'Unknown error'));
                    uploadBtn.disabled = false;
                    uploadBtn.textContent = 'Upload & Select';
                }
            } catch (e) {
                alert('Upload failed: Invalid server response');
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload & Select';
            }
        });

        xhr.addEventListener('error', function() {
            alert('Upload failed: Network error');
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Upload & Select';
        });

        xhr.send(formData);
    });

    // Reset on modal close
    $('#mediaPickerModal').on('hidden.bs.modal', function() {
        _mpCallback = null;
        _mpSelectedFile = null;
        document.getElementById('mp-upload-preview').classList.add('d-none');
        document.getElementById('mp-upload-alt').value = '';
        document.getElementById('mp-upload-progress').classList.add('d-none');
        uploadBtn.textContent = 'Upload & Select';
        uploadBtn.disabled = true;
        fileInput.value = '';
    });
})();
</script>
