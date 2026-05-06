/**
 * Schedule — Live Crew Overlay (System 3)
 *
 * Polls /crm/api/crew-location.php?action=live every 30 seconds.
 * For each non-completed stop on today's date, estimates ETA from the
 * nearest active crew member's GPS using haversine distance at 30 km/h.
 * Adds an ahead/behind badge to stop cards and highlights late stops.
 *
 * Admin/manager only — included conditionally by schedule.php.
 */
(function () {
    'use strict';

    if (typeof MW_WEEK_STOPS === 'undefined' || typeof MW_TODAY === 'undefined') return;

    var POLL_MS    = 30000;    // 30 seconds
    var STALE_SEC  = 600;      // ignore GPS pings older than 10 min
    var LATE_MIN   = 15;       // badge as "late" if ETA > scheduled + this many minutes
    var EARLY_MIN  = 10;       // badge as "early" if ETA < scheduled - this many minutes
    var KM_PER_MIN = 0.5;      // 30 km/h urban estimate

    var pollTimer = null;

    // ── Haversine distance in km ─────────────────────────────────────────────
    function haversineKm(lat1, lng1, lat2, lng2) {
        var R    = 6371;
        var toR  = Math.PI / 180;
        var dLat = (lat2 - lat1) * toR;
        var dLng = (lng2 - lng1) * toR;
        var a    = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                 + Math.cos(lat1 * toR) * Math.cos(lat2 * toR)
                 * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // ── Update or insert an ETA badge on a stop card ─────────────────────────
    function applyEtaBadge(card, diffMin) {
        var old = card.querySelector('.mw-crew-eta-badge');
        if (old) old.remove();

        card.classList.remove('mw-stop--running-late', 'mw-stop--early');

        var badge = document.createElement('span');
        badge.className = 'mw-crew-eta-badge';

        if (diffMin > LATE_MIN) {
            badge.textContent = '~' + diffMin + 'm late';
            badge.classList.add('mw-eta-late');
            card.classList.add('mw-stop--running-late');
        } else if (diffMin < -EARLY_MIN) {
            badge.textContent = '~' + Math.abs(diffMin) + 'm early';
            badge.classList.add('mw-eta-early');
            card.classList.add('mw-stop--early');
        } else {
            badge.textContent = 'On time';
            badge.classList.add('mw-eta-ontime');
        }

        // Inject into first child element of the card (the address/header row)
        var target = card.querySelector('.mw-stop-card-header, .mw-stop-service-row, :first-child');
        if (target) {
            target.appendChild(badge);
        } else {
            card.insertBefore(badge, card.firstChild);
        }
    }

    // ── Main poll handler ────────────────────────────────────────────────────
    function pollAndUpdate() {
        var todayStops = MW_WEEK_STOPS[MW_TODAY];
        if (!todayStops || todayStops.length === 0) return;

        fetch('/crm/api/crew-location.php?action=live', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !Array.isArray(data.crew) || data.crew.length === 0) return;

                // Filter to fresh GPS pings only
                var activeCrew = data.crew.filter(function (c) {
                    return c.lat && c.lng && c.seconds_ago < STALE_SEC;
                });
                if (activeCrew.length === 0) return;

                var nowSec = Math.floor(Date.now() / 1000);

                todayStops.forEach(function (stop) {
                    if (!stop.lat || !stop.lng) return;
                    if (stop.status === 'completed' || stop.status === 'skipped') return;
                    if (!stop.estimatedArrival) return;
                    // Only badge stops within a ±2 hour window of now
                    // (avoids "36h late" badges on past morning stops at end of day)
                    if (Math.abs(stop.estimatedArrival - nowSec) > 7200) return;

                    // Find the crew member assigned to this stop, or the closest one
                    var matched = activeCrew.find(function (c) {
                        return stop.crewId && (int(c.user_id) === stop.crewId);
                    });
                    if (!matched) {
                        // Fall back to closest active crew member
                        var bestDist = Infinity;
                        activeCrew.forEach(function (c) {
                            var d = haversineKm(c.lat, c.lng, stop.lat, stop.lng);
                            if (d < bestDist) { bestDist = d; matched = c; }
                        });
                    }
                    if (!matched) return;

                    var distKm = haversineKm(matched.lat, matched.lng, stop.lat, stop.lng);
                    var etaMin = Math.round(distKm / KM_PER_MIN);
                    var arrivalEpoch = stop.estimatedArrival; // Unix timestamp
                    var scheduledMin = Math.floor(arrivalEpoch / 60);
                    var expectedMin  = Math.floor(nowSec / 60) + etaMin;
                    var diffMin      = expectedMin - scheduledMin;

                    var card = document.querySelector(
                        '.mw-day-column[data-date="' + MW_TODAY + '"] .mw-stop-card[data-stop-id="' + stop.stopId + '"]'
                    );
                    if (card) applyEtaBadge(card, diffMin);
                });
            })
            .catch(function () { /* Silent — stale data acceptable */ });
    }

    // ── int helper (avoid parseInt ambiguity) ────────────────────────────────
    function int(v) { return v | 0; }

    // ── Lifecycle: pause when tab hidden, resume on focus ───────────────────
    function startPolling() {
        pollAndUpdate();
        pollTimer = setInterval(pollAndUpdate, POLL_MS);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopPolling();
        else startPolling();
    });

    // Start immediately — only if today is within the displayed week
    if (typeof MW_WEEK_STOPS[MW_TODAY] !== 'undefined') {
        startPolling();
    }
})();
