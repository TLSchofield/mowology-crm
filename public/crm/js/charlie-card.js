/**
 * Charlie (Chief of Staff) — dashboard card.
 *
 * Loads after the page: /crm/api/charlie.php?mode=today asks every head and returns
 * the one thing, the rest, each head's top items and Charlie's questions.
 * Buttons: Open it (counts as "you went to it" — Charlie learns your order), Later
 * (back tomorrow, teaches nothing), Not today (teaches him it matters less).
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-charlie');
    if (!root) return;

    var API = '/crm/api/charlie.php';
    var say = document.getElementById('mw-charlie-say');
    var acts = document.getElementById('mw-charlie-acts');
    var msg = document.getElementById('mw-charlie-msg');
    var qs = document.getElementById('mw-charlie-qs');
    var brief = document.getElementById('mw-charlie-brief');
    var busy = false;
    var current = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function safeUrl(u) {
        return (typeof u === 'string' && /^(\/|#|https:\/\/)/.test(u)) ? u : null;
    }

    function renderSay(d) {
        var s = d.say || {};
        var html = '<span class="mw-charlie-lead">' + esc(s.lead) + '</span>';
        if (s.thing) html += '<b class="mw-charlie-thing">' + esc(s.thing) + '</b>';
        if (s.after) html += '<span class="mw-charlie-after">' + esc(s.after) + '</span>';
        if (d.failed && d.failed.length) {
            html += '<span class="mw-charlie-after">I couldn\'t reach ' + esc(d.failed.join(', ')) + ' just now.</span>';
        }
        say.innerHTML = html;
        current = d.one;
        acts.hidden = !d.one;
        var go = acts.querySelector('[data-what="open"]');
        if (go) go.hidden = !(d.one && safeUrl(d.one.url));
    }

    function renderHeads(heads) {
        var box = brief.querySelector('.mw-charlie-heads');
        var waiting = 0;
        box.innerHTML = (heads || []).map(function (h) {
            waiting += h.waiting || 0;
            var items = (h.items || []).map(function (it) {
                var u = safeUrl(it.url);
                var t = esc(it.text);
                return '<li class="is-p' + (it.priority || 2) + '">' + (u ? '<a href="' + esc(u) + '" data-key="' + esc(it.key) + '">' + t + '</a>' : t) + '</li>';
            }).join('');
            var more = (h.waiting || 0) - (h.items || []).length;
            return '<div class="mw-charlie-head">'
                + '<div class="mw-charlie-hd"><b>' + esc(h.name) + '</b> <small>' + esc(h.role) + '</small></div>'
                + '<div class="mw-charlie-hl">' + esc(h.headline) + '</div>'
                + (items ? '<ul>' + items + '</ul>' : '')
                + (more > 0 ? '<div class="mw-charlie-more">+ ' + more + ' more</div>' : '')
                + '</div>';
        }).join('');
        brief.querySelector('summary').textContent = 'Your 7 am brief · ' + waiting + ' waiting across the team';
        brief.hidden = !(heads && heads.length);
    }

    function renderQuestions(list) {
        if (!list || !list.length) { qs.hidden = true; qs.innerHTML = ''; return; }
        qs.innerHTML = list.map(function (q) {
            return '<div class="mw-charlie-q" data-id="' + q.id + '"><p>' + esc(q.question) + '</p><div class="mw-charlie-qbtns">'
                + q.options.map(function (o) {
                    return '<button type="button" data-answer="' + esc(o.answer) + '">' + esc(o.label) + '</button>';
                }).join('') + '</div></div>';
        }).join('');
        qs.hidden = false;
    }

    function load() {
        return fetch(API + '?mode=today', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    say.innerHTML = '<span class="mw-charlie-lead">' + esc((d && d.error) || 'I can\'t reach the team right now.') + '</span>';
                    acts.hidden = true;
                    return;
                }
                renderSay(d);
                renderHeads(d.heads);
                renderQuestions(d.questions);
            })
            .catch(function () {
                say.innerHTML = '<span class="mw-charlie-lead">I can\'t reach the team right now — try a refresh.</span>';
                acts.hidden = true;
            });
    }

    acts.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-what]');
        if (!b || busy || !current) return;
        var what = b.getAttribute('data-what');
        var target = what === 'open' ? safeUrl(current.url) : null;
        busy = true;
        post({ mode: 'act', key: current.key, what: what })
            .then(function (r) {
                if (what === 'open' && target) { window.location.href = target; return; }
                msg.textContent = (r && r.message) || '';
                return load();
            })
            .catch(function () { msg.textContent = 'That didn\'t save — try again.'; })
            .then(function () { busy = false; });
    });

    // A click on any brief item counts the same as "Open it".
    brief.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-key]');
        if (!a) return;
        e.preventDefault();
        var href = a.getAttribute('href');
        post({ mode: 'act', key: a.getAttribute('data-key'), what: 'open' })
            .catch(function () {})
            .then(function () { window.location.href = href; });
    });

    qs.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-answer]');
        if (!b || busy) return;
        var q = b.closest('.mw-charlie-q');
        busy = true;
        post({ mode: 'answer', question_id: parseInt(q.getAttribute('data-id'), 10), answer: b.getAttribute('data-answer') })
            .then(function (r) {
                msg.textContent = (r && r.message) || 'Thanks.';
                return load();
            })
            .catch(function () { msg.textContent = 'That didn\'t save — try again.'; })
            .then(function () { busy = false; });
    });

    load();
})();
