/**
 * "What is this plant?" on the crew schedule (Android app / phone browser), 2026-10-15.
 * Loaded on demand by schedule-pill-workflow.js. Three photos of one plant (whole, closer,
 * close-up) → uploaded as visit photos (category plant, internal) → /crm/api/plants.php
 * identify_visit (Pl@ntNet, the visit's property, bed from a fresh precise fix + the compass)
 * → the crew picks the name and the bed → confirm; approved care from the library is shown.
 * Reuses the crew page's .mw-obs-modal styles. window.MwPlantCrew.open(visitId, csrf).
 */
(function () {
    'use strict';
    var SHOTS = [['whole', 'Whole plant', 'Step back so the whole plant is in the photo. Stand still — this marks where it grows.'],
                 ['closer', 'Closer', 'Half the plant: stems, leaves, any flowers.'],
                 ['close', 'Close-up', 'One leaf or flower, sharp, filling the photo.']];
    var S = null;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function $(sel) { return S.modal.querySelector(sel); }

    function token() {
        return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); }).then(function (t) { if (t && t.token) S.csrf = t.token; return S.csrf; });
    }

    // POST JSON with the CSRF token; a stale token is refreshed and the call retried once (crew pages, 2026-09-24 trap).
    function post(body, retried) {
        body.csrf_token = S.csrf;
        return fetch('/crm/api/plants.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) {
                if (r.status === 403 && !retried) return r.json().then(function (d) {
                    if (d && /CSRF/i.test(d.error || '')) return token().then(function () { return post(body, true); });
                    return d;
                });
                return r.json();
            });
    }

    function shrink(file) {
        var MAX = 2048;
        if (!window.createImageBitmap || !document.createElement('canvas').toBlob) return Promise.resolve(file);
        return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
            var k = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
            if (k === 1 && file.size < 2500000) return file;
            var c = document.createElement('canvas');
            c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            return new Promise(function (res) { c.toBlob(function (b) { res(b ? new File([b], 'plant.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.88); });
        }).catch(function () { return file; });
    }

    function upload(file, retried) {
        return shrink(file).then(function (f) {
            var fd = new FormData();
            fd.append('files[]', f);
            fd.append('csrf_token', S.csrf);
            fd.append('context_type', 'job_visit');
            fd.append('context_id', String(S.visitId));
            fd.append('category', 'plant');
            fd.append('visibility', 'internal');
            if (S.fix) { fd.append('gps_lat', S.fix.lat); fd.append('gps_lng', S.fix.lng); fd.append('gps_accuracy', S.fix.accuracy); }
            return fetch('/crm/api/media-upload.php', { method: 'POST', credentials: 'same-origin', body: fd });
        }).then(function (r) {
            if (r.status === 403 && !retried) return token().then(function () { return upload(file, true); });
            return r.json().then(function (d) {
                var r0 = d.results && d.results[0];
                if (!r0 || !r0.success) throw new Error((r0 && r0.error) || d.error || 'Upload failed');
                return r0.media_id;
            });
        });
    }

    function startFix() {
        if (!navigator.geolocation) return;
        var t0 = Date.now();
        S.watch = navigator.geolocation.watchPosition(function (p) {
            var c = p.coords;
            if (!S.fix || c.accuracy < S.fix.accuracy) S.fix = { lat: c.latitude, lng: c.longitude, accuracy: Math.round(c.accuracy) };
            var w = S.modal && S.modal.querySelector('.mw-plant-where');
            if (w) w.textContent = 'Location within ' + S.fix.accuracy + ' m' + (S.fix.accuracy <= 15 ? '' : ' — hold on for a better fix…');
            if (S.fix.accuracy <= 8 || Date.now() - t0 > 15000) stopFix();
        }, function () {}, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
    }
    function stopFix() { if (S && S.watch != null) { navigator.geolocation.clearWatch(S.watch); S.watch = null; } }

    function startCompass() {
        S.onOri = function (e) {
            if (typeof e.webkitCompassHeading === 'number') S.heading = Math.round(e.webkitCompassHeading);
            else if (e.absolute && typeof e.alpha === 'number') S.heading = Math.round((360 - e.alpha) % 360);
        };
        try {
            if ('ondeviceorientationabsolute' in window) window.addEventListener('deviceorientationabsolute', S.onOri);
            else window.addEventListener('deviceorientation', S.onOri);
        } catch (e) { /* no compass: the point alone */ }
    }
    function stopCompass() {
        if (!S || !S.onOri) return;
        window.removeEventListener('deviceorientationabsolute', S.onOri);
        window.removeEventListener('deviceorientation', S.onOri);
    }

    function close() {
        stopFix(); stopCompass();
        if (S && S.modal) S.modal.remove();
        S = null;
    }

    function shell(title, body, footer) {
        S.modal.innerHTML = '<div class="mw-obs-modal-content"><div class="mw-obs-modal-header"><h3>' + esc(title) + '</h3>'
            + '<button class="mw-obs-modal-close" data-plant="close">&times;</button></div>'
            + '<div class="mw-obs-modal-body">' + body + '</div>'
            + (footer ? '<div class="mw-obs-modal-footer">' + footer + '</div>' : '') + '</div>';
    }

    function renderShots() {
        var n = Object.keys(S.files).length;
        shell('What is this plant?',
            '<p>Three photos of <b>one</b> plant.</p>'
            + SHOTS.map(function (s, i) {
                var done = !!S.files[s[0]];
                return '<div class="mw-obs-field"><label class="mw-obs-btn' + (done ? ' mw-obs-btn-submit' : '') + '">'
                    + (i + 1) + '. ' + esc(s[1]) + (done ? ' ✓' : '')
                    + '<input type="file" accept="image/jpeg,image/png" capture="environment" data-shot="' + s[0] + '" hidden></label>'
                    + '<div><small>' + esc(s[2]) + '</small></div></div>';
            }).join('')
            + '<div class="mw-obs-field"><small class="mw-plant-where">Finding where you are…</small></div>'
            + '<div class="mw-obs-error mw-plant-error" style="display:none;"></div>',
            '<button class="mw-obs-btn" data-plant="close">Cancel</button>'
            + '<button class="mw-obs-btn mw-obs-btn-submit" data-plant="identify"' + (n < 3 ? ' disabled' : '') + '>Identify</button>');
    }

    function fail(msg) {
        var e = $('.mw-plant-error');
        if (e) { e.textContent = msg; e.style.display = ''; }
        var b = $('[data-plant="identify"]');
        if (b) { b.disabled = false; b.textContent = 'Identify'; }
    }

    function identify() {
        var b = $('[data-plant="identify"]');
        b.disabled = true;
        var files = SHOTS.map(function (s) { return S.files[s[0]]; }).filter(Boolean);
        var ids = [];
        files.reduce(function (p, f, i) {
            return p.then(function () { b.textContent = 'Uploading ' + (i + 1) + ' of ' + files.length + '…'; return upload(f).then(function (id) { ids.push(id); }); });
        }, Promise.resolve()).then(function () {
            b.textContent = 'Asking Pl@ntNet…';
            return post({ mode: 'identify_visit', visit_id: S.visitId, media_ids: ids, lat: S.fix && S.fix.lat, lng: S.fix && S.fix.lng,
                          accuracy: S.fix && S.fix.accuracy, heading: S.wholeHeading != null ? S.wholeHeading : S.heading });
        }).then(function (r) {
            if (!r || !r.ok || !r.record) throw new Error((r && (r.message || r.error)) || 'Could not identify it');
            stopFix(); stopCompass();
            renderPick(r);
        }).catch(function (err) { fail(err.message || 'Something went wrong — try again.'); });
    }

    function renderPick(r) {
        var rec = r.record, m = rec.matches || [];
        var chosen = rec.scientific_name || (m[0] && m[0].scientific) || '';
        var bedId = rec.bed ? rec.bed.id : 0;
        S.record = rec;
        shell('Is this it?',
            '<p><b>' + esc(r.message || '') + '</b></p>'
            + m.map(function (x, i) {
                return '<div class="mw-obs-field"><label><input type="radio" name="mw-plant-pick" value="' + esc(x.scientific) + '"' + (x.scientific === chosen ? ' checked' : '') + '> '
                    + esc(x.common || x.scientific) + ' <i>' + esc(x.common ? x.scientific : '') + '</i> — ' + Math.round(x.score * 100) + '%</label></div>';
            }).join('')
            + '<div class="mw-obs-field"><label><input type="radio" name="mw-plant-pick" value=""' + (m.length && chosen ? '' : ' checked') + '> Something else:</label>'
            + '<input type="text" class="mw-plant-other" placeholder="Type the name"></div>'
            + '<div class="mw-obs-field"><label>Which bed?</label><select class="mw-plant-bed"><option value="0">No bed / not drawn yet</option>'
            + (r.beds || []).map(function (b) { return '<option value="' + b.id + '"' + (b.id === bedId ? ' selected' : '') + '>' + esc(b.name) + '</option>'; }).join('') + '</select>'
            + (rec.bed && !rec.bed.sure ? '<div><small>Probably ' + esc(rec.bed.name) + ' — from where you stood. Change it if not.</small></div>' : '') + '</div>'
            + '<div class="mw-obs-error mw-plant-error" style="display:none;"></div>',
            '<button class="mw-obs-btn" data-plant="close">Close</button><button class="mw-obs-btn mw-obs-btn-submit" data-plant="save">Save</button>');
    }

    function save() {
        var pick = S.modal.querySelector('input[name="mw-plant-pick"]:checked');
        var sci = pick ? pick.value : '';
        var other = ($('.mw-plant-other').value || '').trim();
        if (!sci && !other) { fail('Pick a match or type the name.'); return; }
        $('[data-plant="save"]').disabled = true;
        post({ mode: 'confirm', id: S.record.id, scientific_name: sci, common_name: sci ? '' : other, measurement_id: +$('.mw-plant-bed').value })
            .then(function (r) {
                if (!r || !r.ok) throw new Error((r && (r.message || r.error)) || 'Could not save it');
                renderDone(r);
            }).catch(function (err) { $('[data-plant="save"]').disabled = false; fail(err.message); });
    }

    function renderDone(r) {
        var rec = r.record || {}, c = r.care;
        var care = c && c.status === 'approved' && (c.tasks || []).length
            ? '<p><b>Care' + (c.match === 'genus' ? ' (general for this kind)' : '') + '</b></p><ul>' + c.tasks.map(function (t) {
                return '<li><b>' + esc(t.task) + '</b> — ' + esc(t.when) + (t.how ? '<br><small>' + esc(t.how) + '</small>' : '') + '</li>';
            }).join('') + '</ul>' + (c.toxic_pets ? '<p><b>Toxic to pets.</b> ' + esc(c.toxic_note || '') + '</p>' : '')
            : '<p><small>Its care card isn\'t in the library yet — the office will add it.</small></p>';
        shell('Saved', '<p><b>' + esc(rec.common_name || rec.scientific_name || 'Plant') + '</b>' + (rec.bed ? ' — ' + esc(rec.bed.name) : '') + '</p>' + care,
              '<button class="mw-obs-btn mw-obs-btn-submit" data-plant="close">Done</button>');
    }

    function open(visitId, csrf) {
        if (S) close();
        S = { visitId: +visitId, csrf: csrf || window.MW_CSRF_TOKEN || '', files: {}, fix: null, heading: null, wholeHeading: null };
        S.modal = document.createElement('div');
        S.modal.id = 'mw-plant-modal';
        S.modal.className = 'mw-obs-modal';
        document.body.appendChild(S.modal);
        renderShots();
        startFix();
        startCompass();
        if (!S.csrf) token();
        S.modal.addEventListener('click', function (e) {
            if (e.target === S.modal) { close(); return; }
            var a = e.target.closest('[data-plant]');
            if (!a) return;
            var what = a.getAttribute('data-plant');
            if (what === 'close') close();
            else if (what === 'identify') identify();
            else if (what === 'save') save();
        });
        S.modal.addEventListener('change', function (e) {
            var inp = e.target;
            if (!inp.matches || !inp.matches('input[data-shot]') || !inp.files || !inp.files[0]) return;
            var shot = inp.getAttribute('data-shot');
            S.files[shot] = inp.files[0];
            if (shot === 'whole') S.wholeHeading = S.heading;
            var fix = S.fix, where = $('.mw-plant-where') ? $('.mw-plant-where').textContent : '';
            renderShots();
            if (fix && $('.mw-plant-where')) $('.mw-plant-where').textContent = where;
        });
    }

    window.MwPlantCrew = { open: open };
})();
