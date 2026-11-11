/**
 * Otto's equipment input — /crm/ops/equipment.php (Add equipment, Import a list) and
 * /crm/ops/equipment-item.php (find the purchase receipt, read the manual).
 *
 *   Read the plate   multipart → /crm/api/dispatch.php mode=plate_read → fills the confirm form
 *   Add to equipment JSON      → dispatch.php mode=add_equipment → opens the machine's page
 *   Receipts         JSON      → dispatch.php mode=receipt_candidates (by make/model/date/cost)
 *   Import a list    multipart → dispatch.php mode=import_preview, then import_commit
 *   Read the manual  multipart → /crm/api/label-products.php mode=manual_read (OttoManualService, daily cap);
 *                    each line → manual_confirm / manual_skip
 * Simple saves on these pages (log service, parts, link receipt, adopt intervals) are ops-dispatch.js
 * [data-row] rows. Server logic lives in EquipmentService / EquipmentImportService / EquipmentCatalogService.
 */
(function () {
    'use strict';
    var API = '/crm/api/dispatch.php';
    var LABEL_API = '/crm/api/label-products.php';
    var token = function () { return window.MW_CSRF_TOKEN || ''; };

    function say(el, text, bad) {
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('is-bad', !!bad);
    }
    function post(url, body, isForm) {
        var opts = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': token() } };
        if (isForm) { body.append('csrf_token', token()); opts.body = body; }
        else { body.csrf_token = token(); opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'The server did not answer properly (' + r.status + ').' }; });
        });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function money(v) { return v == null ? '' : '$' + Number(v).toFixed(2); }

    // ── Receipt candidates (both pages) ─────────────────────────────────────
    function showReceipts(box, list, input) {
        if (!box) return;
        if (!list || !list.length) { box.innerHTML = '<span class="mw-ops-hint">No matching receipts found. Type the receipt # if you know it.</span>'; return; }
        box.innerHTML = list.map(function (r) {
            return '<label class="d-block mb-1"><input type="radio" name="mw_eq_receipt" value="' + r.id + '"> #' + r.id + ' · ' + esc(r.date) + ' · ' +
                esc(r.vendor || 'receipt') + ' · ' + money(r.total) + ' <small class="text-muted">(' + esc(r.why) + ')</small></label>';
        }).join('');
        box.querySelectorAll('input[type=radio]').forEach(function (rb) {
            rb.addEventListener('change', function () { if (input) input.value = rb.value; });
        });
    }

    // ── Add equipment ──────────────────────────────────────────────────────
    var snap = document.querySelector('[data-eq-snap]');
    var form = document.querySelector('[data-eq-confirm]');
    function field(name) { return form ? form.querySelector('[name="' + name + '"]') : null; }
    function setField(name, v) {
        var el = field(name);
        if (!el || v == null || v === '') return;
        if (el.tagName === 'SELECT' && !el.querySelector('option[value="' + String(v).replace(/"/g, '') + '"]')) return;
        el.value = v;
    }
    function openForm() {
        if (!form) return;
        form.hidden = false;
        if (window.mwInitDatePickers) window.mwInitDatePickers(form);
    }

    if (snap && form) {
        snap.querySelector('[data-eq-manual]').addEventListener('click', function () { openForm(); field('name').focus(); });
        snap.querySelector('[data-eq-read]').addEventListener('click', function () {
            var b = this;
            var plate = snap.querySelector('[name=plate_photo]').files[0];
            var photo = snap.querySelector('[name=machine_photo]').files[0];
            var msg = snap.querySelector('[data-eq-snap-msg]');
            if (!plate) { say(msg, 'Choose or take a photo of the plate first.', true); return; }
            var fd = new FormData();
            fd.append('mode', 'plate_read');
            fd.append('plate_photo', plate);
            if (photo) fd.append('machine_photo', photo);
            b.disabled = true;
            say(msg, 'Reading the plate…');
            post(API, fd, true).then(function (r) {
                b.disabled = false;
                if (!r.ok) { say(msg, r.message || r.error || 'That did not work.', true); return; }
                say(msg, r.message, !!r.existing);
                var s = r.suggested || {};
                ['name', 'make', 'model', 'serial_no', 'equipment_class', 'power_source', 'model_name'].forEach(function (k) { setField(k, s[k]); });
                setField('plate_media_id', r.plate_media_id);
                setField('photo_media_id', r.photo_media_id);
                setField('label_capture_id', r.label_capture_id);
                var known = form.querySelector('[data-eq-known]');
                if (known) {
                    known.innerHTML = r.known_model
                        ? '<span class="mw-ops-hint">I know this model: ' + esc(r.known_model.name) + (r.known_model.intervals ? ' — the manual\'s ' + r.known_model.intervals + ' service interval(s) are on file.' : '.') + '</span>'
                        : '';
                }
                showReceipts(form.querySelector('[data-eq-receipts]'), r.receipts, field('expense_id'));
                openForm();
            }).catch(function () { b.disabled = false; say(msg, 'No connection. Try again.', true); });
        });

        form.querySelector('[data-eq-receipts-search]').addEventListener('click', function () {
            var box = form.querySelector('[data-eq-receipts]');
            say(box, 'Looking…');
            post(API, { mode: 'receipt_candidates', make: field('make').value, model: field('model').value,
                        date: field('purchase_date').value, cost: field('cost').value })
                .then(function (r) { showReceipts(box, r.receipts || [], field('expense_id')); });
        });

        form.querySelector('[data-eq-save]').addEventListener('click', function () {
            var b = this;
            var msg = form.querySelector('[data-eq-confirm-msg]');
            var body = { mode: 'add_equipment' };
            form.querySelectorAll('input[name], select[name]').forEach(function (el) {
                if (el.type === 'radio' || el.type === 'file') return;
                body[el.name] = el.value;
            });
            if (!body.name) { say(msg, 'Give it a name.', true); return; }
            b.disabled = true;
            post(API, body).then(function (r) {
                b.disabled = false;
                if (!r.ok) { say(msg, r.message || r.error || 'That did not save.', true); return; }
                say(msg, r.message);
                setTimeout(function () { location.href = r.url || '/crm/ops/equipment.php'; }, 900);
            }).catch(function () { b.disabled = false; say(msg, 'No connection. Try again.', true); });
        });
    }

    // ── Import a list ──────────────────────────────────────────────────────
    var imp = document.querySelector('[data-eq-import]');
    if (imp) {
        var rowsBox = document.querySelector('[data-eq-import-rows]');
        var commitBtn = imp.querySelector('[data-eq-import-commit]');
        var imsg = imp.querySelector('[data-eq-import-msg]');
        var run = function (mode, btn) {
            var f = imp.querySelector('[name=file]').files[0];
            if (!f) { say(imsg, 'Choose a .csv or .xlsx file.', true); return; }
            var fd = new FormData();
            fd.append('mode', mode);
            fd.append('file', f);
            btn.disabled = true;
            say(imsg, mode === 'import_preview' ? 'Reading…' : 'Adding…');
            post(API, fd, true).then(function (r) {
                btn.disabled = false;
                say(imsg, r.message || r.error || '', !r.ok);
                if (!r.ok) return;
                if (mode === 'import_commit') { setTimeout(function () { location.reload(); }, 900); return; }
                commitBtn.hidden = !r.ready;
                commitBtn.textContent = 'Add ' + r.ready + ' machine' + (r.ready === 1 ? '' : 's');
                rowsBox.innerHTML = '<table class="table table-sm mw-ops-table"><thead><tr><th>Line</th><th>Name</th><th>Make / model</th><th>Serial</th><th>Type</th><th>Bought</th><th>Cost</th><th></th></tr></thead><tbody>' +
                    r.rows.map(function (x) {
                        return '<tr class="' + (x.status === 'skip' ? 'mw-ops-retired' : '') + '"><td>' + x.line + '</td><td>' + esc(x.name) + '</td><td>' + esc((x.make + ' ' + x.model).trim()) +
                            '</td><td>' + esc(x.serial_no) + '</td><td>' + esc(x.equipment_class) + '</td><td>' + esc(x.purchase_date || '') + '</td><td>' + money(x.cost_manual) +
                            '</td><td>' + (x.status === 'skip' ? '<b>skip</b> ' : '') + (x.problems.length ? '<span class="mw-ops-flag">' + esc(x.problems.join('; ')) + '</span>' : '') + '</td></tr>';
                    }).join('') + '</tbody></table>';
            }).catch(function () { btn.disabled = false; say(imsg, 'No connection. Try again.', true); });
        };
        imp.querySelector('[data-eq-import-preview]').addEventListener('click', function () { run('import_preview', this); });
        commitBtn.addEventListener('click', function () { run('import_commit', this); });
    }

    // ── Machine page: find the purchase receipt ────────────────────────────
    document.querySelectorAll('[data-eq-receipt-find]').forEach(function (b) {
        b.addEventListener('click', function () {
            var row = b.closest('[data-row]');
            var box = row.querySelector('[data-eq-receipts]');
            say(box, 'Looking…');
            post(API, { mode: 'receipt_candidates', make: b.dataset.make, model: b.dataset.model, date: b.dataset.date, cost: b.dataset.cost })
                .then(function (r) { showReceipts(box, r.receipts || [], row.querySelector('[name=expense_id]')); });
        });
    });

    // ── Machine page: read the manual ──────────────────────────────────────
    var mr = document.querySelector('[data-eq-manual-read]');
    if (mr) {
        mr.querySelector('[data-eq-manual-go]').addEventListener('click', function () {
            var b = this;
            var msg = mr.querySelector('[data-eq-manual-msg]');
            var f = mr.querySelector('[name=manual]').files[0];
            if (!f) { say(msg, 'Choose the manual (PDF) or a photo of its maintenance page.', true); return; }
            var fd = new FormData();
            fd.append('mode', 'manual_read');
            fd.append('equipment_id', mr.dataset.equipment_id);
            fd.append('manual', f);
            b.disabled = true;
            say(msg, 'Reading — this can take a minute…');
            post(LABEL_API, fd, true).then(function (r) {
                b.disabled = false;
                say(msg, r.message || r.error || '', !r.ok);
                if (r.ok) setTimeout(function () { location.reload(); }, 1200);
            }).catch(function () { b.disabled = false; say(msg, 'No connection. Try again.', true); });
        });
    }
    document.querySelectorAll('[data-eq-manual-line]').forEach(function (tr) {
        var act = function (mode, btn) {
            var body = { mode: mode, read_id: +tr.dataset.read_id, index: +tr.dataset.index };
            if (mode === 'manual_confirm') {
                body.task = tr.querySelector('[name=task]').value;
                body.every_hours = tr.querySelector('[name=every_hours]').value || null;
                body.every_days = tr.querySelector('[name=every_days]').value || null;
            }
            btn.disabled = true;
            post(LABEL_API, body).then(function (r) {
                btn.disabled = false;
                if (r.ok) location.reload(); else alert(r.message || r.error || 'That did not save.');
            });
        };
        tr.querySelector('[data-eq-manual-confirm]').addEventListener('click', function () { act('manual_confirm', this); });
        tr.querySelector('[data-eq-manual-skip]').addEventListener('click', function () { act('manual_skip', this); });
    });
})();
