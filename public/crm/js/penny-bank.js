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

    // ── Pending e-Transfers: same reading as the Invoices panel; the owner presses Record ──
    var et = document.getElementById('mw-et');
    var etItems = [], etIdx = 0, etWaiting = 0;
    function loadEt(msg) {
        if (!et) return;
        fetch(API + '?mode=etransfers&limit=12', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                etItems = (d && d.ok && d.items) || [];
                etWaiting = (d && d.waiting) || 0;
                etIdx = 0;
                renderEt(msg);
            })
            .catch(function () { et.hidden = true; });
    }
    function etLine(l) {
        return '<div class="mw-et-l"><input class="mw-rc-in" data-et-inv placeholder="INV-2026-…" value="' + esc(l.invoice) + '" aria-label="Invoice number">' +
            '<input class="mw-rc-in" data-et-amt type="number" step="0.01" inputmode="decimal" value="' + esc(Number(l.amount).toFixed(2)) + '" aria-label="Amount">' +
            '<button type="button" class="mw-rc-idel" data-et-del aria-label="Remove line">✕</button></div>';
    }
    function renderEt(msg) {
        if (!et) return;
        if (!etItems.length) { et.hidden = !msg; et.innerHTML = msg ? '<div class="mw-bl-head"><b>💸 e-Transfers</b></div><div class="mw-rc-empty">' + esc(msg) + '</div>' : ''; return; }
        et.hidden = false;
        if (etIdx >= etItems.length) etIdx = 0;
        var t = etItems[etIdx];
        var lines = t.lines.length ? t.lines : [{ invoice: '', amount: t.active }];
        et.innerHTML =
            '<div class="mw-bl-head"><span><b>💸 e-Transfers</b> · ' + etWaiting + ' waiting</span>' +
              '<span><button type="button" class="mw-rc-arrow" data-et="prev" aria-label="Previous">‹</button> ' +
              '<button type="button" class="mw-rc-arrow" data-et="next" aria-label="Next">›</button></span></div>' +
            '<div class="mw-bl-line"><div class="mw-bl-top"><span>' + esc(t.date ? String(t.date).slice(0, 10) : '') + '</span><b class="is-in">+$' + Number(t.amount).toFixed(2) + '</b></div>' +
              '<div class="mw-bl-desc">' + esc(t.sender) + (t.memo ? ' — “' + esc(t.memo) + '”' : '') + '</div>' +
              '<div class="mw-bl-now">' + (t.confirmed === 3 ? '✓ Bank deposit, invoice and email all match' : t.confirmed + '/3 confirmed — missing ' + esc(t.missing.join(' & '))) + '</div>' +
              (t.claim ? '<div class="mw-et-claim">⚠ Claim it in your online banking first — it isn\'t deposited until you do.</div>' : '') +
            '</div>' +
            '<div class="mw-bl-say">' + (GREETING ? esc(GREETING) + ', ' : '') + esc(t.say) + '</div>' +
            (t.kind === 'duplicate' ? '' : '<div class="mw-et-lines">' + lines.map(etLine).join('') +
              '<button type="button" class="mw-rc-ed mw-et-add" data-et="split">+ split across another invoice</button></div>') +
            '<div class="mw-rc-actions">' +
              (t.kind === 'duplicate'
                ? '<button type="button" class="mw-rc-ok" data-et="dismiss">Already recorded — dismiss</button><button type="button" class="mw-rc-ed" data-et="record-anyway">Record anyway</button>'
                : '<button type="button" class="mw-rc-ok" data-et="record">✓ Record payment</button><button type="button" class="mw-rc-ed" data-et="dismiss">Dismiss</button>') +
              '<a class="mw-rc-sk" href="/crm/invoices/index.php#etransfers">Invoices page →</a>' +
              '<button type="button" class="mw-rc-sk" data-et="next">Skip →</button>' +
            '</div><div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>';
    }
    function etPost(fields) {
        var fd = new FormData();
        fd.append('csrf_token', window.MW_CSRF_TOKEN || '');
        Object.keys(fields).forEach(function (k) {
            var v = fields[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v);
        });
        return fetch('/crm/api/etransfer-confirm.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    }
    if (et) {
        et.addEventListener('click', function (e) {
            var a = e.target.getAttribute && e.target.getAttribute('data-et');
            if (e.target.hasAttribute && e.target.hasAttribute('data-et-del')) { var row = e.target.closest('.mw-et-l'); if (row && et.querySelectorAll('.mw-et-l').length > 1) row.remove(); return; }
            if (!a) return;
            var t = etItems[etIdx];
            if (a === 'next') { etIdx = (etIdx + 1) % Math.max(1, etItems.length); renderEt(); return; }
            if (a === 'prev') { etIdx = (etIdx - 1 + etItems.length) % Math.max(1, etItems.length); renderEt(); return; }
            if (a === 'split') { e.target.insertAdjacentHTML('beforebegin', etLine({ invoice: '', amount: 0 })); return; }
            if (a === 'record-anyway') { t.kind = 'hint'; renderEt('Check the invoice and amount, then record.'); return; }
            var req;
            if (a === 'dismiss') {
                req = etPost({ action: 'dismiss', notification_id: t.id });
            } else {
                var invs = [], amts = [];
                et.querySelectorAll('.mw-et-l').forEach(function (r) {
                    var i = r.querySelector('[data-et-inv]').value.trim(), m = r.querySelector('[data-et-amt]').value;
                    if (i) { invs.push(i); amts.push(m); }
                });
                if (!invs.length) { renderEt('Type the invoice number first.'); return; }
                req = etPost({ action: 'record', notification_id: t.id, invoice_numbers: invs, amounts: amts });
            }
            e.target.disabled = true;
            req.then(function (d) {
                if (d && d.ok) { loadEt(d.message || (a === 'dismiss' ? 'Dismissed.' : 'Recorded.')); }
                else { e.target.disabled = false; renderEt((d && d.message) || 'Could not save'); }
            }).catch(function () { e.target.disabled = false; renderEt('Network error — try again'); });
        });
        loadEt();
    }

    // ── Recurring bills: what's due, late, or changed amount ──────────────
    var rb = document.getElementById('mw-rb');
    if (rb) {
        fetch(API + '?mode=recurring', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var bills = (d && d.ok && d.bills) || [];
                if (!bills.length) { rb.hidden = true; return; }
                var flag = { due: '📅 due soon', late: '⚠ late — not seen yet', not_imported: '⏳ due, statement not imported yet', changed: '↕ amount changed' };
                var attention = bills.filter(function (b) { return b.status.length; });
                rb.hidden = false;
                rb.innerHTML = '<div class="mw-bl-head"><b>🔁 Recurring bills</b><span>' + bills.length + ' I watch' +
                    (attention.length ? ' · ' + attention.length + ' to look at' : ' · all as expected') + '</span></div>' +
                    '<ul class="mw-rb-list">' + bills.map(function (b) {
                        var notes = b.status.map(function (s) {
                            return '<span class="' + (s === 'due' || s === 'not_imported' ? 'mw-sc-un' : 'mw-sc-bad') + '">' + flag[s] +
                                (s === 'changed' ? ': $' + b.usual.toFixed(2) + ' → $' + b.latest.toFixed(2) : '') + '</span>';
                        }).join(' ');
                        return '<li><b>' + esc(b.payee) + '</b> ~$' + b.usual.toFixed(2) + ' · next ' + esc(b.next) + ' ' + notes + '</li>';
                    }).join('') + '</ul>';
            })
            .catch(function () { rb.hidden = true; });
    }

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
                    }).join('') +
                    '<div class="mw-sc-lock">' +
                      ((d.lockable || []).length
                        ? '<span>Ready to close:</span> ' + d.lockable.slice(-6).map(function (m) {
                              return '<button type="button" class="mw-rc-ed" data-lock="' + esc(m) + '">🔒 Lock ' + esc(m) + '</button>';
                          }).join(' ')
                        : '<span>No month is ready to close yet.</span>') +
                      ((d.locked || []).length ? ' <span class="mw-sc-un">Locked: ' + esc(d.locked.slice(-6).join(', ')) + '</span>' : '') +
                      (d.balances ? '' : '<div class="mw-sc-un">Run migration 1068 so new statements are proven against their balances.</div>') +
                    '</div><div class="mw-rc-msg" data-sc-msg></div>';
            })
            .catch(function () { sc.hidden = true; });
    }
    if (sc) {
        sc.addEventListener('click', function (e) {
            var m = e.target.getAttribute && e.target.getAttribute('data-lock');
            if (!m) return;
            if (!e.target.classList.contains('is-confirm')) {      // two clicks: locking can't be undone here
                e.target.classList.add('is-confirm');
                e.target.textContent = 'Lock ' + m + '? Nothing in it can change after';
                return;
            }
            e.target.disabled = true;
            post({ mode: 'lock_month', month: m }).then(function (d) {
                var msg = (d && (d.message || d.error)) || 'Could not lock';
                loadClose();
                setTimeout(function () { var el = sc.querySelector('[data-sc-msg]'); if (el) el.textContent = msg; }, 600);
            }).catch(function () { e.target.disabled = false; });
        });
    }
    loadClose();
})();
