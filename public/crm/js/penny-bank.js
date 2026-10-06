/**
 * Penny's bank lines (dashboard → her card): imported lines left on Miscellaneous /
 * Other Services, one at a time, with her suggested category and why. Approve it, pick
 * another account, keep it as it is, or skip. Approving teaches the import.
 * API: /crm/api/bookkeeper.php ?mode=bank_queue · POST bank_decide (BankDeskService).
 */
(function () {
    'use strict';
    var box = document.getElementById('mw-bl');
    if (!box) return;
    var API = '/crm/api/bookkeeper.php';
    var GREETING = box.getAttribute('data-name') || '';
    var lines = [], accounts = [], waiting = 0, idx = 0, busy = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return '$' + Math.abs(Number(v) || 0).toFixed(2); }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load(msg) {
        return fetch(API + '?mode=bank_queue&limit=12', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.ready) { box.hidden = true; return; }
                lines = d.lines || [];
                accounts = d.accounts || [];
                waiting = d.waiting || 0;
                idx = 0;
                render(msg);
            })
            .catch(function () { box.hidden = true; });
    }

    function options(line, selectedId) {
        var order = line.type === 'income' ? ['revenue', 'liability', 'asset', 'equity', 'expense'] : ['expense', 'liability', 'asset', 'equity', 'revenue'];
        var label = { expense: 'Expenses', revenue: 'Income', liability: 'Owed (cards, loans)', asset: 'Assets', equity: 'Owner' };
        var html = '<option value="">— pick an account —</option>';
        order.forEach(function (t) {
            var group = accounts.filter(function (a) { return a.type === t; });
            if (!group.length) return;
            html += '<optgroup label="' + esc(label[t] || t) + '">' + group.map(function (a) {
                return '<option value="' + esc(a.id) + '"' + (String(a.id) === String(selectedId) ? ' selected' : '') + '>' + esc(a.code + ' ' + a.name) + '</option>';
            }).join('') + '</optgroup>';
        });
        return html;
    }

    function render(msg) {
        if (!lines.length) {
            box.hidden = waiting === 0 && !msg;
            box.innerHTML = '<div class="mw-bl-head"><b>🏦 Bank lines</b></div><div class="mw-rc-empty">' +
                esc(msg || 'All bank lines are categorized. 🎉') + '</div>';
            return;
        }
        box.hidden = false;
        if (idx >= lines.length) idx = 0;
        var l = lines[idx], s = l.suggestion;
        var now = l.current ? l.current.name : 'No account';
        box.innerHTML =
            '<div class="mw-bl-head"><span><b>🏦 Bank lines</b> · ' + waiting + ' to check</span>' +
              '<span><button type="button" class="mw-rc-arrow" data-bl="prev" aria-label="Previous">‹</button> ' +
              '<button type="button" class="mw-rc-arrow" data-bl="next" aria-label="Next">›</button></span></div>' +
            '<div class="mw-bl-line">' +
              '<div class="mw-bl-top"><span>' + esc(l.date) + (l.bank ? ' · ' + esc(l.bank) : '') + '</span>' +
                '<b class="' + (l.type === 'income' ? 'is-in' : 'is-out') + '">' + (l.type === 'income' ? '+' : '−') + money(l.amount) + '</b></div>' +
              '<div class="mw-bl-desc">' + esc(l.description) + '</div>' +
              '<div class="mw-bl-now">Booked now: <s>' + esc(now) + '</s></div>' +
            '</div>' +
            '<div class="mw-bl-say">' + (GREETING ? esc(GREETING) + ', ' : '') +
              (l.note ? esc(l.note) : s && s.source === 'found_receipt' ? '🧾 ' + esc(s.reason) + '.' + (s.name ? ' So: <b>' + esc(s.name) + '</b>.' : '')
                 : s ? 'I think this is <b>' + esc(s.name) + '</b>: ' + esc(s.reason) + '.'
                 : 'I don\'t know this one yet. What is it? Your answer teaches the import.') + '</div>' +
            '<div class="mw-bl-pick"><select class="mw-rc-in" data-bl-acct aria-label="Account">' + options(l, s ? s.account_id : '') + '</select></div>' +
            '<div class="mw-rc-actions">' +
              '<button type="button" class="mw-rc-ok" data-bl="approve">✓ Approve</button>' +
              '<button type="button" class="mw-rc-ed" data-bl="keep">Keep as ' + esc(now) + '</button>' +
              '<button type="button" class="mw-rc-sk" data-bl="next">Skip →</button>' +
            '</div>' +
            '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>';
    }

    function decide(action) {
        if (busy || !lines.length) return;
        var l = lines[idx];
        var sel = box.querySelector('[data-bl-acct]');
        var acct = sel && sel.value ? parseInt(sel.value, 10) : null;
        if (action === 'approve' && !acct) { render('Pick an account first'); return; }
        busy = true;
        post({ mode: 'bank_decide', transaction_id: l.id, action: action, account_id: acct,
               suggested_id: l.suggestion ? l.suggestion.account_id : null,
               expense_id: l.suggestion && l.suggestion.expense_id ? l.suggestion.expense_id : null })
            .then(function (d) {
                busy = false;
                if (!(d && d.ok)) { render((d && (d.message || d.error)) || 'Could not save'); return; }
                lines.splice(idx, 1);
                waiting = Math.max(0, waiting - 1);
                if (lines.length < 3 && waiting > lines.length) load(d.message); else render(d.message);
            })
            .catch(function () { busy = false; render('Network error — try again'); });
    }

    box.addEventListener('click', function (e) {
        var a = e.target.getAttribute && e.target.getAttribute('data-bl');
        if (!a) return;
        if (a === 'approve') decide('approve');
        else if (a === 'keep') decide('keep');
        else if (a === 'next') { idx = (idx + 1) % Math.max(1, lines.length); render(); }
        else if (a === 'prev') { idx = (idx - 1 + lines.length) % Math.max(1, lines.length); render(); }
    });

    load();

    // ── Month-close proof: does every statement add up, stay in the books and chain? ──
    var sc = document.getElementById('mw-sc');
    function loadClose() {
        if (!sc) return;
        fetch(API + '?mode=close_status', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.ready || !(d.accounts || []).length) { sc.hidden = true; return; }
                sc.hidden = false;
                sc.innerHTML = '<div class="mw-bl-head"><b>📒 Statements</b><span>' + (GREETING ? esc(GREETING) + ', ' : '') +
                    'each month is closed when its statement adds up, every line is in the books and it follows on from the last one.</span></div>' +
                    d.accounts.map(function (a) {
                        var bad = a.statements.filter(function (s) { return !s.ok && !s.unproven; });
                        return '<details class="mw-sc-acct"' + (bad.length ? '' : '') + '><summary><b>' + esc(a.account) + '</b> ' +
                            '<span class="mw-sc-ok">✅ ' + a.closed + ' closed</span>' +
                            (a.open ? ' <span class="mw-sc-bad">❌ ' + a.open + ' to fix</span>' : '') +
                            (a.unproven ? ' <span class="mw-sc-un">◻ ' + a.unproven + ' can\'t prove (no balance saved)</span>' : '') +
                            (a.gaps.length ? ' <span class="mw-sc-bad">⚠ no statement for ' + esc(a.gaps.join(', ')) + '</span>' : '') +
                            '</summary>' +
                            (bad.length ? '<ul>' + bad.map(function (s) {
                                return '<li>' + esc(s.from) + ' → ' + esc(s.to) + ': ' + esc(s.problems.join('; ')) + '</li>';
                            }).join('') + '</ul>' : '<p>' + (a.unproven ? 'Nothing wrong found. Statements imported from now on are proven against their balances.' : 'All statements on this account check out.') + '</p>') +
                            '</details>';
                    }).join('');
            })
            .catch(function () { sc.hidden = true; });
    }
    loadClose();
})();
