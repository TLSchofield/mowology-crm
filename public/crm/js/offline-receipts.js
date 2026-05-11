/**
 * Mowology — Offline Receipt Queue
 * ──────────────────────────────────
 * Uses IndexedDB to queue receipt photos when the device is offline.
 * Automatically syncs via Background Sync API when connectivity restores.
 * Falls back to manual retry if Background Sync is not supported.
 *
 * Database: mowology-receipts  (version 2)
 *   Store: pending-receipts  (keyPath: id, autoIncrement)
 *     Record shape: { id, blob, lat, lng, csrf, timestamp, previewDataUrl? }
 *   Store: pending-ocr       (keyPath: ocr_job_id)   ← v2
 *     Record shape: { ocr_job_id, media_id, file_path, queued_at }
 *
 * `previewDataUrl` is a data:image/jpeg;base64,... string of the ORIGINAL
 * captured file, used to render thumbnails for queued receipts on reload.
 * Data URLs survive page reloads; blob URLs from `URL.createObjectURL` do
 * not (they're revoked on navigation), and on Android WebView even live
 * blob URLs sometimes render as solid black. Store the data URL instead.
 *
 * The pending-ocr store tracks server-side OCR jobs that are still
 * 'pending' or 'processing'. Endpoints can return either 200 (legacy
 * synchronous OCR) or 202 (async queue, with `ocr_job_id` in the
 * envelope's `data` field). Today only iOS uploads via /receipt-upload
 * return 202; the web/CSRF /receipt-intake path is still synchronous.
 * The receipt-detail UI (and any future inbox/parsing view) can
 * subscribe via OfflineReceipts.pollOcrJob() once a 202 path is wired.
 *
 * Usage:
 *   OfflineReceipts.init()                        — open DB, set up listeners
 *   OfflineReceipts.queue(file, lat, lng, csrf, previewDataUrl?)  — save to IDB + register sync
 *   OfflineReceipts.getPendingCount()             — Promise<number> queued uploads
 *   OfflineReceipts.syncNow()                     — manually upload all pending
 *   OfflineReceipts.getPendingOcrJobs()           — Promise<Array> of jobs awaiting OCR
 *   OfflineReceipts.pollOcrJob(id, opts)          — poll receipt-ocr-status.php every 5s
 *                                                    until complete/failed/timeout (60s)
 */
