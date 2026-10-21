/**
 * Penny's revisit page (/crm/accounting/penny-revisit.php). Data: /crm/api/penny-revisit.php.
 * She proposes, the owner decides one payee group at a time. Everything is built with DOM calls and textContent:
 * bank descriptions are untrusted text, never HTML.
 */
(function () {
    'use strict';
    var API = '/crm/api/penny-revisit.php';
    var csrf = window.PENNY_REVISIT_CSRF || '';
    var lastText = '';

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function money(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function $(id) { return document.getElementById(id); }

    function say(text, kind) {
        var m = $('pr-msg');
        if (!m) return;
        m.className = 'alert alert-' + (kind || 'info');
        m.textContent = text;
    }

    function call(mode, data, method) {
        var opts = { credentials: 'same-origin', headers: {} };
        var url = API + '?mode=' + encodeURIComponent(mode);
        if (method === 'POST') {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(Object.assign({ mode: mode, csrf_token: csrf }, data || {}));
            url = API;
        }
        return fetch(url, opts).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) throw new Error(d.error || d.message || 'Failed');
            return d;
        });
    }

    function tierBadge(tier) {
        var b = el('span', 'badge ms-2 ' + (tier === 'A' ? 'bg-success' : 'bg-secondary'), tier === 'A' ? 'Penny is sure' : 'Check it');
        b.title = tier === 'A' ? 'A vendor or rule you set up points here, and the line is on a default account.' : 'Weaker evidence, or the line is already on a real account.';
        return b;
    }

    function groupCard(g, applied) {
        var card = el('div', 'border rounded p-3 mb-2');
        var top = el('div', 'd-flex justify-content-between flex-wrap gap-2');
        var left = el('div');
        var title = el('div', 'fw-bold');
        title.appendChild(document.createTextNode(g.label || g.payee_key));
        if (!applied) title.appendChild(tierBadge(g.tier));
        left.appendChild(title);
        left.appendChild(el('div', 'small text-muted',
            g.count + ' line' + (g.count === 1 ? '' : 's') + ' · ' + money(g.total) + ' · ' + (g.first === g.last ? (g.first || '') : (g.first + ' to ' + g.last))));
        left.appendChild(el('div', 'small', (g.from_labels.join(', ') || 'no account') + '  →  ' + g.to_label));
        left.appendChild(el('div', 'small text-muted', 'Why: ' + g.reason));
        top.appendChild(left);

        var btns = el('div', 'd-flex gap-2 align-items-start');
        if (applied) {
            var undo = el('button', 'btn btn-sm btn-outline-secondary', 'Undo');
            undo.type = 'button';
            undo.addEventListener('click', function () { act('undo', g.ids, undo); });
            btns.appendChild(undo);
        } else {
            var ok = el('button', 'btn btn-sm btn-primary', 'Move ' + g.count + ' line' + (g.count === 1 ? '' : 's'));
            ok.type = 'button';
            ok.addEventListener('click', function () { act('apply', g.ids, ok); });
            var no = el('button', 'btn btn-sm btn-outline-secondary', 'Not now');
            no.type = 'button';
            no.title = 'Penny will not suggest this again';
            no.addEventListener('click', function () { act('dismiss', g.ids, no); });
            btns.appendChild(ok);
            btns.appendChild(no);
        }
        top.appendChild(btns);
        card.appendChild(top);
        return card;
    }

    function act(mode, ids, btn) {
        if (btn) btn.disabled = true;
        call(mode, { ids: ids }, 'POST').then(function (d) {
            if (mode === 'apply') {
                var sk = (d.skipped || []).length;
                say('Moved ' + d.moved + ' line' + (d.moved === 1 ? '' : 's') + '.' + (sk ? ' ' + sk + ' not moved: ' + d.skipped.map(function (s) { return s.why; }).join('; ') + '.' : ''), sk ? 'warning' : 'success');
            } else if (mode === 'undo') {
                var su = (d.skipped || []).length;
                say('Put back ' + d.undone + ' line' + (d.undone === 1 ? '' : 's') + '.' + (su ? ' ' + su + ' not put back: ' + d.skipped.map(function (s) { return s.why; }).join('; ') + '.' : ''), su ? 'warning' : 'success');
            } else {
                say('Dismissed. Penny will not suggest that again.', 'success');
            }
            load();
        }).catch(function (e) { say(e.message, 'danger'); if (btn) btn.disabled = false; });
    }

    function render(d) {
        $('pr-mode').textContent = d.mode === 'off' ? 'Revisit is switched off.' : 'Mode: proposals only. Nothing moves without your approval.';
        var w = d.waiting, a = d.applied;
        $('pr-count').textContent = w.counts.groups + ' payee' + (w.counts.groups === 1 ? '' : 's') + ', ' + w.counts.lines + ' line' + (w.counts.lines === 1 ? '' : 's');
        var box = $('pr-waiting');
        box.textContent = '';
        if (!w.actionable.length) box.appendChild(el('span', 'text-muted', 'Nothing waiting. Press "Look again and record" after you add a vendor or identify a payee.'));
        w.actionable.forEach(function (g) { box.appendChild(groupCard(g, false)); });

        $('pr-acc-count').textContent = w.counts.accountant_lines + ' line' + (w.counts.accountant_lines === 1 ? '' : 's');
        lastText = w.accountant_text;
        $('pr-accountant').textContent = w.accountant_text;

        $('pr-done-count').textContent = a.counts.lines + ' line' + (a.counts.lines === 1 ? '' : 's');
        var done = $('pr-applied');
        done.textContent = '';
        if (!a.actionable.length) done.appendChild(el('span', 'text-muted', 'Nothing yet.'));
        a.actionable.forEach(function (g) { done.appendChild(groupCard(g, true)); });
    }

    function load() {
        return call('list').then(render).catch(function (e) { say(e.message, 'danger'); $('pr-waiting').textContent = ''; });
    }

    function scan(dry) {
        var note = $('pr-scan-note');
        note.textContent = 'Looking… this can take a minute.';
        ['pr-preview', 'pr-scan'].forEach(function (id) { $(id).disabled = true; });
        call('scan', { dry_run: dry ? 1 : 0 }, 'POST').then(function (d) {
            var s = d.scan;
            var t = s.by_tier;
            note.textContent = (dry ? 'Preview (nothing recorded): ' : 'Recorded: ') + 'looked at ' + s.scanned + ' lines. Penny is sure about ' + t.A + ', ' + t.B + ' need a check, ' + t.C + ' are in closed periods (for the accountant).';
            if (dry && s.examples && s.examples.length) {
                say('Examples: ' + s.examples.map(function (x) { return x.date + ' ' + money(x.amount) + ' ' + x.description + ' (' + x.from + ' → ' + x.to + ')'; }).join('  |  '), 'info');
            }
            return dry ? null : load();
        }).catch(function (e) { note.textContent = ''; say(e.message, 'danger'); })
          .then(function () { ['pr-preview', 'pr-scan'].forEach(function (id) { $(id).disabled = false; }); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!$('pr-waiting')) return;
        $('pr-preview').addEventListener('click', function () { scan(true); });
        $('pr-scan').addEventListener('click', function () { scan(false); });
        $('pr-copy').addEventListener('click', function () {
            if (navigator.clipboard) navigator.clipboard.writeText(lastText).then(function () { say('Copied.', 'success'); });
        });
        load();
    });
})();
