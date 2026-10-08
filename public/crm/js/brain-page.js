/**
 * The full brain page (/crm/brain.php): tabs per kind of knowledge, "Show N more" per tier,
 * and a search across everything the head knows. The 3D brain itself is head-brain.js.
 * Everything is rendered by the server; this only shows and hides rows.
 */
(function () {
    'use strict';
    function init() {
        var page = document.querySelector('[data-brain-page]');
        if (!page) return;
        var tabs = Array.prototype.slice.call(page.querySelectorAll('[data-brp-tab]'));
        var search = page.querySelector('[data-brp-search]');

        function panelOf(tab) { return document.getElementById(tab.getAttribute('aria-controls')); }
        function select(tab) {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                var p = panelOf(t);
                if (p) p.hidden = !on;
            });
        }
        tabs.forEach(function (t, i) {
            t.tabIndex = t.getAttribute('aria-selected') === 'true' ? 0 : -1;
            t.addEventListener('click', function () { select(t); });
            t.addEventListener('keydown', function (e) {
                var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (!d) return;
                var n = tabs[(i + d + tabs.length) % tabs.length];
                select(n); n.focus(); e.preventDefault();
            });
        });

        page.addEventListener('click', function (e) {
            var b = e.target.closest('[data-brp-more]');
            if (!b) return;
            var tier = b.closest('[data-brp-tier]');
            tier.querySelectorAll('[data-brp-extra]').forEach(function (li) { li.hidden = false; li.removeAttribute('data-brp-extra'); });
            b.remove();
        });

        if (!search) return;
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            page.querySelectorAll('.mw-brp-panel').forEach(function (panel) {
                var any = false;
                panel.querySelectorAll('[data-brp-tier]').forEach(function (tier) {
                    var shown = 0;
                    tier.querySelectorAll('.mw-brp-row').forEach(function (li) {
                        var hit = !q || (li.getAttribute('data-q') || '').indexOf(q) >= 0;
                        li.hidden = q ? !hit : li.hasAttribute('data-brp-extra');
                        if (hit) shown++;
                    });
                    tier.hidden = q !== '' && shown === 0;
                    var more = tier.querySelector('[data-brp-more]');
                    if (more) more.hidden = q !== '';
                    if (shown) any = true;
                });
                var none = panel.querySelector('[data-brp-none]');
                if (none) none.hidden = !q || any;
            });
            // While searching, jump to the first tab with a match.
            if (q) {
                for (var i = 0; i < tabs.length; i++) {
                    var p = panelOf(tabs[i]);
                    if (p && p.querySelector('.mw-brp-row:not([hidden])')) { if (p.hidden) select(tabs[i]); break; }
                }
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
