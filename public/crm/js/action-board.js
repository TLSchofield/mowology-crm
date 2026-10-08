/**
 * Action Board — "Needs you now" on the dashboard (includes/action-board.php).
 *
 * Uses Charlie's already-ranked list: charlie-card.js stores it on window.MW_CHARLIE_TODAY
 * and fires 'mw:charlie-today'. If neither arrives within WAIT_MS (Charlie's card isn't on
 * the page, or it's slow), the board asks /crm/api/charlie.php?mode=today itself.
 * Buttons call Charlie's own actions so he keeps learning: Open → what:'open' then go;
 * Not now → what:'snooze' (back tomorrow) and the row leaves.
 * Move to… (customer messages only — any head's reply, Sam's "they replied" card) posts to
 * /crm/api/inbound-route.php {mode: move}: the message goes to that head and the sender + topic
 * is learned (InboundRouteService, 2026-10-08), and the row leaves.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-action-board');
    if (!root) return;

    var API = '/crm/api/charlie.php';
    var WAIT_MS = 6000;
    var status = root.querySelector('[data-ab-status]');
    var list = root.querySelector('[data-ab-rows]');
    var count = root.querySelector('[data-ab-count]');
    var msg = root.querySelector('[data-ab-msg]');
    var EMPTY = 'Nothing needs you right now. Charlie is watching.';

    // Who each head is on the board. Unknown heads (and the Work Queue) are Charlie's.
    var HEADS = {
        charlie: { name: 'Charlie', face: 'charlie', role: 'Foreman' },
        penny:   { name: 'Penny',   face: 'penny',   role: 'Bookkeeper' },
        sam:     { name: 'Sam',     face: 'sam',     role: 'Sales' },
        otto:    { name: 'Otto',    face: 'otto',    role: 'Operations' },
        mia:     { name: 'Mia',     face: 'mia',     role: 'Marketing' },
        yui:     { name: 'Yui',     face: 'yui',     role: 'Comms' },
        house:   { name: 'Charlie', face: 'charlie', role: 'Foreman' }
    };
    var PER_COL = 3;     // items shown under each head before "+N more"
    var ROUTE_API = '/crm/api/inbound-route.php';
    var MOVE_TO = ['penny', 'sam', 'otto', 'mia', 'yui'];

    var items = [];
    var gone = {};
    var got = false;
    var busy = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function safeUrl(u) {
        return (typeof u === 'string' && /^(\/(?!\/)|#|https:\/\/)/.test(u)) ? u : null;
    }
    function headKey(slug) {
        var key = String(slug || '').toLowerCase();
        return (HEADS[key] && key !== 'house') ? key : 'charlie';
    }
    function head(slug) { return HEADS[headKey(slug)]; }
    /** "today", "1 day", "3 days", "2 weeks" — from since (or first_seen when the head gave no date). */
    function waited(it) {
        var from = String(it.since || it.first_seen || '').slice(0, 10);
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(from);
        if (!m) return '';
        var then = new Date(+m[1], +m[2] - 1, +m[3]);
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var days = Math.max(0, Math.round((today - then) / 86400000));
        if (days === 0) return 'today';
        if (days < 14) return days + (days === 1 ? ' day' : ' days');
        if (days < 60) return Math.floor(days / 7) + ' weeks';
        return Math.floor(days / 30) + ' months';
    }
    function label(it) {
        var kind = String(it.kind || '');
        return (kind === 'campaign_reply' || /:campaign_reply$/.test(kind)) ? 'Start the quote' : 'Open';
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    /** A customer message (a reply in any head's lane, or Sam's "they replied" card) can be moved. */
    function movable(key) {
        return /^(sam|yui|penny|otto|mia):reply:\d+:[0-9a-f]{12}$/.test(key) || /^sam:contact:c\d+$/.test(key);
    }
    function moveMenu(it) {
        var here = headKey(it.head);
        var opts = MOVE_TO.filter(function (h) { return h !== here; }).map(function (h) {
            return '<option value="' + h + '">' + esc(HEADS[h].name) + ' · ' + esc(HEADS[h].role) + '</option>';
        }).join('');
        return '<select class="custom-select custom-select-sm mw-ab-move" data-ab-move aria-label="Move this message to another head"'
            + ' title="Wrong head? Move it — next time mail like this from the same sender goes there.">'
            + '<option value="">Move to…</option>' + opts + '</select>';
    }

    function row(it, first) {
        var u = safeUrl(it.url);
        var w = waited(it);
        return '<li class="mw-ab-row' + (first ? ' is-top' : '') + '" data-key="' + esc(it.key) + '">'
            +   '<div class="mw-ab-text">' + esc(it.text) + '</div>'
            +   '<div class="mw-ab-foot">'
            +     (w ? '<span class="mw-ab-wait" title="How long this has been waiting">' + esc(w) + '</span>' : '<span></span>')
            +     '<span class="mw-ab-acts">'
            +       (movable(String(it.key)) ? moveMenu(it) : '')
            +       '<button type="button" class="mw-ab-later" data-ab-snooze title="Bring it back tomorrow">Not now</button>'
            +       (u ? '<a class="btn btn-sm btn-primary mw-ab-go" href="' + esc(u) + '" data-ab-open>' + esc(label(it)) + '</a>' : '')
            +     '</span>'
            +   '</div>'
            + '</li>';
    }

    /** One column per head: face and name on top, that head's items beneath, in Charlie's order. */
    function column(key, its) {
        var h = HEADS[key];
        var more = its.length - PER_COL;
        return '<li class="mw-ab-col">'
            + '<div class="mw-ab-colhead">'
            +   '<img class="mw-ab-face" src="/crm/img/heads/' + esc(h.face) + '.jpg" alt="" width="72" height="72" loading="lazy">'
            +   '<div><b>' + esc(h.name) + '</b><span>' + esc(h.role) + '</span></div>'
            +   '<em class="mw-ab-n">' + its.length + '</em>'
            + '</div>'
            + '<ul class="mw-ab-items">' + its.slice(0, PER_COL).map(function (it, i) { return row(it, i === 0); }).join('') + '</ul>'
            + (more > 0 ? '<div class="mw-ab-more">+' + more + ' more</div>' : '')
            + '</li>';
    }

    function render() {
        var cols = {}, order = [], total = 0;
        items.forEach(function (it) {
            if (!it || !it.key || gone[it.key]) return;
            var k = headKey(it.head);
            if (!cols[k]) { cols[k] = []; order.push(k); }   // columns ordered by each head's most urgent item
            cols[k].push(it);
            total++;
        });
        if (!total) {
            list.hidden = true;
            list.innerHTML = '';
            count.hidden = true;
            status.hidden = false;
            status.textContent = EMPTY;
            return;
        }
        status.hidden = true;
        list.innerHTML = order.map(function (k) { return column(k, cols[k]); }).join('');
        list.hidden = false;
        count.textContent = String(total);
        count.hidden = false;
    }

    function take(d) {
        if (!d || !d.ok) return false;
        got = true;
        // Everything Charlie ranked plus each head's own top items, one list by score.
        var seen = {}, all = [d.one].concat(d.rest || []);
        (Array.isArray(d.heads) ? d.heads : Object.keys(d.heads || {}).map(function (k) { return d.heads[k]; }))
            .forEach(function (h) { all = all.concat((h && h.items) || []); });
        items = all.filter(function (it) {
            if (!it || !it.key || seen[it.key]) return false;
            return (seen[it.key] = true);
        }).sort(function (a, b) { return (b.score || 0) - (a.score || 0); });
        render();
        return true;
    }

    window.addEventListener('mw:charlie-today', function (e) { take(e && e.detail); });
    if (!take(window.MW_CHARLIE_TODAY)) {
        setTimeout(function () {
            if (got) return;
            fetch(API + '?mode=today', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (got) return;
                    if (!take(d)) status.textContent = (d && d.error) || 'Charlie can\'t reach the team right now.';
                })
                .catch(function () { if (!got) status.textContent = 'Charlie can\'t reach the team right now. Try a refresh.'; });
        }, WAIT_MS);
    }

    list.addEventListener('click', function (e) {
        var li = e.target.closest('.mw-ab-row');
        if (!li) return;
        var key = li.getAttribute('data-key');
        var open = e.target.closest('[data-ab-open]');
        var later = e.target.closest('[data-ab-snooze]');
        if (open) {
            // Count it as "you went to it", then go — a failed save never blocks the link.
            e.preventDefault();
            var href = open.getAttribute('href');
            if (busy) return;
            busy = true;
            post({ mode: 'act', key: key, what: 'open' })
                .catch(function () {})
                .then(function () { window.location.href = href; });
            return;
        }
        if (later) {
            if (busy) return;
            busy = true;
            later.disabled = true;
            post({ mode: 'act', key: key, what: 'snooze' })
                .then(function (r) {
                    if (r && r.ok === false) throw new Error(r.message || 'not saved');
                    gone[key] = true;
                    msg.textContent = (r && r.message) || 'Back tomorrow.';
                    render();
                })
                .catch(function () {
                    later.disabled = false;
                    msg.textContent = 'That didn\'t save. Try again.';
                })
                .then(function () { busy = false; });
        }
    });

    // "Move to…" on a customer message: it goes to that head and Charlie's team learns the sender.
    list.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-ab-move]');
        if (!sel || !sel.value) return;
        var li = sel.closest('.mw-ab-row');
        var key = li && li.getAttribute('data-key');
        if (!key || busy) { sel.value = ''; return; }
        busy = true;
        sel.disabled = true;
        fetch(ROUTE_API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                           body: JSON.stringify({ mode: 'move', key: key, to: sel.value, csrf_token: window.MW_CSRF_TOKEN || '' }) })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (!r || r.ok === false) throw new Error((r && (r.message || r.error)) || 'not moved');
                gone[key] = true;
                msg.textContent = r.message || 'Moved.';
                render();
            })
            .catch(function (err) {
                sel.disabled = false;
                sel.value = '';
                msg.textContent = (err && err.message && err.message !== 'not moved') ? err.message : 'That didn\'t move. Try again.';
            })
            .then(function () { busy = false; });
    });
})();
