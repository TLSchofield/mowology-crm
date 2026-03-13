/**
 * Mowology Media Uploader — UI Shell
 * ────────────────────────────────────
 * Thin UI layer over photo-queue.js.
 *
 * This file only handles the visual side:
 *   • Shows the local blob URL preview the moment a file is picked (instant)
 *   • Delegates all storage, queuing, and uploading to window.MwPhotoQueue
 *   • Listens for queue events to update each photo card's state
 *   • Never blocks the user — no progress bars, no spinners over the full UI
 *
 * Requires: photo-queue.js (must load first)
 *
 * Usage: Place a .mw-media-dropzone element with data attributes:
 *   data-context-type   — context type (job_visit, quote_visit, etc.)
 *   data-context-id     — context entity ID
 *   data-category       — default category (before, after, during, etc.)
 *   data-visibility     — default visibility (internal, client_visible, etc.)
 *   data-csrf           — CSRF token
 *   data-upload-url     — upload endpoint (default: /crm/api/media-upload.php)
 *   data-max-size       — max file size in bytes (default: 15 MB)
 *   data-allowed-types  — comma-separated MIME types
 *
 * Events dispatched on the dropzone element:
 *   mediaUploaded       — detail: { media_id, uuid, filename, thumb_url, ... }
 *   mediaError          — detail: { filename, errors }
 *   allUploadsComplete  — detail: { total, succeeded, failed }
 *
 * @package Mowology CRM
 */
