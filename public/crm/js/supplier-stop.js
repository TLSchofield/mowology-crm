/**
 * MwSupplierStop — Otto's supplier stop on a receipt (expense modal on web + the Android app).
 *
 * Shows the stop the receipt came from on the crew's GPS ("LAWNBOY Oct 7 11:27–11:34 · Nigel, truck")
 * and, for a material receipt with no job, Otto's job pick with his reason: "Use this job" puts it on
 * the receipt (through the expense gate), "Not this job" leaves it and Otto learns. Shadow mode —
 * nothing is set without the tap. Office only (the server returns nothing to anyone else).
 * Server: SupplierJobSuggestService via /crm/api/supplier-stops.php (?mode=receipt&id=N,
 * POST {mode: confirm|reject}). Styling reuses Penny's read card (.mw-penny-read).
 *
 *   MwSupplierStop.load(el, expenseId, { csrf: CSRF, onDone: function (res) { … } })
 */
(function () {
    'use strict';

    var BASIS = {
        printed: 'matched by the time printed on the receipt',
        photo: 'matched by when the receipt was photographed',
        date: 'matched by the receipt date (photographed another day)'
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, kind) {
        if (window.mwToast) window.mwToast(msg, kind || 'success'); else window.alert(msg);
    }

    function render(el, d, expenseId, opts) {
        if (!d || !d.stop) { el.innerHTML = ''; el.hidden = true; return; }
        el.hidden = false;
        var h = '<div class="mw-penny-read is-applied">' +
            '<div class="mw-penny-read-head"><span class="mw-penny-read-title">Otto</span>' +
            '<span class="mw-penny-read-sub">Supplier stop on the GPS</span></div>' +
            '<div class="mw-penny-read-note">' + esc(d.stop.line) + ' (' + esc(d.stop.minutes) + ' min, ' +
            esc(BASIS[d.stop.basis] || 'matched') + ')</div>';
        var s = d.suggestion;
        if (s) {
            h += '<div class="mw-penny-read-row"><div class="mw-penny-read-label">Job</div>' +
                 '<div class="mw-penny-read-vals"><span class="mw-penny-read-penny">' + esc(s.plan_number) + ' · ' + esc(s.street) + '</span>' +
                 '<span class="mw-penny-read-was">receipt: no job</span></div>' +
                 '<div class="mw-penny-read-why">' + esc(s.reason) + '</div></div>' +
                 '<div class="mw-penny-read-actions">' +
                 '<button type="button" class="btn btn-sm btn-success" data-otto="confirm">Use this job</button>' +
                 '<button type="button" class="btn btn-sm btn-outline-secondary" data-otto="reject">Not this job</button></div>' +
                 (s.shadow && s.shadow.line ? '<div class="mw-penny-read-note">' + esc(s.shadow.line) + '</div>' : '');
        }
        h += '</div>';
        el.innerHTML = h;
        Array.prototype.forEach.call(el.querySelectorAll('[data-otto]'), function (b) {
            b.addEventListener('click', function () { act(el, b.getAttribute('data-otto'), expenseId, s, opts); });
        });
    }

    async function act(el, which, expenseId, s, opts) {
        var buttons = el.querySelectorAll('[data-otto]');
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
        try {
            var r = await fetch('/crm/api/supplier-stops.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
                body: JSON.stringify({ mode: which, id: expenseId, plan_id: s ? s.plan_id : 0, csrf_token: opts.csrf })
            });
            var d = await r.json();
            if (!d || !d.ok) throw new Error((d && (d.error || d.message)) || 'Not saved');
            toast(d.message || 'Saved');
            if (opts.onDone) opts.onDone(d, which); else load(el, expenseId, opts);
        } catch (err) {
            toast(err.message || 'Not saved', 'error');
            Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
        }
    }

    async function load(el, expenseId, opts) {
        if (!el) return;
        opts = opts || {};
        el.innerHTML = ''; el.hidden = true;
        el.setAttribute('data-expense-id', String(expenseId));
        try {
            var r = await fetch('/crm/api/supplier-stops.php?mode=receipt&id=' + encodeURIComponent(expenseId), { cache: 'no-store' });
            var d = await r.json();
            // The modal may have moved on to another receipt while this loaded.
            if (!d || !d.ok || el.getAttribute('data-expense-id') !== String(expenseId)) return;
            render(el, d, expenseId, opts);
        } catch (err) { /* a bonus — the receipt works without it */ }
    }

    window.MwSupplierStop = { load: load, render: render };
})();
