/**
 * Mowology Photo Queue — Storage Engine + Upload Queue + Runner
 * ──────────────────────────────────────────────────────────────
 *
 * Architecture (matches field-app requirements):
 *
 *   PhotoStorage  — saves the actual binary image to the best available store:
 *                    • Capacitor native app  → device Filesystem (Cache dir)
 *                    • Browser / PWA         → IndexedDB blob store
 *                   The queue DB tracks paths/keys, NOT the image bytes.
 *
 *   PhotoQueue    — IndexedDB metadata queue (mowology-photo-queue).
 *                   Each record tracks: storage ref, upload meta, status,
 *                   retry count. The queue is the persistent source of truth.
 *
 *   PhotoUploader — queue runner. Never blocks the UI. Processes pending
 *                   records, retries failures with exponential back-off, and
 *                   resumes automatically on:
 *                     • page load (pending items from previous session)
 *                     • device comes online
 *                     • app is foregrounded (visibilitychange)
 *                     • MwNative.network status change (Android Capacitor)
 *
 * Public API (window.MwPhotoQueue):
 *   saveAndEnqueue(file, meta)  → Promise<queueId>
 *   processQueue()              → void  (triggers queue runner)
 *   getPendingCount()           → Promise<number>
 *   on(event, fn)               → subscribe to queue events
 *
 * Events dispatched on window.MwPhotoQueue:
 *   mwPhotoQueued   { queueId }
 *   mwPhotoUploaded { queueId, mediaId, uuid, thumbUrl, isDuplicate }
 *   mwPhotoFailed   { queueId, filename, error }
 *   mwPhotoRetrying { queueId, attempt }
 *
 * Note on background-while-closed:
 *   True background upload after the app is fully closed is not reliably
 *   guaranteed in a web/PWA model. This engine handles the reliable cases:
 *   save locally → upload while foregrounded → resume on reopen. Service
 *   Worker Background Sync is registered where supported but not relied upon.
 *
 * @package Mowology CRM
 */
