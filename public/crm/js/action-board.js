/**
 * Action Board — "Needs you now" on the dashboard (includes/action-board.php).
 *
 * Uses Charlie's already-ranked list: charlie-card.js stores it on window.MW_CHARLIE_TODAY
 * and fires 'mw:charlie-today'. If neither arrives within WAIT_MS (Charlie's card isn't on
 * the page, or it's slow), the board asks /crm/api/charlie.php?mode=today itself.
 * Buttons call Charlie's own actions so he keeps learning: Open → what:'open' then go;
 * Not now → what:'snooze' (back tomorrow) and the row leaves.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-action-board');
    if (!root) return;

    var API = '/crm/api/charlie.php';
    var MAX = 6;
    var PER_HEAD = 2;
    var WAIT_MS = 6000;
    var status = root.querySelector('[data-ab-status]');
    var list = root.querySelector('[data-ab-rows]');
    var count = root.querySelector('[data-ab-count]');
    var msg = root.querySelector('[data-ab-msg]');
    var EMPTY = 'Nothing needs you right now. Charlie is watching.';

    // Who each head is on the board. Unknown heads (and the Work Queue) are Charlie's.
    var HEADS = {
        charlie: { name: 'Charlie', face: 'charlie' },
        penny:   { name: 'Penny',   face: 'penny' },
        sam:     { name: 'Sam',     face: 'sam' },
        otto:    { name: 'Otto',    face: 'otto' },
        mia:     { name: 'Mia',     face: 'mia' },
        house:   { name: 'Charlie', face: 'charlie' }
    };

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
    function head(slug) {
        var key = String(slug || '').toLowerCase();
        return HEADS[key] || HEADS.charlie;
    }
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

    function row(it) {
        var h = head(it.head);
        var u = safeUrl(it.url);
        var w = waited(it);
        return '<li class="mw-ab-row" data-key="' + esc(it.key) + '">'
            + '<img class="mw-ab-face" src="/crm/img/heads/' + esc(h.face) + '.jpg" alt="" width="56" height="56" loading="lazy">'
            + '<div class="mw-ab-body">'
            +   '<div class="mw-ab-who"><b>' + esc(h.name) + '</b>'
            +     (w ? '<span class="mw-ab-wait" title="How long this has been waiting">' + esc(w) + '</span>' : '') + '</div>'
            +   '<div class="mw-ab-text">' + esc(it.text) + '</div>'
            + '</div>'
            + '<div class="mw-ab-acts">'
            +   (u ? '<a class="btn btn-sm btn-primary mw-ab-go" href="' + esc(u) + '" data-ab-open>' + esc(label(it)) + '</a>' : '')
            +   '<button type="button" class="mw-ab-later" data-ab-snooze title="Bring it back tomorrow">Not now</button>'
            + '</div>'
            + '</li>';
    }

    function render() {
        // Charlie's order, but at most PER_HEAD rows from any one head — so 50 of Otto's timers
        // can't push Mia's "yes, please do it" replies off the board.
        var perHead = {}, show = [];
        items.forEach(function (it) {
            if (!it || !it.key || gone[it.key] || show.length >= MAX) return;
            var h = it.head || '?';
            if ((perHead[h] = (perHead[h] || 0) + 1) > PER_HEAD) return;
            show.push(it);
        });
        if (!show.length) {
            list.hidden = true;
            list.innerHTML = '';
            count.hidden = true;
            status.hidden = false;
            status.textContent = EMPTY;
            return;
        }
        status.hidden = true;
        list.innerHTML = show.map(row).join('');
        list.hidden = false;
        var left = items.filter(function (it) { return it && it.key && !gone[it.key]; }).length;
        count.textContent = left > show.length ? show.length + ' of ' + left : String(left);
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
})();
