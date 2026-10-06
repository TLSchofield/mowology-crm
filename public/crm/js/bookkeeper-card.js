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
    var GREETING = root.getAttribute('data-name') || '';
    var queue = [];
    var dupes = [];          // possible duplicate pairs — sorted before anything is approved
    var idx = 0;
    var busy = false;
    var rounds = 0;          // background preparation rounds this page view (max 3 × 2 receipts)
    var preparing = false;
    var capped = false;
    var zoomed = false;     // full-screen review: big receipt beside Penny's read
    var zoomBig = false;    // receipt at 2× inside the full-screen view
    var jobLabels = {};     // job id → label, for jobs picked from search
    var jobTimer = null;
    var venTimer = null;
    var rotation = {};      // expense id → degrees; view only, like the receipts page lightbox
    var datePopups = [];    // date-picker popups made for the current render (removed on the next)
    var rechecking = false;
    var rejecting = false;   // the reason box is open

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
    function tagLabel(t) { return t === 'truck' ? '🚚 Truck' : t === 'equipment' ? '🔧 Equipment' : t === 'stock' ? '🏪 Shop stock' : 'None'; }

    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load(msg) {
        return fetch(API + '?mode=queue&limit=15', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                queue = (d && d.ok && d.queue) ? d.queue : [];
                dupes = (d && d.ok && d.dupes) ? d.dupes : [];
                idx = 0;
                topUp();
                render(msg);
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

    // ── Possible duplicates: settled first, never offered for approval ──────
    var WAITING = ['draft', 'pending_approval'];
    function dupCard(r) {
        var name = r.vendor_name || r.vendor_name_raw || 'Unknown vendor';
        var waiting = WAITING.indexOf(r.status) !== -1;
        var photo = r.receipt_path
            ? '<img class="mw-rc-dup-photo" src="' + esc(r.receipt_path) + '" alt="Receipt #' + esc(r.id) + '" data-zoomsrc="' + esc(r.receipt_path) + '">'
            : '<div class="mw-rc-nophoto">No photo</div>';
        return '<div class="mw-rc-dup-side">' + photo +
            '<div class="mw-rc-dup-facts"><b>' + esc(name) + '</b><span>' + esc(r.expense_date || '') + ' · ' + money(r.total) + '</span>' +
            '<small>#' + esc(r.id) + ' · ' + esc(String(r.status || '').replace('_', ' ')) + (r.submitted_by ? ' · from ' + esc(r.submitted_by) : '') + '</small></div>' +
            (waiting
                ? '<button type="button" class="mw-rc-ed" data-dup-remove="' + esc(r.id) + '">✕ It\'s a copy — set aside</button>'
                : '<div class="mw-rc-dup-note">Already ' + (r.status === 'forwarded' ? 'sent to accounting' : 'approved') + ' — this one stays</div>') +
          '</div>';
    }
    function renderDupe(msg) {
        var g = dupes[0];
        var n = g.members.length;
        root.innerHTML = '<div class="mw-rc-top"><span><b>Possible duplicate' + (n > 2 ? 's' : '') + '</b>' + (dupes.length > 1 ? ' · group 1 of ' + dupes.length : '') +
                ' · sorted before anything is approved</span></div>' +
            '<div class="mw-rc-dup-say">' + (GREETING ? 'Hey ' + esc(GREETING) + ' — ' : '') +
                (n === 2 ? 'these two look' : 'these ' + n + ' look') + ' like the same purchase: same total, within 3 days. ' +
                'Set the copies aside and keep one — they\'re kept on record, not deleted, and a copy\'s photo moves to the one you keep if that has none.</div>' +
            '<div class="mw-rc-dup">' + g.members.map(dupCard).join('') + '</div>' +
            '<div class="mw-rc-actions">' +
              '<button type="button" class="mw-rc-ed" data-dup-not="1">' + (n === 2 ? 'Not duplicates' : 'None of these are duplicates') + ' — approve ' + (n === 2 ? 'both' : 'them all') + '</button>' +
              '<a class="mw-rc-sk" href="/crm/expenses_appstack.php">Compare every field on the receipts page →</a>' +
            '</div>' +
            '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>';
    }
    /** The copy goes into the one that stays: an approved/sent member, else the oldest other waiting one. */
    function keeperFor(g, removeId) {
        var others = g.members.filter(function (m) { return String(m.id) !== String(removeId); });
        var settled = others.filter(function (m) { return WAITING.indexOf(m.status) === -1; });
        return (settled[0] || others[0]).id;
    }
    function settleDupe(removeId, notDupe) {
        if (busy || !dupes.length) return;
        var g = dupes[0];
        busy = true;
        var req = notDupe
            ? post({ mode: 'not_dupe', pairs: g.pairs })
            : post({ mode: 'dupe_remove', copy_id: removeId, keep_id: keeperFor(g, removeId) });
        req.then(function (d) {
            busy = false;
            if (!(d && d.ok)) { renderDupe((d && d.message) || 'Could not save'); return; }
            load().then(function () { if (!dupes.length) render(notDupe ? 'Got it — they go on for approval.' : (d.message || 'Copy set aside.')); else renderDupe(notDupe ? 'Got it — not duplicates.' : (d.message || 'Copy set aside.')); });
        }).catch(function () { busy = false; renderDupe('Network error — try again'); });
    }

    function render(msg) {
        if (dupes.length) { setZoom(false); renderDupe(msg); return; }
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
            ? '<div class="mw-rc-photowrap"><img class="mw-rc-photo" data-rot src="' + esc(it.image_url) + '" alt="Receipt photo" data-zoom="' + esc(it.image_url) + '">' +
              '<button type="button" class="mw-rc-rot" data-act="rotate" title="Rotate (R)" aria-label="Rotate receipt">↻</button></div>'
            : '<div class="mw-rc-nophoto">No photo</div>';
        var checks = (it.checks || []).map(function (c) {
            return '<span class="' + (c.ok ? '' : 'is-bad') + '">' + (c.ok ? '✓ ' : '⚠ ') + esc(c.message) + '</span>';
        }).concat((it.anomalies || []).filter(function (a) { return a.code !== 'DUPLICATE_DAY'; }).map(function (a) {
            return '<span class="is-bad" title="Receipts system anomaly rule ' + esc(a.code) + '">⚠ ' + esc(a.detail) + '</span>';
        })).concat(it.bank ? ['<span title="' + esc(it.bank.description || '') + '">🏦 Matched to the bank: ' + esc(it.bank.date || '') + ' · ' + money(Math.abs(it.bank.amount)) + '</span>'] : [])
          .join(' &nbsp; ');

        // Always editable: the value shown is Penny's (or your saved draft); type over it.
        var d = it.saved_draft || null;
        var cur = function (f) { return d && Object.prototype.hasOwnProperty.call(d, f) ? d[f] : val(s, f); };
        var mine = function (f) {      // differs from Penny's suggestion → your edit
            var a = cur(f), b = val(s, f);
            if (f === 'asset_tag') { a = a || 'none'; b = b || 'none'; }
            if (f === 'vendor') { a = venNow; b = venSug; }
            if (f === 'expense_date') { a = dateNow; b = it.date || ''; }
            return String(a == null ? '' : a) !== String(b == null ? '' : b);
        };
        var venSug = val(s, 'vendor') || it.vendor || '';
        var venNow = d && d.vendor != null ? d.vendor : venSug;
        var venIdNow = d && d.vendor_id ? d.vendor_id : (venNow === it.vendor ? (it.vendor_id || '') : '');
        var venCtl = '<div class="mw-rc-job mw-rc-ven">' +
              '<input class="mw-rc-in" data-f="vendor" data-vensearch type="search" autocomplete="off" placeholder="Who sold it? Search or type a new one…" value="' + esc(venNow) + '" aria-label="Vendor">' +
              '<input type="hidden" data-f="vendor_id" value="' + esc(venIdNow) + '">' +
              '<div class="mw-rc-jobres" hidden></div>' +
            '</div>';
        var dateNow = d && d.expense_date ? d.expense_date : (it.date || '');
        var dateCtl = '<button type="button" class="mw-datepicker-trigger mw-rc-date" data-mw-dp-commit="input" data-mw-dp-target="#mw-rc-date" aria-haspopup="true" aria-expanded="false" aria-label="Receipt date">' +
              '<svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
              '<span class="mw-datepicker-date" data-mw-dp-label></span>' +
              '<svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>' +
            '</button><input type="date" id="mw-rc-date" data-f="expense_date" hidden value="' + esc(dateNow) + '">';
        var tagNow = cur('asset_tag') || 'none';
        var catOpts = CATEGORIES.map(function (c) {
            return '<option' + (c === cur('accounting_category') ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
        var tagOpts = ['none', 'truck', 'equipment', 'stock'].map(function (t) {
            return '<option value="' + t + '"' + (t === tagNow ? ' selected' : '') + '>' + esc(tagLabel(t)) + '</option>';
        }).join('');
        var jobNow = cur('job');
        var jobSug = val(s, 'job');
        if (jobSug && it.job_title) jobLabels[jobSug] = it.job_title;
        var jobLabel = jobNow ? (jobLabels[jobNow] || ('Job #' + jobNow)) : '';
        var jobCtl = '<div class="mw-rc-job">' +
              '<input class="mw-rc-in" data-jobsearch type="search" autocomplete="off" placeholder="None — search jobs…" value="' + esc(jobLabel) + '" aria-label="Search jobs">' +
              '<input type="hidden" data-f="job" value="' + esc(jobNow || '') + '">' +
              (jobNow ? '<button type="button" class="mw-rc-jobclear" data-act="jobclear" aria-label="No job">×</button>' : '') +
              '<div class="mw-rc-jobres" hidden></div>' +
            '</div>';
        var amt = function (f, label) {
            return '<label class="mw-rc-amt"><span>' + label + '</span><input class="mw-rc-in" data-f="' + f + '" type="number" step="0.01" inputmode="decimal" value="' + esc(cur(f)) + '" aria-label="' + label + '"></label>';
        };
        var tagFld = function (f, label, control, conff) {
            return '<div class="mw-rc-fld' + (mine(f) ? ' is-yours' : '') + '" data-fld="' + f + '"><div class="mw-k">' + esc(label) +
                ' ' + conf(s, conff || f) + '<span class="mw-rc-yours">edited by you</span></div>' +
                '<div class="mw-v">' + control + '</div>' + why(s, conff || f) + '</div>';
        };
        var fields =
            tagFld('vendor', 'Vendor', venCtl + vendorStrengthLine(it)) +
            tagFld('expense_date', 'Receipt date', dateCtl) +
            tagFld('accounting_category', 'Category', '<select class="mw-rc-in' + (changed(it, 'accounting_category') ? ' mw-rc-changed' : '') + '" data-f="accounting_category">' + catOpts + '</select>') +
            tagFld('asset_tag', 'For', '<select class="mw-rc-in" data-f="asset_tag">' + tagOpts + '</select>') +
            tagFld('total', 'Total · GST · PST', '<div class="mw-rc-amts">' + amt('total', 'Total') + amt('gst', 'GST') + amt('pst', 'PST') + '</div>') +
            tagFld('job', 'Job', jobCtl);

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
            return '<div class="mw-rc-meta">' +
                  (val(s, 'vendor') && it.vendor && val(s, 'vendor') !== it.vendor ? 'Recorded as <s>' + esc(it.vendor) + '</s>' : '') +
                  (it.submitted_by ? ' · from ' + esc(it.submitted_by) : '') + '</div>' +
                '<div class="mw-rc-fields">' + fields + '</div>' +
                itemsHtml(it) +
                (checks ? '<div class="mw-rc-checks">' + checks + '</div>' : '') +
                (s.notes ? '<div class="mw-rc-checks' + (fullNotes ? '' : ' mw-rc-note') + '" title="' + esc(s.notes) + '">📝 ' + esc(s.notes) + '</div>' : '') +
                '<div class="mw-rc-actions">' +
                  '<button type="button" class="mw-rc-ok" data-act="approve">✓ Approve</button>' +
                  '<button type="button" class="mw-rc-ed" data-act="draft">💾 Save draft</button>' +
                  '<button type="button" class="mw-rc-ed" data-act="recheck" title="Penny reads this receipt again, with the photo (about 5¢)"' + (rechecking ? ' disabled' : '') + '>' + (rechecking ? '⏳ Re-reading…' : '🔄 Re-check') + '</button>' +
                  '<button type="button" class="mw-rc-ed mw-rc-rej" data-act="reject" title="Reject this receipt, with a reason">✕ Reject</button>' +
                  '<button type="button" class="mw-rc-sk" data-act="next">Skip →</button>' +
                '</div>' +
                (rejecting ? '<div class="mw-rc-rejbox"><input class="mw-rc-in" data-rejreason placeholder="Why? e.g. personal purchase, not ours, duplicate…" aria-label="Reason for rejecting">' +
                    '<button type="button" class="mw-rc-ed mw-rc-rej" data-act="reject-go">Reject it</button>' +
                    '<button type="button" class="mw-rc-sk" data-act="reject-cancel">Cancel</button></div>' : '') +
                '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>';
        };

        var html = top(false) + '<div class="mw-rc-slide">' + photo + '<div>' + (zoomed ? '' : detail(false)) + '</div></div>';
        if (zoomed) {
            html += '<div class="mw-rc-zoom" role="dialog" aria-modal="true" aria-label="Receipt and Penny\'s read">' +
                '<div class="mw-rc-zoom-img' + (zoomBig ? ' is-big' : '') + '">' +
                  (it.image_url ? '<img src="' + esc(it.image_url) + '" data-rot alt="Receipt photo" data-act="bigger" title="Click to zoom">' : '<div class="mw-rc-nophoto">No photo</div>') +
                '</div>' +
                (it.image_url ? '<button type="button" class="mw-rc-rot mw-rc-rot-zoom" data-act="rotate" title="Rotate (R)" aria-label="Rotate receipt">↻</button>' : '') +
                '<div class="mw-rc-zoom-side">' + top(true) + detail(true) +
                  '<div class="mw-rc-zoom-hint">Click the receipt to zoom · R rotate · type straight into any field · A approve · S save draft · → skip · Esc close</div>' +
                '</div>' +
              '</div>';
        }
        datePopups.forEach(function (p) { if (p.parentNode) p.parentNode.removeChild(p); });
        root.innerHTML = html;
        applyRotation();
        var isum = root.querySelector('.mw-rc-isum');
        if (isum && queue[idx]) isum.textContent = itemsCheck(queue[idx]);
        if (window.mwInitDatePickers) {
            var before = document.querySelectorAll('.mw-datepicker-popup').length;
            window.mwInitDatePickers(root);
            datePopups = Array.prototype.slice.call(document.querySelectorAll('.mw-datepicker-popup'), before);
        }
    }

    /** "I know Chevron: 3 in a row right" — from your past approvals of this vendor. */
    function vendorStrengthLine(it) {
        var v = it.vendor_strength;
        if (!v) return '<div class="mw-rc-vstrength">New to me — no approved receipts from this vendor yet</div>';
        var dots = '';
        for (var i = 0; i < v.need; i++) dots += '<em class="' + (i < v.run ? 'on' : '') + '"></em>';
        return '<div class="mw-rc-vstrength"><i class="mw-hv-dots">' + dots + '</i> ' +
            (v.trusted ? 'Trusted — ' : 'I know them: ') + v.run + ' in a row right · ' + v.right + ' of ' + v.seen + ' unchanged</div>';
    }

    // ── Line items: edited in place, saved straight away (same API as the receipts page) ──
    function itemsSum(items) {
        return items.reduce(function (a, i) { return a + (parseFloat(i.line_total) || 0); }, 0);
    }
    function pennyItems(it) {
        var li = it.suggestion && it.suggestion.line_items;
        var v = li && li.value !== undefined ? li.value : li;
        return Array.isArray(v) ? v.filter(function (x) { return x && x.name; }) : [];
    }
    function itemsHtml(it) {
        var items = it.items || [];
        var locked = it.status === 'forwarded';
        var rows = items.map(function (i) {
            return '<div class="mw-rc-item" data-item="' + esc(i.id) + '">' +
                '<div class="mw-rc-iname"><input class="mw-rc-in" data-iname autocomplete="off" value="' + esc(i.name) + '" aria-label="Item name — type to search your products"' + (locked ? ' disabled' : '') + '>' +
                  (i.product_id ? '<span class="mw-rc-iprod-tag" title="Linked to your product: ' + esc(i.product_name || '') + '">📦</span>' : '') +
                  '<div class="mw-rc-jobres mw-rc-iprod" hidden></div></div>' +
                '<input class="mw-rc-in" data-iqty type="number" step="any" inputmode="decimal" value="' + esc(i.quantity) + '" aria-label="Quantity"' + (locked ? ' disabled' : '') + '>' +
                '<input class="mw-rc-in" data-itotal type="number" step="0.01" inputmode="decimal" value="' + esc(Number(i.line_total).toFixed(2)) + '" aria-label="Line total"' + (locked ? ' disabled' : '') + '>' +
                (locked ? '' : '<button type="button" class="mw-rc-idel" data-idel aria-label="Remove item">✕</button>') +
              '</div>';
        }).join('');
        var pi = pennyItems(it);
        var piSum = itemsSum(pi.map(function (x) { return { line_total: x.amount }; }));
        var differs = pi.length && (pi.length !== items.length || Math.abs(piSum - itemsSum(items)) > 0.01);
        return '<div class="mw-rc-items"><div class="mw-k">Items <span class="mw-rc-isum"></span></div>' +
            (items.length ? '<div class="mw-rc-item mw-rc-ihead"><span>Item</span><span>Qty</span><span>Total</span><span></span></div>' + rows
                          : '<div class="mw-rc-inone">No items read from this receipt yet.</div>') +
            (locked ? '' : '<div class="mw-rc-iactions"><button type="button" class="mw-rc-ed" data-iadd>+ Add item</button>' +
                (differs ? '<button type="button" class="mw-rc-ed" data-ipenny title="Replace these with the ' + pi.length + ' items Penny read">⭐ Use Penny\'s items (' + pi.length + ' · ' + money(piSum) + ')</button>' : '') +
                '<span class="mw-rc-isave"></span></div>') +
          '</div>';
    }
    /** Items vs the receipt's subtotal (total − GST − PST as typed in the fields). */
    function itemsCheck(it) {
        var items = it.items || [];
        if (!items.length) return '';
        var t = parseFloat((root.querySelector('[data-f="total"]') || {}).value);
        var g = parseFloat((root.querySelector('[data-f="gst"]') || {}).value) || 0;
        var p = parseFloat((root.querySelector('[data-f="pst"]') || {}).value) || 0;
        if (isNaN(t)) t = parseFloat(val(it.suggestion || {}, 'total'));
        if (isNaN(t)) return money(itemsSum(items));
        var sub = t - g - p, sum = itemsSum(items), ok = Math.abs(sub - sum) <= 0.05;
        return (ok ? '✓ ' : '⚠ ') + money(sum) + (ok ? ' — matches the subtotal' : ' vs subtotal ' + money(sub));
    }
    // ── Product search on an item's name (your products list, same search as the receipts page) ──
    var prodTimer = null;
    function searchProducts(input) {
        var res = input.parentNode.querySelector('.mw-rc-iprod');
        var q = input.value.trim();
        clearTimeout(prodTimer);
        if (q.length < 2) { res.hidden = true; return; }
        prodTimer = setTimeout(function () {
            fetch('/crm/products/api-products.php?action=list-products&search=' + encodeURIComponent(q), { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (document.activeElement !== input) return;
                    var rows = (d && d.success && d.products) ? d.products.slice(0, 8) : [];
                    res.innerHTML = rows.length
                        ? rows.map(function (p) {
                            return '<button type="button" data-pick-prod="' + esc(p.id) + '" data-label="' + esc(p.name) + '">📦 ' + esc(p.name) +
                                (p.sku ? '<small>' + esc(p.sku) + '</small>' : '') + '</button>';
                          }).join('') + '<div class="mw-rc-jobnone">Or keep typing — it saves as written</div>'
                        : '<div class="mw-rc-jobnone">No product matches — it saves as written</div>';
                    res.hidden = false;
                }).catch(function () {});
        }, 250);
    }
    var picking = false;    // a product result is being picked: don't save the half-typed name on blur
    function pickProduct(btn) {
        var row = btn.closest('.mw-rc-item[data-item]');
        var id = parseInt(row.getAttribute('data-item'), 10);
        var pid = parseInt(btn.getAttribute('data-pick-prod'), 10);
        var name = btn.getAttribute('data-label');
        row.querySelector('[data-iname]').value = name;
        btn.parentNode.hidden = true;
        itemSaved('Linking ' + name + '…');
        lineApi({ action: 'update_line_item', line_item_id: id, name: name })
            .then(function () { return lineApi({ action: 'link_product', line_item_id: id, product_id: pid }); })
            .then(function () {
                var item = (queue[idx].items || []).filter(function (x) { return x.id === id; })[0];
                if (item) { item.name = name; item.product_id = pid; item.product_name = name; }
                picking = false;
                render('Linked to your product: ' + name);
            })
            .catch(function (e) { picking = false; itemSaved(e.message, true); });
    }

    function lineApi(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch('/crm/api/expenses.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (!d || !d.success) throw new Error((d && d.error) || 'Could not save'); return d; });
    }
    function itemSaved(text, bad) {
        var el = root.querySelector('.mw-rc-isave');
        if (el) { el.textContent = text; el.classList.toggle('is-bad', !!bad); }
        var sum = root.querySelector('.mw-rc-isum');
        if (sum && queue[idx]) sum.textContent = itemsCheck(queue[idx]);
    }
    function saveItemRow(row) {
        var it = queue[idx];
        var id = parseInt(row.getAttribute('data-item'), 10);
        var item = (it.items || []).filter(function (i) { return i.id === id; })[0];
        if (!item) return;
        var name = row.querySelector('[data-iname]').value.trim();
        var qty = parseFloat(row.querySelector('[data-iqty]').value);
        var tot = parseFloat(row.querySelector('[data-itotal]').value);
        if (!name) { itemSaved('An item needs a name', true); return; }
        var body = { action: 'update_line_item', line_item_id: id, name: name };
        if (!isNaN(qty) && qty !== item.quantity) body.quantity = qty;
        if (!isNaN(tot) && Math.abs(tot - item.line_total) > 0.004) body.line_total = tot;
        else if (body.quantity !== undefined && item.unit_price == null) body.line_total = item.line_total;
        itemSaved('Saving…');
        lineApi(body).then(function (d) {
            var li = d.line_item || {};
            item.name = li.name || name;
            item.quantity = li.quantity != null ? parseFloat(li.quantity) : qty;
            item.line_total = li.line_total != null ? parseFloat(li.line_total) : tot;
            item.unit_price = li.unit_price != null ? parseFloat(li.unit_price) : item.unit_price;
            row.querySelector('[data-itotal]').value = Number(item.line_total).toFixed(2);
            itemSaved('Saved ✓');
        }).catch(function (e) { itemSaved(e.message, true); });
    }
    function addItem(name, qty, total) {
        var it = queue[idx];
        return lineApi({ action: 'add_line_item', expense_id: it.expense_id, name: name, quantity: qty, line_total: total })
            .then(function (d) {
                var li = d.line_item || {};
                (it.items = it.items || []).push({ id: parseInt(li.id, 10), name: li.name || name, quantity: parseFloat(li.quantity || qty),
                    unit_price: li.unit_price != null ? parseFloat(li.unit_price) : null, line_total: parseFloat(li.line_total != null ? li.line_total : total) });
            });
    }
    function usePennyItems() {
        var it = queue[idx];
        var pi = pennyItems(it);
        itemSaved('Swapping in Penny\'s items…');
        var chain = Promise.resolve();
        (it.items || []).slice().forEach(function (i) {
            chain = chain.then(function () { return lineApi({ action: 'delete_line_item', line_item_id: i.id }); })
                         .then(function () { it.items = it.items.filter(function (x) { return x.id !== i.id; }); });
        });
        pi.forEach(function (x) { chain = chain.then(function () { return addItem(x.name, 1, Number(x.amount) || 0); }); });
        chain.then(function () { render('Using Penny\'s items — check them before you approve'); })
             .catch(function (e) { render('Items: ' + e.message); });
    }

    /** Turn the receipt photo(s) for this receipt; sideways turns shrink to fit their box. */
    function applyRotation() {
        var it = queue[idx];
        if (!it) return;
        var deg = rotation[it.expense_id] || 0;
        root.querySelectorAll('img[data-rot]').forEach(function (img) {
            var fit = function () {
                var box = img.parentElement;
                var s = 1;
                if (deg % 180 !== 0 && img.offsetWidth && img.offsetHeight) {
                    s = Math.min(1, (box.clientWidth - 8) / img.offsetHeight, (box.clientHeight - 8) / img.offsetWidth);
                }
                img.style.transform = deg ? 'rotate(' + deg + 'deg) scale(' + s.toFixed(3) + ')' : '';
            };
            if (img.complete) fit(); else img.addEventListener('load', fit, { once: true });
        });
    }
    function rotate() {
        var it = queue[idx];
        if (!it) return;
        rotation[it.expense_id] = ((rotation[it.expense_id] || 0) + 90) % 360;
        applyRotation();
    }

    function setZoom(on) {
        zoomed = !!on;
        if (!zoomed) zoomBig = false;
        document.body.classList.toggle('mw-rc-zoom-open', zoomed);
    }

    function fld(k, v, w) {
        return '<div class="mw-rc-fld"><div class="mw-k">' + esc(k) + '</div><div class="mw-v">' + v + '</div>' + w + '</div>';
    }

    // ── Job search (title, address, client, plan number) ─────────────────
    function jobResults(box, rows, penny) {
        var res = box.querySelector('.mw-rc-jobres');
        var html = '<button type="button" data-pick-stock="1">🏪 Shop stock — no job</button>';
        if (penny) html += '<button type="button" data-pick-job="' + esc(penny.id) + '" data-label="' + esc(penny.label) + '">⭐ Penny\'s pick: ' + esc(penny.label) + '</button>';
        rows.forEach(function (j) {
            var label = (j.title || j.service_type || 'Job') + ' — ' + (j.address || '') + (j.plan_number ? ' (' + j.plan_number + ')' : '');
            html += '<button type="button" data-pick-job="' + esc(j.id) + '" data-label="' + esc(label) + '">' + esc(label) +
                (j.contact_name ? '<small>' + esc(j.contact_name) + (j.status && j.status !== 'active' ? ' · ' + esc(j.status) : '') + '</small>' : '') + '</button>';
        });
        res.innerHTML = html + (rows.length || penny ? '' : '<div class="mw-rc-jobnone">No jobs match — keep typing</div>');
        res.hidden = false;
    }

    function searchJobs(input) {
        var box = input.closest('.mw-rc-job');
        var it = queue[idx];
        var sug = it && it.suggestion && it.suggestion.job ? it.suggestion.job.value : null;
        var penny = sug ? { id: sug, label: jobLabels[sug] || ('Job #' + sug) } : null;
        var q = input.value.trim();
        clearTimeout(jobTimer);
        if (q.length < 2) { jobResults(box, [], penny); return; }
        jobTimer = setTimeout(function () {
            fetch('/crm/api/expenses.php?action=search_jobs&q=' + encodeURIComponent(q), { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (document.activeElement === input) jobResults(box, (d && d.jobs) || [], null); })
                .catch(function () {});
        }, 250);
    }

    function setJob(box, id, label) {
        var hidden = box.querySelector('[data-f="job"]');
        hidden.value = id || '';
        box.querySelector('[data-jobsearch]').value = id ? label : '';
        if (id) jobLabels[id] = label;
        var res = box.querySelector('.mw-rc-jobres');
        res.hidden = true;
        hidden.dispatchEvent(new Event('input', { bubbles: true }));   // updates "edited by you"
    }

    // ── Vendor search (name or alias); free typing keeps a new vendor's name ──
    function vendorResults(box, rows, typed) {
        var it = queue[idx];
        var s = (it && it.suggestion) || {};
        var res = box.querySelector('.mw-rc-jobres');
        var html = '';
        var pennyV = val(s, 'vendor');
        if (pennyV && pennyV.toLowerCase() !== typed.toLowerCase()) html += '<button type="button" data-pick-ven="" data-label="' + esc(pennyV) + '">⭐ Penny read: ' + esc(pennyV) + '</button>';
        if (it && it.vendor && it.vendor !== pennyV && it.vendor.toLowerCase() !== typed.toLowerCase()) html += '<button type="button" data-pick-ven="' + esc(it.vendor_id || '') + '" data-label="' + esc(it.vendor) + '">Keep: ' + esc(it.vendor) + '</button>';
        var exact = false;
        rows.forEach(function (v) {
            if (v.name.toLowerCase() === typed.toLowerCase()) exact = true;
            html += '<button type="button" data-pick-ven="' + esc(v.id) + '" data-label="' + esc(v.name) + '">' + esc(v.name) +
                (v.default_accounting_category ? '<small>' + esc(v.default_accounting_category) + '</small>' : '') + '</button>';
        });
        if (typed.length >= 3 && !exact) html += '<div class="mw-rc-jobnone">Not on your list? Keep typing — approving adds “' + esc(typed) + '” as a new vendor.</div>';
        res.innerHTML = html;
        res.hidden = html === '';
    }

    function searchVendors(input) {
        var box = input.closest('.mw-rc-ven');
        var q = input.value.trim();
        clearTimeout(venTimer);
        if (q.length < 2) { vendorResults(box, [], q); return; }
        venTimer = setTimeout(function () {
            fetch('/crm/api/vendors.php?action=search&q=' + encodeURIComponent(q), { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (document.activeElement === input) vendorResults(box, (d && d.vendors) || [], q); })
                .catch(function () {});
        }, 250);
    }

    function setVendor(box, id, name) {
        var input = box.querySelector('[data-vensearch]');
        input.value = name;
        box.querySelector('[data-f="vendor_id"]').value = id || '';
        box.querySelector('.mw-rc-jobres').hidden = true;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        box.querySelector('[data-f="vendor_id"]').value = id || '';   // the input handler clears it for typing
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
                if (d && d.ok && d.blocked) {
                    it.saved_draft = values;        // stays put, so the reason is read on this receipt
                    render(d.message);
                } else if (d && d.ok && d.saved_draft) {
                    it.saved_draft = values;
                    queue.splice(idx, 1);
                    queue.push(it);                 // come back to it last
                    render(d.message || 'Saved as a draft');
                } else if (d && d.ok) {
                    queue.splice(idx, 1);
                    render(d.message || 'Approved');
                    if (queue.length < 3) { load(d.message || 'Approved'); }
                } else {
                    render((d && (d.message || d.error)) || 'Could not save');
                }
            })
            .catch(function () { busy = false; render('Network error — try again'); });
    }
    function approve() { submit(false); }

    /** Reject with a reason — the same reject as the receipts page; it leaves the card. */
    function rejectIt() {
        if (busy || !queue.length) return;
        var box = root.querySelector('[data-rejreason]');
        var reason = box ? box.value.trim() : '';
        if (!reason) { if (box) box.focus(); return; }
        var it = queue[idx];
        busy = true;
        post({ mode: 'reject', suggestion_id: it.suggestion_id, reason: reason }).then(function (d) {
            busy = false;
            if (d && d.ok) {
                rejecting = false;
                queue.splice(idx, 1);
                render(d.message || 'Rejected');
                if (queue.length < 3) load(d.message || 'Rejected');
            } else {
                render((d && (d.message || d.error)) || 'Could not reject');
            }
        }).catch(function () { busy = false; render('Network error — try again'); });
    }

    /** Penny reads this receipt again, with the photo; her new read replaces the old one. */
    function recheck() {
        if (busy || rechecking || !queue.length) return;
        var it = queue[idx];
        rechecking = true;
        render('Penny is taking another look, with the photo — about 20 seconds…');
        post({ mode: 'recheck', suggestion_id: it.suggestion_id })
            .then(function (d) {
                rechecking = false;
                if (!(d && d.ok)) { render((d && (d.message || d.error)) || 'Re-check failed'); return; }
                return fetch(API + '?mode=queue&limit=15', { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (q) {
                        queue = (q && q.ok && q.queue) ? q.queue : queue;
                        var at = queue.findIndex(function (x) { return x.expense_id === it.expense_id; });
                        idx = at >= 0 ? at : 0;
                        render(d.message || 'Penny took another look');
                    });
            })
            .catch(function () { rechecking = false; render('Network error — try again'); });
    }

    root.addEventListener('click', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-iadd')) {
            addItem('New item', 1, 0).then(function () {
                render();
                var names = root.querySelectorAll('[data-iname]');
                if (names.length) { names[names.length - 1].focus(); names[names.length - 1].select(); }
            }).catch(function (err) { itemSaved(err.message, true); });
            return;
        }
        if (e.target.hasAttribute && e.target.hasAttribute('data-idel')) {
            var row = e.target.closest('.mw-rc-item');
            var delId = parseInt(row.getAttribute('data-item'), 10);
            lineApi({ action: 'delete_line_item', line_item_id: delId }).then(function () {
                queue[idx].items = (queue[idx].items || []).filter(function (x) { return x.id !== delId; });
                row.parentNode.removeChild(row);
                itemSaved('Removed ✓');
            }).catch(function (err) { itemSaved(err.message, true); });
            return;
        }
        if (e.target.hasAttribute && e.target.hasAttribute('data-ipenny')) { usePennyItems(); return; }
        var dr = e.target.getAttribute && e.target.getAttribute('data-dup-remove');
        if (dr) { settleDupe(dr, false); return; }
        if (e.target.getAttribute && e.target.getAttribute('data-dup-not')) { settleDupe(null, true); return; }
        var zsrc = e.target.getAttribute && e.target.getAttribute('data-zoomsrc');
        if (zsrc) { window.open(zsrc, '_blank', 'noopener'); return; }
        if (e.target.getAttribute('data-zoom')) { setZoom(true); render(); return; }
        var stockBtn = e.target.closest && e.target.closest('[data-pick-stock]');
        if (stockBtn) {
            var jb = stockBtn.closest('.mw-rc-job');
            setJob(jb, '', '');
            var tagSel = root.querySelector('[data-f="asset_tag"]');
            if (tagSel) { tagSel.value = 'stock'; tagSel.dispatchEvent(new Event('input', { bubbles: true })); }
            return;
        }
        var ven = e.target.closest && e.target.closest('[data-pick-ven]');
        if (ven) { setVendor(ven.closest('.mw-rc-ven'), ven.getAttribute('data-pick-ven'), ven.getAttribute('data-label')); return; }
        var pick = e.target.closest && e.target.closest('[data-pick-job]');
        if (pick) { setJob(pick.closest('.mw-rc-job'), pick.getAttribute('data-pick-job'), pick.getAttribute('data-label')); return; }
        if (e.target.getAttribute('data-act') === 'jobclear') { setJob(e.target.closest('.mw-rc-job'), '', ''); return; }
        var act = e.target.getAttribute('data-act');
        if (!act) return;
        if (act === 'close') { setZoom(false); render(); }
        else if (act === 'bigger') { zoomBig = !zoomBig; render(); }
        else if (act === 'rotate') { e.stopPropagation(); rotate(); }
        else if (act === 'approve') approve();
        else if (act === 'draft') submit(true);
        else if (act === 'recheck') recheck();
        else if (act === 'reject') { rejecting = true; render(); var rb = root.querySelector('[data-rejreason]'); if (rb) rb.focus(); }
        else if (act === 'reject-cancel') { rejecting = false; render(); }
        else if (act === 'reject-go') rejectIt();
        else if (act === 'next') { rejecting = false; idx = (idx + 1) % Math.max(1, queue.length); render(); }
        else if (act === 'prev') { rejecting = false; idx = (idx - 1 + queue.length) % Math.max(1, queue.length); render(); }
    });

    root.addEventListener('keydown', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-rejreason')) {
            if (e.key === 'Enter') { e.preventDefault(); rejectIt(); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); rejecting = false; render(); }
        }
    });

    root.addEventListener('mousedown', function (e) {
        var b = e.target.closest && e.target.closest('[data-pick-prod]');
        if (b) { picking = true; e.preventDefault(); pickProduct(b); }
    });
    root.addEventListener('change', function (e) {
        var row = e.target.closest && e.target.closest('.mw-rc-item[data-item]');
        if (row && queue.length && !picking) saveItemRow(row);
    });

    root.addEventListener('focusin', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-jobsearch')) { e.target.select(); searchJobs(e.target); }
        if (e.target.hasAttribute && e.target.hasAttribute('data-vensearch')) { e.target.select(); searchVendors(e.target); }
    });
    root.addEventListener('focusout', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-iname')) {
            var pr = e.target.parentNode.querySelector('.mw-rc-iprod');
            setTimeout(function () { if (pr) pr.hidden = true; }, 200);
            return;
        }
        if (!(e.target.hasAttribute && e.target.hasAttribute('data-vensearch'))) return;
        var box = e.target.closest('.mw-rc-ven');
        setTimeout(function () { if (!box.contains(document.activeElement)) box.querySelector('.mw-rc-jobres').hidden = true; }, 200);
    });
    root.addEventListener('focusout', function (e) {
        if (!(e.target.hasAttribute && e.target.hasAttribute('data-jobsearch'))) return;
        var box = e.target.closest('.mw-rc-job');
        setTimeout(function () {                   // let a click on a result land first
            if (box.contains(document.activeElement)) return;
            box.querySelector('.mw-rc-jobres').hidden = true;
            var id = box.querySelector('[data-f="job"]').value;
            e.target.value = id ? (jobLabels[id] || ('Job #' + id)) : '';   // typed text without a pick doesn't count
        }, 200);
    });

    root.addEventListener('input', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-jobsearch')) { searchJobs(e.target); return; }
        if (e.target.hasAttribute && e.target.hasAttribute('data-iname')) { searchProducts(e.target); return; }
        if (e.target.hasAttribute && e.target.hasAttribute('data-vensearch')) {
            var vbox = e.target.closest('.mw-rc-ven');
            vbox.querySelector('[data-f="vendor_id"]').value = '';   // typed, not picked
            if (e.isTrusted) searchVendors(e.target);
            var vit = queue[idx];
            var vsug = val(vit.suggestion || {}, 'vendor') || vit.vendor || '';
            e.target.closest('.mw-rc-fld').classList.toggle('is-yours', e.target.value.trim().toLowerCase() !== vsug.toLowerCase());
            return;
        }
        var f = e.target.getAttribute && e.target.getAttribute('data-f');
        if ((f === 'total' || f === 'gst' || f === 'pst') && queue.length) {
            var isum = root.querySelector('.mw-rc-isum');
            if (isum) isum.textContent = itemsCheck(queue[idx]);
        }
        if (!f || !queue.length) return;
        var s = queue[idx].suggestion || {};
        var key = (f === 'gst' || f === 'pst') ? 'total' : f;
        var box = root.querySelector('.mw-rc-fld[data-fld="' + key + '"]');
        if (!box) return;
        var edited = Array.prototype.some.call(box.querySelectorAll('[data-f]'), function (el) {
            var name = el.getAttribute('data-f');
            var sv = s[name] ? s[name].value : null;
            if (name === 'asset_tag') sv = sv || 'none';
            if (name === 'expense_date') sv = queue[idx].date || '';
            if (el.type === 'number') return el.value !== '' && Math.abs(parseFloat(el.value) - parseFloat(sv)) > 0.004;
            return String(el.value) !== String(sv == null ? '' : sv);
        });
        box.classList.toggle('is-yours', edited);
    });

    document.addEventListener('keydown', function (e) {
        var t = e.target.tagName;
        if (t === 'INPUT' || t === 'SELECT' || t === 'TEXTAREA' || e.metaKey || e.ctrlKey || e.altKey) return;
        if (zoomed && e.key === 'Escape') { e.preventDefault(); setZoom(false); render(); return; }
        if ((e.key === 'r' || e.key === 'R') && queue.length && !dupes.length && root.offsetParent) { e.preventDefault(); rotate(); return; }
        if (!root.offsetParent || !queue.length) return;
        if (e.key === 'a' || e.key === 'A') { e.preventDefault(); approve(); }
        else if (e.key === 's' || e.key === 'S') { e.preventDefault(); submit(true); }
        else if (e.key === 'ArrowRight') { idx = (idx + 1) % queue.length; render(); }
        else if (e.key === 'ArrowLeft') { idx = (idx - 1 + queue.length) % queue.length; render(); }
    });

    // ── Questions for you (unbilled materials) ─────────────────────────────
    var pqBox = document.getElementById('mw-pq');
    function loadQuestions() {
        if (!pqBox) return;
        fetch(API + '?mode=questions', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var qs = (d && d.ok && d.questions) || [];
                var found = d && d.found_to_bill_month ? Number(d.found_to_bill_month) : 0;
                if (!qs.length && !found) { pqBox.hidden = true; return; }
                pqBox.hidden = false;
                var head = qs.length === 1 ? 'A quick question for you' : qs.length ? qs.length + ' quick questions for you' : 'Nothing to ask you right now';
                pqBox.innerHTML = '<div class="mw-pq-head"><b>' + head + '</b>' +
                    (found ? '<span>Found to bill this month: <b>$' + found.toFixed(2) + '</b></span>' : '') + '</div>' +
                    qs.map(function (q) {
                        if (q.kind === 'service_account') {
                            return '<div class="mw-pq-item" data-q="' + esc(q.id) + '"><p>' + esc(q.question) + '</p>' +
                                '<div class="mw-pq-btns"><select class="mw-rc-in" data-pq-acct aria-label="Income account"><option value="">— pick an income account —</option>' +
                                (q.choices || []).map(function (a) { return '<option value="' + esc(a.id) + '">' + esc(a.code + ' ' + a.name) + '</option>'; }).join('') +
                                '</select><button type="button" data-ans="account">✓ Save</button></div></div>';
                        }
                        return '<div class="mw-pq-item" data-q="' + esc(q.id) + '"><p>' + esc(q.question) + '</p>' +
                            '<div class="mw-pq-btns">' +
                              '<button type="button" data-ans="invoice">🧾 Forgot — invoice it</button>' +
                              (q.contract_id ? '<button type="button" data-ans="contract">📄 In the contract</button>' : '') +
                              '<button type="button" data-ans="not_billable">🚫 Not billable</button>' +
                            '</div></div>';
                    }).join('');
            })
            .catch(function () { pqBox.hidden = true; });
    }
    if (pqBox) {
        pqBox.addEventListener('click', function (e) {
            var ans = e.target.getAttribute('data-ans');
            if (!ans) return;
            var item = e.target.closest('.mw-pq-item');
            var id = item.getAttribute('data-q');
            var acctSel = item.querySelector('[data-pq-acct]');
            if (ans === 'account' && (!acctSel || !acctSel.value)) { if (acctSel) acctSel.focus(); return; }
            item.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
            post({ mode: 'answer', question_id: id, answer: ans, account_id: acctSel ? acctSel.value : null }).then(function (d) {
                if (d && d.ok && d.invoice_url) { window.open(d.invoice_url, '_blank', 'noopener'); }
                item.innerHTML = '<p class="mw-pq-done">' + esc((d && d.message) || 'Saved') + '</p>';
                setTimeout(loadQuestions, 1500);
            }).catch(function () { item.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
        });
        loadQuestions();
    }

    load();
})();
