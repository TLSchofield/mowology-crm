/**
 * Penny's product proposals (dashboard → her card, #mw-pp): label photos and receipt lines
 * that should become, or be linked to, a product. One card at a time:
 *   "New product: Richardson Sun & Shade Lawn Seed 5 kg · $60/bag from Lawnboy · 2 in stock · photo"
 *   "Add this photo to <product> · cost changed $41→$40 — update?"
 *   "Link 2 receipt lines to Black Composted Bark Mulch (+3 to stock)"
 * Add writes it (explicit columns, server side); Not now is remembered. Nothing else here writes.
 * API: /crm/api/label-products.php ?mode=penny · POST {mode: accept|dismiss, id, update_cost}.
 */
(function () {
    'use strict';
    var box = document.getElementById('mw-pp');
    if (!box) return;
    var API = '/crm/api/label-products.php';
    var items = [], waiting = 0, idx = 0, busy = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) { v = Number(v) || 0; return '$' + (Math.abs(v - Math.round(v)) < 0.005 ? v.toFixed(0) : v.toFixed(2)); }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load(msg) {
        return fetch(API + '?mode=penny', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.ready) { box.hidden = true; return; }
                items = d.items || [];
                waiting = d.waiting || 0;
                if (idx >= items.length) idx = 0;
                render(msg);
            })
            .catch(function () { box.hidden = true; });
    }

    function lineList(it) {
        if (!it.lines || !it.lines.length) return '';
        return '<ul class="mw-pp-lines">' + it.lines.slice(0, 4).map(function (l) {
            return '<li><a href="/crm/expenses_appstack.php?edit=' + esc(l.expense_id) + '">#' + esc(l.expense_id) + '</a> ' +
                esc(l.date || '') + ' · ' + esc(l.name) + (l.quantity ? ' · ' + esc(Number(l.quantity)) + ' × ' + money(l.unit_price) : '') + '</li>';
        }).join('') + (it.lines.length > 4 ? '<li>+' + (it.lines.length - 4) + ' more</li>' : '') + '</ul>';
    }

    function render(msg) {
        if (!items.length) {
            box.hidden = !msg;
            box.innerHTML = '<div class="mw-bl-head"><b>📦 Products</b></div><div class="mw-rc-empty">' + esc(msg || '') + '</div>';
            return;
        }
        box.hidden = false;
        var it = items[idx], p = it.payload || {};
        var read = p.read || {};
        var facts = [];
        if (read.sku) facts.push('Item# ' + read.sku);
        if (read.batch) facts.push('Batch ' + read.batch);
        if (read.composition && read.composition.length) facts.push(read.composition.map(function (c) { return c.pct + '% ' + c.what; }).join(', '));
        var care = p.care_notes ? '<div class="mw-pp-care">🧺 From the label: ' + esc(p.care_notes) + '</div>' : '';
        var cost = p.cost_change ? '<label class="mw-pp-cost"><input type="checkbox" data-pp="cost" checked> Update the cost ' +
            money(p.cost_change.old) + ' → ' + money(p.cost_change.new) + '</label>' : '';
        box.innerHTML =
            '<div class="mw-bl-head"><span><b>📦 Products</b> · ' + waiting + ' to check</span>' +
              (items.length > 1 ? '<span><button type="button" class="mw-rc-arrow" data-pp="prev" aria-label="Previous">‹</button> ' +
              '<button type="button" class="mw-rc-arrow" data-pp="next" aria-label="Next">›</button></span>' : '') + '</div>' +
            '<div class="mw-pp-item">' +
              (it.photo_url ? '<a class="mw-pp-photo" href="' + esc(it.photo_url) + '" target="_blank" rel="noopener"><img src="' + esc(it.photo_url) + '" alt="Label photo" loading="lazy"></a>' : '') +
              '<div class="mw-pp-body">' +
                '<div class="mw-pp-say">' + esc(it.say) + '</div>' +
                (facts.length ? '<div class="mw-pp-facts">' + esc(facts.join(' · ')) + '</div>' : '') +
                care + lineList(it) + cost +
                '<div class="mw-pp-btns"><button type="button" class="mw-rc-ok" data-pp="add"' + (busy ? ' disabled' : '') + '>' +
                  (it.kind === 'product_new' ? 'Add product' : 'Add') + '</button>' +
                '<button type="button" class="mw-rc-sk" data-pp="no"' + (busy ? ' disabled' : '') + '>Not now</button></div>' +
              '</div>' +
            '</div>' +
            '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>';
    }

    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pp]');
        if (!b || busy) return;
        var act = b.getAttribute('data-pp');
        if (act === 'prev' || act === 'next') {
            idx = (idx + (act === 'next' ? 1 : items.length - 1)) % items.length;
            render('');
            return;
        }
        if (act !== 'add' && act !== 'no') return;
        var it = items[idx];
        var costBox = box.querySelector('[data-pp="cost"]');
        busy = true;
        render('');
        post(act === 'add' ? { mode: 'accept', id: it.id, update_cost: costBox ? costBox.checked : true } : { mode: 'dismiss', id: it.id })
            .then(function (r) {
                busy = false;
                return load(r && r.message ? r.message : (r && r.error) || 'Done.');
            })
            .catch(function () { busy = false; render('Could not reach the server — try again.'); });
    });

    load('');
})();
