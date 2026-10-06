/**
 * Otto — the operations head's card on the dashboard (public/crm/includes/otto-card.php).
 *
 * Loads Otto's suggestions and questions from /crm/api/otto.php?mode=suggestions and lets
 * the owner act on each one. Nothing changes until he clicks: a weather move asks the
 * weather guard for a slot first and shows it before moving; a clock-out or a duration
 * is shown in an editable field first. Nothing here messages a crew member.
 */
(function () {
    'use strict';
    var API = '/crm/api/otto.php';
    var list, qs;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function get(params) {
        return fetch(API + '?' + params, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); });
    }

    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    // ── Suggestions ──────────────────────────────────────────────────────────

    function controls(it) {
        var p = it.propose || {};
        switch (it.kind) {
            case 'weather':
                var moveFirst = p.lean === 'move';
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="' + (moveFirst ? 'is-main' : '') + '" data-do="slot">Move it</button>' +
                    '<button type="button" class="' + (p.lean === 'keep' ? 'is-main' : '') + '" data-do="keep">Keep it</button>' +
                    '</div><div class="mw-otto-slot" hidden></div>';
            case 'clock_out':
                return '<div class="mw-otto-btns">' +
                    '<label class="mw-otto-field">Clock-out <input type="datetime-local" name="clock_out" min="' + esc(p.clock_in || '') +
                    '" value="' + esc(p.clock_out || p.clock_in || '') + '"></label>' +
                    '<button type="button" class="is-main" data-do="apply">Use this time</button>' +
                    '<button type="button" data-do="dismiss">Leave it</button></div>';
            case 'job_timer':
            case 'no_time':
                return '<div class="mw-otto-btns">' +
                    '<label class="mw-otto-field"><input type="number" name="minutes" min="1" max="720" step="5" value="' + esc(p.minutes || '') + '"> min</label>' +
                    '<button type="button" class="is-main" data-do="apply">' + (it.kind === 'job_timer' ? 'Fix the timer' : 'Save the time') + '</button>' +
                    '<button type="button" data-do="dismiss">Leave it</button></div>';
            case 'bylaw':
                return '<div class="mw-otto-btns">' +
                    (p.problem === 'ban' ? '' :
                        '<label class="mw-otto-field">Start <input type="time" name="time" value="' + esc(p.allowed_start || '') + '"></label>' +
                        '<button type="button" class="is-main" data-do="retime">Use this time</button>') +
                    (p.blower ? '<button type="button"' + (p.problem === 'ban' ? ' class="is-main"' : '') + ' data-do="note">Rake/vac instead — tell the crew</button>' : '') +
                    '<button type="button" data-do="dismiss">Leave it</button></div>';
            case 'west_end':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="note">Add a crew note</button>' +
                    '<button type="button" data-do="dismiss">Leave it</button></div>';
            case 'truck_range':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="ack">I\'ll rework the day</button>' +
                    '<button type="button" data-do="dismiss">It\'s fine</button></div>';
            case 'maintenance':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="task">Make a task</button>' +
                    '<button type="button" data-do="done">Already done</button>' +
                    '<button type="button" data-do="dismiss">Leave it</button></div>';
            case 'pack_fading':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="retire">Retire the pack</button>' +
                    '<button type="button" data-do="dismiss">Keep using it</button></div>';
            case 'training_gap':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="task">Make me a task</button>' +
                    (p.shadowed ? '<button type="button" data-do="shadow">Fine, they\'re shadowing</button>' : '') +
                    '<button type="button" data-do="dismiss">Not now</button></div>';
            case 'training_quality':
            case 'safety_refresher':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="task">Make me a task</button>' +
                    '<button type="button" data-do="dismiss">Not now</button></div>';
            case 'training_topic':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="task">Add to the crew meeting</button>' +
                    '<button type="button" data-do="dismiss">Not now</button></div>';
            case 'silent':
                return '<div class="mw-otto-btns">' +
                    '<button type="button" class="is-main" data-do="real">It\'s a problem — I\'ll call</button>' +
                    '<button type="button" data-do="fine">It\'s fine</button></div>';
        }
        return '';
    }

    function item(it) {
        var el = document.createElement('div');
        el.className = 'mw-otto-item is-p' + it.priority;
        el.dataset.id = it.id;
        el.dataset.kind = it.kind;
        el.innerHTML = '<p>' + esc(it.text) + '</p>' +
            (it.detail ? '<small class="mw-otto-detail">' + esc(it.detail) + '</small>' : '') +
            controls(it) +
            (it.url ? '<a class="mw-otto-open" href="' + esc(it.url) + '">Open</a>' : '') +
            '<div class="mw-otto-msg" hidden></div>';
        el.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-do]');
            if (b) act(el, it, b.getAttribute('data-do'), b);
        });
        return el;
    }

    function say(el, text, bad) {
        var m = el.querySelector('.mw-otto-msg');
        m.hidden = false;
        m.textContent = text;
        m.classList.toggle('is-bad', !!bad);
    }

    function done(el, text) {
        el.classList.add('is-done');
        el.innerHTML = '<p class="mw-otto-done">' + esc(text) + '</p>';
        setTimeout(function () {
            el.remove();
            if (!list.querySelector('.mw-otto-item')) list.innerHTML = '<div class="mw-otto-empty">All caught up. Nothing needs you.</div>';
        }, 2600);
    }

    function decide(el, body, btn) {
        if (btn) btn.disabled = true;
        body.mode = 'decide';
        body.suggestion_id = parseInt(el.dataset.id, 10);
        return post(body).then(function (r) {
            if (r.ok) done(el, r.message);
            else { say(el, r.message || r.error || 'That didn\'t work.', true); if (btn) btn.disabled = false; }
        }).catch(function () { say(el, 'No connection. Try again.', true); if (btn) btn.disabled = false; });
    }

    function act(el, it, what, btn) {
        if (what === 'slot') return findSlot(el, it, btn);
        if (what === 'move') {
            var d = el.querySelector('input[name="date"]').value;
            var t = el.querySelector('input[name="time"]').value;
            if (!d) return say(el, 'Pick a date first.', true);
            return decide(el, { choice: 'move', date: d, time: t }, btn);
        }
        if (what === 'retime') {
            var tm = el.querySelector('input[name="time"]');
            if (!tm || !tm.value) return say(el, 'Pick a start time first.', true);
            return decide(el, { choice: 'retime', time: tm.value }, btn);
        }
        if (what === 'cancel') { el.querySelector('.mw-otto-slot').hidden = true; return; }
        if (what === 'apply') {
            var body = { choice: 'apply' };
            var co = el.querySelector('input[name="clock_out"]');
            var mi = el.querySelector('input[name="minutes"]');
            if (co) { if (!co.value) return say(el, 'Set the clock-out time first.', true); body.clock_out = co.value.replace('T', ' '); }
            if (mi) { if (!mi.value) return say(el, 'Give the minutes first.', true); body.minutes = parseInt(mi.value, 10); }
            return decide(el, body, btn);
        }
        return decide(el, { choice: what }, btn);
    }

    function findSlot(el, it, btn) {
        btn.disabled = true;
        var box = el.querySelector('.mw-otto-slot');
        box.hidden = false;
        box.innerHTML = '<small>Looking for a dry slot…</small>';
        get('mode=find_slot&visit_id=' + encodeURIComponent(it.key.split(':').pop())).then(function (r) {
            btn.disabled = false;
            var s = (r && r.slot) || null;
            box.innerHTML = '<small>' + (s ? 'The next dry slot I can find:' : 'I couldn\'t find a dry slot in the next few days. Pick one:') + '</small>' +
                '<div class="mw-otto-btns">' +
                '<input type="date" name="date" value="' + esc(s ? s.date : '') + '">' +
                '<input type="time" name="time" value="' + esc(s ? s.time_start : '08:00') + '">' +
                '<button type="button" class="is-main" data-do="move">Move it there</button>' +
                '<button type="button" data-do="cancel">Cancel</button></div>';
        }).catch(function () { btn.disabled = false; box.innerHTML = '<small>No connection. Try again.</small>'; });
    }

    // ── Questions ────────────────────────────────────────────────────────────

    var ANSWER_LABELS = {
        keep: 'Keep it going', move: 'Move it', depends: 'It depends',
        yes: 'Yes, wait an hour', no: 'No, keep flagging'
    };

    function questions(list2) {
        if (!list2 || !list2.length) { qs.hidden = true; return; }
        qs.hidden = false;
        qs.innerHTML = '<div class="mw-otto-qhead"><b>Otto has ' + (list2.length === 1 ? 'a question' : list2.length + ' questions') + '</b></div>' +
            list2.map(function (q) {
                return '<div class="mw-otto-q" data-id="' + q.id + '"><p>' + esc(q.question) + '</p><div class="mw-otto-btns">' +
                    q.answers.map(function (a) { return '<button type="button" data-a="' + esc(a) + '">' + esc(ANSWER_LABELS[a] || a) + '</button>'; }).join('') +
                    '</div></div>';
            }).join('');
    }

    function onQuestion(e) {
        var b = e.target.closest('button[data-a]');
        if (!b) return;
        var q = b.closest('.mw-otto-q');
        b.disabled = true;
        post({ mode: 'answer', question_id: parseInt(q.dataset.id, 10), answer: b.getAttribute('data-a') }).then(function (r) {
            q.innerHTML = '<p class="mw-otto-done">' + esc(r.ok ? r.message : (r.message || r.error || 'That didn\'t work.')) + '</p>';
            setTimeout(function () { q.remove(); if (!qs.querySelector('.mw-otto-q')) qs.hidden = true; }, 2600);
        }).catch(function () { b.disabled = false; });
    }

    function load() {
        get('mode=suggestions').then(function (r) {
            if (!r || !r.ok) { list.innerHTML = '<div class="mw-otto-empty">' + esc((r && r.error) || 'Otto couldn\'t load.') + '</div>'; return; }
            list.innerHTML = '';
            if (!r.items.length) list.innerHTML = '<div class="mw-otto-empty">All caught up. Nothing needs you.</div>';
            r.items.slice(0, 8).forEach(function (it) { list.appendChild(item(it)); });
            if (r.items.length > 8) {
                var more = document.createElement('div');
                more.className = 'mw-otto-empty';
                more.textContent = (r.items.length - 8) + ' more after these.';
                list.appendChild(more);
            }
            questions(r.questions);
        }).catch(function () { list.innerHTML = '<div class="mw-otto-empty">Otto couldn\'t load. Refresh to try again.</div>'; });
    }

    function init() {
        list = document.getElementById('mw-otto-list');
        qs = document.getElementById('mw-otto-qs');
        if (!list) return;
        if (qs) qs.addEventListener('click', onQuestion);
        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
