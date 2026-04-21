/**
 * BatchCamera — Multi-shot in-app camera overlay for Capacitor + PWA
 * ──────────────────────────────────────────────────────────────────
 * Keeps the camera viewfinder open between shots so crew can snap up
 * to maxPhotos images in one session without the OS camera app closing.
 *
 * Each photo is captured via canvas.drawImage(video) and returned as a
 * File blob. The caller is responsible for local persistence and upload.
 *
 * Usage:
 *   var cam = new BatchCamera({
 *     maxPhotos : 10,
 *     onCapture : function(file, objectUrl) { ... },  // fires on each snap
 *     onDone    : function(count) { ... },             // fires on Done tap
 *     onCancel  : function(count) { ... }              // fires on Cancel tap
 *   });
 *   cam.open();
 *
 * @package Mowology CRM
 */
(function (global) {
    'use strict';

    var CANVAS_QUALITY = 0.92;   // JPEG quality for captured frames
    var CANVAS_MAX_DIM = 2048;   // max width or height (downscale if needed)

    // ─────────────────────────────────────────────────────────────────────────
    // BatchCamera constructor
    // ─────────────────────────────────────────────────────────────────────────
    function BatchCamera(opts) {
        this._maxPhotos  = opts.maxPhotos || 10;
        this._onCapture  = opts.onCapture  || null;
        this._onDone     = opts.onDone     || null;
        this._onCancel   = opts.onCancel   || null;

        this._stream     = null;
        this._videoEl    = null;
        this._overlay    = null;
        this._captured   = [];   // { file, objectUrl }
        this._capturing  = false;
        this._photoCount = 0;
    }

    // ── Public: open ──────────────────────────────────────────────────────────
    BatchCamera.prototype.open = function () {
        var self = this;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('[BatchCamera] getUserMedia not available — falling back');
            if (self._onCancel) self._onCancel(0, 'unsupported');
            return;
        }

        // Build the overlay DOM immediately so the user gets instant feedback
        self._buildOverlay();
        document.body.appendChild(self._overlay);
        // Prevent body scroll while overlay is open
        document.body.style.overflow = 'hidden';

        self._setStatus('Starting camera…');

        var constraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width:  { ideal: 1920 },
                height: { ideal: 1080 }
            },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (stream) {
                self._stream   = stream;
                self._videoEl.srcObject = stream;
                self._setStatus('');
                self._videoEl.play().catch(function () {});
            })
            .catch(function (err) {
                console.warn('[BatchCamera] getUserMedia error:', err && err.name, err && err.message);
                self._cleanup();
                if (self._onCancel) self._onCancel(0, err && err.name === 'NotAllowedError' ? 'denied' : 'error');
            });
    };

    // ── Build overlay DOM ─────────────────────────────────────────────────────
    BatchCamera.prototype._buildOverlay = function () {
        var self = this;

        var ov = document.createElement('div');
        ov.className = 'mw-batch-camera-overlay';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-label', 'Batch camera');

        // ── Header ────────────────────────────────────────────────────────────
        var hdr = document.createElement('div');
        hdr.className = 'mw-batch-camera-header';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'mw-bc-cancel';
        cancelBtn.type = 'button';
        cancelBtn.setAttribute('aria-label', 'Cancel');
        cancelBtn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        cancelBtn.addEventListener('click', function () { self._cancel(); });

        var counter = document.createElement('span');
        counter.className = 'mw-bc-counter';
        counter.textContent = '0 / ' + self._maxPhotos;
        self._counterEl = counter;

        var doneBtn = document.createElement('button');
        doneBtn.className = 'mw-bc-done';
        doneBtn.type = 'button';
        doneBtn.disabled = true;
        doneBtn.textContent = 'Done';
        doneBtn.addEventListener('click', function () { self._done(); });
        self._doneBtnEl = doneBtn;

        hdr.appendChild(cancelBtn);
        hdr.appendChild(counter);
        hdr.appendChild(doneBtn);

        // ── Viewfinder ────────────────────────────────────────────────────────
        var vf = document.createElement('div');
        vf.className = 'mw-batch-camera-viewfinder';

        var video = document.createElement('video');
        video.setAttribute('autoplay', '');
        video.setAttribute('playsinline', '');   // iOS Safari requires this
        video.setAttribute('muted', '');
        video.className = 'mw-bc-video';
        vf.appendChild(video);
        self._videoEl = video;

        // ── Status label (shown while camera starts) ──────────────────────────
        var statusEl = document.createElement('div');
        statusEl.className = 'mw-bc-status';
        vf.appendChild(statusEl);
        self._statusEl = statusEl;

        // ── Flash feedback layer ───────────────────────────────────────────────
        var flash = document.createElement('div');
        flash.className = 'mw-bc-flash';
        vf.appendChild(flash);
        self._flashEl = flash;

        // ── Shutter button (centred below viewfinder) ─────────────────────────
        var controls = document.createElement('div');
        controls.className = 'mw-batch-camera-controls';

        var shutterBtn = document.createElement('button');
        shutterBtn.className = 'mw-bc-shutter';
        shutterBtn.type = 'button';
        shutterBtn.setAttribute('aria-label', 'Take photo');
        shutterBtn.innerHTML =
            '<span class="mw-bc-shutter-ring">' +
            '  <span class="mw-bc-shutter-dot"></span>' +
            '</span>';
        shutterBtn.addEventListener('click', function () { self._capture(); });
        self._shutterBtnEl = shutterBtn;

        controls.appendChild(shutterBtn);

        // ── Thumbnail strip ───────────────────────────────────────────────────
        var strip = document.createElement('div');
        strip.className = 'mw-batch-camera-strip';
        self._stripEl = strip;

        // ── Assemble ──────────────────────────────────────────────────────────
        ov.appendChild(hdr);
        ov.appendChild(vf);
        ov.appendChild(controls);
        ov.appendChild(strip);

        self._overlay = ov;
    };

    // ── Capture one frame ─────────────────────────────────────────────────────
    BatchCamera.prototype._capture = function () {
        var self = this;

        if (self._capturing)                                  return;
        if (self._photoCount >= self._maxPhotos)              return;
        if (!self._videoEl || !self._videoEl.videoWidth)      return;

        self._capturing = true;
        self._shutterBtnEl.disabled = true;

        // Flash feedback
        self._flashEl.classList.add('mw-bc-flash-active');
        setTimeout(function () {
            self._flashEl.classList.remove('mw-bc-flash-active');
        }, 120);

        var vw = self._videoEl.videoWidth;
        var vh = self._videoEl.videoHeight;

        // Downscale if the native resolution exceeds CANVAS_MAX_DIM
        var scale  = Math.min(1, CANVAS_MAX_DIM / Math.max(vw, vh));
        var cw     = Math.round(vw * scale);
        var ch     = Math.round(vh * scale);

        var canvas = document.createElement('canvas');
        canvas.width  = cw;
        canvas.height = ch;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(self._videoEl, 0, 0, cw, ch);

        var ts   = Date.now();
        var name = 'mw_batch_' + ts + '.jpg';

        canvas.toBlob(function (blob) {
            if (!blob) {
                console.warn('[BatchCamera] toBlob returned null');
                self._capturing = false;
                self._shutterBtnEl.disabled = (self._photoCount >= self._maxPhotos);
                return;
            }

            var file      = new File([blob], name, { type: 'image/jpeg', lastModified: ts });
            var objectUrl = URL.createObjectURL(blob);

            self._captured.push({ file: file, objectUrl: objectUrl });
            self._photoCount++;

            // Fire onCapture immediately — caller saves to IDB right here
            if (self._onCapture) {
                self._onCapture(file, objectUrl);
            }

            // Update UI
            self._counterEl.textContent = self._photoCount + ' / ' + self._maxPhotos;
            self._doneBtnEl.disabled = false;
            if (self._photoCount >= self._maxPhotos) {
                self._shutterBtnEl.disabled = true;
                self._setStatus('Maximum ' + self._maxPhotos + ' photos reached');
            } else {
                self._shutterBtnEl.disabled = false;
            }

            self._addThumb(objectUrl, self._photoCount);
            self._capturing = false;

        }, 'image/jpeg', CANVAS_QUALITY);
    };

    // ── Add thumbnail to strip ────────────────────────────────────────────────
    BatchCamera.prototype._addThumb = function (objectUrl, n) {
        var self  = this;
        var thumb = document.createElement('div');
        thumb.className = 'mw-batch-camera-thumb';
        thumb.dataset.idx = String(n - 1);

        var img = document.createElement('img');
        img.src  = objectUrl;
        img.alt  = 'Photo ' + n;
        img.loading = 'eager';

        var badge = document.createElement('span');
        badge.className = 'mw-bc-thumb-num';
        badge.textContent = String(n);

        // Tap thumbnail to remove it
        thumb.addEventListener('click', function () {
            self._removeThumb(n - 1, thumb, objectUrl);
        });

        thumb.appendChild(img);
        thumb.appendChild(badge);
        self._stripEl.appendChild(thumb);
        // Scroll strip to show the newest thumb
        self._stripEl.scrollLeft = self._stripEl.scrollWidth;
    };

    // ── Remove a thumbnail (user taps to discard one shot) ────────────────────
    BatchCamera.prototype._removeThumb = function (idx, thumbEl, objectUrl) {
        var self = this;
        if (idx < 0 || idx >= self._captured.length) return;

        self._captured.splice(idx, 1);
        self._photoCount--;
        URL.revokeObjectURL(objectUrl);
        thumbEl.parentNode && thumbEl.parentNode.removeChild(thumbEl);

        // Re-number remaining thumbs
        var thumbs = self._stripEl.querySelectorAll('.mw-batch-camera-thumb');
        for (var i = 0; i < thumbs.length; i++) {
            var badge = thumbs[i].querySelector('.mw-bc-thumb-num');
            if (badge) badge.textContent = String(i + 1);
            thumbs[i].dataset.idx = String(i);
        }

        self._counterEl.textContent = self._photoCount + ' / ' + self._maxPhotos;
        self._doneBtnEl.disabled = (self._photoCount === 0);
        if (self._photoCount < self._maxPhotos) {
            self._shutterBtnEl.disabled = false;
            self._setStatus('');
        }
    };

    // ── Done ─────────────────────────────────────────────────────────────────
    BatchCamera.prototype._done = function () {
        var count = this._photoCount;
        this._cleanup();
        if (this._onDone) this._onDone(count);
    };

    // ── Cancel ────────────────────────────────────────────────────────────────
    BatchCamera.prototype._cancel = function () {
        var count = this._photoCount;
        this._cleanup();
        if (this._onCancel) this._onCancel(count);
    };

    // ── Stop stream + remove overlay ──────────────────────────────────────────
    BatchCamera.prototype._cleanup = function () {
        this._stopStream();
        this._captured.forEach(function (c) {
            try { URL.revokeObjectURL(c.objectUrl); } catch (e) {}
        });
        this._captured = [];
        this._photoCount = 0;
        if (this._overlay && this._overlay.parentNode) {
            this._overlay.parentNode.removeChild(this._overlay);
        }
        document.body.style.overflow = '';
    };

    BatchCamera.prototype._stopStream = function () {
        if (this._stream) {
            this._stream.getTracks().forEach(function (t) { t.stop(); });
            this._stream = null;
        }
        if (this._videoEl) {
            this._videoEl.srcObject = null;
        }
    };

    BatchCamera.prototype._setStatus = function (msg) {
        if (this._statusEl) {
            this._statusEl.textContent = msg;
            this._statusEl.style.display = msg ? 'block' : 'none';
        }
    };

    // ── Expose globally ───────────────────────────────────────────────────────
    global.BatchCamera = BatchCamera;

}(window));
