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

    var queue = [];
    try { queue = JSON.parse(root.querySelector('.it-job-data').textContent) || []; } catch (e) { queue = []; }
    if (!queue.length) return;

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

    function render() {
        var card = queue[0];
        if (!card) return;
        put('.it-job-tt', card.title);
        var p = card.post || {};
        put('.it-job-who', card.title);
        var places = (p.platforms || []).map(function (k) { return PLACE[k] || k; }).join(' · ');
        put('.it-job-at', [places, p.when].filter(Boolean).join(' · '));
        put('.it-job-quote', p.caption);
        put('.it-job-why', card.why);
        var why = q('.it-job-why');
        if (why) { if (card.detail) why.setAttribute('title', card.detail); else why.removeAttribute('title'); }
        var conf = q('.it-job-conf');
        var sure = card.sure || 'forming';
        var words = { forming: 'No record yet.', held: 'Noticed, not yet known: check it.', known: 'Mia knows this kind.', mastered: 'Mia has mastered this kind.' };
        var label = 'Mia proposes; your decision acts. ' + (words[sure] || '') + (sure !== 'forming' && card.confidence ? ' ' + card.confidence : '');
        conf.className = 'it-job-conf is-' + sure;
        conf.setAttribute('aria-label', label);
        conf.setAttribute('title', label);
        conf.querySelector('use').setAttribute('href', '#' + (sure === 'known' ? 'it-st-approval' : 'it-st-approval-' + sure));

        var fig = q('.it-job-fig');
        if (p.thumb) { fig.querySelector('img').src = p.thumb; fig.hidden = false; } else { fig.hidden = true; }

        form.textContent = '';
        var tpl = q('.it-job-tpl-' + card.kind);
        if (tpl) {
            form.appendChild(tpl.content.cloneNode(true));
            var when = field('scheduled_at');
            if (when && card.right) { when.value = card.right.scheduled_at || ''; if (card.right.min) when.min = card.right.min; }
        }
        q('.it-job-right').hidden = !tpl;

        fillButton(btnP, card.primary);
        fillButton(btnS, card.secondary);
        q('.it-job-n').textContent = '1 of ' + queue.length;
        skipBtn.hidden = queue.length < 2;

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
        say((text ? text + ' ' : '') + 'Mia has nothing else for you.');
        setTimeout(function () { root.hidden = true; }, 3000);
    }

    /** One button: a link is followed; a request is sent, plus the form values the card names in `fields`. */
    function settle(role) {
        if (busy || !queue.length) return;
        var card = queue[0];
        var a = card[role];
        if (!a) return;
        if (a.skip) { skip(); return; }
        if (a.href) { window.location.href = a.href; return; }
        if (!a.request) return;
        var body = JSON.parse(JSON.stringify(a.request.body || {}));
        if (role === 'primary') {
            (card.fields || []).forEach(function (name) { var f = field(name); if (f) body[name] = f.value; });
            if ((card.fields || []).indexOf('scheduled_at') >= 0 && !body.scheduled_at) { say('Pick a time first.'); return; }
        }
        setBusy(true);
        say('');
        post(a.request.url, body).then(function (d) {
            if (!(d && d.success)) { setBusy(false); say((d && (d.message || d.error)) || 'Could not save. Try again.'); return; }
            var text = d.message || 'Done.';
            queue.shift();
            if (!queue.length) { setBusy(false); finish(text); return; }
            render();
            say(text);
        }).catch(function () { setBusy(false); say('Could not reach the server. Try again.'); });
    }

    function skip() {
        if (busy || queue.length < 2) return;
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
        skip();
    });

    root.hidden = false;
    render();
})();
