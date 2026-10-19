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
    // Ittai design only (action-board.php prints it when the it_design choice is on): "Today with the
    // office" — the mockup made live. Charlie in the centre of the hexagon with his three, then a carousel
    // to the next head with their three (the next head takes the centre, their side lit). Same items,
    // same Open / Not now / Move to as the columns, so Charlie keeps learning.
    var hero = root.querySelector('[data-ab-hero]');
    var gi = 0, ti = 0;   // which group (Charlie's three, then a head's three) and which of its tabs

    // Who each head is on the board. Unknown heads (and the Work Queue) are Charlie's.
    var HEADS = {
        charlie: { name: 'Charlie', face: 'charlie', role: 'Chief of Staff' },
        penny:   { name: 'Penny',   face: 'penny',   role: 'Bookkeeper' },
        sam:     { name: 'Sam',     face: 'sam',     role: 'Sales' },
        otto:    { name: 'Otto',    face: 'otto',    role: 'Operations' },
        mia:     { name: 'Mia',     face: 'mia',     role: 'Marketing' },
        yui:     { name: 'Yui',     face: 'yui',     role: 'Comms' },
        ada:     { name: 'Ada',     face: 'ada',     role: 'Technology' },
        house:   { name: 'Charlie', face: 'charlie', role: 'Chief of Staff' }
    };
    var SEAT = {
        yui:   { kanji: '結', role: 'Communications', cx: 210, cy: 30,  tx: 240, ty: 26,  anchor: 'start', side: [167, 74.7, 253, 74.7] },
        sam:   { kanji: '商', role: 'Sales',          cx: 330, cy: 98,  tx: 360, ty: 94,  anchor: 'start', side: [271, 85.1, 314, 159.6] },
        penny: { kanji: '計', role: 'Finance',        cx: 330, cy: 242, tx: 360, ty: 238, anchor: 'start', side: [314, 180.4, 271, 254.9] },
        otto:  { kanji: '運', role: 'Operations',     cx: 210, cy: 310, tx: 240, ty: 306, anchor: 'start', side: [253, 265.3, 167, 265.3] },
        mia:   { kanji: '広', role: 'Marketing',      cx: 90,  cy: 242, tx: 60,  ty: 238, anchor: 'end',   side: [149, 254.9, 106, 180.4] },
        ada:   { kanji: '技', role: 'Technology',     cx: 90,  cy: 98,  tx: 60,  ty: 94,  anchor: 'end',   side: [106, 159.6, 149, 85.1] }
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
            +   (movable(String(it.key)) ? '<div class="mw-ab-movebar">' + moveMenu(it) + '</div>' : '')
            +   '<div class="mw-ab-foot">'
            +     (w ? '<span class="mw-ab-wait" title="How long this has been waiting">' + esc(w) + '</span>' : '<span></span>')
            +     '<span class="mw-ab-acts">'
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
            +   '<a class="mw-ab-brain" href="/crm/brain.php?head=' + esc(h.face) + '" title="Open ' + esc(h.name) + '\'s brain">'
            +     '<img class="mw-ab-face" src="/crm/img/heads/' + esc(h.face) + '.jpg" alt="' + esc(h.name) + '\'s brain" width="72" height="72" loading="lazy"></a>'
            +   '<div><b>' + esc(h.name) + '</b><span>' + esc(h.role) + '</span></div>'
            +   '<em class="mw-ab-n">' + its.length + '</em>'
            + '</div>'
            + '<ul class="mw-ab-items">' + its.slice(0, PER_COL).map(function (it, i) { return row(it, i === 0); }).join('') + '</ul>'
            + (more > 0 ? '<div class="mw-ab-more">+' + more + ' more</div>' : '')
            + '</li>';
    }

    // ── Today with the office (Ittai) ──────────────────────────────────────────────────────────
    /** Charlie's three (the top of his ranking, any head), then each head's own three, most urgent first. */
    function groups(live) {
        var out = [{ lead: 'charlie', its: live.slice(0, 3) }];
        var used = {}, by = {}, order = [];
        out[0].its.forEach(function (it) { used[it.key] = true; });
        live.forEach(function (it) {
            if (used[it.key]) return;
            var k = headKey(it.head);
            if (!by[k]) { by[k] = []; order.push(k); }
            by[k].push(it);
        });
        order.forEach(function (k) { out.push({ lead: k, its: by[k].slice(0, 3), all: by[k].length }); });
        return out.filter(function (g) { return g.its.length; });
    }
    function face(k, cls) {
        var s = SEAT[k];
        return '<span class="it-tc-face ' + (cls || '') + '"><img src="/crm/img/heads/' + esc(HEADS[k].face) + '.jpg" alt="" width="36" height="36">'
            + '<span class="it-tc-kanji" aria-hidden="true">' + (s ? s.kanji : '統') + '</span></span>';
    }
    function hexSvg(lead, sel, waiting) {
        var h = '<defs><clipPath id="it-hx-c"><circle cx="210" cy="170" r="26"/></clipPath>';
        Object.keys(SEAT).forEach(function (k) { var s = SEAT[k]; h += '<clipPath id="it-hx-' + k + '"><circle cx="' + s.cx + '" cy="' + s.cy + '" r="18"/></clipPath>'; });
        h += '</defs><g class="it-hx-spoke"><line x1="210" y1="140" x2="210" y2="83"/><line x1="236" y1="155" x2="285" y2="127"/><line x1="236" y1="185" x2="285" y2="213"/>'
            + '<line x1="210" y1="200" x2="210" y2="257"/><line x1="184" y1="185" x2="135" y2="213"/><line x1="184" y1="155" x2="135" y2="127"/></g>';
        var on = [];
        Object.keys(SEAT).forEach(function (k) {
            var s = SEAT[k], st = (k === sel || k === lead) ? 'done' : (waiting[k] ? 'wait' : 'off');
            h += '<line class="it-hx-side is-' + st + '" x1="' + s.side[0] + '" y1="' + s.side[1] + '" x2="' + s.side[2] + '" y2="' + s.side[3] + '"/>';
            if (st !== 'off') on.push(HEADS[k].name + (st === 'done' ? ' (has this ready)' : ' (' + waiting[k] + ' waiting)'));
        });
        var c = HEADS[lead] || HEADS.charlie;
        h += '<image href="/crm/img/heads/' + esc(c.face) + '.jpg" x="184" y="144" width="52" height="52" clip-path="url(#it-hx-c)" preserveAspectRatio="xMidYMid slice"/>'
            + '<circle class="it-hx-centre" cx="210" cy="170" r="28"/>'
            + '<circle class="it-hx-badge" cx="232" cy="192" r="10"/><text class="it-hx-k" x="232" y="196" text-anchor="middle">' + (SEAT[lead] ? SEAT[lead].kanji : '統') + '</text>'
            + '<text class="it-hx-lbl it-hx-meta" x="210" y="222" text-anchor="middle">' + esc(c.name) + ' · ' + esc(SEAT[lead] ? SEAT[lead].role : 'Chief of Staff') + '</text>';
        Object.keys(SEAT).forEach(function (k) {
            var s = SEAT[k], st = (k === sel || k === lead) ? 'done' : (waiting[k] ? 'wait' : 'off');
            h += '<image href="/crm/img/heads/' + k + '.jpg" x="' + (s.cx - 18) + '" y="' + (s.cy - 18) + '" width="36" height="36" clip-path="url(#it-hx-' + k + ')" preserveAspectRatio="xMidYMid slice"/>'
                + '<circle class="it-hx-ring is-' + st + '" cx="' + s.cx + '" cy="' + s.cy + '" r="18.5"/>'
                + '<circle class="it-hx-badge" cx="' + (s.cx + 15) + '" cy="' + (s.cy + 15) + '" r="10"/>'
                + '<text class="it-hx-k" x="' + (s.cx + 15) + '" y="' + (s.cy + 19) + '" text-anchor="middle">' + s.kanji + '</text>'
                + '<text class="it-hx-lbl it-hx-name' + (st !== 'off' ? ' is-on' : '') + '" x="' + s.tx + '" y="' + s.ty + '" text-anchor="' + s.anchor + '">' + esc(HEADS[k].name) + '</text>'
                + '<text class="it-hx-lbl it-hx-role is-' + st + '" x="' + s.tx + '" y="' + (s.ty + 16) + '" text-anchor="' + s.anchor + '">'
                +   esc(s.role) + (waiting[k] ? ' · ' + waiting[k] : '') + '</text>';
        });
        return { svg: h, label: c.name + ' in the centre. ' + (on.length ? 'On this: ' + on.join(', ') + '.' : '') };
    }
    /** "Penny's 3", or "Charlie's next 2" for his items beyond today's three. */
    function groupName(g, i) {
        return HEADS[g.lead].name + '\'s ' + (g.lead === 'charlie' && i > 0 ? 'next ' : '') + g.its.length;
    }
    function renderHero(live) {
        if (!hero) return;
        if (!live.length) { hero.hidden = true; return; }
        var gs = groups(live);
        if (gi >= gs.length) gi = gs.length - 1;
        var g = gs[gi];
        if (ti >= g.its.length) ti = g.its.length - 1;
        var it = g.its[ti], k = headKey(it.head), u = safeUrl(it.url), w = waited(it);
        var waiting = {};
        live.forEach(function (x) { var hk = headKey(x.head); if (SEAT[hk]) waiting[hk] = (waiting[hk] || 0) + 1; });
        var hx = hexSvg(g.lead, k, waiting);
        var svg = hero.querySelector('[data-tc-hex]');
        svg.innerHTML = hx.svg;
        svg.setAttribute('aria-label', hx.label);

        // Phone: the hexagon's labels would fall under 12 px, so the heads on this are listed under it.
        var onthis = '<li>' + face(g.lead) + '<b>' + esc(HEADS[g.lead].name) + '</b><span>' + esc(SEAT[g.lead] ? SEAT[g.lead].role : 'Chief of Staff') + '</span><small class="is-done">in the centre</small></li>';
        Object.keys(SEAT).forEach(function (hk) {
            if (hk === g.lead || !(waiting[hk] || hk === k)) return;
            onthis += '<li>' + face(hk) + '<b>' + esc(HEADS[hk].name) + '</b><span>' + esc(SEAT[hk].role) + '</span>'
                + '<small class="' + (hk === k ? 'is-done">has this ready' : 'is-wait">' + waiting[hk] + ' waiting') + '</small></li>';
        });
        hero.querySelector('[data-tc-onthis]').innerHTML = onthis;

        var leadName = HEADS[g.lead].name;
        hero.querySelector('[data-tc-eyebrow]').textContent = g.lead === 'charlie' && gi === 0
            ? 'Charlie · ranked by what happens if it waits' : leadName + ' · ' + (SEAT[g.lead] ? SEAT[g.lead].role : 'Chief of Staff');
        var n = g.its.length, word = ['one', 'two', 'three'][n - 1];
        hero.querySelector('[data-tc-h]').textContent = gi === 0
            ? (n === 1 ? 'One thing needs' : (n === 2 ? 'Two things need' : 'Three things need')) + ' you today'
            : leadName + '\'s ' + (g.lead === 'charlie' ? 'next ' : '') + word;
        hero.querySelector('[data-tc-tabs]').innerHTML = g.its.map(function (x, i) {
            var xk = headKey(x.head), xw = waited(x);
            return '<button type="button" class="it-tc-tab" data-tc-tab="' + i + '" aria-pressed="' + (i === ti) + '">'
                + '<b>' + esc(x.text) + '</b><small>' + esc(HEADS[xk].name) + (xw ? ' · ' + esc(xw) : '') + '</small></button>';
        }).join('');

        var next = gs[gi + 1], prev = gs[gi - 1];
        hero.querySelector('[data-tc-next]').innerHTML = next
            ? '<button type="button" class="it-tc-tab it-tc-step" data-tc-go="1">' + face(next.lead) + '<span><b>' + esc(groupName(next, gi + 1)) + '</b><small>Up next · ' + (gi + 2) + ' of ' + gs.length + '</small></span></button>'
            : '<span class="it-tc-end">That\'s everyone.</span>';
        hero.querySelector('[data-tc-prev]').innerHTML = prev
            ? '<button type="button" class="it-tc-back" data-tc-go="-1">‹ ' + (gi === 1 ? 'Charlie\'s three' : esc(groupName(prev, gi - 1))) + '</button>' : '';

        var ready = SEAT[k] ? HEADS[k].name : 'Charlie';
        hero.querySelector('[data-tc-card]').innerHTML =
            '<div class="mw-ab-row it-tc-item" data-key="' + esc(it.key) + '">'
            + '<div class="it-tc-top">'
            +   '<span class="it-tc-tag">' + esc(ready) + ' has this ready' + (w ? ' · waiting ' + esc(w) : '') + '</span>'
            +   '<p class="it-tc-text">' + esc(it.text) + '</p>'
            +   (movable(String(it.key)) ? '<div class="mw-ab-movebar">' + moveMenu(it) + '</div>' : '')
            +   '<div class="it-tc-acts">'
            +     (u ? '<a class="btn btn-primary mw-ab-go it-tc-go" href="' + esc(u) + '" data-ab-open>' + esc(label(it)) + '</a>' : '')
            +     '<button type="button" class="mw-ab-later it-tc-later" data-ab-snooze title="Bring it back tomorrow">Not now</button>'
            +   '</div>'
            + '</div>'
            + '<ol class="it-tc-stages" aria-label="Who does what">'
            +   '<li class="it-tc-stage"><div class="it-tc-stage-top">' + face(SEAT[k] ? k : 'charlie', 'is-done') + '<i class="it-tc-solid" aria-hidden="true"></i></div>'
            +     '<b>Ready</b><small>' + esc(ready) + ' · <span class="is-done">done</span></small></li>'
            +   '<li class="it-tc-stage"><div class="it-tc-stage-top"><span class="it-tc-you">You</span></div>'
            +     '<b>Your decision</b><small>You · <span class="is-wait">now</span></small></li>'
            + '</ol></div>';
        var rest = live.length - g.its.length;
        hero.querySelector('[data-tc-note]').textContent = rest > 0 ? rest + ' more ' + (rest === 1 ? 'is' : 'are') + ' with the team below.' : '';
        hero.hidden = false;
    }

    function render() {
        renderHero(items.filter(function (it) { return it && it.key && !gone[it.key]; }));
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

    root.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-tc-tab]'), go = e.target.closest('[data-tc-go]');
        if (tab || go) {
            if (tab) ti = parseInt(tab.getAttribute('data-tc-tab'), 10) || 0;
            else { gi = Math.max(0, gi + (parseInt(go.getAttribute('data-tc-go'), 10) || 0)); ti = 0; }
            render();
            return;
        }
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
    root.addEventListener('change', function (e) {
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
