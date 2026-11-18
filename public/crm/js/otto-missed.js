/**
 * Otto's end-of-day missed-visit review — renders into every `.mw-omr` container
 * (the dashboard Otto card section, public/crm/includes/otto-missed-panel.php, and the desk page
 * public/crm/ops/missed-visits.php). Android shows these same web pages.
 *
 * Data: /crm/api/otto-missed.php (MissedVisitReviewService). Per visit: Otto's pick + reason,
 * then Tomorrow / pick a date (MwDatePicker, never a native date input) / Skip with a reason /
 * Cancel with a reason, and one "Accept all of Otto's picks". Nothing here messages a client.
 * Container attributes: data-compact="1" (card: hides itself when nothing is waiting).
 */
(function () {
    'use strict';
    var API = '/crm/api/otto-missed.php';
    var CAL = '<svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    var CHEV = '<svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    var state = { data: null };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, kind) { if (window.mwToast) window.mwToast(msg, kind || 'success'); }
    function dayLabel(d) {
        var t = new Date(d + 'T12:00:00');
        return t.toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
    }

    function load() {
        return fetch(API + '?mode=list', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { state.data = d; renderAll(); })
            .catch(function () { state.data = { ok: false, error: 'Could not load the review.' }; renderAll(); });
    }

    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function itemHtml(it, uid, d) {
        var head = '<div class="mw-omr-top"><b>' + esc(it.property || 'Property') + '</b>' +
            '<span class="mw-omr-when">' + esc(it.missed_label) + (it.crew ? ' · ' + esc(it.crew) : '') + '</span></div>' +
            '<div class="mw-omr-plan">' + esc(it.plan) + '</div>' +
            (it.why ? '<div class="mw-omr-why">Why: ' + esc(it.why) + '</div>' : '');
        var pick = '<div class="mw-omr-pick"><span class="mw-omr-otto">Otto: ' + esc(it.pick_label) + '</span> ' + esc(it.pick_reason) +
            (!it.skip_allowed && it.skip_block ? '<small>Never skipped by Otto: ' + esc(it.skip_block) + '</small>' : '') + '</div>';
        if (!it.can_decide) {
            return '<div class="mw-omr-item is-done">' + head + '<div class="mw-omr-done">' + esc(it.decision_text || 'Done') + '</div></div>';
        }
        var dpId = 'mw-omr-date-' + uid + '-' + it.id;
        var btns = '<div class="mw-otto-btns">' +
            (it.pick ? '<button type="button" class="is-main" data-do="accept">Do Otto\'s pick</button>' : '') +
            '<button type="button" data-do="tomorrow">Tomorrow</button>' +
            '<button type="button" class="mw-datepicker-trigger mw-omr-dp" data-mw-dp-commit="input" data-mw-dp-target="#' + dpId + '" aria-haspopup="true" aria-expanded="false">' +
                CAL + '<span class="mw-datepicker-date" data-mw-dp-label>Pick a date</span>' + CHEV + '</button>' +
            '<input type="date" id="' + dpId + '" class="mw-omr-date" min="' + esc(d.today) + '" hidden>' +
            '<button type="button" data-do="skip">Skip…</button>' +
            '<button type="button" data-do="cancel">Cancel…</button>' +
            '</div>';
        var reasons = (d.reasons || []).map(function (r) {
            return '<label class="mw-omr-reason"><input type="radio" name="r-' + uid + '-' + it.id + '" value="' + esc(r.key) + '"> ' + esc(r.label) + '</label>';
        }).join('');
        var why = '<div class="mw-omr-ask" hidden><div class="mw-omr-asktitle"></div><div class="mw-omr-reasons">' + reasons + '</div>' +
            '<div class="mw-otto-btns"><input type="text" class="mw-omr-note" maxlength="200" placeholder="Note (optional)">' +
            '<button type="button" class="is-main" data-do="confirm">Confirm</button><button type="button" data-do="back">Back</button></div></div>';
        return '<div class="mw-omr-item' + (it.apply_error ? ' is-err' : '') + '" data-id="' + it.id + '">' + head + pick + btns + why +
            (it.apply_error ? '<div class="mw-otto-msg mw-omr-err">Last try: ' + esc(it.apply_error) + '</div>' : '') +
            '<div class="mw-otto-msg" hidden></div></div>';
    }

    function render(box, uid) {
        var d = state.data;
        var compact = box.getAttribute('data-compact') === '1';
        if (!d || !d.ok) {
            if (compact) { box.hidden = true; return; }
            box.hidden = false;
            box.innerHTML = '<div class="mw-otto-empty">' + esc((d && d.error) || 'Loading…') + '</div>';
            return;
        }
        var items = d.items || [];
        var waiting = d.waiting || 0;
        if (compact && waiting === 0) { box.hidden = true; return; }
        box.hidden = false;
        var hd = '<div class="mw-omr-hd"><div><b>' + (waiting ? waiting + ' visit' + (waiting === 1 ? '' : 's') + ' not done' : 'Nothing waiting') + '</b>' +
            (d.review ? ' <small>review for ' + esc(d.review.label) + '</small>' : '') +
            '<small class="mw-omr-sub">Undecided visits roll on tonight as before; contract visits stay open for the next review.</small></div>' +
            (d.with_pick ? '<button type="button" class="btn btn-sm btn-success" data-do="all">Accept all of Otto\'s picks (' + d.with_pick + ')</button>' : '') +
            '</div>';
        // Waiting ones first; the decided ones (today) underneath on the full page.
        var shown = items.filter(function (i) { return i.can_decide; });
        if (!compact) shown = shown.concat(items.filter(function (i) { return !i.can_decide; }));
        var list = shown.length ? shown.map(function (it) { return itemHtml(it, uid, d); }).join('')
            : '<div class="mw-otto-empty">No missed visits in the last week. ' +
              '<button type="button" class="mw-otto-btnlink" data-do="build">Check today now</button></div>';
        box.innerHTML = hd + '<div class="mw-omr-list">' + list + '</div>' +
            (compact ? '<a class="mw-omr-more" href="/crm/ops/missed-visits.php">Open the review →</a>' : '');
        if (window.mwInitDatePickers) window.mwInitDatePickers();
    }

    function renderAll() {
        Array.prototype.forEach.call(document.querySelectorAll('.mw-omr'), function (box, i) { render(box, i); });
    }

    function find(id) {
        var items = (state.data && state.data.items) || [];
        for (var i = 0; i < items.length; i++) if (items[i].id === id) return items[i];
        return null;
    }

    function decide(el, body) {
        var msg = el.querySelector('.mw-otto-msg:not(.mw-omr-err)');
        Array.prototype.forEach.call(el.querySelectorAll('button'), function (b) { b.disabled = true; });
        body.mode = 'decide';
        body.item_id = Number(el.getAttribute('data-id'));
        return post(body).then(function (r) {
            if (r && r.ok) { toast(r.message || 'Done'); return load(); }
            Array.prototype.forEach.call(el.querySelectorAll('button'), function (b) { b.disabled = false; });
            if (msg) { msg.hidden = false; msg.textContent = (r && (r.error || r.message)) || 'Could not apply that.'; }
        }).catch(function () {
            Array.prototype.forEach.call(el.querySelectorAll('button'), function (b) { b.disabled = false; });
            if (msg) { msg.hidden = false; msg.textContent = 'Network error — nothing was changed.'; }
        });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('.mw-omr [data-do]');
        if (!b) return;
        var what = b.getAttribute('data-do');
        if (what === 'all') {
            b.disabled = true;
            post({ mode: 'accept_all', review_id: null }).then(function (r) {
                toast((r && r.message) || 'Done', r && r.ok ? 'success' : 'warning');
                load();
            }).catch(function () { b.disabled = false; toast('Network error — nothing was changed.', 'error'); });
            return;
        }
        if (what === 'build') {
            b.disabled = true;
            post({ mode: 'build' }).then(function (r) { toast((r && r.message) || 'Checked', 'info'); load(); });
            return;
        }
        var el = b.closest('.mw-omr-item');
        if (!el) return;
        var it = find(Number(el.getAttribute('data-id')));
        var ask = el.querySelector('.mw-omr-ask');
        if (what === 'accept') return decide(el, { choice: 'accept' });
        if (what === 'tomorrow') return decide(el, { choice: 'tomorrow' });
        if (what === 'skip' || what === 'cancel') {
            ask.hidden = false;
            ask.setAttribute('data-choice', what);
            ask.querySelector('.mw-omr-asktitle').textContent = what === 'skip'
                ? 'Skip this visit' + (it && !it.skip_allowed ? ' (Otto never would: ' + (it.skip_block || '') + ')' : '') + ' — why?'
                : 'Cancel this visit — why? (it will not be done or billed)';
            return;
        }
        if (what === 'back') { ask.hidden = true; return; }
        if (what === 'confirm') {
            var r = ask.querySelector('input[type="radio"]:checked');
            if (!r) { toast('Pick a reason first.', 'warning'); return; }
            return decide(el, { choice: ask.getAttribute('data-choice'), reason: r.value, note: ask.querySelector('.mw-omr-note').value || '' });
        }
    });

    // A date picked in the branded picker moves the visit there (after a confirm).
    document.addEventListener('change', function (e) {
        var inp = e.target;
        if (!inp.classList || !inp.classList.contains('mw-omr-date') || !inp.value) return;
        var el = inp.closest('.mw-omr-item');
        if (!el) return;
        if (!window.confirm('Move this visit to ' + dayLabel(inp.value) + '?')) { inp.value = ''; return; }
        decide(el, { choice: 'date', date: inp.value });
    });

    function init() {
        if (!document.querySelector('.mw-omr')) return;
        load();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
