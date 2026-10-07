/**
 * Charlie's decision inbox — shared by his dashboard card and /crm/foreman_calendar_appstack.php.
 *
 * A view and a router: each button posts Tim's click to the head's OWN endpoint (with his
 * CSRF token, so the head's permissions and checks run), then tells Charlie what happened
 * (?mode=inbox_done) so he learns. A batch runs its items one by one and stops at the first
 * that fails. Held proposals show the rule that held them and an Override button.
 * Only the endpoints below are ever called — a URL in the data can't add one.
 */
window.CharlieInbox = (function () {
    'use strict';
    var CHARLIE = '/crm/api/charlie.php';
    var ALLOWED = ['/crm/api/otto.php', '/crm/api/sales-head.php', '/crm/api/bookkeeper.php', '/crm/api/charlie.php', '/crm/api/mia.php'];
    var HEADS = { charlie: 'Charlie', penny: 'Penny', sam: 'Sam', otto: 'Otto', mia: 'Mia', house: 'Work Queue' };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function safeUrl(u) { return (typeof u === 'string' && /^(\/|#|https:\/\/)/.test(u)) ? u : null; }
    function money(v) { return v ? '$' + Math.round(v).toLocaleString('en-CA') : ''; }
    function post(url, body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    return { ok: r.ok && j.ok !== false && j.success !== false, message: j.message || j.error || '' };
                });
            });
    }

    /** Run one button: the head's endpoint, then log it with Charlie. */
    function run(item, action, batchKey) {
        if (!action || ALLOWED.indexOf(action.endpoint) < 0) return Promise.resolve({ ok: false, message: 'Not allowed' });
        var body = {};
        Object.keys(action.body || {}).forEach(function (k) { body[k] = action.body[k]; });
        return post(action.endpoint, body).then(function (res) {
            post(CHARLIE, { mode: 'inbox_done', key: item.key, head: item.head, label: action.label, batch_key: batchKey || null,
                            ok: res.ok, message: res.message }).catch(function () {});
            return res;
        }, function () { return { ok: false, message: "Couldn't reach " + (HEADS[item.head] || item.head) }; });
    }

    function buttons(item, i) {
        return (item.actions || []).map(function (a, j) {
            return '<button type="button" class="mw-charlie-btn is-' + esc(a.style) + '" data-i="' + i + '" data-a="' + j + '">' + esc(a.label) + '</button>';
        }).join('');
    }

    function rowHtml(r, i) {
        if (r.type === 'batch') {
            var n = r.items.length;
            return '<div class="mw-charlie-row is-batch">'
                + '<div class="mw-charlie-row-main"><span class="mw-charlie-tag">' + esc(HEADS[r.head] || r.head) + '</span>'
                + '<b>' + esc(r.label) + '</b>' + (r.value ? ' <small>' + money(r.value) + '</small>' : '') + '</div>'
                + '<details><summary>Show all ' + n + '</summary><ul>' + r.items.map(function (it) { return '<li>' + esc(it.text) + '</li>'; }).join('') + '</ul></details>'
                + '<div class="mw-charlie-row-btns"><button type="button" class="mw-charlie-btn is-primary" data-batch="' + i + '">Do all ' + n + '</button></div></div>';
        }
        var u = safeUrl(r.url);
        return '<div class="mw-charlie-row' + (r.priority === 1 ? ' is-p1' : '') + '">'
            + '<div class="mw-charlie-row-main"><span class="mw-charlie-tag">' + esc(HEADS[r.head] || r.head) + '</span>'
            + (u ? '<a href="' + esc(u) + '">' + esc(r.text) + '</a>' : esc(r.text))
            + (r.value ? ' <small>' + money(r.value) + '</small>' : '') + '</div>'
            + ((r.actions || []).length ? '<div class="mw-charlie-row-btns">' + buttons(r, i) + '</div>' : '') + '</div>';
    }

    function heldHtml(h, i) {
        var u = safeUrl(h.url);
        return '<div class="mw-charlie-row is-held"><div class="mw-charlie-row-main"><span class="mw-charlie-tag">' + esc(HEADS[h.head] || h.head) + '</span>'
            + (u ? '<a href="' + esc(u) + '">' + esc(h.text) + '</a>' : esc(h.text)) + '</div>'
            + '<div class="mw-charlie-why">' + esc(h.rule_title) + ': ' + esc(h.reason) + '</div>'
            + '<div class="mw-charlie-row-btns"><button type="button" class="mw-charlie-btn is-quiet" data-held="' + i + '">Override</button></div></div>';
    }

    /**
     * @param {HTMLElement} rowsEl  where the decisions go
     * @param {HTMLElement} heldEl  where held proposals go
     * @param {object} inbox        {rows, held} from ?mode=today|calendar
     * @param {function} say        say(message) — status line
     * @param {function} reload     called after any change
     * @param {number} limit        rows to show (0 = all)
     */
    function render(rowsEl, heldEl, inbox, say, reload, limit) {
        var rows = inbox.rows || [];
        var shown = limit ? rows.slice(0, limit) : rows;
        rowsEl.innerHTML = shown.length ? shown.map(rowHtml).join('')
            + (limit && rows.length > limit ? '<a class="mw-charlie-more" href="/crm/foreman_calendar_appstack.php#decisions">+ ' + (rows.length - limit) + ' more</a>' : '')
            : '<div class="mw-charlie-empty">Nothing waiting on you.</div>';
        var held = inbox.held || [];
        if (heldEl) {
            heldEl.innerHTML = held.length ? '<div class="mw-charlie-held-hd">Held by your rules</div>' + held.map(heldHtml).join('') : '';
            heldEl.hidden = !held.length;
        }
        var busy = false;
        function done(p) { return p.then(function () { busy = false; reload(); }, function () { busy = false; }); }

        rowsEl.onclick = function (e) {
            var b = e.target.closest('button');
            if (!b || busy) return;
            busy = true;
            b.disabled = true;
            if (b.hasAttribute('data-batch')) {
                var r = shown[+b.getAttribute('data-batch')];
                var ok = 0, i = 0;
                var next = function () {
                    if (i >= r.items.length) { say(ok + ' done.'); return Promise.resolve(); }
                    var it = r.items[i++];
                    return run(it, (it.actions || [])[0], r.batch_key).then(function (res) {
                        if (!res.ok) { say(ok + ' done, then one needs you: ' + it.text + (res.message ? ' — ' + res.message : '')); return; }
                        ok++;
                        return next();
                    });
                };
                done(next());
                return;
            }
            var item = shown[+b.getAttribute('data-i')];
            done(run(item, item.actions[+b.getAttribute('data-a')], null).then(function (res) {
                say(res.message || (res.ok ? 'Done.' : "That didn't work."));
            }));
        };
        if (heldEl) heldEl.onclick = function (e) {
            var b = e.target.closest('button[data-held]');
            if (!b || busy) return;
            busy = true;
            var h = held[+b.getAttribute('data-held')];
            done(post(CHARLIE, { mode: 'override', rule: h.rule, key: h.key }).then(function (res) { say(res.message); }));
        };
        return rows.length + held.length;
    }

    return { render: render, esc: esc, post: post, safeUrl: safeUrl, money: money };
})();
