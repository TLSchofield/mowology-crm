/**
 * mw-offline-db.js — Mowology offline IndexedDB store
 *
 * Full server-down survival: schedule cache, quiz cache, GPS ping buffer,
 * and visit photo blob queue — all stored locally and synced on reconnect.
 *
 * Database:  mw-offline  (version 3)
 * Stores:
 *   schedule      — keyPath: 'date' (YYYY-MM-DD)
 *   meta          — keyPath: 'key'
 *   quiz_questions — keyPath: 'id'
 *   quiz_preshift  — keyPath: 'date'
 *   visit_pings   — autoIncrement; index on visit_id  (v3)
 *   visit_photos  — autoIncrement; index on visit_id  (v3)
 *
 * Exposed as window.MwOfflineDB (no dependencies, loads synchronously).
 */
(function () {
    'use strict';

    var DB_NAME    = 'mw-offline';
    var DB_VERSION = 3;
    var S_SCHEDULE = 'schedule';
    var S_META     = 'meta';
    var S_QUIZ_Q   = 'quiz_questions';
    var S_QUIZ_PS  = 'quiz_preshift';
    var S_PINGS    = 'visit_pings';
    var S_PHOTOS   = 'visit_photos';

    // GPS ping interval while on a property (ms)
    var PING_INTERVAL_MS = 30000;
    // Max pings stored per visit (30s × 300 = 2.5 hours before oldest are dropped)
    var PING_MAX         = 300;

    var _pingTimer   = null;
    var _pingVisitId = null;

    // ── DB open ───────────────────────────────────────────────────────────────

    function openDB() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onerror = function () { reject(req.error); };
            req.onsuccess = function () { resolve(req.result); };
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains(S_SCHEDULE)) {
                    db.createObjectStore(S_SCHEDULE, { keyPath: 'date' });
                }
                if (!db.objectStoreNames.contains(S_META)) {
                    db.createObjectStore(S_META, { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(S_QUIZ_Q)) {
                    db.createObjectStore(S_QUIZ_Q, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(S_QUIZ_PS)) {
                    db.createObjectStore(S_QUIZ_PS, { keyPath: 'date' });
                }
                // v3: GPS pings during visits
                if (!db.objectStoreNames.contains(S_PINGS)) {
                    var ps = db.createObjectStore(S_PINGS, { keyPath: 'id', autoIncrement: true });
                    ps.createIndex('by_visit', 'visit_id', { unique: false });
                }
                // v3: offline photo blob queue
                if (!db.objectStoreNames.contains(S_PHOTOS)) {
                    var ph = db.createObjectStore(S_PHOTOS, { keyPath: 'id', autoIncrement: true });
                    ph.createIndex('by_visit', 'visit_id', { unique: false });
                }
            };
        });
    }

    // ── Schedule store ────────────────────────────────────────────────────────

    /**
     * Save an array of day objects returned by /crm/api/offline-schedule.php.
     * days: [{ date: 'YYYY-MM-DD', stops: [...] }, ...]
     */
    function saveScheduleDays(days) {
        if (!Array.isArray(days) || !days.length) return Promise.resolve();
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx   = db.transaction(S_SCHEDULE, 'readwrite');
                var store = tx.objectStore(S_SCHEDULE);
                var now  = Date.now();
                days.forEach(function (day) {
                    store.put({ date: day.date, stops: day.stops || [], savedAt: now });
                });
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }

    /**
     * Get one day's schedule from the cache.
     * Returns { date, stops[], savedAt } or null if not cached.
     */
    function getSchedule(date) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_SCHEDULE, 'readonly');
                var req = tx.objectStore(S_SCHEDULE).get(date);
                req.onsuccess = function () { resolve(req.result || null); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    /**
     * Get all cached schedule days, sorted ascending by date.
     */
    function getAllSchedule() {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_SCHEDULE, 'readonly');
                var req = tx.objectStore(S_SCHEDULE).getAll();
                req.onsuccess = function () {
                    var rows = (req.result || []).sort(function (a, b) {
                        return a.date < b.date ? -1 : a.date > b.date ? 1 : 0;
                    });
                    resolve(rows);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    // ── Meta store ────────────────────────────────────────────────────────────

    function setMeta(key, value) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(S_META, 'readwrite');
                tx.objectStore(S_META).put({ key: key, value: value });
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }

    function getMeta(key) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_META, 'readonly');
                var req = tx.objectStore(S_META).get(key);
                req.onsuccess = function () {
                    resolve(req.result ? req.result.value : null);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    // ── Quiz questions store ──────────────────────────────────────────────────

    /**
     * Save quiz questions array from offline-quiz API.
     * Each question: { id, text, category_name, category_colour, difficulty, options: [{id, text, is_correct}] }
     */
    function saveQuizQuestions(questions) {
        if (!Array.isArray(questions) || !questions.length) return Promise.resolve();
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx    = db.transaction(S_QUIZ_Q, 'readwrite');
                var store = tx.objectStore(S_QUIZ_Q);
                questions.forEach(function (q) { store.put(q); });
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }

    /** Get all cached quiz questions. Returns [] if none cached. */
    function getQuizQuestions() {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_QUIZ_Q, 'readonly');
                var req = tx.objectStore(S_QUIZ_Q).getAll();
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    // ── Quiz preshift log (offline) ───────────────────────────────────────────

    /**
     * Save today's preshift completion locally.
     * data: { correct, asked, completed_at, synced: false }
     */
    function saveQuizPreshift(date, data) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(S_QUIZ_PS, 'readwrite');
                tx.objectStore(S_QUIZ_PS).put(Object.assign({ date: date }, data));
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }

    /** Get preshift log for a given date. Returns null if not recorded. */
    function getQuizPreshift(date) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_QUIZ_PS, 'readonly');
                var req = tx.objectStore(S_QUIZ_PS).get(date);
                req.onsuccess = function () { resolve(req.result || null); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    /** Get all preshift records not yet synced to the server. */
    function getPendingQuizSyncs() {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_QUIZ_PS, 'readonly');
                var req = tx.objectStore(S_QUIZ_PS).getAll();
                req.onsuccess = function () {
                    resolve((req.result || []).filter(function (r) { return !r.synced; }));
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    // ── GPS visit pings ───────────────────────────────────────────────────────

    /**
     * Capture one GPS fix and write it to IDB.
     * Uses MwNative.geo (Capacitor) if available, falls back to browser geolocation.
     */
    function _capturePing(visitId) {
        function storePing(lat, lng, accuracy) {
            openDB().then(function (db) {
                var tx    = db.transaction(S_PINGS, 'readwrite');
                var store = tx.objectStore(S_PINGS);
                store.add({
                    visit_id:  visitId,
                    lat:       lat,
                    lng:       lng,
                    accuracy:  accuracy,
                    timestamp: Date.now(),
                    synced:    false,
                });
                // Enforce ring buffer: count pings for this visit, drop oldest if over limit
                var idx = store.index('by_visit');
                var range = IDBKeyRange.only(visitId);
                var all = idx.getAll(range);
                all.onsuccess = function () {
                    var rows = all.result || [];
                    if (rows.length > PING_MAX) {
                        // Delete oldest (lowest autoIncrement id)
                        rows.sort(function (a, b) { return a.id - b.id; });
                        var toDelete = rows.slice(0, rows.length - PING_MAX);
                        toDelete.forEach(function (r) { store.delete(r.id); });
                    }
                };
            }).catch(function () {});
        }

        // Prefer Capacitor native GPS (more reliable on Android)
        if (window.MwNative && window.MwNative.geo && typeof window.MwNative.geo.getCurrentPosition === 'function') {
            window.MwNative.geo.getCurrentPosition(function (pos) {
                storePing(pos.lat || pos.latitude, pos.lng || pos.longitude, pos.accuracy || 0);
            });
            return;
        }
        // Browser geolocation
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                storePing(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
            }, function () { /* GPS unavailable — skip ping */ }, {
                enableHighAccuracy: true,
                timeout:            10000,
                maximumAge:         0,
            });
        }
    }

    /**
     * Begin GPS pinging for an active visit.
     * Call after start_visit succeeds. Captures first ping immediately.
     */
    function startVisitPings(visitId) {
        if (!visitId) return;
        stopVisitPings(); // clear any previous timer
        _pingVisitId = visitId;
        _capturePing(visitId);
        _pingTimer = setInterval(function () { _capturePing(visitId); }, PING_INTERVAL_MS);
    }

    /**
     * Stop pinging. Call before end_visit (so final position is captured by end_visit GPS).
     */
    function stopVisitPings() {
        if (_pingTimer) { clearInterval(_pingTimer); _pingTimer = null; }
        _pingVisitId = null;
    }

    /**
     * Send all unsynced pings for a visit to tracking-sync.php.
     * Returns Promise<{sent, failed}>.
     */
    function flushVisitPings(visitId) {
        if (!visitId) return Promise.resolve({ sent: 0, failed: 0 });

        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(S_PINGS, 'readonly');
                var req = tx.objectStore(S_PINGS).index('by_visit').getAll(IDBKeyRange.only(visitId));
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror   = function () { reject(req.error); };
            });
        }).then(function (pings) {
            var unsynced = pings.filter(function (p) { return !p.synced; });
            if (!unsynced.length) return { sent: 0, failed: 0 };

            var points = unsynced.map(function (p) {
                return {
                    lat:       p.lat,
                    lng:       p.lng,
                    accuracy:  p.accuracy,
                    visit_id:  p.visit_id,
                    timestamp: p.timestamp,
                };
            });

            var token = window.MwAuth && window.MwAuth.getToken ? window.MwAuth.getToken() : null;
            var headers = { 'Content-Type': 'application/json' };
            if (token) headers['Authorization'] = 'Bearer ' + token;

            var realFetch = window._mwOrigFetch || fetch;
            return realFetch('/crm/api/tracking-sync.php', {
                method:  'POST',
                headers: headers,
                body:    JSON.stringify({ action: 'sync_points', points: points }),
            }).then(function (res) {
                if (!res.ok) return { sent: 0, failed: unsynced.length };
                // Mark synced in IDB
                return openDB().then(function (db) {
                    var tx    = db.transaction(S_PINGS, 'readwrite');
                    var store = tx.objectStore(S_PINGS);
                    unsynced.forEach(function (p) { store.put(Object.assign({}, p, { synced: true })); });
                    return { sent: unsynced.length, failed: 0 };
                });
            }).catch(function () { return { sent: 0, failed: unsynced.length }; });
        });
    }

    /** How many unsynced pings exist for a visit (for debugging/status). */
    function getVisitPingCount(visitId) {
        return openDB().then(function (db) {
            return new Promise(function (resolve) {
                var req = db.transaction(S_PINGS, 'readonly')
                            .objectStore(S_PINGS).index('by_visit')
                            .count(IDBKeyRange.only(visitId));
                req.onsuccess = function () { resolve(req.result); };
                req.onerror   = function () { resolve(0); };
            });
        }).catch(function () { return 0; });
    }

    // ── Offline photo queue ───────────────────────────────────────────────────

    /**
     * Queue a photo blob for upload when server is unreachable.
     * blob: File or Blob object
     * meta: { visit_id, photo_type ('before'|'after'|'during'|'issue') }
     * Returns Promise<id> — the IDB record id.
     */
    function queuePhoto(blob, meta) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(S_PHOTOS, 'readwrite');
                var req = tx.objectStore(S_PHOTOS).add({
                    visit_id:   meta.visit_id,
                    photo_type: meta.photo_type || 'after',
                    blob:       blob,
                    filename:   meta.filename || ('offline-' + Date.now() + '.jpg'),
                    queued_at:  Date.now(),
                    retries:    0,
                    synced:     false,
                });
                req.onsuccess = function () { resolve(req.result); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    /** Get all unsynced photos (for upload on reconnect). */
    function getPendingPhotos() {
        return openDB().then(function (db) {
            return new Promise(function (resolve) {
                var req = db.transaction(S_PHOTOS, 'readonly').objectStore(S_PHOTOS).getAll();
                req.onsuccess = function () {
                    resolve((req.result || []).filter(function (p) { return !p.synced; }));
                };
                req.onerror = function () { resolve([]); };
            });
        });
    }

    /** Mark a queued photo as synced (delete it from the queue). */
    function markPhotoSynced(id) {
        return openDB().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(S_PHOTOS, 'readwrite');
                tx.objectStore(S_PHOTOS).delete(id);
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { resolve(); };
            });
        });
    }

    /**
     * Upload all pending queued photos to pow-actions.php.
     * Runs sequentially; stops on first server error (will retry next online event).
     * Returns Promise<{sent, failed}>.
     */
    function flushPendingPhotos() {
        return getPendingPhotos().then(function (photos) {
            if (!photos.length) return { sent: 0, failed: 0 };

            var token     = window.MwAuth && window.MwAuth.getToken ? window.MwAuth.getToken() : null;
            var realFetch = window._mwOrigFetch || fetch;
            var sent = 0, failed = 0;

            var chain = photos.reduce(function (p, photo) {
                return p.then(function () {
                    var form = new FormData();
                    form.append('action',     'upload_photo');
                    form.append('visit_id',   photo.visit_id);
                    form.append('photo_type', photo.photo_type);
                    form.append('photo',      photo.blob, photo.filename);

                    var headers = {};
                    if (token) headers['Authorization'] = 'Bearer ' + token;

                    return realFetch('/crm/api/pow-actions.php', {
                        method:      'POST',
                        headers:     headers,
                        body:        form,
                        credentials: 'include',
                    }).then(function (res) {
                        return res.ok ? res.json() : null;
                    }).then(function (data) {
                        if (data && data.success) {
                            sent++;
                            return markPhotoSynced(photo.id);
                        }
                        failed++;
                    }).catch(function () { failed++; });
                });
            }, Promise.resolve());

            return chain.then(function () { return { sent: sent, failed: failed }; });
        });
    }

    // ── pow-actions.php response hook ─────────────────────────────────────────
    // Intercepts start_visit / end_visit responses to auto-start/stop GPS pings.
    // Runs only when this file loads (synchronously in <head>) so the hook is
    // in place before any page script calls fetch.

    (function () {
        var _prevFetch = window.fetch;
        window.fetch = function (resource, options) {
            var url    = typeof resource === 'string' ? resource : (resource && resource.url) || '';
            var isPow  = url.indexOf('pow-actions.php') !== -1;

            if (!isPow) return _prevFetch.apply(this, arguments);

            // Parse action + visit_id from the request body
            var bodyStr = (options && options.body) || '';
            var bodyObj = null;
            try { bodyObj = typeof bodyStr === 'string' ? JSON.parse(bodyStr) : null; } catch (e) {}
            var action  = bodyObj && bodyObj.action;
            var visitId = bodyObj && (parseInt(bodyObj.visit_id, 10) || 0);

            return _prevFetch.apply(this, arguments).then(function (response) {
                if (!action || !visitId) return response;
                var clone = response.clone();
                clone.json().then(function (data) {
                    if (!data || !data.success) return;
                    if (action === 'start_visit') {
                        startVisitPings(visitId);
                    } else if (action === 'end_visit') {
                        stopVisitPings();
                        // Flush pings + photos now that we're (potentially) back online
                        flushVisitPings(visitId).catch(function () {});
                        flushPendingPhotos().catch(function () {});
                    }
                }).catch(function () {});
                return response;
            });
        };
    })();

    // Flush pending photos + any active-visit pings when connectivity returns
    window.addEventListener('online', function () {
        flushPendingPhotos().catch(function () {});
        if (_pingVisitId) flushVisitPings(_pingVisitId).catch(function () {});
    });

    // ── Utility ───────────────────────────────────────────────────────────────

    function clear() {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction([S_SCHEDULE, S_META], 'readwrite');
                tx.objectStore(S_SCHEDULE).clear();
                tx.objectStore(S_META).clear();
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }

    /**
     * Pull fresh schedule from the API and store in IDB.
     * token: JWT string (optional — falls back to MwAuth.getToken() if loaded).
     * Returns a Promise resolving to the number of days saved, or 0 on failure.
     */
    function pullFromServer(token) {
        var bearerToken = token ||
            (window.MwAuth && window.MwAuth.getToken ? window.MwAuth.getToken() : null);

        var headers = {};
        if (bearerToken) headers['Authorization'] = 'Bearer ' + bearerToken;

        return fetch('/crm/api/offline-schedule.php', { headers: headers })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok || !Array.isArray(data.days)) return 0;
                return saveScheduleDays(data.days)
                    .then(function () {
                        setMeta('last_sync', Date.now());
                        setMeta('user_id', data.user && data.user.id);
                        return data.days.length;
                    });
            })
            .catch(function () { return 0; });
    }

    // ── Public API ────────────────────────────────────────────────────────────

    window.MwOfflineDB = {
        // Schedule
        saveScheduleDays:   saveScheduleDays,
        getSchedule:        getSchedule,
        getAllSchedule:      getAllSchedule,
        pullFromServer:     pullFromServer,
        // Quiz
        saveQuizQuestions:  saveQuizQuestions,
        getQuizQuestions:   getQuizQuestions,
        saveQuizPreshift:   saveQuizPreshift,
        getQuizPreshift:    getQuizPreshift,
        getPendingQuizSyncs: getPendingQuizSyncs,
        // GPS visit pings
        startVisitPings:    startVisitPings,
        stopVisitPings:     stopVisitPings,
        flushVisitPings:    flushVisitPings,
        getVisitPingCount:  getVisitPingCount,
        // Offline photo queue
        queuePhoto:         queuePhoto,
        getPendingPhotos:   getPendingPhotos,
        flushPendingPhotos: flushPendingPhotos,
        // Meta / util
        setMeta:            setMeta,
        getMeta:            getMeta,
        clear:              clear,
    };

})();
