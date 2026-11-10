/**
 * Yui's job strip (Ittai desktop shell): her decision queue across the top of her pages.
 * Markup comes from public/crm/includes/ittai-job-yui.php; the cards come from GET /crm/api/yui.php?mode=jobs
 * (YuiJobAdapter). Buttons send the exact requests the web Yui card sends (JSON body: mode + params + csrf_token),
 * so nothing is decided here and nothing reads as done before the server says ok. A "who is this?" answer shows
 * Undo for 6 s. Text goes in with textContent (no HTML strings); each card's form is cloned from a <template>.
 */
(function () {
    'use strict';

    var UNDO_MS = 6000;
    var root = document.querySelector('.it-job-yui');
    if (!root) return;

    var api = root.getAttribute('data-api') || '/crm/api/yui.php';
    var msgBox = root.querySelector('.it-job-msg');
    var msgText = root.querySelector('.it-job-msgt');
    var undoBtn = root.querySelector('.it-job-undo');
    var form = root.querySelector('.it-job-form');
    var btnP = root.querySelector('[data-role="primary"]');
    var btnS = root.querySelector('[data-role="secondary"]');
    var skipBtn = root.querySelector('.it-job-skip');
    var queue = [];
    var busy = false;
    var undoTimer = null;
    var coolTimer = null;
    var findTimer = null;
    var lastDone = null;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function q(sel) { return root.querySelector(sel); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function put(sel, text) { var n = q(sel); if (n) { n.textContent = text || ''; n.hidden = !text; } }

    function token() { return window.MW_CSRF_TOKEN || ''; }
    function post(url, body, retried) {
        var payload = {};
        Object.keys(body).forEach(function (k) { payload[k] = body[k]; });
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
        if (!on) gate();
    }
    function say(text, withUndo) {
        msgText.textContent = text || '';
        undoBtn.hidden = !withUndo;
        msgBox.hidden = !text;
    }

    function field(name) { return form.querySelector('[name="' + name + '"]'); }

    /** Primary stays off until there is something to send / an account chosen. */
    function gate() {
        var card = queue[0];
        if (!card || busy) return;
        var body = field('body');
        var cid = field('client_id');
        var off = false;
        var rcpt = field('recipient');
        if (card.kind === 'who') off = !cid || cid.value === '';
        else if (body) off = body.value.trim() === '' || (rcpt && rcpt.value === '');
        btnP.disabled = off;
    }

    function fillButton(b, a) {
        var parts = b.querySelectorAll('b, span');
        if (parts[0]) parts[0].textContent = (a && a.label) || '';
        if (parts[1]) parts[1].textContent = (a && a.sub) || '';
    }

    function setWarn() {
        var sel = field('recipient'), warn = form.querySelector('.it-job-warn');
        if (!sel || !warn) return;
        var opt = sel.options[sel.selectedIndex];
        warn.hidden = !opt || opt.getAttribute('data-on-file') === '1';
    }

    function addAccount(sel, id, name) {
        var found = null;
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value === String(id)) found = o; });
        if (!found) { found = el('option', '', name); found.value = String(id); sel.appendChild(found); }
        sel.value = String(id);
    }

    function buildStatement(card) {
        var r = card.right || {};
        var sel = field('recipient');
        (r.recipients || []).forEach(function (rc) {
            var o = el('option', '', rc.name ? rc.name + ' · ' + rc.email : rc.email);
            o.value = rc.email;
            o.setAttribute('data-on-file', rc.on_file ? '1' : '0');
            sel.appendChild(o);
        });
        if (r.recipient) sel.value = r.recipient;
        if (sel.selectedIndex < 0 && sel.options.length) sel.selectedIndex = 0;   // the web card's default: the first address
        field('subject').value = r.subject || '';
        field('body').value = r.body || '';
        sel.addEventListener('change', setWarn);
        sel.addEventListener('change', greet);
        setWarn();
        greet();
    }

    /**
     * The greeting follows the address: a statement drafted "Hi Candace," but addressed to Sandra went out wrong
     * (Tim, 2026-11-10). Only the first line's name is touched, and only while the owner has not edited the body.
     */
    function greet() {
        var sel = field('recipient'), body = field('body');
        if (!sel || !body || sel.selectedIndex < 0) return;
        var opt = sel.options[sel.selectedIndex];
        var label = opt ? opt.textContent : '';
        var first = label.split(' · ')[0].trim().split(/\s+/)[0] || '';
        if (!first || first.indexOf('@') >= 0) return;
        var m = /^(Hi|Hello|Dear)\s+([^,\n]+),/.exec(body.value);
        if (!m || m[2] === first) return;
        body.value = body.value.replace(m[0], m[1] + ' ' + first + ',');
    }

    function buildWho(card) {
        var r = card.right || {};
        var sel = field('client_id');
        if (!r.client_id) { var o0 = el('option', '', 'Choose…'); o0.value = ''; sel.appendChild(o0); }
        (r.candidates || []).forEach(function (c) { var o = el('option', '', c.name); o.value = String(c.client_id); sel.appendChild(o); });
        if (r.client_id) sel.value = String(r.client_id);
        field('first_name').value = r.first_name || '';
        field('last_name').value = r.last_name || '';
        sel.addEventListener('change', gate);
        var search = field('q'), list = form.querySelector('.it-job-found');
        search.addEventListener('input', function () {
            clearTimeout(findTimer);
            var term = search.value.trim();
            list.textContent = '';
            if (term.length < 2) return;
            findTimer = setTimeout(function () {
                fetch(api + '?mode=who_accounts&q=' + encodeURIComponent(term), { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (x) { return x.json(); })
                    .then(function (d) {
                        list.textContent = '';
                        var rows = (d && (d.accounts || d.results || d.clients)) || [];
                        rows.forEach(function (a) {
                            var id = a.client_id != null ? a.client_id : a.id;
                            var li = el('li');
                            li.setAttribute('role', 'option');
                            var b = el('button', '', a.name || a.label || '');
                            b.type = 'button';
                            b.addEventListener('click', function () { addAccount(sel, id, a.name || a.label || ''); list.textContent = ''; search.value = ''; gate(); });
                            li.appendChild(b);
                            list.appendChild(li);
                        });
                    }).catch(function () { list.textContent = ''; });
            }, 250);
        });
    }

    function buildReply(card) {
        var r = card.right || {};
        var to = r.to && typeof r.to === 'object' ? ((r.to.name ? r.to.name + ' · ' : '') + (r.to.email || '')) : (r.to || '');
        field('to').value = to;
        field('subject').value = r.subject || '';
        field('body').value = r.body || '';
        var dr = form.querySelector('.it-job-draft');
        var draftReq = card.draft ? (card.draft.request || card.draft) : null;   // the adapter sends {mode:'draft', key} directly
        if (!(r.can_draft && draftReq && draftReq.mode)) { dr.hidden = true; return; }
        dr.addEventListener('click', function () {
            if (busy) return;
            setBusy(true);
            say('Drafting…', false);
            post(card.api || api, draftReq).then(function (d) {
                setBusy(false);
                if (d && d.ok) { field('body').value = d.body || d.draft || d.text || ''; say('Here’s a draft — read it before you send.', false); gate(); }
                else say((d && (d.message || d.error)) || 'Could not draft that one', false);
            }).catch(function () { setBusy(false); say('Could not reach Yui — try again', false); });
        });
    }

    // Enter in the one text field of a form would submit it (a GET reload that drops the body): never.
    form.addEventListener('submit', function (e) { e.preventDefault(); });

    function render() {
        var card = queue[0];
        if (!card) return;
        put('.it-job-tt', card.title);
        var l = card.left || {};
        put('.it-job-who', l.name);
        put('.it-job-at', l.at);
        put('.it-job-subj', l.subject);
        put('.it-job-quote', l.text);
        put('.it-job-addr', card.kind === 'who' ? l.addr : '');
        var more = q('.it-job-more');
        more.textContent = '';
        if (Array.isArray(l.more)) l.more.forEach(function (m) { more.appendChild(el('li', '', m)); });
        else if (l.more && Number(l.more) > 0) more.appendChild(el('li', '', Number(l.more) + ' earlier from them'));
        put('.it-job-why', card.why);

        form.textContent = '';
        var tpl = q('.it-job-tpl-' + card.kind);
        if (tpl) form.appendChild(tpl.content.cloneNode(true));
        if (card.kind === 'statement') buildStatement(card);
        else if (card.kind === 'who') buildWho(card);
        else if (card.kind === 'reply') buildReply(card);
        var body = field('body');
        if (body) body.addEventListener('input', gate);

        fillButton(btnP, card.primary);
        fillButton(btnS, card.secondary);
        q('.it-job-n').textContent = '1 of ' + queue.length;
        skipBtn.hidden = queue.length < 2;

        var rail = q('.it-job-rail');
        rail.classList.remove('is-enter');
        if (!reduced) { void rail.offsetWidth; rail.classList.add('is-enter'); }

        // Focus the title, never a button: a held Enter must not send a message nobody has read.
        var tt = q('.it-job-tt');
        tt.setAttribute('tabindex', '-1');
        tt.focus({ preventScroll: true });
        setBusy(true);
        clearTimeout(coolTimer);
        coolTimer = setTimeout(function () { setBusy(false); }, 600);
    }

    function finish(text, withUndo) {
        root.classList.add('is-done');
        Array.prototype.forEach.call(root.querySelectorAll('.it-job-body, .it-job-foot'), function (n) { n.hidden = true; });
        say((text ? text + ' ' : '') + 'Yui has nothing else for you.', withUndo);
        clearTimeout(undoTimer);
        undoTimer = setTimeout(function () { root.hidden = true; }, withUndo ? UNDO_MS : 3000);
    }
    function reopen() {
        root.classList.remove('is-done');
        Array.prototype.forEach.call(root.querySelectorAll('.it-job-body, .it-job-foot'), function (n) { n.hidden = false; });
        root.hidden = false;
    }

    /** The request for one button: a copy of the card's, plus (primary only) the form values named in card.fields. */
    function request(card, role) {
        var req = JSON.parse(JSON.stringify((card[role] && card[role].request) || {}));
        if (role === 'primary') {
            (card.fields || []).forEach(function (name) {
                var f = field(name);
                if (f) req[name] = f.value;
            });
        }
        return req;
    }

    function settle(role) {
        if (busy || !queue.length) return;
        var card = queue[0];
        var req = request(card, role);
        clearTimeout(undoTimer);
        setBusy(true);
        say('', false);
        post(card.api || api, req).then(function (d) {
            if (!(d && d.ok)) { setBusy(false); say((d && (d.message || d.error)) || 'Could not save — try again', false); return; }
            var canUndo = !!(card.undo && card.undo.request && role === 'primary');
            var text = d.message || 'Done';
            queue.shift();
            lastDone = canUndo ? card : null;
            if (!queue.length) { setBusy(false); finish(text, canUndo); return; }
            render();
            say(text, canUndo);
            if (canUndo) undoTimer = setTimeout(function () { lastDone = null; say(text, false); }, UNDO_MS);
        }).catch(function () { setBusy(false); say('Could not reach Yui — try again', false); });
    }

    function undo() {
        var card = lastDone;
        if (busy || !card) return;
        clearTimeout(undoTimer);
        setBusy(true);
        post(card.api || api, JSON.parse(JSON.stringify(card.undo.request))).then(function (d) {
            setBusy(false);
            if (!(d && d.ok)) { say((d && (d.message || d.error)) || 'Could not undo — try again', true); return; }
            lastDone = null;
            queue.unshift(card);
            reopen();
            render();
            say('Undone — back as it was.', false);
        }).catch(function () { setBusy(false); say('Could not reach Yui — try again', true); });
    }

    function skip() {
        if (busy || queue.length < 2) return;
        queue.push(queue.shift());
        say('', false);
        render();
    }

    btnP.addEventListener('click', function () { settle('primary'); });
    btnS.addEventListener('click', function () { settle('secondary'); });
    skipBtn.addEventListener('click', skip);
    undoBtn.addEventListener('click', undo);
    root.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var t = e.target;
        if (t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
        skip();
    });

    function start(jobs) {
        if (!jobs || !jobs.length) return;
        queue = jobs;
        root.hidden = false;
        render();
    }

    var demoEl = document.querySelector('.it-job-demo');
    if (demoEl) {
        try { start(JSON.parse(demoEl.textContent)); } catch (e) { /* leave hidden */ }
    } else {
        fetch(api + '?mode=jobs', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok) start(d.jobs); })
            .catch(function () { /* stay hidden: the page works without the strip */ });
    }

    window.ItJobYui = { next: render };
})();
