/**
 * The job strip (Ittai desktop shell): Penny's jobs across the top of her pages — the duplicate receipt and the
 * pending e-Transfer to record. Markup comes from public/crm/includes/ittai-job.php; the jobs are in its JSON block.
 * Buttons carry the exact requests the web cards send: the duplicate job posts JSON to /crm/api/bookkeeper.php
 * (mode + params + csrf_token, as bookkeeper-card.js does; Merge shows Undo for 6 s), the e-Transfer job posts the
 * Invoices card's own form (action=record, notification_id, invoice_numbers[], amounts[], csrf_token) to
 * /crm/api/etransfer-confirm.php (job.api + job.transport = 'form'). Nothing is decided here.
 * Text goes in with textContent (no HTML strings); the next job is cloned from the <template> of its kind.
 */
(function () {
    'use strict';

    var API = '/crm/api/bookkeeper.php';
    var UNDO_MS = 6000;
    var root = document.querySelector('.it-job');
    if (!root) return;

    var tpls = root.querySelectorAll('.it-job-tpl');
    function tplFor(kind) {
        for (var i = 0; i < tpls.length; i++) if (tpls[i].getAttribute('data-kind') === kind) return tpls[i];
        return tpls[0];
    }
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
    /** The card's form, field for field: arrays go as key[] (invoice_numbers[], amounts[]), the token as csrf_token. */
    function formBody(body) {
        var fd = new FormData();
        Object.keys(body).forEach(function (k) {
            var v = body[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', String(x)); });
            else if (v != null) fd.append(k, String(v));
        });
        fd.append('csrf_token', token());
        return fd;
    }
    /** POST the job's request the way its card does: JSON to bookkeeper.php, or a form to the job's own endpoint. */
    function post(body, job, retried) {
        var url = (job && job.api) || API;
        var asForm = !!(job && job.transport === 'form');
        var init = { method: 'POST', credentials: 'same-origin' };
        if (asForm) {
            init.body = formBody(body);
        } else {
            var payload = {};
            Object.keys(body).forEach(function (k) { payload[k] = body[k]; });
            payload.csrf_token = token();
            init.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': token() };
            init.body = JSON.stringify(payload);
        }
        return fetch(url, init).then(function (r) {
            if (r.status === 403 && !retried) {
                return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (x) { return x.json(); })
                    .then(function (d) { if (d && d.token) window.MW_CSRF_TOKEN = d.token; return post(body, job, true); });
            }
            return r.json();
        });
    }

    var coolTimer = null;
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

    /** One side of the e-Transfer job: its name, who, when. */
    function side(node, s) {
        if (!node) return;
        node.textContent = '';
        node.appendChild(el('b', '', (s && s.label) || ''));
        ['who', 'when'].forEach(function (k) { if (s && s[k]) node.appendChild(el('span', '', s[k])); });
    }
    /** Draw queue[0]: clone the skeleton of its kind, fill it, swap it in for the old rail. */
    function render() {
        var job = queue[0];
        var kind = job.kind === 'etransfer' ? 'etransfer' : 'duplicate';
        var oldRail = root.querySelector('.it-job-rail');
        var rail = tplFor(kind).content.firstElementChild.cloneNode(true);
        root.setAttribute('data-kind', kind);
        var cols = rail.querySelector('.it-job-cols');
        (job.columns || []).forEach(function (c) {
            var li = el('li', 'it-job-col');
            var top = el('span', 'it-job-top', c.top); top.setAttribute('title', c.top || ''); li.appendChild(top);
            var d = el('span', 'it-job-disc it-job-disc-' + c.state);
            d.setAttribute('role', 'img');
            d.setAttribute('aria-label', c.label + ': ' + (WORD[c.state] || 'info'));
            d.appendChild(icon(ICON[c.state] || 'info'));
            li.appendChild(d);
            var bot = el('span', 'it-job-bot', c.bottom); bot.setAttribute('title', c.bottom || ''); li.appendChild(bot);
            cols.appendChild(li);
        });
        var kl = rail.querySelector('.it-job-keeplbl'), cl = rail.querySelector('.it-job-copylbl');
        if (kl) kl.textContent = (job.keep && job.keep.label) || '';
        if (cl) cl.textContent = (job.copy && job.copy.label) || '';
        var imgs = rail.querySelector('.it-job-imgs');
        if (imgs) {
            var viewBtn = imgs.querySelector('.it-job-view');
            imgs.textContent = '';
            imgs.appendChild(figure(job.keep, true));
            if (viewBtn) imgs.appendChild(viewBtn);
            imgs.appendChild(figure(job.copy, false));
        }
        // the e-Transfer job: the fraction, the match's name, the two sides, the Bank anchor
        var frac = rail.querySelector('.it-job-frac');
        if (frac) {
            var pm = (job.pieces && job.pieces.matched) || 0, pt = (job.pieces && job.pieces.total) || 3;
            var fn = frac.querySelector('.it-job-frac-n'), fdn = frac.querySelector('.it-job-frac-d');
            if (fn) fn.textContent = String(pm);
            if (fdn) fdn.textContent = String(pt);
            frac.setAttribute('aria-label', pm + ' of ' + pt + ' pieces in hand');
        }
        var lbl = rail.querySelector('.it-job-lbl');
        if (lbl) {   // "1 invoice + payment": the first word group above the rail, the second below it
            lbl.textContent = '';
            String(job.label || '').split(' + ').forEach(function (w) { var s = document.createElement('span'); s.textContent = w; lbl.appendChild(s); });
        }
        side(rail.querySelector('.it-job-side-top'), job.left);
        side(rail.querySelector('.it-job-side-bot'), job.right);
        var start = rail.querySelector('.it-job-anchor-start');
        if (start && job.start) {
            start.setAttribute('href', job.start.href);
            var sl = start.querySelectorAll('span');
            if (sl.length) sl[sl.length - 1].textContent = job.start.label;
        }
        var conf = rail.querySelector('.it-job-conf');
        if (conf) {
            var sure = ['forming', 'held', 'earned'].indexOf(job.sure) >= 0 ? job.sure : 'forming';
            conf.classList.remove('is-forming', 'is-held', 'is-earned');
            conf.classList.add('is-' + sure);
            var use = conf.querySelector('use');
            if (use) use.setAttribute('href', '#it-st-approval' + (sure === 'earned' ? '' : '-' + sure));
            var why = kind === 'etransfer'
                ? (job.confidence || (sure === 'earned' ? 'The invoice number is on the transfer.' : 'Her reading of this transfer — check it.'))
                : (sure === 'forming' ? 'First time with this vendor.' : (job.confidence || 'Her record with this vendor is short.'));
            var lbl = 'Penny proposes; your approval decides. ' + why;
            conf.setAttribute('aria-label', lbl);
            conf.setAttribute('title', lbl);
        }
        fillButton(rail.querySelector('.it-btn-fill'), job.primary);
        fillButton(rail.querySelector('.it-btn-line'), job.secondary);
        var a = rail.querySelector('.it-job-anchor:not(.it-job-anchor-start)');
        if (a && job.anchor) {
            a.setAttribute('href', job.anchor.href);
            var last = a.querySelectorAll('span');
            if (last.length) last[last.length - 1].textContent = job.anchor.label;
        }
        if (!reduced) rail.classList.add('is-enter');
        oldRail.replaceWith(rail);

        var tt = root.querySelector('.it-job-tt'), cc = root.querySelector('.it-job-c');
        if (tt) tt.textContent = job.title;
        if (cc) cc.textContent = job.confidence || '';
        var total = queue.length;
        var n = root.querySelector('.it-job-n');
        n.textContent = '1 of ' + total;
        var skip = foot.querySelector('.it-job-skip');
        if (skip) skip.hidden = total < 2;
        // Never land focus on a money button: a held Enter or a double tap would merge a pair the owner
        // has not looked at (review, 2026-11-10). Focus the title; the buttons wake after a short pause.
        var tt = root.querySelector('.it-job-tt');
        if (tt) { tt.setAttribute('tabindex', '-1'); tt.focus({ preventScroll: true }); }
        setBusy(true);
        clearTimeout(coolTimer);
        coolTimer = setTimeout(function () { setBusy(false); }, 600);
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

    /** "Not this invoice": the card below (same page) has the form to pick another; elsewhere, go to it. */
    function pick(req) {
        var row = req.notification_id ? document.querySelector('#etransfers .mw-et-row[data-id="' + String(req.notification_id).replace(/[^0-9]/g, '') + '"]') : null;
        if (row) {
            row.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
            var inp = row.querySelector('.mw-et-inv');
            if (inp) { try { inp.focus({ preventScroll: true }); inp.select(); } catch (e) { /* no focus: the scroll is enough */ } }
            say('Pick the invoice on the card below, then record it there.', false);
            return;
        }
        if (req.href && /^\/crm\//.test(req.href)) window.location.assign(req.href);
    }

    /**
     * The merge's resolution (Tim's pick of five, 2026-11-10: "the ink line"): the frame's words fade, then ONE navy
     * stroke is drawn along the rail from the Duplicate disc to the Ledger, each disc dissolving as the ink reaches it
     * and the frame erased behind it; the Ledger disc fills, holds, and the ink lightens to the hairline — the split
     * has become one line again. Resolves when done; at once under prefers-reduced-motion or when anything is missing.
     */
    function playInk() {
        var rail = root.querySelector('.it-job-rail');
        var a = rail && rail.querySelector('.it-job-anchor-start .it-ad');
        var z = rail && rail.querySelector('.it-job-anchor:not(.it-job-anchor-start) .it-ad');
        if (reduced || !rail || !a || !z) return Promise.resolve();
        var rr = rail.getBoundingClientRect(), ar = a.getBoundingClientRect(), zr = z.getBoundingClientRect();
        var x0 = ar.left + ar.width / 2, x1 = zr.left + zr.width / 2;
        if (!(x1 > x0 + 40)) return Promise.resolve();
        var FADE = 250, DRAW = 600, HOLD = 300, LIGHT = 400;
        var ink = el('span', 'it-job-ink');
        ink.style.left = Math.round(x0 - rr.left + rail.scrollLeft) + 'px';
        ink.style.width = Math.round(x1 - x0) + 'px';
        ink.style.transform = 'scaleX(0)';
        rail.appendChild(ink);
        rail.classList.add('is-inking');
        var timers = [];
        // every knot on the line goes as the ink reaches it: the discs, the View eye, the triangle
        Array.prototype.forEach.call(rail.querySelectorAll('.it-job-disc, .it-job-view .it-ad, .it-job-conf'), function (d) {
            var r = d.getBoundingClientRect(), cx = r.left + r.width / 2;
            var at = FADE + DRAW * Math.min(1, Math.max(0, (cx - x0) / (x1 - x0)));
            timers.push(setTimeout(function () { d.classList.add('is-gone'); }, at));
        });
        return new Promise(function (resolve) {
            timers.push(setTimeout(function () {
                rail.classList.add('is-erasing');
                ink.style.transition = 'transform ' + DRAW + 'ms cubic-bezier(.3,.7,.3,1)';
                ink.style.transform = 'scaleX(1)';
            }, FADE));
            timers.push(setTimeout(function () { z.classList.add('is-lit'); }, FADE + DRAW));
            timers.push(setTimeout(function () { ink.classList.add('is-light'); z.classList.remove('is-lit'); }, FADE + DRAW + HOLD));
            timers.push(setTimeout(function () { resolve(); }, FADE + DRAW + HOLD + LIGHT));
        });
    }

    function settle(btn) {
        if (busy || !queue.length) return;
        var req;
        try { req = JSON.parse(btn.getAttribute('data-request') || ''); } catch (e) { return; }
        var job = queue[0];
        if (req.mode === 'pick') { pick(req); return; }
        var isMerge = req.mode === 'dupe_merge';
        var isRecord = req.action === 'record';
        clearTimeout(undoTimer);
        setBusy(true);
        say('', false);
        post(req, job).then(function (d) {
            if (!(d && d.ok)) { setBusy(false); say((d && (d.message || d.error)) || 'Could not save — try again', false); return; }
            var text = d.message || (isMerge ? 'Merged' : (isRecord ? 'Recorded.' : 'Got it — two purchases.'));
            if (isRecord && d.fully_allocated === false) {
                text = 'Part recorded. ' + (typeof d.remaining === 'number' ? '$' + d.remaining.toFixed(2) + ' is still waiting for an invoice — see the card.' : 'The rest is still waiting for an invoice — see the card.');
            }
            var canUndo = isMerge && !!(job.undo && job.undo.requests && job.undo.requests.length);
            // A merge or a recorded payment resolves on screen first (the ink line: the split becomes one line into
            // the Ledger), then the next job or the quiet end.
            return ((isMerge || isRecord) ? playInk() : Promise.resolve()).then(function () {
                setBusy(false);
                queue.shift();
                lastMerged = canUndo ? job : null;
                // A recorded payment changes the invoice list and the card below: reload the Invoices page so it shows
                // (as the card does, 2026-10-23); the next job is drawn first so the strip never goes blank.
                var reload = isRecord && !!document.getElementById('etransfers');
                if (!queue.length) { finish(text, canUndo); }
                else { next(); say(text, canUndo); }
                if (reload) { setBusy(true); setTimeout(function () { window.location.reload(); }, 1400); return; }
                if (canUndo) {
                    undoTimer = setTimeout(function () { lastMerged = null; say(text, false); }, UNDO_MS);
                }
            });
        }).catch(function () { setBusy(false); say('Could not save — try again', false); });
    }

    /** One unmerge per copy, in turn; every one must come back ok before the job returns to the front. */
    function undo() {
        var job = lastMerged;
        if (busy || !job) return;
        clearTimeout(undoTimer);
        setBusy(true);
        var bad = null, done = 0;
        job.undo.requests.reduce(function (p, r) {
            return p.then(function () {
                if (bad) return null;
                return post(r, job).then(function (d) { if (d && d.ok) done++; else bad = d || {}; });
            });
        }, Promise.resolve()).then(function () {
            setBusy(false);
            if (bad) {
                // A copy that did come back is no longer merged: forget its request so a retry does not resend it,
                // and keep the strip until the owner has read the message.
                job.undo.requests = job.undo.requests.slice(done);
                say(bad.message || bad.error || 'Could not undo — try again', job.undo.requests.length > 0);
                if (!job.undo.requests.length) lastMerged = null;
                undoTimer = setTimeout(function () { if (root.classList.contains('is-done') && root.parentNode) root.parentNode.removeChild(root); }, UNDO_MS);
                return;
            }
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

    /** Both receipts large, side by side, for a visual check (Tim, 2026-11-10). */
    var dlg = root.querySelector('.it-job-dlg');
    function view() {
        var job = queue[0];
        if (!dlg || !job || typeof dlg.showModal !== 'function') return;
        var figs = dlg.querySelectorAll('.it-job-dlg-fig');
        [job.keep, job.copy].forEach(function (side, i) {
            var f = figs[i]; if (!f) return;
            var img = f.querySelector('img'), cap = f.querySelector('figcaption');
            if (side && side.image) { img.setAttribute('src', side.image); img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
            cap.textContent = ((side && side.label) || '') + (side && side.who ? ' · ' + side.who : '') + (side && !side.image ? ' · no photo' : '');
        });
        dlg.showModal();
    }

    root.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        if (t.closest('.it-job-view')) { view(); return; }
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
