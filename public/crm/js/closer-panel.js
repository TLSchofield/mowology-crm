/**
 * Sam the Closer — "Closer's price" card on a quote.
 *
 * Shows the Closer's price beside each line's quoted price, the margin the quoted price earns,
 * a flag when that is under the margin floor, the three tiers per visit and the drive minutes
 * this stop adds. Display only: nothing here changes a price. Admins get the rate-card form
 * (Tim's edits; the Closer never changes a rate himself).
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-closer');
    if (!root) return;
    var API = '/crm/api/closer.php';
    var body = root.querySelector('.mw-closer-body');
    var quoteId = root.getAttribute('data-quote-id');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) {
        if (v == null || isNaN(v)) return '—';
        return '$' + Number(v).toLocaleString('en-CA', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function pct(v) { return v == null ? '—' : Math.round(v) + '%'; }
    function niceDate(d) {
        if (!d) return '';
        var t = new Date(d + 'T12:00:00');
        return t.toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
    }

    function driveText(dr) {
        if (!dr) return 'Drive not counted';
        var m = dr.minutes < 1 ? 'Under 1 min' : '+' + Math.round(dr.minutes) + ' min';
        if (dr.basis === 'round_trip') return m + ' drive — round trip from the yard (no route day nearby)';
        var s = m + ' drive added to the ' + niceDate(dr.date) + ' route';
        if (dr.neighbours > 0) s += ' · ' + dr.neighbours + ' client' + (dr.neighbours === 1 ? '' : 's') + ' within 500 m';
        return s;
    }

    function render(d) {
        var h = '';
        if (d.below_floor > 0) {
            h += '<div class="mw-closer-alert">' + d.below_floor + ' line' + (d.below_floor === 1 ? '' : 's') +
                 ' quoted under the ' + pct(d.card.margin_floor_pct) + ' margin floor.</div>';
        }
        h += '<table class="mw-closer-table"><thead><tr><th>Line</th><th class="text-right">Quoted</th>' +
             '<th class="text-right">Closer</th><th class="text-right">Margin</th></tr></thead><tbody>';
        (d.lines || []).forEach(function (l) {
            var c = l.closer;
            h += '<tr class="' + (c && c.quoted_below_floor ? 'is-below' : '') + '">';
            h += '<td>' + esc(l.label) + (l.basis ? '<div class="mw-closer-basis">' + esc(l.basis) + '</div>' : '') +
                 (l.why_not ? '<div class="mw-closer-basis">' + esc(l.why_not) + '</div>' : '') + '</td>';
            h += '<td class="text-right">' + money(l.quoted) + '</td>';
            h += '<td class="text-right"><strong>' + (c ? money(c.price) : '—') + '</strong>' +
                 (c && c.minimum_applied ? '<div class="mw-closer-basis">minimum</div>' : '') + '</td>';
            var thin = c && !c.quoted_below_floor && c.margin_at_quoted != null && c.margin_at_quoted < d.card.target_margin_pct;
            h += '<td class="text-right">' + (c ? '<span class="mw-closer-margin' + (c.quoted_below_floor ? ' is-below' : (thin ? ' is-thin' : '')) +
                 '" title="' + (thin ? 'Above the floor, under the ' + pct(d.card.target_margin_pct) + ' target' : '') + '">' +
                 pct(c.margin_at_quoted) + '</span>' : '—') + '</td>';
            h += '</tr>';
        });
        h += '</tbody></table>';
        h += '<p class="mw-closer-drive">' + esc(driveText(d.drive)) + '</p>';

        var tiers = (d.tiers || []).filter(function (t) { return t.price != null; });
        if (tiers.length) {
            h += '<div class="mw-closer-tiers">';
            tiers.forEach(function (t) {
                h += '<div class="mw-closer-tier"><span class="mw-closer-tier-name">' + esc(t.label) + '</span>' +
                     '<span class="mw-closer-tier-price">' + money(t.price) + '</span><span class="mw-closer-basis">per visit</span>' +
                     (t.missing && t.missing.length ? '<span class="mw-closer-basis">without ' + esc(t.missing.join(', ')) + '</span>' : '') + '</div>';
            });
            h += '</div>';
        }

        // Trip lines Sam suggests from Otto's run costs. Tim adds or ignores them — never added here.
        if ((d.trip_lines || []).length) {
            h += '<div class="mw-closer-trips"><p class="mw-closer-rc-head">Trips this job needs</p><ul>';
            d.trip_lines.forEach(function (t) {
                h += '<li class="' + (t.ready ? '' : 'is-waiting') + '"><span class="mw-closer-trip-name">' + esc(t.label) + '</span>' +
                     '<span class="mw-closer-trip-price">' + (t.ready ? money(t.price) + ' <small>+ GST</small>' : 'not enough runs yet (' + esc(Math.min(t.sample_n, t.needed)) + '/' + esc(t.needed) + ')') + '</span>' +
                     '<div class="mw-closer-basis">Because of “' + esc(t.because) + '”. ' + esc(t.why) + '.</div>' +
                     (t.basis ? '<div class="mw-closer-basis">' + esc(t.basis) + '</div>' : '') + '</li>';
            });
            h += '</ul><p class="mw-closer-basis">Suggestions only — add the line to the quote yourself if you want it.</p></div>';
        }

        if ((d.flags || []).length) {
            h += '<ul class="mw-closer-flags">' + d.flags.map(function (f) { return '<li>' + esc(f) + '</li>'; }).join('') + '</ul>';
        }
        var c = d.card;
        h += '<p class="mw-closer-card">' +
             (c.hourly_cost != null ? 'Cost ' + money(c.hourly_cost) + '/person-hour' : 'Hourly cost not set') +
             ' · target ' + pct(c.target_margin_pct) + ' · floor ' + pct(c.margin_floor_pct) +
             ' · minimum visit ' + money(c.min_visit) + '</p>';
        h += '<p class="mw-closer-note">' + esc(d.note) + '</p>';
        if (d.admin) h += '<button type="button" class="btn btn-sm btn-outline-secondary mw-closer-edit">Rate card</button><div class="mw-closer-form"></div>';
        body.innerHTML = h;
        var btn = body.querySelector('.mw-closer-edit');
        if (btn) btn.addEventListener('click', function () { openForm(d); });
    }

    function openForm(d) {
        var box = body.querySelector('.mw-closer-form');
        if (box.innerHTML) { box.innerHTML = ''; return; }
        fetch(API + '?mode=card', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) { box.textContent = j.error || 'Could not load the rate card.'; return; }
            var c = j.card, s = c.suggested_loaded || {};
            var h = '<form class="mw-closer-rc">';
            h += '<label>Cost per person-hour <input name="hourly_cost" type="number" step="0.01" value="' + esc(c.hourly_cost) + '"></label>';
            h += '<div class="mw-closer-basis">' + esc(c.hourly_source || '') + '</div>';
            if (s.total) {
                h += '<div class="mw-closer-basis">From your cost factors, fully loaded: ' + money(s.total) + ' = ' +
                     esc(s.labour_name || 'labour') + ' ' + money(s.labour) + ' + kit ' + money(s.kit) + ' + overhead ' + money(s.overhead) + '/h</div>';
            }
            h += '<label>Margin floor % <input name="margin_floor_pct" type="number" step="0.5" value="' + esc(c.margin_floor_pct) + '"></label>';
            h += '<label>Minimum visit $ <input name="min_visit" type="number" step="1" value="' + esc(c.min_visit) + '"></label>';
            h += '<div class="mw-closer-basis">Target margin ' + pct(c.target_margin_pct) + ' lives on Cost Factors (profit margin).</div>';
            h += '<p class="mw-closer-rc-head">Person-minutes per service (used until ' + esc(c.calibration_min) + ' timed visits)</p>' +
                 '<div class="mw-closer-basis">Fixed minutes per visit, plus minutes per 1,000 ft² of lawn (or per 100 ft of edge/hedge).</div>';
            var cal = {};
            (j.calibration || []).forEach(function (r) { cal[r.service_key + ':' + r.source] = r; });
            Object.keys(j.services).forEach(function (k) {
                var sv = j.services[k], man = cal[k + ':manual'] || {}, fit = cal[k + ':fit'];
                var unit = sv.unit === 'lawn' ? '/1000 ft²' : '/100 ft';
                var seed = (d.services && d.services[k] && d.services[k].basis) || 'needs minutes';
                h += '<div class="mw-closer-rc-row"><span>' + esc(sv.label) + '</span>' +
                     '<input name="m_' + k + '_fixed" type="number" step="0.5" placeholder="fixed" value="' + esc(man.fixed_minutes || '') + '">' +
                     '<input name="m_' + k + '_per" type="number" step="0.1" placeholder="' + esc(unit) + '" value="' + esc(man.per_unit_minutes || '') + '">' +
                     '<span class="mw-closer-basis">' + esc(seed) + (fit ? ' · fit n=' + fit.n + (fit.realised_margin_pct != null ? ', earned ' + pct(fit.realised_margin_pct) : '') : '') + '</span></div>';
            });
            h += '<button type="submit" class="btn btn-sm btn-primary">Save rate card</button> <span class="mw-closer-rc-msg"></span></form>';
            box.innerHTML = h;
            box.querySelector('form').addEventListener('submit', function (e) {
                e.preventDefault();
                var f = e.target, payload = { mode: 'save_card', csrf_token: window.MW_CSRF_TOKEN || '', minutes: {} };
                ['hourly_cost', 'margin_floor_pct', 'min_visit'].forEach(function (n) { payload[n] = f.elements[n].value; });
                Object.keys(j.services).forEach(function (k) {
                    payload.minutes[k] = { fixed: f.elements['m_' + k + '_fixed'].value, per_unit: f.elements['m_' + k + '_per'].value };
                });
                fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var msg = f.querySelector('.mw-closer-rc-msg');
                        if (res.ok) { msg.textContent = 'Saved.'; load(); }
                        else msg.textContent = (res.errors || [res.error || 'Not saved']).join(' · ');
                    });
            });
        });
    }

    function load() {
        fetch(API + '?mode=quote&id=' + encodeURIComponent(quoteId), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { body.innerHTML = '<p class="mw-closer-loading">' + esc(d.error || 'No Closer price for this quote.') + '</p>'; return; }
                render(d);
            })
            .catch(function () { body.innerHTML = '<p class="mw-closer-loading">The Closer could not price this quote right now.</p>'; });
    }
    load();
})();
