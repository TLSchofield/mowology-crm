/**
 * Otto's review page (public/crm/ops/otto-review.php).
 *
 * Unscheduled work: each case card gets the same buttons as Otto's dashboard card, by matching
 * its data-key to /crm/api/otto.php?mode=suggestions (window.MwOtto from otto-card.js).
 * Visit lengths: Apply / Apply all confident / Undo → /crm/api/otto-durations.php.
 * Nothing changes until a button is clicked. AppStack page, so MW_CSRF_TOKEN is present.
 */
(function () {
    'use strict';
    var DUR = '/crm/api/otto-durations.php';

    function post(url, body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function msg(host, text, bad) {
        var m = host.querySelector('.mw-or-msg');
        if (!m) return;
        m.hidden = false;
        m.textContent = text;
        m.classList.toggle('is-bad', !!bad);
    }

    function unscheduled(root) {
        var cases = root.querySelectorAll('.mw-or-case[data-key]');
        if (!cases.length || !window.MwOtto) return;
        window.MwOtto.get('mode=suggestions').then(function (r) {
            var byKey = {};
            ((r && r.items) || []).forEach(function (it) { byKey[it.key] = it; });
            cases.forEach(function (c) {
                var box = c.querySelector('.mw-or-act');
                var it = byKey[c.getAttribute('data-key')];
                box.innerHTML = '';
                if (!it) { box.innerHTML = '<small class="text-muted">Already decided, or Otto has not raised it yet — check back after the dashboard loads.</small>'; return; }
                it.url = '';
                box.appendChild(window.MwOtto.item(it));
            });
        }).catch(function () {
            cases.forEach(function (c) { c.querySelector('.mw-or-act').innerHTML = '<small class="text-muted">Otto couldn\'t load. Refresh to try again.</small>'; });
        });
    }

    function durations(root) {
        root.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-or]');
            if (!b) return;
            var what = b.getAttribute('data-or');
            var host = b.closest('td, li, .mw-or-bulk') || b.parentNode;
            var body;
            if (what === 'apply') {
                var inp = host.querySelector('input[type="number"]');
                body = { mode: 'apply', plan_id: parseInt(b.getAttribute('data-plan'), 10), minutes: parseInt(inp && inp.value, 10) };
                if (!body.minutes) return msg(host, 'Give the minutes first.', true);
            } else if (what === 'apply_all') {
                if (!window.confirm('Update every confident plan length? You can undo them all.')) return;
                body = { mode: 'apply_all' };
            } else if (what === 'undo') {
                body = { mode: 'undo', change_id: parseInt(b.getAttribute('data-change'), 10) };
            } else if (what === 'undo_batch') {
                body = { mode: 'undo_batch', batch: b.getAttribute('data-batch') };
            } else return;
            b.disabled = true;
            post(DUR, body).then(function (r) {
                msg(host, r.message || r.error || 'Done.', !r.ok);
                if (!r.ok) { b.disabled = false; return; }
                if (what === 'apply') {
                    var row = b.closest('tr');
                    var cell = row && row.querySelector('[data-planned]');
                    if (cell) cell.textContent = body.minutes + ' min';
                    b.textContent = 'Applied';
                } else if (what === 'apply_all' && r.batch) {
                    b.hidden = true;
                    var u = document.createElement('button');
                    u.type = 'button';
                    u.className = 'btn btn-outline-secondary btn-sm';
                    u.setAttribute('data-or', 'undo_batch');
                    u.setAttribute('data-batch', r.batch);
                    u.textContent = 'Undo all of these';
                    host.insertBefore(u, host.firstChild);
                } else if (what === 'undo' || what === 'undo_batch') {
                    b.hidden = true;
                    setTimeout(function () { window.location.reload(); }, 1200);
                }
            }).catch(function () { b.disabled = false; msg(host, 'No connection. Try again.', true); });
        });
    }

    /** Contract visits Otto logged by himself: Undo → /crm/api/otto-unscheduled.php (mode=undo_auto). */
    function autoLog(root) {
        root.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-or="undo_auto"]');
            if (!b) return;
            if (!window.confirm('Cancel the visit Otto logged? He will ask about that day instead.')) return;
            var host = b.closest('li');
            b.disabled = true;
            post('/crm/api/otto-unscheduled.php', { mode: 'undo_auto', id: parseInt(b.getAttribute('data-id'), 10) }).then(function (r) {
                msg(host, r.message || r.error || 'Done.', !r.ok);
                if (r.ok) b.hidden = true; else b.disabled = false;
            }).catch(function () { b.disabled = false; msg(host, 'No connection. Try again.', true); });
        });
    }

    function init() {
        var root = document.querySelector('.mw-or');
        if (!root) return;
        if (root.getAttribute('data-view') === 'durations') durations(root);
        else { unscheduled(root); autoLog(root); }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
