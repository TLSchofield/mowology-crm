/**
 * Penny's receipt carousel (dashboard → department heads deck).
 *
 * One prepared receipt at a time: photo, Penny's suggestion with her reasons, the
 * hard checks, then Approve (A) · Edit (E) · Skip (→). Approving writes the values,
 * records the decision (her scorecard + learning) and approves the expense.
 * When fewer than 5 receipts are prepared it asks for 2 more in the background (up to
 * 3 rounds per page view, only while the tab is visible). API: /crm/api/bookkeeper.php (?mode=queue / decide / prepare).
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-rc');
    if (!root) return;

    var API = '/crm/api/bookkeeper.php';
    var CATEGORIES = [];
    try { CATEGORIES = JSON.parse(root.getAttribute('data-categories') || '[]'); } catch (e) {}
    var BACKLOG = parseInt(root.getAttribute('data-backlog') || '0', 10);
    var queue = [];
    var idx = 0;
    var editing = false;
    var busy = false;
    var rounds = 0;          // background preparation rounds this page view (max 3 × 2 receipts)
    var preparing = false;
    var capped = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return v == null || v === '' || isNaN(v) ? '—' : '$' + Number(v).toFixed(2); }
    function val(s, f) { return s && s[f] ? s[f].value : null; }
    function conf(s, f) {
        var c = s && s[f] ? s[f].confidence : null;
        return c ? '<span class="mw-rc-conf is-' + esc(c) + '">' + esc(c) + '</span>' : '';
    }
    function why(s, f) { return s && s[f] && s[f].reason ? '<div class="mw-why">' + esc(s[f].reason) + '</div>' : ''; }
    function changed(item, f, curKey) {
        var cur = item.current ? item.current[curKey || f] : null;
        var v = val(item.suggestion, f);
        return cur != null && cur !== '' && v != null && String(cur) !== String(v);
    }
    function tagLabel(t) { return t === 'truck' ? '🚚 Truck' : t === 'equipment' ? '🔧 Equipment' : 'None'; }

    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load() {
        return fetch(API + '?mode=queue&limit=15', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                queue = (d && d.ok && d.queue) ? d.queue : [];
                idx = 0;
                topUp();
                render();
            })
            .catch(function () { root.innerHTML = '<div class="mw-rc-empty">Couldn\'t load receipts — refresh to try again.</div>'; });
    }

    /** Prepare 2 more in the background while fewer than 5 are ready (≤ 3 rounds per view). */
    function topUp() {
        if (queue.length >= 5 || preparing || capped || rounds >= 3 || BACKLOG <= queue.length) return;
        if (document.visibilityState !== 'visible') {
            document.addEventListener('visibilitychange', function once() {
                if (document.visibilityState === 'visible') { document.removeEventListener('visibilitychange', once); topUp(); render(); }
            });
            return;
        }
        preparing = true;
        rounds++;
        post({ mode: 'prepare', max: 2 }).then(function (p) {
            preparing = false;
            if (p && p.capped) { capped = true; render(); return; }
            if (p && p.ok && p.prepared && p.prepared.length) { load(); } else { capped = true; render(); }
        }).catch(function () { preparing = false; render(); });
    }

    function render(msg) {
        if (!queue.length) {
            var text = preparing ? 'I\'m preparing your receipts — they\'ll appear here in a moment…'
                : capped && BACKLOG > 0 ? 'I\'ve prepared as many as I can for now — more tomorrow, or open All receipts.'
                : BACKLOG > 0 ? 'Getting your receipts ready…'
                : 'All caught up — nothing waiting for you. 🎉';
            root.innerHTML = '<div class="mw-rc-empty">' + text + '</div>';
            return;
        }
        if (idx >= queue.length) idx = 0;
        var it = queue[idx];
        var s = it.suggestion || {};
        var photo = it.image_url
            ? '<img class="mw-rc-photo" src="' + esc(it.image_url) + '" alt="Receipt photo" data-zoom="' + esc(it.image_url) + '">'
            : '<div class="mw-rc-nophoto">No photo</div>';
        var checks = (it.checks || []).map(function (c) {
            return '<span class="' + (c.ok ? '' : 'is-bad') + '">' + (c.ok ? '✓ ' : '⚠ ') + esc(c.message) + '</span>';
        }).join(' &nbsp; ');

        var fields;
        if (!editing) {
            var job = val(s, 'job');
            fields =
                fld('Category', '<span class="' + (changed(it, 'accounting_category') ? 'mw-rc-changed' : '') + '">' + esc(val(s, 'accounting_category') || '—') + '</span> ' + conf(s, 'accounting_category'), why(s, 'accounting_category')) +
                fld('For', '<span>' + esc(tagLabel(val(s, 'asset_tag'))) + '</span> ' + conf(s, 'asset_tag'), why(s, 'asset_tag')) +
                fld('Total · GST · PST', money(val(s, 'total')) + ' · ' + money(val(s, 'gst')) + ' · ' + money(val(s, 'pst')) + ' ' + conf(s, 'total'), why(s, 'total')) +
                fld('Job', esc(job ? (it.job_title || ('Job #' + job)) : 'None') + ' ' + conf(s, 'job'), why(s, 'job'));
        } else {
            var catOpts = CATEGORIES.map(function (c) {
                return '<option' + (c === val(s, 'accounting_category') ? ' selected' : '') + '>' + esc(c) + '</option>';
            }).join('');
            var tag = val(s, 'asset_tag') || 'none';
            var tagOpts = ['none', 'truck', 'equipment'].map(function (t) {
                return '<option value="' + t + '"' + (t === tag ? ' selected' : '') + '>' + esc(tagLabel(t)) + '</option>';
            }).join('');
            var job2 = val(s, 'job');
            fields =
                fld('Category', '<select data-f="accounting_category">' + catOpts + '</select>', '') +
                fld('For', '<select data-f="asset_tag">' + tagOpts + '</select>', '') +
                fld('Total · GST · PST',
                    '<input data-f="total" type="number" step="0.01" value="' + esc(val(s, 'total')) + '" aria-label="Total">' +
                    '<input data-f="gst" type="number" step="0.01" value="' + esc(val(s, 'gst')) + '" aria-label="GST">' +
                    '<input data-f="pst" type="number" step="0.01" value="' + esc(val(s, 'pst')) + '" aria-label="PST">', '') +
                fld('Job', '<select data-f="job"><option value="">None</option>' +
                    (job2 ? '<option value="' + esc(job2) + '" selected>' + esc(it.job_title || ('Job #' + job2)) + '</option>' : '') +
                    '</select>', '');
        }

        root.innerHTML =
            '<div class="mw-rc-top">' +
              '<span><b>Receipt ' + (idx + 1) + ' of ' + queue.length + '</b> · ' + (it.status === 'pending_approval' ? 'submitted for approval' : 'draft') + '</span>' +
              '<span><button type="button" class="mw-rc-arrow" data-act="prev" aria-label="Previous">‹</button> ' +
              '<button type="button" class="mw-rc-arrow" data-act="next" aria-label="Next">›</button></span>' +
            '</div>' +
            '<div class="mw-rc-slide">' + photo +
              '<div>' +
                '<div class="mw-rc-meta"><b>' + esc(it.vendor || 'Unknown vendor') + '</b> · ' + esc(it.date || '') +
                  (it.submitted_by ? ' · from ' + esc(it.submitted_by) : '') + '</div>' +
                '<div class="mw-rc-fields">' + fields + '</div>' +
                (checks ? '<div class="mw-rc-checks">' + checks + '</div>' : '') +
                (s.notes ? '<div class="mw-rc-checks">📝 ' + esc(s.notes) + '</div>' : '') +
                '<div class="mw-rc-actions">' +
                  '<button type="button" class="mw-rc-ok" data-act="approve">✓ ' + (editing ? 'Save &amp; approve' : 'Approve') + '</button>' +
                  '<button type="button" class="mw-rc-ed" data-act="' + (editing ? 'cancel' : 'edit') + '">' + (editing ? 'Cancel' : '✎ Edit') + '</button>' +
                  '<button type="button" class="mw-rc-sk" data-act="next">Skip →</button>' +
                '</div>' +
                '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>' +
              '</div>' +
            '</div>';
    }

    function fld(k, v, w) {
        return '<div class="mw-rc-fld"><div class="mw-k">' + esc(k) + '</div><div class="mw-v">' + v + '</div>' + w + '</div>';
    }

    function approve() {
        if (busy || !queue.length) return;
        var it = queue[idx];
        var overrides = {};
        if (editing) {
            root.querySelectorAll('[data-f]').forEach(function (el) { overrides[el.getAttribute('data-f')] = el.value; });
        }
        busy = true;
        post({ mode: 'decide', suggestion_id: it.suggestion_id, overrides: overrides })
            .then(function (d) {
                busy = false;
                if (d && d.ok) {
                    queue.splice(idx, 1);
                    editing = false;
                    render(d.message || 'Approved');
                    if (queue.length < 3) { load(); }
                } else {
                    render((d && (d.message || d.error)) || 'Could not save');
                }
            })
            .catch(function () { busy = false; render('Network error — try again'); });
    }

    root.addEventListener('click', function (e) {
        var zoom = e.target.getAttribute('data-zoom');
        if (zoom) { window.open(zoom, '_blank', 'noopener'); return; }
        var act = e.target.getAttribute('data-act');
        if (!act) return;
        if (act === 'approve') approve();
        else if (act === 'edit') { editing = true; render(); }
        else if (act === 'cancel') { editing = false; render(); }
        else if (act === 'next') { editing = false; idx = (idx + 1) % Math.max(1, queue.length); render(); }
        else if (act === 'prev') { editing = false; idx = (idx - 1 + queue.length) % Math.max(1, queue.length); render(); }
    });

    document.addEventListener('keydown', function (e) {
        var t = e.target.tagName;
        if (t === 'INPUT' || t === 'SELECT' || t === 'TEXTAREA' || e.metaKey || e.ctrlKey || e.altKey) return;
        if (!root.offsetParent || !queue.length) return;
        if (e.key === 'a' || e.key === 'A') { e.preventDefault(); approve(); }
        else if (e.key === 'e' || e.key === 'E') { e.preventDefault(); editing = true; render(); }
        else if (e.key === 'ArrowRight') { editing = false; idx = (idx + 1) % queue.length; render(); }
        else if (e.key === 'ArrowLeft') { editing = false; idx = (idx - 1 + queue.length) % queue.length; render(); }
    });

    load();
})();
