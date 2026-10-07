/**
 * Otto's property gaps card (public/crm/includes/otto-property-card.php).
 *
 * "Geocode": looks the address up with the Google Maps JS Geocoder (loaded on demand when
 * the page has not already loaded it), saves the pin through /crm/api/geocode-save.php and
 * marks the row done — no page reload. The border and measure rows are plain links.
 */
(function () {
    'use strict';

    var mapsPromise = null;

    function loadMaps(key) {
        if (window.google && window.google.maps && window.google.maps.Geocoder) return Promise.resolve();
        if (mapsPromise) return mapsPromise;
        mapsPromise = new Promise(function (resolve, reject) {
            if (!key) { reject(new Error('No Google Maps key on this page.')); return; }
            var cb = '__mwOttoMapsReady';
            window[cb] = function () { resolve(); };
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&callback=' + cb;
            s.async = true;
            s.onerror = function () { mapsPromise = null; reject(new Error('Google Maps did not load.')); };
            document.head.appendChild(s);
        });
        return mapsPromise;
    }

    function geocode(address) {
        return new Promise(function (resolve, reject) {
            new window.google.maps.Geocoder().geocode({ address: address }, function (results, status) {
                if (status === 'OK' && results && results[0]) resolve(results[0]);
                else if (status === 'ZERO_RESULTS') reject(new Error('Google could not find this address. Check it and try again.'));
                else reject(new Error('Google said ' + status + '.'));
            });
        });
    }

    function save(propertyId, lat, lng) {
        return fetch('/crm/api/geocode-save.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: propertyId, lat: lat, lng: lng, csrf_token: window.MW_CSRF_TOKEN || '' })
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'The server answered ' + r.status + '.' }; });
        }).then(function (data) {
            if (!data || !data.success) throw new Error((data && data.error) || 'The pin was not saved.');
            return data;
        });
    }

    function init() {
        var card = document.getElementById('mw-otto-gaps');
        if (!card || card.dataset.ready) return;
        card.dataset.ready = '1';
        var say = card.querySelector('[data-otto-say]');

        function refresh() {
            if (card.querySelector('.mw-otto-gap:not(.is-done)')) return;
            if (say) say.textContent = 'All set — I can route here now.';
        }

        card.querySelectorAll('[data-otto-geocode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.mw-otto-gap');
                var note = row && row.querySelector('[data-otto-note]');
                var id = parseInt(btn.getAttribute('data-property-id'), 10);
                var address = btn.getAttribute('data-address') || '';
                btn.disabled = true;
                btn.textContent = 'Looking up…';
                if (note) note.classList.remove('is-bad');
                var found;
                loadMaps(card.getAttribute('data-maps-key'))
                    .then(function () { return geocode(address); })
                    .then(function (result) {
                        found = result;
                        var loc = result.geometry.location;
                        return save(id, loc.lat(), loc.lng());
                    })
                    .then(function () {
                        row.classList.add('is-done');
                        if (note) note.textContent = 'Pinned at ' + (found.formatted_address || address);
                        btn.remove();
                        var after = card.getAttribute('data-after-pin');
                        if (say && after) say.textContent = after;
                        card.querySelectorAll('.mw-otto-gap[data-gap="border"] .mw-otto-gap-note').forEach(function (n) {
                            n.textContent = "Where the crew's arrival is counted.";
                        });
                        refresh();
                    })
                    .catch(function (err) {
                        btn.disabled = false;
                        btn.textContent = 'Geocode';
                        if (note) {
                            note.textContent = err && err.message ? err.message : 'That did not work.';
                            note.classList.add('is-bad');
                        }
                    });
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
