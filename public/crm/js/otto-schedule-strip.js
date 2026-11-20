/**
 * Otto's strip on the schedule surfaces (public/crm/includes/otto-schedule-strip.php).
 *
 * One line — Otto's face, how many things he has for the day(s) in view and the most urgent one — that
 * opens to the list. Items with an id are Otto's own suggestions and get the card's buttons
 * (window.MwOtto.item from otto-card.js, loaded on demand); the rest (overbooked day, empty stop,
 * no border) link to where they are fixed. Data: /crm/api/otto-schedule.php (cached 5 min).
 */
(function () {
    'use strict';
    var API = '/crm/api/otto-schedule.php';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function label(iso) {
        var d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
    }

    /** otto-card.js gives the buttons; load it once if the page has not. */
    var cardLoading = null;
    function withCard() {
        if (window.MwOtto) return Promise.resolve(window.MwOtto);
        if (!cardLoading) {
            cardLoading = new Promise(function (resolve) {
                var s = document.createElement('script');
                s.src = '/crm/js/otto-card.js';
                s.onload = function () { resolve(window.MwOtto || null); };
                s.onerror = function () { resolve(null); };
                document.head.appendChild(s);
            });
        }
        return cardLoading;
    }

    function linkItem(it) {
        var el = document.createElement('div');
        el.className = 'mw-otto-item is-p' + it.priority + ' is-link';
        el.dataset.kind = it.kind;
        el.innerHTML = '<p>' + esc(it.text) + '</p>' +
            (it.detail ? '<small class="mw-otto-detail">' + esc(it.detail) + '</small>' : '') +
            (it.url ? '<a class="mw-otto-open" href="' + esc(it.url) + '">Open</a>' : '');
        return el;
    }

    function fill(strip, day) {
        var list = strip.querySelector('.mw-otto-strip-list');
        var count = strip.querySelector('.mw-otto-strip-count');
        var top = strip.querySelector('.mw-otto-strip-top');
        var n = day.count || 0;
        var when = strip.dataset.date ? '' : ' for ' + label(day.date);
        count.textContent = n ? n + (n === 1 ? ' thing' : ' things') + when : 'Nothing needs you' + when + '.';
        top.textContent = n ? '· ' + day.items[0].text : '';
        strip.classList.toggle('is-clear', !n);
        strip.classList.toggle('is-alert', day.items.some(function (i) { return i.priority <= 2; }));
        return withCard().then(function (card) {
            list.innerHTML = '';
            if (!n) { list.innerHTML = '<div class="mw-otto-empty">All caught up for ' + esc(label(day.date)) + '.</div>'; return; }
            day.items.forEach(function (it) {
                list.appendChild(it.id && card ? card.item(it) : linkItem(it));
            });
        });
    }

    function load(strip, focus, fresh) {
        var q;
        if (strip.dataset.date) q = 'mode=day&date=' + encodeURIComponent(strip.dataset.date);
        else q = 'mode=range&from=' + encodeURIComponent(strip.dataset.from) + '&to=' + encodeURIComponent(strip.dataset.to) +
            (focus || strip.dataset.focus ? '&focus=' + encodeURIComponent(focus || strip.dataset.focus) : '');
        if (fresh) q += '&fresh=1';
        return fetch(API + '?' + q, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                strip.classList.remove('is-loading');
                if (!r || !r.ok) { strip.hidden = true; return; }
                if (r.days) {
                    days(strip, r.days, r.focus.date);
                    return fill(strip, r.focus);
                }
                return fill(strip, r);
            })
            .catch(function () { strip.hidden = true; });
    }

    function days(strip, list, focus) {
        var box = strip.querySelector('.mw-otto-strip-days');
        box.hidden = false;
        box.innerHTML = list.map(function (d) {
            return '<button type="button" class="mw-otto-strip-day' + (d.date === focus ? ' is-on' : '') + (d.p1 ? ' is-alert' : '') +
                (d.count ? '' : ' is-clear') + '" data-date="' + esc(d.date) + '" title="' + esc(d.top || 'Nothing for Otto') + '">' +
                esc(label(d.date)) + ' <b>' + (d.count || '✓') + '</b></button>';
        }).join('');
    }

    function init(strip) {
        var hd = strip.querySelector('.mw-otto-strip-hd');
        var list = strip.querySelector('.mw-otto-strip-list');
        hd.addEventListener('click', function () {
            var open = list.hidden;
            list.hidden = !open;
            hd.setAttribute('aria-expanded', open ? 'true' : 'false');
            strip.classList.toggle('is-open', open);
        });
        strip.querySelector('.mw-otto-strip-days').addEventListener('click', function (e) {
            var b = e.target.closest('button[data-date]');
            if (!b) return;
            strip.querySelectorAll('.mw-otto-strip-day').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            strip.classList.add('is-loading');
            list.hidden = false;
            hd.setAttribute('aria-expanded', 'true');
            strip.classList.add('is-open');
            load(strip, b.getAttribute('data-date'), false);
        });
        load(strip, null, false);
    }

    function boot() { document.querySelectorAll('.mw-otto-strip').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
