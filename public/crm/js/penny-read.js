/**
 * MwPennyRead — "Penny's read" on a receipt (expense modal on web + the Android app).
 *
 * When she hasn't read the receipt yet, offers "Ask Penny" (POST penny_ask) instead.
 * Shows Penny's pending suggestion where it differs from the receipt: each field with her
 * reason, her line items next to the receipt's, her notes, and two buttons —
 * "Use Penny's version" (applies all of it; for approvers it also approves) and
 * "Keep mine" (approvers: her read is set aside). Renders nothing when she has no open
 * suggestion or agrees with the receipt. Server: PennyReadService via /crm/api/expenses.php
 * (?action=penny_read&id=N, POST penny_apply / penny_keep). Same as the iPhone receipt detail.
 *
 *   MwPennyRead.load(el, expenseId, { csrf: CSRF, onDone: function (res) { … } })
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return '$' + (Number(v) || 0).toFixed(2); }
    function toast(msg, kind) {
        if (window.mwToast) window.mwToast(msg, kind || 'success'); else window.alert(msg);
    }

    function render(el, read, expenseId, opts) {
        opts = opts || {};
        if (!read && opts.canAsk) { renderAsk(el, expenseId, opts); return; }
        if (read && !read.differs && !read.applied_draft && opts.justAsked) {
            el.hidden = false;
            el.innerHTML = '<div class="mw-penny-read is-applied"><span class="mw-penny-read-title">Penny\'s read</span> ' +
                '<span class="mw-penny-read-note">She read this receipt the same way — nothing to change.</span></div>';
            return;
        }
        if (!read || (!read.differs && !read.applied_draft)) { el.innerHTML = ''; el.hidden = true; return; }
        el.hidden = false;
        if (!read.differs) {
            el.innerHTML = '<div class="mw-penny-read is-applied"><span class="mw-penny-read-title">Penny\'s read</span> ' +
                '<span class="mw-penny-read-note">Using Penny\'s version — waiting for the office to approve.</span></div>';
            return;
        }
        var h = '<div class="mw-penny-read">';
        h += '<div class="mw-penny-read-head"><span class="mw-penny-read-title">Penny\'s read</span>' +
             '<span class="mw-penny-read-sub">She read this receipt differently</span></div>';
        (read.fields || []).forEach(function (f) {
            h += '<div class="mw-penny-read-row">' +
                 '<div class="mw-penny-read-label">' + esc(f.label) + '</div>' +
                 '<div class="mw-penny-read-vals"><span class="mw-penny-read-penny">' + esc(f.penny || '—') + '</span>' +
                 '<span class="mw-penny-read-was">receipt: ' + esc(f.receipt || '—') + '</span></div>' +
                 (f.reason ? '<div class="mw-penny-read-why">' + esc(f.reason) +
                    (f.confidence ? ' <span class="mw-penny-read-conf is-' + esc(f.confidence) + '">' + esc(f.confidence) + '</span>' : '') + '</div>' : '') +
                 '</div>';
        });
        if (read.items) {
            h += '<div class="mw-penny-read-row"><div class="mw-penny-read-label">Line items</div>' +
                 '<div class="mw-penny-read-items"><div><div class="mw-penny-read-col">Penny · ' + money(read.items.penny_sum) + '</div>';
            read.items.penny.forEach(function (i) {
                h += '<div class="mw-penny-read-item"><span>' + esc(i.name) + '</span><span>' + money(i.amount) + '</span></div>';
            });
            h += '</div><div class="mw-penny-read-was-col"><div class="mw-penny-read-col">Receipt · ' + money(read.items.receipt_sum) + '</div>';
            if (!read.items.receipt.length) h += '<div class="mw-penny-read-item"><span>No items</span></div>';
            read.items.receipt.forEach(function (i) {
                h += '<div class="mw-penny-read-item"><span>' + esc(i.name) + '</span><span>' + money(i.amount) + '</span></div>';
            });
            h += '</div></div></div>';
        }
        if (read.notes) h += '<div class="mw-penny-read-notes">' + esc(read.notes) + '</div>';
        if (read.can_apply || read.can_keep) {
            h += '<div class="mw-penny-read-actions">';
            if (read.can_apply) h += '<button type="button" class="btn btn-sm btn-success" data-penny="apply">Use Penny\'s version</button>';
            if (read.can_keep) h += '<button type="button" class="btn btn-sm btn-outline-secondary" data-penny="keep">Keep mine</button>';
            h += '</div><div class="mw-penny-read-note">' + esc(read.can_apply ? read.apply_note : '') + '</div>';
        }
        h += '</div>';
        el.innerHTML = h;
        Array.prototype.forEach.call(el.querySelectorAll('[data-penny]'), function (b) {
            b.addEventListener('click', function () { act(el, b.getAttribute('data-penny'), expenseId, opts); });
        });
    }

    // No read of hers yet: Penny prepares only some receipts a day. "Ask Penny" reads this one now
    // (server: PennyReadService::ask → BookkeeperDeskService::askOne, daily cap + LlmDailyCap).
    function renderAsk(el, expenseId, opts) {
        el.hidden = false;
        el.innerHTML = '<div class="mw-penny-read is-applied"><div class="mw-penny-read-head">' +
            '<span class="mw-penny-read-title">Penny\'s read</span>' +
            '<span class="mw-penny-read-sub">Penny hasn\'t read this receipt yet.</span></div>' +
            '<div class="mw-penny-read-actions"><button type="button" class="btn btn-sm btn-outline-success" data-penny-ask>Ask Penny</button></div></div>';
        var b = el.querySelector('[data-penny-ask]');
        b.addEventListener('click', async function () {
            b.disabled = true;
            b.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Penny is reading…';
            try {
                var r = await fetch('/crm/api/expenses.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
                    body: JSON.stringify({ action: 'penny_ask', id: expenseId, csrf_token: opts.csrf })
                });
                var d = await r.json();
                if (!d || !d.success) throw new Error((d && (d.message || d.error)) || 'Penny couldn\'t read it');
                if (el.getAttribute('data-expense-id') !== String(expenseId)) return;
                render(el, d.read || null, expenseId, Object.assign({}, opts, { canAsk: false, justAsked: true }));
                if (!d.read) load(el, expenseId, opts);
            } catch (err) {
                toast(err.message || 'Penny couldn\'t read it', 'error');
                b.disabled = false; b.textContent = 'Ask Penny';
            }
        });
    }

    async function act(el, which, expenseId, opts) {
        var buttons = el.querySelectorAll('[data-penny]');
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
        try {
            var r = await fetch('/crm/api/expenses.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
                body: JSON.stringify({ action: which === 'apply' ? 'penny_apply' : 'penny_keep', id: expenseId, csrf_token: opts.csrf })
            });
            var d = await r.json();
            if (!d || !d.success) throw new Error((d && (d.message || d.error)) || 'Not saved');
            toast(d.message || 'Saved', d.blocked ? 'warning' : 'success');
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
            var r = await fetch('/crm/api/expenses.php?action=penny_read&id=' + encodeURIComponent(expenseId), { cache: 'no-store' });
            var d = await r.json();
            // The modal may have moved on to another receipt while this loaded.
            if (!d || !d.success || el.getAttribute('data-expense-id') !== String(expenseId)) return;
            render(el, d.read, expenseId, Object.assign({}, opts, { canAsk: !!d.can_ask }));
        } catch (err) { /* a bonus — the receipt works without it */ }
    }

    window.MwPennyRead = { load: load, render: render };
})();
