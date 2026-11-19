/**
 * Media Library → Mia's tags: the hero star. And, on a contact page, "Opt this client out of photos".
 * API: /crm/api/media-tags.php (POST hero / optout).
 */
(function () {
    'use strict';
    var API = '/crm/api/media-tags.php';
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    document.addEventListener('click', function (e) {
        var star = e.target.closest('.mw-mtag-star');
        if (star) {
            var card = star.closest('[data-media]');
            var on = star.getAttribute('data-hero') !== '1';
            star.disabled = true;
            post({ mode: 'hero', id: +card.getAttribute('data-media'), on: on }).then(function (r) {
                star.disabled = false;
                if (!r || !r.ok) return;
                star.setAttribute('data-hero', on ? '1' : '0');
                star.classList.toggle('is-on', on);
                star.setAttribute('aria-label', on ? 'Remove hero star' : 'Mark as a hero photo');
            }).catch(function () { star.disabled = false; });
            return;
        }
        var opt = e.target.closest('.mw-photo-optout');
        if (opt) {
            var out = opt.getAttribute('data-out') !== '1';
            if (out && !window.confirm('Stop using photos of this client\'s properties in marketing? Photos already posted stay where they are.')) return;
            opt.disabled = true;
            post({ mode: 'optout', contact_id: +opt.getAttribute('data-contact'), optout: out }).then(function (r) {
                opt.disabled = false;
                if (!r || !r.ok) { opt.textContent = 'Didn\'t save — try again'; return; }
                opt.setAttribute('data-out', out ? '1' : '0');
                opt.classList.toggle('is-out', out);
                opt.textContent = out ? 'Photos: opted out (undo)' : 'Opt this client out of photos';
            }).catch(function () { opt.disabled = false; });
        }
    });
})();
