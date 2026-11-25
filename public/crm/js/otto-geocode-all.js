/**
 * Find missing pins — the Territory Map's geocode-all tool (public/crm/map_appstack.php #mwGeoAll).
 *
 * Lists every active client property with no pin (/crm/api/otto-borders.php?mode=missing_pins), then
 * runs Google's Geocoder in the browser for each, one every 1.2 s (the client-side quota is ~50/s,
 * but a burst earns OVER_QUERY_LIMIT). Only a ROOFTOP or RANGE_INTERPOLATED, non-partial match in
 * Canada is saved (/crm/api/geocode-save.php — the same save as the Geocode buttons); anything
 * vaguer (GEOMETRIC_CENTER, APPROXIMATE, partial) is flagged "place it by hand" with a link.
 * Opens by itself when the page is loaded with ?geocode=<property id> (Otto's "no pin" item).
 */
(function () {
    'use strict';
    var GAP_MS = 1200;
    var GOOD = ['ROOFTOP', 'RANGE_INTERPOLATED'];
    var panel, list, status, runBtn, rows = [], running = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function geocoderReady() {
        return window.google && google.maps && typeof google.maps.Geocoder === 'function';
    }

    function waitForMaps(tries) {
        return new Promise(function (resolve, reject) {
            (function poll(n) {
                if (geocoderReady()) return resolve();
                if (n <= 0) return reject(new Error('Google Maps did not load.'));
                setTimeout(function () { poll(n - 1); }, 250);
            })(tries);
        });
    }

    function setRow(r, state, text, href) {
        r.el.className = 'is-' + state;
        r.el.querySelector('.mw-geo-all-res').innerHTML = esc(text) + (href ? ' <a href="' + esc(href) + '">Fix the address</a>' : '');
    }

    function open() {
        panel.hidden = false;
        if (rows.length || running) return;
        status.textContent = 'Loading…';
        fetch('/crm/api/otto-borders.php?mode=missing_pins', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (!r || !r.ok) { status.textContent = (r && r.error) || 'Could not load the list.'; return; }
                var focus = parseInt(panel.dataset.focus || '0', 10);
                var props = r.properties.slice().sort(function (a, b) { return (b.id === focus) - (a.id === focus); });
                if (!props.length) { status.textContent = 'Every active client property has a pin.'; return; }
                status.textContent = props.length + (props.length === 1 ? ' property has' : ' properties have') + ' no pin.';
                list.innerHTML = '';
                rows = props.map(function (p) {
                    var li = document.createElement('li');
                    li.innerHTML = '<span class="mw-geo-all-addr">' + esc(p.address) + '</span> <span class="mw-geo-all-res">waiting</span>';
                    if (p.id === focus) li.classList.add('is-focus');
                    list.appendChild(li);
                    return { p: p, el: li };
                });
                runBtn.disabled = false;
            })
            .catch(function () { status.textContent = 'No connection. Try again.'; });
    }

    function save(p, loc) {
        return fetch('/crm/api/geocode-save.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify({ property_id: p.id, lat: loc.lat(), lng: loc.lng(), csrf_token: window.MW_CSRF_TOKEN || '' })
        }).then(function (r) { return r.json(); });
    }

    function one(geocoder, r) {
        return new Promise(function (resolve) {
            var addr = r.p.geocode_address || r.p.address;
            if (!addr) { setRow(r, 'manual', 'No address to look up.', manualHref(r.p)); return resolve('manual'); }
            setRow(r, 'busy', 'looking up…');
            geocoder.geocode({ address: addr, componentRestrictions: { country: 'CA' } }, function (res, st) {
                if (st === 'OVER_QUERY_LIMIT') { setRow(r, 'wait', 'Google asked us to slow down — run again in a minute.'); return resolve('limit'); }
                if (st !== 'OK' || !res || !res.length) { setRow(r, 'manual', 'Google could not find it.', manualHref(r.p)); return resolve('manual'); }
                var g = res[0];
                var type = g.geometry && g.geometry.location_type;
                if (GOOD.indexOf(type) < 0 || g.partial_match) {
                    setRow(r, 'manual', 'Only a rough match (' + (g.partial_match ? 'partial, ' : '') + String(type || '?').toLowerCase().replace('_', ' ') + ': ' + g.formatted_address + ').', manualHref(r.p));
                    return resolve('manual');
                }
                save(r.p, g.geometry.location).then(function (s) {
                    if (s && s.success) { setRow(r, 'ok', 'Pinned — ' + g.formatted_address); resolve('ok'); }
                    else { setRow(r, 'manual', (s && s.error) || 'Could not save.', manualHref(r.p)); resolve('manual'); }
                }).catch(function () { setRow(r, 'manual', 'No connection while saving.', manualHref(r.p)); resolve('manual'); });
            });
        });
    }

    function manualHref(p) {
        return '/crm/properties/view.php?id=' + encodeURIComponent(p.id);
    }

    function run() {
        if (running) return;
        running = true;
        runBtn.disabled = true;
        status.textContent = 'Waiting for Google Maps…';
        waitForMaps(40).then(function () {
            var geocoder = new google.maps.Geocoder();
            var todo = rows.filter(function (r) { return !r.el.classList.contains('is-ok'); });
            var n = { ok: 0, manual: 0 };
            var i = 0;
            (function next() {
                if (i >= todo.length) {
                    running = false;
                    runBtn.disabled = false;
                    status.textContent = n.ok + ' pinned · ' + n.manual + ' to place by hand. Reload the map to see the new pins.';
                    return;
                }
                status.textContent = 'Looking up ' + (i + 1) + ' of ' + todo.length + '…';
                one(geocoder, todo[i++]).then(function (res) {
                    if (res === 'limit') { running = false; runBtn.disabled = false; status.textContent = 'Paused — Google rate limit. Run again in a minute.'; return; }
                    n[res === 'ok' ? 'ok' : 'manual']++;
                    setTimeout(next, GAP_MS);
                });
            })();
        }).catch(function (e) { running = false; runBtn.disabled = false; status.textContent = e.message; });
    }

    function init() {
        panel = document.getElementById('mwGeoAll');
        if (!panel) return;
        list = document.getElementById('mwGeoAllList');
        status = document.getElementById('mwGeoAllStatus');
        runBtn = document.getElementById('mwGeoAllRun');
        runBtn.addEventListener('click', run);
        document.getElementById('mwGeoAllClose').addEventListener('click', function () { panel.hidden = true; });
        var btn = document.getElementById('btn-geocode-all');
        if (btn) btn.addEventListener('click', open);
        if (parseInt(panel.dataset.focus || '0', 10) !== 0) open();   // ?geocode=<id> (Otto's item) or -1 (the review page)
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
