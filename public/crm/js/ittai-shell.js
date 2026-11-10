/**
 * ittai-shell.js — the Ittai menu (appstack_sidebar.php + mobile-nav.php, it-shell only).
 * Phone menu: its head blocks fold open and closed; the block holding the current page is always open and
 * the rest remember the owner's choice (localStorage, per browser — a convenience).
 * Desktop sidebar (round 6): one head open at a time, the square head card beside the selected row, the
 * 72 px rail with flyouts, and the posture helper for a head's workspace (window.ItShell).
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

    // ── Desktop sidebar: the seven heads as navigation ──────────────────────────────────────────────
    var RAIL_KEY = 'it-rail';
    var reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

    function sidebarInit() {
        var sb = document.querySelector('.it-sidebar');
        if (!sb) return;
        var scroller = sb.querySelector('.sidebar-content');
        var cb = sb.querySelector('.it-cb');
        var groups = [].slice.call(sb.querySelectorAll('.it-grp'));
        var flyTimer = null;

        function isRail() { return sb.classList.contains('collapsed'); }
        function rowOf(g) { return g.querySelector('.it-grow'); }
        function cardOf(g) { return g.querySelector('.it-hp'); }
        function flyOf(g) { return g.querySelector('.it-fly'); }
        function setChev(g, open) {
            var use = g.querySelector('.it-grow > .it-ch use');
            if (use) use.setAttribute('href', open ? '#it-i-down' : '#it-i-right');
        }
        function setOpen(g, open) {
            var acc = g.querySelector('.it-acc');
            if (acc) acc.hidden = !open;
            rowOf(g).setAttribute('aria-expanded', open ? 'true' : 'false');
            setChev(g, open);
        }
        function closeCards() {
            groups.forEach(function (g) { cardOf(g).classList.remove('is-open'); });
        }
        function closeFlies() {
            clearTimeout(flyTimer);
            groups.forEach(function (g) { flyOf(g).classList.remove('is-open'); });
        }

        // The card: 200 px, centred on its row, 5 px right of the sidebar, kept 8 px inside the window.
        function placeCard(g) {
            var card = cardOf(g);
            if (!card || isRail()) return;
            var r = rowOf(g).getBoundingClientRect();
            var h = card.offsetHeight || 200;
            // Never over the top bar's controls: the card starts below it when the row is near the top.
            var bar = document.querySelector('.main > .navbar');
            var minTop = bar ? Math.max(8, bar.getBoundingClientRect().bottom + 8) : 8;
            var top = Math.min(Math.max(r.top + r.height / 2 - h / 2, minTop), Math.max(minTop, window.innerHeight - 8 - h));
            card.style.top = Math.round(top) + 'px';
            card.style.left = Math.round(sb.getBoundingClientRect().right + 5) + 'px';
            card.setAttribute('data-placed', '1');
        }
        function replaceOpenCards() {
            groups.forEach(function (g) { if (cardOf(g).classList.contains('is-open')) placeCard(g); });
        }
        function openCard(g) {
            closeCards();
            placeCard(g);
            cardOf(g).classList.add('is-open');
        }

        function select(g) {
            var ws = g.getAttribute('data-workspace') || '';
            if (g.classList.contains('is-sel')) {
                // Already selected: the second click only shows or hides the card.
                var card = cardOf(g);
                if (card.classList.contains('is-open')) card.classList.remove('is-open'); else openCard(g);
                return;
            }
            groups.forEach(function (o) {
                if (o === g) return;
                o.classList.remove('is-sel', 'is-here');
                setOpen(o, false);
            });
            g.classList.remove('is-here');
            g.classList.add('is-sel');
            setOpen(g, true);
            openCard(g);
            // The other group folds over 180 ms and the row moves with it: place the card again once it has
            // settled (the fold's transitionend below does it too; these are the fallback for a slow frame).
            setTimeout(replaceOpenCards, 220);
            setTimeout(replaceOpenCards, 600);
            if (ws !== '' && window.location.pathname !== ws) window.location.href = ws;
        }

        // Rail: a head's pages open in a flyout beside its button.
        function placeFly(g) {
            var fly = flyOf(g);
            var r = rowOf(g).getBoundingClientRect();
            var top = Math.max(r.top - 8, 8);
            fly.style.left = '76px';
            fly.style.top = Math.round(top) + 'px';
            var h = fly.offsetHeight;
            if (top + h > window.innerHeight - 8) fly.style.top = Math.round(Math.max(8, window.innerHeight - 8 - h)) + 'px';
        }
        function openFly(g) {
            clearTimeout(flyTimer);
            groups.forEach(function (o) { if (o !== g) flyOf(o).classList.remove('is-open'); });
            var fly = flyOf(g);
            fly.classList.add('is-open');
            placeFly(g);
        }

        groups.forEach(function (g) {
            var row = rowOf(g);
            row.addEventListener('click', function () {
                if (isRail()) {
                    openFly(g);
                    var ws = g.getAttribute('data-workspace') || '';
                    if (ws !== '' && window.location.pathname !== ws) window.location.href = ws;
                    return;
                }
                select(g);
            });
            g.addEventListener('mouseenter', function () { if (isRail()) openFly(g); });
            g.addEventListener('mouseleave', function () {
                if (!isRail()) return;
                clearTimeout(flyTimer);
                flyTimer = setTimeout(function () { flyOf(g).classList.remove('is-open'); }, 120);
            });
            g.addEventListener('focusin', function () { if (isRail()) openFly(g); });
            g.addEventListener('focusout', function (e) {
                if (isRail() && !g.contains(e.relatedTarget)) flyOf(g).classList.remove('is-open');
            });
        });

        // Escape, a click elsewhere or on the lockup closes the card and any flyout.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            closeCards();
            closeFlies();
        });
        document.addEventListener('click', function (e) {
            if (!sb.contains(e.target) || e.target.closest('.it-mark')) { closeCards(); closeFlies(); }
        });
        window.addEventListener('resize', function () { replaceOpenCards(); closeFlies(); });
        // A group finished folding or unfolding: every row below it has moved, so the open card follows.
        sb.addEventListener('transitionend', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('it-acc')) replaceOpenCards();
        });
        if (scroller) scroller.addEventListener('scroll', replaceOpenCards, { passive: true });

        // The rail: the sidebar's own button, the AppStack hamburger, and the remembered choice.
        function saveRail() {
            try { window.localStorage.setItem(RAIL_KEY, isRail() ? '1' : '0'); } catch (e) { /* private mode */ }
        }
        function syncRail() {
            var rail = isRail();
            if (cb) {
                cb.setAttribute('aria-label', rail ? 'Expand sidebar' : 'Collapse sidebar');
                var use = cb.querySelector('use');
                if (use) use.setAttribute('href', rail ? '#it-i-right' : '#it-i-left');
            }
            closeCards();
            closeFlies();
            saveRail();
            if (!rail) {
                var sel = sb.querySelector('.it-grp.is-sel');
                if (sel) window.requestAnimationFrame(function () { openCard(sel); });
            }
        }
        var desktop = !window.matchMedia || window.matchMedia('(min-width: 992px)').matches;
        try { if (desktop && window.localStorage.getItem(RAIL_KEY) === '1') sb.classList.add('collapsed'); } catch (e) { /* private mode */ }
        if (cb) cb.addEventListener('click', function () { sb.classList.toggle('collapsed'); });
        document.querySelectorAll('.sidebar-toggle').forEach(function (t) {
            t.addEventListener('click', function () {
                // On a desktop screen AppStack's toggle has nothing to slide away; here it folds to the rail.
                if (!window.matchMedia || !window.matchMedia('(min-width: 992px)').matches) return;
                sb.classList.toggle('collapsed');
                setTimeout(function () { sb.classList.remove('toggled'); }, 0);
            });
        });
        if (window.MutationObserver) {
            var was = isRail();
            new MutationObserver(function () {
                if (isRail() !== was) { was = isRail(); syncRail(); }
            }).observe(sb, { attributes: true, attributeFilter: ['class'] });
        }
        syncRail();

        // A selected head (the page is its workspace) shows its card from the start.
        var sel0 = sb.querySelector('.it-grp.is-sel');
        if (sel0 && !isRail()) {
            window.requestAnimationFrame(function () { openCard(sel0); });
            window.addEventListener('load', function () { replaceOpenCards(); });
        }
    }

    // ── A head's workspace: work posture (one job, advice as bars) and rest posture (advice as panels) ──
    function setBar(li, open) {
        if (!li) return;
        li.classList.toggle('shut', !open);
        var b = li.querySelector('.it-ah');
        if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function posture(frame, rest, animate) {
        if (!frame) return;
        var items = [].slice.call(frame.querySelectorAll('.it-ai'));
        var h1 = frame.querySelector('h1');
        var nm = h1 ? (h1.textContent || '').split(' ')[0] : '';
        var st = frame.querySelector('[role="status"]');
        clearTimeout(frame._t1); clearTimeout(frame._t2);
        frame.classList.remove('anim', 'closing');
        if (st) st.textContent = animate ? (rest ? nm + ': nothing waiting. Advice is open.' : nm + ': a new job is waiting.') : '';
        if (!animate || reduced.matches) {
            frame.classList.toggle('rest', rest);
            frame.classList.toggle('work', !rest);
            items.forEach(function (li) { li.style.removeProperty('--d'); setBar(li, rest); });
            return;
        }
        frame.classList.add('anim');
        if (rest) {
            frame.classList.add('closing');
            frame._t1 = setTimeout(function () {
                frame.classList.remove('closing', 'work');
                frame.classList.add('rest');
                items.forEach(function (li, i) { li.style.setProperty('--d', (i * 30) + 'ms'); setBar(li, true); });
            }, 180);
            frame._t2 = setTimeout(function () { frame.classList.remove('anim'); }, 180 + items.length * 30 + 260);
        } else {
            items.forEach(function (li) { li.style.removeProperty('--d'); setBar(li, false); });
            frame._t1 = setTimeout(function () { frame.classList.remove('rest'); frame.classList.add('work'); }, 180);
            frame._t2 = setTimeout(function () { frame.classList.remove('anim'); }, 440);
        }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.it-ah') : null;
        if (!b) return;
        setBar(b.closest('.it-ai'), b.getAttribute('aria-expanded') !== 'true');
    });
    window.ItShell = { posture: posture, setBar: setBar };

    function boot() {
        init();
        sidebarInit();
        // The phone menu's head blocks name their icons with data-feather; draw any still empty.
        try { if (window.feather && document.querySelector('.it-mb-link i[data-feather]')) window.feather.replace(); } catch (e) { /* icons are decoration */ }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
