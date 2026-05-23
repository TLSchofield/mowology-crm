/**
 * schedule-cache.js
 * IndexedDB-backed cache for prefetched schedule day data.
 * DB: mowology-schedule  Store: days  keyPath: date
 *
 * Used by schedule-prefetch.js to silently warm the next 7 days, and by
 * service-worker.js as a deep-offline fallback when both network and Cache
 * Storage miss for /crm/api/calendar-stops.php.
 *
 * API:
 *   ScheduleCache.save(date, stops, checksum)  →  Promise<void>
 *   ScheduleCache.load(date)                   →  Promise<{date, stops, checksum, cachedAt}|null>
 *   ScheduleCache.checksum(date)               →  Promise<string|null>
 *   ScheduleCache.evict(days = 14)             →  fire-and-forget
 */
const ScheduleCache = (() => {
    const DB_NAME    = 'mowology-schedule';
    const DB_VERSION = 1;
    const STORE      = 'days';

    function openDB() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = e => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'date' });
                }
            };
            req.onsuccess = e => resolve(e.target.result);
            req.onerror   = e => reject(e.target.error);
        });
    }

    async function save(date, stops, checksum) {
        const db = await openDB();
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put({ date, stops, checksum, cachedAt: Date.now() });
        return new Promise((res, rej) => { tx.oncomplete = res; tx.onerror = rej; });
    }

    async function load(date) {
        const db  = await openDB();
        const tx  = db.transaction(STORE, 'readonly');
        const req = tx.objectStore(STORE).get(date);
        return new Promise((res, rej) => {
            req.onsuccess = () => res(req.result || null);
            req.onerror   = () => rej(req.error);
        });
    }

    async function checksum(date) {
        const entry = await load(date);
        return entry ? entry.checksum : null;
    }

    // Remove entries older than `days` days
    async function evict(days = 14) {
        const cutoff = Date.now() - days * 86400 * 1000;
        const db     = await openDB();
        const tx     = db.transaction(STORE, 'readwrite');
        const store  = tx.objectStore(STORE);
        const req    = store.openCursor();
        req.onsuccess = e => {
            const cursor = e.target.result;
            if (!cursor) return;
            if (cursor.value.cachedAt < cutoff) cursor.delete();
            cursor.continue();
        };
    }

    return { save, load, checksum, evict };
})();
