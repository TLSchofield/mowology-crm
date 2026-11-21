/**
 * ittai-soft.js — soft navigation for the Ittai desktop shell (opt-in: it_soft cookie, set with ?it_soft=1).
 *
 * A click on a page in the safe list below does not reload the document. The next page is fetched (ahead of the
 * click, on hover or press), and inside one view transition this swaps only <main class="content">, the title and
 * the body class, re-runs that page's own scripts, and brings the sidebar to the new page's state through
 * ItShell.sidebarSync(). The sidebar element is never rebuilt, so nothing in it moves or blinks.
 *
 * Anything it is not sure about is a normal page load: a page outside the list, a form post, a download, a new tab,
 * a phone-sized screen, a login redirect, a page that fails while it starts. A page that throws while its scripts
 * start is remembered for the session and loads normally from then on.
 *
 * Loaded in <head> without defer on purpose: it must see the page scripts' listeners and timers being added so a
 * swap can remove them (the old page's document/window listeners and intervals) instead of piling them up.
 */
(function () {
    'use strict';
    if (!window.navigation || !document.startViewTransition || !window.fetch || !window.DOMParser) return;

    // The pages that swap without a reload. Add a page here only once tools/soft-nav-check.js passes for it.
    var SAFE = [
        /^\/crm\/dashboard_appstack\.php$/,
        /^\/crm\/kaname_appstack\.php$/,
        /^\/crm\/quotes_appstack\.php$/,
        /^\/crm\/quotes\/$/,
        /^\/crm\/jobs\/$/,
        /^\/crm\/invoices\/$/
    ];
    var HARD_KEY = 'it-soft-hard';
    var DESKTOP = window.matchMedia ? window.matchMedia('(min-width: 992px)') : { matches: true };

    function norm(path) { return path.replace(/\/index\.php$/, '/'); }
    function isSafe(u) {
        if (u.origin !== location.origin) return false;
        if (/(^|&)it_(design|soft|brand)=/.test(u.search.slice(1))) return false;
        var p = norm(u.pathname);
        return SAFE.some(function (re) { return re.test(p); });
    }
    function hardList() { try { return JSON.parse(sessionStorage.getItem(HARD_KEY) || '[]'); } catch (e) { return []; } }
    function markHard(u) { try { var l = hardList(); l.push(norm(u.pathname)); sessionStorage.setItem(HARD_KEY, JSON.stringify(l)); } catch (e) { /* private mode */ } }
    function isHard(u) { return hardList().indexOf(norm(u.pathname)) !== -1; }

    // ── What the current page's scripts added, so the next swap can take it away ─────────────────────────────────
    var owner = newOwner();          // the page on screen
    var ctx = null;                  // the owner while its scripts (and its load handlers) are running
    var queue = null;                // load/DOMContentLoaded handlers held back during a swap, run once the scripts are in
    function newOwner() { return { listeners: [], timers: [], head: [] }; }
    function pageScript() {
        var cs = document.currentScript;
        return !!(cs && cs.closest && cs.closest('main.content'));
    }
    function owned() { return ctx || (pageScript() ? owner : null); }

    var nativeAdd = EventTarget.prototype.addEventListener;
    EventTarget.prototype.addEventListener = function (type, fn, opts) {
        var o = (this === window || this === document) && typeof fn === 'function' ? owned() : null;
        if (!o) return nativeAdd.call(this, type, fn, opts);
        var target = this;
        if (queue && (type === 'DOMContentLoaded' || (type === 'load' && target === window))) {
            queue.push({ fn: fn, target: target, type: type, o: o });   // the page already loaded: run it after the swap instead
            return;
        }
        var run = fn;
        if (type === 'DOMContentLoaded' || type === 'load') {
            run = function () { var was = ctx; ctx = o; try { return fn.apply(this, arguments); } finally { ctx = was; } };
        }
        o.listeners.push({ target: target, type: type, fn: run, opts: opts });
        return nativeAdd.call(target, type, run, opts);
    };
    var nativeInterval = window.setInterval;
    window.setInterval = function () {
        var id = nativeInterval.apply(window, arguments), o = owned();
        if (o) o.timers.push(id);
        return id;
    };
    function dispose(o) {
        o.listeners.forEach(function (l) { l.target.removeEventListener(l.type, l.fn, l.opts); });
        o.timers.forEach(function (id) { clearInterval(id); });
        o.head.forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });
        o.listeners = []; o.timers = []; o.head = [];
    }

    // ── Fetching ahead ───────────────────────────────────────────────────────────────────────────────────────────
    var ahead = {};
    function fetchPage(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'soft-nav' } }).then(function (r) {
            var ru = new URL(r.url);
            if (!r.ok || ru.origin !== location.origin || norm(ru.pathname) !== norm(new URL(url).pathname)) throw new Error('hard');   // a login or other redirect
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('text/html') === -1) throw new Error('hard');
            return r.text();
        });
    }
    function prefetch(url) {
        var hit = ahead[url];
        if (hit && Date.now() - hit.t < 8000) return hit.p;
        var p = fetchPage(url);
        p.catch(function () { /* a failed read ahead is just a normal load later */ });
        ahead[url] = { t: Date.now(), p: p };
        return p;
    }
    function takePage(url) {
        var hit = ahead[url];
        delete ahead[url];
        return hit && Date.now() - hit.t < 8000 ? hit.p : fetchPage(url);
    }
    ['pointerenter', 'pointerdown', 'focusin'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a || a.target || !DESKTOP.matches) return;
            var u = new URL(a.href, location.href);
            if (isSafe(u) && isSafe(new URL(location.href)) && !isHard(u)) prefetch(u.href);
        }, { capture: true, passive: true });
    });

    // ── The swap ─────────────────────────────────────────────────────────────────────────────────────────────────
    var serverBody = [];   // the classes the server gave this page (read once the body exists)
    // Classes the shell and the page scripts add while the page runs (the Kaname seat, the rail, an open modal): not the server's, never removed here.
    var RUNTIME = /^(it-kan-seated|it-rail|modal-open|it-soft-wait|sidebar-.*)$/;
    function readBody() { serverBody = (document.body.className || '').split(/\s+/).filter(function (c) { return c && !RUNTIME.test(c); }); }
    nativeAdd.call(document, 'DOMContentLoaded', readBody);
    function headKey(n) {
        if (n.tagName === 'LINK') return 'L' + n.getAttribute('href');
        if (n.tagName === 'SCRIPT') return n.src ? 'S' + n.getAttribute('src') : 'I' + n.textContent;
        return 'T' + n.textContent;
    }
    function syncHead(doc, o) {
        var have = {};
        [].forEach.call(document.head.querySelectorAll('link[rel="stylesheet"], style, script'), function (n) { have[headKey(n)] = true; });
        var loads = [];
        [].forEach.call(doc.head.querySelectorAll('script'), function (n) {
            if (!have[headKey(n)]) throw new Error('hard');   // a script that only this page loads in its head runs on a normal load, not here
        });
        [].forEach.call(doc.head.querySelectorAll('link[rel="stylesheet"], style'), function (n) {
            if (have[headKey(n)]) return;
            var c = document.importNode(n, true);
            o.head.push(c);
            if (c.tagName === 'LINK') loads.push(waitFor(c));
            document.head.appendChild(c);
        });
        return Promise.all(loads);
    }
    function waitFor(el) {
        return new Promise(function (res) { el.addEventListener('load', res); el.addEventListener('error', res); setTimeout(res, 4000); });
    }
    function fresh(old) {
        var s = document.createElement('script');
        [].forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });
        s.text = old.textContent;
        s.async = false;
        return s;
    }
    function swapMain(doc, o, errors) {
        // Kaname's line is moved up into the top bar by the shell; the old page's copy goes with the old page.
        [].forEach.call(document.querySelectorAll('.navbar .it-kan-line'), function (l) { l.parentNode.removeChild(l); });
        var cur = document.querySelector('main.content'), next = doc.querySelector('main.content');
        var frag = document.createDocumentFragment(), loads = [];
        [].forEach.call(next.childNodes, function (n) { frag.appendChild(document.importNode(n, true)); });
        [].slice.call(frag.querySelectorAll('script')).forEach(function (old) {
            var s = fresh(old);
            if (s.src) loads.push(waitFor(s));
            old.parentNode.replaceChild(s, old);
        });
        while (cur.firstChild) cur.removeChild(cur.firstChild);
        function onerr(e) { errors.push(e.message || 'error'); }
        nativeAdd.call(window, 'error', onerr);
        var q = queue = [];
        ctx = o;
        try { cur.appendChild(frag); } catch (e) { errors.push(String(e)); }
        ctx = null;
        return Promise.all(loads).then(function () {
            var held = q; if (queue === q) queue = null;
            held.forEach(function (h) {
                var was = ctx; ctx = h.o;
                try { h.fn.call(h.target, new Event(h.type)); } catch (e) { errors.push(String(e)); }
                ctx = was;
            });
            window.removeEventListener('error', onerr);
        });
    }
    function apply(doc, url) {
        var next = newOwner(), errors = [];
        return syncHead(doc, next).then(function () {
            dispose(owner);   // only now, with the new page's styles in, is the old page's work taken away
            owner = next;
            // The page's own scripts may read the title, the body class and the token, so these are set before they start.
            document.title = doc.title;
            var want = (doc.body.className || '').split(/\s+/).filter(function (c) { return c && !RUNTIME.test(c); });
            serverBody.forEach(function (c) { if (want.indexOf(c) === -1) document.body.classList.remove(c); });
            want.forEach(function (c) { document.body.classList.add(c); });
            serverBody = want;
            var m = /window\.MW_CSRF_TOKEN\s*=\s*(['"])([^'"]*)\1/.exec(doc.head.innerHTML);
            if (m) window.MW_CSRF_TOKEN = m[2];
            return swapMain(doc, next, errors);
        }).then(function () {
            if (window.ItShell && window.ItShell.sidebarSync) window.ItShell.sidebarSync(doc);
            if (window.ItShell && window.ItShell.measureFw) window.ItShell.measureFw();
            if (!document.querySelector('.it-kan-line')) document.body.classList.remove('it-kan-seated');   // a page with no Kaname line (her own page) has nothing seated
            try { if (window.feather) window.feather.replace(); } catch (e) { /* icons are decoration */ }
            document.dispatchEvent(new CustomEvent('mw:soft-nav', { detail: { url: url } }));
            if (errors.length) throw new Error('page-script: ' + errors[0]);
            // An error in the next two seconds (a timer or a promise in the page's startup) cannot undo the swap,
            // but it marks the page so the next visit is a normal load.
            var u = new URL(url), watch = function () { markHard(u); };
            nativeAdd.call(window, 'error', watch);
            setTimeout(function () { window.removeEventListener('error', watch); }, 2000);
        });
    }
    // One swap at a time: a second click waits for the first to finish, so the held-handler queue and the page owner never interleave.
    var lock = Promise.resolve();
    function exclusive(fn) { var p = lock.then(fn); lock = p.catch(function () { /* the caller handles it */ }); return p; }
    var root = document.documentElement;
    function named(t) { root.classList.add('it-vt'); t.finished.then(function () { root.classList.remove('it-vt'); }, function () { root.classList.remove('it-vt'); }); }
    // Normal page loads (the page changes by a real navigation): the names are on for the swap and the first frames of the new page.
    window.addEventListener('pageswap', function (e) { if (e.viewTransition) root.classList.add('it-vt'); });
    window.addEventListener('pagereveal', function (e) { if (e.viewTransition) named(e.viewTransition); });
    function run(fn) {
        root.classList.add('it-vt');
        var t = document.startViewTransition(fn);
        named(t);
        t.ready.catch(function () { /* skipped by a newer click: nothing to report */ });
        t.finished.catch(function () { /* same */ });
        return t.updateCallbackDone;
    }

    var bypass = false, seq = 0;
    function hardTo(url) { bypass = true; window.location.href = url; }

    navigation.addEventListener('navigate', function (e) {
        if (bypass || !e.canIntercept || e.hashChange || e.downloadRequest !== null || e.formData || e.navigationType === 'reload') return;
        if (!DESKTOP.matches || !document.body.classList.contains('it-shell')) return;
        var u = new URL(e.destination.url), here = new URL(location.href);
        if (!isSafe(u) || !isSafe(here) || isHard(u)) return;   // a normal load (the transition still keeps the sidebar still)
        e.intercept({
            scroll: 'manual', focusReset: 'manual',
            handler: function () {
                var mine = ++seq;   // a second click while this one is still loading wins; this one does nothing more
                document.body.classList.add('it-soft-wait');
                return takePage(u.href).then(function (html) {
                    if (mine !== seq || e.signal.aborted) throw new Error('superseded');
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    if (!doc.querySelector('main.content') || !doc.querySelector('.it-sidebar') || !doc.body.classList.contains('it-shell')) throw new Error('hard');
                    return run(function () { return exclusive(function () { if (mine !== seq || e.signal.aborted) throw new Error('superseded'); return apply(doc, u.href); }); });
                }).then(function () {
                    document.body.classList.remove('it-soft-wait');
                    if (e.navigationType === 'traverse') { try { e.scroll(); } catch (x) { window.scrollTo(0, 0); } } else window.scrollTo(0, 0);
                }).catch(function (err) {
                    if (err && err.message === 'superseded') { if (mine === seq) document.body.classList.remove('it-soft-wait'); return; }
                    document.body.classList.remove('it-soft-wait');
                    if (err && /^page-script/.test(err.message)) markHard(u);
                    hardTo(u.href);
                });
            }
        });
    });
    window.ItSoft = { safe: SAFE, isSafe: isSafe, owner: function () { return owner; } };
})();
