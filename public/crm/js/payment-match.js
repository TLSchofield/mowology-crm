/**
 * Payments ↔ invoices (/crm/accounting/payment-match.php) — one deposit at a time, Penny narrating.
 * Reads /crm/api/payment-match.php?mode=scan. Books only what Tim approves: the browser sends
 * deposit ids + signatures (or a deposit + the items he ticked); the server rebuilds the match
 * and refuses anything that changed. Every booking can be undone from the history.
 * 2026-10-07 first version (everything on one page); 2026-10-25 deck redesign (Ittai v1.2).
 * The API calls, signatures and booking rules are unchanged; only the rendering is new.
 */
(function () {
    'use strict';
    var API = '/crm/api/payment-match.php';
    var ORDER = ['high', 'medium', 'low', 'needs_you'];
    var KIND = { crm_open: 'CRM · owing', crm_paid: 'CRM · paid, not linked', crm_rec: 'CRM · payment recorded', jb_inv: 'Jobber · owing', jb_pay: 'Jobber · payment' };
    var ICON = {
        ok: '<svg class="it-pm-ico" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10.5l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        warn: '<svg class="it-pm-ico" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3l8 14H2L10 3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 8v4M10 14.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        block: '<svg class="it-pm-ico" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5.5 14.5l9-9" stroke="currentColor" stroke-width="1.8"/></svg>',
        info: '<svg class="it-pm-ico" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 9v5M10 6v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
    };
    var WORD = { ok: 'Matches.', warn: 'Check.', block: 'Blocked.', info: 'Note.' };

    var data = null;
    var cur = null;            // id of the deposit on screen
    var seen = [];             // ids already viewed this visit, for Back
    var deferred = [];         // "Not now" — moved to the end of the queue, this visit only
    var pick = null;           // { id, targets: {key: amount} } — hand-pick mode
    var flash = null;          // { msg, kind } — Penny's last line after an action
    var doneCount = 0;         // deposits settled this visit, for the progress bar
    var startLeft = null;
    var busy = false;          // one booking request at a time

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) { var n = Number(v || 0); return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function signed(v) { var n = Number(v || 0); return (n > 0 ? '+' : '') + money(n); }
    function nid(v) { return Number(v) || 0; }
    function cents(v) { return Math.round(Number(v || 0) * 100); }
    function r2(v) { return Math.round(Number(v || 0) * 100) / 100; }
    function sentence(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }
    function title(s) { return String(s || '').toLowerCase().replace(/(^|[\s(&-])([a-z])/g, function (m, a, b) { return a + b.toUpperCase(); }); }
    function say(msg, kind) { flash = msg ? { msg: msg, kind: kind || 'info' } : null; renderPenny(); }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function bookable(l) { return ['high', 'medium', 'low'].indexOf(l.outcome) >= 0 && !l.locked && !l.blocked; }
    function line(id) { return data.lines.find(function (l) { return l.id === id; }); }

    /* ── What an approval changes (pure; unit-checked against real scan lines) ───────────── */
    function impact(l) {
        var inv = (l.targets || []).map(function (t) {
            var open = t.kind === 'crm_open' || t.kind === 'jb_inv';
            var owing = Number(t.owing), paid = Number(t.amount);
            var left = open ? Math.max(0, r2(owing - paid)) : 0;
            return {
                number: t.number, payer: t.payer, kind: t.kind, open: open, owing: owing, paid: paid, left: left,
                pct: open && owing > 0 ? Math.min(100, Math.round(paid / owing * 1000) / 10) : 100,
                full: left < 0.005, old: !!(t.year && t.year < '2026')
            };
        });
        var ar = inv.filter(function (i) { return i.kind === 'crm_open'; }).reduce(function (a, i) { return a + i.paid; }, 0);
        return { invoices: inv, ar: ar ? -r2(ar) : 0, income: r2(l.income_delta), fy2025: r2(l.fy2025_part), action: l.action, amount: Number(l.amount) };
    }

    /* ── Penny's words ──────────────────────────────────────────────────────────────────── */
    function who(l) {
        var t = (l.targets || [])[0];
        if (!t) return '';
        var n = (l.targets || []).length;
        return title(t.payer || '') + (n > 1 ? ' (' + n + ' invoices)' : '');
    }
    function headline(l) {
        if (l.locked) return 'I can\'t book this one: its month is locked.';
        if (l.blocked) return 'I can\'t book this one yet.';
        if (l.outcome === 'needs_you') {
            return (l.alternatives || []).length ? 'This one could be paying more than one client.' : 'I couldn\'t match this one.';
        }
        var w = who(l) || 'one invoice';
        if (l.outcome === 'high') return 'I\'m confident this is ' + w + '.';
        if (l.outcome === 'medium') return 'This looks like ' + w + ', but please check.';
        return 'This might be ' + w + '. I\'m not sure.';
    }
    function sub(l) {
        if (l.locked || l.blocked) return '';
        if (l.outcome === 'needs_you' && l.remittance && l.remittance.state === 'settled') return 'The remittance names an invoice that is not open. Check it before booking anything.';
        if (l.outcome === 'needs_you') return 'Nothing adds up to the cent. You can pick the invoices it paid, or leave it alone.';
        if (l.outcome === 'high') return '';
        if (l.outcome === 'medium') return 'It adds up to the cent, but the memo doesn\'t say who paid.';
        return 'Several payers could fit, or the dates are far apart.';
    }
    function evidence(l) {
        var ev = [];
        var nums = (l.targets || []).map(function (t) { return t.number; }).join(', ');
        if (l.named) ev.push({ k: 'ok', t: sentence(l.named) + '.' });
        if (nums && !l.partial && bookable(l)) ev.push({ k: 'ok', t: money(l.amount) + ' adds up to ' + esc(nums) + ' to the cent.', raw: true });
        if (l.partial && !/Part-payment/.test(l.note || '')) ev.push({ k: 'warn', t: 'This is a part-payment. Part of the invoice will still be owing.' });
        if (l.note) ev.push({ k: 'warn', t: l.note });
        (l.flags || []).forEach(function (f) { ev.push({ k: 'warn', t: f }); });
        if (l.flag) ev.push({ k: 'warn', t: l.flag });
        if (l.locked) ev.push({ k: 'block', t: 'That month is locked. Nothing can be booked on it.' });
        if (l.blocked) ev.push({ k: 'block', t: l.blocked });
        if (l.outcome === 'needs_you' && l.why) ev.push({ k: 'info', t: l.why });
        if (l.skip_note) ev.push({ k: 'info', t: 'You left this earlier: ' + l.skip_note });
        return ev;
    }
    function evList(ev) {
        return '<ul class="it-pm-ev">' + ev.map(function (e) {
            return '<li class="is-' + e.k + '">' + ICON[e.k] + '<span><span class="it-pm-ev-word">' + WORD[e.k] + '</span>' + (e.raw ? e.t : esc(e.t)) + '</span></li>';
        }).join('') + '</ul>';
    }

    /* ── Queue ──────────────────────────────────────────────────────────────────────────── */
    function queue() {
        var q = data.lines.filter(function (l) { return ORDER.indexOf(l.outcome) >= 0; });
        q.sort(function (a, b) {
            var da = deferred.indexOf(a.id) >= 0 ? 1 : 0, db = deferred.indexOf(b.id) >= 0 ? 1 : 0;
            if (da !== db) return da - db;
            var oa = ORDER.indexOf(a.outcome), ob = ORDER.indexOf(b.outcome);
            return oa !== ob ? oa - ob : (a.date < b.date ? -1 : a.date > b.date ? 1 : a.id - b.id);
        });
        return q;
    }

    /* ── Render ─────────────────────────────────────────────────────────────────────────── */
    function render() {
        var q = queue();
        if (cur == null || !q.some(function (l) { return l.id === cur; })) cur = q.length ? q[0].id : null;
        if (startLeft == null) startLeft = q.length;
        renderPenny(q); renderBatch(q); renderDeck(q); renderHistory();
    }

    function renderPenny(q) {
        q = q || (data ? queue() : []);
        var el = $('pm-penny');
        if (!data) { el.innerHTML = '<img class="it-pm-face" src="/crm/img/heads/penny.jpg" alt="Penny"><div class="it-pm-say"><p class="it-pm-say-line">Reading the bank lines and every open invoice…</p></div>'; return; }
        var total = q.reduce(function (a, l) { return a + l.amount; }, 0);
        var line1, sub1, cls = '';
        if (flash) { line1 = flash.msg; cls = flash.kind === 'success' ? ' is-good' : flash.kind === 'danger' ? ' is-bad' : ''; sub1 = q.length ? q.length + ' left, ' + money(total) + '.' : ''; }
        else if (!data.ready) { line1 = 'Run migration 1260 before booking.'; sub1 = 'The matching below is read-only until then.'; cls = ' is-bad'; }
        else if (!q.length) { line1 = 'All caught up. Every 2026 deposit is tied to what it paid.'; sub1 = ''; cls = ' is-good'; }
        else { line1 = 'I went through your 2026 deposits and matched what I could.'; sub1 = q.length + ' left, ' + money(total) + '. Nothing is booked until you approve it.'; }
        var done = doneCount, all = done + q.length;
        $('pm-penny').innerHTML = '<img class="it-pm-face" src="/crm/img/heads/penny.jpg" alt="Penny">' +
            '<div class="it-pm-say' + cls + '" role="status" aria-live="polite"><p class="it-pm-say-line">' + esc(line1) + '</p>' + (sub1 ? '<p class="it-pm-say-sub">' + esc(sub1) + '</p>' : '') +
            (all > 0 ? '<div class="it-pm-progress" aria-hidden="true"><span style="width:' + Math.round(done / all * 100) + '%"></span></div>' : '') + '</div>';
    }

    function renderBatch(q) {
        var hi = q.filter(function (l) { return l.outcome === 'high' && bookable(l); });
        var el = $('pm-batch');
        if (pick || hi.length < 2) { el.innerHTML = ''; return; }
        var tot = hi.reduce(function (a, l) { return a + l.amount; }, 0);
        el.innerHTML = '<div class="it-pm-batch"><span><b>' + hi.length + ' high-confidence matches</b> <span class="it-pm-meta">(' + money(tot) + ') — the memo names the payer and it adds up exactly.</span></span>' +
            '<button type="button" class="it-pm-btn" data-pm="all" data-group="high">Approve these ' + hi.length + ' together</button></div>';
    }

    function impactHtml(l) {
        var im = impact(l);
        var rows = '';
        var flow = im.invoices.map(function (i) {
            var state = !i.open ? '<span class="it-pm-inv-state">' + ICON.ok + 'Matched to the bank</span>'
                : i.full ? '<span class="it-pm-inv-state">' + ICON.ok + 'Paid in full</span>'
                : '<span class="it-pm-inv-state is-part">' + ICON.warn + money(i.left) + ' still owing</span>';
            var bar = i.open ? '<div class="it-pm-bar" data-pct="' + i.pct + '" style="--pct:' + i.pct + '%"><i></i></div>' +
                '<div class="it-pm-inv-nums"><span>Owing now <b>' + money(i.owing) + '</b></span><span>This deposit pays <b>' + money(i.paid) + '</b></span><span>Owing after <b>' + money(i.left) + '</b></span></div>'
                : '<div class="it-pm-inv-nums"><span>' + esc(KIND[i.kind] || i.kind) + ' · <b>' + money(i.paid) + '</b></span></div>';
            return '<div class="it-pm-inv"><div class="it-pm-inv-top"><span class="it-pm-inv-name">' + esc(i.number) + ' · ' + esc(title(i.payer)) +
                (i.old ? ' <span class="it-pm-tag">2025 invoice</span>' : '') + '</span>' + state + '</div>' + bar + '</div>';
        }).join('');
        if (im.ar < 0) rows += ledger('Money owed to you', money(im.ar), 'is-good', 'Invoices paid off by this deposit.');
        if (l.action === 'record_payment') rows += ledger('2026 income', 'No change', 'is-flat', 'It moves from the bank line onto the invoice.');
        else if (im.income !== 0) rows += ledger('2026 income', money(im.income), 'is-good', l.action === 'jobber' ? 'That part belongs to 2025, not this year.' : 'It was counted on the bank line and on the invoice. Now it counts once.');
        else if (l.action === 'jobber') rows += ledger('2026 income', 'No change', 'is-flat', 'Income stays on the deposit.');
        if (im.fy2025 > 0) rows += ledger('2025 receivable settled', money(im.fy2025), 'is-good', 'Settles what 2025 was still owed.');
        rows += ledger('Bank deposit', 'Matched', 'is-good', 'No longer sitting unexplained.');
        return '<section class="it-pm-sec it-pm-impact"><h2>If you approve <span class="it-pm-impact-hint">What changes</span></h2>' +
            (flow ? '<div class="it-pm-flow">' + flow + '</div>' : '') + '<ul class="it-pm-ledger">' + rows + '</ul>' +
            '<p class="it-pm-undo">You can undo this any time from the history below.</p></section>';
    }
    function ledger(what, val, cls, why) {
        return '<li><span class="it-pm-ledger-what">' + esc(what) + '<span class="it-pm-ledger-why">' + esc(why) + '</span></span><span class="it-pm-delta ' + cls + '">' + esc(val) + '</span></li>';
    }

    function candHtml(l) {
        var c = (l.candidates || []).map(function (x) {
            return '<li class="is-info">' + ICON.info + '<span><b>' + esc(x.number) + '</b> ' + esc(title(x.payer)) + ' ' + money(x.amount) + '<span class="it-pm-gap">' + signed(x.diff) + '</span>' +
                '<span class="it-pm-meta"> · ' + esc(KIND[x.kind] || x.kind) + (x.named ? ' · ' + esc(x.named) : '') + '</span></span></li>';
        }).join('');
        var alts = (l.alternatives || []).map(function (x) {
            return '<li class="is-info">' + ICON.info + '<span><b>' + esc(x.number) + '</b> ' + esc(title(x.payer)) + ' <span class="it-pm-meta">would also add up to ' + money(x.amount) + '</span></span></li>';
        }).join('');
        if (!c && !alts) return '';
        return '<section class="it-pm-sec"><h2>' + (alts ? 'Who it could be' : 'Closest I found') + '</h2><ul class="it-pm-ev">' + alts + c + '</ul></section>';
    }

    function renderDeck(q) {
        var el = $('pm-deck');
        if (!data) { el.innerHTML = ''; return; }
        if (pick) { el.innerHTML = handHtml(); return; }
        var l = cur == null ? null : line(cur);
        if (!l) { el.innerHTML = '<div class="it-pm-deck"><div class="it-pm-done"><h2>Nothing left to review.</h2><p class="it-pm-meta">Anything you booked is in the history below, with an Undo.</p></div></div>'; return; }
        var ev = evidence(l), ok = bookable(l);
        var pos = q.findIndex(function (x) { return x.id === l.id; }) + 1;
        var primary = (l.locked || l.blocked) ? '' : ok ? '<button type="button" class="it-pm-btn it-pm-btn-primary" data-pm="one" data-id="' + nid(l.id) + '">Approve and book ' + money(l.amount) + '</button>'
            : '<button type="button" class="it-pm-btn it-pm-btn-primary" data-pm="pick" data-id="' + nid(l.id) + '">Pick the invoices it paid</button>';
        var secondary = '<div class="it-pm-row2"><div class="it-pm-left">' +
            (seen.length ? '<button type="button" class="it-pm-btn it-pm-btn-quiet" data-pm="back">Back</button>' : '') +
            '<button type="button" class="it-pm-btn it-pm-btn-quiet" data-pm="later" data-id="' + nid(l.id) + '">Not now</button></div><div class="it-pm-right">' +
            (ok ? '<button type="button" class="it-pm-btn" data-pm="pick" data-id="' + nid(l.id) + '">Pick invoices by hand</button>' : '') +
            '<button type="button" class="it-pm-btn" data-pm="skip" data-id="' + nid(l.id) + '">Leave it alone</button></div></div>';
        el.innerHTML = '<div class="it-pm-deck"><div class="it-pm-two it-pm-sec" style="padding:0">' +
            '<section class="it-pm-sec"><h2>The deposit <span class="it-pm-meta">(' + pos + ' of ' + q.length + ')</span></h2>' +
            '<div class="it-pm-dep-top"><span class="it-pm-amount it-pm-num">' + money(l.amount) + '</span><span class="it-pm-date">' + esc(l.date) + '</span></div>' +
            '<p class="it-pm-desc">' + esc(l.description) + '</p></section>' +
            '<section class="it-pm-sec"><h2>' + esc(headline(l)) + '</h2>' + (sub(l) ? '<p class="it-pm-meta" style="margin:0 0 10px">' + esc(sub(l)) + '</p>' : '') + (ev.length ? evList(ev) : '') + '</section></div>' +
            candHtml(l) + (ok ? impactHtml(l) : '') +
            (primary ? '<section class="it-pm-sec it-pm-act-sticky">' + primary + '</section>' : '') +
            '<section class="it-pm-sec">' + secondary + '</section></div>';
        requestAnimationFrame(function () { requestAnimationFrame(function () {
            Array.prototype.forEach.call(document.querySelectorAll('.it-pm-bar'), function (b) { b.classList.add('is-in'); });
        }); });
    }

    /* ── Hand-pick: the one place the full open-invoice list survives ───────────────────── */
    function openRows() {
        var f = ($('pm-filter') && $('pm-filter').value) || 'all', q = (($('pm-search') && $('pm-search').value) || '').toLowerCase().trim();
        var cov = {};
        data.open.forEach(function (o) { cov[o.key] = o; });
        var list = f === 'open' ? data.open : data.candidates;
        if (f === 'none') list = data.open.filter(function (o) { return o.status === 'none'; });
        if (q) list = list.filter(function (o) { return (o.number + ' ' + o.payer + ' ' + (o.names || []).join(' ')).toLowerCase().indexOf(q) >= 0; });
        return { list: list, cov: cov };
    }
    function handHtml() {
        var l = line(pick.id);
        var keys = Object.keys(pick.targets);
        var sum = keys.reduce(function (a, k) { return a + cents(pick.targets[k]); }, 0);
        var diff = cents(l.amount) - sum;
        var f = ($('pm-filter') && $('pm-filter').value) || 'all', q = ($('pm-search') && $('pm-search').value) || '';
        return '<div class="it-pm-deck"><div class="it-pm-hand-sum"><div><b>Picking for ' + money(l.amount) + '</b> <span class="it-pm-meta">' + esc(l.date) + ' · ' + esc(l.description) + '</span>' +
            '<div>Ticked ' + keys.length + ' · ' + money(sum / 100) + ' · <span class="it-pm-diff ' + (diff === 0 ? 'is-zero' : '') + '">' + (diff === 0 ? 'adds up exactly' : 'still ' + money(diff / 100) + ' to place') + '</span></div></div>' +
            '<div class="it-pm-right" style="display:flex;gap:8px"><button type="button" class="it-pm-btn" data-pm="cancel">Cancel</button>' +
            '<button type="button" class="it-pm-btn it-pm-btn-primary" style="width:auto" data-pm="manual"' + (diff === 0 && keys.length ? '' : ' disabled') + '>Book this</button></div></div>' +
            '<section class="it-pm-sec"><h2>Tick what this deposit paid</h2><div class="it-pm-filters">' +
            '<input type="search" class="it-pm-input" id="pm-search" placeholder="Client, strata plan, invoice #" aria-label="Filter invoices" value="' + esc(q) + '">' +
            '<select class="it-pm-input" id="pm-filter" aria-label="Which items"><option value="all"' + (f === 'all' ? ' selected' : '') + '>Everything a deposit can pay</option><option value="open"' + (f === 'open' ? ' selected' : '') + '>Open invoices</option><option value="none"' + (f === 'none' ? ' selected' : '') + '>Open, no money found</option></select></div>' +
            '<div id="pm-open">' + openHtml() + '</div></section></div>';
    }
    function openHtml() {
        var r = openRows();
        var rows = r.list.slice(0, 200).map(function (o) {
            var c = r.cov[o.key];
            var owing = o.owing != null ? o.owing : o.amount;
            var st = c ? c.status : '';
            var ticked = pick && pick.targets[o.key] != null;
            var partial = o.kind === 'crm_open' || o.kind === 'jb_inv';
            var bank = c && c.deposits.length ? c.deposits.map(function (d) { return esc(d.date) + ' ' + money(d.amount); }).join(', ') : '';
            return '<li class="' + (ticked ? 'is-ticked' : '') + '"><input type="checkbox" data-pm="tick" data-key="' + esc(o.key) + '"' + (ticked ? ' checked' : '') + ' aria-label="This deposit paid ' + esc(o.number) + '">' +
                '<div class="it-pm-list-main"><b>' + esc(o.number) + '</b> ' + esc(title(o.payer)) + '<div class="it-pm-meta">' + esc(KIND[o.kind] || o.kind) + (o.issued ? ' · ' + esc(o.issued) : (o.date ? ' · ' + esc(o.date) : '')) + '</div>' +
                (st === 'covered' ? '<span class="it-pm-tag is-in">In the bank</span>' : st === 'partly' ? '<span class="it-pm-tag">Part in the bank</span>' : st === 'none' ? '<span class="it-pm-tag">Not found in the bank</span>' : '') +
                (bank ? '<span class="it-pm-meta"> ' + bank + '</span>' : '') + '</div>' +
                '<div class="it-pm-list-amt">' + money(owing) + (ticked && partial ? '<br><input type="number" step="0.01" min="0.01" class="it-pm-input" data-pm="amt" data-key="' + esc(o.key) + '" value="' + Number(pick.targets[o.key]).toFixed(2) + '" aria-label="Amount paid">' : '') + '</div></li>';
        }).join('');
        return rows ? '<ul class="it-pm-list">' + rows + '</ul>' + (r.list.length > 200 ? '<p class="it-pm-meta">Showing 200 of ' + r.list.length + '. Narrow it with the search.</p>' : '') : '<p class="it-pm-empty">Nothing here.</p>';
    }

    /* ── History: what I booked (Undo) and what you left alone (Un-skip) ────────────────── */
    function renderHistory() {
        var el = $('pm-log');
        var booked = (data.log || []).map(function (r) {
            return '<li><div><b>' + money(r.amount) + '</b> <span class="it-pm-meta">' + esc((r.created_at || '').slice(0, 16)) + ' · bank line #' + nid(r.transaction_id) + '</span><div>' +
                esc((r.numbers || []).join(', ')) + (Number(r.fy2025_part) > 0 ? ' — ' + money(r.fy2025_part) + ' settles 2025' : '') + (r.note ? ' — ' + esc(r.note) : '') +
                ' <span class="it-pm-meta">(' + esc(r.status) + ')</span></div></div>' +
                (r.status === 'booked' ? '<button type="button" class="it-pm-btn" data-pm="undo" data-log="' + nid(r.id) + '">Undo</button>' : '') + '</li>';
        }).join('');
        var skipped = data.lines.filter(function (l) { return l.outcome === 'skipped'; }).map(function (l) {
            return '<li><div><b>' + money(l.amount) + '</b> <span class="it-pm-meta">' + esc(l.date) + ' · ' + esc(l.description) + '</span><div>' + esc(l.why) + '</div></div>' +
                '<button type="button" class="it-pm-btn" data-pm="unskip" data-id="' + nid(l.id) + '">Bring it back</button></li>';
        }).join('');
        var stripe = data.lines.filter(function (l) { return l.outcome === 'stripe'; });
        var stripeNote = stripe.length ? '<li><div><b>' + stripe.length + ' Stripe payout' + (stripe.length === 1 ? '' : 's') + '</b> <span class="it-pm-meta">' + money(stripe.reduce(function (a, l) { return a + l.amount; }, 0)) + '</span><div>The income clean-up books these from Stripe itself, so I leave them alone.</div></div></li>' : '';
        el.innerHTML = (booked || skipped || stripeNote) ? '<ul class="it-pm-hist">' + booked + skipped + stripeNote + '</ul>' : '<p class="it-pm-empty">Nothing booked yet.</p>';
    }

    /* ── Loading and booking (unchanged rules) ──────────────────────────────────────────── */
    function load(msg, kind) {
        return fetch(API + '?mode=scan', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'I couldn\'t read the bank lines.', 'danger'); return; }
                data = d;
                if (pick && !line(pick.id)) pick = null;
                flash = msg ? { msg: msg, kind: kind || 'info' } : null;
                render();
            })
            .catch(function () { say('I couldn\'t reach the server. Reload to see what was booked.', 'danger'); });
    }

    function approve(ids, label, confirmFirst) {
        var approved = {}, total = 0;
        ids.forEach(function (id) { var l = line(id); if (l && bookable(l)) { approved[id] = l.signature; total += l.amount; } });
        var n = Object.keys(approved).length;
        if (!n || busy) return;
        if (confirmFirst && !window.confirm('Book ' + n + ' deposit' + (n === 1 ? '' : 's') + ' (' + money(total) + ')' + (label ? ' — ' + label : '') + '?\nEach one is logged and can be undone.')) return;
        say('Booking ' + n + '…', 'info');
        busy = true;
        post({ mode: 'book', approved: approved }).then(function (r) {
            busy = false;
            if (r.error) { say(r.error, 'danger'); return; }
            doneCount += r.booked.length;
            var msg = 'Booked ' + r.booked.length + (r.booked.length === 1 ? ' deposit' : ' deposits') + '.';
            if (r.failed.length) msg += ' ' + r.failed.length + ' not booked: ' + r.failed.slice(0, 5).map(function (f) { return '#' + f.id + ' ' + f.message; }).join(' · ');
            load(msg, r.failed.length ? 'warning' : 'success');
        }).catch(function () { busy = false; say('I couldn\'t reach the server. Reload to see what was booked.', 'danger'); });
    }

    function rerenderPick(kind, key) {
        var list = document.querySelector('.it-pm-list'); var st = list ? list.scrollTop : 0;
        renderDeck(queue());
        list = document.querySelector('.it-pm-list'); if (list) list.scrollTop = st;
        var again = Array.prototype.find.call(document.querySelectorAll('[data-pm="' + kind + '"]'), function (x) { return x.getAttribute('data-key') === key; });
        if (again) again.focus();
    }

    function go(id) { if (cur != null && cur !== id) seen.push(cur); cur = id; }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pm]');
        if (!b || !data) return;
        var k = b.getAttribute('data-pm'), id = Number(b.getAttribute('data-id'));
        if (k === 'tick') {
            var key = b.getAttribute('data-key');
            var c = data.candidates.find(function (x) { return x.key === key; });
            if (b.checked && c) {
                var l = line(pick.id), sum = Object.keys(pick.targets).reduce(function (a, x) { return a + cents(pick.targets[x]); }, 0);
                var rest = (cents(l.amount) - sum) / 100;
                pick.targets[key] = c.partial_ok && rest > 0 && rest < c.amount ? rest : c.amount;
            } else delete pick.targets[key];
            rerenderPick('tick', key);
        } else if (k === 'all') {
            var g = b.getAttribute('data-group');
            approve(data.lines.filter(function (l) { return l.outcome === g && bookable(l); }).map(function (l) { return l.id; }), 'high confidence', true);
        } else if (k === 'one') {
            approve([id], '', false);
        } else if (k === 'pick') {
            pick = { id: id, targets: {} };
            render();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else if (k === 'cancel') {
            pick = null; render();
        } else if (k === 'back') {
            if (seen.length) { cur = seen.pop(); render(); }
        } else if (k === 'later') {
            deferred.push(id); seen = seen.filter(function (x) { return x !== id; }); cur = null; render();
        } else if (k === 'manual') {
            var targets = Object.keys(pick.targets).map(function (key) { return { key: key, amount: pick.targets[key] }; });
            if (busy) return;
            busy = true; b.disabled = true;
            post({ mode: 'manual', transaction_id: pick.id, targets: targets }).then(function (r) {
                busy = false; if (r.ok) { pick = null; doneCount += 1; load(r.message, 'success'); } else { b.disabled = false; say(r.message || r.error || 'I couldn\'t book that.', 'danger'); }
            }).catch(function () { busy = false; b.disabled = false; say('I couldn\'t reach the server. Reload to see what was booked.', 'danger'); });
        } else if (k === 'skip') {
            var note = window.prompt('Leave this deposit exactly as it is. Why? (e.g. "owner loan", "Jobber invoice not imported")', '');
            if (note === null) return;
            post({ mode: 'skip', transaction_id: id, note: note }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        } else if (k === 'unskip') {
            post({ mode: 'unskip', transaction_id: id }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        } else if (k === 'undo') {
            if (!window.confirm('Undo this booking? The deposit and the invoices go back to exactly how they were.')) return;
            post({ mode: 'undo', log_id: Number(b.getAttribute('data-log')) }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'pm-filter' && data) { $('pm-open').innerHTML = openHtml(); return; }
        var i = e.target.closest('[data-pm="amt"]');
        if (!i || !pick) return;
        pick.targets[i.getAttribute('data-key')] = Math.max(0, Number(i.value || 0));
        rerenderPick('amt', i.getAttribute('data-key'));
    });
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'pm-search' && data) $('pm-open').innerHTML = openHtml();
    });

    window.PaymentMatchImpact = impact;   // for the unit check
    renderPenny();
    load();
})();
