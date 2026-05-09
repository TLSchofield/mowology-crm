/**
 * Mowology — Offline Receipt Queue
 * ──────────────────────────────────
 * Uses IndexedDB to queue receipt photos when the device is offline.
 * Automatically syncs via Background Sync API when connectivity restores.
 * Falls back to manual retry if Background Sync is not supported.
 *
 * Database: mowology-receipts  (version 2)
 * Store:    pending-receipts   (keyPath: id, autoIncrement)
 *
 * v2 schema fields per record:
 *   id, blob, lat, lng, csrf, timestamp,
 *   idempotencyKey, attempts, lastAttemptAt, status, lastError, nextAttemptAt
 *
 * status: 'pending' | 'failed_unrecoverable' | 'deadletter'
 *   pending              — eligible for retry
 *   failed_unrecoverable — server returned a 4xx that won't be fixed by retrying
 *                          (e.g. invalid file type, rate limit, corrupted record)
 *   deadletter           — exceeded MAX_ATTEMPTS retries on transient errors
 *
 * Usage:
 *   OfflineReceipts.init()           — open DB, set up listeners
 *   OfflineReceipts.queue(file, lat, lng, csrf)  — save to IDB + register sync
 *   OfflineReceipts.getPendingCount() — returns Promise<number>  (eligible only)
 *   OfflineReceipts.getFailedCount()  — returns Promise<number>  (failed + deadletter)
 *   OfflineReceipts.syncNow()        — manually trigger upload of all pending
 *   OfflineReceipts.listAll()        — returns Promise<Array<record>> for UI inspection
 */
