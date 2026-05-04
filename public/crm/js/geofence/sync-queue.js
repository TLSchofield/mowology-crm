/**
 * GeofenceSyncQueue — Offline-first batch uploader for geofence location samples.
 *
 * ── Architecture ──────────────────────────────────────────────────────────────
 *
 * Samples collected by LocationSampler are:
 *   1. Queued in memory
 *   2. Periodically flushed to the server in batches (POST sync_samples)
 *   3. On flush failure: retained in memory and retried (exponential back-off)
 *   4. On page reload: NOT persisted across sessions (visiting browser restores
 *      the page mid-shift is a rare edge case; the server-side POW GPS sync
 *      already handles that via visit_gps_points).
 *
 * ── Usage ─────────────────────────────────────────────────────────────────────
 *
 *   const q = new GeofenceSyncQueue({
 *     visitId:       7,
 *     geofenceId:    42,
 *     apiBase:       '/crm/api/geofence.php',
 *     csrfToken:     '...',
 *     flushIntervalSec: 30,    // how often to auto-flush
 *     maxBatchSize:     50,    // max samples per HTTP request
 *     onFlush:     (result) => console.log('Flushed', result.inserted),
 *     onFlushFail: (err)    => console.warn('Flush failed', err),
 *   });
 *
 *   q.start();
 *   q.enqueue(sample);
 *   q.flush();   // force immediate flush
 *   q.stop();    // stop auto-flush (final flush is called by LocationSampler.stop)
 */

'use strict';

// ── IndexedDB constants ────────────────────────────────────────────────────
const _GFQ_IDB_NAME    = 'mw-geofence-v1';
const _GFQ_IDB_VERSION = 1;
const _GFQ_STORE       = 'pending-samples';

class GeofenceSyncQueue {

