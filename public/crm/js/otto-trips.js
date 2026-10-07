/**
 * Otto — "Today's runs" on Otto's card (public/crm/includes/otto-trips.php).
 *
 * Two owner actions, both posted to /crm/api/trips.php, then the page reloads to show the
 * re-priced day: name an unnamed stop once (creates the place), and the one-man / two-man
 * toggle on a finished run. Nothing here messages anyone.
 */
(function () {
    'use strict';
    var API = '/crm/api/trips.php';

    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function say(el, text) {
        var m = el.querySelector('.mw-otto-msg');
        if (!m) {
            m = document.createElement('div');
            m.className = 'mw-otto-msg';
            el.appendChild(m);
        }
        m.textContent = text;
    }

    function init() {
        var box = document.getElementById('mw-otto-trips');
        if (!box) return;
        var date = box.getAttribute('data-date') || '';

        box.addEventListener('submit', function (e) {
            var form = e.target.closest('.mw-otto-name');
            if (!form) return;
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            post({
                mode: 'name_stop',
                lat: parseFloat(form.getAttribute('data-lat')),
                lng: parseFloat(form.getAttribute('data-lng')),
                name: form.elements.name.value,
                kind: form.elements.kind.value,
                date: date
            }).then(function (r) {
                if (r && r.ok) { say(form, 'Saved — I\'ll know it from now on.'); window.location.reload(); return; }
                btn.disabled = false;
                say(form, (r && r.error) || 'Could not save that.');
            }).catch(function () { btn.disabled = false; say(form, 'Could not reach the server.'); });
        });

        box.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-trip]');
            if (!btn) return;
            var run = btn.closest('.mw-otto-run');
            btn.disabled = true;
            post({ mode: 'set_crew', trip_key: btn.getAttribute('data-trip'), one_man: btn.getAttribute('data-one-man') === '1' ? 1 : 0 })
                .then(function (r) {
                    if (r && r.ok) { window.location.reload(); return; }
                    btn.disabled = false;
                    say(run, (r && r.error) || 'Could not save that.');
                }).catch(function () { btn.disabled = false; say(run, 'Could not reach the server.'); });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
