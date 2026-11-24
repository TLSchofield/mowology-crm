/**
 * Otto's review page, Pins & borders view (public/crm/ops/otto-review.php?view=borders).
 *
 *   Otto's items   no_pin / pin_off / default_border / border_overlap from /crm/api/otto.php?mode=suggestions,
 *                  drawn with the card's buttons (window.MwOtto.item).
 *   Preview        GET /crm/api/otto-borders.php?mode=audit — read-only; follows next_offset until every
 *                  property has been measured, then lists the default borders it would create.
 *   Create them    POST mode=apply with exactly the previewed property ids (offset loop as above).
 *   Re-measure     POST mode=measure — stores where crews work (Otto's "pin is X m off" items).
 */
(function () {
    'use strict';
    var API = '/crm/api/otto-borders.php';
    var KINDS = ['no_pin', 'pin_off', 'default_border', 'border_overlap'];
    var previewIds = [];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function say(root, text, bad) {
        var m = root.querySelector('.mw-ob-btns .mw-or-msg');
        m.hidden = false;
        m.textContent = text;
        m.classList.toggle('is-bad', !!bad);
    }

    function items() {
        var host = document.getElementById('mw-ob-items');
        if (!host || !window.MwOtto) return;
        window.MwOtto.get('mode=suggestions').then(function (r) {
            var list = ((r && r.items) || []).filter(function (it) { return KINDS.indexOf(it.kind) >= 0; });
            host.innerHTML = '';
            if (!list.length) { host.innerHTML = '<div class="mw-otto-empty">Nothing for Otto here.</div>'; return; }
            list.forEach(function (it) { host.appendChild(window.MwOtto.item(it)); });
        }).catch(function () { host.innerHTML = '<div class="mw-otto-empty">Otto couldn\'t load. Refresh to try again.</div>'; });
    }

    /** Follow next_offset until done; collect each page's answer. */
    function walk(step, onPage) {
        return (function go(offset) {
            return step(offset).then(function (r) {
                if (!r || r.ok === false) throw new Error((r && (r.message || r.error)) || 'That didn\'t work.');
                onPage(r);
                return r.next_offset != null ? go(r.next_offset) : r;
            });
        })(0);
    }

    function preview(root, btn) {
        var box = root.querySelector('.mw-ob-preview');
        var apply = root.querySelector('[data-ob="apply"]');
        btn.disabled = true;
        apply.disabled = true;
        previewIds = [];
        var rows = [];
        say(root, 'Reading crew GPS for each property…');
        walk(function (offset) {
            return fetch(API + '?mode=audit&offset=' + offset, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
        }, function (r) {
            rows = rows.concat(r.proposed || []);
            say(root, 'Measured ' + (r.walked_from + r.walked) + ' of ' + r.active + '…');
        }).then(function () {
            btn.disabled = false;
            previewIds = rows.map(function (p) { return p.property_id; });
            var hull = rows.filter(function (p) { return p.source === 'default_hull'; }).length;
            say(root, rows.length ? rows.length + ' default borders: ' + hull + ' from crew GPS, ' + (rows.length - hull) + ' squares to draw.' : 'Every pinned property already has a border.');
            box.hidden = !rows.length;
            box.innerHTML = rows.length ? '<table class="table table-sm mb-0"><thead><tr><th>Property</th><th>Kind</th><th class="text-end">Fixes</th><th class="text-end">Visits</th><th class="text-end">Area</th></tr></thead><tbody>' +
                rows.map(function (p) {
                    return '<tr><td>' + esc(p.address) + '</td><td>' + (p.source === 'default_hull' ? 'From crew GPS' : 'Square — draw me') + '</td>' +
                        '<td class="text-end">' + p.fixes + '</td><td class="text-end">' + p.visits + '</td><td class="text-end">' + p.area_sqm + ' m²</td></tr>';
                }).join('') + '</tbody></table>' : '';
            apply.disabled = !rows.length;
            apply.textContent = 'Create ' + rows.length + ' default border' + (rows.length === 1 ? '' : 's');
        }).catch(function (e) { btn.disabled = false; say(root, e.message, true); });
    }

    function apply(root, btn) {
        if (!previewIds.length) return;
        btn.disabled = true;
        var made = 0;
        say(root, 'Creating…');
        walk(function (offset) { return post({ mode: 'apply', property_ids: previewIds, offset: offset }); },
            function (r) { made += r.created || 0; say(root, made + ' created…'); })
            .then(function () { say(root, made + ' default border' + (made === 1 ? '' : 's') + ' created. Reload to see the counts.'); })
            .catch(function (e) { btn.disabled = false; say(root, e.message, true); });
    }

    function measure(root, btn) {
        btn.disabled = true;
        var n = 0;
        say(root, 'Measuring…');
        walk(function (offset) { return post({ mode: 'measure', offset: offset }); }, function (r) { n += r.measured || 0; say(root, n + ' measured…'); })
            .then(function () { btn.disabled = false; say(root, n + ' properties measured. Reload to see Otto\'s pin items.'); })
            .catch(function (e) { btn.disabled = false; say(root, e.message, true); });
    }

    function init() {
        var root = document.querySelector('.mw-ob-tools');
        items();
        if (!root) return;
        root.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-ob]');
            if (!b) return;
            var what = b.getAttribute('data-ob');
            if (what === 'preview') preview(root, b);
            else if (what === 'apply') apply(root, b);
            else if (what === 'measure') measure(root, b);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
