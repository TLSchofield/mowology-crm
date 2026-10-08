/**
 * MwExpenseSplit — "Split by line" for a receipt (Penny's card + the expense edit modal).
 *
 * One receipt, several destinations: Lawnboy #412's mulch goes on Oakridge Gardens' job, the
 * seed goes to shop stock. Each line gets For (a job, shop stock, or no job) and a category
 * (+ the truck / equipment tag on fuel). Penny pre-fills it (ExpenseSplitService::propose):
 * the line on a job's quote → that job, a stocked product → stock, diesel → the truck, gas
 * under $50 → the equipment, food / drink → Meals, else what Tim did last time.
 * The server works the money out (GST by net, PST only on the taxable lines) — the amounts
 * shown here come from it; the job cost line is net + PST (+ half a meal's GST).
 *
 *   var state = MwExpenseSplit.render(el, data, { categories: [...], autoOn: true, state: previous, onChange: fn });
 *   MwExpenseSplit.value(state)  → { on: bool, lines: [{ line_item_id, job_id, is_stock, accounting_category, asset_tag }] }
 *
 * data = /crm/api/expenses.php?action=split&id=N (or the card's queue item .split):
 *   { lines: [{line_item_id, name, line_total, net, gst, pst, job_id, accounting_category, asset_tag, is_stock, reason}],
 *     jobs: [{id, label}], current: [allocation rows], suggest: bool }
 * Saved through the expense gate: the card's decide (overrides.split) and the modal's update (split).
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return '$' + (Number(v) || 0).toFixed(2); }

    function buildState(data, opts) {
        var current = (data && data.current) || [];
        var penny = {};
        ((data && data.lines) || []).forEach(function (l) { penny[l.line_item_id] = l; });
        var rows;
        if (current.length) {
            rows = current.map(function (a) {
                var p = penny[a.line_item_id] || {};
                return {
                    line_item_id: a.line_item_id, name: a.label, line_total: p.line_total != null ? p.line_total : a.net_amount,
                    net: Number(a.net_amount), gst: Number(a.gst_amount), pst: Number(a.pst_amount),
                    job_id: a.job_id || null, is_stock: !!a.is_stock, accounting_category: a.accounting_category || '',
                    asset_tag: a.asset_tag || '', reason: a.source === 'penny' ? (a.reason || p.reason || '') : 'Your split', penny: p
                };
            });
        } else {
            rows = ((data && data.lines) || []).map(function (l) {
                return {
                    line_item_id: l.line_item_id, name: l.name, line_total: l.line_total, net: Number(l.net), gst: Number(l.gst), pst: Number(l.pst),
                    job_id: l.job_id || null, is_stock: !!l.is_stock, accounting_category: l.accounting_category || '', asset_tag: l.asset_tag || '',
                    reason: l.reason || '', penny: l
                };
            });
        }
        return {
            data: data || {}, rows: rows, jobs: (data && data.jobs) || [],
            on: current.length > 0 || (!!opts.autoOn && !!(data && data.suggest)),
            wasSplit: current.length > 0, dirty: false
        };
    }

    function forValue(r) { return r.is_stock ? 'stock' : (r.job_id ? 'job:' + r.job_id : 'none'); }

    function jobLabel(state, id) {
        for (var i = 0; i < state.jobs.length; i++) if (String(state.jobs[i].id) === String(id)) return state.jobs[i].label;
        return 'Job #' + id;
    }

    /** "Oakridge Gardens $80.00 · Shop stock $128.40" — what each destination carries (job cost). */
    function summary(state) {
        var by = {}, order = [];
        state.rows.forEach(function (r) {
            var meals = /^meals$/i.test(r.accounting_category || '');
            var cost = r.net + r.pst + (meals ? r.gst / 2 : 0);
            var k = r.is_stock ? '🏪 Shop stock' : r.job_id ? jobLabel(state, r.job_id) : (r.accounting_category || 'No job');
            if (!(k in by)) { by[k] = 0; order.push(k); }
            by[k] += cost;
        });
        return order.map(function (k) { return '<b>' + esc(k) + '</b> ' + money(by[k]); }).join(' · ');
    }

    function rowHtml(state, r, i, cats, locked) {
        var fv = forValue(r);
        var forOpts = '<option value="stock"' + (fv === 'stock' ? ' selected' : '') + '>🏪 Shop stock</option>' +
            '<option value="none"' + (fv === 'none' ? ' selected' : '') + '>No job</option>';
        var seen = {};
        state.jobs.forEach(function (j) {
            seen[j.id] = true;
            forOpts += '<option value="job:' + esc(j.id) + '"' + (fv === 'job:' + j.id ? ' selected' : '') + '>' + esc(j.label) + '</option>';
        });
        if (r.job_id && !seen[r.job_id]) forOpts += '<option value="job:' + esc(r.job_id) + '" selected>Job #' + esc(r.job_id) + '</option>';
        var catOpts = cats.map(function (c) {
            return '<option' + (c === r.accounting_category ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
        if (r.accounting_category && cats.indexOf(r.accounting_category) === -1) catOpts = '<option selected>' + esc(r.accounting_category) + '</option>' + catOpts;
        var isFuel = /^fuel$/i.test(r.accounting_category || '');
        var tagSel = isFuel ? '<select class="mw-split-in" data-split-tag aria-label="Fuel for"' + (locked ? ' disabled' : '') + '>' +
            ['', 'truck', 'equipment'].map(function (t) {
                return '<option value="' + t + '"' + (t === (r.asset_tag || '') ? ' selected' : '') + '>' + (t === 'truck' ? '🚚 Truck' : t === 'equipment' ? '🔧 Equipment' : '—') + '</option>';
            }).join('') + '</select>' : '';
        var taxes = 'GST ' + money(r.gst) + (r.pst ? ' · PST ' + money(r.pst) : '');
        return '<div class="mw-split-row" data-split-row="' + i + '">' +
            '<div class="mw-split-name"><span>' + esc(r.name) + '</span><small>' + money(r.net) + ' · ' + taxes + '</small></div>' +
            '<select class="mw-split-in" data-split-for aria-label="For"' + (locked ? ' disabled' : '') + '>' + forOpts + '</select>' +
            '<select class="mw-split-in" data-split-cat aria-label="Category"' + (locked ? ' disabled' : '') + '>' + catOpts + '</select>' +
            tagSel +
            (r.reason ? '<div class="mw-split-why">' + esc(r.reason) + '</div>' : '') +
          '</div>';
    }

    function draw(el, state, opts) {
        var cats = opts.categories || [];
        var locked = !!opts.locked;
        var penny = state.data && state.data.suggest && !state.wasSplit;
        el.innerHTML = '<div class="mw-split' + (state.on ? ' is-on' : '') + '">' +
            '<div class="mw-split-head">' +
              '<label class="mw-split-toggle"><input type="checkbox" data-split-on' + (state.on ? ' checked' : '') + (locked ? ' disabled' : '') + '> Split by line</label>' +
              (penny ? '<span class="mw-split-hint">⭐ Penny suggests splitting this receipt</span>' : '') +
              (state.wasSplit && !state.on ? '<span class="mw-split-hint">Ends the split — the receipt\'s own job and category again</span>' : '') +
            '</div>' +
            (state.on ? '<div class="mw-split-rows">' + state.rows.map(function (r, i) { return rowHtml(state, r, i, cats, locked); }).join('') + '</div>' +
                        '<div class="mw-split-sum">' + summary(state) + ' <small>— job cost: GST back as a tax credit</small></div>' : '') +
          '</div>';
    }

    function bind(el, state, opts) {
        if (el._mwSplitBound) { el._mwSplitState = state; el._mwSplitOpts = opts; return; }
        el._mwSplitBound = true;
        el._mwSplitState = state;
        el._mwSplitOpts = opts;
        el.addEventListener('change', function (e) {
            var st = el._mwSplitState, o = el._mwSplitOpts;
            var t = e.target;
            if (t.hasAttribute('data-split-on')) {
                st.on = t.checked;
            } else {
                var rowEl = t.closest('[data-split-row]');
                if (!rowEl) return;
                var r = st.rows[parseInt(rowEl.getAttribute('data-split-row'), 10)];
                if (t.hasAttribute('data-split-for')) {
                    var v = t.value;
                    r.is_stock = v === 'stock';
                    r.job_id = v.indexOf('job:') === 0 ? parseInt(v.slice(4), 10) : null;
                    if (r.is_stock && !r.accounting_category) r.accounting_category = 'Materials';
                } else if (t.hasAttribute('data-split-cat')) {
                    r.accounting_category = t.value;
                    if (!/^fuel$/i.test(r.accounting_category)) r.asset_tag = '';
                } else if (t.hasAttribute('data-split-tag')) {
                    r.asset_tag = t.value;
                }
                r.reason = 'Your choice';
            }
            st.dirty = true;
            e.stopPropagation();      // the card's own change handlers (line items) ignore it
            draw(el, st, o);
            if (o.onChange) o.onChange(st);
        });
    }

    window.MwExpenseSplit = {
        /** Draw the split into el. Pass opts.state (from a previous render) to keep the owner's choices. */
        render: function (el, data, opts) {
            opts = opts || {};
            if (!el) return null;
            if (!data || !data.lines || data.lines.length < 2) { el.innerHTML = ''; return null; }
            var state = opts.state && opts.state.data === data ? opts.state : buildState(data, opts);
            draw(el, state, opts);
            bind(el, state, opts);
            return state;
        },
        /** What to send: {on, lines}. On a receipt that was never split and is still off: null (send nothing). */
        value: function (state) {
            if (!state) return null;
            if (!state.on && !state.wasSplit) return null;
            return {
                on: !!state.on,
                lines: state.rows.map(function (r) {
                    return { line_item_id: r.line_item_id, job_id: r.is_stock ? null : (r.job_id || null), is_stock: !!r.is_stock,
                             accounting_category: r.accounting_category || null, asset_tag: r.asset_tag || null };
                })
            };
        }
    };
})();
