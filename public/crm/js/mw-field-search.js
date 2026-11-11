/**
 * MwFieldSearch — the crew Search tab (search.php).
 *
 *   GET /crm/api/field-search.php?q=…            ranked property cards
 *   GET /crm/api/field-search.php?mode=nearby    before anything is typed
 *   GET /crm/api/field-search.php?mode=property  the property page (plans, history grid, upcoming)
 *   POST /crm/api/field-job.php  add_visit       "Add visit" on a plan
 *
 * Mirrors the iOS SearchView: Near you + Recent before typing, a debounced search while typing,
 * "couldn't search" is said out loud (an empty list would read as "no such client").
 */
(function () {
    'use strict';

    var root = document.getElementById('mwFieldSearch');
    if (!root) return;

    var input  = document.getElementById('mwFsInput');
    var clear  = document.getElementById('mwFsClear');
    var status = document.getElementById('mwFsStatus');
    var list   = document.getElementById('mwFsList');
    var detail = document.getElementById('mwFsDetail');
    var canAdd = root.getAttribute('data-can-add') === '1';
    var csrf   = root.getAttribute('data-csrf') || '';

    var API = '/crm/api/field-search.php';
    var RECENTS_KEY = 'mw_fs_recents_v1_' + (window.MW_USER_ID || '0');
    var fix = null, timer = null, seq = 0, nearby = [];

    // ── helpers ─────────────────────────────────────────────────────────────
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function metres(m) { return m == null ? '' : (m < 1000 ? m + ' m' : (m / 1000).toFixed(1) + ' km'); }
    function withFix(url) { return fix ? url + '&lat=' + fix.lat + '&lng=' + fix.lng : url; }
    function getJSON(url) {
        return fetch(url, { credentials: 'include', cache: 'no-store' }).then(function (r) {
            if (!r.ok) throw new Error('http ' + r.status);
            return r.json();
        });
    }
    function uuid() {
        return (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
            : 'r' + Date.now() + Math.random().toString(16).slice(2);
    }
    function recents() { try { return JSON.parse(localStorage.getItem(RECENTS_KEY) || '[]'); } catch (e) { return []; } }
    function remember(p) {
        try {
            var slim = { id: p.id, label: p.label, address: p.address, city: p.city, contact_name: p.contact_name,
                         last_done: p.last_done, on_today: false, distance_m: null, plans: [] };
            var next = [slim].concat(recents().filter(function (r) { return r.id !== p.id; })).slice(0, 8);
            localStorage.setItem(RECENTS_KEY, JSON.stringify(next));
        } catch (e) { /* private mode — recents are a convenience */ }
    }

    // ── rows ────────────────────────────────────────────────────────────────
    function row(p, showDistance) {
        var sub = p.label === p.address ? (p.city || '') : [p.address, p.city].filter(Boolean).join(', ');
        var client = p.contact_name && p.contact_name !== p.label ? p.contact_name : '';
        return '<button type="button" class="mw-fs-row" data-id="' + p.id + '">' +
            '<span class="mw-fs-row-main">' +
                '<span class="mw-fs-row-title">' + esc(p.label) +
                    (p.on_today ? ' <span class="mw-fs-today">TODAY</span>' : '') + '</span>' +
                (sub ? '<span class="mw-fs-row-sub">' + esc(sub) + '</span>' : '') +
                (client ? '<span class="mw-fs-row-meta">' + esc(client) + '</span>' : '') +
                '<span class="mw-fs-row-meta">' + esc(p.last_done || ((p.plans && p.plans.length) ? '' : 'No active plan')) + '</span>' +
            '</span>' +
            (showDistance && p.distance_m != null ? '<span class="mw-fs-row-dist">' + metres(p.distance_m) + '</span>' : '') +
        '</button>';
    }
    function section(title, items, showDistance, extra) {
        if (!items.length) return '';
        return '<div class="mw-fs-section"><div class="mw-fs-section-head"><span>' + esc(title) + '</span>' + (extra || '') + '</div>' +
            items.map(function (p) { return row(p, showDistance); }).join('') + '</div>';
    }

    function renderEmptyState() {
        status.textContent = '';
        var rec = recents();
        var html = section('Near you', nearby, true) +
                   section('Recent', rec, false, '<button type="button" class="mw-fs-linkbtn" id="mwFsClearRecents">Clear</button>');
        list.innerHTML = html || '<div class="mw-fs-hint">Find a client, address, building or phone number.</div>';
    }

    // ── search ──────────────────────────────────────────────────────────────
    function run(q) {
        var mine = ++seq;
        status.textContent = 'Searching…';
        getJSON(withFix(API + '?q=' + encodeURIComponent(q))).then(function (d) {
            if (mine !== seq) return;
            var results = (d && d.results) || [];
            if (!results.length) {
                status.textContent = '';
                list.innerHTML = '<div class="mw-fs-hint"><b>Nothing found for “' + esc(q) + '”.</b><br>Try part of the street name, the surname, or a phone number.</div>';
                return;
            }
            status.textContent = results.length + ' found — today\'s stops first, then nearest';
            list.innerHTML = section('Results', results, true);
        }).catch(function () {
            if (mine !== seq) return;
            status.textContent = '';
            list.innerHTML = '<div class="mw-fs-hint mw-fs-hint--warn">Couldn\'t search — check your signal and try again.</div>';
        });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        clear.hidden = q === '';
        if (q.length < 2) { seq++; renderEmptyState(); return; }
        timer = setTimeout(function () { run(q); }, 300);
    });
    clear.addEventListener('click', function () { input.value = ''; clear.hidden = true; seq++; renderEmptyState(); input.focus(); });

    // ── property page ───────────────────────────────────────────────────────
    function openProperty(id) {
        detail.hidden = false;
        detail.innerHTML = '<div class="mw-fs-hint">Loading…</div>';
        document.body.classList.add('mw-fs-detail-open');
        getJSON(withFix(API + '?mode=property&id=' + id)).then(function (d) {
            if (!d || !d.success) throw new Error('nope');
            remember(d.property);
            renderProperty(d.property);
        }).catch(function () {
            detail.innerHTML = head('Property') + '<div class="mw-fs-hint mw-fs-hint--warn">Couldn\'t load this property — check your signal.</div>';
        });
    }
    function head(title) {
        return '<div class="mw-fs-detail-head"><button type="button" class="mw-fs-back" id="mwFsBack" aria-label="Back">&#8249;</button>' +
               '<span>' + esc(title) + '</span></div>';
    }
    function renderProperty(p) {
        var addr  = [p.address, p.city].filter(Boolean).join(', ');
        var phone = String(p.phone || '').replace(/[^0-9+]/g, '');
        var nav   = (p.latitude != null && p.longitude != null)
            ? 'https://www.google.com/maps/dir/?api=1&destination=' + p.latitude + ',' + p.longitude
            : 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addr);

        var plans = (p.plans || []).map(function (pl) {
            return '<div class="mw-fs-plan">' +
                '<div class="mw-fs-plan-head"><span class="mw-fs-plan-title">' + esc(pl.title) + '</span>' +
                    (canAdd ? '<button type="button" class="mw-fs-addvisit" data-plan="' + pl.id + '" data-title="' + esc(pl.title) + '">' +
                        (pl.has_visit_today ? '+ Add another' : '+ Add visit') + '</button>' : '') +
                '</div>' + (pl.history_html || (pl.summary ? '<div class="mw-fs-row-meta">' + esc(pl.summary) + '</div>' : '')) +
            '</div>';
        }).join('');

        var upcoming = (p.upcoming || []).map(function (v) {
            return '<div class="mw-fs-up"><span>' + esc(v.plan_title) + '</span><span>' + esc(v.scheduled_date) + '</span></div>';
        }).join('');

        detail.innerHTML = head(p.label) +
            '<div class="mw-fs-card">' +
                '<div class="mw-fs-title">' + esc(p.label) + '</div>' +
                '<div class="mw-fs-row-sub">' + esc(addr) + '</div>' +
                (p.contact_name && p.contact_name !== p.label ? '<div class="mw-fs-row-meta">' + esc(p.contact_name) + '</div>' : '') +
                (p.distance_m != null ? '<div class="mw-fs-row-meta">' + metres(p.distance_m) + ' away</div>' : '') +
            '</div>' +
            '<div class="mw-fs-tiles">' +
                '<a class="mw-fs-tile" href="' + esc(nav) + '" target="_blank" rel="noopener">Navigate</a>' +
                (phone.length >= 7 ? '<a class="mw-fs-tile" href="tel:' + esc(phone) + '">Call</a>' : '<span class="mw-fs-tile mw-fs-tile--off">Call</span>') +
                (p.today_visit_id ? '<a class="mw-fs-tile" href="/crm/jobs/schedule.php">Today\'s visit</a>' : '<span class="mw-fs-tile mw-fs-tile--off">Not today</span>') +
            '</div>' +
            (p.notes ? '<div class="mw-fs-card"><div class="mw-fs-section-head"><span>Site notes</span></div><div class="mw-fs-notes">' + esc(p.notes) + '</div></div>' : '') +
            '<div class="mw-fs-card"><div class="mw-fs-section-head"><span>Plans</span></div>' +
                (plans || '<div class="mw-fs-row-meta">No active plan at this property.</div>') + '</div>' +
            (upcoming ? '<div class="mw-fs-card"><div class="mw-fs-section-head"><span>Coming up</span></div>' + upcoming + '</div>' : '') +
            '<div id="mwFsToast" class="mw-fs-toast" hidden></div>';
        detail.setAttribute('data-property', p.id);
    }
    function closeProperty() {
        detail.hidden = true;
        detail.innerHTML = '';
        document.body.classList.remove('mw-fs-detail-open');
        if (input.value.trim().length < 2) renderEmptyState();
    }

    function addVisit(btn) {
        var title = btn.getAttribute('data-title') || 'this plan';
        if (!window.confirm('Add a visit to “' + title + '” for today? It goes on your schedule.')) return;
        btn.disabled = true;
        fetch('/crm/api/field-job.php', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ action: 'add_visit', plan_id: parseInt(btn.getAttribute('data-plan'), 10), client_request_id: uuid(), csrf_token: csrf })
        }).then(function (r) { return r.json().catch(function () { return {}; }); }).then(function (d) {
            if (d && d.success) {
                var id = detail.getAttribute('data-property');
                openProperty(id);
                setTimeout(function () { toast('Added to today\'s schedule' + (d.visit_number ? ' — ' + d.visit_number : '')); }, 400);
            } else {
                btn.disabled = false;
                window.alert((d && d.error) || 'Couldn\'t add the visit. Try again.');
            }
        }).catch(function () {
            btn.disabled = false;
            window.alert('No connection — nothing was saved. Try again when you have signal.');
        });
    }
    function toast(msg) {
        var t = document.getElementById('mwFsToast');
        if (!t) return;
        t.textContent = msg; t.hidden = false;
        setTimeout(function () { t.hidden = true; }, 2600);
    }

    // ── events ──────────────────────────────────────────────────────────────
    root.addEventListener('click', function (e) {
        var r = e.target.closest('.mw-fs-row');
        if (r) { openProperty(r.getAttribute('data-id')); return; }
        if (e.target.closest('#mwFsBack')) { closeProperty(); return; }
        if (e.target.closest('#mwFsClearRecents')) { try { localStorage.removeItem(RECENTS_KEY); } catch (x) {} renderEmptyState(); return; }
        var add = e.target.closest('.mw-fs-addvisit');
        if (add) addVisit(add);
    });

    // ── start: Near you ─────────────────────────────────────────────────────
    renderEmptyState();
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (pos) {
            fix = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            getJSON(API + '?mode=nearby&lat=' + fix.lat + '&lng=' + fix.lng).then(function (d) {
                nearby = (d && d.results) || [];
                if (input.value.trim().length < 2) renderEmptyState();
            }).catch(function () { /* near-you is a convenience */ });
        }, function () { /* no location — search still works, just unranked by distance */ },
        { enableHighAccuracy: false, maximumAge: 120000, timeout: 8000 });
    }
}());
