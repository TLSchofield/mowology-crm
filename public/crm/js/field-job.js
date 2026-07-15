/**
 * Field Job — crew "Add job / visit on the spot" overlay.
 * ───────────────────────────────────────────────────────
 * GPS-first, stepped, full-screen overlay for the crew mobile schedule.
 *
 * Smarter than a blank form: it reads the crew's GPS, detects the property
 * they're standing at, and branches —
 *   • property has an active plan + no visit today → one-tap "add today's visit"
 *   • property has no plan                          → quick "create job"
 *   • nothing nearby                                → search or new client
 *
 * Endpoints:
 *   GET  /crm/api/nearby-properties.php?lat&lng     — proximity detection
 *   GET  /crm/api/client-search.php?action=search   — manual fallback
 *   POST /crm/api/field-job.php                      — add_visit | create_job | create_client_job
 *
 * POSTs flow through offline-queue.js (field-job.php is a queued endpoint); a
 * body `client_request_id` makes them idempotent across offline replays.
 */
(function () {
    'use strict';

    var SERVICE_TYPES = [
        'Lawn Maintenance', 'Lawn Cut', 'Garden Care', 'Cleanup',
        'Hedge Trimming', 'Fertilizing', 'Power Raking', 'Aeration',
        'Snow Removal', 'Other'
    ];

    var state = {
        gps: null,           // {lat, lng, accuracy}
        nearby: [],          // results from nearby-properties.php
        selected: null,      // chosen property object
        newClient: null,     // {first_name,...} when creating a client
        busy: false
    };

    var el = null;          // overlay root
    var panel = null;       // inner scrollable panel

    // ── Helpers ──────────────────────────────────────────────────────────────
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function csrf() {
        var c = document.querySelector('.mw-mc-container');
        return (c && c.getAttribute('data-csrf')) || '';
    }
    function metres(m) {
        if (m == null) return '';
        return m < 1000 ? (m + ' m') : ((m / 1000).toFixed(1) + ' km');
    }

    // ── Overlay shell ────────────────────────────────────────────────────────
    function ensureEl() {
        if (el) return;
        el = document.createElement('div');
        el.className = 'mw-fj-overlay';
        el.innerHTML =
            '<div class="mw-fj-sheet" role="dialog" aria-modal="true" aria-label="Add a job">' +
                '<div class="mw-fj-head">' +
                    '<button type="button" class="mw-fj-close" aria-label="Close">&times;</button>' +
                    '<div class="mw-fj-title">Add a job</div>' +
                    '<div class="mw-fj-head-spacer"></div>' +
                '</div>' +
                '<div class="mw-fj-panel"></div>' +
            '</div>';
        document.body.appendChild(el);
        panel = el.querySelector('.mw-fj-panel');
        el.querySelector('.mw-fj-close').addEventListener('click', close);
        el.addEventListener('click', function (e) { if (e.target === el) close(); });
    }

    function open() {
        ensureEl();
        state.selected = null;
        state.newClient = null;
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        stepLocate();
    }
    function close() {
        if (el) el.classList.remove('show');
        document.body.style.overflow = '';
    }

    function render(html) { if (panel) { panel.innerHTML = html; panel.scrollTop = 0; } }

    // ── Step 1: Locate ───────────────────────────────────────────────────────
    function stepLocate() {
        render(
            '<div class="mw-fj-locating">' +
                '<div class="mw-fj-radar"><span></span><span></span><span></span></div>' +
                '<div class="mw-fj-locating-text">Finding where you are…</div>' +
                '<button type="button" class="mw-fj-link" id="fjSkipLocate">Skip — search instead</button>' +
            '</div>'
        );
        document.getElementById('fjSkipLocate').addEventListener('click', function () { stepPick(true); });
        getGps().then(function (gps) {
            state.gps = gps;
            return fetchNearby(gps);
        }).then(function (results) {
            state.nearby = results || [];
            stepPick();
        }).catch(function () {
            // GPS denied / offline / error — fall back to manual paths.
            stepPick();
        });
    }

    function getGps() {
        return new Promise(function (resolve, reject) {
            if (window.MwNative && window.MwNative.geo && window.MwNative.geo.getCurrentPosition) {
                window.MwNative.geo.getCurrentPosition().then(resolve).catch(reject);
                return;
            }
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function (p) { resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }); },
                    function (e) { reject(e); },
                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
                );
                return;
            }
            reject(new Error('no geolocation'));
        });
    }

    function fetchNearby(gps) {
        if (!gps) return Promise.resolve([]);
        var url = '/crm/api/nearby-properties.php?lat=' + encodeURIComponent(gps.lat) +
                  '&lng=' + encodeURIComponent(gps.lng) + '&radius=250';
        return fetch(url, { credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function (d) { return (d && d.success) ? d.results : []; })
            .catch(function () { return []; });
    }

    // ── Step 2: Pick property — search + new client on top, list below ───────
    function stepPick(focusSearch) {
        var offline = !navigatorOnline();
        render(
            '<div class="mw-fj-pick-top">' +
                '<input type="search" class="mw-fj-input mw-fj-search" id="fjSearchInput" placeholder="Search a client by name…" autocomplete="off">' +
                '<button type="button" class="mw-fj-alt mw-fj-newbtn" id="fjNew">＋ New</button>' +
            '</div>' +
            '<div class="mw-fj-results" id="fjResults"></div>'
        );

        var input = document.getElementById('fjSearchInput');
        var box   = document.getElementById('fjResults');
        var t = null;

        function showNearby() {
            var h = '';
            if (state.nearby.length) {
                h += '<div class="mw-fj-section-label">You\'re near</div><div class="mw-fj-list">';
                state.nearby.forEach(function (p, i) { h += propCard(p, i); });
                h += '</div>';
            } else if (offline) {
                h += '<div class="mw-fj-note">📡 You\'re offline — nearby search needs a signal. Add a new client above; it\'ll sync when you\'re back online.</div>';
            } else {
                h += '<div class="mw-fj-note">No saved properties near you — search above, or add a new client.</div>';
            }
            box.innerHTML = h;
            bindNearby(box);
        }

        input.addEventListener('input', function () {
            clearTimeout(t);
            var q = input.value.trim();
            if (q.length < 1) { showNearby(); return; }
            t = setTimeout(function () { runSearch(q, box); }, 220);
        });
        document.getElementById('fjNew').addEventListener('click', stepNewClient);

        showNearby();
        // Open the keyboard ready for typing when there's nothing to tap nearby
        // (or when the crew explicitly skipped GPS to search).
        if (focusSearch || (!state.nearby.length && !offline)) {
            try { input.focus(); } catch (e) { /* ignore */ }
        }
    }

    function propCard(p, i) {
        var badge = p.has_plan
            ? (p.has_visit_today
                ? '<span class="mw-fj-pill mw-fj-pill-muted">On today\'s schedule</span>'
                : '<span class="mw-fj-pill mw-fj-pill-go">Plan active</span>')
            : '<span class="mw-fj-pill">New job</span>';
        return '<button type="button" class="mw-fj-prop' + (i === 0 ? ' mw-fj-prop-near' : '') + '" data-idx="' + i + '">' +
            '<div class="mw-fj-prop-main">' +
                '<div class="mw-fj-prop-addr">' + (i === 0 ? '📍 ' : '') + esc(p.address) + '</div>' +
                '<div class="mw-fj-prop-sub">' +
                    (p.contact_name ? esc(p.contact_name) + ' · ' : '') +
                    '<span class="mw-fj-dist">' + metres(p.distance_m) + '</span>' +
                '</div>' +
            '</div>' + badge + '</button>';
    }

    function bindNearby(box) {
        Array.prototype.forEach.call(box.querySelectorAll('.mw-fj-prop[data-idx]'), function (btn) {
            btn.addEventListener('click', function () {
                state.selected = state.nearby[parseInt(btn.getAttribute('data-idx'), 10)];
                stepBranch();
            });
        });
    }

    function navigatorOnline() {
        if (window.MwNative && typeof window.MwNative.network === 'object') {
            return window.MwNative.network.isOnline !== false;
        }
        return navigator.onLine !== false;
    }

    function runSearch(q, box) {
        fetch('/crm/api/client-search.php?action=search&type=contact&q=' + encodeURIComponent(q), { credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var rows = (d && d.results) || [];
                if (!rows.length) { box.innerHTML = '<div class="mw-fj-note">No matches.</div>'; return; }
                box.innerHTML = rows.map(function (r) {
                    return '<button type="button" class="mw-fj-prop" data-cid="' + r.id + '">' +
                        '<div class="mw-fj-prop-main"><div class="mw-fj-prop-addr">' + esc(r.label) + '</div>' +
                        '<div class="mw-fj-prop-sub">' + esc(r.sublabel || '') +
                        (r.property_count ? ' · ' + r.property_count + ' propert' + (r.property_count === 1 ? 'y' : 'ies') : '') +
                        '</div></div><span class="mw-fj-pill">›</span></button>';
                }).join('');
                Array.prototype.forEach.call(box.querySelectorAll('.mw-fj-prop'), function (btn) {
                    btn.addEventListener('click', function () { pickContactProperties(parseInt(btn.getAttribute('data-cid'), 10)); });
                });
            })
            .catch(function () { box.innerHTML = '<div class="mw-fj-note">Search failed.</div>'; });
    }

    function pickContactProperties(contactId) {
        fetch('/crm/api/client-search.php?action=properties&contact_id=' + contactId, { credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var props = (d && d.properties) || [];
                if (!props.length) { render('<div class="mw-fj-note">This client has no properties yet.</div><div class="mw-fj-altrow"><button type="button" class="mw-fj-alt" id="fjBackSearch">‹ Back</button></div>'); document.getElementById('fjBackSearch').addEventListener('click', function () { stepPick(true); }); return; }
                if (props.length === 1) { state.selected = normaliseProp(props[0]); stepBranch(); return; }
                render('<div class="mw-fj-section-label">Which property?</div><div class="mw-fj-list">' +
                    props.map(function (p, i) {
                        return '<button type="button" class="mw-fj-prop" data-idx="' + i + '"><div class="mw-fj-prop-main">' +
                            '<div class="mw-fj-prop-addr">' + esc(p.address) + '</div>' +
                            '<div class="mw-fj-prop-sub">' + esc(p.city || '') + '</div></div><span class="mw-fj-pill">›</span></button>';
                    }).join('') + '</div>');
                Array.prototype.forEach.call(panel.querySelectorAll('.mw-fj-prop'), function (btn) {
                    btn.addEventListener('click', function () { state.selected = normaliseProp(props[parseInt(btn.getAttribute('data-idx'), 10)]); stepBranch(); });
                });
            });
    }
    function normaliseProp(p) {
        return { id: p.id, address: p.address, city: p.city, has_plan: false, has_visit_today: false, plan_id: null, plan_title: null };
    }

    // ── Step 3: Branch ───────────────────────────────────────────────────────
    function stepBranch() {
        var p = state.selected;
        if (!p) { stepPick(); return; }

        if (p.has_plan && p.has_visit_today) {
            render(
                '<div class="mw-fj-branch">' +
                    '<div class="mw-fj-branch-addr">' + esc(p.address) + '</div>' +
                    '<div class="mw-fj-note">✅ This property is already on today\'s schedule for <strong>' + esc(p.plan_title || 'a plan') + '</strong>.</div>' +
                    '<button type="button" class="mw-fj-primary" id="fjAddAnother">Add another visit anyway</button>' +
                    '<button type="button" class="mw-fj-alt mw-fj-full" id="fjBackPick2">‹ Pick a different property</button>' +
                '</div>'
            );
            document.getElementById('fjAddAnother').addEventListener('click', function () { submitAddVisit(p.plan_id); });
            document.getElementById('fjBackPick2').addEventListener('click', stepPick);
            return;
        }

        if (p.has_plan) {
            render(
                '<div class="mw-fj-branch">' +
                    '<div class="mw-fj-branch-addr">' + esc(p.address) + '</div>' +
                    '<button type="button" class="mw-fj-primary" id="fjAddVisit">＋ Add today\'s visit to “' + esc(p.plan_title || 'plan') + '”</button>' +
                    '<div class="mw-fj-or">or</div>' +
                    '<button type="button" class="mw-fj-alt mw-fj-full" id="fjNewJobHere">Create a separate new job</button>' +
                    '<button type="button" class="mw-fj-alt mw-fj-full" id="fjBackPick3">‹ Pick a different property</button>' +
                '</div>'
            );
            document.getElementById('fjAddVisit').addEventListener('click', function () { submitAddVisit(p.plan_id); });
            document.getElementById('fjNewJobHere').addEventListener('click', stepJobForm);
            document.getElementById('fjBackPick3').addEventListener('click', stepPick);
            return;
        }

        // No plan — straight to the create-job form.
        stepJobForm();
    }

    // ── Step 3b: New client form ─────────────────────────────────────────────
    function stepNewClient() {
        var addr = '';
        render(
            '<div class="mw-fj-section-label">New client</div>' +
            '<div class="mw-fj-grid2">' +
                '<input type="text" class="mw-fj-input" id="fjFirst" placeholder="First name" autocomplete="off">' +
                '<input type="text" class="mw-fj-input" id="fjLast" placeholder="Last name" autocomplete="off">' +
            '</div>' +
            '<input type="tel" class="mw-fj-input" id="fjPhone" placeholder="Phone (optional)" autocomplete="off">' +
            '<input type="text" class="mw-fj-input" id="fjAddr" placeholder="Property address" value="' + esc(addr) + '" autocomplete="off">' +
            '<div class="mw-fj-hint" id="fjGeoHint"></div>' +
            '<div class="mw-fj-altrow">' +
                '<button type="button" class="mw-fj-alt" id="fjBackPick4">‹ Back</button>' +
                '<button type="button" class="mw-fj-primary mw-fj-inline" id="fjNewNext">Next ›</button>' +
            '</div>'
        );
        document.getElementById('fjBackPick4').addEventListener('click', stepPick);
        // Reverse-geocode the GPS fix to prefill the address (best-effort).
        if (state.gps) reverseGeocode(state.gps, document.getElementById('fjAddr'), document.getElementById('fjGeoHint'));
        document.getElementById('fjNewNext').addEventListener('click', function () {
            var first = document.getElementById('fjFirst').value.trim();
            var addrV = document.getElementById('fjAddr').value.trim();
            if (!first) { document.getElementById('fjFirst').focus(); return; }
            if (!addrV) { document.getElementById('fjAddr').focus(); return; }
            state.newClient = {
                first_name: first,
                last_name: document.getElementById('fjLast').value.trim(),
                phone: document.getElementById('fjPhone').value.trim(),
                property_address: addrV
            };
            state.selected = null; // signal new-client path
            stepJobForm();
        });
    }

    function reverseGeocode(gps, addrInput, hint) {
        if (!(window.google && google.maps && google.maps.Geocoder)) return;
        try {
            new google.maps.Geocoder().geocode({ location: { lat: gps.lat, lng: gps.lng } }, function (res, status) {
                if (status === 'OK' && res && res[0] && !addrInput.value) {
                    addrInput.value = res[0].formatted_address;
                    if (hint) hint.textContent = '📍 Prefilled from your location — edit if needed';
                }
            });
        } catch (e) { /* ignore */ }
    }

    // ── Step 4: Job details ──────────────────────────────────────────────────
    function stepJobForm() {
        var heading = state.newClient
            ? esc(state.newClient.property_address)
            : (state.selected ? esc(state.selected.address) : 'New job');
        render(
            '<div class="mw-fj-branch-addr">' + heading + '</div>' +
            '<div class="mw-fj-section-label">Service</div>' +
            '<div class="mw-fj-chips" id="fjServices">' +
                SERVICE_TYPES.map(function (s, i) {
                    return '<button type="button" class="mw-fj-chip' + (i === 0 ? ' on' : '') + '" data-svc="' + esc(s) + '">' + esc(s) + '</button>';
                }).join('') +
            '</div>' +
            '<div class="mw-fj-section-label">How often</div>' +
            '<div class="mw-fj-seg" id="fjFreqSeg">' +
                '<button type="button" class="mw-fj-seg-btn on" data-mode="once">One-off (today)</button>' +
                '<button type="button" class="mw-fj-seg-btn" data-mode="recurring">Recurring</button>' +
            '</div>' +
            '<div class="mw-fj-freqwrap" id="fjFreqWrap" style="display:none">' +
                '<div class="mw-fj-seg mw-fj-seg-sm" id="fjFreqPick">' +
                    '<button type="button" class="mw-fj-seg-btn on" data-freq="weekly">Weekly</button>' +
                    '<button type="button" class="mw-fj-seg-btn" data-freq="biweekly">Bi-weekly</button>' +
                    '<button type="button" class="mw-fj-seg-btn" data-freq="monthly">Monthly</button>' +
                '</div>' +
            '</div>' +
            '<div class="mw-fj-section-label">Price per visit</div>' +
            '<div class="mw-fj-pricewrap">' +
                '<span class="mw-fj-price-cur">$</span>' +
                '<input type="number" inputmode="decimal" class="mw-fj-input mw-fj-price" id="fjPrice" placeholder="0.00" min="0" step="0.01">' +
            '</div>' +
            '<div class="mw-fj-hint">Pre-tax. Leave blank if the office will price it.</div>' +
            '<textarea class="mw-fj-input mw-fj-textarea" id="fjNotes" placeholder="Notes / instructions (optional)"></textarea>' +
            '<button type="button" class="mw-fj-primary mw-fj-full" id="fjSave">Save job</button>' +
            '<button type="button" class="mw-fj-alt mw-fj-full" id="fjBackBranch">‹ Back</button>'
        );

        var svc = SERVICE_TYPES[0];
        var mode = 'once', freq = 'weekly';

        bindGroup('fjServices', '.mw-fj-chip', 'svc', function (v) { svc = v; });
        bindGroup('fjFreqSeg', '.mw-fj-seg-btn', 'mode', function (v) {
            mode = v;
            document.getElementById('fjFreqWrap').style.display = (v === 'recurring') ? 'block' : 'none';
        });
        bindGroup('fjFreqPick', '.mw-fj-seg-btn', 'freq', function (v) { freq = v; });

        document.getElementById('fjBackBranch').addEventListener('click', function () {
            if (state.newClient) stepNewClient(); else stepBranch();
        });
        document.getElementById('fjSave').addEventListener('click', function () {
            var priceRaw = document.getElementById('fjPrice').value.trim();
            submitJob({
                service_type: svc,
                recurring: mode === 'recurring' ? 1 : 0,
                frequency: freq,
                price: priceRaw === '' ? '' : priceRaw,
                notes: document.getElementById('fjNotes').value.trim()
            });
        });
    }

    function bindGroup(containerId, sel, attr, cb) {
        var c = document.getElementById(containerId);
        if (!c) return;
        Array.prototype.forEach.call(c.querySelectorAll(sel), function (btn) {
            btn.addEventListener('click', function () {
                Array.prototype.forEach.call(c.querySelectorAll(sel), function (b) { b.classList.remove('on'); });
                btn.classList.add('on');
                cb(btn.getAttribute('data-' + attr));
            });
        });
    }

    // ── Submit ───────────────────────────────────────────────────────────────
    function post(body) {
        body.csrf_token = csrf();
        body.client_request_id = uuid();
        return fetch('/crm/api/field-job.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    function submitAddVisit(planId) {
        if (state.busy) return; state.busy = true;
        showSaving('Adding your visit…');
        post({ action: 'add_visit', plan_id: planId }).then(function (res) {
            state.busy = false;
            if (res.data && res.data.success) showDone('Visit added', res.data.queued);
            else showError((res.data && res.data.error) || 'Could not add the visit.');
        }).catch(function () { state.busy = false; showError('Network error.'); });
    }

    function submitJob(job) {
        if (state.busy) return; state.busy = true;
        showSaving('Saving job…');
        var body;
        if (state.newClient) {
            body = Object.assign({ action: 'create_client_job' }, state.newClient, job);
            if (state.gps) { body.lat = state.gps.lat; body.lng = state.gps.lng; }
        } else {
            body = Object.assign({ action: 'create_job', property_id: state.selected.id }, job);
        }
        post(body).then(function (res) {
            state.busy = false;
            if (res.data && res.data.success) showDone('Job created', res.data.queued);
            else showError((res.data && res.data.error) || 'Could not save the job.');
        }).catch(function () { state.busy = false; showError('Network error.'); });
    }

    // ── Result screens ───────────────────────────────────────────────────────
    function showSaving(msg) {
        render('<div class="mw-fj-locating"><div class="mw-fj-radar"><span></span><span></span><span></span></div>' +
               '<div class="mw-fj-locating-text">' + esc(msg) + '</div></div>');
    }
    function showDone(msg, queued) {
        render('<div class="mw-fj-result"><div class="mw-fj-result-check">✓</div>' +
            '<div class="mw-fj-result-title">' + esc(msg) + '</div>' +
            (queued ? '<div class="mw-fj-note">📡 Saved offline — it\'ll sync when you\'re back online.</div>' : '') +
            '<button type="button" class="mw-fj-primary mw-fj-full" id="fjDone">Done</button></div>');
        document.getElementById('fjDone').addEventListener('click', function () {
            close();
            // Reload so the new stop/visit appears on today's schedule.
            window.location.reload();
        });
    }
    function showError(msg) {
        render('<div class="mw-fj-result"><div class="mw-fj-result-x">!</div>' +
            '<div class="mw-fj-result-title">' + esc(msg) + '</div>' +
            '<button type="button" class="mw-fj-primary mw-fj-full" id="fjRetry">Back</button></div>');
        document.getElementById('fjRetry').addEventListener('click', function () {
            if (state.newClient) stepJobForm();
            else if (state.selected) stepBranch();
            else stepPick();
        });
    }

    window.MwFieldJob = { open: open, close: close };
})();