(function (global) {
    'use strict';

    var MAX_RETRIES    = 5;
    var BASE_BACKOFF   = 3000; // ms — doubled each retry: 3s, 6s, 12s, 24s, 48s

    // =========================================================================
    // PhotoStorage — Capacitor Filesystem (native) || IndexedDB blobs (browser)
    // =========================================================================
    var PhotoStorage = (function () {

        // ── Capacitor Filesystem detection ──────────────────────────────────
        function getFilesystem() {
            if (global.Capacitor &&
                global.Capacitor.isNativePlatform &&
                global.Capacitor.isNativePlatform() &&
                global.Capacitor.Plugins &&
                global.Capacitor.Plugins.Filesystem) {
                return global.Capacitor.Plugins.Filesystem;
            }
            return null;
        }

        var PHOTO_DIR = 'mw-photos'; // sub-path inside Cache dir

        // ── File → base64 (for Capacitor writeFile) ──────────────────────
        function fileToBase64(file) {
            return new Promise(function (resolve, reject) {
                var reader = new FileReader();
                reader.onload = function () {
                    // readAsDataURL returns "data:<mime>;base64,<data>"
                    var parts = reader.result.split(',');
                    resolve(parts.length > 1 ? parts[1] : parts[0]);
                };
                reader.onerror = function () { reject(new Error('FileReader failed')); };
                reader.readAsDataURL(file);
            });
        }

        // ── base64 → Blob (for re-constructing the file on upload) ──────
        function base64ToBlob(base64, mimeType) {
            try {
                var binary = atob(base64);
                var bytes  = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }
                return new Blob([bytes], { type: mimeType || 'image/jpeg' });
            } catch (e) {
                return null;
            }
        }

        // ── UUID generator (used for Filesystem filenames) ────────────────
        function uuid() {
            return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        }

        // ── IDB blob store ────────────────────────────────────────────────
        var IDB_NAME    = 'mowology-photo-store';
        var IDB_VERSION = 1;
        var IDB_STORE   = 'blobs';
        var _idb        = null;

        function openBlobDB() {
            if (_idb) return Promise.resolve(_idb);
            return new Promise(function (resolve, reject) {
                var req = indexedDB.open(IDB_NAME, IDB_VERSION);
                req.onupgradeneeded = function (e) {
                    e.target.result.createObjectStore(IDB_STORE, { keyPath: 'id', autoIncrement: true });
                };
                req.onsuccess  = function (e) { _idb = e.target.result; resolve(_idb); };
                req.onerror    = function (e) { reject(e.target.error); };
            });
        }

        function idbSaveBlob(blob, mimeType) {
            return openBlobDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx  = db.transaction(IDB_STORE, 'readwrite');
                    var req = tx.objectStore(IDB_STORE).add({ blob: blob, mime: mimeType, saved: Date.now() });
                    req.onsuccess = function () { resolve(req.result); }; // numeric IDB key
                    req.onerror   = function (e) { reject(e.target.error); };
                });
            });
        }

        function idbLoadBlob(key) {
            return openBlobDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx  = db.transaction(IDB_STORE, 'readonly');
                    var req = tx.objectStore(IDB_STORE).get(key);
                    req.onsuccess = function () { resolve(req.result ? req.result.blob : null); };
                    req.onerror   = function (e) { reject(e.target.error); };
                });
            });
        }

        function idbRemoveBlob(key) {
            return openBlobDB().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction(IDB_STORE, 'readwrite');
                    tx.objectStore(IDB_STORE).delete(key);
                    tx.oncomplete = resolve;
                    tx.onerror    = resolve; // silent
                });
            }).catch(function () {});
        }

        // ── Public: save, load, remove ────────────────────────────────────
        function save(file) {
            var fs = getFilesystem();

            if (fs) {
                // ── Capacitor native: write to device filesystem ──────────
                var ext      = (file.name || 'photo.jpg').split('.').pop() || 'jpg';
                var filename = PHOTO_DIR + '/' + uuid() + '.' + ext;
                return fileToBase64(file).then(function (b64) {
                    return fs.writeFile({
                        path      : filename,
                        data      : b64,
                        directory : 'CACHE',
                        recursive : true   // create PHOTO_DIR if needed
                    });
                }).then(function (result) {
                    return {
                        type     : 'filesystem',
                        ref      : result.uri || filename,
                        mime     : file.type || 'image/jpeg',
                        filename : file.name || 'photo.jpg'
                    };
                });
            } else {
                // ── Browser / PWA: write blob to IndexedDB ────────────────
                return idbSaveBlob(file, file.type).then(function (key) {
                    return {
                        type     : 'idb',
                        ref      : key,
                        mime     : file.type || 'image/jpeg',
                        filename : file.name || 'photo.jpg'
                    };
                });
            }
        }

        function load(storageType, storageRef, mime) {
            if (storageType === 'filesystem') {
                var fs = getFilesystem();
                if (!fs) return Promise.resolve(null);
                return fs.readFile({ path: storageRef }).then(function (result) {
                    return base64ToBlob(result.data, mime || 'image/jpeg');
                }).catch(function () { return null; });
            } else {
                // idb
                return idbLoadBlob(storageRef);
            }
        }

        function remove(storageType, storageRef) {
            if (storageType === 'filesystem') {
                var fs = getFilesystem();
                if (!fs) return Promise.resolve();
                return fs.deleteFile({ path: storageRef }).catch(function () {});
            } else {
                return idbRemoveBlob(storageRef);
            }
        }

        return { save: save, load: load, remove: remove };
    })();


    // =========================================================================
    // PhotoQueue — IndexedDB metadata queue
    // =========================================================================
    var PhotoQueue = (function () {
        var DB_NAME    = 'mowology-photo-queue';
        var DB_VERSION = 1;
        var STORE      = 'uploads';
        var _db        = null;

        function openDB() {
            if (_db) return Promise.resolve(_db);
            return new Promise(function (resolve, reject) {
                var req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = function (e) {
                    var db    = e.target.result;
                    var store = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('status', 'status', { unique: false });
                };
                req.onsuccess  = function (e) { _db = e.target.result; resolve(_db); };
                req.onerror    = function (e) { reject(e.target.error); };
            });
        }

        function add(record) {
            return openDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx  = db.transaction(STORE, 'readwrite');
                    var req = tx.objectStore(STORE).add(record);
                    req.onsuccess = function () { resolve(req.result); };
                    req.onerror   = function (e) { reject(e.target.error); };
                });
            });
        }

        function patch(id, updates) {
            return openDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx    = db.transaction(STORE, 'readwrite');
                    var store = tx.objectStore(STORE);
                    var req   = store.get(id);
                    req.onsuccess = function () {
                        var rec = req.result;
                        if (!rec) { resolve(); return; }
                        Object.assign(rec, updates);
                        store.put(rec);
                        tx.oncomplete = resolve;
                    };
                    req.onerror = function (e) { reject(e.target.error); };
                });
            });
        }

        function remove(id) {
            return openDB().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction(STORE, 'readwrite');
                    tx.objectStore(STORE).delete(id);
                    tx.oncomplete = resolve;
                    tx.onerror    = resolve;
                });
            }).catch(function () {});
        }

        function getByStatus(statuses) {
            return openDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx      = db.transaction(STORE, 'readonly');
                    var index   = tx.objectStore(STORE).index('status');
                    var results = [];
                    var pending = statuses.length;

                    statuses.forEach(function (status) {
                        var req = index.openCursor(IDBKeyRange.only(status));
                        req.onsuccess = function (e) {
                            var cursor = e.target.result;
                            if (cursor) { results.push(cursor.value); cursor.continue(); }
                        };
                        req.onerror = function () {}; // ignore per-status errors
                    });

                    tx.oncomplete = function () { resolve(results); };
                    tx.onerror    = function (e) { reject(e.target.error); };
                });
            });
        }

        function count() {
            return openDB().then(function (db) {
                return new Promise(function (resolve) {
                    var tx  = db.transaction(STORE, 'readonly');
                    var req = tx.objectStore(STORE).count();
                    req.onsuccess = function () { resolve(req.result); };
                    req.onerror   = function () { resolve(0); };
                });
            }).catch(function () { return 0; });
        }

        /**
         * Get all non-completed records for a specific upload context.
         * Used on page load to restore preview cards for photos that are still
         * pending upload — so the user always sees their photos, never wonders
         * if they were lost.
         *
         * @param {string} contextType
         * @param {string|number} contextId
         * @returns {Promise<Array>} array of raw queue records (with .blob loadable
         *   via PhotoStorage.load if needed)
         */
        function getForContext(contextType, contextId) {
            return openDB().then(function (db) {
                return new Promise(function (resolve) {
                    var tx      = db.transaction(STORE, 'readonly');
                    var results = [];
                    var req     = tx.objectStore(STORE).openCursor();
                    req.onsuccess = function (e) {
                        var cursor = e.target.result;
                        if (!cursor) return;
                        var r = cursor.value;
                        if (r.contextType === contextType &&
                            String(r.contextId) === String(contextId) &&
                            r.status !== 'done') {
                            results.push(r);
                        }
                        cursor.continue();
                    };
                    tx.oncomplete = function () { resolve(results); };
                    tx.onerror    = function () { resolve([]); };
                });
            }).catch(function () { return []; });
        }

        return { add: add, patch: patch, remove: remove, getByStatus: getByStatus, count: count, getForContext: getForContext };
    })();


    // =========================================================================
    // Event bus — simple pub/sub for queue events
    // =========================================================================
    var _listeners = {};

    function emit(event, detail) {
        var fns = _listeners[event] || [];
        fns.forEach(function (fn) {
            try { fn(detail); } catch (e) { /* silent */ }
        });
    }

    function on(event, fn) {
        if (!_listeners[event]) _listeners[event] = [];
        _listeners[event].push(fn);
    }


    // =========================================================================
    // PhotoUploader — queue runner
    // =========================================================================
    var PhotoUploader = (function () {
        var _running = false; // prevent concurrent runs

        function isOnline() {
            // Prefer Capacitor Network plugin (reliable in Android WebView)
            if (global.MwNative && global.MwNative.network) {
                return global.MwNative.network.isOnline !== false;
            }
            return navigator.onLine !== false;
        }

        function run() {
            if (_running || !isOnline()) return;
            _running = true;

            PhotoQueue.getByStatus(['pending', 'failed']).then(function (records) {
                if (!records.length) { _running = false; return; }

                // Filter out records that have exhausted retries
                var actionable = records.filter(function (r) { return (r.retries || 0) < MAX_RETRIES; });

                if (!actionable.length) { _running = false; return; }

                // Upload one at a time (sequential) to conserve bandwidth on mobile
                var chain = Promise.resolve();
                actionable.forEach(function (record) {
                    chain = chain.then(function () { return uploadRecord(record); });
                });
                chain.then(function () { _running = false; });
            }).catch(function () { _running = false; });
        }

        function uploadRecord(record) {
            // Mark as 'uploading' so we don't pick it up in a concurrent run
            return PhotoQueue.patch(record.id, { status: 'uploading' }).then(function () {
                // Load the actual binary from storage
                return PhotoStorage.load(record.storageType, record.storageRef, record.mime);
            }).then(function (blob) {
                if (!blob) {
                    // Storage entry missing — photo was probably cleared by the OS
                    // Remove queue record and report failure
                    PhotoQueue.remove(record.id);
                    emit('mwPhotoFailed', { queueId: record.id, filename: record.filename, error: 'Local file missing' });
                    return;
                }

                var formData = new FormData();
                formData.append('files[]',        blob, record.filename);
                formData.append('csrf_token',     record.csrf        || '');
                formData.append('context_type',   record.contextType || '');
                formData.append('context_id',     String(record.contextId || ''));
                formData.append('category',       record.category    || '');
                formData.append('visibility',     record.visibility  || 'internal');
                if (record.powStamp)     formData.append('pow_stamp',    record.powStamp);
                if (record.gpsLat)      formData.append('gps_lat',      String(record.gpsLat));
                if (record.gpsLng)      formData.append('gps_lng',      String(record.gpsLng));
                if (record.gpsAccuracy) formData.append('gps_accuracy', String(record.gpsAccuracy));

                return fetch(record.uploadUrl || '/crm/api/media-upload.php', {
                    method: 'POST',
                    body  : formData
                })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    var fileResult = (resp.results && resp.results[0]) ? resp.results[0] : null;

                    if (resp.success && fileResult && fileResult.success) {
                        // ── Upload succeeded ──────────────────────────────
                        // Clean up storage — server has the file now
                        PhotoStorage.remove(record.storageType, record.storageRef);
                        PhotoQueue.remove(record.id);

                        emit('mwPhotoUploaded', {
                            queueId    : record.id,
                            mediaId    : fileResult.media_id    || null,
                            uuid       : fileResult.uuid        || null,
                            thumbUrl   : fileResult.thumb_url   || null,
                            isDuplicate: !!fileResult.is_duplicate
                        });
                    } else {
                        // ── Server rejected the upload ────────────────────
                        var errMsg = (fileResult && fileResult.errors)
                            ? fileResult.errors.join(', ')
                            : (resp.error || 'Server error');
                        handleFailure(record, errMsg, false /* don't retry server errors */);
                    }
                })
                .catch(function () {
                    // ── Network / timeout error — retry ──────────────────
                    handleFailure(record, 'Network error', true);
                });
            });
        }

        function handleFailure(record, errMsg, shouldRetry) {
            var retries    = (record.retries || 0) + 1;
            var exhausted  = retries >= MAX_RETRIES;

            if (shouldRetry && !exhausted) {
                var delay = BASE_BACKOFF * Math.pow(2, retries - 1); // 3s,6s,12s,24s,48s
                PhotoQueue.patch(record.id, { status: 'pending', retries: retries });
                emit('mwPhotoRetrying', { queueId: record.id, attempt: retries });
                setTimeout(function () { uploadRecord(Object.assign({}, record, { retries: retries })); }, delay);
            } else {
                PhotoQueue.patch(record.id, { status: 'failed', retries: retries, lastError: errMsg });
                emit('mwPhotoFailed', { queueId: record.id, filename: record.filename, error: errMsg });
            }
        }

        return { run: run };
    })();


    // =========================================================================
    // Public API — window.MwPhotoQueue
    // =========================================================================

    /**
     * Save a file to durable storage and add it to the upload queue.
     *
     * @param {File}   file  — the raw File from the input/camera
     * @param {object} meta  — upload metadata:
     *   { contextType, contextId, category, visibility, csrf, uploadUrl,
     *     powStamp, gpsLat, gpsLng, gpsAccuracy }
     *
     * @returns {Promise<number>}  queue record ID
     */
    function saveAndEnqueue(file, meta) {
        return PhotoStorage.save(file).then(function (stored) {
            var record = {
                // Storage
                storageType : stored.type,
                storageRef  : stored.ref,
                mime        : stored.mime,
                filename    : file.name || 'photo.jpg',
                fileSize    : file.size || 0,

                // Upload metadata
                contextType : meta.contextType || 'marketing_general',
                contextId   : meta.contextId   || '0',
                category    : meta.category    || '',
                visibility  : meta.visibility  || 'internal',
                csrf        : meta.csrf        || '',
                uploadUrl   : meta.uploadUrl   || '/crm/api/media-upload.php',
                powStamp    : meta.powStamp    || '',
                gpsLat      : meta.gpsLat      || null,
                gpsLng      : meta.gpsLng      || null,
                gpsAccuracy : meta.gpsAccuracy || null,

                // Queue state
                status    : 'pending',
                retries   : 0,
                mediaId   : null,
                timestamp : Date.now()
            };

            return PhotoQueue.add(record);
        }).then(function (queueId) {
            emit('mwPhotoQueued', { queueId: queueId });
            // Kick the queue runner immediately
            PhotoUploader.run();
            return queueId;
        });
    }

    /**
     * Manually trigger the queue runner (e.g. called when the app comes online).
     */
    function processQueue() {
        PhotoUploader.run();
    }

    /**
     * Get all pending/failed photos for a specific context, with local blob URLs
     * ready to render as previews. Call this on page load to restore photo cards
     * so the user always sees their photos even if upload hasn't completed.
     *
     * Each returned item:
     *   { queueId, blobUrl, filename, fileSize, status, retries }
     *
     * The caller is responsible for revoking blobUrls when done (on upload success
     * the mwPhotoUploaded event carries the server thumbUrl to swap in).
     *
     * @param {string} contextType  e.g. 'job_visit'
     * @param {string|number} contextId
     * @returns {Promise<Array>}
     */
    function getPendingForContext(contextType, contextId) {
        return PhotoQueue.getForContext(contextType, contextId).then(function (records) {
            // Load blobs from storage in parallel, build preview items
            return Promise.all(records.map(function (record) {
                return PhotoStorage.load(record.storageType, record.storageRef, record.mime)
                    .then(function (blob) {
                        if (!blob) {
                            // Storage entry gone (OS cleared cache) — clean up queue
                            PhotoQueue.remove(record.id);
                            return null;
                        }
                        return {
                            queueId : record.id,
                            blobUrl : URL.createObjectURL(blob),
                            filename: record.filename  || 'photo.jpg',
                            fileSize: record.fileSize  || 0,
                            status  : record.status    || 'pending',
                            retries : record.retries   || 0,
                            category: record.category  || ''
                        };
                    })
                    .catch(function () { return null; }); // silent on storage errors
            }));
        }).then(function (items) {
            // Filter out nulls (missing/corrupt storage entries)
            return items.filter(Boolean);
        }).catch(function () { return []; });
    }

    /**
     * Returns count of all items currently in the queue (any status).
     * @returns {Promise<number>}
     */
    function getPendingCount() {
        return PhotoQueue.count();
    }


    // =========================================================================
    // Lifecycle hooks — resume on reopen, online, foregrounded
    // =========================================================================
    function setupLifecycleHooks() {
        // Resume pending uploads when the page first loads
        PhotoUploader.run();

        // Device/browser comes back online
        window.addEventListener('online', function () {
            PhotoUploader.run();
        });

        // App foregrounded (all platforms: iOS PWA, Android WebView, desktop)
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') PhotoUploader.run();
        });

        // Android Capacitor: MwNative.network is more reliable than navigator.onLine
        // capacitor-bridge.js runs before this script so MwNative is available
        if (global.MwNative && global.MwNative.network) {
            global.MwNative.network.onStatusChange(function (connected) {
                if (connected) PhotoUploader.run();
            });
        } else {
            // Register a deferred hook in case the bridge loads after this script
            document.addEventListener('mw-capacitor-ready', function () {
                if (global.MwNative && global.MwNative.network) {
                    global.MwNative.network.onStatusChange(function (connected) {
                        if (connected) PhotoUploader.run();
                    });
                }
            });
        }

        // Background Sync registration (supported on Android Chrome / some PWAs)
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.sync.register('photo-queue-sync');
            }).catch(function () { /* Background Sync not available — silent */ });
        }

        // Listen for 'process-photo-queue' message from the service worker.
        // The SW sends this when a photo-queue-sync Background Sync event fires
        // and open CRM pages are detected — it prefers the full engine (with
        // Capacitor Filesystem access) over the SW's IDB-only fallback path.
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', function (e) {
                if (e.data && e.data.type === 'process-photo-queue') {
                    PhotoUploader.run();
                }
                // Also handle receipt-synced messages for badge updates
                if (e.data && e.data.type === 'photo-queue-synced') {
                    emit('mwPhotoQueueSynced', {});
                }
            });
        }
    }


    // =========================================================================
    // Boot
    // =========================================================================
    if (!global.indexedDB) {
        // IndexedDB not available — expose a no-op API so callers don't crash
        global.MwPhotoQueue = {
            saveAndEnqueue      : function () { return Promise.resolve(-1); },
            processQueue        : function () {},
            getPendingCount     : function () { return Promise.resolve(0); },
            getPendingForContext: function () { return Promise.resolve([]); },
            on                  : function () {}
        };
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupLifecycleHooks);
        } else {
            setupLifecycleHooks();
        }

        global.MwPhotoQueue = {
            saveAndEnqueue      : saveAndEnqueue,
            processQueue        : processQueue,
            getPendingCount     : getPendingCount,
            getPendingForContext: getPendingForContext,
            on                  : on
        };
    }

})(window);
