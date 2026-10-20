/**
 * Quote builder: "Add from supplier lists" (owner, 2026-10-19: "when Sam writes a quote, type
 * 'Japanese maple #5' and get today's best supplier price plus your markup").
 *
 * Mounted by quotes/create.php (#mwSupplierAdd). Searches /crm/api/supplier-search.php (every supplier's
 * latest list, cheapest in stock first) and adds a quote line with the page's own addLine():
 *   - a plant: unit price = best cost + the plant markup (35%);
 *   - soil / mulch / compost: Sam's INSTALLED price per yard — /crm/api/mulch-pricing.php?mode=hint with
 *     supplier_item_id: that list price is the material, that supplier's yard the haul end, plus spreading
 *     at the target margin; the local market price is shown beside it;
 *   - other bulk (gravel, sand, rock): added at cost, flagged "set your price" — no installed model yet.
 * The line's DESCRIPTION is what the client reads: the plant and size only. Supplier, list, cost and markup
 * go into the line's pricing_snapshot (internal), never the description.
 * Tim's click only — nothing is added by itself. Never throws into the page.
 */
(function () {
    'use strict';
    var root = document.getElementById('mwSupplierAdd');
    if (!root || typeof addLine !== 'function' || typeof updateLineItem !== 'function') return;
    var input = root.querySelector('[data-sa-q]');
    var out = root.querySelector('[data-sa-results]');
    var propSel = document.getElementById('propertySelect');
    var timer = null, seq = 0;
    root.hidden = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return v == null ? '—' : '$' + Number(v).toFixed(2); }
    function yd(y) { y = Number(y); return y === 1 ? '1 yd' : (y === 0.5 ? '½ yd' : (y === 0.25 ? '¼ yd' : y + ' yd')); }
    function lastIndex() { try { return lineItems.length - 1; } catch (e) { return -1; } }
    function marketLine(m) {
        if (!m || !m.length) return '';
        return '<div class="small text-muted">Local market: ' + m.slice(0, 2).map(function (x) {
            var sizes = Object.keys(x.sizes).sort(function (a, b) { return b - a; }).map(function (k) { return yd(k) + ' $' + Math.round(x.sizes[k]); });
            return esc(x.source + ', ' + x.product) + ' ' + esc(sizes.join(' · ')) + ' delivered';
        }).join('; ') + '</div>';
    }

    function add(name, size, price, unit, snapshot) {
        addLine({ name: name, description: size ? String(size) : '', base_price: price, unit_type: unit });
        var i = lastIndex();
        if (i >= 0 && snapshot) updateLineItem(i, 'pricing_snapshot', typeof snapshot === 'string' ? snapshot : JSON.stringify(snapshot));
        if (i >= 0 && typeof recalculateLineTotal === 'function') recalculateLineTotal(i);
    }

    function render(d) {
        if (!d || !d.ok) { out.innerHTML = '<div class="small text-danger">' + esc((d && d.message) || 'The search failed.') + '</div>'; return; }
        if (!d.results || !d.results.length) { out.innerHTML = '<div class="small text-muted">No supplier lists it right now. Try fewer words or another size.</div>'; return; }
        out.innerHTML = '<ul class="list-group">' + d.results.map(function (g, i) {
            var b = g.best, label = g.common || g.name;
            var stock = b.available == null ? 'stock not listed' : (b.available > 0 ? b.available + ' in stock' : 'sold out');
            var meta = esc(b.supplier) + ' · ' + money(b.price) + ' cost · ' + esc(stock) + (b.stale ? ' · list ' + b.age_days + ' days old' : '')
                + (b.distance ? ' · about ' + b.distance.km + ' km from the job' : '') + (b.rules && b.rules.length ? ' · ' + esc(b.rules[0]) : '');
            var act;
            if (!g.bulk) act = '<button type="button" class="btn btn-sm btn-success" data-sa-add="' + i + '">Add at ' + money(g.sell) + '</button>';
            else if (g.install_family) act = '<button type="button" class="btn btn-sm btn-outline-success" data-sa-install="' + i + '">Price it installed</button>';
            else act = '<button type="button" class="btn btn-sm btn-outline-secondary" data-sa-cost="' + i + '">Add at cost — set your price</button>';
            return '<li class="list-group-item" data-sa-row="' + i + '"><div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:8px">'
                + '<div><strong>' + esc(label) + '</strong> · ' + esc(g.size || 'per yard') + (g.common ? ' <span class="text-muted small">' + esc(b.item) + '</span>' : '')
                + '<div class="small text-muted">' + meta + '</div>' + (g.bulk ? marketLine(g.market) : '') + '<div data-sa-note></div></div>'
                + '<div>' + act + '</div></div></li>';
        }).join('') + '</ul>';
        out._results = d.results;
        out._markup = d.markup_pct;
    }

    function search() {
        var q = input.value.trim();
        if (q.length < 3) { out.innerHTML = ''; return; }
        var my = ++seq;
        out.innerHTML = '<div class="small text-muted">Looking through the supplier lists…</div>';
        var pid = propSel ? propSel.value : '';
        fetch('/crm/api/supplier-search.php?limit=6&q=' + encodeURIComponent(q) + (pid ? '&property_id=' + encodeURIComponent(pid) : ''),
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (my === seq) render(d); })
            .catch(function () { if (my === seq) out.innerHTML = '<div class="small text-danger">The search failed — try again.</div>'; });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 350); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(); } });

    out.addEventListener('click', function (e) {
        var list = out._results || [];
        var plant = e.target.closest('[data-sa-add]'), inst = e.target.closest('[data-sa-install]'), cost = e.target.closest('[data-sa-cost]');
        var btn = plant || inst || cost;
        if (!btn) return;
        var g = list[parseInt(btn.getAttribute('data-sa-add') || btn.getAttribute('data-sa-install') || btn.getAttribute('data-sa-cost'), 10)];
        if (!g) return;
        var b = g.best, name = g.common || g.name;
        var snap = { source: 'supplier_list', supplier: b.supplier, supplier_id: b.supplier_id, item_id: b.item_id, list_id: b.list_id,
                     list_date: b.list_date, item: b.item, size: b.size, cost: b.price, priced_at: new Date().toISOString().slice(0, 10) };
        if (plant) {
            snap.kind = 'plant'; snap.markup_pct = out._markup; snap.sell = g.sell;
            add(name, g.size, g.sell, 'each', snap);
            btn.textContent = 'Added'; btn.disabled = true;
            return;
        }
        if (cost) {
            // Added at $0, never at trade cost: a forgotten line must not go to the client at cost with no margin.
            // The "set the price" warning lives here in the editor, not in the description the client reads.
            snap.kind = 'cost_only'; snap.note = 'No installed model for this material yet — set the price';
            add(name, 'per yard', 0, 'yd', snap);
            btn.textContent = 'Added at $0 — set the price'; btn.disabled = true;
            btn.closest('[data-sa-row]').querySelector('[data-sa-note]').innerHTML =
                '<div class="small text-warning">Cost ' + money(b.price) + '/yd from ' + esc(b.supplier) + '. Set the price on the line before sending.</div>';
            return;
        }
        // Soil / mulch / compost: Sam's installed price from this supplier's list.
        var note = btn.closest('[data-sa-row]').querySelector('[data-sa-note]');
        var pid = propSel ? propSel.value : '';
        if (!pid) { note.innerHTML = '<div class="small text-warning">Pick the property first — Sam prices the haul from it.</div>'; return; }
        btn.disabled = true;
        note.innerHTML = '<div class="small text-muted">Sam is pricing the yard…</div>';
        fetch('/crm/api/mulch-pricing.php?mode=hint&property_id=' + encodeURIComponent(pid) + '&family=' + encodeURIComponent(g.install_family)
              + '&supplier_item_id=' + encodeURIComponent(b.item_id) + '&text=' + encodeURIComponent(name),
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (h) {
                btn.disabled = false;
                if (!h || !h.ok) { note.innerHTML = '<div class="small text-danger">' + esc((h && (h.headline || h.error)) || "Sam couldn't price this yet") + '</div>'; return; }
                note.innerHTML = '<div class="small"><strong>Installed ' + money(h.sell_per_yard) + '/yd</strong> + GST (material ' + money(h.material)
                    + ' + haul ' + money(h.haul || 0) + ' + spreading ' + money(h.labour) + ' = ' + money(h.cost_per_yard) + ' cost) · minimum '
                    + money(h.minimum_charge) + (h.estimated && h.estimated.length ? ' · assumed: ' + esc(h.estimated.join(', ')) : '') + '</div>'
                    + '<button type="button" class="btn btn-sm btn-success mt-1" data-sa-use>Add at ' + money(h.sell_per_yard) + '/yd</button>';
                note.querySelector('[data-sa-use]').addEventListener('click', function (ev) {
                    add(name + ' — installed', 'per yard, spread', h.sell_per_yard, 'yd', h.snapshot || null);
                    ev.target.textContent = 'Added'; ev.target.disabled = true;
                    // One yard at the per-yard price is below Sam's minimum for a small job: say so (quantity is Tim's call).
                    if (h.minimum_charge && Number(h.sell_per_yard) < Number(h.minimum_charge)) {
                        ev.target.insertAdjacentHTML('afterend', '<div class="small text-warning">Small job: Sam\'s minimum is ' + money(h.minimum_charge)
                            + ' — set the quantity, or price the line up to the minimum.</div>');
                    }
                });
            })
            .catch(function () { btn.disabled = false; note.innerHTML = '<div class="small text-danger">Sam couldn\'t price this — try again.</div>'; });
    });
})();
