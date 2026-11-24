/**
 * Otto — "how to look after it" (#mw-otto-care, public/crm/includes/otto-care.php).
 *
 *   New machines from label photos  → Add to equipment / Not now
 *   Each machine: Tim's intervals and where they stand (due / nearly due, from job-timer hours)
 *   "Ask Otto to read the manual"    → upload a PDF or a photo of the maintenance table; Otto
 *                                      proposes intervals quoting the page; Tim confirms each
 *                                      (numbers editable) before it's saved
 *   Stock running low · label care notes · SDS links (Tim pastes the link)
 * API: /crm/api/label-products.php ?mode=otto · POST accept / dismiss / manual_read (multipart) /
 * manual_confirm / manual_skip / save_sds. Nothing is written without a click.
 */
(function () {
    'use strict';
    var box = document.getElementById('mw-otto-care');
    if (!box) return;
    var API = '/crm/api/label-products.php';
    var data = null, busy = false, msg = '', open = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function load(m) {
        if (m !== undefined) msg = m;
        return fetch(API + '?mode=otto', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (!d || !d.ok || !d.ready) { box.hidden = true; return; } data = d; render(); })
            .catch(function () { box.hidden = true; });
    }

    function proposals() {
        if (!data.items.length) return '';
        return data.items.map(function (it) {
            return '<div class="mw-oc-prop">' +
                (it.photo_url ? '<img src="' + esc(it.photo_url) + '" alt="Nameplate photo" loading="lazy">' : '') +
                '<div><div class="mw-oc-say">' + esc(it.say) + '</div>' +
                '<div class="mw-pp-btns"><button type="button" class="mw-rc-ok" data-oc="add" data-id="' + it.id + '">Add to equipment</button>' +
                '<button type="button" class="mw-rc-sk" data-oc="no" data-id="' + it.id + '">Not now</button></div></div></div>';
        }).join('');
    }

    function machine(m) {
        var chips = m.intervals.length ? m.intervals.map(function (i) {
            return '<span class="mw-oc-chip is-' + esc(i.state) + '" title="' + esc(i.hours + ' h / ' + i.days + ' days since ' + (i.last || 'purchase')) + '">' +
                esc(i.task) + ' · ' + esc(i.every) + (i.state === 'due' ? ' · due' : i.state === 'soon' ? ' · soon' : '') + '</span>';
        }).join('') : '<span class="mw-oc-none">No intervals yet — they are your numbers.</span>';
        var manual = m.manual.map(function (p) {
            return '<div class="mw-oc-iv" data-read="' + p.read_id + '" data-index="' + p.index + '">' +
                '<input type="text" name="task" value="' + esc(p.task) + '" aria-label="Task">' +
                '<label>every <input type="number" name="every_hours" min="0" step="0.5" value="' + esc(p.every_hours || '') + '"> h</label>' +
                '<label>or <input type="number" name="every_days" min="0" step="1" value="' + esc(p.every_days || '') + '"> days</label>' +
                '<button type="button" class="mw-rc-ok" data-oc="ivok">Save</button><button type="button" class="mw-rc-sk" data-oc="ivno">Skip</button>' +
                '<div class="mw-oc-quote">“' + esc(p.quote) + '”' + (p.page ? ' — p. ' + esc(p.page) : '') + '</div></div>';
        }).join('');
        var left = data.manual && data.manual.ready ? data.manual.left : 0;
        var ask = data.manual && data.manual.ready
            ? '<div class="mw-oc-ask"><input type="file" accept="application/pdf,image/*" data-eq="' + m.id + '" aria-label="Manual or maintenance-table photo">' +
              '<button type="button" class="mw-bl-askbtn" data-oc="read" data-eq="' + m.id + '"' + (left > 0 && !busy ? '' : ' disabled') + '>Ask Otto to read the manual</button>' +
              '<small>' + left + ' left today</small></div>' : '';
        var title = m.name + (m.make || m.model ? ' · ' + [m.make, m.model].filter(Boolean).join(' ') : '') + (m.serial_no ? ' · S/N ' + m.serial_no : '');
        return '<details class="mw-oc-machine"' + (open[m.id] || m.manual.length ? ' open' : '') + ' data-mid="' + m.id + '"><summary>' + esc(title) + '</summary>' +
            '<div class="mw-oc-chips">' + chips + '</div>' + manual + ask + '</details>';
    }

    function render() {
        var d = data;
        var due = 0;
        d.machines.forEach(function (m) { m.intervals.forEach(function (i) { if (i.state !== 'ok') due++; }); });
        var low = d.low_stock.length ? '<div class="mw-oc-sec"><b>Stock running low</b><ul>' + d.low_stock.map(function (l) { return '<li>' + esc(l.say) + '</li>'; }).join('') +
            '</ul><small>Stock rises when receipt lines are linked; nothing is taken off for jobs.</small></div>' : '';
        var care = d.care.length ? '<div class="mw-oc-sec"><b>From the labels</b><ul>' + d.care.map(function (c) {
            return '<li><b>' + esc(c.name) + '</b>' + (c.care ? ' — ' + esc(c.care) : '') + (c.safety ? ' ⚠ ' + esc(c.safety) : '') +
                (!c.care && !c.safety ? ' — <span class="mw-oc-none">the label prints no storage or safety advice</span>' : '') +
                (c.sds_url ? ' · <a href="' + esc(c.sds_url) + '" target="_blank" rel="noopener">SDS</a>' : '') +
                (c.needs_sds ? '<div class="mw-oc-sds"><input type="url" placeholder="Paste the SDS link (https://…)" data-sds="' + c.id + '"><button type="button" class="mw-rc-sk" data-oc="sds" data-id="' + c.id + '">Save</button></div>' : '') +
                '</li>';
        }).join('') + '</ul></div>' : '';
        if (!d.items.length && !d.machines.length && !low && !care) { box.hidden = true; return; }
        box.hidden = false;
        box.innerHTML = '<div class="mw-bl-head"><span><b>🔧 Looking after it</b>' + (due ? ' · ' + due + ' service' + (due === 1 ? '' : 's') + ' due or soon' : '') + '</span></div>' +
            proposals() + (d.machines.length ? '<div class="mw-oc-sec">' + d.machines.map(machine).join('') + '</div>' : '') + low + care +
            '<div class="mw-rc-msg">' + esc(msg) + '</div>';
    }

    box.addEventListener('toggle', function (e) {
        var det = e.target.closest && e.target.closest('details[data-mid]');
        if (det) open[det.getAttribute('data-mid')] = det.open;
    }, true);

    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-oc]');
        if (!b || busy) return;
        var act = b.getAttribute('data-oc'), req = null;
        if (act === 'add' || act === 'no') {
            req = post({ mode: act === 'add' ? 'accept' : 'dismiss', id: +b.getAttribute('data-id') });
        } else if (act === 'ivok' || act === 'ivno') {
            var row = b.closest('.mw-oc-iv');
            var body = { mode: act === 'ivok' ? 'manual_confirm' : 'manual_skip', read_id: +row.getAttribute('data-read'), index: +row.getAttribute('data-index') };
            if (act === 'ivok') {
                body.task = row.querySelector('[name=task]').value;
                body.every_hours = row.querySelector('[name=every_hours]').value;
                body.every_days = row.querySelector('[name=every_days]').value;
            }
            req = post(body);
        } else if (act === 'sds') {
            var inp = box.querySelector('[data-sds="' + b.getAttribute('data-id') + '"]');
            req = post({ mode: 'save_sds', product_id: +b.getAttribute('data-id'), url: inp ? inp.value : '' });
        } else if (act === 'read') {
            var eq = b.getAttribute('data-eq');
            var file = box.querySelector('input[type=file][data-eq="' + eq + '"]');
            if (!file || !file.files.length) { msg = 'Choose the manual (PDF) or a photo of its maintenance table first.'; render(); return; }
            open[eq] = true;
            var fd = new FormData();
            fd.append('mode', 'manual_read');
            fd.append('equipment_id', eq);
            fd.append('manual', file.files[0]);
            fd.append('csrf_token', window.MW_CSRF_TOKEN || '');
            msg = 'Reading the manual…';
            render();
            req = fetch(API, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
        }
        if (!req) return;
        busy = true;
        req.then(function (r) { busy = false; return load(r && (r.message || r.error) || 'Done.'); })
           .catch(function () { busy = false; msg = 'Could not reach the server — try again.'; render(); });
    });

    load('');
})();