(function () {
    'use strict';

    // Map: queueId → { item (DOM element), blobUrl, zone, filename, stats reference }
    var _pending = {};

    // =========================================================================
    // Initialisation
    // =========================================================================
    function init() {
        document.querySelectorAll('.mw-media-dropzone').forEach(initDropzone);

        // Listen globally for queue events — updates any live card on the page
        if (window.MwPhotoQueue) {
            MwPhotoQueue.on('mwPhotoUploaded', onUploaded);
            MwPhotoQueue.on('mwPhotoFailed',   onFailed);
            MwPhotoQueue.on('mwPhotoRetrying', onRetrying);
        }
    }

    function initDropzone(zone) {
        var fileInput   = zone.querySelector('.mw-dropzone-input');
        var cameraInput = zone.querySelector('.mw-dropzone-camera-input');
        var browseBtn   = zone.querySelector('.mw-dropzone-browse');
        var cameraBtn   = zone.querySelector('.mw-dropzone-camera');
        var queue       = zone.querySelector('.mw-dropzone-queue');
        var gpsToggle   = zone.querySelector('.mw-dropzone-gps-toggle');
        var powToggle   = zone.querySelector('.mw-dropzone-pow-toggle');

        var config = {
            contextType : zone.dataset.contextType  || 'marketing_general',
            contextId   : zone.dataset.contextId    || '0',
            category    : zone.dataset.category     || '',
            visibility  : zone.dataset.visibility   || 'internal',
            csrf        : zone.dataset.csrf          || '',
            uploadUrl   : zone.dataset.uploadUrl    || '/crm/api/media-upload.php',
            maxSize     : parseInt(zone.dataset.maxSize || '15728640', 10),
            allowedTypes: (zone.dataset.allowedTypes || 'image/jpeg,image/png,image/webp').split(',')
        };

        // Per-zone upload stats (reset after allUploadsComplete)
        var stats        = { total: 0, succeeded: 0, failed: 0, active: 0 };
        var cachedGps    = null;
        var gpsRequested = false;

        // ── GPS ─────────────────────────────────────────────────────────────
        function requestGps() {
            if (gpsRequested || !navigator.geolocation) return;
            gpsRequested = true;
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    cachedGps = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy };
                },
                function () { cachedGps = null; },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        }

        if (gpsToggle && gpsToggle.checked) requestGps();
        if (gpsToggle) {
            gpsToggle.addEventListener('change', function () {
                if (this.checked) requestGps(); else cachedGps = null;
            });
        }

        // ── Drag-and-drop ────────────────────────────────────────────────────
        ['dragenter', 'dragover'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault(); e.stopPropagation();
                zone.classList.add('mw-dropzone-active');
            });
        });

        ['dragleave', 'drop'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault(); e.stopPropagation();
                zone.classList.remove('mw-dropzone-active');
            });
        });

        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
        });

        // ── Browse / camera ──────────────────────────────────────────────────
        if (browseBtn && fileInput) {
            browseBtn.addEventListener('click', function (e) { e.preventDefault(); fileInput.click(); });
            fileInput.addEventListener('change', function () {
                if (this.files && this.files.length) { handleFiles(this.files); this.value = ''; }
            });
        }

        if (cameraBtn && cameraInput) {
            cameraBtn.addEventListener('click', function (e) { e.preventDefault(); cameraInput.click(); });
            cameraInput.addEventListener('change', function () {
                if (this.files && this.files.length) { handleFiles(this.files); this.value = ''; }
            });
        }

        // ── Blur detection (receipts only) ───────────────────────────────────
        function isImageBlurry(file, threshold) {
            threshold = threshold || 80;
            return new Promise(function (resolve) {
                if (!file.type.match(/^image\/(jpeg|png|webp)$/)) { resolve(false); return; }
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload = function () {
                    try {
                        var c = document.createElement('canvas'); c.width = 100; c.height = 100;
                        var ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0, 100, 100);
                        URL.revokeObjectURL(url);
                        var data = ctx.getImageData(0, 0, 100, 100).data;
                        var grey = [];
                        for (var i = 0; i < data.length; i += 4) {
                            grey.push(0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]);
                        }
                        var mean = grey.reduce(function (a, b) { return a + b; }, 0) / grey.length;
                        var variance = grey.reduce(function (s, v) { return s + (v - mean) * (v - mean); }, 0) / grey.length;
                        resolve(variance < threshold);
                    } catch (e) { resolve(false); }
                };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(false); };
                img.src = url;
            });
        }

        // ── File handling ────────────────────────────────────────────────────
        function handleFiles(fileList) {
            Array.prototype.slice.call(fileList).forEach(function (file) {
                if (config.allowedTypes.indexOf(file.type) === -1) {
                    showError(queue, file.name, 'Invalid file type: ' + file.type); return;
                }
                if (file.size > config.maxSize) {
                    showError(queue, file.name, 'Too large (max ' + Math.round(config.maxSize / 1048576) + ' MB)'); return;
                }

                if (config.contextType === 'expense') {
                    isImageBlurry(file).then(function (blurry) {
                        if (blurry && !window.confirm('This photo looks blurry or dark.\nUpload anyway, or retake?')) return;
                        processFile(file);
                    });
                } else {
                    processFile(file);
                }
            });
        }

        // ── Core: instant preview + enqueue to background uploader ───────────
        function processFile(file) {
            // 1. Create local blob URL — instant preview, zero network required
            var blobUrl = URL.createObjectURL(file);

            // 2. Show the photo card immediately
            var item = createPhotoCard(file.name, file.size, blobUrl);
            queue.appendChild(item);

            // 3. Track stats
            stats.total++;
            stats.active++;

            // 4. Build upload metadata snapshot (CSRF, GPS, etc.)
            var meta = {
                contextType : config.contextType,
                contextId   : config.contextId,
                category    : config.category,
                visibility  : config.visibility,
                csrf        : config.csrf,
                uploadUrl   : config.uploadUrl,
                powStamp    : (powToggle && powToggle.checked) ? '1' : '',
                gpsLat      : (gpsToggle && gpsToggle.checked && cachedGps) ? cachedGps.lat      : null,
                gpsLng      : (gpsToggle && gpsToggle.checked && cachedGps) ? cachedGps.lng      : null,
                gpsAccuracy : (gpsToggle && gpsToggle.checked && cachedGps) ? cachedGps.accuracy : null
            };

            // 5. Persist to durable storage + add to upload queue
            if (window.MwPhotoQueue) {
                MwPhotoQueue.saveAndEnqueue(file, meta)
                    .then(function (queueId) {
                        // Register this item so queue events can update it
                        _pending[queueId] = {
                            item    : item,
                            blobUrl : blobUrl,
                            zone    : zone,
                            filename: file.name,
                            stats   : stats
                        };
                        item.dataset.mwQueueId = queueId;
                    })
                    .catch(function () {
                        // Storage unavailable — mark item as failed immediately
                        setCardState(item, 'error', 'Storage unavailable');
                        stats.active--;
                        stats.failed++;
                        checkAllComplete(zone, stats);
                    });
            } else {
                // photo-queue.js not loaded — fall back to direct inline upload
                inlineUpload(file, meta, item, blobUrl, stats, zone, config);
            }
        }

        // Store refs on the zone so replay (page reload) can use the right zone
        zone._mwConfig = config;
        zone._mwQueue  = queue;
        zone._mwStats  = stats;

        // ── Restore pending photos on page load ───────────────────────────
        // Any photos that are still in the upload queue (from this session or
        // a previous one) are immediately shown as preview cards so the user
        // can always see their photos and know they are safe.
        restorePendingCards(zone, queue, config, stats);
    }

    /**
     * On page load, query the photo queue for any pending/failed items belonging
     * to this dropzone's context and render preview cards for them.
     * This is the "always visible" guarantee — photos survive page reloads.
     */
    function restorePendingCards(zone, queue, config, stats) {
        if (!window.MwPhotoQueue) return;

        MwPhotoQueue.getPendingForContext(config.contextType, config.contextId)
            .then(function (items) {
                if (!items.length) return;

                items.forEach(function (item) {
                    // Don't add a duplicate card if this queueId is already tracked
                    // (can happen if the same page calls init() twice)
                    if (_pending[item.queueId]) return;

                    var card = createPhotoCard(item.filename, item.fileSize, item.blobUrl);

                    // Show appropriate state: failed items get error styling, others pulse
                    if (item.status === 'failed' && item.retries >= 5) {
                        setCardState(card, 'error', 'Failed — tap to retry');
                        var thumbEl = card.querySelector('.mw-upload-thumb');
                        if (thumbEl) {
                            thumbEl.style.cursor = 'pointer';
                            thumbEl.title = 'Tap to retry';
                            thumbEl.addEventListener('click', function retryClick() {
                                thumbEl.removeEventListener('click', retryClick);
                                thumbEl.style.cursor = '';
                                thumbEl.title = '';
                                setCardState(card, 'uploading', 'Retrying…');
                                MwPhotoQueue.processQueue();
                            }, { once: true });
                        }
                    } else {
                        // pending or failed-but-retrying — show as syncing
                        setCardState(card, 'uploading', item.status === 'failed' ? 'Retrying…' : 'Syncing…');
                    }

                    card.dataset.mwQueueId = item.queueId;
                    queue.appendChild(card);

                    // Track so queue events can update this card
                    stats.active++;
                    stats.total++;
                    _pending[item.queueId] = {
                        item    : card,
                        blobUrl : item.blobUrl,
                        zone    : zone,
                        filename: item.filename,
                        stats   : stats
                    };
                });
            })
            .catch(function () { /* silent — restore is best-effort */ });
    }


    // =========================================================================
    // Queue event handlers — called by photo-queue.js lifecycle events
    // =========================================================================
    function onUploaded(detail) {
        var entry = _pending[detail.queueId];
        if (!entry) return;

        var item = entry.item;
        setCardState(item, 'done', detail.isDuplicate ? 'Linked' : 'Saved');

        // Swap blob URL for real server thumbnail once it loads
        if (detail.thumbUrl) {
            var thumbEl = item.querySelector('.mw-upload-thumb');
            var img     = thumbEl ? thumbEl.querySelector('img') : null;
            if (img) {
                var newImg    = new Image();
                newImg.onload = function () {
                    img.src = detail.thumbUrl;
                    URL.revokeObjectURL(entry.blobUrl);
                };
                newImg.onerror = function () {
                    URL.revokeObjectURL(entry.blobUrl);
                };
                newImg.src = detail.thumbUrl;
            }
        } else {
            URL.revokeObjectURL(entry.blobUrl);
        }

        entry.stats.active--;
        entry.stats.succeeded++;

        entry.zone.dispatchEvent(new CustomEvent('mediaUploaded', {
            detail: {
                media_id    : detail.mediaId,
                uuid        : detail.uuid,
                thumb_url   : detail.thumbUrl,
                is_duplicate: detail.isDuplicate,
                filename    : entry.filename
            }
        }));

        checkAllComplete(entry.zone, entry.stats);
        delete _pending[detail.queueId];
    }

    function onFailed(detail) {
        var entry = _pending[detail.queueId];
        if (!entry) return;

        var item = entry.item;
        setCardState(item, 'error', 'Failed — tap to retry');

        // Tap-to-retry on the photo thumb
        var thumbEl = item.querySelector('.mw-upload-thumb');
        if (thumbEl) {
            thumbEl.style.cursor = 'pointer';
            thumbEl.title = 'Tap to retry';
            thumbEl.addEventListener('click', function retryClick() {
                thumbEl.removeEventListener('click', retryClick);
                thumbEl.style.cursor = '';
                thumbEl.title = '';
                setCardState(item, 'uploading', 'Retrying…');
                // Re-enqueue: reset the queue record status via patch
                if (window.MwPhotoQueue) {
                    // Accessing internal PhotoQueue is not public — trigger a full queue run
                    // The queue runner will pick up 'failed' records that still have retries left
                    MwPhotoQueue.processQueue();
                }
            }, { once: true });
        }

        entry.stats.active--;
        entry.stats.failed++;

        entry.zone.dispatchEvent(new CustomEvent('mediaError', {
            detail: { filename: entry.filename, errors: [detail.error || 'Upload failed'] }
        }));

        checkAllComplete(entry.zone, entry.stats);
        delete _pending[detail.queueId];
    }

    function onRetrying(detail) {
        var entry = _pending[detail.queueId];
        if (!entry) return;
        setCardState(entry.item, 'uploading', 'Retrying (' + detail.attempt + ')…');
    }

    function checkAllComplete(zone, stats) {
        if (stats.active <= 0) {
            zone.dispatchEvent(new CustomEvent('allUploadsComplete', {
                detail: { total: stats.total, succeeded: stats.succeeded, failed: stats.failed }
            }));
            // Reset for next batch
            stats.total = 0; stats.succeeded = 0; stats.failed = 0; stats.active = 0;
        }
    }


    // =========================================================================
    // DOM helpers
    // =========================================================================

    /**
     * Create a photo card with an instant local preview.
     * The card is photo-first: large thumb on the left, subtle status on the right.
     * A small pulsing ring on the thumb corner indicates the upload is in progress.
     */
    function createPhotoCard(filename, fileSize, blobUrl) {
        var div       = document.createElement('div');
        div.className = 'mw-upload-item mw-upload-item--photo';

        var sizeStr = fileSize > 1048576
            ? (fileSize / 1048576).toFixed(1) + ' MB'
            : Math.round(fileSize / 1024) + ' KB';

        // Thumb with pulsing ring (uploading state by default)
        var thumbImg = blobUrl
            ? '<img src="' + escapeAttr(blobUrl) + '" alt="" loading="eager">'
            : '';

        div.innerHTML =
            '<span class="mw-upload-thumb mw-thumb-uploading">' + thumbImg + '</span>' +
            '<span class="mw-upload-meta">' +
                '<span class="mw-upload-name" title="' + escapeAttr(filename) + '">' + escapeHtml(filename) + '</span>' +
                '<span class="mw-upload-size">' + sizeStr + '</span>' +
                '<span class="mw-upload-status">Uploading…</span>' +
            '</span>';

        return div;
    }

    /**
     * Set a card's visual state.
     * @param {HTMLElement} item
     * @param {'uploading'|'done'|'error'} state
     * @param {string} statusText
     */
    function setCardState(item, state, statusText) {
        var thumbEl  = item.querySelector('.mw-upload-thumb');
        var statusEl = item.querySelector('.mw-upload-status');

        // Remove all state classes
        if (thumbEl) {
            thumbEl.classList.remove('mw-thumb-uploading', 'mw-thumb-done', 'mw-thumb-error');
        }
        item.classList.remove('mw-upload-done', 'mw-upload-error');

        if (state === 'done') {
            if (thumbEl)  thumbEl.classList.add('mw-thumb-done');
            item.classList.add('mw-upload-done');
        } else if (state === 'error') {
            if (thumbEl)  thumbEl.classList.add('mw-thumb-error');
            item.classList.add('mw-upload-error');
        } else {
            // uploading
            if (thumbEl) thumbEl.classList.add('mw-thumb-uploading');
        }

        if (statusEl) statusEl.textContent = statusText || '';
    }

    function showError(queueEl, name, msg) {
        var div = document.createElement('div');
        div.className = 'mw-upload-item mw-upload-error';
        div.innerHTML =
            '<span class="mw-upload-thumb mw-thumb-error"></span>' +
            '<span class="mw-upload-meta">' +
                '<span class="mw-upload-name">' + escapeHtml(name) + '</span>' +
                '<span class="mw-upload-size"></span>' +
                '<span class="mw-upload-status">' + escapeHtml(msg) + '</span>' +
            '</span>';
        queueEl.appendChild(div);
    }


    // =========================================================================
    // Inline upload fallback (when photo-queue.js is not available)
    // =========================================================================
    function inlineUpload(file, meta, item, blobUrl, stats, zone) {
        var formData = new FormData();
        formData.append('files[]',        file);
        formData.append('csrf_token',     meta.csrf        || '');
        formData.append('context_type',   meta.contextType || '');
        formData.append('context_id',     String(meta.contextId || ''));
        formData.append('category',       meta.category    || '');
        formData.append('visibility',     meta.visibility  || 'internal');
        if (meta.powStamp)     formData.append('pow_stamp',    meta.powStamp);
        if (meta.gpsLat)      formData.append('gps_lat',      String(meta.gpsLat));
        if (meta.gpsLng)      formData.append('gps_lng',      String(meta.gpsLng));
        if (meta.gpsAccuracy) formData.append('gps_accuracy', String(meta.gpsAccuracy));

        fetch(meta.uploadUrl || '/crm/api/media-upload.php', { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (resp) {
                var fr = (resp.results && resp.results[0]) ? resp.results[0] : null;
                if (resp.success && fr && fr.success) {
                    setCardState(item, 'done', fr.is_duplicate ? 'Linked' : 'Saved');
                    if (fr.thumb_url) {
                        var img = item.querySelector('.mw-upload-thumb img');
                        if (img) { img.onload = function () { URL.revokeObjectURL(blobUrl); }; img.src = fr.thumb_url; }
                    }
                    stats.active--; stats.succeeded++;
                    zone.dispatchEvent(new CustomEvent('mediaUploaded', { detail: fr }));
                } else {
                    var err = (fr && fr.errors) ? fr.errors.join(', ') : 'Upload failed';
                    setCardState(item, 'error', err);
                    stats.active--; stats.failed++;
                    zone.dispatchEvent(new CustomEvent('mediaError', { detail: { filename: file.name, errors: [err] } }));
                }
                checkAllComplete(zone, stats);
            })
            .catch(function () {
                setCardState(item, 'error', 'Network error');
                stats.active--; stats.failed++;
                checkAllComplete(zone, stats);
            });
    }


    // =========================================================================
    // Utilities
    // =========================================================================
    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function escapeAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }


    // =========================================================================
    // Boot
    // =========================================================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.MowologyMediaUploader = { init: init };
})();
