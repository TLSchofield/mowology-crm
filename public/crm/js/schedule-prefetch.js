/**
 * schedule-prefetch.js
 * Silently pre-downloads the next 7 days of schedule data so crew devices
 * have a warm IndexedDB cache when they drop offline mid-route.
 *
 * Flow:
 *   1. GET /api/schedule/freshness?start=YYYY-MM-DD&days=7
 *        → { days: [{ date, stop_count, checksum }, ...] }
 *   2. For each day whose checksum differs from what's cached locally:
 *        GET /crm/api/calendar-stops.php?date=YYYY-MM-DD
 *        → ScheduleCache.save(date, stops, checksum)
 *   3. Evict entries older than 14 days, stamp last-run.
 *
 * Rate-limited to once per 30 minutes. Best-effort — all errors swallowed.
 */
(async function schedulePrefetch() {
    if (!navigator.onLine) return;
    if (typeof ScheduleCache === 'undefined') return;

    const RATE_KEY = 'mw_sched_prefetch_ts';
    const lastRun  = parseInt(localStorage.getItem(RATE_KEY) || '0', 10);
    if (Date.now() - lastRun < 30 * 60 * 1000) return;

    const DAYS      = 7;
    const startDate = new Date();
    const startStr  = startDate.toISOString().slice(0, 10);

    try {
        const res = await fetch(`/api/schedule/freshness?start=${startStr}&days=${DAYS}`, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) return;
        const payload = await res.json();
        const days    = payload && payload.days;
        if (!Array.isArray(days)) return;

        for (const day of days) {
            if (!day || !day.date) continue;
            const cached = await ScheduleCache.checksum(day.date);
            if (cached === day.checksum) continue;

            const dayRes = await fetch(`/crm/api/calendar-stops.php?date=${day.date}`, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!dayRes.ok) continue;
            const dayData = await dayRes.json();
            if (dayData && dayData.success && Array.isArray(dayData.stops)) {
                await ScheduleCache.save(day.date, dayData.stops, day.checksum);
            }

            await new Promise(r => setTimeout(r, 300));
        }

        ScheduleCache.evict(14);
        localStorage.setItem(RATE_KEY, Date.now().toString());
    } catch (e) {
        // silent — prefetch is best-effort
    }
})();
