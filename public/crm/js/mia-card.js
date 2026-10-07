/**
 * Mia's card (dashboard → department heads deck): who to get back in touch with, and the
 * message she drafted for each. One suggestion at a time; the subject and the email are
 * editable in place. Send asks once more ("Send to jane@…?") before anything goes — Mia
 * never sends on her own, and the server checks the consent ledger again at that moment.
 * Email only: marketing texts are off (a text can't carry an unsubscribe link).
 * Skip asks why, and she remembers.
 * Her questions sit above the carousel.
 * API: /crm/api/mia.php (?mode=queue / questions; POST prepare / decide / answer).
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-mia-rc');
    if (!root) return;
    var qBox = document.getElementById('mw-mia-q');
    var cBox = document.getElementById('mw-mia-camp');
    var camp = null;
    var API = '/crm/api/mia.php';
    var KIND = { reconnect: 'Reconnect', seasonal: 'This time last year', pm_quiet: 'Quiet property manager', referral: 'Referral ask', consent_ask: 'Keep in touch (consent)' };
    var SKIPS = [['not_now', 'Not now'], ['talked', 'Already talked'], ['not_fit', 'Not a fit'], ['never', 'Never']];
    var queue = [], idx = 0, busy = false, prepared = false, armed = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function get(mode) {
        return fetch(API + '?mode=' + mode, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load() {
        Promise.all([get('queue'), get('questions'), get('campaign')]).then(function (r) {
            queue = (r[0] && r[0].items) || [];
            idx = Math.min(idx, Math.max(0, queue.length - 1));
            renderQuestions((r[1] && r[1].questions) || []);
            renderCampaign((r[2] && r[2].campaign) || null);
            render();
            if (!prepared && queue.length < 3 && document.visibilityState === 'visible') {
                prepared = true;
                post({ mode: 'prepare' }).then(function (p) {
                    if (p && (p.created || p.questions)) load();
                }).catch(function () {});
            }
        }).catch(function () {
            root.innerHTML = '<div class="mw-mia-empty">Mia couldn\'t load just now. Refresh to try again.</div>';
        });
    }

    function renderQuestions(qs) {
        if (!qBox) return;
        if (!qs.length) { qBox.hidden = true; qBox.innerHTML = ''; return; }
        qBox.hidden = false;
        var labels = { yes: 'Yes', no: 'No', done: "Done — I've added them", skip: 'Leave it', later: 'Later', fine: "That's fine", booked: 'Booked for spring', not_prebook: 'Not a pre-book' };
        qBox.innerHTML = '<div class="mw-mia-q-head"><b>Mia has ' + qs.length + ' question' + (qs.length === 1 ? '' : 's') + '</b></div>' +
            qs.map(function (q) {
                return '<div class="mw-mia-q-item" data-q="' + q.id + '"><p>' + esc(q.question) +
                    (q.url ? ' <a href="' + esc(q.url) + '">Open</a>' : '') + '</p><div class="mw-mia-q-btns">' +
                    q.answers.map(function (a) { return '<button type="button" data-a="' + esc(a) + '">' + esc(labels[a] || a) + '</button>'; }).join('') +
                    '</div></div>';
            }).join('');
    }

    /** Bookings first; opens last and marked rough (Apple Mail opens mail by itself). */
    function results(r) {
        if (!r || !r.sent) return '';
        var money = r.booked_amount ? ' ($' + Math.round(r.booked_amount).toLocaleString() + ' in quotes accepted)' : '';
        return '<div class="mw-mia-camp-nums mw-mia-results">' +
            '<span><b>' + r.booked + '</b> booked' + esc(money) + '</span>' +
            '<span><b>' + r.spring_holds + '</b> spring holds</span>' +
            '<span><b>' + r.quoted + '</b> quoted</span>' +
            '<span><b>' + r.replied + '</b> replied</span>' +
            '<span class="is-soft"><b>' + r.opened + '</b> opened <small>(rough)</small></span>' +
            '</div><p class="mw-mia-camp-why">Counted within ' + r.days + ' days of each email. Spring replies show up as questions above.</p>';
    }

    if (qBox) qBox.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-a]');
        if (!b) return;
        var item = b.closest('[data-q]');
        item.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        post({ mode: 'answer', question_id: +item.getAttribute('data-q'), answer: b.getAttribute('data-a') }).then(function (r) {
            item.innerHTML = '<p class="mw-mia-q-done">' + (r.ok ? 'Thanks — got it.' : esc(r.error || 'That didn\'t save.')) + '</p>';
            setTimeout(function () { item.remove(); if (!qBox.querySelector('[data-q]')) qBox.hidden = true; }, 1500);
        });
    });

    // ── The campaign Mia proposes: nothing goes until Tim approves ──────────
    function renderCampaign(c, msg) {
        camp = c;
        if (!cBox) return;
        if (!c) { cBox.hidden = true; cBox.innerHTML = ''; return; }
        cBox.hidden = false;
        var n = c.counts || {};
        if (c.status === 'approved') {
            var p = c.progress || {};
            cBox.innerHTML = '<div class="mw-mia-camp-head"><b>' + esc(c.name) + '</b> · approved</div>' +
                '<p class="mw-mia-camp-why">' + (p.sent || 0) + ' of ' + (c.recipients || 0) + ' sent' +
                (p.pending ? ' · ' + p.pending + ' waiting for the campaign sender' : '') +
                (p.skipped ? ' · ' + p.skipped + ' skipped (no consent at send time)' : '') + '.</p>' +
                results(c.results) +
                (msg ? '<div class="mw-mia-msg">' + esc(msg) + '</div>' : '');
            return;
        }
        cBox.innerHTML =
            '<div class="mw-mia-camp-head"><b>Campaign for your OK: ' + esc(c.name) + '</b></div>' +
            '<p class="mw-mia-camp-why">' + esc(c.why) + '</p>' +
            hubLines(c) +
            '<div class="mw-mia-camp-nums">' +
              (n.segment
                ? '<span><b>' + (n.clients || 0) + '</b> ' + esc(String(n.segment).replace(/_/g, ' ').replace(/,/g, ' and ')) + '</span>'
                : '<span><b>' + (n.clients || 0) + '</b> current clients</span>' +
                  '<span><b>' + (n.neighbours || 0) + '</b> neighbours of clients</span>') +
              '<span><b>' + (n.consented || 0) + '</b> of ' + (n.considered || 0) + ' pass the consent check</span>' +
              '<span>' + (c.photo ? 'With your before/after photo' : 'No lawn before/after in the portfolio yet') + '</span>' +
            '</div>' +
            (c.photo ? '<div class="mw-mia-camp-photo"><img src="' + esc(c.photo.before) + '" alt="' + esc(c.photo.alt_before || 'Before') + '">' +
                       '<img src="' + esc(c.photo.after) + '" alt="' + esc(c.photo.alt_after || 'After') + '"></div>' : '') +
            '<label class="mw-mia-lbl">Subject<input class="mw-mia-in" data-c="subject" value="' + esc(c.subject) + '"></label>' +
            '<label class="mw-mia-lbl">Email ({{first_name}} becomes each person\'s name)<textarea class="mw-mia-in mw-mia-body" data-c="body" rows="10">' + esc(c.body) + '</textarea></label>' +
            '<div class="mw-mia-actions">' +
              '<button type="button" class="mw-mia-send" data-camp="approve">Approve: send to ' + (n.consented || 0) + ' people</button>' +
              '<button type="button" class="mw-mia-skip" data-camp="dismiss">Not this time</button>' +
            '</div>' +
            '<div class="mw-mia-msg">' + (msg ? esc(msg) : 'Goes out through the campaign sender, with your address and an unsubscribe link in every email.') + '</div>';
        grow(cBox.querySelector('[data-c="body"]'));
    }

    /** Calendar campaigns (migration 1195): when it goes, its follow-ups, last year, and any flags. */
    function hubLines(c) {
        var bits = [];
        if (c.send_window) bits.push('Goes out ' + c.send_window + ', at each person\'s best time');
        if (c.sequence_note) bits.push(c.sequence_note + ' to anyone who hasn\'t answered — your OK covers them');
        var out = bits.length ? '<p class="mw-mia-camp-seq">' + esc(bits.join(' · ')) + '</p>' : '';
        if (c.last_year) out += '<p class="mw-mia-camp-ly">' + esc(c.last_year) + '</p>';
        if (c.flags && c.flags.length) {
            out += '<ul class="mw-mia-camp-flags">' + c.flags.map(function (f) { return '<li>' + esc(f) + '</li>'; }).join('') + '</ul>';
        }
        return out;
    }

    if (cBox) cBox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-camp]');
        if (!b || !camp || busy) return;
        var act = b.getAttribute('data-camp');
        if (act === 'approve' && !b.classList.contains('is-armed')) {
            b.classList.add('is-armed');
            b.textContent = 'Tap again to send to ' + ((camp.counts || {}).consented || 0) + ' people';
            setTimeout(function () { if (b.isConnected) { b.classList.remove('is-armed'); b.textContent = 'Approve: send to ' + ((camp.counts || {}).consented || 0) + ' people'; } }, 6000);
            return;
        }
        busy = true;
        cBox.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        post({ mode: 'campaign_decide', id: camp.id, action: act,
               subject: (cBox.querySelector('[data-c="subject"]') || {}).value, body: (cBox.querySelector('[data-c="body"]') || {}).value })
            .then(function (r) {
                busy = false;
                if (!r || !r.ok) {
                    cBox.querySelectorAll('button').forEach(function (x) { x.disabled = false; });
                    var m = cBox.querySelector('.mw-mia-msg'); if (m) m.textContent = (r && r.error) || 'That didn\'t work — nothing was sent.';
                    return;
                }
                if (act === 'dismiss') { renderCampaign(null); return; }
                get('campaign').then(function (g) { renderCampaign(g.campaign, 'Approved. ' + r.recipients + ' emails are queued for the campaign sender.'); });
            });
    });
    if (cBox) cBox.addEventListener('input', function (e) { if (e.target.getAttribute('data-c') === 'body') grow(e.target); });

    function render(msg) {
        armed = null;
        if (!queue.length) {
            root.innerHTML = '<div class="mw-mia-empty">' + (msg ? esc(msg) + '<br>' : '') +
                'Nobody needs a message from you right now. I\'ll keep watching.</div>';
            return;
        }
        var s = queue[idx];
        var who = s.kind === 'pm_quiet' && s.company ? s.company + (s.name ? ' · ' + s.name : '') : (s.name || 'A past customer');
        root.innerHTML =
            '<div class="mw-mia-rc-top">' +
              '<button type="button" class="mw-mia-arrow" data-go="-1" aria-label="Previous"' + (idx === 0 ? ' disabled' : '') + '>‹</button>' +
              '<span><b>' + (idx + 1) + ' of ' + queue.length + '</b> · ' + esc(KIND[s.kind] || s.kind) + '</span>' +
              '<button type="button" class="mw-mia-arrow" data-go="1" aria-label="Next"' + (idx >= queue.length - 1 ? ' disabled' : '') + '>›</button>' +
            '</div>' +
            '<div class="mw-mia-who"><b>' + esc(who) + '</b>' + (s.url ? ' <a href="' + esc(s.url) + '" target="_blank" rel="noopener">Open</a>' : '') +
              '<div class="mw-mia-why">' + esc(s.why) + '</div>' +
              '<div class="mw-mia-to">To ' + esc(s.email || '—') + (s.address ? ' · ' + esc(s.address) : '') + '</div></div>' +
            '<label class="mw-mia-lbl">Subject<input class="mw-mia-in" data-f="subject" value="' + esc(s.subject) + '"></label>' +
            '<label class="mw-mia-lbl">Email<textarea class="mw-mia-in mw-mia-body" data-f="body" rows="9">' + esc(s.body) + '</textarea></label>' +
            '<div class="mw-mia-nosms">Email only. Sent with Mowology\'s address and an unsubscribe link; consent is checked again when you send.</div>' +
            '<div class="mw-mia-actions">' +
              '<button type="button" class="mw-mia-send" data-do="send">Send email</button>' +
              '<button type="button" class="mw-mia-skip" data-do="skip">Skip…</button>' +
              '<div class="mw-mia-skips" hidden>' + SKIPS.map(function (k) {
                  return '<button type="button" data-skip="' + k[0] + '">' + k[1] + '</button>';
              }).join('') + '</div>' +
            '</div>' +
            '<div class="mw-mia-msg">' + (msg ? esc(msg) : '') + '</div>';
        grow(root.querySelector('.mw-mia-body'));
    }

    function grow(t) { if (t) { t.style.height = 'auto'; t.style.height = Math.min(420, t.scrollHeight + 4) + 'px'; } }
    function field(f) { return root.querySelector('[data-f="' + f + '"]'); }
    function say(m) { var el = root.querySelector('.mw-mia-msg'); if (el) el.textContent = m || ''; }

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f');
        if (!f) return;
        var s = queue[idx];
        if (f === 'subject' || f === 'body') s[f] = e.target.value;
        if (f === 'body') grow(e.target);
        disarm();
    });

    function disarm() {
        if (!armed) return;
        clearTimeout(armed);
        armed = null;
        var b = root.querySelector('[data-do="send"]');
        if (b) { b.textContent = 'Send email'; b.classList.remove('is-armed'); }
    }

    root.addEventListener('click', function (e) {
        var go = e.target.closest('[data-go]');
        if (go && !busy) { idx = Math.max(0, Math.min(queue.length - 1, idx + (+go.getAttribute('data-go')))); render(); return; }
        var skipOpen = e.target.closest('[data-do="skip"]');
        if (skipOpen) { var box = root.querySelector('.mw-mia-skips'); box.hidden = !box.hidden; return; }
        var sk = e.target.closest('[data-skip]');
        if (sk && !busy) { decide({ action: 'skip', reason: sk.getAttribute('data-skip') }, 'Skipped — I\'ll remember why.'); return; }
        var send = e.target.closest('[data-do="send"]');
        if (send && !busy) {
            var s = queue[idx];
            if (!armed) {
                // Second click sends: Tim sees exactly who it goes to first.
                send.textContent = 'Send to ' + (s.email || 'them') + '?';
                send.classList.add('is-armed');
                armed = setTimeout(disarm, 6000);
                return;
            }
            clearTimeout(armed); armed = null;
            decide({ action: 'send', subject: field('subject').value, body: field('body').value }, null);
        }
    });

    function decide(extra, okMsg) {
        var s = queue[idx];
        busy = true;
        root.querySelectorAll('.mw-mia-actions button').forEach(function (b) { b.disabled = true; });
        say(extra.action === 'send' ? 'Sending…' : '');
        var body = { mode: 'decide', id: s.id };
        for (var k in extra) body[k] = extra[k];
        post(body).then(function (r) {
            busy = false;
            if (!r || !r.ok) {
                root.querySelectorAll('.mw-mia-actions button').forEach(function (b) { b.disabled = false; });
                disarm();
                say((r && r.error) || 'That didn\'t work — nothing was sent.');
                return;
            }
            var m = okMsg;
            if (r.status === 'sent') {
                m = 'Sent to ' + (s.name || s.email) + '.' +
                    (r.learned ? ' I\'ve learned your wording for next time.' : '') + (r.note ? ' ' + r.note : '');
            }
            queue.splice(idx, 1);
            idx = Math.min(idx, Math.max(0, queue.length - 1));
            render(m);
        }).catch(function () {
            busy = false;
            disarm();
            root.querySelectorAll('.mw-mia-actions button').forEach(function (b) { b.disabled = false; });
            say('Lost the connection — check the client\'s history before trying again.');
        });
    }

    load();
})();
