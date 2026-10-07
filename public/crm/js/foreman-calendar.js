/**
 * Charlie, Foreman — the calendar page (/crm/foreman_calendar_appstack.php).
 *
 * Deadlines (Done / Snooze / Not this year / Edit), the year-end package, the decision
 * inbox (shared CharlieInbox), Tim's rules with every ruling, and history.
 * Data: /crm/api/charlie.php?mode=calendar. Every change posts back with the CSRF token.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-foreman');
    if (!root) return;
    var API = '/crm/api/charlie.php';
    var CI = window.CharlieInbox;
    var esc = CI.esc;
    var $ = function (id) { return document.getElementById(id); };
    var msg = $('mw-foreman-msg');
    var form = $('mw-foreman-form');
    var F = form.elements;
    var data = null;

    function say(m) { msg.textContent = m || ''; }
    function post(body) { return CI.post(API, body); }

    var GROUPS = [
        ['now', 'Needs you now'], ['soon', 'Coming up'], ['later', 'Later'], ['setup', 'Needs a date'], ['off', 'Not watching']
    ];
    function group(d) {
        if (!+d.active) return 'off';
        if (d.needs_setup) return 'setup';
        if (d.priority === 1) return 'now';
        if (d.priority === 2) return 'soon';
        return 'later';
    }

    function deadlineHtml(d) {
        var notes = [];
        if (d.condition_note) notes.push('<div class="mw-charlie-note">' + esc(d.condition_note) + '</div>');
        if (d.confirm_note) notes.push('<div class="mw-charlie-note is-confirm">' + esc(d.confirm_note) + '</div>');
        var prep = (d.prepare || '').split('\n').filter(Boolean);
        var btns = '';
        if (d.occ_id && +d.active) {
            btns = '<button type="button" class="mw-charlie-btn is-primary" data-mark="done" data-occ="' + d.occ_id + '">Done</button>'
                 + '<button type="button" class="mw-charlie-btn" data-mark="snooze" data-occ="' + d.occ_id + '">Remind me in 3 days</button>'
                 + '<button type="button" class="mw-charlie-btn is-quiet" data-mark="skip" data-occ="' + d.occ_id + '">Not this time</button>';
        }
        btns += '<button type="button" class="mw-charlie-btn is-quiet" data-edit="' + d.id + '">Edit</button>';
        var u = CI.safeUrl(d.url);
        return '<div class="mw-charlie-dl' + (d.overdue ? ' is-overdue' : '') + '" id="d-' + esc(d.slug) + '">'
            + '<div class="mw-charlie-dl-hd"><b>' + esc(d.title) + '</b><span class="mw-charlie-dl-when">' + esc(d.when || '') + '</span></div>'
            + '<div class="mw-charlie-dl-rule">' + esc(d.describe) + (d.due_date ? ' · next ' + esc(d.due_date) : '') + ' · reminds ' + (+d.lead_days) + ' days before'
            + (u ? ' · <a href="' + esc(u) + '">open</a>' : '') + '</div>'
            + notes.join('')
            + (prep.length ? '<ul class="mw-charlie-prep">' + prep.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul>' : '')
            + '<div class="mw-charlie-row-btns">' + btns + '</div></div>';
    }

    function renderDeadlines(list) {
        var box = $('mw-foreman-deadlines');
        if (!list) { box.innerHTML = '<div class="mw-charlie-empty">The calendar needs migration 1171.</div>'; return; }
        var by = {};
        list.forEach(function (d) { (by[group(d)] = by[group(d)] || []).push(d); });
        box.innerHTML = GROUPS.map(function (g) {
            var items = by[g[0]] || [];
            if (!items.length) return '';
            return '<section class="mw-charlie-dl-group is-' + g[0] + '"><h5>' + g[1] + ' <small>' + items.length + '</small></h5>' + items.map(deadlineHtml).join('') + '</section>';
        }).join('');
    }

    function renderPack(lines, year) {
        var card = $('mw-foreman-pack');
        if (!lines || !lines.length) { card.hidden = true; return; }
        $('mw-foreman-pack-year').textContent = year ? '(' + year + ')' : '';
        card.querySelector('.mw-charlie-pack-list').innerHTML = lines.map(function (l) {
            var u = CI.safeUrl(l.url);
            return '<li class="' + (l.bad ? 'is-bad' : '') + '">' + (u ? '<a href="' + esc(u) + '">' + esc(l.text) + '</a>' : esc(l.text)) + '</li>';
        }).join('');
        card.hidden = false;
    }

    function renderRules(rules, rulings, paymentMin) {
        var box = $('mw-foreman-rules');
        if (!rules) { box.innerHTML = '<div class="mw-charlie-empty">Rules need migration 1172.</div>'; return; }
        box.innerHTML = rules.map(function (r) {
            var live = r.phase === 'now';
            var params = Object.keys(r.params || {}).map(function (k) {
                var label = { late_days: 'Days late', window_days: 'Days between messages' }[k] || k;
                return '<label>' + esc(label) + '<input type="number" class="form-control" min="1" max="120" data-param="' + esc(k) + '" value="' + (+r.params[k]) + '"></label>';
            }).join('');
            return '<div class="mw-charlie-rule' + (live ? '' : ' is-later') + '" data-slug="' + esc(r.slug) + '">'
                + '<div class="mw-charlie-dl-hd"><b>' + esc(r.title) + '</b>' + (live ? '' : '<span class="mw-charlie-dl-when">Later</span>') + '</div>'
                + '<div>' + esc(String(r.sentence).replace(r.title + ': ', '')) + '</div>'
                + (r.escalate_text ? '<div class="mw-charlie-note">Goes anyway: ' + esc(r.escalate_text) + '</div>' : '')
                + (+r.never_escalate ? '<div class="mw-charlie-note">Never escalates.</div>' : '')
                + (live ? '<div class="mw-charlie-rule-edit"><label class="mw-charlie-check"><input type="checkbox" data-enabled' + (r.enabled ? ' checked' : '') + '> On</label>'
                    + params + '<button type="button" class="mw-charlie-btn is-primary" data-save-rule>Save</button></div>'
                    : '<div class="mw-charlie-note">Waiting for: ' + esc(r.needs || '') + '</div>')
                + '</div>';
        }).join('');
        $('mw-foreman-rulings').innerHTML = (rulings || []).length ? '<table class="table table-sm mw-charlie-table"><thead><tr><th>Day</th><th>Rule</th><th>What</th><th>Ruling</th></tr></thead><tbody>'
            + rulings.map(function (r) {
                return '<tr><td>' + esc(r.ruling_date) + '</td><td>' + esc(r.rule_slug) + '</td><td>' + esc(r.reason) + '</td><td>'
                    + esc(r.verdict) + (r.overridden_at ? ' · overridden' : '') + '</td></tr>';
            }).join('') + '</tbody></table>' : '<div class="mw-charlie-empty">No rulings yet.</div>';
        var pm = document.querySelector('#mw-foreman-settings [name="payment_min"]');
        if (pm && paymentMin != null) pm.value = Math.round(paymentMin);
    }

    function renderHistory(rows) {
        $('mw-foreman-history').innerHTML = (rows || []).length ? '<table class="table table-sm mw-charlie-table"><thead><tr><th>Deadline</th><th>Due</th><th>What happened</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td>' + esc(r.title) + '</td><td>' + esc(r.due_date) + '</td><td>' + (r.status === 'done' ? 'Done ' : 'Skipped ')
                    + esc((r.done_at || '').slice(0, 10)) + (r.note ? ' — ' + esc(r.note) : '') + '</td></tr>';
            }).join('') + '</tbody></table>' : '<div class="mw-charlie-empty">Nothing marked done yet.</div>';
    }

    function renderQuestions(list) {
        var qs = $('mw-foreman-qs');
        if (!list || !list.length) { qs.hidden = true; return; }
        qs.innerHTML = list.map(function (q) {
            return '<div class="mw-charlie-q" data-id="' + q.id + '"><p>' + esc(q.question) + '</p><div class="mw-charlie-qbtns">'
                + q.options.map(function (o) { return '<button type="button" data-answer="' + esc(o.answer) + '">' + esc(o.label) + '</button>'; }).join('')
                + '</div></div>';
        }).join('');
        qs.hidden = false;
    }

    function load() {
        return fetch(API + '?mode=calendar', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) { say((d && d.error) || "Charlie can't load the calendar right now."); return; }
            data = d;
            renderDeadlines(d.deadlines);
            renderPack(d.pack, d.pack_year);
            renderRules(d.rules, d.rulings, d.payment_min);
            renderHistory(d.history);
            renderQuestions(d.questions);
            if (d.inbox) CI.render($('mw-foreman-rows'), $('mw-foreman-held'), d.inbox, say, load, 0);
            else $('mw-foreman-rows').innerHTML = '<div class="mw-charlie-empty">Decisions need migrations 1172 and 1173.</div>';
            if (location.hash && location.hash.indexOf('#d-') === 0) {
                var el = document.getElementById(location.hash.slice(1));
                if (el) { el.classList.add('is-target'); el.scrollIntoView({ block: 'center' }); }
            }
        }).catch(function () { say("Charlie can't load the calendar right now."); });
    }

    // ── Deadlines: buttons and the form ──────────────────────────────────────
    function showFields() {
        var rep = F.repeat.value;
        form.querySelectorAll('[data-for]').forEach(function (l) { l.hidden = l.getAttribute('data-for').split(' ').indexOf(rep) < 0; });
    }
    function fill(d) {
        form.reset();
        F.id.value = d ? d.id : '';
        $('mw-foreman-form-title').textContent = d ? 'Edit: ' + d.title : 'Add a deadline';
        if (d) {
            F.title.value = d.title;
            F.category.value = d.category;
            F.lead_days.value = d.lead_days;
            F.prepare.value = d.prepare || '';
            F.amount_hint.value = d.amount_hint || '';
            F.active.checked = !!+d.active;
            var m = /^(annual|dates|monthly|every|once):(.+)$/.exec(d.rule || '');
            F.repeat.value = m ? m[1] : '';
            if (m && m[1] === 'annual') F.date.value = (d.due_date || (new Date().getFullYear() + '-' + m[2].replace('last', '28')));
            if (m && m[1] === 'once') F.date.value = m[2];
            if (m && m[1] === 'dates') F.dates.value = m[2].split(',').join(', ');
            if (m && m[1] === 'monthly') F.day.value = m[2];
            if (m && m[1] === 'every') { F.months.value = parseInt(m[2], 10); F.anchor_date.value = d.anchor_date || ''; }
            form.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
        showFields();
    }
    F.repeat.addEventListener('change', showFields);
    $('mw-foreman-form-reset').addEventListener('click', function () { fill(null); });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = { mode: 'deadline_save', active: F.active.checked ? 1 : 0 };
        ['id', 'title', 'category', 'repeat', 'date', 'dates', 'day', 'months', 'anchor_date', 'lead_days', 'prepare', 'amount_hint']
            .forEach(function (k) { body[k] = F[k].value; });
        post(body).then(function (r) { say(r.message); if (r.ok) { fill(null); load(); } });
    });
    $('mw-foreman-deadlines').addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        if (b.hasAttribute('data-edit')) {
            var d = (data.deadlines || []).filter(function (x) { return String(x.id) === b.getAttribute('data-edit'); })[0];
            fill(d);
            return;
        }
        b.disabled = true;
        post({ mode: 'deadline_mark', occurrence_id: +b.getAttribute('data-occ'), what: b.getAttribute('data-mark'), days: 3 })
            .then(function (r) { say(r.message); load(); });
    });

    // ── Rules and settings ────────────────────────────────────────────────────
    $('mw-foreman-rules').addEventListener('click', function (e) {
        var b = e.target.closest('[data-save-rule]');
        if (!b) return;
        var card = b.closest('[data-slug]');
        var params = {};
        card.querySelectorAll('[data-param]').forEach(function (i) { params[i.getAttribute('data-param')] = +i.value; });
        post({ mode: 'rule_save', slug: card.getAttribute('data-slug'), enabled: card.querySelector('[data-enabled]').checked ? 1 : 0, params: params })
            .then(function (r) { say(r.message); load(); });
    });
    $('mw-foreman-settings').addEventListener('submit', function (e) {
        e.preventDefault();
        post({ mode: 'settings_save', payment_min: this.payment_min.value }).then(function (r) { say(r.message); });
    });
    $('mw-foreman-qs').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-answer]');
        if (!b) return;
        post({ mode: 'answer', question_id: +b.closest('.mw-charlie-q').getAttribute('data-id'), answer: b.getAttribute('data-answer') })
            .then(function (r) { say(r.message); load(); });
    });

    // Open the tab a link points at (#decisions from the card).
    if (location.hash === '#decisions' && window.jQuery) window.jQuery('a[href="#decisions"]').tab('show');
    fill(null);
    load();
})();
