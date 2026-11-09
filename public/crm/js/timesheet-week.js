/**
 * Timesheets, the week grid (/crm/timeclock/timesheet-week.php). Ittai v1.2 styling lives in mowology-brand.css (.it-ts).
 *
 * Click a person to see their days; click a day cell to jump to that day. Every shift and job time can be changed,
 * removed, or a shift added. An approved week asks why first, reopens the week for approval (/crm/api/timesheet-reopen.php)
 * and then makes the change. Nothing looks saved until the server says so: the page reloads from the server afterwards.
 * Built with DOM calls and textContent only.
 */
(function () {
    'use strict';
    var root = document.getElementById('it-ts');
    if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    var canEdit = root.getAttribute('data-can-edit') === '1';
    var KEY = 'it-ts-open';

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function addDays(d, n) {
        var p = d.split('-');
        return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)).toISOString().slice(0, 10);
    }
    function post(url, data) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: csrf }, data))
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'The server sent something unexpected. Nothing was changed.' }; });
        }).catch(function () { return { success: false, error: 'No connection. Nothing was changed.' }; });
    }

    // ── open / close, remembered across the reload that follows a save ─────────────────
    function saved() { try { return JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } }
    function remember(user, day) { try { var s = saved(); s.users = s.users || {}; s.users[user] = true; if (day) s.day = day; sessionStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* private mode */ } }
    function forget(user) { try { var s = saved(); if (s.users) delete s.users[user]; sessionStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* ignore */ } }

    function setOpen(person, open) {
        var btn = person.querySelector('.it-ts-who');
        var detail = person.querySelector('.it-ts-detail');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        detail.hidden = !open;
        person.classList.toggle('is-open', open);
        if (open) remember(person.getAttribute('data-user')); else forget(person.getAttribute('data-user'));
    }
    function focusDay(person, day) {
        setOpen(person, true);
        var block = person.querySelector('.it-ts-dayblock[data-day="' + day + '"]');
        if (!block) return;
        remember(person.getAttribute('data-user'), day);
        block.classList.add('is-focus');
        block.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        setTimeout(function () { block.classList.remove('is-focus'); }, 1600);
    }

    var persons = Array.prototype.slice.call(root.querySelectorAll('.it-ts-person'));
    persons.forEach(function (person) {
        person.querySelector('.it-ts-who').addEventListener('click', function () {
            setOpen(person, person.querySelector('.it-ts-who').getAttribute('aria-expanded') !== 'true');
        });
        Array.prototype.forEach.call(person.querySelectorAll('.it-ts-cell'), function (c) {
            c.addEventListener('click', function () { focusDay(person, c.getAttribute('data-day')); });
        });
    });
    var s0 = saved();
    persons.forEach(function (person) { if (s0.users && s0.users[person.getAttribute('data-user')]) setOpen(person, true); });
    if (s0.day) {
        var first = root.querySelector('.it-ts-person.is-open .it-ts-dayblock[data-day="' + s0.day + '"]');
        if (first) first.scrollIntoView({ block: 'nearest' });
    }
    if (!canEdit) return;

    // ── editors ──────────────────────────────────────────────────────────────────────────
    function field(label, input, hint) {
        var w = el('label', 'it-ts-field');
        w.appendChild(el('span', 'it-ts-field-l', label));
        w.appendChild(input);
        if (hint) w.appendChild(el('span', 'it-ts-field-h', hint));
        return w;
    }
    function input(type, value, extra) {
        var i = el('input', 'it-ts-input');
        i.type = type;
        if (value !== undefined && value !== null) i.value = value;
        if (extra) Object.keys(extra).forEach(function (k) { i.setAttribute(k, extra[k]); });
        return i;
    }

    function panel(person, afterEl, title, buildFields, onSave, saveLabel) {
        closePanels(person);
        var p = el('form', 'it-ts-panel');
        p.noValidate = true;
        p.appendChild(el('h4', 'it-ts-panel-t', title));
        var body = el('div', 'it-ts-panel-body');
        var api = buildFields(body) || {};
        p.appendChild(body);
        var locked = person.getAttribute('data-locked') === '1';
        var reason = null;
        if (locked) {
            reason = input('text', '', { maxlength: '200', placeholder: 'For example: timer ran overnight' });
            p.appendChild(field('Why is the approved week being changed?', reason, 'It is kept with the week. The week goes back to waiting for approval.'));
        }
        var err = el('p', 'it-ts-error', '');
        err.setAttribute('role', 'alert');
        err.hidden = true;
        p.appendChild(err);
        var acts = el('div', 'it-ts-panel-acts');
        var save = el('button', 'it-ts-btn it-ts-btn-primary', saveLabel || 'Save');
        save.type = 'submit';
        var cancel = el('button', 'it-ts-btn', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { p.remove(); });
        acts.appendChild(save);
        acts.appendChild(cancel);
        p.appendChild(acts);
        p.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') p.remove(); });
        p.addEventListener('submit', function (ev) {
            ev.preventDefault();
            err.hidden = true;
            var why = reason ? reason.value.trim() : '';
            if (locked && why.length < 3) { err.textContent = 'Say why the approved week is being changed.'; err.hidden = false; reason.focus(); return; }
            var job = onSave(api);
            if (typeof job === 'string') { err.textContent = job; err.hidden = false; return; }
            save.disabled = true;
            save.textContent = 'Saving…';
            var reopened = false;
            var step = locked
                ? post('/crm/api/timesheet-reopen.php', { timesheet_id: parseInt(person.getAttribute('data-timesheet'), 10), reason: why })
                : Promise.resolve({ success: true });
            step.then(function (r) {
                if (!r.success) return r;
                reopened = locked;
                return job();
            }).then(function (r) {
                if (r && r.success) {
                    remember(person.getAttribute('data-user'), afterEl.closest('.it-ts-dayblock').getAttribute('data-day'));
                    window.location.reload();
                    return;
                }
                var msg = (r && r.error) || 'That did not save.';
                // Be plain about what did change: the week was reopened even though this edit did not go through.
                err.textContent = reopened
                    ? 'The week was reopened and is waiting for approval, but this change did not save: ' + msg + ' Reload to see where it stands.'
                    : msg + ' Nothing was changed.';
                err.hidden = false;
                save.disabled = false;
                save.textContent = saveLabel || 'Save';
            });
        });
        afterEl.insertAdjacentElement('afterend', p);
        var firstInput = p.querySelector('input');
        if (firstInput) firstInput.focus();
        return p;
    }
    function closePanels(person) {
        Array.prototype.forEach.call(person.querySelectorAll('.it-ts-panel'), function (p) { p.remove(); });
    }

    function shiftFields(day, vals) {
        return function (body) {
            var a = input('time', vals.start, { step: '60', required: 'required' });
            var b = input('time', vals.end, { step: '60' });
            var nx = input('checkbox');
            nx.checked = !!vals.next;
            var nxw = el('label', 'it-ts-check');
            nxw.appendChild(nx);
            nxw.appendChild(el('span', '', 'Ends the next day'));
            var notes = input('text', vals.notes || '', { maxlength: '200', placeholder: 'Optional' });
            body.appendChild(field('Start', a));
            body.appendChild(field('End', b, vals.running ? 'Still running: leave empty to keep it running.' : ''));
            body.appendChild(nxw);
            body.appendChild(field('Note', notes));
            return { a: a, b: b, nx: nx, notes: notes };
        };
    }
    function shiftValue(day, api, requireEnd) {
        if (!api.a.value) return 'Enter a start time.';
        if (requireEnd && !api.b.value) return 'A shift needs an end time.';
        var endDay = api.nx.checked ? addDays(day, 1) : day;
        if (api.b.value && endDay === day && api.b.value <= api.a.value) return 'The end is not after the start. If it ends the next day, tick that box.';
        return {
            clock_in: day + ' ' + api.a.value + ':00',
            clock_out: api.b.value ? endDay + ' ' + api.b.value + ':00' : '',
            notes: api.notes.value.trim()
        };
    }

    persons.forEach(function (person) {
        var userId = parseInt(person.getAttribute('data-user'), 10);

        Array.prototype.forEach.call(person.querySelectorAll('.it-ts-entry'), function (row) {
            var kind = row.getAttribute('data-kind');
            var id = parseInt(row.getAttribute('data-id'), 10);
            var day = row.closest('.it-ts-dayblock').getAttribute('data-day');
            var editBtn = row.querySelector('.it-ts-act-edit');
            var voidBtn = row.querySelector('.it-ts-act-void');
            if (!editBtn) return;

            editBtn.addEventListener('click', function () {
                if (kind === 'shift') {
                    var vals = { start: row.getAttribute('data-in'), end: row.getAttribute('data-out'), next: row.getAttribute('data-next') === '1',
                                 notes: row.getAttribute('data-notes') || '', running: !row.getAttribute('data-out') };
                    panel(person, row, 'Change this shift', shiftFields(day, vals), function (api) {
                        var v = shiftValue(day, api, !vals.running);
                        if (typeof v === 'string') return v;
                        return function () { return post('/crm/api/time-entry-edit.php', { action: 'edit', entry_id: id, clock_in: v.clock_in, clock_out: v.clock_out, notes: v.notes }); };
                    });
                } else {
                    var total = parseInt(row.getAttribute('data-min'), 10) || 0;
                    panel(person, row, 'Change this job time', function (body) {
                        var h = input('number', Math.floor(total / 60), { min: '0', max: '12', step: '1', inputmode: 'numeric' });
                        var m = input('number', total % 60, { min: '0', max: '59', step: '1', inputmode: 'numeric' });
                        body.appendChild(field('Hours', h));
                        body.appendChild(field('Minutes', m));
                        return { h: h, m: m };
                    }, function (api) {
                        var mins = (parseInt(api.h.value || '0', 10) * 60) + parseInt(api.m.value || '0', 10);
                        if (isNaN(mins) || mins < 0 || mins > 720) return 'Job time is between 0 and 12 hours.';
                        return function () { return post('/crm/api/job-time-entry-edit.php', { action: 'set_duration', entry_id: id, duration_minutes: mins }); };
                    });
                }
            });

            voidBtn.addEventListener('click', function () {
                panel(person, row, kind === 'shift' ? 'Remove this shift?' : 'Remove this job time?', function (body) {
                    body.appendChild(el('p', 'it-ts-panel-text', 'It stops counting toward the week. The row is kept in the audit trail as removed.'));
                }, function () {
                    var url = kind === 'shift' ? '/crm/api/time-entry-edit.php' : '/crm/api/job-time-entry-edit.php';
                    return function () { return post(url, { action: 'void', entry_id: id }); };
                }, 'Remove');
            });
        });

        Array.prototype.forEach.call(person.querySelectorAll('.it-ts-act-add'), function (btn) {
            btn.addEventListener('click', function () {
                var day = btn.getAttribute('data-day');
                panel(person, btn.parentElement, 'Add a shift', shiftFields(day, { start: '08:00', end: '', next: false }), function (api) {
                    var v = shiftValue(day, api, true);
                    if (typeof v === 'string') return v;
                    return function () { return post('/crm/api/time-entry-edit.php', { action: 'add', user_id: userId, clock_in: v.clock_in, clock_out: v.clock_out, notes: v.notes }); };
                }, 'Add shift');
            });
        });
    });
})();