(function() {
    'use strict';

    var DB_NAME    = 'mowology-receipts';
    var DB_VERSION = 2;
    var STORE_NAME = 'pending-receipts';
    var MAX_ATTEMPTS = 5;
    var db = null;
    var _isSyncing = false; // re-entrancy guard

    /**
     * Open / create the IndexedDB database. Bumps to v2 to add retry-tracking fields.
     */
    function openDB() {
        return new Promise(function(resolve, reject) {
            if (db) { resolve(db); return; }
            if (!window.indexedDB) { reject(new Error('IndexedDB not supported')); return; }

            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function(e) {
                var database = e.target.result;
                var tx = e.target.transaction;
                var store;

                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    store = database.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                } else {
                    store = tx.objectStore(STORE_NAME);
                }

                // Backfill v2 fields onto any existing v1 records.
                if (e.oldVersion < 2) {
                    var cursorReq = store.openCursor();
                    cursorReq.onsuccess = function(ev) {
                        var cursor = ev.target.result;
                        if (!cursor) return;
                        var rec = cursor.value;
                        if (typeof rec.attempts !== 'number') rec.attempts = 0;
                        if (!rec.status) rec.status = 'pending';
                        if (!rec.idempotencyKey) rec.idempotencyKey = generateIdempotencyKey();
                        if (typeof rec.lastAttemptAt !== 'number') rec.lastAttemptAt = 0;
                        if (typeof rec.nextAttemptAt !== 'number') rec.nextAttemptAt = 0;
                        if (!rec.lastError) rec.lastError = '';
                        cursor.update(rec);
                        cursor.continue();
                    };
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
     * Generate a UUIDv4-ish idempotency key. Stored alongside the record so
     * retries — including from a different device session — collapse to a single
     * server-side resource.
     */
    function generateIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Fallback for older WebViews
        var d = Date.now();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = (d + Math.random() * 16) % 16 | 0;
            d = Math.floor(d / 16);
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    /**
     * Queue a receipt photo for later upload. Returns the stored record ID.
     */
    function queue(file, lat, lng, csrf) {
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
                    idempotencyKey: generateIdempotencyKey(),
                    attempts: 0,
                    lastAttemptAt: 0,
                    nextAttemptAt: 0,
                    status: 'pending',
                    lastError: ''
                };

                var req = store.add(record);
                req.onsuccess = function() { resolve(req.result); };
                req.onerror = function() { reject(new Error('Failed to queue receipt')); };

                tx.oncomplete = function() {
                    registerSync();
                    updatePendingBadge();
                };
            });
        });
    }

    /**
     * Count records eligible for upload (status === 'pending').
     */
    function getPendingCount() {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var getAll = tx.objectStore(STORE_NAME).getAll();
                getAll.onsuccess = function() {
                    var rows = getAll.result || [];
                    var n = 0;
                    for (var i = 0; i < rows.length; i++) {
                        if ((rows[i].status || 'pending') === 'pending') n++;
                    }
                    resolve(n);
                };
                getAll.onerror = function() { resolve(0); };
            });
        }).catch(function() { return 0; });
    }

    /**
     * Count failed_unrecoverable + deadletter records — surfaced in the UI badge.
     */
    function getFailedCount() {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var getAll = tx.objectStore(STORE_NAME).getAll();
                getAll.onsuccess = function() {
                    var rows = getAll.result || [];
                    var n = 0;
                    for (var i = 0; i < rows.length; i++) {
                        var s = rows[i].status;
                        if (s === 'failed_unrecoverable' || s === 'deadletter') n++;
                    }
                    resolve(n);
                };
                getAll.onerror = function() { resolve(0); };
            });
        }).catch(function() { return 0; });
    }

    /** Return all records for UI inspection (failures detail view). */
    function listAll() {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var getAll = tx.objectStore(STORE_NAME).getAll();
                getAll.onsuccess = function() { resolve(getAll.result || []); };
                getAll.onerror = function() { resolve([]); };
            });
        }).catch(function() { return []; });
    }

    /** Update a single record by id with the supplied patch (shallow merge). */
    function updateRecord(id, patch) {
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var getReq = store.get(id);
                getReq.onsuccess = function() {
                    var rec = getReq.result;
                    if (!rec) { resolve(false); return; }
                    for (var k in patch) {
                        if (Object.prototype.hasOwnProperty.call(patch, k)) rec[k] = patch[k];
                    }
                    var putReq = store.put(rec);
                    putReq.onsuccess = function() { resolve(true); };
                    putReq.onerror = function() { resolve(false); };
                };
                getReq.onerror = function() { resolve(false); };
            });
        });
    }

    /**
     * Compute the next retry timestamp using exponential backoff capped at 1h.
     * attempts:1 → 2s, 2 → 4s, 3 → 8s, 4 → 16s, 5 → 32s ... (Math.min cap)
     */
    function computeNextAttemptAt(attempts) {
        var seconds = Math.min(3600, Math.pow(2, attempts));
        return Date.now() + seconds * 1000;
    }

    /**
     * Register a background sync tag so the service worker
     * retries uploads when the device comes back online.
     */
    function registerSync() {
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(function(reg) {
                return reg.sync.register('receipt-upload');
            }).catch(function() { /* manual fallback */ });
        }
    }

    /**
     * Manually upload all eligible (pending + cooled-off) receipts.
     * Re-entrancy-guarded; failed_unrecoverable / deadletter records are skipped.
     */
    function syncNow() {
        if (_isSyncing) return Promise.resolve({ uploaded: 0, failed: 0, skipped: 0 });
        _isSyncing = true;
        return openDB().then(function(database) {
            return new Promise(function(resolve) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var getAll = tx.objectStore(STORE_NAME).getAll();

                getAll.onsuccess = function() {
                    var receipts = getAll.result || [];
                    var now = Date.now();
                    var eligible = receipts.filter(function(r) {
                        var s = r.status || 'pending';
                        if (s !== 'pending') return false;
                        var next = r.nextAttemptAt || 0;
                        return next <= now;
                    });

                    if (!eligible.length) {
                        _isSyncing = false;
                        resolve({ uploaded: 0, failed: 0, skipped: receipts.length });
                        return;
                    }

                    var uploaded = 0;
                    var failed = 0;
                    var chain = Promise.resolve();

                    eligible.forEach(function(receipt) {
                        chain = chain.then(function() {
                            return uploadOne(receipt).then(function(ok) {
                                if (ok) {
                                    uploaded++;
                                    return deleteRecord(receipt.id);
                                }
                                failed++;
                            });
                        });
                    });

                    chain.then(function() {
                        _isSyncing = false;
                        if (uploaded > 0) {
                            updatePendingBadge();
                        } else {
                            // All failed — refresh badge counts but DON'T retrigger sync
                            // to avoid a tight loop while server is unreachable.
                            renderBadgeCounts();
                        }
                        resolve({ uploaded: uploaded, failed: failed, skipped: 0 });
                    });
                };

                getAll.onerror = function() {
                    _isSyncing = false;
                    resolve({ uploaded: 0, failed: 0, skipped: 0 });
                };
            });
        }).catch(function() {
            _isSyncing = false;
            return { uploaded: 0, failed: 0, skipped: 0 };
        });
    }

    /**
     * Upload a single receipt record to the intake API.
     * Returns true on success (record can be deleted), false on failure (record updated in place).
     *
     * Outcome handling:
     *   - 2xx + json.success + (media_id || duplicate_image): true  (caller deletes the record)
     *   - 4xx (except 408/429): record marked failed_unrecoverable
     *   - 408/429/5xx/network: record attempts++ + backoff; at MAX_ATTEMPTS → deadletter
     */
    function uploadOne(receipt) {
        // Prefer the page's live CSRF token over the stored one — the PHP session
        // may have rotated while the receipt was queued. The server also accepts
        // an Idempotency-Key in lieu of CSRF for queued offline uploads, so this
        // is belt-and-suspenders.
        var csrf = (typeof window.CSRF === 'string' && window.CSRF)
            ? window.CSRF
            : (receipt.csrf || '');

        var idempotencyKey = receipt.idempotencyKey || generateIdempotencyKey();

        var formData = new FormData();
        formData.append('receipt_photo', receipt.blob, 'receipt.jpg');
        formData.append('csrf_token', csrf);
        formData.append('idempotency_key', idempotencyKey);
        if (receipt.lat) formData.append('lat', receipt.lat);
        if (receipt.lng) formData.append('lng', receipt.lng);

        return fetch('/crm/api/receipt-intake.php', {
            method: 'POST',
            headers: { 'Idempotency-Key': idempotencyKey },
            body: formData
        }).then(function(r) {
            return r.text().then(function(body) {
                var json = null;
                try { json = body ? JSON.parse(body) : null; } catch (e) { /* non-JSON */ }

                if (r.ok) {
                    var ok = !!(json && json.success && (json.media_id || (json.duplicate_image && json.duplicate_image.existing_media_id)));
                    if (ok) return true;
                    // 200 but unexpected body — treat as transient
                    return recordTransientFailure(receipt, 'No media_id in response');
                }
                if (r.status >= 400 && r.status < 500 && r.status !== 408 && r.status !== 429) {
                    var msg = 'HTTP ' + r.status + (json && json.error ? ' — ' + json.error : '');
                    return recordUnrecoverable(receipt, msg);
                }
                return recordTransientFailure(receipt, 'HTTP ' + r.status);
            });
        }).catch(function(err) {
            return recordTransientFailure(receipt, (err && err.message) ? err.message : 'Network error');
        });
    }

    /** Mark a record as unrecoverable — won't be retried. Returns false. */
    function recordUnrecoverable(receipt, errMsg) {
        return updateRecord(receipt.id, {
            status: 'failed_unrecoverable',
            lastError: String(errMsg || '').slice(0, 500),
            lastAttemptAt: Date.now(),
            attempts: (receipt.attempts || 0) + 1
        }).then(function() { return false; });
    }

    /** Increment attempts + backoff. At MAX → deadletter. Returns false. */
    function recordTransientFailure(receipt, errMsg) {
        var attempts = (receipt.attempts || 0) + 1;
        var patch = {
            attempts: attempts,
            lastAttemptAt: Date.now(),
            lastError: String(errMsg || '').slice(0, 500)
        };
        if (attempts >= MAX_ATTEMPTS) {
            patch.status = 'deadletter';
        } else {
            patch.status = 'pending';
            patch.nextAttemptAt = computeNextAttemptAt(attempts);
        }
        return updateRecord(receipt.id, patch).then(function() { return false; });
    }

    /** Delete a single record from the pending store. */
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

    /** Refresh just the count text on the banner without triggering sync. */
    function renderBadgeCounts() {
        return Promise.all([getPendingCount(), getFailedCount()]).then(function(arr) {
            var pending = arr[0], failed = arr[1];
            var badge = document.getElementById('mw-offline-pending-count');
            if (badge) badge.textContent = pending;

            var banner = document.getElementById('mw-offline-banner');
            if (!banner) return;

            // Failed-uploads chip (created on demand)
            var failedChip = document.getElementById('mw-offline-failed-chip');
            if (failed > 0) {
                if (!failedChip) {
                    failedChip = document.createElement('button');
                    failedChip.type = 'button';
                    failedChip.id = 'mw-offline-failed-chip';
                    failedChip.className = 'mw-offline-failed-chip';
                    failedChip.style.cssText = 'background:#dc3545;color:#fff;border:none;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-left:8px;cursor:pointer;';
                    failedChip.addEventListener('click', showFailedDetail);
                    var retryBtn = banner.querySelector('.mw-offline-sync-btn');
                    if (retryBtn) {
                        retryBtn.parentNode.insertBefore(failedChip, retryBtn);
                    } else {
                        banner.appendChild(failedChip);
                    }
                }
                failedChip.textContent = failed + ' failed';
                if (banner.style.display === 'none' || !banner.style.display) {
                    banner.style.display = 'flex';
                }
            } else if (failedChip) {
                failedChip.remove();
            }
        });
    }

    /**
     * Lightweight modal listing failed_unrecoverable + deadletter records.
     * Lets the user delete (give up on) bad records.
     */
    function showFailedDetail() {
        listAll().then(function(rows) {
            var failed = rows.filter(function(r) {
                return r.status === 'failed_unrecoverable' || r.status === 'deadletter';
            });
            var existing = document.getElementById('mw-offline-failed-modal');
            if (existing) existing.remove();

            var modal = document.createElement('div');
            modal.id = 'mw-offline-failed-modal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;';

            var card = document.createElement('div');
            card.style.cssText = 'background:#fff;border-radius:8px;max-width:520px;width:100%;max-height:80vh;overflow:auto;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,0.25);';

            var heading = document.createElement('h3');
            heading.textContent = 'Failed Receipt Uploads';
            heading.style.cssText = 'margin:0 0 12px 0;font-size:18px;';
            card.appendChild(heading);

            if (!failed.length) {
                var p = document.createElement('p');
                p.textContent = 'No failed uploads.';
                card.appendChild(p);
            } else {
                failed.forEach(function(r) {
                    var row = document.createElement('div');
                    row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:8px;';
                    var info = document.createElement('div');
                    info.style.cssText = 'flex:1;min-width:0;font-size:13px;';
                    var when = new Date(r.timestamp || 0).toLocaleString();
                    var status = r.status === 'deadletter' ? 'Gave up after retries' : 'Server rejected';
                    info.innerHTML = '<strong>' + status + '</strong><br>' +
                        '<span style="color:#6b7280;">' + when + ' · ' + (r.attempts || 0) + ' attempt(s)</span><br>' +
                        '<span style="color:#dc3545;word-break:break-word;">' + (r.lastError || 'Unknown error') + '</span>';
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.textContent = 'Delete';
                    del.style.cssText = 'background:#fff;border:1px solid #dc3545;color:#dc3545;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer;flex-shrink:0;';
                    del.addEventListener('click', function() {
                        deleteRecord(r.id).then(function() {
                            row.remove();
                            renderBadgeCounts();
                        });
                    });
                    row.appendChild(info);
                    row.appendChild(del);
                    card.appendChild(row);
                });
            }

            var close = document.createElement('button');
            close.type = 'button';
            close.textContent = 'Close';
            close.style.cssText = 'margin-top:12px;background:#2D8659;color:#fff;border:none;border-radius:4px;padding:8px 16px;font-size:14px;cursor:pointer;width:100%;';
            close.addEventListener('click', function() { modal.remove(); });
            card.appendChild(close);

            modal.appendChild(card);
            modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
            document.body.appendChild(modal);
        });
    }

    /**
     * Update the offline pending banner + badge. Called after queue/sync ops
     * and on online/offline events. May trigger a sync if online + pending > 0.
     */
    function updatePendingBadge() {
        renderBadgeCounts();
        getPendingCount().then(function(count) {
            var banner = document.getElementById('mw-offline-banner');
            if (count > 0) {
                if (banner && !isOnline()) {
                    banner.style.display = 'flex';
                } else if (banner && isOnline()) {
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
                                getFailedCount().then(function(fc) {
                                    // Keep banner visible if we still have failed records to surface.
                                    if (fc === 0) {
                                        banner.style.display = 'none';
                                        banner.classList.remove('mw-offline-success');
                                    } else {
                                        banner.classList.remove('mw-offline-success');
                                    }
                                });
                            }, 3000);
                            if (typeof loadExpenses === 'function') loadExpenses();
                        }
                    });
                }
            } else {
                getFailedCount().then(function(fc) {
                    if (banner && isOnline() && fc === 0) {
                        banner.style.display = 'none';
                    }
                });
            }
        });
    }

    /**
     * Returns true if the device appears to be online.
     * Prefers MwNative.network.isOnline (Capacitor Network — reliable on Android)
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
     */
    function init() {
        openDB().catch(function() { /* IDB unavailable — offline disabled */ });

        var _orAbort = new AbortController();
        var _orSig = { signal: _orAbort.signal };

        window.addEventListener('online', function() {
            var banner = document.getElementById('mw-offline-banner');
            if (banner) {
                banner.classList.remove('mw-offline-offline');
                banner.classList.add('mw-offline-syncing');
            }
            updatePendingBadge();
        }, _orSig);

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
        }, _orSig);

        window.addEventListener('pagehide', function() {
            try { _orAbort.abort(); } catch (e) { /* ignore */ }
        }, { once: true });

        // Android Capacitor Network bridge
        if (window.MwNative && window.MwNative.network) {
            window.MwNative.network.onStatusChange(function(connected) {
                var banner = document.getElementById('mw-offline-banner');
                if (connected) {
                    if (banner) {
                        banner.classList.remove('mw-offline-offline');
                        banner.classList.add('mw-offline-syncing');
                    }
                    updatePendingBadge();
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

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible' && isOnline()) {
                getPendingCount().then(function(count) {
                    if (count > 0) updatePendingBadge();
                });
            }
        });

        window.addEventListener('focus', function() {
            if (isOnline()) {
                getPendingCount().then(function(count) {
                    if (count > 0) updatePendingBadge();
                });
            }
        });

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', function(e) {
                if (e.data && e.data.type === 'receipts-synced') {
                    updatePendingBadge();
                    if (typeof loadExpenses === 'function') loadExpenses();
                }
            });
        }

        updatePendingBadge();
    }

    // ── Public API ──
    window.OfflineReceipts = {
        init: init,
        queue: queue,
        remove: deleteRecord,
        getPendingCount: getPendingCount,
        getFailedCount: getFailedCount,
        listAll: listAll,
        syncNow: syncNow,
        updatePendingBadge: updatePendingBadge,
        showFailedDetail: showFailedDetail
    };

})();
