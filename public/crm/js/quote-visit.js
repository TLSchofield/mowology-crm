/**
 * quote-visit.js — book a quote visit from the quote workflow page (Tim, 2026-10-15).
 * The card under the request: "Book quote visit" → day / time / who / length / note → Save.
 * Once booked: when and who, Move, Done, Cancel, and Send confirmation (email, and text only when the
 * client has agreed to texts). Nothing reaches the client unless that button is clicked.
 * Server: /crm/api/appointments.php (AppointmentService). Shows on the schedule beside the job stops.
 */
(function () {
    'use strict';
    var box = document.getElementById('mwQuoteVisit');
    if (!box) return;
    var API = '/crm/api/appointments.php';
    var rid = box.getAttribute('data-request-id');
    var csrf = box.getAttribute('data-csrf');
    var $ = function (sel) { return box.querySelector(sel); };
    var booked = $('[data-qv-booked]'), openBtn = $('[data-qv-open]'), form = $('[data-qv-form]'), msg = $('[data-qv-msg]');
    var state = { appt: null, canText: false, canEdit: false };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function say(text, bad) {
        msg.hidden = !text;
        msg.textContent = text || '';
        msg.classList.toggle('mw-qv-msg-bad', !!bad);
    }
    function post(body) {
        body.csrf_token = csrf;
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    // 6:00 am – 7:45 pm in 15-minute steps
    var timeSel = $('[data-qv-time]');
    for (var m = 6 * 60; m < 20 * 60; m += 15) {
        var h = Math.floor(m / 60), mm = m % 60;
        var v = (h < 10 ? '0' : '') + h + ':' + (mm < 10 ? '0' : '') + mm;
        var lab = ((h + 11) % 12 + 1) + ':' + (mm < 10 ? '0' : '') + mm + (h < 12 ? ' am' : ' pm');
        timeSel.insertAdjacentHTML('beforeend', '<option value="' + v + '">' + lab + '</option>');
    }
    timeSel.value = '08:00';

    function setDate(iso) {
        // MwDatePicker follows its hidden input's `change` (label + selected day)
        var input = document.getElementById('mwQvDate');
        input.value = iso;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    var badge = document.getElementById('mwQuoteVisitBadge');   // in the card header: seen while the details are folded
    function render() {
        var a = state.appt;
        if (badge) { badge.hidden = !a; badge.textContent = a ? 'Visit ' + a.label : ''; }
        form.hidden = true;
        openBtn.hidden = !!a || !state.canEdit;
        booked.hidden = !a;
        if (!a) { booked.innerHTML = ''; return; }
        var html = '<div class="mw-qv-when"><i data-feather="calendar"></i> <strong>Quote visit ' + esc(a.label) + '</strong>'
                 + (a.assigned_name ? ' · ' + esc(a.assigned_name) : '')
                 + ' · ' + esc(a.duration_minutes) + ' min'
                 + (a.confirmed ? ' <span class="mw-qv-tag">Client confirmed by message</span>' : '') + '</div>';
        if (a.notes) html += '<div class="mw-qv-notes">' + esc(a.notes) + '</div>';
        if (state.canEdit) {
            html += '<div class="mw-qv-actions">'
                  + '<button type="button" class="btn btn-sm btn-outline-secondary" data-qv-move>Move</button>'
                  + '<button type="button" class="btn btn-sm btn-outline-success" data-qv-done>Done</button>'
                  + '<button type="button" class="btn btn-sm btn-outline-danger" data-qv-cancel>Cancel visit</button>'
                  + '</div>'
                  + '<div class="mw-qv-confirm">'
                  + '<span class="mw-qv-confirm-label">Send the client a confirmation:</span>'
                  + '<label><input type="checkbox" data-qv-c-email' + (a.contact_email ? ' checked' : ' disabled') + '> Email'
                  + (a.contact_email ? '' : ' (none on file)') + '</label>'
                  + '<label><input type="checkbox" data-qv-c-sms' + (state.canText && a.contact_phone ? '' : ' disabled') + '> Text'
                  + (!a.contact_phone ? ' (no phone)' : (state.canText ? '' : ' (no consent to texts)')) + '</label>'
                  + '<button type="button" class="btn btn-sm btn-success" data-qv-send>Send confirmation</button>'
                  + '</div>';
        }
        booked.innerHTML = html;
        if (window.feather) window.feather.replace();
    }

    function load() {
        fetch(API + '?mode=request&request_id=' + encodeURIComponent(rid), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { say(d.error || 'Could not load the quote visit.', true); return; }
                state.appt = d.appointment; state.canText = !!d.can_text; state.canEdit = !!d.can_edit;
                var who = $('[data-qv-who]');
                who.innerHTML = (d.people || []).map(function (p) {
                    return '<option value="' + esc(p.id) + '"' + (String(p.id) === String(d.me) ? ' selected' : '') + '>' + esc(p.full_name) + '</option>';
                }).join('');
                render();
            })
            .catch(function () { say('Could not load the quote visit.', true); });
    }

    function openForm(a) {
        form.hidden = false; openBtn.hidden = true; say('');
        if (a) {
            setDate(a.date);
            timeSel.value = a.time;
            if (a.assigned_to) $('[data-qv-who]').value = String(a.assigned_to);
            $('[data-qv-len]').value = String(a.duration_minutes);
            $('[data-qv-note]').value = a.notes || '';
        }
    }

    box.addEventListener('click', function (ev) {
        var t = ev.target.closest('button');
        if (!t || !box.contains(t)) return;
        if (t.hasAttribute('data-qv-open')) openForm(null);
        else if (t.hasAttribute('data-qv-move')) { booked.hidden = true; openForm(state.appt); }
        else if (t.hasAttribute('data-qv-cancel-form')) { render(); }
        else if (t.hasAttribute('data-qv-save')) {
            var date = document.getElementById('mwQvDate').value;
            if (!date) { say('Pick a day.', true); return; }
            t.disabled = true;
            post({ mode: 'book', request_id: rid, date: date, time: timeSel.value, assigned_to: $('[data-qv-who]').value,
                   duration_minutes: $('[data-qv-len]').value, notes: $('[data-qv-note]').value })
                .then(function (d) {
                    t.disabled = false;
                    if (!d.ok) { say(d.error || 'Could not save the visit.', true); return; }
                    state.appt = d.appointment; render();
                    say((d.moved ? 'Moved to ' : 'Booked for ') + d.appointment.label + '. It\'s on the schedule.'
                        + (d.moved && d.appointment.contact_email ? ' Send the client the new time below.' : ''));
                })
                .catch(function () { t.disabled = false; say('Could not save the visit.', true); });
        }
        else if (t.hasAttribute('data-qv-done') || t.hasAttribute('data-qv-cancel')) {
            var done = t.hasAttribute('data-qv-done');
            if (!done && !window.confirm('Cancel this quote visit? Nothing is sent to the client.')) return;
            post({ mode: 'status', id: state.appt.appointment_id, status: done ? 'done' : 'cancelled' })
                .then(function (d) {
                    if (!d.ok) { say(d.error || 'Could not update the visit.', true); return; }
                    state.appt = null; render();
                    say(done ? 'Visit marked done.' : 'Visit cancelled. Tell the client if they were expecting you.');
                });
        }
        else if (t.hasAttribute('data-qv-send')) {
            var email = !!(box.querySelector('[data-qv-c-email]') || {}).checked;
            var sms = !!(box.querySelector('[data-qv-c-sms]') || {}).checked;
            if (!email && !sms) { say('Tick email or text first.', true); return; }
            t.disabled = true;
            post({ mode: 'confirm', id: state.appt.appointment_id, email: email, sms: sms })
                .then(function (d) {
                    t.disabled = false;
                    if (!d.ok) { say(d.error || 'Nothing was sent.', true); return; }
                    state.appt.confirmed = true; render();
                    say('Sent by ' + d.sent.join(' and ') + '.' + (d.problems && d.problems.length ? ' Not sent: ' + d.problems.join('; ') + '.' : ''),
                        d.problems && d.problems.length);
                })
                .catch(function () { t.disabled = false; say('Nothing was sent.', true); });
        }
    });

    load();
})();
