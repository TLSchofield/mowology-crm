/**
 * ittai-shell.js — the Ittai menu's head blocks open and close (appstack_sidebar.php, it-shell only).
 * The block holding the current page is always open; the rest remember the owner's choice
 * (localStorage, per browser — a convenience, so a blocked storage just means "all open").
 */
(function () {
    'use strict';
    var KEY = 'it-sb-closed';

    function load() {
        try { return JSON.parse(window.localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; }
    }
    function save(list) {
        try { window.localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* private mode */ }
    }

    var OPEN_KEY = 'it-mb-open';
    function loadOpen() {
        try { return JSON.parse(window.localStorage.getItem(OPEN_KEY) || '[]') || []; } catch (e) { return []; }
    }
    function saveOpen(list) {
        try { window.localStorage.setItem(OPEN_KEY, JSON.stringify(list)); } catch (e) { /* private mode */ }
    }

    function init() {
        var closed = load();
        document.querySelectorAll('.it-sb-strip').forEach(function (strip) {
            var head = strip.getAttribute('data-head');
            var btn = strip.querySelector('.it-sb-toggle');
            var block = document.getElementById(btn.getAttribute('aria-controls'));
            if (!block) return;

            function set(open) {
                block.hidden = !open;
                btn.setAttribute('aria-expanded', String(open));
                strip.classList.toggle('is-closed', !open);
            }
            // Phone menu: everything folded but the block you're in, unless you opened it before.
            var phone = !!strip.closest('.mw-mobile-menu-sheet');
            var opened = phone ? loadOpen() : [];
            set(strip.classList.contains('is-here') || (phone ? opened.indexOf(head) !== -1 : closed.indexOf(head) === -1));

            btn.addEventListener('click', function () {
                var open = block.hidden;
                set(open);
                if (phone) {
                    var o = loadOpen().filter(function (h) { return h !== head; });
                    if (open) o.push(head);
                    saveOpen(o);
                    return;
                }
                var list = load().filter(function (h) { return h !== head; });
                if (!open) list.push(head);
                save(list);
            });
        });
    }

    function boot() {
        init();
        // The phone menu's head blocks name their icons with data-feather; draw any still empty.
        try { if (window.feather && document.querySelector('.it-mb-link i[data-feather]')) window.feather.replace(); } catch (e) { /* icons are decoration */ }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
