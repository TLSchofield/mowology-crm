/**
 * Penny's "Missing receipts" strip on the dashboard (includes/penny-missing-strip.php).
 * "Manage" loads /crm/api/penny-chase.php?mode=admin and shows:
 *   - every open item: who Penny is asking and why, with "ask someone else"
 *   - the receipts marked "no receipt" (no GST claim on those)
 *   - Tim's card list: card ••last4 → person
 *   - the lines that never have a receipt (exempt rules): add / turn off
 * AppStack page: window.MW_CSRF_TOKEN exists here; a 403 refreshes it from /crm/api/get-csrf.php once.
 */
(function () {
    'use strict';
    var API = '/crm/api/penny-chase.php';
    var root = document.getElementById('mw-penny-missing');
    if (!root) return;
    var panel = document.getElementById('mw-pm-panel');
    var openBtn = root.querySelector('[data-pm-open]');
    var data = null;

    function token() { return window.MW_CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }
    function post(body, retried) {
        return fetch(API, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({}, body, { csrf_token: token() }))
        }).then(function (r) {
            if (r.status === 403 && !retried) {
                return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (x) { return x.json(); })
                    .then(function (d) { if (d && d.token) window.MW_CSRF_TOKEN = d.token; return post(body, true); });
            }
            return r.json();
        });
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function money(v) { return '$' + Number(v).toFixed(2); }
    function note(msg, bad) {
        var n = panel.querySelector('.mw-pm-msg');
        if (!n) { n = el('div', 'mw-pm-msg'); panel.prepend(n); }
        n.textContent = msg || '';
        n.classList.toggle('is-bad', !!bad);
    }
    function after(res) {
        note(res && (res.message || res.error) || '', !(res && res.ok));
        if (res && res.ok) load();
    }

    function load() {
        fetch(API + '?mode=admin', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { data = d; render(); })
            .catch(function () { panel.textContent = 'Could not load — try again.'; });
    }

    function personSelect(selected, blankLabel) {
        var s = el('select', 'mw-pm-select');
        var o = el('option', null, blankLabel || 'Shared — use truck / clock');
        o.value = '';
        s.appendChild(o);
        (data.people || []).forEach(function (p) {
            var op = el('option', null, p.full_name);
            op.value = p.id;
            if (String(p.id) === String(selected || '')) op.selected = true;
            s.appendChild(op);
        });
        return s;
    }

    function render() {
        panel.innerHTML = '';
        if (!data || !data.ok) { panel.textContent = (data && (data.error || data.message)) || 'Not available'; return; }
        panel.appendChild(el('div', 'mw-pm-sum', data.summary));

        // Open items
        var h = el('h4', null, 'Penny is asking');
        panel.appendChild(h);
        if (!data.items.length) panel.appendChild(el('p', 'mw-pm-empty', 'Nothing missing.'));
        var ul = el('ul', 'mw-pm-list');
        data.items.forEach(function (i) {
            var li = el('li');
            li.appendChild(el('span', 'mw-pm-what', i.date + ' · ' + i.vendor + ' · ' + money(i.amount)));
            li.appendChild(el('span', 'mw-pm-who', (i.who || '—') + (i.nudges ? ' · asked ' + i.nudges + '×' : '') + (i.basis_note ? ' — ' + i.basis_note : '')));
            var sel = personSelect('', 'Ask someone else…');
            sel.addEventListener('change', function () {
                if (sel.value) post({ mode: 'reassign', id: i.id, user_id: parseInt(sel.value, 10) }).then(after);
            });
            li.appendChild(sel);
            ul.appendChild(li);
        });
        panel.appendChild(ul);

        // No receipt
        if (data.no_receipt && data.no_receipt.length) {
            panel.appendChild(el('h4', null, 'Marked "no receipt" — no GST claim on these'));
            var nl = el('ul', 'mw-pm-list');
            data.no_receipt.forEach(function (n) {
                nl.appendChild(el('li', null, n.charge_date + ' · ' + n.vendor_label + ' · ' + money(n.amount) + ' — '
                    + ((data.reasons || {})[n.reason] || n.reason) + (n.full_name ? ' (' + n.full_name + ')' : '') + (n.reason_note ? ': ' + n.reason_note : '')));
            });
            panel.appendChild(nl);
        }

        // Cards
        panel.appendChild(el('h4', null, 'Whose card is whose'));
        var cl = el('ul', 'mw-pm-list');
        (data.cards || []).forEach(function (c) {
            var li = el('li', null, '••' + c.card_last4 + ' → ' + (c.full_name || 'shared') + (c.label ? ' (' + c.label + ')' : '') + ' ');
            var x = el('button', 'mw-pm-x', 'Remove');
            x.type = 'button';
            x.addEventListener('click', function () { post({ mode: 'card_delete', last4: c.card_last4 }).then(after); });
            li.appendChild(x);
            cl.appendChild(li);
        });
        panel.appendChild(cl);
        var cf = el('form', 'mw-pm-form');
        var last4 = el('input'); last4.placeholder = 'Last 4'; last4.maxLength = 4; last4.inputMode = 'numeric'; last4.required = true; last4.className = 'mw-pm-in mw-pm-in-4';
        var who = personSelect('');
        var lbl = el('input'); lbl.placeholder = 'Label (e.g. Visa)'; lbl.className = 'mw-pm-in';
        var add = el('button', 'btn btn-sm btn-outline-success', 'Save card'); add.type = 'submit';
        [last4, who, lbl, add].forEach(function (n) { cf.appendChild(n); });
        cf.addEventListener('submit', function (e) {
            e.preventDefault();
            post({ mode: 'card_save', last4: last4.value.trim(), user_id: who.value ? parseInt(who.value, 10) : 0, label: lbl.value }).then(after);
        });
        panel.appendChild(cf);

        // Exempt rules
        panel.appendChild(el('h4', null, 'Never chase (no receipt exists)'));
        var rl = el('ul', 'mw-pm-list mw-pm-rules');
        (data.rules || []).filter(function (r) { return String(r.active) === '1'; }).forEach(function (r) {
            var li = el('li', null, r.label + ' ');
            li.appendChild(el('small', null, r.match_on.replace('_', ' ') + ': ' + r.pattern + ' '));
            var x = el('button', 'mw-pm-x', 'Turn off');
            x.type = 'button';
            x.addEventListener('click', function () { post({ mode: 'rule_delete', id: r.id }).then(after); });
            li.appendChild(x);
            rl.appendChild(li);
        });
        panel.appendChild(rl);
        var rf = el('form', 'mw-pm-form');
        var on = el('select', 'mw-pm-select');
        [['description', 'Bank line contains'], ['account_code', 'Account code'], ['account_name', 'Account name contains']].forEach(function (o) {
            var op = el('option', null, o[1]); op.value = o[0]; on.appendChild(op);
        });
        var pat = el('input'); pat.placeholder = 'e.g. PRESTO or 6800 or 2*'; pat.required = true; pat.className = 'mw-pm-in';
        var rlbl = el('input'); rlbl.placeholder = 'What it is'; rlbl.className = 'mw-pm-in';
        var radd = el('button', 'btn btn-sm btn-outline-success', 'Add'); radd.type = 'submit';
        [on, pat, rlbl, radd].forEach(function (n) { rf.appendChild(n); });
        rf.addEventListener('submit', function (e) {
            e.preventDefault();
            post({ mode: 'rule_save', match_on: on.value, pattern: pat.value, label: rlbl.value }).then(after);
        });
        panel.appendChild(rf);
    }

    openBtn.addEventListener('click', function () {
        var show = panel.hidden;
        panel.hidden = !show;
        openBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
        openBtn.textContent = show ? 'Close' : 'Manage →';
        if (show) { panel.textContent = 'Loading…'; load(); }
    });
})();
