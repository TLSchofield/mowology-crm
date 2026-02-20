/**
 * Mowology Media Uploader
 *
 * Self-initializing vanilla JS component for drag-and-drop + file picker
 * multi-file upload with per-file XHR progress, browser geolocation,
 * and mobile camera capture support.
 *
 * Usage: Place a .mw-media-dropzone element on the page with data attributes:
 *   data-context-type   - Context type (job_visit, quote_visit, marketing_general, etc.)
 *   data-context-id     - Context ID
 *   data-category       - Default category (before, after, etc.)
 *   data-visibility     - Default visibility (internal, client_visible, marketing_eligible)
 *   data-csrf           - CSRF token
 *   data-upload-url     - Upload endpoint URL
 *   data-max-size       - Max file size in bytes
 *   data-allowed-types  - Comma-separated MIME types
 *
 * Events dispatched on the dropzone element:
 *   mediaUploaded  - detail: { media_id, uuid, filename, thumb_url, ... }
 *   mediaError     - detail: { filename, errors }
 *   allUploadsComplete - detail: { total, succeeded, failed }
 *
 * @package Mowology CRM
 */
(function() {
    'use strict';

    // Initialize all dropzones on the page
    function init() {
        document.querySelectorAll('.mw-media-dropzone').forEach(initDropzone);
    }

    function initDropzone(zone) {
        var fileInput = zone.querySelector('.mw-dropzone-input');
        var cameraInput = zone.querySelector('.mw-dropzone-camera-input');
        var browseBtn = zone.querySelector('.mw-dropzone-browse');
        var cameraBtn = zone.querySelector('.mw-dropzone-camera');
        var queue = zone.querySelector('.mw-dropzone-queue');
        var gpsToggle = zone.querySelector('.mw-dropzone-gps-toggle');
        var powToggle = zone.querySelector('.mw-dropzone-pow-toggle');

        var config = {
            contextType: zone.dataset.contextType || 'marketing_general',
            contextId: zone.dataset.contextId || '0',
            category: zone.dataset.category || '',
            visibility: zone.dataset.visibility || 'internal',
            csrf: zone.dataset.csrf || '',
            uploadUrl: zone.dataset.uploadUrl || '/crm/api/media-upload.php',
            maxSize: parseInt(zone.dataset.maxSize || '15728640', 10),
            allowedTypes: (zone.dataset.allowedTypes || 'image/jpeg,image/png,image/webp').split(',')
        };

        var cachedGps = null;
        var gpsRequested = false;
        var pendingUploads = 0;
        var uploadStats = { total: 0, succeeded: 0, failed: 0 };

        // --- GPS: request once on init if toggle checked ---
        function requestGps() {
            if (gpsRequested || !navigator.geolocation) return;
            gpsRequested = true;
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    cachedGps = {
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy: pos.coords.accuracy
                    };
                },
                function() { cachedGps = null; },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        }

        if (gpsToggle && gpsToggle.checked) {
            requestGps();
        }
        if (gpsToggle) {
            gpsToggle.addEventListener('change', function() {
                if (this.checked) requestGps();
                else cachedGps = null;
            });
        }

        // --- Drag and drop ---
        ['dragenter', 'dragover'].forEach(function(evtName) {
            zone.addEventListener(evtName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('mw-dropzone-active');
            });
        });

        ['dragleave', 'drop'].forEach(function(evtName) {
            zone.addEventListener(evtName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('mw-dropzone-active');
            });
        });

        zone.addEventListener('drop', function(e) {
            if (e.dataTransfer && e.dataTransfer.files) {
                handleFiles(e.dataTransfer.files);
            }
        });

        // --- Browse button ---
        if (browseBtn && fileInput) {
            browseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                fileInput.click();
            });
            fileInput.addEventListener('change', function() {
                if (this.files && this.files.length) {
                    handleFiles(this.files);
                    this.value = ''; // Reset so same files can be picked again
                }
            });
        }

        // --- Camera button (mobile) ---
        if (cameraBtn && cameraInput) {
            cameraBtn.addEventListener('click', function(e) {
                e.preventDefault();
                cameraInput.click();
            });
            cameraInput.addEventListener('change', function() {
                if (this.files && this.files.length) {
                    handleFiles(this.files);
                    this.value = '';
                }
            });
        }

        // --- Blur detection via Canvas pixel-variance analysis ---
        // Samples a downscaled greyscale version of the image and computes
        // variance. Low variance = flat image = likely blurry/dark/covered lens.
        // Threshold of 80 catches most blurry photos without false positives.
        // Returns a Promise<boolean> — true if the image appears blurry.
        function isImageBlurry(file, threshold) {
            threshold = threshold || 80;
            return new Promise(function(resolve) {
                // Only run on JPEG/PNG/WebP — skip for unsupported types
                if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
                    resolve(false);
                    return;
                }
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload = function() {
                    try {
                        // Downscale to 100x100 for fast variance calculation
                        var canvas = document.createElement('canvas');
                        canvas.width = 100;
                        canvas.height = 100;
                        var ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, 100, 100);
                        URL.revokeObjectURL(url);

                        var data = ctx.getImageData(0, 0, 100, 100).data;
                        // Convert to greyscale and compute mean
                        var grey = [];
                        for (var i = 0; i < data.length; i += 4) {
                            grey.push(0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]);
                        }
                        var mean = grey.reduce(function(a, b) { return a + b; }, 0) / grey.length;
                        var variance = grey.reduce(function(sum, v) { return sum + (v - mean) * (v - mean); }, 0) / grey.length;

                        resolve(variance < threshold);
                    } catch (e) {
                        // Canvas tainted or unsupported — skip blur check
                        resolve(false);
                    }
                };
                img.onerror = function() {
                    URL.revokeObjectURL(url);
                    resolve(false);
                };
                img.src = url;
            });
        }

        // --- File handling ---
        function handleFiles(fileList) {
            var files = Array.prototype.slice.call(fileList);
            files.forEach(function(file) {
                // Validate type
                if (config.allowedTypes.indexOf(file.type) === -1) {
                    showFileError(file.name, 'Invalid file type: ' + file.type);
                    return;
                }
                // Validate size
                if (file.size > config.maxSize) {
                    var maxMB = Math.round(config.maxSize / 1024 / 1024);
                    showFileError(file.name, 'Too large (max ' + maxMB + ' MB)');
                    return;
                }
                // Blur detection — warn before wasting an API call on a bad photo.
                // Only applied when context is 'expense' (receipt capture).
                if (config.contextType === 'expense') {
                    isImageBlurry(file).then(function(blurry) {
                        if (blurry) {
                            if (!window.confirm('This photo looks blurry or dark.\nUpload anyway, or retake for a better result?')) {
                                return; // User chose to retake
                            }
                        }
                        uploadFile(file);
                    });
                } else {
                    uploadFile(file);
                }
            });
        }

        function uploadFile(file) {
            var item = createQueueItem(file.name, file.size);
            queue.appendChild(item);
            pendingUploads++;
            uploadStats.total++;

            var formData = new FormData();
            formData.append('files[]', file);
            formData.append('csrf_token', config.csrf);
            formData.append('context_type', config.contextType);
            formData.append('context_id', config.contextId);
            formData.append('category', config.category);
            formData.append('visibility', config.visibility);

            // GPS
            if (gpsToggle && gpsToggle.checked && cachedGps) {
                formData.append('gps_lat', String(cachedGps.lat));
                formData.append('gps_lng', String(cachedGps.lng));
                formData.append('gps_accuracy', String(cachedGps.accuracy));
            }

            // PoW stamp
            if (powToggle && powToggle.checked) {
                formData.append('pow_stamp', '1');
            }

            var xhr = new XMLHttpRequest();

            // Progress handler
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    var bar = item.querySelector('.mw-upload-progress-bar');
                    var pctLabel = item.querySelector('.mw-upload-pct');
                    if (bar) bar.style.width = pct + '%';
                    if (pctLabel) pctLabel.textContent = pct + '%';
                }
            });

            // Load handler
            xhr.addEventListener('load', function() {
                pendingUploads--;
                try {
                    var resp = JSON.parse(xhr.responseText);
                    var fileResult = (resp.results && resp.results[0]) ? resp.results[0] : null;

                    if (resp.success && fileResult && fileResult.success) {
                        item.classList.add('mw-upload-done');
                        item.querySelector('.mw-upload-status').textContent = 'Done';

                        // Show thumbnail if available
                        if (fileResult.thumb_url) {
                            var thumbEl = item.querySelector('.mw-upload-thumb');
                            if (thumbEl) {
                                thumbEl.innerHTML = '<img src="' + escapeAttr(fileResult.thumb_url) + '" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:3px;">';
                            }
                        }

                        // Show dedup badge
                        if (fileResult.is_duplicate) {
                            var statusEl = item.querySelector('.mw-upload-status');
                            if (statusEl) statusEl.textContent = 'Linked';
                            statusEl.title = 'File already exists — linked to this context';
                        }

                        uploadStats.succeeded++;
                        zone.dispatchEvent(new CustomEvent('mediaUploaded', { detail: fileResult }));
                    } else {
                        item.classList.add('mw-upload-error');
                        var errorMsg = (fileResult && fileResult.errors) ? fileResult.errors.join(', ') : (resp.error || 'Upload failed');
                        item.querySelector('.mw-upload-status').textContent = 'Error';
                        item.querySelector('.mw-upload-status').title = errorMsg;
                        uploadStats.failed++;
                        zone.dispatchEvent(new CustomEvent('mediaError', { detail: { filename: file.name, errors: [errorMsg] } }));
                    }
                } catch (e) {
                    item.classList.add('mw-upload-error');
                    item.querySelector('.mw-upload-status').textContent = 'Error';
                    uploadStats.failed++;
                }

                checkAllComplete();
            });

            // Error handler
            xhr.addEventListener('error', function() {
                pendingUploads--;
                item.classList.add('mw-upload-error');
                item.querySelector('.mw-upload-status').textContent = 'Network error';
                uploadStats.failed++;
                checkAllComplete();
            });

            // Timeout handler
            xhr.addEventListener('timeout', function() {
                pendingUploads--;
                item.classList.add('mw-upload-error');
                item.querySelector('.mw-upload-status').textContent = 'Timeout';
                uploadStats.failed++;
                checkAllComplete();
            });

            xhr.timeout = 120000; // 2 minutes
            xhr.open('POST', config.uploadUrl);
            xhr.send(formData);
        }

        function checkAllComplete() {
            if (pendingUploads <= 0) {
                zone.dispatchEvent(new CustomEvent('allUploadsComplete', { detail: uploadStats }));
                // Reset stats for next batch
                uploadStats = { total: 0, succeeded: 0, failed: 0 };
            }
        }

        // --- Queue item DOM ---
        function createQueueItem(filename, fileSize) {
            var div = document.createElement('div');
            div.className = 'mw-upload-item';

            var sizeStr = fileSize > 1024 * 1024
                ? (fileSize / 1024 / 1024).toFixed(1) + ' MB'
                : Math.round(fileSize / 1024) + ' KB';

            div.innerHTML =
                '<span class="mw-upload-thumb"></span>' +
                '<span class="mw-upload-name" title="' + escapeAttr(filename) + '">' + escapeHtml(filename) + '</span>' +
                '<span class="mw-upload-size">' + sizeStr + '</span>' +
                '<div class="mw-upload-progress"><div class="mw-upload-progress-bar"></div></div>' +
                '<span class="mw-upload-pct">0%</span>' +
                '<span class="mw-upload-status">Uploading</span>';

            return div;
        }

        function showFileError(name, msg) {
            var div = document.createElement('div');
            div.className = 'mw-upload-item mw-upload-error';
            div.innerHTML =
                '<span class="mw-upload-thumb"></span>' +
                '<span class="mw-upload-name">' + escapeHtml(name) + '</span>' +
                '<span class="mw-upload-size"></span>' +
                '<div class="mw-upload-progress"></div>' +
                '<span class="mw-upload-pct"></span>' +
                '<span class="mw-upload-status" title="' + escapeAttr(msg) + '">' + escapeHtml(msg) + '</span>';
            queue.appendChild(div);
        }
    }

    // --- Utility functions ---
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-initialize for dynamically added dropzones
    window.MowologyMediaUploader = { init: init };
})();
