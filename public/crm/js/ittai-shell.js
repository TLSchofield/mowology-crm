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

        // Flyouts move out to <body>: inside the sidebar they sat under the page (its stacking context is below
        // the content), so no z-index could lift them (Tim, 2026-11-10). The card stays in flow: it is the head's
        // row grown taller, and the pages move down beneath it.
        var layer = document.createElement('div');
        layer.className = 'it-layer';
        document.body.appendChild(layer);
        var cards = {}, flies = {};
        // The card floats on <body>, CENTRED on the rail line (Tim, 2026-11-10); a spacer in the group keeps
        // its room so the pages move down beneath it and the live line meets its bottom edge.
        var spaces = {};
        groups.forEach(function (g) {
            var k = g.getAttribute('data-head');
            var c = g.querySelector('.it-hp'), f = g.querySelector('.it-fly');
            if (c) {
                var sp = document.createElement('div');
                sp.className = 'it-hp-space';
                sp.hidden = true;
                c.parentNode.insertBefore(sp, c);
                layer.appendChild(c);
                cards[k] = c;
                spaces[k] = sp;
            }
            if (f) { layer.appendChild(f); flies[k] = f; }
        });
        function spaceOf(g) { return spaces[g.getAttribute('data-head')]; }

        function isRail() { return sb.classList.contains('collapsed'); }
        function rowOf(g) { return g.querySelector('.it-grow'); }
        function cardOf(g) { return cards[g.getAttribute('data-head')]; }
        function flyOf(g) { return flies[g.getAttribute('data-head')]; }

        // The live page's line to its head: from the current page's row up the spine to the portrait.
        function measureLive() {
            groups.forEach(function (g) {
                g.classList.remove('is-live');
                var acc = g.querySelector('.it-acc');
                var pg = g.querySelector('.it-pg[aria-current="page"]');
                var pt = g.querySelector('.it-grow .it-pt');
                if (!acc || acc.hidden || !pg || !pt || isRail()) return;
                var gr = g.getBoundingClientRect(), pr = pt.getBoundingClientRect(), r = pg.getBoundingClientRect();
                // The line feeds the expanded card when there is one (its bottom edge), else the portrait's centre.
                var sp = spaceOf(g);
                var top = (sp && !sp.hidden)
                    ? sp.getBoundingClientRect().bottom - gr.top
                    : pr.top + pr.height / 2 - gr.top;
                var end = r.top + r.height / 2 - gr.top;
                if (end <= top) return;
                g.style.setProperty('--it-live-top', Math.round(top) + 'px');
                g.style.setProperty('--it-live', Math.round(end - top) + 'px');
                g.classList.add('is-live');
            });
        }
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
            groups.forEach(function (g) {
                var c = cardOf(g), sp = spaceOf(g);
                if (c) c.classList.remove('is-open');
                if (sp) sp.hidden = true;
                g.classList.remove('has-card');
            });
            measureLive();
            if (typeof placeLink === 'function') placeLink();
        }
        function closeFlies() {
            clearTimeout(flyTimer);
            groups.forEach(function (g) { var f = flyOf(g); if (f) f.classList.remove('is-open'); });
        }

        // The card is in flow now (nothing to place); kept as a hook for callers and for a future floating variant.
        // Centred on the spine (28 px in from the sidebar's edge), level with its spacer in the sidebar.
        function placeCard(g) {
            var c = cardOf(g), sp = spaceOf(g);
            if (!c || !sp || isRail() || !c.classList.contains('is-open')) return;
            var w = c.offsetWidth || 200, h = c.offsetHeight || 200;
            sp.style.height = h + 'px';
            var sr = sp.getBoundingClientRect(), sb0 = sb.getBoundingClientRect();
            c.style.top = Math.round(sr.top) + 'px';
            c.style.left = Math.round(sb0.right - 28 - w / 2) + 'px';
            c.setAttribute('data-placed', '1');
        }
        function replaceOpenCards() {
            groups.forEach(function (g) { var c = cardOf(g); if (c && c.classList.contains('is-open')) placeCard(g); });
            placeLink();
        }

        // The job strip's rail line reaches the head's card (Tim, 2026-11-10): out of the card's right edge at its
        // middle, a short step, a right angle up or down to the rail's height, then into the strip's rail.
        var link = document.createElement('div'), stub = document.createElement('div');
        link.className = 'it-job-link'; stub.className = 'it-job-link-stub';
        link.hidden = stub.hidden = true;
        layer.appendChild(link); layer.appendChild(stub);
        function placeLink() {
            var rail = document.querySelector('.it-job:not(.is-done) .it-job-rail');
            var strip = rail ? rail.closest('.it-job') : null;
            var card = strip ? cards[strip.getAttribute('data-head')] : null;
            if (!rail || !card || !card.classList.contains('is-open') || isRail()) {
                link.hidden = stub.hidden = true;
                return;
            }
            var cr = card.getBoundingClientRect(), rr = rail.getBoundingClientRect();
            // The line runs at the height of the table's lead row into the strip's edge, where a hairline carries it
            // on to the top of the spine; the confidence triangle sits ON the line between the card and the strip.
            var tr = rr;   /* the rail's own middle is the line */
            var cy = cr.top + cr.height / 2, ry = tr.top + tr.height / 2, x0 = cr.right + 16;
            var conf = null;   /* the triangle sits in flow on the rail now */
            if (rr.bottom < 0 || rr.top > window.innerHeight) { link.hidden = stub.hidden = true; if (conf) conf.hidden = true; return; }
            var endX = strip.getBoundingClientRect().left;
            if (conf) {
                var gap = endX - cr.right;
                conf.hidden = gap < 44;
                conf.style.left = Math.round(cr.right + gap / 2) + 'px';
                conf.style.top = Math.round(ry) + 'px';
            }
            // The card overlaps the strip's edge (the usual case): the rail simply emerges from under the card,
            // a straight navy line at the rail's height from the card's right edge to the first disc.
            if (ry >= cr.top + 8 && ry <= cr.bottom - 8 && cr.right < endX - 4) {
                if (conf) conf.hidden = (endX - cr.right) < 44;
                stub.hidden = true; link.hidden = false;
                link.classList.add('is-flat'); link.classList.remove('is-up');
                link.style.left = Math.round(cr.right) + 'px'; link.style.width = Math.round(endX - cr.right) + 'px';
                link.style.top = Math.round(ry) + 'px'; link.style.height = '0px';
                return;
            }
            link.classList.remove('is-flat');
            if (endX <= x0 + 8) { link.hidden = stub.hidden = true; if (conf) conf.hidden = true; return; }
            if (conf) { conf.style.left = Math.round(x0 + (endX - x0) / 2) + 'px'; conf.style.top = Math.round(ry) + 'px'; conf.hidden = (endX - x0) < 44; }
            stub.hidden = link.hidden = false;
            stub.style.left = Math.round(cr.right) + 'px'; stub.style.top = Math.round(cy) + 'px'; stub.style.width = '16px';
            link.style.left = Math.round(x0) + 'px'; link.style.width = Math.round(endX - x0) + 'px';
            link.style.top = Math.round(Math.min(cy, ry)) + 'px'; link.style.height = Math.max(0, Math.round(Math.abs(cy - ry))) + 'px';
            link.classList.toggle('is-up', ry < cy);
        }
        window.addEventListener('scroll', function () { replaceOpenCards(); }, { passive: true });
        function openCard(g) {
            closeCards();
            var c = cardOf(g), sp = spaceOf(g);
            if (!c || !sp) return;
            sp.hidden = false;
            c.classList.add('is-open');
            g.classList.add('has-card');
            placeCard(g);
            measureLive();
        }

        function select(g) {
            var ws = g.getAttribute('data-workspace') || '';
            if (g.classList.contains('is-sel')) {
                // Already selected: the second click only shows or hides the card.
                var card = cardOf(g);
                if (card && card.classList.contains('is-open')) closeCards(); else openCard(g);
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
            setTimeout(function () { replaceOpenCards(); measureLive(); }, 220);
            setTimeout(function () { replaceOpenCards(); measureLive(); }, 600);
            if (ws !== '' && window.location.pathname !== ws) window.location.href = ws;
        }

        // Rail: a head's pages open in a flyout beside its button.
        function placeFly(g) {
            var fly = flyOf(g);
            if (!fly) return;
            var r = rowOf(g).getBoundingClientRect();
            var top = Math.max(r.top - 8, 8);
            fly.style.left = '76px';
            fly.style.top = Math.round(top) + 'px';
            var h = fly.offsetHeight;
            if (top + h > window.innerHeight - 8) fly.style.top = Math.round(Math.max(8, window.innerHeight - 8 - h)) + 'px';
        }
        function openFly(g) {
            clearTimeout(flyTimer);
            groups.forEach(function (o) { var f = flyOf(o); if (o !== g && f) f.classList.remove('is-open'); });
            var fly = flyOf(g);
            if (!fly) return;
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
            function leave() {
                if (!isRail()) return;
                clearTimeout(flyTimer);
                flyTimer = setTimeout(function () { var f = flyOf(g); if (f) f.classList.remove('is-open'); }, 120);
            }
            function stillIn(el) { var f = flyOf(g); return g.contains(el) || (f && f.contains(el)); }
            g.addEventListener('mouseleave', leave);
            g.addEventListener('focusin', function () { if (isRail()) openFly(g); });
            g.addEventListener('focusout', function (e) {
                if (isRail() && !stillIn(e.relatedTarget)) { var f = flyOf(g); if (f) f.classList.remove('is-open'); }
            });
            var fly = flyOf(g);
            if (fly) {
                // The flyout lives on <body> now, so moving the pointer onto it must not count as leaving the row.
                fly.addEventListener('mouseenter', function () { clearTimeout(flyTimer); });
                fly.addEventListener('mouseleave', leave);
                fly.addEventListener('focusout', function (e) {
                    if (isRail() && !stillIn(e.relatedTarget)) fly.classList.remove('is-open');
                });
                // Keyboard: the flyout is no longer next in tab order, so ArrowRight (or ArrowDown) steps into it
                // and Escape inside it comes back to the row.
                row.addEventListener('keydown', function (e) {
                    if (!isRail() || (e.key !== 'ArrowRight' && e.key !== 'ArrowDown')) return;
                    e.preventDefault();
                    openFly(g);
                    var first = fly.querySelector('.it-pg');
                    if (first) first.focus();
                });
                fly.addEventListener('keydown', function (e) {
                    if (e.key !== 'Escape') return;
                    e.stopPropagation();
                    fly.classList.remove('is-open');
                    row.focus();
                });
            }
        });

        // Escape, a click elsewhere or on the lockup closes the card and any flyout.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            closeCards();
            closeFlies();
        });
        document.addEventListener('click', function (e) {
            if ((!sb.contains(e.target) && !layer.contains(e.target)) || e.target.closest('.it-mark')) { closeCards(); closeFlies(); }
        });
        window.addEventListener('resize', function () { replaceOpenCards(); closeFlies(); measureLive(); });
        // A group finished folding or unfolding: every row below it has moved, so the open card follows.
        sb.addEventListener('transitionend', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('it-acc')) { replaceOpenCards(); measureLive(); }
        });
        if (scroller) scroller.addEventListener('scroll', replaceOpenCards, { passive: true });

        // The rail: the sidebar's own button, the AppStack hamburger, and the remembered choice.
        function saveRail() {
            try { window.localStorage.setItem(RAIL_KEY, isRail() ? '1' : '0'); } catch (e) { /* private mode */ }
        }
        function syncRail() {
            var rail = isRail();
            document.body.classList.toggle('it-rail', rail);
            measureLive();
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
        measureLive();
        window.addEventListener('load', measureLive);
        // Anything that moves a row later (a late webfont, a wrapped chip) moves the live line with it.
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function () { measureLive(); replaceOpenCards(); });
            ro.observe(sb);
            var nv = sb.querySelector('.it-nv');
            if (nv) ro.observe(nv);
        }

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