(function() {
    'use strict';

    var DB_NAME    = 'mowology-receipts';
    var DB_VERSION = 2;
    var STORE_NAME = 'pending-receipts';
    var OCR_STORE  = 'pending-ocr';
    var db = null;

    /**
     * Open / create the IndexedDB database.
     * Returns a Promise<IDBDatabase>.
     */
    function openDB() {
        return new Promise(function(resolve, reject) {
            if (db) { resolve(db); return; }
            if (!window.indexedDB) { reject(new Error('IndexedDB not supported')); return; }

            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function(e) {
                var database = e.target.result;
                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    database.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                }
                if (!database.objectStoreNames.contains(OCR_STORE)) {
                    database.createObjectStore(OCR_STORE, { keyPath: 'ocr_job_id' });
                }
            };

            request.onsuccess = function(e) {
                db = e.target.result;
                resolve(db);
            };

            request.onerror = function() {
                reject(new Error('Failed to open IndexedDB'));
            };
        });
    }

    /**
     * Queue a receipt photo for later upload.
     * @param {File|Blob} file  — the receipt image
     * @param {number|null} lat — GPS latitude
     * @param {number|null} lng — GPS longitude
     * @param {string} csrf     — CSRF token
     * @param {string} [previewDataUrl] — optional data: URL of the original
     *        file for thumbnail rendering (survives reloads / WebView quirks)
     * @returns {Promise<number>} — the stored record ID
     */
    function queue(file, lat, lng, csrf, previewDataUrl) {
        return openDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);

                var record = {
                    blob: file,
                    lat: lat || null,
                    lng: lng || null,
                    csrf: csrf || '',
                    timestamp: Date.now(),
                    previewDataUrl: (typeof previewDataUrl === 'string' && previewDataUrl.indexOf('data:') === 0)
                        ? previewDataUrl
                        : null
                };

                var req = store.add(record);
                req.onsuccess = function() {
                    resolve(req.result);
                };
                req.onerror = function() {
                    reject(new Error('Failed to queue receipt'));
                };

                tx.oncomplete = function() {
                    // Register for background sync if supported
                    registerSync();
                    // Update the offline indicator
                    updatePendingBadge();
                };
            });
        });
    }

    /**
     * Get count of pending receipts.
     * @returns {Promise<number>}
     */
    function getPendingCount() {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var store = tx.objectStore(STORE_NAME);
                var countReq = store.count();
                countReq.onsuccess = function() {
                    resolve(countReq.result);
                };
                countReq.onerror = function() {
                    resolve(0);
                };
            });
        }).catch(function() {
            return 0;
        });
    }

    /**
     * Register a background sync tag so the service worker
     * retries uploads when the device comes back online.
     */
    function registerSync() {
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(function(reg) {
                return reg.sync.register('receipt-upload');
            }).catch(function() {
                // Background sync not available — manual fallback
            });
        }
    }

    /**
     * Manually upload all pending receipts (fallback when Background Sync unavailable).
     * @returns {Promise<{uploaded: number, failed: number}>}
     */
    function syncNow() {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var store = tx.objectStore(STORE_NAME);
                var getAll = store.getAll();

                getAll.onsuccess = function() {
                    var receipts = getAll.result || [];
                    if (!receipts.length) {
                        resolve({ uploaded: 0, failed: 0 });
                        return;
                    }

                    var uploaded = 0;
                    var failed = 0;
                    var chain = Promise.resolve();

                    receipts.forEach(function(receipt) {
                        chain = chain.then(function() {
                            return uploadOne(receipt).then(function(ok) {
                                if (ok) {
                                    uploaded++;
                                    return deleteRecord(receipt.id);
                                } else {
                                    failed++;
                                }
                            });
                        });
                    });

                    chain.then(function() {
                        updatePendingBadge();
                        resolve({ uploaded: uploaded, failed: failed });
                    });
                };

                getAll.onerror = function() {
                    resolve({ uploaded: 0, failed: 0 });
                };
            });
        });
    }

    /**
     * Upload a single receipt record to the intake API.
     * @param {object} receipt — record from IndexedDB
     * @returns {Promise<boolean>} — true if upload succeeded
     *
     * Accepts both legacy 200 (synchronous OCR) and 202 (async OCR queue)
     * responses. When the server returns an ocr_job_id, the job is recorded
     * in the pending-ocr store so the UI can poll for completion later.
     */
    function uploadOne(receipt) {
        var formData = new FormData();
        formData.append('receipt_photo', receipt.blob, 'receipt.jpg');
        formData.append('csrf_token', receipt.csrf || '');
        if (receipt.lat) formData.append('lat', receipt.lat);
        if (receipt.lng) formData.append('lng', receipt.lng);

        return fetch('/crm/api/receipt-intake.php', {
            method: 'POST',
            body: formData
        }).then(function(r) {
            if (!r.ok) return false;
            // Async path: 202 Accepted with ocr_job_id → record for later polling.
            // Sync path:  200 OK with full payload → no extra bookkeeping.
            return r.json().then(function(data) {
                var inner = (data && data.data) ? data.data : data;
                var jobId = inner && (inner.ocr_job_id || inner.ocrJobId);
                if (jobId) {
                    return recordPendingOcr({
                        ocr_job_id: jobId,
                        media_id:   inner.media_id   || null,
                        file_path:  inner.file_path  || null,
                        queued_at:  Date.now()
                    }).then(function() { return true; }, function() { return true; });
                }
                return true;
            }).catch(function() {
                // Server returned 200/202 but body wasn't JSON — still treat upload
                // as successful since the file is on the server.
                return true;
            });
        }).catch(function() {
            return false;
        });
    }

    // ── OCR job tracking (post-202) ──────────────────────────────────

    /**
     * Persist a pending OCR job record so the UI can poll for completion later.
     * @param {object} jobRecord — { ocr_job_id, media_id, file_path, queued_at }
     */
    function recordPendingOcr(jobRecord) {
        return openDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                if (!database.objectStoreNames.contains(OCR_STORE)) { resolve(); return; }
                var tx = database.transaction(OCR_STORE, 'readwrite');
                tx.objectStore(OCR_STORE).put(jobRecord);
                tx.oncomplete = function() { resolve(); };
                tx.onerror    = function() { reject(new Error('Failed to record OCR job')); };
            });
        });
    }

    /**
     * @returns {Promise<Array>} — pending OCR job records (oldest first)
     */
    function getPendingOcrJobs() {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                if (!database.objectStoreNames.contains(OCR_STORE)) { resolve([]); return; }
                var tx = database.transaction(OCR_STORE, 'readonly');
                var req = tx.objectStore(OCR_STORE).getAll();
                req.onsuccess = function() {
                    var rows = (req.result || []).slice().sort(function(a, b) {
                        return (a.queued_at || 0) - (b.queued_at || 0);
                    });
                    resolve(rows);
                };
                req.onerror = function() { resolve([]); };
            });
        }).catch(function() { return []; });
    }

    /**
     * Remove a pending OCR job record (call when complete/failed/dismissed).
     */
    function clearPendingOcr(jobId) {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                if (!database.objectStoreNames.contains(OCR_STORE)) { resolve(); return; }
                var tx = database.transaction(OCR_STORE, 'readwrite');
                tx.objectStore(OCR_STORE).delete(jobId);
                tx.oncomplete = function() { resolve(); };
                tx.onerror    = function() { resolve(); };
            });
        });
    }

    /**
     * Poll receipt-ocr-status.php every 5s until the job reaches a terminal
     * state ('complete' or 'failed') or 60s elapses.
     *
     * @param {number} jobId
     * @param {object} [opts]
     * @param {(payload: object) => void} [opts.onComplete] — fires once with the parsed payload
     * @param {(payload: object) => void} [opts.onFailed]   — fires once with the error payload
     * @param {() => void}                 [opts.onTimeout] — fires once after ~60s with no terminal state
     * @param {(payload: object) => void} [opts.onTick]     — fires every poll (status: 'pending'|'processing')
     * @returns {{stop: () => void}} — call .stop() to cancel polling early
     */
    function pollOcrJob(jobId, opts) {
        opts = opts || {};
        var POLL_MS    = 5000;
        var MAX_POLLS  = 12;          // 12 × 5s = 60s
        var pollCount  = 0;
        var stopped    = false;
        var timer      = null;

        function done() { stopped = true; if (timer) { clearTimeout(timer); timer = null; } }

        function fire() {
            if (stopped) return;
            fetch('/crm/api/receipt-ocr-status.php?id=' + encodeURIComponent(jobId), {
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json().catch(function() { return null; });
            }).then(function(envelope) {
                if (stopped) return;
                var payload = envelope && envelope.data ? envelope.data : envelope;
                if (!payload || !payload.status) {
                    pollCount++;
                    if (pollCount >= MAX_POLLS) { done(); if (opts.onTimeout) opts.onTimeout(); return; }
                    timer = setTimeout(fire, POLL_MS);
                    return;
                }
                if (payload.status === 'complete') {
                    done();
                    clearPendingOcr(jobId);
                    if (opts.onComplete) opts.onComplete(payload);
                    return;
                }
                if (payload.status === 'failed') {
                    done();
                    clearPendingOcr(jobId);
                    if (opts.onFailed) opts.onFailed(payload);
                    return;
                }
                if (opts.onTick) opts.onTick(payload);
                pollCount++;
                if (pollCount >= MAX_POLLS) { done(); if (opts.onTimeout) opts.onTimeout(); return; }
                timer = setTimeout(fire, POLL_MS);
            }).catch(function() {
                if (stopped) return;
                pollCount++;
                if (pollCount >= MAX_POLLS) { done(); if (opts.onTimeout) opts.onTimeout(); return; }
                timer = setTimeout(fire, POLL_MS);
            });
        }

        fire();
        return { stop: done };
    }

    /**
     * Delete a single record from the pending store.
     * @param {number} id
     * @returns {Promise<void>}
     */
    function deleteRecord(id) {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).delete(id);
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { resolve(); };
            });
        });
    }

    /**
     * Update the offline pending badge in the UI.
     * Called after queue/sync operations and on online/offline events.
     */
    function updatePendingBadge() {
        getPendingCount().then(function(count) {
            var banner = document.getElementById('mw-offline-banner');
            var badge = document.getElementById('mw-offline-pending-count');

            if (count > 0) {
                if (badge) badge.textContent = count;
                // Show banner even when online if there are pending items
                if (banner && !navigator.onLine) {
                    banner.style.display = 'flex';
                } else if (banner && navigator.onLine && count > 0) {
                    // Online but still has pending — trigger sync
                    banner.style.display = 'flex';
                    banner.classList.add('mw-offline-syncing');
                    var bannerText = banner.querySelector('.mw-offline-text');
                    if (bannerText) bannerText.textContent = 'Syncing ' + count + ' receipt(s)...';
                    syncNow().then(function(result) {
                        if (result.uploaded > 0) {
                            if (bannerText) bannerText.textContent = result.uploaded + ' receipt(s) synced!';
                            banner.classList.remove('mw-offline-syncing');
                            banner.classList.add('mw-offline-success');
                            setTimeout(function() {
                                banner.style.display = 'none';
                                banner.classList.remove('mw-offline-success');
                            }, 3000);
                            // Reload expenses list to show synced items
                            if (typeof loadExpenses === 'function') loadExpenses();
                        }
                    });
                }
            } else {
                if (banner && navigator.onLine) {
                    banner.style.display = 'none';
                }
            }
        });
    }

    /**
     * Returns true if the device appears to be online.
     * Prefers MwNative.network.isOnline (Capacitor Network plugin — reliable on Android)
     * over navigator.onLine which is unreliable in Android WebView.
     */
    function isOnline() {
        if (window.MwNative && typeof window.MwNative.network === 'object') {
            return window.MwNative.network.isOnline !== false;
        }
        return navigator.onLine !== false;
    }

    /**
     * Initialize: open DB, attach online/offline listeners,
     * listen for service worker sync messages.
     *
     * iOS PWA note: Background Sync API is not supported on iOS Safari/PWA.
     * Android Capacitor note: navigator.onLine is unreliable in WebView; use
     * MwNative.network.onStatusChange() instead (bridged below).
     * We compensate by triggering syncNow() on:
     *   - window 'online' event (browser / iOS PWA)
     *   - MwNative.network.onStatusChange (Android Capacitor)
     *   - document 'visibilitychange' (app foregrounded — all platforms)
     *   - window 'focus' (desktop + some mobile browsers)
     * This ensures pending receipts are uploaded as soon as the device has
     * connectivity and the user returns to the app, even without a service worker.
     */
    function init() {
        openDB().catch(function() {
            // IndexedDB not available — offline receipts disabled
        });

        // ── Online / Offline events (browser / iOS PWA) ──
        window.addEventListener('online', function() {
            var banner = document.getElementById('mw-offline-banner');
            if (banner) {
                banner.classList.remove('mw-offline-offline');
                banner.classList.add('mw-offline-syncing');
            }
            // Auto-sync pending receipts when connectivity restored
            updatePendingBadge();
        });

        window.addEventListener('offline', function() {
            var banner = document.getElementById('mw-offline-banner');
            if (banner) {
                banner.style.display = 'flex';
                banner.classList.add('mw-offline-offline');
                banner.classList.remove('mw-offline-syncing', 'mw-offline-success');
                var bannerText = banner.querySelector('.mw-offline-text');
                if (bannerText) {
                    getPendingCount().then(function(count) {
                        bannerText.textContent = count > 0
                            ? 'Offline — ' + count + ' receipt(s) pending'
                            : 'Offline — receipts will queue automatically';
                    });
                }
            }
        });

        // ── Android Capacitor: bridge MwNative.network → sync trigger ──
        // navigator.onLine is unreliable in Android WebView; the Capacitor Network
        // plugin fires MwNative.network.onStatusChange() reliably instead.
        // capacitor-bridge.js loads before this script, but MwNative.network.init()
        // runs synchronously so onStatusChange is safe to call here.
        if (window.MwNative && window.MwNative.network) {
            window.MwNative.network.onStatusChange(function(connected) {
                var banner = document.getElementById('mw-offline-banner');
                if (connected) {
                    if (banner) {
                        banner.classList.remove('mw-offline-offline');
                        banner.classList.add('mw-offline-syncing');
                    }
                    updatePendingBadge(); // will trigger syncNow() if pending items exist
                } else {
                    if (banner) {
                        banner.style.display = 'flex';
                        banner.classList.add('mw-offline-offline');
                        banner.classList.remove('mw-offline-syncing', 'mw-offline-success');
                        var bannerText = banner.querySelector('.mw-offline-text');
                        if (bannerText) {
                            getPendingCount().then(function(count) {
                                bannerText.textContent = count > 0
                                    ? 'Offline — ' + count + ' receipt(s) pending'
                                    : 'Offline — receipts will queue automatically';
                            });
                        }
                    }
                }
            });
        }

        // ── iOS PWA / Android: sync when app is foregrounded ──
        // visibilitychange fires when the user switches back to the app.
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible' && isOnline()) {
                getPendingCount().then(function(count) {
                    if (count > 0) {
                        updatePendingBadge(); // Will trigger syncNow() if online + pending
                    }
                });
            }
        });

        // ── Additional fallback: window focus (desktop + some mobile browsers) ──
        window.addEventListener('focus', function() {
            if (isOnline()) {
                getPendingCount().then(function(count) {
                    if (count > 0) {
                        updatePendingBadge();
                    }
                });
            }
        });

        // ── Listen for service worker sync completion messages ──
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', function(e) {
                if (e.data && e.data.type === 'receipts-synced') {
                    updatePendingBadge();
                    // Reload the expense list to show synced receipts
                    if (typeof loadExpenses === 'function') loadExpenses();
                }
            });
        }

        // Initial badge update on page load
        updatePendingBadge();
    }

    // ── Public API ──
    window.OfflineReceipts = {
        init: init,
        queue: queue,
        remove: deleteRecord,
        getPendingCount: getPendingCount,
        syncNow: syncNow,
        updatePendingBadge: updatePendingBadge,
        // OCR job polling (post-202)
        getPendingOcrJobs: getPendingOcrJobs,
        clearPendingOcr:   clearPendingOcr,
        pollOcrJob:        pollOcrJob
    };

})();
