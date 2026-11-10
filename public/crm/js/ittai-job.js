/**
 * The job strip (Ittai desktop shell): Penny's duplicate-receipt job across the top of her pages.
 * Markup comes from public/crm/includes/ittai-job.php; the jobs are in its JSON block. Buttons carry the exact
 * requests bookkeeper-card.js sends to /crm/api/bookkeeper.php (JSON body: mode + params + csrf_token), so Merge,
 * Different and Undo do what the Penny card does — nothing is decided here. Merge shows Undo for 6 s.
 * Text goes in with textContent (no HTML strings), the next job is cloned from the <template>.
 */
(function () {
    'use strict';

    var API = '/crm/api/bookkeeper.php';
    var UNDO_MS = 6000;
    var root = document.querySelector('.it-job');
    if (!root) return;

    var tpl = root.querySelector('.it-job-tpl');
    var dataEl = root.querySelector('.it-job-data');
    var msgBox = root.querySelector('.it-job-msg');
    var msgText = root.querySelector('.it-job-msgt');
    var undoBtn = root.querySelector('.it-job-undo');
    var foot = root.querySelector('.it-job-foot');
    var queue = [];
    var busy = false;
    var undoTimer = null;
    var lastMerged = null;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var ICON = { same: 'check', differs: 'neq', missing: 'x', info: 'info' };
    var WORD = { same: 'same', differs: 'differs', missing: 'missing', info: 'info' };
    var SVGNS = 'http://www.w3.org/2000/svg';

    try { queue = JSON.parse(dataEl ? dataEl.textContent : '[]') || []; } catch (e) { queue = []; }
    if (!queue.length) return;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function icon(id) {
        var s = document.createElementNS(SVGNS, 'svg');
        s.setAttribute('class', 'it-job-ic');
        s.setAttribute('width', '16');
        s.setAttribute('height', '16');
        s.setAttribute('viewBox', '0 0 24 24');
        s.setAttribute('fill', 'none');
        s.setAttribute('stroke', 'currentColor');
        s.setAttribute('stroke-width', '2');
        s.setAttribute('stroke-linecap', 'round');
        s.setAttribute('stroke-linejoin', 'round');
        s.setAttribute('aria-hidden', 'true');
        s.setAttribute('focusable', 'false');
        var u = document.createElementNS(SVGNS, 'use');
        u.setAttribute('href', '#it-w-' + id);
        s.appendChild(u);
        return s;
    }

    function token() { return window.MW_CSRF_TOKEN || ''; }
    function post(body, retried) {
        var payload = {};
        Object.keys(body).forEach(function (k) { payload[k] = body[k]; });
        payload.csrf_token = token();
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify(payload)
        }).then(function (r) {
            if (r.status === 403 && !retried) {
                return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (x) { return x.json(); })
                    .then(function (d) { if (d && d.token) window.MW_CSRF_TOKEN = d.token; return post(body, true); });
            }
            return r.json();
        });
    }

    function setBusy(on) {
        busy = on;
        Array.prototype.forEach.call(root.querySelectorAll('.it-btn, .it-job-skip'), function (b) { b.disabled = on; });
    }
    function say(text, withUndo) {
        msgText.textContent = text || '';
        undoBtn.hidden = !withUndo;
        msgBox.hidden = !text;
    }

    function figure(side, keep) {
        var f = el('figure', 'it-job-fig' + (keep ? ' is-keep' : ''));
        if (side && side.image) {
            var im = el('img');
            im.setAttribute('src', side.image);
            im.setAttribute('alt', '');
            im.setAttribute('loading', 'lazy');
            f.appendChild(im);
        } else {
            var ph = el('span', 'it-job-noimg');
            ph.setAttribute('aria-hidden', 'true');
            f.appendChild(ph);
        }
        f.appendChild(el('figcaption', '', ((side && side.label) || '') + (side && side.who ? ' · ' + side.who : '')));
        return f;
    }
    function fillButton(b, a) {
        if (!b || !a) return;
        b.setAttribute('data-request', JSON.stringify(a.request));
        while (b.querySelector('b')) b.removeChild(b.querySelector('b'));
        while (b.querySelector('span')) b.removeChild(b.querySelector('span'));
        b.appendChild(el('b', '', a.label));
        b.appendChild(el('span', '', a.sub));
    }

    /** Draw queue[0]: clone the skeleton, fill it, swap it in for the old rail. */
    function render() {
        var job = queue[0];
        var oldRail = root.querySelector('.it-job-rail');
        var rail = tpl.content.firstElementChild.cloneNode(true);
        var cols = rail.querySelector('.it-job-cols');
        (job.columns || []).forEach(function (c) {
            var li = el('li', 'it-job-col');
            li.appendChild(el('span', 'it-job-top', c.top));
            var d = el('span', 'it-job-disc it-job-disc-' + c.state);
            d.setAttribute('role', 'img');
            d.setAttribute('aria-label', c.label + ': ' + (WORD[c.state] || 'info'));
            d.appendChild(icon(ICON[c.state] || 'info'));
            li.appendChild(d);
            li.appendChild(el('span', 'it-job-bot', c.bottom));
            li.appendChild(el('span', 'it-job-lbl', c.label));
            cols.appendChild(li);
        });
        var imgs = rail.querySelector('.it-job-imgs');
        imgs.textContent = '';
        imgs.appendChild(figure(job.keep, true));
        imgs.appendChild(figure(job.copy, false));
        fillButton(rail.querySelector('.it-btn-fill'), job.primary);
        fillButton(rail.querySelector('.it-btn-line'), job.secondary);
        var a = rail.querySelector('.it-job-anchor');
        if (a && job.anchor) {
            a.setAttribute('href', job.anchor.href);
            var last = a.querySelectorAll('span');
            if (last.length) last[last.length - 1].textContent = job.anchor.label;
        }
        if (!reduced) rail.classList.add('is-enter');
        oldRail.replaceWith(rail);

        root.querySelector('.it-job-tt').textContent = job.title;
        root.querySelector('.it-job-c').textContent = job.confidence || '';
        var total = queue.length;
        var n = root.querySelector('.it-job-n');
        n.textContent = '1 of ' + total;
        var skip = foot.querySelector('.it-job-skip');
        if (skip) skip.hidden = total < 2;
        var first = rail.querySelector('.it-btn-fill');
        if (first) first.focus({ preventScroll: true });
    }

    /** Nothing left: say so politely, keep the Undo for its 6 s, then take the strip away. */
    function finish(text, withUndo) {
        root.classList.add('is-done');
        Array.prototype.forEach.call(root.querySelectorAll('.it-job-rail, .it-job-foot, .it-job-t'), function (n) { n.hidden = true; });
        say((text ? text + ' ' : '') + 'Penny has nothing else for you.', withUndo);
        clearTimeout(undoTimer);
        undoTimer = setTimeout(function () { if (root.parentNode) root.parentNode.removeChild(root); }, withUndo ? UNDO_MS : 3000);
    }
    function reopen() {
        root.classList.remove('is-done');
        Array.prototype.forEach.call(root.querySelectorAll('.it-job-rail, .it-job-foot, .it-job-t'), function (n) { n.hidden = false; });
    }

    function next() {
        if (!queue.length) { finish('', false); return; }
        render();
    }

    function settle(btn) {
        if (busy || !queue.length) return;
        var req;
        try { req = JSON.parse(btn.getAttribute('data-request') || ''); } catch (e) { return; }
        var job = queue[0];
        var isMerge = req.mode === 'dupe_merge';
        clearTimeout(undoTimer);
        setBusy(true);
        say('', false);
        post(req).then(function (d) {
            setBusy(false);
            if (!(d && d.ok)) { say((d && (d.message || d.error)) || 'Could not save — try again', false); return; }
            var text = d.message || (isMerge ? 'Merged' : 'Got it — two purchases.');
            var canUndo = isMerge && !!(job.undo && job.undo.requests && job.undo.requests.length);
            queue.shift();
            lastMerged = canUndo ? job : null;
            if (!queue.length) { finish(text, canUndo); return; }
            next();
            say(text, canUndo);
            if (canUndo) {
                undoTimer = setTimeout(function () { lastMerged = null; say(text, false); }, UNDO_MS);
            }
        }).catch(function () { setBusy(false); say('Could not save — try again', false); });
    }

    /** One unmerge per copy, in turn; every one must come back ok before the job returns to the front. */
    function undo() {
        var job = lastMerged;
        if (busy || !job) return;
        clearTimeout(undoTimer);
        setBusy(true);
        var bad = null;
        job.undo.requests.reduce(function (p, r) {
            return p.then(function () {
                if (bad) return null;
                return post(r).then(function (d) { if (!(d && d.ok)) bad = d || {}; });
            });
        }, Promise.resolve()).then(function () {
            setBusy(false);
            if (bad) { say(bad.message || bad.error || 'Could not undo — try again', false); return; }
            lastMerged = null;
            queue.unshift(job);
            reopen();
            render();
            say('Undone — back as they were.', false);
        }).catch(function () { setBusy(false); say('Could not undo — try again', false); });
    }

    function skip() {
        if (busy || queue.length < 2) return;
        queue.push(queue.shift());
        say('', false);
        render();
    }

    root.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        var b = t.closest('.it-btn[data-request]');
        if (b && root.contains(b)) { settle(b); return; }
        if (t.closest('.it-job-skip')) { skip(); return; }
        if (t.closest('.it-job-undo')) undo();
    });
    root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') skip();
    });

    window.ItJob = { next: next };
})();
