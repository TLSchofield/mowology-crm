/**
 * Penny's receipt carousel (dashboard → department heads deck).
 *
 * One prepared receipt at a time: photo, Penny's suggestion with her reasons, the
 * hard checks. Every field is editable in place; Approve (A) · Save draft (S) · Skip (→). Approving writes the values,
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
    var busy = false;
    var rounds = 0;          // background preparation rounds this page view (max 3 × 2 receipts)
    var preparing = false;
    var capped = false;
    var zoomed = false;     // full-screen review: big receipt beside Penny's read
    var zoomBig = false;    // receipt at 2× inside the full-screen view

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
            setZoom(false);
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

        // Always editable: the value shown is Penny's (or your saved draft); type over it.
        var d = it.saved_draft || null;
        var cur = function (f) { return d && Object.prototype.hasOwnProperty.call(d, f) ? d[f] : val(s, f); };
        var mine = function (f) {      // differs from Penny's suggestion → your edit
            var a = cur(f), b = val(s, f);
            if (f === 'asset_tag') { a = a || 'none'; b = b || 'none'; }
            return String(a == null ? '' : a) !== String(b == null ? '' : b);
        };
        var tagNow = cur('asset_tag') || 'none';
        var catOpts = CATEGORIES.map(function (c) {
            return '<option' + (c === cur('accounting_category') ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
        var tagOpts = ['none', 'truck', 'equipment'].map(function (t) {
            return '<option value="' + t + '"' + (t === tagNow ? ' selected' : '') + '>' + esc(tagLabel(t)) + '</option>';
        }).join('');
        var jobNow = cur('job');
        var jobSug = val(s, 'job');
        var jobOpts = '<option value="">None</option>' +
            (jobSug ? '<option value="' + esc(jobSug) + '"' + (String(jobNow) === String(jobSug) ? ' selected' : '') + '>' + esc(it.job_title || ('Job #' + jobSug)) + '</option>' : '');
        var amt = function (f, label) {
            return '<label class="mw-rc-amt"><span>' + label + '</span><input class="mw-rc-in" data-f="' + f + '" type="number" step="0.01" inputmode="decimal" value="' + esc(cur(f)) + '" aria-label="' + label + '"></label>';
        };
        var tagFld = function (f, label, control, conff) {
            return '<div class="mw-rc-fld' + (mine(f) ? ' is-yours' : '') + '" data-fld="' + f + '"><div class="mw-k">' + esc(label) +
                ' ' + conf(s, conff || f) + '<span class="mw-rc-yours">edited by you</span></div>' +
                '<div class="mw-v">' + control + '</div>' + why(s, conff || f) + '</div>';
        };
        var fields =
            tagFld('accounting_category', 'Category', '<select class="mw-rc-in' + (changed(it, 'accounting_category') ? ' mw-rc-changed' : '') + '" data-f="accounting_category">' + catOpts + '</select>') +
            tagFld('asset_tag', 'For', '<select class="mw-rc-in" data-f="asset_tag">' + tagOpts + '</select>') +
            tagFld('total', 'Total · GST · PST', '<div class="mw-rc-amts">' + amt('total', 'Total') + amt('gst', 'GST') + amt('pst', 'PST') + '</div>') +
            tagFld('job', 'Job', '<select class="mw-rc-in" data-f="job">' + jobOpts + '</select>');

        var top = function (closeBtn) {
            return '<div class="mw-rc-top">' +
              '<span><b>Receipt ' + (idx + 1) + ' of ' + queue.length + '</b> · ' + (it.status === 'pending_approval' ? 'submitted for approval' : 'draft') +
                (it.saved_draft ? ' · <span class="mw-rc-draftbadge">Saved draft</span>' : '') + '</span>' +
              '<span><button type="button" class="mw-rc-arrow" data-act="prev" aria-label="Previous">‹</button> ' +
              '<button type="button" class="mw-rc-arrow" data-act="next" aria-label="Next">›</button>' +
              (closeBtn ? ' <button type="button" class="mw-rc-arrow" data-act="close" aria-label="Close">✕</button>' : '') + '</span>' +
            '</div>';
        };
        var detail = function (fullNotes) {
            return '<div class="mw-rc-meta"><b>' + esc(it.vendor || 'Unknown vendor') + '</b> · ' + esc(it.date || '') +
                  (it.submitted_by ? ' · from ' + esc(it.submitted_by) : '') + '</div>' +
                '<div class="mw-rc-fields">' + fields + '</div>' +
                (checks ? '<div class="mw-rc-checks">' + checks + '</div>' : '') +
                (s.notes ? '<div class="mw-rc-checks' + (fullNotes ? '' : ' mw-rc-note') + '" title="' + esc(s.notes) + '">📝 ' + esc(s.notes) + '</div>' : '') +
                '<div class="mw-rc-actions">' +
                  '<button type="button" class="mw-rc-ok" data-act="approve">✓ Approve</button>' +
                  '<button type="button" class="mw-rc-ed" data-act="draft">💾 Save draft</button>' +
                  '<button type="button" class="mw-rc-sk" data-act="next">Skip →</button>' +
                '</div>' +
                '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>';
        };

        var html = top(false) + '<div class="mw-rc-slide">' + photo + '<div>' + (zoomed ? '' : detail(false)) + '</div></div>';
        if (zoomed) {
            html += '<div class="mw-rc-zoom" role="dialog" aria-modal="true" aria-label="Receipt and Penny\'s read">' +
                '<div class="mw-rc-zoom-img' + (zoomBig ? ' is-big' : '') + '">' +
                  (it.image_url ? '<img src="' + esc(it.image_url) + '" alt="Receipt photo" data-act="bigger" title="Click to zoom">' : '<div class="mw-rc-nophoto">No photo</div>') +
                '</div>' +
                '<div class="mw-rc-zoom-side">' + top(true) + detail(true) +
                  '<div class="mw-rc-zoom-hint">Click the receipt to zoom · type straight into any field · A approve · S save draft · → skip · Esc close</div>' +
                '</div>' +
              '</div>';
        }
        root.innerHTML = html;
    }

    function setZoom(on) {
        zoomed = !!on;
        if (!zoomed) zoomBig = false;
        document.body.classList.toggle('mw-rc-zoom-open', zoomed);
    }

    function fld(k, v, w) {
        return '<div class="mw-rc-fld"><div class="mw-k">' + esc(k) + '</div><div class="mw-v">' + v + '</div>' + w + '</div>';
    }

    function formValues() {
        var out = {};
        root.querySelectorAll('[data-f]').forEach(function (el) { out[el.getAttribute('data-f')] = el.value; });
        return out;
    }

    /** Approve with whatever is in the fields (unchanged = Penny's value accepted), or save as a draft. */
    function submit(saveDraft) {
        if (busy || !queue.length) return;
        var it = queue[idx];
        var values = formValues();
        busy = true;
        post({ mode: 'decide', suggestion_id: it.suggestion_id, overrides: values, save_draft: !!saveDraft })
            .then(function (d) {
                busy = false;
                if (d && d.ok && d.saved_draft) {
                    it.saved_draft = values;
                    queue.splice(idx, 1);
                    queue.push(it);                 // come back to it last
                    render(d.message || 'Saved as a draft');
                } else if (d && d.ok) {
                    queue.splice(idx, 1);
                    render(d.message || 'Approved');
                    if (queue.length < 3) { load(); }
                } else {
                    render((d && (d.message || d.error)) || 'Could not save');
                }
            })
            .catch(function () { busy = false; render('Network error — try again'); });
    }
    function approve() { submit(false); }

    root.addEventListener('click', function (e) {
        if (e.target.getAttribute('data-zoom')) { setZoom(true); render(); return; }
        var act = e.target.getAttribute('data-act');
        if (!act) return;
        if (act === 'close') { setZoom(false); render(); }
        else if (act === 'bigger') { zoomBig = !zoomBig; render(); }
        else if (act === 'approve') approve();
        else if (act === 'draft') submit(true);
        else if (act === 'next') { idx = (idx + 1) % Math.max(1, queue.length); render(); }
        else if (act === 'prev') { idx = (idx - 1 + queue.length) % Math.max(1, queue.length); render(); }
    });

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute && e.target.getAttribute('data-f');
        if (!f || !queue.length) return;
        var s = queue[idx].suggestion || {};
        var key = (f === 'gst' || f === 'pst') ? 'total' : f;
        var box = root.querySelector('.mw-rc-fld[data-fld="' + key + '"]');
        if (!box) return;
        var edited = Array.prototype.some.call(box.querySelectorAll('[data-f]'), function (el) {
            var name = el.getAttribute('data-f');
            var sv = s[name] ? s[name].value : null;
            if (name === 'asset_tag') sv = sv || 'none';
            if (el.type === 'number') return el.value !== '' && Math.abs(parseFloat(el.value) - parseFloat(sv)) > 0.004;
            return String(el.value) !== String(sv == null ? '' : sv);
        });
        box.classList.toggle('is-yours', edited);
    });

    document.addEventListener('keydown', function (e) {
        var t = e.target.tagName;
        if (t === 'INPUT' || t === 'SELECT' || t === 'TEXTAREA' || e.metaKey || e.ctrlKey || e.altKey) return;
        if (zoomed && e.key === 'Escape') { e.preventDefault(); setZoom(false); render(); return; }
        if (!root.offsetParent || !queue.length) return;
        if (e.key === 'a' || e.key === 'A') { e.preventDefault(); approve(); }
        else if (e.key === 's' || e.key === 'S') { e.preventDefault(); submit(true); }
        else if (e.key === 'ArrowRight') { idx = (idx + 1) % queue.length; render(); }
        else if (e.key === 'ArrowLeft') { idx = (idx - 1 + queue.length) % queue.length; render(); }
    });

    load();
})();
