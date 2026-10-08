/**
 * MwUnbilledWork — "Also unbilled at this address": other unbilled work at the property being
 * invoiced (UnbilledWorkFinder via /crm/api/unbilled-work.php), shown as optional extra lines.
 *
 *   MwUnbilledWork.fetch({ visit_id: 2500 })                          → Promise<data|null>
 *   MwUnbilledWork.fetch({ property_id: 29, company_id: 7 })
 *
 *   // Inline list (invoices/create.php): ticking calls onToggle(item, checked, amount)
 *   var ctl = MwUnbilledWork.render(hostEl, data, { onToggle: fn });
 *   ctl.selections() → [{ visit_id, amount }]
 *
 *   // Confirm dialog (schedule "Complete & Invoice"): resolves the ticked selections,
 *   // [] for "invoice today only", or null when cancelled.
 *   MwUnbilledWork.prompt(data, { title: 'Complete & Invoice' }).then(function (sel) { … });
 *
 * Items: { visit_id, kind: completed|possibly_done|zero_price, badge, description, service_date,
 *          amount, suggested_amount, needs_price, evidence[], warnings[], preselect }.
 * Possibly-done lines are disabled unless data.can_mark_done (admin/manager): the server would
 * refuse them anyway. $0 lines need a price before they can be ticked.
 * Styles: .mw-unbilled-* in mowology-brand.css.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function money(n) { return '$' + (Math.round((parseFloat(n) || 0) * 100) / 100).toFixed(2); }

    function fetchList(params) {
        var q = Object.keys(params || {}).filter(function (k) { return params[k]; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch('/crm/api/unbilled-work.php?mode=list&' + q, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { return d && d.success ? d : null; })
            .catch(function () { return null; });
    }

    function render(host, data, opts) {
        opts = opts || {};
        var items = (data && data.items) || [];
        var hints = (data && data.hints) || [];
        var canMarkDone = !!(data && data.can_mark_done);
        host.innerHTML = '';
        host.classList.add('mw-unbilled');
        if (!items.length && !hints.length) { host.hidden = true; return { selections: function () { return []; } }; }
        host.hidden = false;

        var head = document.createElement('div');
        head.className = 'mw-unbilled-head';
        head.innerHTML = '<strong>Also unbilled at this address</strong>' +
            '<span class="mw-unbilled-sub">Last 60 days · tick to add to this invoice</span>';
        host.appendChild(head);

        var rows = [];
        items.forEach(function (it) {
            var locked = it.kind === 'possibly_done' && !canMarkDone;
            var row = document.createElement('label');
            row.className = 'mw-unbilled-row mw-unbilled-' + it.kind + (locked ? ' is-locked' : '');
            var amount = it.amount > 0 ? it.amount : (it.suggested_amount || '');
            row.innerHTML =
                '<input type="checkbox" class="mw-unbilled-check"' + (locked ? ' disabled' : '') + '>' +
                '<span class="mw-unbilled-body">' +
                    '<span class="mw-unbilled-badge">' + esc(it.badge) + '</span>' +
                    '<span class="mw-unbilled-desc">' + esc(it.description) + '</span>' +
                    '<span class="mw-unbilled-evidence">' + esc((it.evidence || []).join(' ')) + '</span>' +
                    (it.warnings && it.warnings.length ? '<span class="mw-unbilled-warn">' + esc(it.warnings.join(' ')) + '</span>' : '') +
                    (locked ? '<span class="mw-unbilled-warn">The office has to confirm this one.</span>' : '') +
                '</span>' +
                '<span class="mw-unbilled-amount">' +
                    (it.needs_price
                        ? '<input type="number" step="0.01" min="0" class="form-control form-control-sm mw-unbilled-price" placeholder="Price" value="' + esc(amount) + '">'
                        : money(it.amount)) +
                '</span>';
            var cb = row.querySelector('.mw-unbilled-check');
            var price = row.querySelector('.mw-unbilled-price');
            function currentAmount() { return price ? (parseFloat(price.value) || 0) : it.amount; }
            function sync() {
                if (price && cb.checked && currentAmount() <= 0) cb.checked = false;   // a $0 line needs a price
                row.classList.toggle('is-checked', cb.checked);
            }
            cb.checked = !!it.preselect && !locked;
            sync();
            cb.addEventListener('change', function () {
                sync();
                if (opts.onToggle) opts.onToggle(it, cb.checked, currentAmount());
            });
            if (price) price.addEventListener('input', function () {
                if (currentAmount() <= 0 && cb.checked) { cb.checked = false; sync(); if (opts.onToggle) opts.onToggle(it, false, 0); }
                else if (cb.checked && opts.onToggle) opts.onToggle(it, true, currentAmount());
            });
            rows.push({ it: it, cb: cb, amount: currentAmount });
            host.appendChild(row);
            if (cb.checked && opts.onToggle) opts.onToggle(it, true, currentAmount());
        });

        hints.forEach(function (h) {
            var p = document.createElement('div');
            p.className = 'mw-unbilled-hint';
            p.textContent = h.text;
            host.appendChild(p);
        });

        return {
            selections: function () {
                return rows.filter(function (r) { return r.cb.checked; })
                    .map(function (r) { return { visit_id: r.it.visit_id, amount: r.amount() }; });
            }
        };
    }

    function prompt(data, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'mw-unbilled-overlay';
            overlay.innerHTML =
                '<div class="mw-unbilled-dialog" role="dialog" aria-modal="true">' +
                    '<div class="mw-unbilled-dialog-title">' + esc(opts.title || 'Before you invoice') + '</div>' +
                    '<div class="mw-unbilled-dialog-list"></div>' +
                    '<div class="mw-unbilled-dialog-total"></div>' +
                    '<div class="mw-unbilled-dialog-actions">' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="cancel">Cancel</button>' +
                        '<button type="button" class="btn btn-sm btn-primary" data-act="ok">Invoice</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(overlay);
            var totalEl = overlay.querySelector('.mw-unbilled-dialog-total');
            var ctl;
            function updateTotal() {
                if (!ctl) return;
                var sel = ctl.selections();
                var sum = sel.reduce(function (a, s) { return a + (s.amount || 0); }, 0);
                totalEl.textContent = sel.length ? ('Adding ' + sel.length + ' line' + (sel.length === 1 ? '' : 's') + ' · ' + money(sum) + ' + GST') : 'Invoice today’s visit only';
            }
            ctl = render(overlay.querySelector('.mw-unbilled-dialog-list'), data, { onToggle: function () { setTimeout(updateTotal, 0); } });
            updateTotal();
            function close(val) { overlay.remove(); resolve(val); }
            overlay.querySelector('[data-act="cancel"]').addEventListener('click', function () { close(null); });
            overlay.querySelector('[data-act="ok"]').addEventListener('click', function () { close(ctl.selections()); });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) close(null); });
        });
    }

    window.MwUnbilledWork = { fetch: fetchList, render: render, prompt: prompt };
})();
