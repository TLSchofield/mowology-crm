/**
 * Review receipts (receipts-review_appstack.php) — draws the review layer from /crm/api/receipt-review.php.
 * The person ticks each receipt they checked; "Approve checked" approves only those. Duplicate sets show the copy in
 * the books first (green) with "Merge into #keeper" / "Not a duplicate" on each copy. After every action the list is
 * read again (ticks and scroll kept), so the page always shows what is in the books. Never throws into the page.
 */
(function () {
    'use strict';
    var root = document.getElementById('itReview');
    if (!root) return;
    var API = '/crm/api/receipt-review.php';
    var data = null, filter = 'all', ticked = {}, busy = false;
    var $ = function (sel) { return root.querySelector(sel); };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return '$' + Number(v || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function day(d) {
        if (!d) return '';
        var t = new Date(d + 'T12:00:00');
        return isNaN(t) ? d : t.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function toast(msg, kind) { if (typeof window.mwToast === 'function') window.mwToast(msg, kind); }
    function csrf() { return window.MW_CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }

    function load() {
        return fetch(API, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) throw new Error((d && d.error) || 'Could not load');
                data = d;
                var keep = {};
                d.groups.forEach(function (g) { g.rows.forEach(function (r) { if (ticked[r.id] !== undefined && !r.in_duplicate) keep[r.id] = r.total; }); });
                ticked = keep;
                render();
            })
            .catch(function (e) { $('[data-rv-groups]').innerHTML = '<p class="it-rv-sub">' + esc(e.message || 'Could not load the receipts.') + ' Reload to try again.</p>'; });
    }

    function post(body) {
        body.csrf_token = csrf();
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                            body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function checksHtml(checks) {
        return '<ul class="it-rv-checks">' + checks.map(function (c) {
            return '<li class="it-rv-check ' + (c.ok ? 'is-ok' : 'is-look') + '"><span class="it-rv-mark" aria-hidden="true">' + (c.ok ? '✓' : '!') + '</span>'
                + '<span><span class="it-rv-sr">' + (c.ok ? 'Agrees: ' : 'Needs a look: ') + '</span>' + esc(c.text) + '</span></li>';
        }).join('') + '</ul>';
    }

    function thumb(url, label) {
        if (!url) return '<span class="it-rv-thumb is-none">No photo</span>';
        return '<button type="button" class="it-rv-thumb" data-rv-open="' + esc(url) + '" aria-label="' + esc('Open the photo of ' + label) + '">'
            + '<img src="' + esc(url) + '&amp;thumb=1" alt="" loading="lazy" decoding="async"></button>';
    }

    function rowHtml(r, vendor) {
        var label = vendor + ' ' + money(r.total) + ' ' + day(r.date);
        var on = ticked[r.id] !== undefined;
        return '<li class="it-rv-row' + (on ? ' is-checked' : '') + '" data-rv-row="' + r.id + '">'
            + (r.in_duplicate
                ? '<span class="it-rv-tick" title="A suspected duplicate — merge it or mark it not a duplicate first"><span aria-hidden="true">—</span>'
                  + '<span class="it-rv-sr">Resolve the suspected duplicate before approving</span></span>'
                : '<label class="it-rv-tick"><input type="checkbox" data-rv-tick="' + r.id + '" data-total="' + r.total + '"' + (on ? ' checked' : '')
                  + ' aria-label="' + esc('I checked ' + label) + '"></label>')
            + thumb(r.photo, label)
            + '<div class="it-rv-main"><div class="it-rv-line1"><span class="it-rv-amount">' + money(r.total) + '</span>'
            + '<span class="it-rv-date">' + esc(day(r.date)) + ' · #' + r.id + (r.submitted_by ? ' · ' + esc(r.submitted_by) : '') + '</span>'
            + (r.all_pass ? '<span class="it-rv-pass">Passes every check</span>' : '') + '</div>'
            + checksHtml(r.checks)
            + (r.penny_notes ? '<details class="it-rv-note"><summary>Penny\'s note</summary><p>' + esc(r.penny_notes) + '</p></details>' : '')
            + '</div>'
            + '<div class="it-rv-actions"><a class="it-rv-btn" href="/crm/expenses_appstack.php?edit=' + r.id + '" target="_blank" rel="noopener"'
            + ' aria-label="' + esc('Fix ' + label + ' (opens the receipt)') + '">Fix</a></div>'
            + '</li>';
    }

    function shown(r) { return filter === 'all' || (filter === 'pass' ? r.all_pass : !r.all_pass); }

    function renderGroups() {
        var html = data.groups.map(function (g) {
            var rows = g.rows.filter(shown);
            if (!rows.length) return '';
            var passing = g.rows.filter(function (r) { return r.all_pass; });
            return '<section class="it-rv-group" aria-label="' + esc(g.vendor) + '"><div class="it-rv-group-head">'
                + '<h3 class="it-rv-vendor">' + esc(g.vendor) + '<small>' + g.rows.length + ' waiting · ' + passing.length + ' pass every check</small></h3>'
                + (passing.length ? '<button type="button" class="it-rv-btn it-rv-btn-quiet" data-rv-tick-group="' + esc(g.vendor) + '">Tick the ' + passing.length + ' that pass</button>' : '')
                + '</div><ul class="it-rv-rows">' + rows.map(function (r) { return rowHtml(r, g.vendor); }).join('') + '</ul></section>';
        }).join('');
        $('[data-rv-groups]').innerHTML = html || '<p class="it-rv-sub">' + (data.counts.waiting ? 'Nothing here with this filter.' : 'Nothing waiting — every receipt is checked.') + '</p>';
    }

    function dupRow(m, keeper, isKeeper, setIdx) {
        var label = (m.vendor || 'Receipt') + ' ' + money(m.total) + ' ' + day(m.expense_date);
        var facts = '#' + m.id + ' · ' + esc(day(m.expense_date)) + ' · <strong>' + money(m.total) + '</strong> · ' + esc(m.vendor || '')
            + (m.bank ? ' · bank line ' + esc(day(m.bank.date)) + ' ' + money(Math.abs(m.bank.amount)) : '');
        var badge = isKeeper
            ? '<span class="it-rv-badge">' + (m.status === 'approved' || m.status === 'forwarded' ? 'In the books' : 'Keep this one') + (m.linked ? ' · linked to the bank' : '') + '</span>'
            : '<span class="it-rv-badge is-copy">Suspected copy · ' + esc(m.status === 'draft' ? 'not approved' : m.status) + '</span>';
        var actions = isKeeper ? '' : '<div class="it-rv-actions">'
            + '<button type="button" class="it-rv-btn it-rv-btn-primary" data-rv-merge="' + m.id + '" data-keep="' + keeper.id + '">Merge into #' + keeper.id + '</button>'
            + '<button type="button" class="it-rv-btn" data-rv-notdupe="' + m.id + '" data-keep="' + keeper.id + '" data-set="' + setIdx + '">Not a duplicate</button></div>';
        return '<div class="it-rv-dup-row' + (isKeeper ? ' is-keeper' : '') + '">' + thumb(m.photo, label)
            + '<div class="it-rv-main">' + badge + '<p class="it-rv-facts">' + facts + '</p>'
            + (m.items ? '<p class="it-rv-items">' + esc(m.items) + '</p>' : '') + '</div>' + actions + '</div>';
    }

    function renderDups() {
        var sets = data.duplicates || [];
        $('[data-rv-dups]').hidden = !sets.length;
        $('[data-rv-dups-list]').innerHTML = sets.map(function (s, i) {
            return '<article class="it-rv-dup" aria-label="' + esc('Suspected duplicates of receipt #' + s.keeper.id) + '">'
                + dupRow(s.keeper, s.keeper, true, i)
                + s.copies.map(function (c) { return dupRow(c, s.keeper, false, i); }).join('')
                + (s.why && s.why.length ? '<div class="it-rv-dup-row"><span></span><div class="it-rv-why">' + s.why.map(function (w) { return '<span>' + esc(w) + '</span>'; }).join('') + '</div></div>' : '')
                + '</article>';
        }).join('');
    }

    function renderBar() {
        var ids = Object.keys(ticked), sum = 0;
        ids.forEach(function (k) { sum += Number(ticked[k]) || 0; });
        $('[data-rv-sum]').textContent = ids.length ? ids.length + ' checked · ' + money(sum) : 'Nothing ticked yet';
        $('[data-rv-approve]').disabled = !ids.length || busy;
        $('[data-rv-approve]').textContent = ids.length ? 'Approve ' + ids.length + ' checked' : 'Approve checked';
    }

    // After a redraw the button that was pressed is gone: put focus on the summary, not the top of the page.
    function focusSummary() { var el = $('[data-rv-counts]'); if (el) { el.setAttribute('tabindex', '-1'); el.focus({ preventScroll: true }); } }

    function render() {
        var c = data.counts;
        $('[data-rv-counts]').textContent = c.waiting + ' waiting · ' + c.all_pass + ' pass every check · ' + c.duplicates + ' suspected duplicate' + (c.duplicates === 1 ? '' : 's');
        renderDups();
        renderGroups();
        renderBar();
    }

    // ── Events ──────────────────────────────────────────────────────────────
    root.addEventListener('change', function (e) {
        var t = e.target.closest('[data-rv-tick]');
        if (!t) return;
        var id = t.getAttribute('data-rv-tick');
        if (t.checked) ticked[id] = Number(t.getAttribute('data-total')); else delete ticked[id];
        var row = t.closest('[data-rv-row]');
        if (row) row.classList.toggle('is-checked', t.checked);
        renderBar();
    });

    root.addEventListener('click', function (e) {
        var f = e.target.closest('[data-rv-filter]');
        if (f) {
            filter = f.getAttribute('data-rv-filter');
            root.querySelectorAll('[data-rv-filter]').forEach(function (b) { b.setAttribute('aria-checked', b === f ? 'true' : 'false'); });
            renderGroups();
            return;
        }
        var g = e.target.closest('[data-rv-tick-group]');
        if (g) {
            var name = g.getAttribute('data-rv-tick-group');
            // Only rows on screen with this filter, never a suspected copy.
            data.groups.forEach(function (grp) { if (grp.vendor === name) grp.rows.forEach(function (r) { if (r.all_pass && !r.in_duplicate && shown(r)) ticked[r.id] = r.total; }); });
            renderGroups(); renderBar();
            return;
        }
        var o = e.target.closest('[data-rv-open]');
        if (o) {
            var dlg = $('[data-rv-photo]');
            $('[data-rv-photo-img]').src = o.getAttribute('data-rv-open');
            $('[data-rv-photo-img]').alt = o.getAttribute('aria-label') || 'Receipt photo';
            if (dlg.showModal) dlg.showModal(); else window.open(o.getAttribute('data-rv-open'), '_blank', 'noopener');
            return;
        }
        var m = e.target.closest('[data-rv-merge]'), n = e.target.closest('[data-rv-notdupe]');
        if (m || n) {
            var btn = m || n;
            if (busy) return;
            busy = true;
            btn.disabled = true;
            var body;
            if (m) body = { mode: 'merge', copy_id: Number(m.getAttribute('data-rv-merge')), keep_id: Number(m.getAttribute('data-keep')) };
            else {
                var copy = Number(n.getAttribute('data-rv-notdupe')), set = (data.duplicates || [])[Number(n.getAttribute('data-set'))] || {};
                var pairs = (set.pairs || []).filter(function (p) { return Number(p[0]) === copy || Number(p[1]) === copy; });
                body = { mode: 'not_dupe', pairs: pairs.length ? pairs : [[copy, Number(n.getAttribute('data-keep'))]] };
            }
            post(body).then(function (r) {
                busy = false;
                if (!r || r.ok === false) { btn.disabled = false; toast((r && (r.message || r.error)) || 'That didn\'t save.', 'error'); return; }
                toast(r.message || (m ? 'Merged.' : 'Marked as not a duplicate.'));
                return load().then(focusSummary);
            }).catch(function () { busy = false; btn.disabled = false; toast('No connection — try again.', 'error'); });
        }
    });

    $('[data-rv-approve]').addEventListener('click', function () {
        var ids = Object.keys(ticked).map(Number);
        if (!ids.length || busy) return;
        busy = true; renderBar();
        post({ mode: 'approve', ids: ids }).then(function (r) {
            busy = false;
            if (!r || !r.ok) { toast((r && r.error) || 'Nothing was approved.', 'error'); renderBar(); return; }
            (r.approved || []).forEach(function (id) { delete ticked[id]; });
            var msg = (r.approved || []).length + ' approved';
            if ((r.failed || []).length) msg += ' · ' + r.failed.length + ' not: ' + r.failed.map(function (f) { return '#' + f.id + ' ' + f.error; }).join('; ');
            toast(msg, (r.failed || []).length ? 'error' : undefined);
            return load().then(focusSummary);
        }).catch(function () { busy = false; renderBar(); toast('No connection — nothing was approved.', 'error'); });
    });

    load();
})();
