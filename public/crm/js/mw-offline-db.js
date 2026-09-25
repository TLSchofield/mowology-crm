/**
 * mw-offline-db.js — Mowology offline IndexedDB store
 *
 * Manages a local schedule cache so crew can view their jobs
 * when the server is unreachable (no connectivity, server outage).
 *
 * Database:  mw-offline  (version 1)
 * Stores:
 *   schedule  — keyPath: 'date' (YYYY-MM-DD), each record = { date, stops[], savedAt }
 *   meta      — keyPath: 'key', misc settings (last_sync, user_id, ...)
 *
 * Exposed as window.MwOfflineDB (no dependencies, loads synchronously).
 */
(function () {
    'use strict';

    var DB_NAME    = 'mw-offline';
    var DB_VERSION = 2;          // v2 adds quiz_questions + quiz_preshift stores
    var S_SCHEDULE = 'schedule';
    var S_META     = 'meta';
    var S_QUIZ_Q   = 'quiz_questions';
    var S_QUIZ_PS  = 'quiz_preshift';

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
                // v2 additions
                if (!db.objectStoreNames.contains(S_QUIZ_Q)) {
                    db.createObjectStore(S_QUIZ_Q, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(S_QUIZ_PS)) {
                    // keyPath = date string 'YYYY-MM-DD'; one record per day per user
                    db.createObjectStore(S_QUIZ_PS, { keyPath: 'date' });
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
        // Meta / util
        setMeta:            setMeta,
        getMeta:            getMeta,
        clear:              clear,
    };

})();