    constructor(opts = {}) {
        this._opts = Object.assign({
            visitId:          null,
            geofenceId:       null,
            apiBase:          '/crm/api/geofence.php',
            csrfToken:        '',
            flushIntervalSec: 30,
            maxBatchSize:     50,
            onFlush:          null,
            onFlushFail:      null,
        }, opts);

        this._queue        = [];      // pending samples
        this._inflight     = false;   // a flush is in progress
        this._running      = false;
        this._timerId      = null;
        this._backoffMs    = 0;       // current back-off delay
        this._totalSent    = 0;
        this._totalFailed  = 0;
        this._retryQueue   = [];      // samples that failed on last attempt

        // Promise<IDBDatabase | null> — null when IDB is unavailable.
        // Resolved after start() opens the database and restores any
        // samples that survived a previous page reload.
        this._idb          = null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────────────────────────────────

    start() {
        if (this._running) return;
        this._running = true;

        // Open IndexedDB and restore any samples that survived a page reload
        // before scheduling the first flush so they're included.
        this._idb = this._openIdb()
            .then(db => this._restoreFromIdb(db).then(() => db))
            .then(db => {
                this._scheduleFlush();
                return db;
            })
            .catch(err => {
                console.warn('[GeofenceSyncQueue] IDB unavailable — samples will not',
                             'survive a page reload:', err.message);
                this._scheduleFlush();   // still run without persistence
                return null;
            });

        console.debug('[GeofenceSyncQueue] Started. Interval:', this._opts.flushIntervalSec, 's');
    }

    stop() {
        this._running = false;
        if (this._timerId) {
            clearTimeout(this._timerId);
            this._timerId = null;
        }
        // Final flush — don't await; let the fetch complete in background
        this.flush();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Enqueue
    // ──────────────────────────────────────────────────────────────────────────

    enqueue(sample) {
        this._queue.push(sample);

        // Persist to IDB immediately so this sample survives a page reload.
        // Fire-and-forget — do not block the sample ingestion path.
        // The IDB auto-increment key is stamped back on the sample object
        // as _idbKey once the write completes; _sendBatch uses it to delete
        // the record after a successful server upload.
        this._persistToIdb(sample);

        // Immediate flush if queue is large (avoid memory buildup on slow networks)
        if (this._queue.length >= this._opts.maxBatchSize && !this._inflight) {
            this.flush();
        }
    }

    get queueLength() { return this._queue.length + this._retryQueue.length; }

    // ──────────────────────────────────────────────────────────────────────────
    // Flush
    // ──────────────────────────────────────────────────────────────────────────

    flush() {
        if (this._inflight) return Promise.resolve({ skipped: true });

        // Combine retry queue + current queue
        const combined = [...this._retryQueue, ...this._queue];
        this._retryQueue = [];
        this._queue      = [];

        if (!combined.length) return Promise.resolve({ inserted: 0, skipped: 0 });
        if (!navigator.onLine) {
            // Offline — put everything back into retry queue
            this._retryQueue = combined;
            console.debug('[GeofenceSyncQueue] Offline — deferred', combined.length, 'samples');
            return Promise.resolve({ deferred: combined.length });
        }

        // Slice into batches
        const batches = [];
        for (let i = 0; i < combined.length; i += this._opts.maxBatchSize) {
            batches.push(combined.slice(i, i + this._opts.maxBatchSize));
        }

        this._inflight = true;
        return this._sendBatches(batches)
            .then(result => {
                this._inflight  = false;
                this._backoffMs = 0;  // reset back-off on success
                this._totalSent += result.inserted;
                if (typeof this._opts.onFlush === 'function') this._opts.onFlush(result);
                return result;
            })
            .catch(err => {
                this._inflight = false;
                // Put all samples back into the retry queue
                this._retryQueue = [...combined, ...this._retryQueue];
                this._totalFailed++;
                this._backoffMs = Math.min(
                    this._backoffMs ? this._backoffMs * 2 : 5000,
                    120000   // cap at 2 minutes
                );
                console.warn('[GeofenceSyncQueue] Flush failed, backing off',
                             this._backoffMs / 1000, 's. Queued:', this._retryQueue.length);
                if (typeof this._opts.onFlushFail === 'function') this._opts.onFlushFail(err);
                return { error: err.message };
            });
    }

    // Send all batches sequentially (one HTTP request per batch)
    _sendBatches(batches) {
        let totalInserted = 0;
        let totalSkipped  = 0;

        const sendNext = (i) => {
            if (i >= batches.length) {
                return Promise.resolve({ inserted: totalInserted, skipped: totalSkipped });
            }
            return this._sendBatch(batches[i]).then(res => {
                totalInserted += res.inserted || 0;
                totalSkipped  += res.skipped  || 0;
                return sendNext(i + 1);
            });
        };

        return sendNext(0);
    }

    _sendBatch(samples) {
        // Strip the internal IDB tracking key before sending to the server.
        // The server must never receive _idbKey in the sample payload.
        const cleanSamples = samples.map(s => {
            const { _idbKey, ...rest } = s;  // eslint-disable-line no-unused-vars
            return rest;
        });

        const body = JSON.stringify({
            action:      'sync_samples',
            csrf_token:  this._opts.csrfToken,
            visit_id:    this._opts.visitId,
            geofence_id: this._opts.geofenceId,
            samples:     cleanSamples,
        });

        return fetch(this._opts.apiBase, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body,
        })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Sync error');
            // Delete successfully-sent records from IDB so they don't
            // get re-submitted after a future page reload.
            const sentKeys = samples
                .filter(s => s._idbKey !== undefined)
                .map(s => s._idbKey);
            this._deleteFromIdb(sentKeys);
            console.debug('[GeofenceSyncQueue] Batch sent:',
                          data.inserted, 'inserted,', data.skipped, 'skipped');
            return data;
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Scheduling
    // ──────────────────────────────────────────────────────────────────────────

    _scheduleFlush() {
        if (!this._running) return;

        // Use back-off if last flush failed, otherwise use the configured interval
        const delay = this._backoffMs > 0
            ? this._backoffMs
            : this._opts.flushIntervalSec * 1000;

        this._timerId = setTimeout(() => {
            this.flush().finally(() => this._scheduleFlush());
        }, delay);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // IndexedDB helpers — durability layer for page-reload survival
    // ──────────────────────────────────────────────────────────────────────────

    _openIdb() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB not available'));
                return;
            }
            const req = indexedDB.open(_GFQ_IDB_NAME, _GFQ_IDB_VERSION);
            req.onupgradeneeded = e => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(_GFQ_STORE)) {
                    const store = db.createObjectStore(_GFQ_STORE, {
                        keyPath:       '_idbKey',
                        autoIncrement: true,
                    });
                    // Index on visitId so we only restore samples for the current visit.
                    store.createIndex('byVisit', '_visitId', { unique: false });
                }
            };
            req.onsuccess = e => resolve(e.target.result);
            req.onerror   = e => reject(e.target.error);
        });
    }

    _restoreFromIdb(db) {
        return new Promise(resolve => {
            const visitId = this._opts.visitId;
            try {
                const tx    = db.transaction(_GFQ_STORE, 'readonly');
                const store = tx.objectStore(_GFQ_STORE);
                const idx   = store.index('byVisit');
                const req   = idx.getAll(IDBKeyRange.only(visitId));
                req.onsuccess = e => {
                    const records = e.target.result || [];
                    if (records.length > 0) {
                        // Prepend to queue — these are older than anything collected
                        // since page load. _idbKey is already on each record from IDB.
                        this._queue = [...records, ...this._queue];
                        console.debug('[GeofenceSyncQueue] Restored', records.length,
                                      'samples from IDB for visit', visitId);
                    }
                    resolve();
                };
                req.onerror = () => resolve();   // non-fatal
            } catch (e) {
                resolve();
            }
        });
    }

    /** Write one sample to IDB and stamp its auto-increment key back on the object. */
    _persistToIdb(sample) {
        if (!this._idb) return;
        Promise.resolve(this._idb).then(db => {
            if (!db) return;
            // Merge IDB-only fields (visitId index) into the record.
            // These are NOT sent to the server — stripped in _sendBatch.
            const record = Object.assign({}, sample, { _visitId: this._opts.visitId });
            try {
                const tx    = db.transaction(_GFQ_STORE, 'readwrite');
                const store = tx.objectStore(_GFQ_STORE);
                const req   = store.add(record);
                req.onsuccess = e => {
                    // Stamp IDB key onto the in-memory sample object.
                    // _sendBatch reads this key to delete after successful upload.
                    sample._idbKey = e.target.result;
                };
            } catch (e) { /* non-fatal */ }
        });
    }

    /** Delete IDB records after successful server upload. */
    _deleteFromIdb(keys) {
        if (!this._idb || !keys.length) return;
        Promise.resolve(this._idb).then(db => {
            if (!db) return;
            try {
                const tx    = db.transaction(_GFQ_STORE, 'readwrite');
                const store = tx.objectStore(_GFQ_STORE);
                keys.forEach(k => store.delete(k));
            } catch (e) { /* non-fatal */ }
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Stats
    // ──────────────────────────────────────────────────────────────────────────

    get stats() {
        return {
            queued:      this.queueLength,
            totalSent:   this._totalSent,
            totalFailed: this._totalFailed,
            backoffMs:   this._backoffMs,
        };
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { GeofenceSyncQueue };
} else {
    window.GeofenceSyncQueue = GeofenceSyncQueue;
}
