/**
 * MwMiaInvoiceNote — "Mia suggests" under the invoice Notes field (create + edit).
 *
 *   MwMiaInvoiceNote.attach({ note: <textarea>, host: <div>, invoiceId, getContext: function () {
 *       return { property_id, contact_id, company_id, services: [names] }; } });
 *
 * Tap Insert to put the line in the note (nothing is saved until the invoice is saved); Dismiss hides it.
 * Both taps are recorded so Mia learns. When Mia has proposed automating a suggestion the owner is asked here.
 * Styles: .mw-mia-* in mowology-brand.css.
 */
(function () {
    'use strict';
    var DEFAULT_NOTE = 'Thank you for your business!';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch('/crm/api/mia-invoice-suggestion.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function attach(o) {
        if (!o || !o.note || !o.host) return;
        var lastKey = null, seq = 0;

        function render(d) {
            o.host.innerHTML = '';
            var s = d && d.suggestion;
            if (s && s.auto) {
                var cur = o.note.value.trim();
                if (!cur || cur === DEFAULT_NOTE) o.note.value = s.text;
                o.host.appendChild(el('div', 'mw-mia-auto', 'Mia added her usual note here. Edit it as you like before saving.'));
            } else if (s) {
                var box = el('div', 'mw-mia-suggest');
                box.appendChild(el('div', 'mw-mia-title', 'Mia suggests'));
                box.appendChild(el('div', 'mw-mia-text', s.text));
                var row = el('div', 'mw-mia-actions');
                var ins = el('button', 'btn btn-sm btn-primary', 'Insert'); ins.type = 'button';
                var dis = el('button', 'btn btn-sm btn-outline-secondary', 'Dismiss'); dis.type = 'button';
                ins.addEventListener('click', function () {
                    // The customer may have changed since this was drawn: never insert text written for someone else.
                    if (JSON.stringify(o.getContext()) !== lastKey) { o.host.innerHTML = ''; lastKey = null; load(); return; }
                    var cur = o.note.value.trim();
                    o.note.value = (!cur || cur === DEFAULT_NOTE) ? s.text : cur + '\n\n' + s.text;
                    o.note.dispatchEvent(new Event('input', { bubbles: true }));
                    post({ mode: 'record', event_id: s.event_id, action: 'inserted' });
                    o.host.innerHTML = '';
                });
                dis.addEventListener('click', function () {
                    post({ mode: 'record', event_id: s.event_id, action: 'dismissed' });
                    o.host.innerHTML = '';
                });
                row.appendChild(ins); row.appendChild(dis); box.appendChild(row);
                o.host.appendChild(box);
            }
            ((d && d.proposals) || []).forEach(function (p) {
                var box = el('div', 'mw-mia-suggest mw-mia-proposal');
                box.appendChild(el('div', 'mw-mia-title', 'Mia proposes an automation'));
                box.appendChild(el('div', 'mw-mia-text', 'You added "' + p.title + '" ' + p.evidence_inserted + ' of the last ' +
                    p.evidence_shown + ' times' + (p.evidence_converted ? ', and ' + p.evidence_converted + ' led to work' : '') +
                    '. Put it in the note automatically? You will still see it and can edit it before saving.'));
                var row = el('div', 'mw-mia-actions');
                [['Yes', 'active', 'btn-primary'], ['Not now', 'not_now', 'btn-outline-secondary'], ['Never', 'never', 'btn-outline-secondary']].forEach(function (b) {
                    var btn = el('button', 'btn btn-sm ' + b[2], b[0]); btn.type = 'button';
                    btn.addEventListener('click', function () { post({ mode: 'decide', rule_id: p.id, decision: b[1] }); box.remove(); });
                    row.appendChild(btn);
                });
                box.appendChild(row);
                o.host.appendChild(box);
            });
        }

        function load() {
            var c = o.getContext();
            var key = JSON.stringify(c);
            if (key === lastKey) return;
            lastKey = key;
            o.host.innerHTML = '';   // the old customer's suggestion goes at once
            var mine = ++seq;
            if (!c.property_id) { o.host.innerHTML = ''; return; }
            var q = 'mode=suggest&property_id=' + c.property_id + '&contact_id=' + (c.contact_id || 0) +
                '&company_id=' + (c.company_id || 0) + '&invoice_id=' + (o.invoiceId || 0) +
                (c.services || []).map(function (s) { return '&services[]=' + encodeURIComponent(s); }).join('');
            fetch('/crm/api/mia-invoice-suggestion.php?' + q, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (mine !== seq) return; if (d && d.success) render(d); else lastKey = null; })
                .catch(function () { if (mine === seq) lastKey = null; });
        }
        setInterval(load, 1500);   // customer, property and line pickers change these from several places
        load();
    }

    window.MwMiaInvoiceNote = { attach: attach };
})();
