/**
 * MwServicePicker — "Add from services" searchable dropdown for line-item tables.
 *
 * Reuses the quote builder's .mw-template-* styles (mowology-brand.css). Usage:
 *
 *   <div class="mw-template-dropdown" id="invServicePicker"></div>
 *   MwServicePicker.attach(document.getElementById('invServicePicker'), catalog, function (svc) {
 *       addRow({ title: svc.name, description: svc.description, unit_price: svc.base_price });
 *   });
 *
 * `catalog` is InvoiceLineItems::catalog(): [{id, name, description, base_price,
 * category_name, unit_abbreviation}]. Picking a service calls onPick(service) and closes.
 *
 * attach() returns { setAgreed(items) }. Agreed items (ClientAgreedPrices rows: name, unit_price,
 * quantity, unit_type, label) are listed FIRST under "Agreed price" and passed to onPick with
 * `agreed: true` and `base_price` = the agreed unit price; the catalog stays below as the fallback.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function attach(host, catalog, onPick) {
        if (!host) return { setAgreed: function () {} };
        catalog = catalog || [];
        var agreed = [];
        host.classList.add('mw-template-dropdown');
        host.innerHTML =
            '<button type="button" class="btn btn-secondary btn-sm mw-sp-btn">+ Add from services</button>' +
            '<div class="mw-template-menu">' +
                '<div class="mw-template-search-wrap">' +
                    '<input type="text" class="mw-template-search" placeholder="Search services…" autocomplete="off">' +
                '</div>' +
                '<div class="mw-template-results"></div>' +
            '</div>';

        var btn = host.querySelector('.mw-sp-btn');
        var menu = host.querySelector('.mw-template-menu');
        var search = host.querySelector('.mw-template-search');
        var results = host.querySelector('.mw-template-results');

        function render(q) {
            q = (q || '').toLowerCase().trim();
            results.innerHTML = '';
            var agreedShown = 0;
            agreed.forEach(function (a) {
                var hay = (a.name + ' ' + (a.label || '')).toLowerCase();
                if (q && hay.indexOf(q) === -1) return;
                if (!agreedShown) {
                    var ah = document.createElement('div');
                    ah.className = 'mw-template-header mw-template-header-agreed';
                    ah.textContent = 'Agreed price for this client';
                    results.appendChild(ah);
                }
                agreedShown++;
                var ai = document.createElement('div');
                ai.className = 'mw-template-item mw-template-item-agreed';
                ai.innerHTML = '<div><div class="mw-template-name">' + esc(a.name) +
                    ' <span class="mw-template-agreed-tag">Agreed price</span></div>' +
                    '<div class="mw-template-price">$' + (parseFloat(a.unit_price) || 0).toFixed(2) +
                    (a.unit_type ? '&nbsp;/&nbsp;' + esc(a.unit_type) : '') + '</div>' +
                    (a.label ? '<div class="mw-template-agreed-label">' + esc(a.label) + '</div>' : '') + '</div>';
                ai.addEventListener('click', function () {
                    close();
                    onPick({ agreed: true, name: a.name, description: a.description || a.name,
                             base_price: a.unit_price, quantity: a.quantity, unit_type: a.unit_type,
                             plan_id: a.plan_id, label: a.label });
                });
                results.appendChild(ai);
            });
            if (!catalog.length && !agreedShown) {
                results.innerHTML = '<div class="mw-template-empty">No services in the catalog.</div>';
                return;
            }
            var cat = null, shown = 0;
            catalog.forEach(function (s) {
                var hay = (s.name + ' ' + (s.category_name || '') + ' ' + (s.description || '')).toLowerCase();
                if (q && hay.indexOf(q) === -1) return;
                if (!q && (s.category_name || '') !== cat) {
                    cat = s.category_name || '';
                    var h = document.createElement('div');
                    h.className = 'mw-template-header';
                    h.textContent = cat || 'Uncategorized';
                    results.appendChild(h);
                }
                var price = (parseFloat(s.base_price) || 0).toFixed(2);
                var item = document.createElement('div');
                item.className = 'mw-template-item';
                item.innerHTML = '<div><div class="mw-template-name">' + esc(s.name) + '</div>' +
                    '<div class="mw-template-price">$' + price +
                    (s.unit_abbreviation ? '&nbsp;/&nbsp;' + esc(s.unit_abbreviation) : '') + '</div></div>';
                item.addEventListener('click', function () { close(); onPick(s); });
                results.appendChild(item);
                shown++;
            });
            if (!shown && !agreedShown) results.innerHTML = '<div class="mw-template-empty">No matching services.</div>';
        }

        function open() {
            search.value = '';
            render('');
            menu.classList.add('show');
            setTimeout(function () { search.focus(); }, 60);
        }
        function close() { menu.classList.remove('show'); }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.contains('show') ? close() : open();
        });
        search.addEventListener('input', function () { render(search.value); });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') e.preventDefault();          // never submit the invoice form
            if (e.key === 'Escape') { close(); btn.focus(); }
        });
        document.addEventListener('click', function (e) {
            if (!host.contains(e.target)) close();
        });
        return { setAgreed: function (items) { agreed = items || []; if (menu.classList.contains('show')) render(search.value); } };
    }

    window.MwServicePicker = { attach: attach };
})();
