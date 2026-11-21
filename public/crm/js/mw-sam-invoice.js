/**
 * MwSamInvoice — Sam's "we did this here before" card above the invoice line items (invoices/create.php).
 *
 *   MwSamInvoice.attach({ host: <div id="invSamCard">, getProperty: function () { return propertyId; },
 *                         getService: function () { return 'Cleanup'; }, addLines: function (lines) { … } });
 *
 * Reads /crm/api/sam-invoice-history.php (SamInvoiceHistory). Sam only suggests: tapping Add puts lines on the form,
 * nothing is saved until the invoice is. The card stays hidden when there is nothing earlier to show.
 * Styles: .mw-invsam* in mowology-brand.css.
 */
(function () {
    'use strict';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function money(n) {
        return '$' + (Number(n) || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function when(d) {
        var t = new Date(String(d) + 'T00:00:00');
        return isNaN(t) ? String(d) : t.toLocaleDateString('en-CA', { month: 'short', year: 'numeric' });
    }
    function qtyLabel(q) {
        q = Number(q) || 1;
        return q === 1 ? '' : (Math.round(q * 100) / 100) + ' × ';
    }

    function attach(o) {
        if (!o || !o.host || typeof o.addLines !== 'function') return;
        var host = o.host, lastKey = null, seq = 0;

        function render(d) {
            host.innerHTML = '';
            if (!d || !d.success || !d.has || !d.primary) { host.hidden = true; return; }
            var p = d.primary, lines = p.lines || [], added = {};

            var top = el('div', 'mw-invsam-top');
            var face = el('div', 'mw-invsam-face');
            var img = el('img'); img.src = '/crm/img/heads/sam.jpg'; img.alt = 'Sam, sales'; img.width = 44; img.height = 44;
            face.appendChild(img);
            var head = el('div', 'mw-invsam-head');
            head.appendChild(el('div', 'mw-invsam-kicker', 'Sam suggests'));
            head.appendChild(el('div', 'mw-invsam-title', d.headline || 'Done here before'));
            head.appendChild(el('div', 'mw-invsam-src', (p.ref || '') + ' · ' + money(p.subtotal) + ' before GST'));
            top.appendChild(face); top.appendChild(head);
            host.appendChild(top);

            var list = el('ul', 'mw-invsam-lines');
            var addAll = el('button', 'btn btn-sm btn-primary mw-invsam-add-all', 'Use these ' + lines.length + (lines.length === 1 ? ' line' : ' lines'));
            addAll.type = 'button';
            var rows = [];
            lines.forEach(function (l, i) {
                var li = el('li', 'mw-invsam-line');
                li.appendChild(el('span', 'mw-invsam-line-d', qtyLabel(l.quantity) + l.description));
                li.appendChild(el('span', 'mw-invsam-line-p', money(l.line_total)));
                var b = el('button', 'btn btn-sm btn-outline-secondary mw-invsam-add-one', 'Add'); b.type = 'button';
                b.addEventListener('click', function () { use([i]); });
                li.appendChild(b);
                list.appendChild(li);
                rows.push({ li: li, btn: b });
            });
            host.appendChild(list);

            function use(idx) {
                var pick = [];
                idx.forEach(function (i) {
                    if (added[i]) return;
                    added[i] = true;
                    pick.push({ description: lines[i].description, quantity: lines[i].quantity, unit_price: lines[i].unit_price });
                    rows[i].btn.textContent = 'Added'; rows[i].btn.disabled = true; rows[i].li.classList.add('is-added');
                });
                if (!pick.length) return;
                o.addLines(pick);
                if (Object.keys(added).length >= lines.length) { addAll.textContent = 'Added'; addAll.disabled = true; }
            }
            addAll.addEventListener('click', function () { use(lines.map(function (_, i) { return i; })); });

            var foot = el('div', 'mw-invsam-foot');
            foot.appendChild(addAll);
            if (d.caveat) foot.appendChild(el('span', 'mw-invsam-caveat', d.caveat));
            host.appendChild(foot);

            if ((d.earlier || []).length) {
                var det = el('details', 'mw-invsam-earlier');
                det.appendChild(el('summary', null, 'Earlier jobs here (' + d.earlier.length + ')'));
                var ul = el('ul', 'mw-invsam-earlier-list');
                d.earlier.forEach(function (e) {
                    ul.appendChild(el('li', null, when(e.date) + ' · ' + money(e.subtotal) + ' · ' + (e.summary || e.ref)));
                });
                det.appendChild(ul);
                host.appendChild(det);
            }
            host.hidden = false;
        }

        function load() {
            var pid = (o.getProperty && o.getProperty()) || 0;
            var svc = (o.getService && o.getService()) || '';
            var key = pid + ':' + svc;
            if (key === lastKey) return;
            lastKey = key;
            var mine = ++seq;   // only the newest request may draw
            if (!pid) { render(null); return; }
            fetch('/crm/api/sam-invoice-history.php?property_id=' + pid + '&service=' + encodeURIComponent(svc), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (mine === seq) render(d); })
                .catch(function () { if (mine === seq) render(null); });
        }
        setInterval(load, 800);   // the customer/property pickers set the hidden property input from several places
        load();
    }

    window.MwSamInvoice = { attach: attach };
})();
