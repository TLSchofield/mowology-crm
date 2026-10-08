/**
 * Sam's mulch price — two places, one endpoint (/crm/api/mulch-pricing.php?mode=hint, GET, read-only).
 *
 * 1. Quote builder (quotes/create.php): under any line that names mulch / bark / soil / compost,
 *    "Sam's bark mulch price for V6K: $124/yd + GST (material $40 + haul $11 + labour $30)" with a
 *    "Use this price" button that sets that line's unit price (Tim's click only; nothing automatic).
 *    Reads the page's own lineItems / updateLineItem / recalculateLineTotal and #propertySelect.
 * 2. Sam's dashboard card (#mw-sam-mulch): "Installed price for <address or postcode>".
 *
 * Never throws into the page: every failure just leaves the hint out.
 */
(function () {
    'use strict';
    var API = '/crm/api/mulch-pricing.php';
    var WORDS = /\b(mulch|bark|cbm|wood ?chip|hog fuel|soil|garden mix|triple mix|planting mix|lawn mix|top dress|compost)/i;
    var cache = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { return '$' + Math.round(Number(v) || 0).toLocaleString('en-CA'); }

    function fetchHint(params) {
        var key = JSON.stringify(params);
        if (!cache[key]) {
            var qs = Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] != null; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
            cache[key] = fetch(API + '?mode=hint&' + qs, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .catch(function () { return { ok: false, applies: false }; });
        }
        return cache[key];
    }

    /** The body of a hint (shared by the quote builder and the card). */
    function hintHtml(h) {
        var parts = 'material ' + money(h.material) + ' + haul ' + money(h.haul || 0) + ' + labour ' + money(h.labour)
            + (h.disposal != null ? ' + disposal ' + money(h.disposal) : '');
        var html = '<div class="mw-mulch-hint-main"><b>Sam\'s ' + esc(String(h.label || 'mulch').toLowerCase()) + ' price'
            + (h.where ? ' for ' + esc(h.where) : '') + ': ' + money(h.sell_per_yard) + '/yd</b> + GST'
            + ' <span class="mw-mulch-hint-parts">(' + parts + ' = ' + money(h.cost_per_yard) + ' cost)</span></div>'
            + '<div class="mw-mulch-hint-sub">Minimum ' + money(h.minimum_charge) + ' for a small job · material: ' + esc(h.material_basis)
            + ' · haul: ' + esc(h.haul_basis || 'none');
        if (h.includes_pickup) html += ' · includes the pickup trip — using this price drops the separate Material pickup suggestion';
        html += '</div>';
        if (h.estimated && h.estimated.length) {
            html += '<div class="mw-mulch-hint-est">Assumed, not measured: ' + esc(h.estimated.join(', ')) + '</div>';
        }
        return html;
    }

    // ── 1. Quote builder ─────────────────────────────────────────────────────
    function initQuoteBuilder() {
        var container = document.getElementById('lineItemsContainer');
        var propSel = document.getElementById('propertySelect');
        if (!container || typeof updateLineItem !== 'function' || typeof recalculateLineTotal !== 'function') return;
        var timer = null;

        function items() {
            try { return (typeof lineItems !== 'undefined' && Array.isArray(lineItems)) ? lineItems : []; } catch (e) { return []; }
        }

        function decorate() {
            var list = items();
            var propertyId = propSel ? propSel.value : '';
            container.querySelectorAll('.mw-line-item').forEach(function (row) {
                var idx = parseInt(row.dataset.index, 10);
                var item = list[idx];
                var next = row.nextElementSibling;
                var existing = next && next.classList.contains('mw-mulch-hint') ? next : null;
                var text = item ? ((item.service_type || '') + ' ' + (item.description || '')).trim() : '';
                if (!item || !WORDS.test(text)) { if (existing) existing.remove(); return; }
                var sig = propertyId + '|' + text + '|' + (item.product_id || '');
                if (existing && existing.dataset.sig === sig) return;
                var box = existing || document.createElement('div');
                box.className = 'mw-mulch-hint';
                box.dataset.sig = sig;
                if (!existing) row.parentNode.insertBefore(box, row.nextSibling);
                if (!propertyId) {
                    box.innerHTML = '<div class="mw-mulch-hint-sub">Pick the property and Sam will price this per yard, haul included.</div>';
                    return;
                }
                box.innerHTML = '<div class="mw-mulch-hint-sub">Sam is pricing the yard…</div>';
                fetchHint({ property_id: propertyId, text: text, product_id: (item.product_id && item.product_id !== true) ? item.product_id : '' })
                    .then(function (h) {
                        if (box.dataset.sig !== sig) return;
                        if (!h || h.applies === false) { box.remove(); return; }
                        if (!h.ok) { box.innerHTML = '<div class="mw-mulch-hint-sub">' + esc(h.headline || h.error || "Sam couldn't price this yet") + '</div>'; return; }
                        box.innerHTML = hintHtml(h)
                            + '<button type="button" class="btn btn-sm btn-success mw-mulch-hint-use">Use ' + money(h.sell_per_yard) + '/yd</button>';
                        box.querySelector('.mw-mulch-hint-use').addEventListener('click', function () {
                            var i = parseInt(row.dataset.index, 10);
                            updateLineItem(i, 'unit_price', h.sell_per_yard);
                            // Marks the line as Sam-priced: the price holds the pickup trip, so no "Material pickup" suggestion
                            if (h.snapshot) updateLineItem(i, 'pricing_snapshot', h.snapshot);
                            var li = items()[i];
                            if (li && (!li.unit_type || li.unit_type === 'each')) updateLineItem(i, 'unit_type', 'yd');
                            recalculateLineTotal(i);
                        });
                    });
            });
        }

        function soon() { clearTimeout(timer); timer = setTimeout(decorate, 200); }
        new MutationObserver(soon).observe(container, { childList: true });
        container.addEventListener('change', soon);
        if (propSel) propSel.addEventListener('change', soon);
        soon();
    }

    // ── 2. Sam's card ────────────────────────────────────────────────────────
    function initCard() {
        var form = document.getElementById('mw-sam-mulch-form');
        var out = document.getElementById('mw-sam-mulch-out');
        var input = document.getElementById('mw-sam-mulch-q');
        if (!form || !out || !input) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var q = input.value.trim();
            if (!q) return;
            out.hidden = false;
            out.innerHTML = '<div class="mw-mulch-hint-sub">Sam is pricing the yard…</div>';
            fetchHint({ q: q, family: 'mulch' }).then(function (h) {
                if (!h || !h.ok) {
                    out.innerHTML = '<div class="mw-mulch-hint-sub">' + esc((h && (h.location_error || h.headline || h.error)) || "Sam couldn't price that") + '</div>';
                    return;
                }
                out.innerHTML = '<div class="mw-mulch-hint">' + hintHtml(h)
                    + (h.location_error ? '<div class="mw-mulch-hint-est">' + esc(h.location_error) + '</div>' : '') + '</div>';
            });
        });
    }

    function init() { initQuoteBuilder(); initCard(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
