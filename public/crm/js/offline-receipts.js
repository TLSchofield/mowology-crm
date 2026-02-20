/**
 * Mowology — Offline Receipt Queue
 * ──────────────────────────────────
 * Uses IndexedDB to queue receipt photos when the device is offline.
 * Automatically syncs via Background Sync API when connectivity restores.
 * Falls back to manual retry if Background Sync is not supported.
 *
 * Database: mowology-receipts  (version 1)
 * Store:    pending-receipts   (keyPath: id, autoIncrement)
 *
 * Each record: { id, blob, lat, lng, csrf, timestamp }
 *
 * Usage:
 *   OfflineReceipts.init()           — open DB, set up listeners
 *   OfflineReceipts.queue(file, lat, lng, csrf)  — save to IDB + register sync
 *   OfflineReceipts.getPendingCount() — returns Promise<number>
 *   OfflineReceipts.syncNow()        — manually trigger upload of all pending
 */
(function() {
    'use strict';

    var DB_NAME    = 'mowology-receipts';
    var DB_VERSION = 1;
    var STORE_NAME = 'pending-receipts';
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
     * @returns {Promise<number>} — the stored record ID
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
                    timestamp: Date.now()
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
            return r.ok;
        }).catch(function() {
            return false;
        });
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
     * Initialize: open DB, attach online/offline listeners,
     * listen for service worker sync messages.
     *
     * iOS PWA note: Background Sync API is not supported on iOS Safari/PWA.
     * We compensate by triggering syncNow() on:
     *   - window 'online' event
     *   - document 'visibilitychange' (app foregrounded)
     *   - window 'focus' (app regains focus)
     * This ensures pending receipts are uploaded as soon as the device has
     * connectivity and the user returns to the app, even without a service worker.
     */
    function init() {
        openDB().catch(function() {
            // IndexedDB not available — offline receipts disabled
        });

        // ── Online / Offline events ──
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

        // ── iOS PWA fix: sync when app is foregrounded ──
        // document.visibilitychange fires when the user switches back to the app.
        // This is the primary mechanism on iOS PWA where Background Sync is unavailable.
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible' && navigator.onLine) {
                getPendingCount().then(function(count) {
                    if (count > 0) {
                        updatePendingBadge(); // Will trigger syncNow() if online + pending
                    }
                });
            }
        });

        // ── Additional fallback: window focus (desktop + some mobile browsers) ──
        window.addEventListener('focus', function() {
            if (navigator.onLine) {
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
        getPendingCount: getPendingCount,
        syncNow: syncNow,
        updatePendingBadge: updatePendingBadge
    };

})();
