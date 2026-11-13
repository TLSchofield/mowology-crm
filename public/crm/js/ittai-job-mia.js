/**
 * Mia's job strip (Ittai desktop shell): her decisions on the Social Posts page, one at a time.
 * Markup comes from public/crm/includes/ittai-job-mia.php; the cards come from MiaJobAdapter, embedded in the page.
 * Each button either follows a link (editor, Facebook reconnect) or sends the request the page's own button sends
 * (posts.php approve / retry / schedule: JSON body + csrf_token), so nothing is decided here and nothing reads as done
 * before the server says success. Text goes in with textContent (no HTML strings).
 */
(function () {
    'use strict';

    var root = document.querySelector('.it-job-mia');
    if (!root) return;

    var WHO = root.getAttribute('data-who') || 'Mia';   // the head whose strip this is (Sam's quote strip shares this script)
    var queue = [];
    try { queue = JSON.parse(root.querySelector('.it-job-data').textContent) || []; } catch (e) { queue = []; }
    // "Not yet" on a card that offers it: asked again after a day, in this browser.
    function snoozeKey(card) { return 'it-job-later:' + card.key; }
    function snoozed(card) {
        if (!card.later) return false;
        try { var t = parseInt(window.localStorage.getItem(snoozeKey(card)) || '0', 10); return t > Date.now(); } catch (e) { return false; }
    }
    function snooze(card) { try { window.localStorage.setItem(snoozeKey(card), String(Date.now() + 86400000)); } catch (e) { /* no storage: it comes back on reload */ } }
    queue = queue.filter(function (c) { return !snoozed(c); });
    // Nothing left to decide: the page's numbers (Otto's schedule line) take the strip's place.
    function gone() { root.hidden = true; var o = root.closest('.it-job-outer'); if (o && !o.classList.contains('it-job-nums-outer')) o.hidden = true; }
    function showNums() { var n = document.querySelector('.it-job-nums'); if (n) n.hidden = false; }
    if (!queue.length) { gone(); showNums(); return; }

    var msgBox = root.querySelector('.it-job-msg');
    var msgText = root.querySelector('.it-job-msgt');
    var form = root.querySelector('.it-job-form');
    var btnP = root.querySelector('[data-role="primary"]');
    var btnS = root.querySelector('[data-role="secondary"]');
    var skipBtn = root.querySelector('.it-job-skip');
    var busy = false;
    var coolTimer = null;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var PLACE = { facebook: 'Facebook', instagram: 'Instagram', gbp: 'Google Business', linkedin: 'LinkedIn' };

    function q(sel) { return root.querySelector(sel); }
    function put(sel, text) { var n = q(sel); if (n) { n.textContent = text || ''; n.hidden = !text; } }
    function token() { return window.MW_CSRF_TOKEN || ''; }

    function post(url, body, retried) {
        var payload = {};
        Object.keys(body || {}).forEach(function (k) { payload[k] = body[k]; });
        payload.csrf_token = token();
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify(payload)
        }).then(function (r) {
            if (r.status === 403 && !retried) {
                return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (x) { return x.json(); })
                    .then(function (d) { if (d && d.token) window.MW_CSRF_TOKEN = d.token; return post(url, body, true); });
            }
            return r.json();
        });
    }

    /** A page-form request (Before & After: the page's own queue_* actions): form fields plus csrf_token, answered with JSON. */
    function postForm(url, body) {
        var fd = new FormData();
        Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
        fd.append('csrf_token', root.getAttribute('data-csrf') || token());
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
    }

    function setBusy(on) {
        busy = on;
        Array.prototype.forEach.call(root.querySelectorAll('.it-btn, .it-job-skip'), function (b) { b.disabled = on; });
    }
    function say(text) { msgText.textContent = text || ''; msgBox.hidden = !text; }
    function field(name) { return form.querySelector('[name="' + name + '"]'); }

    function fillButton(b, a) {
        b.hidden = !a;
        if (!a) return;
        var parts = b.querySelectorAll('b, span');
        if (parts[0]) parts[0].textContent = a.label || '';
        if (parts[1]) parts[1].textContent = a.sub || '';
    }

    /** Listing cards: the master record against what the site shows. Only the comparison goes in the rows (textContent, no HTML). */
    function renderCompare(rows, link) {
        var ul = q('.it-job-cmp');
        var open = q('.it-job-open');
        if (ul) {
            ul.textContent = '';
            (rows || []).forEach(function (r) {
                var li = document.createElement('li');
                li.className = 'is-' + r.state;
                var k = document.createElement('b'); k.textContent = r.k;
                var mark = document.createElement('i'); mark.setAttribute('aria-hidden', 'true');
                mark.textContent = r.state === 'ok' ? '\u2713' : (r.state === 'diff' ? '\u2260' : '');
                var v = document.createElement('span');
                v.textContent = r.state === 'diff' ? r.site + ' (master: ' + r.master + ')' : (r.state === 'master' ? r.master : (r.site || r.master));
                li.appendChild(k); li.appendChild(mark); li.appendChild(v);
                ul.appendChild(li);
            });
            ul.hidden = !(rows && rows.length);
        }
        if (open) {
            open.hidden = !link;
            if (link) { open.textContent = link.label; open.href = link.href; }
        }
    }

    function render() {
        var card = queue[0];
        if (!card) return;
        put('.it-job-tt', card.title);
        var p = card.post || {};
        put('.it-job-who', card.title);
        var places = (p.platforms || []).map(function (k) { return PLACE[k] || k; }).join(' · ');
        put('.it-job-at', [places, p.when].filter(Boolean).join(' · '));
        put('.it-job-quote', p.caption);
        renderCompare(card.compare, card.link);
        put('.it-job-why', card.why);
        var why = q('.it-job-why');
        if (why) { if (card.detail) why.setAttribute('title', card.detail); else why.removeAttribute('title'); }
        var conf = q('.it-job-conf');
        var sure = card.sure || 'forming';
        var words = { forming: 'No record yet.', held: 'Noticed, not yet known: check it.', known: WHO + ' knows this kind.', mastered: WHO + ' has mastered this kind.' };
        var label = WHO + ' proposes; your decision acts. ' + (words[sure] || '') + (sure !== 'forming' && card.confidence ? ' ' + card.confidence : '');
        conf.className = 'it-job-conf is-' + sure;
        conf.setAttribute('aria-label', label);
        conf.setAttribute('title', label);
        conf.querySelector('use').setAttribute('href', '#' + (sure === 'known' ? 'it-st-approval' : 'it-st-approval-' + sure));

        var thumbs = p.thumbs || (p.thumb ? [p.thumb] : []);
        Array.prototype.forEach.call(root.querySelectorAll('.it-job-fig'), function (fig, i) {
            var src = thumbs[i];
            if (src) {
                fig.querySelector('img').src = src;
                var link = fig.querySelector('a');
                if (link) link.href = src;
                fig.hidden = false;
            } else { fig.hidden = true; }
        });

        form.textContent = '';
        var tpl = q('.it-job-tpl-' + card.kind);
        if (tpl) {
            form.appendChild(tpl.content.cloneNode(true));
            Object.keys(card.right || {}).forEach(function (name) { var f = field(name); if (f && name !== 'min') f.value = card.right[name] || ''; });
            var when = field('scheduled_at');
            if (when && card.right && card.right.min) when.min = card.right.min;
        }
        q('.it-job-right').hidden = !tpl;

        fillButton(btnP, card.primary);
        fillButton(btnS, card.secondary);
        q('.it-job-n').textContent = '1 of ' + queue.length;
        skipBtn.hidden = queue.length < 2 && !card.later;

        var rail = q('.it-job-rail');
        rail.classList.remove('is-enter');
        if (!reduced) { void rail.offsetWidth; rail.classList.add('is-enter'); }

        // Focus the title, never a button: a held Enter must not approve a post nobody has read.
        var tt = q('.it-job-tt');
        tt.setAttribute('tabindex', '-1');
        tt.focus({ preventScroll: true });
        setBusy(true);
        clearTimeout(coolTimer);
        coolTimer = setTimeout(function () { setBusy(false); }, 600);
    }

    function finish(text) {
        root.classList.add('is-done');
        Array.prototype.forEach.call(root.querySelectorAll('.it-job-body, .it-job-foot'), function (n) { n.hidden = true; });
        say((text ? text + ' ' : '') + WHO + ' has nothing else for you.');
        setTimeout(function () { gone(); showNums(); }, 3000);
    }

    /** One button: a link is followed; a request is sent, plus the form values the card names in `fields`. */
    function settle(role) {
        if (busy || !queue.length) return;
        var card = queue[0];
        var a = card[role];
        if (!a) return;
        if (a.skip) { skip(); return; }
        if (a.href) { window.location.href = a.href; return; }
        // Open the site's own page in a new tab, then ask the follow-up. Nothing is sent: only the answer changes anything.
        if (a.open) { window.open(a.open, '_blank', 'noopener'); if (a.alt) { queue[0] = a.alt; render(); } return; }
        if (!a.request && a.alt) { queue[0] = a.alt; render(); return; }
        if (!a.request) return;
        var body = JSON.parse(JSON.stringify(a.request.body || {}));
        if (role === 'primary' || a.withFields) {
            (card.fields || []).forEach(function (name) { var f = field(name); if (f) body[name] = f.value; });
            if ((card.fields || []).indexOf('scheduled_at') >= 0 && !body.scheduled_at) { say('Pick a time first.'); return; }
            var missing = (card.required || []).filter(function (name) { var f = field(name); return !f || !String(f.value).trim(); });
            if (missing.length) { say(card.requiredMsg || 'Fill that in first.'); var m = field(missing[0]); if (m) m.focus(); return; }
        }
        setBusy(true);
        say('');
        (a.request.form ? postForm(a.request.url, body) : post(a.request.url, body)).then(function (d) {
            if (!(d && (d.success || d.ok))) {   // Otto's decide answers ok, the rest success
 setBusy(false); say((d && (d.message || d.error)) || 'Could not save. Try again.'); return; }
            var text = d.message || 'Done.';
            window.dispatchEvent(new CustomEvent('mw:job-done', { detail: { request: body, response: d } }));   // a page may follow (Before & After rows)
            if (d.next) { queue[0] = d.next; setBusy(false); render(); say(text); return; }   // the same pair, its next step
            queue.shift();
            if (!queue.length) { setBusy(false); finish(text); return; }
            render();
            say(text);
        }).catch(function () { setBusy(false); say('Could not reach the server. Try again.'); });
    }

    function skip() {
        if (busy) return;
        var card = queue[0];
        if (card && card.later) {   // "Not yet": this one waits a day
            snooze(card);
            queue.shift();
            say('');
            if (!queue.length) { finish('Okay, later.'); return; }
            render();
            return;
        }
        if (queue.length < 2) return;
        queue.push(queue.shift());
        say('');
        render();
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); });
    btnP.addEventListener('click', function () { settle('primary'); });
    btnS.addEventListener('click', function () { settle('secondary'); });
    skipBtn.addEventListener('click', skip);
    root.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var t = e.target;
        if (t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
        if (queue[0] && queue[0].later) return;   // a quiet "Not yet" is a press, never a stray Escape
        skip();
    });

    // The chart gives way, never the strip: when the rail would overflow with the chart beside it, the strip gets the whole row.
    // The chart's icon sits on its left edge at the rail's height (--it-rail-y), so the line runs on into it.
    var wrap = root.closest('.it-job-wrap');
    if (wrap && wrap.querySelector(':scope > .it-job-viz')) {
        var fitQueued = false;
        var fit = function () {
            fitQueued = false;
            var rail = root.querySelector('.it-job-rail');
            if (!rail) return;
            var was = wrap.classList.contains('is-solo');
            wrap.classList.remove('is-solo');
            var tight = rail.scrollWidth > rail.clientWidth + 1;
            if (!tight) {
                var lead = rail.querySelector('.it-job-anchor-start .it-ad') || rail.querySelector('.it-job-conf');
                var viz = wrap.querySelector(':scope > .it-job-viz');
                if (lead && viz) viz.style.setProperty('--it-rail-y', Math.round(lead.getBoundingClientRect().top + lead.getBoundingClientRect().height / 2 - viz.getBoundingClientRect().top) + 'px');
            }
            wrap.classList.toggle('is-solo', tight);
            if (tight !== was) window.dispatchEvent(new Event('resize'));
        };
        var queueFit = function () { if (!fitQueued) { fitQueued = true; requestAnimationFrame(fit); } };
        window.addEventListener('resize', queueFit);
        if ('ResizeObserver' in window && wrap.parentNode) new ResizeObserver(queueFit).observe(wrap.parentNode);
        if ('MutationObserver' in window) new MutationObserver(queueFit).observe(root, { childList: true, subtree: true });
        queueFit();
    }

    root.hidden = false;
    render();
})();
