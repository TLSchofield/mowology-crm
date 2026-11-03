/**
 * Plant ID on the property page — "What is this?" (2026-10-15, PlantIdService / /crm/api/plants.php).
 *
 * Three photos of one plant (whole plant, closer, close-up), a fresh precise GPS fix and the compass
 * heading the camera faced → uploaded as property photos → one Pl@ntNet identification → pick the
 * name and the bed → saved on the property. Needs #mw-plants with data-property-id and data-csrf.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-plants');
    if (!root) return;
    var PID = +root.getAttribute('data-property-id');
    var CSRF = root.getAttribute('data-csrf') || '';
    var API = '/crm/api/plants.php';
    var SHOTS = [['whole', 'Whole plant'], ['closer', 'Closer'], ['close', 'Close-up (leaf or flower)']];
    var state = { beds: [], canEdit: false, files: {}, fix: null, heading: null };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function toast(msg, kind) { if (window.mwToast) window.mwToast(msg, kind); else window.alert(msg); }
    function q(sel) { return root.querySelector(sel); }
    function post(body) {
        body.csrf_token = CSRF;
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    // ── The list ────────────────────────────────────────────────────────────
    function load() {
        fetch(API + '?mode=list&property_id=' + PID, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { q('.mw-plants-list').innerHTML = '<p class="text-muted small mb-0">' + esc(d.error || 'Plant ID is not set up yet.') + '</p>'; return; }
                state.beds = d.beds || []; state.canEdit = !!d.can_edit; state.canLibrary = !!d.can_library;
                q('.mw-plants-attr').textContent = d.attribution || '';
                renderList(d.plants || []);
            })
            .catch(function () { q('.mw-plants-list').innerHTML = '<p class="text-muted small mb-0">Could not load plants.</p>'; });
    }

    function nameOf(p) {
        if (p.common_name || p.scientific_name) return '<b>' + esc(p.common_name || p.scientific_name) + '</b>' + (p.common_name && p.scientific_name ? ' <i class="text-muted">' + esc(p.scientific_name) + '</i>' : '');
        return '<span class="text-muted">Not identified</span>';
    }
    function bedOf(p) {
        if (!p.bed) return '<span class="text-muted">No bed</span>';
        return (p.bed.sure ? '' : 'probably ') + esc(p.bed.name);
    }

    // The plant's care from the library: approved jobs + months; else where its card stands.
    function careOf(p) {
        var c = p.care;
        if (!c || !p.scientific_name) return '';
        var weed = c.weed ? ' <span class="badge badge-warning">Weed</span>' + (c.invasive ? ' <span class="badge badge-danger" title="' + esc(c.invasive_note) + '">Invasive in BC</span>' : '') : '';
        if (c.status === 'approved' && c.weed) {
            return '<div class="small mt-1">' + weed + ' '
                + (c.tasks || []).map(function (t) { return '<span class="mr-2 text-nowrap" title="' + esc(t.how) + '"><b>' + esc(t.task) + '</b> ' + esc(t.when) + '</span>'; }).join('')
                + (c.safety ? '<div class="text-danger">Safety: ' + esc(c.safety) + '</div>' : '')
                + (c.disposal ? '<div class="text-muted">Disposal: ' + esc(c.disposal) + '</div>' : '') + '</div>';
        }
        if (c.status === 'approved') {
            return '<div class="small mt-1">' + (c.match === 'genus' ? '<span class="text-muted">General care for the genus · </span>' : '')
                + (c.tasks || []).map(function (t) { return '<span class="mr-2 text-nowrap" title="' + esc(t.how) + '"><b>' + esc(t.task) + '</b> ' + esc(t.when) + '</span>'; }).join('')
                + (c.toxic_pets ? ' <span class="badge badge-danger" title="' + esc(c.toxic_note) + '">toxic to pets</span>' : '')
                + ((c.propagation || []).length ? '<div class="text-muted">Propagation: ' + c.propagation.map(function (p) { return esc(p.method) + (p.when ? ' ' + esc(p.when) : ''); }).join(' · ')
                    + (c.propagation_note ? ' — ' + esc(c.propagation_note) : '') + '</div>' : '') + '</div>';
        }
        if (c.status === 'none') {
            return state.canLibrary && p.status === 'confirmed'
                ? '<div class="small mt-1"><button type="button" class="btn btn-sm btn-link p-0" data-care="' + p.id + '">Get its care from the web</button> <span class="text-muted">(not in the library yet, about a minute)</span></div>' : '';
        }
        if (weed) return '<div class="small mt-1">' + weed + ' <a href="/crm/plants/library.php">control card waiting for approval</a></div>';
        var what = c.status === 'draft' ? 'Care card waiting for approval' : (c.status === 'queued' || c.status === 'writing' ? 'Care card being written' : 'Care card failed');
        return '<div class="small mt-1"><a href="/crm/plants/library.php">' + what + '</a></div>';
    }

    function renderList(plants) {
        var box = q('.mw-plants-list');
        if (!plants.length) { box.innerHTML = '<p class="text-muted small mb-0">No plants recorded here yet. Tap <b>What is this?</b> and take three photos of one plant.</p>'; return; }
        box.innerHTML = '<table class="table table-sm mb-0"><tbody>' + plants.map(function (p) {
            var thumb = p.thumbs && p.thumbs[0] ? '<img src="' + esc(p.thumbs[0]) + '" alt="" width="44" height="44" class="rounded">' : '';
            var badge = p.status === 'confirmed' ? '' : ' <span class="badge badge-warning">' + (p.status === 'unsure' ? 'not sure' : 'check') + '</span>';
            var acts = state.canEdit ? (p.status !== 'confirmed' ? '<button type="button" class="btn btn-sm btn-outline-primary mr-1" data-check="' + p.id + '">Check</button>' : '')
                + (!p.bed ? '<a class="btn btn-sm btn-outline-secondary mr-1" href="/crm/products/area-measurement.php?property_id=' + PID + '&plant=' + p.id + '">Draw its bed</a>' : '')
                + '<button type="button" class="btn btn-sm btn-link text-muted" data-remove="' + p.id + '">Remove</button>' : '';
            acts = planButton(p) + acts;
            return '<tr><td width="52">' + thumb + '</td><td>' + nameOf(p) + badge + '<div class="small text-muted">' + bedOf(p) + ' · ' + esc(String(p.created_at || '').slice(0, 10)) + '</div>' + careOf(p) + '</td>'
                + '<td class="text-right text-nowrap">' + acts + '</td></tr>';
        }).join('') + '</tbody></table>';
        state.plants = plants;
    }

    root.addEventListener('click', function (e) {
        var t = e.target.closest('[data-check],[data-remove],[data-care],[data-plan]');
        if (!t) return;
        if (t.hasAttribute('data-plan')) { openPlan(+t.getAttribute('data-plan'), t.getAttribute('data-plan-id') ? +t.getAttribute('data-plan-id') : 0); return; }
        if (t.hasAttribute('data-care')) {
            var pl = (state.plants || []).filter(function (x) { return +x.id === +t.getAttribute('data-care'); })[0];
            if (!pl) return;
            t.disabled = true; t.textContent = 'Reading gardening sources…';
            post({ mode: 'care_now', scientific_name: pl.scientific_name, common_name: pl.common_name || '' })
                .then(function (r) { toast(r.message || 'Done', r.ok ? 'success' : 'error'); load(); });
            return;
        }
        if (t.hasAttribute('data-remove')) {
            if (!window.confirm('Remove this plant from the property?')) return;
            post({ mode: 'remove', id: +t.getAttribute('data-remove') }).then(function (r) { toast(r.message || 'Done', r.ok ? 'success' : 'error'); load(); });
        } else {
            var id = +t.getAttribute('data-check');
            var p = (state.plants || []).filter(function (x) { return +x.id === id; })[0];
            if (p) showResult(p, '');
        }
    });

    // ── Pruning plan (PruningPlanService, migration 1430; badges by photo-markers.js) ──────
    var PLAN_STATUS = { draft: 'Plan drafted', approved: 'Plan approved', scheduled: 'Plan scheduled', done: 'Pruned' };
    var plan = { current: null, types: {}, markers: null, dirty: {}, plantId: 0 };

    function planButton(p) {
        if (p.plan && p.plan.id) {
            return '<button type="button" class="btn btn-sm btn-outline-success mr-1" data-plan="' + p.id + '" data-plan-id="' + p.plan.id + '">' + esc(PLAN_STATUS[p.plan.status] || 'Plan') + '</button>';
        }
        if (state.canLibrary && p.scientific_name) {
            return '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" data-plan="' + p.id + '" title="Claude drafts a pruning plan from the photos (about a minute)">Pruning plan</button>';
        }
        return '';
    }

    function planPanel() {
        var el = q('.mw-plants-plan');
        if (!el) {
            el = document.createElement('div');
            el.className = 'mw-plants-plan border rounded p-3 mb-3';
            q('.mw-plants-result').insertAdjacentElement('afterend', el);
        }
        return el;
    }

    function openPlan(plantId, planId) {
        var panel = planPanel();
        panel.hidden = false;
        plan.plantId = plantId;
        if (!planId) { draftPlan(plantId); return; }
        panel.innerHTML = '<p class="small text-muted mb-0">Loading the plan…</p>';
        fetch(API + '?mode=pruning_get&id=' + planId, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok || !d.plan) { panel.innerHTML = '<p class="small text-muted mb-0">' + esc(d.error || 'No plan found.') + '</p>'; return; }
                plan.types = d.types || {}; renderPlan(d.plan);
            })
            .catch(function () { panel.innerHTML = '<p class="small text-muted mb-0">Could not load the plan.</p>'; });
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function draftPlan(plantId) {
        var panel = planPanel();
        panel.hidden = false;
        panel.innerHTML = '<p class="small mb-0"><span class="spinner-border spinner-border-sm mr-1"></span> Reading the photos and writing the plan — about a minute. Stay on this page.</p>';
        post({ mode: 'pruning_draft', plant_id: plantId })
            .then(function (d) {
                if (!d.ok) { panel.innerHTML = '<p class="small text-danger mb-0">' + esc(d.message || 'Could not draft a plan.') + '</p>'; return; }
                plan.types = d.types || {}; renderPlan(d.plan); load();
            })
            .catch(function () { panel.innerHTML = '<p class="small text-danger mb-0">The request did not come back. Reload the page: the plan may still have been saved.</p>'; });
    }

    function planAct(mode, extra) {
        var body = { mode: mode, id: plan.current.id };
        Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
        return post(body).then(function (d) {
            toast(d.message || (d.ok ? 'Done' : 'Failed'), d.ok ? 'success' : 'error');
            if (d.ok && d.plan) { renderPlan(d.plan); load(); }
            return d;
        });
    }

    function typeLabel(t) { return plan.types[t] || t; }
    function monthWindow(w) {
        var N = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return w.from === w.to ? N[w.from] : N[w.from] + '–' + N[w.to];
    }

    function renderPlan(row) {
        plan.current = row; plan.dirty = {};
        var panel = planPanel();
        var P = row.plan;
        if (!P) { panel.innerHTML = '<p class="small text-muted mb-0">This plan has no content.</p>'; return; }
        var photo0 = row.photos && row.photos[0];
        var canEdit = state.canEdit && (row.status === 'draft' || row.status === 'approved');
        var sp = P.species || {};
        var conf = sp.confidence != null ? Math.round(sp.confidence * 100) + '%' : '';
        var head = '<div class="d-flex align-items-start justify-content-between mb-2">'
            + '<div><h6 class="mb-0">Pruning plan — ' + esc(row.common_name || row.scientific_name) + ' <span class="badge badge-' + (row.status === 'approved' || row.status === 'scheduled' ? 'success' : (row.status === 'done' ? 'secondary' : 'warning')) + '">' + esc(row.status) + '</span></h6>'
            + '<div class="small text-muted">' + esc(sp.scientific || row.scientific_name) + (conf ? ' · species confidence ' + conf : '') + ' · drafted ' + esc(String(row.created_at).slice(0, 10)) + '</div>'
            + ((sp.if_wrong || []).map(function (w) { return '<div class="mw-prune-species-warn">If it is actually <b>' + esc(w.instead) + '</b>: ' + esc(w.what_changes) + '</div>'; }).join(''))
            + '</div><button type="button" class="btn btn-sm btn-link text-muted" data-plan-close>Close</button></div>';
        var legend = '<div class="mw-prune-legend">' + Object.keys(plan.types).map(function (t) { return '<span><span class="mw-pmark" data-type="' + esc(t) + '"></span>' + esc(plan.types[t]) + '</span>'; }).join('') + '</div>';
        var photoHtml = photo0 ? '<div class="mw-prune-photo mb-1"></div>' + (row.anchors_usable ? '' : '<div class="small text-muted mb-2">Markers hidden for this plan (positions were not reliable). Use the numbered list.</div>') : '<div class="small text-muted mb-2">No photo on file.</div>';
        var winHtml = '<div class="mw-prune-windows">'
            + '<div class="mw-prune-window"><b>Now · ' + esc(monthWindow(P.now)) + '</b>' + (P.now.actions.length ? 'Actions ' + P.now.actions.join(', ') : 'Nothing until the main window') + (P.now.note ? '<div class="text-muted">' + esc(P.now.note) + '</div>' : '') + '</div>'
            + '<div class="mw-prune-window"><b>Main prune · ' + esc(monthWindow(P.main)) + '</b>' + (P.main.actions.length ? 'Actions ' + P.main.actions.join(', ') : '') + (P.main.note ? '<div class="text-muted">' + esc(P.main.note) + '</div>' : '') + '</div></div>';
        var acts = '<ul class="mw-prune-actions mb-2">' + P.actions.map(function (a) {
            return '<li data-n="' + a.n + '"><span class="mw-pmark" data-type="' + esc(a.type) + '">' + a.n + '</span><span><b>' + esc(typeLabel(a.type)) + '</b> — ' + esc(a.what)
                + '<span class="mw-prune-why">' + esc(a.why) + (a.anchor && a.anchor.confidence === 0 ? ' · not placed on the photo' : '') + '</span></span></li>';
        }).join('') + '</ul>';
        var lists = (P.form_goal ? '<p class="small mb-1"><b>Goal:</b> ' + esc(P.form_goal) + '</p>' : '')
            + (P.multi_year.length ? '<p class="small mb-1"><b>Over the years:</b> ' + P.multi_year.map(function (y) { return 'Year ' + y.year + ': ' + esc(y.aim); }).join(' · ') + '</p>' : '')
            + (P.site_constraints.length ? '<p class="small mb-1"><b>Site:</b> ' + P.site_constraints.map(esc).join(' · ') + '</p>' : '')
            + (P.checks_on_site.length ? '<p class="small mb-1"><b>Check on site:</b> ' + P.checks_on_site.map(esc).join(' · ') + '</p>' : '')
            + '<div class="mw-prune-summary mb-2"><b>For the client:</b> ' + esc(P.client_summary) + '</div>'
            + '<p class="small mb-2"><b>Crew checklist:</b></p><ol class="small pl-3 mb-2">' + P.crew_checklist.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ol>';
        var btns = '<div class="mw-prune-btns">'
            + (canEdit && row.status === 'draft' ? '<button type="button" class="btn btn-sm btn-success mr-1" data-plan-approve>Approve</button>' : '')
            + (canEdit ? '<button type="button" class="btn btn-sm btn-primary mr-1" data-plan-save hidden>Save marker positions</button>' : '')
            + (canEdit && photo0 ? '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" data-plan-badges>' + (row.anchors_usable ? 'Hide markers' : 'Show markers') + '</button>' : '')
            + (state.canLibrary && row.status !== 'scheduled' && row.status !== 'done' ? '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" data-plan-redraft title="Costs one more call">Draft again</button>' : '')
            + (canEdit ? '<button type="button" class="btn btn-sm btn-link text-danger" data-plan-reject>Reject</button>' : '')
            + '</div>';
        panel.innerHTML = head + photoHtml + (photo0 ? legend : '') + winHtml + acts + lists + btns;
        if (photo0 && !window.MwPhotoMarkers) {
            q('.mw-prune-photo').innerHTML = '<img src="' + esc(photo0.url) + '" alt="Whole plant" class="img-fluid rounded">';
        }
        if (photo0 && window.MwPhotoMarkers) {
            var markers = row.anchors_usable ? P.actions.filter(function (a) { return !!a.anchor; }).map(function (a) {
                return { n: a.n, type: a.type, x: a.anchor.x, y: a.anchor.y, confidence: a.anchor.confidence, title: typeLabel(a.type) + ': ' + a.what };
            }) : [];
            plan.markers = MwPhotoMarkers.render(q('.mw-prune-photo'), {
                src: photo0.url, alt: 'Whole plant', markers: markers, draggable: canEdit,
                onSelect: function (n) { highlight(n); },
                onMove: function (n, x, y) { plan.dirty[n] = { n: n, x: x, y: y, photo: 0 }; var b = q('[data-plan-save]'); if (b) b.hidden = false; }
            });
        }
        panel.querySelectorAll('.mw-prune-actions li').forEach(function (li) {
            li.addEventListener('click', function () { var n = +li.getAttribute('data-n'); highlight(n); if (plan.markers) plan.markers.setActive(n); });
        });
        var saveBtn = q('[data-plan-save]');
        if (saveBtn) saveBtn.addEventListener('click', function () {
            var moved = Object.keys(plan.dirty).map(function (k) { return plan.dirty[k]; });
            if (!moved.length) return;
            saveBtn.disabled = true;
            planAct('pruning_anchors', { anchors: moved }).then(function () { saveBtn.disabled = false; });
        });
        var b;
        if ((b = q('[data-plan-approve]'))) b.addEventListener('click', function () { planAct('pruning_approve'); });
        if ((b = q('[data-plan-reject]'))) b.addEventListener('click', function () { if (window.confirm('Reject this plan? It stays on record but is no longer the plan for this plant.')) planAct('pruning_reject'); });
        if ((b = q('[data-plan-badges]'))) b.addEventListener('click', function () { planAct('pruning_badges', { usable: !row.anchors_usable }); });
        if ((b = q('[data-plan-redraft]'))) b.addEventListener('click', function () { if (window.confirm('Draft a new plan from the photos? The current one stays on record.')) draftPlan(row.plant_record_id); });
        if ((b = q('[data-plan-close]'))) b.addEventListener('click', function () { panel.hidden = true; });
    }

    function highlight(n) {
        var panel = planPanel();
        panel.querySelectorAll('.mw-prune-actions li').forEach(function (li) { li.classList.toggle('is-active', +li.getAttribute('data-n') === n); });
    }

    // ── Taking the photos ───────────────────────────────────────────────────
    function openTaker() {
        state.files = {}; state.fix = null; state.heading = null; state.wholeHeading = null;
        var panel = q('.mw-plants-take');
        panel.hidden = false;
        q('.mw-plants-result').hidden = true;
        panel.innerHTML = '<p class="small mb-2">Three photos of <b>one</b> plant. Stand still for the first one — it marks where the plant is.</p>'
            + '<div class="d-flex flex-wrap mb-2">' + SHOTS.map(function (s, i) {
                return '<label class="btn btn-outline-secondary mr-2 mb-2 mw-plants-shot" data-shot="' + s[0] + '">' + (i + 1) + '. ' + esc(s[1])
                    + '<input type="file" accept="image/jpeg,image/png" capture="environment" hidden></label>';
            }).join('') + '</div>'
            + '<div class="d-flex flex-wrap mb-2 mw-plants-thumbs"></div>'
            + '<div class="small text-muted mb-2 mw-plants-where">Finding where you are…</div>'
            + '<button type="button" class="btn btn-primary mw-plants-go" disabled>Identify</button> '
            + '<button type="button" class="btn btn-link mw-plants-cancel">Cancel</button>';
        startFix();
        startCompass();
    }

    // A fresh, precise fix: watch for up to 12 s and keep the best one (the first reading is often the rough one).
    function startFix() {
        var where = q('.mw-plants-where');
        if (!navigator.geolocation) { where.textContent = 'No location on this device — you will pick the bed.'; return; }
        var t0 = Date.now();
        var id = navigator.geolocation.watchPosition(function (pos) {
            var c = pos.coords;
            if (!state.fix || c.accuracy < state.fix.accuracy) state.fix = { lat: c.latitude, lng: c.longitude, accuracy: Math.round(c.accuracy) };
            where.textContent = 'Location within ' + state.fix.accuracy + ' m' + (state.fix.accuracy <= 15 ? ' — good.' : ' — waiting for a better fix…');
            if (state.fix.accuracy <= 8 || Date.now() - t0 > 12000) navigator.geolocation.clearWatch(id);
        }, function () { where.textContent = 'Location is off — you will pick the bed.'; }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
        setTimeout(function () { navigator.geolocation.clearWatch(id); }, 15000);
    }

    // Which way the camera faces: iPhone Safari gives webkitCompassHeading (asks first); Android Chrome gives an absolute alpha.
    function startCompass() {
        function onOri(e) {
            if (typeof e.webkitCompassHeading === 'number') state.heading = Math.round(e.webkitCompassHeading);
            else if (e.absolute && typeof e.alpha === 'number') state.heading = Math.round((360 - e.alpha) % 360);
        }
        try {
            if (window.DeviceOrientationEvent && typeof DeviceOrientationEvent.requestPermission === 'function') {
                DeviceOrientationEvent.requestPermission().then(function (s) { if (s === 'granted') window.addEventListener('deviceorientation', onOri); }).catch(function () {});
            } else if ('ondeviceorientationabsolute' in window) {
                window.addEventListener('deviceorientationabsolute', onOri);
            } else {
                window.addEventListener('deviceorientation', onOri);
            }
        } catch (e) { /* no compass: the bed comes from the point alone */ }
    }

    root.addEventListener('change', function (e) {
        var inp = e.target;
        var lab = inp.closest('.mw-plants-shot');
        if (!lab || !inp.files || !inp.files[0]) return;
        var shot = lab.getAttribute('data-shot');
        state.files[shot] = inp.files[0];
        if (shot === 'whole' && state.heading !== null) state.wholeHeading = state.heading;   // the heading when the whole-plant shot was taken
        lab.classList.remove('btn-outline-secondary'); lab.classList.add('btn-success');
        var th = q('.mw-plants-thumbs');
        th.innerHTML = SHOTS.filter(function (s) { return state.files[s[0]]; }).map(function (s) {
            return '<img src="' + URL.createObjectURL(state.files[s[0]]) + '" alt="' + esc(s[1]) + '" width="64" height="64" class="rounded mr-2 mb-2">';
        }).join('');
        q('.mw-plants-go').disabled = Object.keys(state.files).length < SHOTS.length;   // all three: whole, closer, close-up
    });

    // Phone photos are 12–24 MP; Pl@ntNet needs far less. Shrink to 2048 px JPEG first (fast upload, fast server).
    function shrink(file) {
        var MAX = 2048;
        if (!window.createImageBitmap || !document.createElement('canvas').toBlob) return Promise.resolve(file);
        return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
            var k = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
            if (k === 1 && file.size < 2500000) return file;
            var c = document.createElement('canvas');
            c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            return new Promise(function (res) {
                c.toBlob(function (b) { res(b ? new File([b], 'plant.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.88);
            });
        }).catch(function () { return file; });
    }

    function upload(original) {
        return shrink(original).then(send);
    }

    function send(file) {
        var fd = new FormData();
        fd.append('files[]', file);
        fd.append('csrf_token', CSRF);
        fd.append('context_type', 'property');
        fd.append('context_id', String(PID));
        fd.append('category', 'plant');
        if (state.fix) { fd.append('gps_lat', state.fix.lat); fd.append('gps_lng', state.fix.lng); fd.append('gps_accuracy', state.fix.accuracy); }
        return fetch('/crm/api/media-upload.php', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) { var r0 = d.results && d.results[0]; if (!r0 || !r0.success) throw new Error((r0 && r0.error) || d.error || 'Upload failed'); return r0.media_id; });
    }

    function identify() {
        var go = q('.mw-plants-go');
        go.disabled = true; go.textContent = 'Uploading…';
        var files = SHOTS.map(function (s) { return state.files[s[0]]; }).filter(Boolean);
        var ids = [];
        files.reduce(function (p, f, i) {
            return p.then(function () { go.textContent = 'Uploading photo ' + (i + 1) + ' of ' + files.length + '…'; return upload(f).then(function (id) { ids.push(id); }); });
        }, Promise.resolve())
            .then(function () {
                go.textContent = 'Asking Pl@ntNet…';
                var heading = state.wholeHeading != null ? state.wholeHeading : state.heading;
                return post({ mode: 'identify', property_id: PID, media_ids: ids, lat: state.fix && state.fix.lat, lng: state.fix && state.fix.lng,
                              accuracy: state.fix && state.fix.accuracy, heading: heading });
            })
            .then(function (r) {
                if (!r.ok) throw new Error(r.message || r.error || 'Could not identify');
                q('.mw-plants-take').hidden = true;
                showResult(r.record, r.message);
                load();
            })
            .catch(function (err) { toast(err.message || 'Something went wrong', 'error'); go.disabled = false; go.textContent = 'Identify'; });
    }

    // ── Pick the name and the bed ───────────────────────────────────────────
    function showResult(p, message) {
        var box = q('.mw-plants-result');
        box.hidden = false;
        var m = p.matches || [];
        var chosen = p.scientific_name || (m[0] && m[0].scientific) || '';
        var bedId = p.bed ? p.bed.id : 0;
        box.innerHTML = (message ? '<p class="mb-2"><b>' + esc(message) + '</b></p>' : '')
            + (m.length ? m.map(function (x, i) {
                return '<div class="custom-control custom-radio"><input type="radio" class="custom-control-input" name="mw-plant-pick" id="mw-pp' + i + '" value="' + esc(x.scientific) + '"' + (x.scientific === chosen ? ' checked' : '') + '>'
                    + '<label class="custom-control-label" for="mw-pp' + i + '">' + esc(x.common || x.scientific) + (x.common ? ' <i class="text-muted">' + esc(x.scientific) + '</i>' : '') + ' — ' + Math.round(x.score * 100) + '%</label></div>';
            }).join('') : '<p class="small text-muted">Pl@ntNet had no match.</p>')
            + '<div class="custom-control custom-radio mb-2"><input type="radio" class="custom-control-input" name="mw-plant-pick" id="mw-pp-other" value=""' + (m.length && chosen ? '' : ' checked') + '>'
            + '<label class="custom-control-label" for="mw-pp-other">Something else:</label> <input type="text" class="form-control form-control-sm d-inline-block w-auto mw-plants-other" placeholder="e.g. English lavender"></div>'
            + '<label class="small mb-1 d-block">Bed</label><select class="form-control form-control-sm w-auto mb-2 mw-plants-bed"><option value="0">No bed / not drawn yet</option>'
            + state.beds.map(function (b) { return '<option value="' + b.id + '"' + (b.id === bedId ? ' selected' : '') + '>' + esc(b.name) + '</option>'; }).join('') + '</select>'
            + (p.bed && !p.bed.sure ? '<div class="small text-muted mb-2">Probably ' + esc(p.bed.name) + ' — from where the photo was taken. Change it if not.</div>' : '')
            + (state.canEdit ? '<button type="button" class="btn btn-primary btn-sm mw-plants-save">Save</button> ' : '<p class="small text-muted">Ask someone who can edit clients to confirm it.</p>')
            + '<a class="btn btn-link btn-sm" href="/crm/products/area-measurement.php?property_id=' + PID + '&plant=' + p.id + '">Bed not drawn? Draw it</a>'
            + '<button type="button" class="btn btn-link btn-sm text-muted mw-plants-close">Close</button>';
        box.setAttribute('data-id', p.id);
    }

    root.addEventListener('click', function (e) {
        if (e.target.closest('.mw-plants-open')) { openTaker(); return; }
        if (e.target.closest('.mw-plants-cancel')) { q('.mw-plants-take').hidden = true; return; }
        if (e.target.closest('.mw-plants-go')) { identify(); return; }
        if (e.target.closest('.mw-plants-close')) { q('.mw-plants-result').hidden = true; return; }
        if (e.target.closest('.mw-plants-save')) {
            var box = q('.mw-plants-result');
            var pick = box.querySelector('input[name="mw-plant-pick"]:checked');
            var sci = pick ? pick.value : '';
            var other = box.querySelector('.mw-plants-other').value.trim();
            post({ mode: 'confirm', id: +box.getAttribute('data-id'), scientific_name: sci, common_name: sci ? '' : other,
                   measurement_id: +box.querySelector('.mw-plants-bed').value })
                .then(function (r) { toast(r.message || 'Saved', r.ok ? 'success' : 'error'); if (r.ok) { box.hidden = true; load(); } });
        }
    });

    load();
})();
