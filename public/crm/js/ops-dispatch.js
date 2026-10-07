/**
 * Otto the Dispatcher — the rules and equipment pages (/crm/ops/municipal-rules.php,
 * /crm/ops/equipment.php). Any [data-row] holds named inputs; a button[data-save="mode"]
 * inside it posts them to /crm/api/dispatch.php and reloads on success. Checkboxes named
 * "classes" are sent as a list.
 */
(function () {
    'use strict';
    var API = '/crm/api/dispatch.php';

    function collect(row) {
        var out = {};
        row.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
            if (el.type === 'checkbox') {
                if (el.name === 'classes') { (out.classes = out.classes || []); if (el.checked) out.classes.push(el.value); }
                else out[el.name] = el.checked ? 1 : 0;
            } else {
                out[el.name] = el.value;
            }
        });
        Object.keys(row.dataset).forEach(function (k) { if (k !== 'row' && !(k in out)) out[k] = row.dataset[k]; });
        return out;
    }

    function say(row, text, bad) {
        var m = row.querySelector('.mw-ops-msg');
        if (!m) { m = document.createElement('span'); m.className = 'mw-ops-msg'; row.appendChild(m); }
        m.textContent = text;
        m.classList.toggle('is-bad', !!bad);
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-save]');
        if (!b) return;
        var row = b.closest('[data-row]');
        if (!row) return;
        e.preventDefault();
        if (b.dataset.confirm && !window.confirm(b.dataset.confirm)) return;
        var body = collect(row);
        body.mode = b.dataset.save;
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        b.disabled = true;
        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).then(function (r) {
            b.disabled = false;
            if (r.ok) { say(row, r.message || 'Saved.'); setTimeout(function () { location.reload(); }, 600); }
            else say(row, r.message || r.error || 'That did not save.', true);
        }).catch(function () { b.disabled = false; say(row, 'No connection. Try again.', true); });
    });
})();
