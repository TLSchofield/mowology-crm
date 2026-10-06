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
        Promise.all([get('queue'), get('questions')]).then(function (r) {
            queue = (r[0] && r[0].items) || [];
            idx = Math.min(idx, Math.max(0, queue.length - 1));
            renderQuestions((r[1] && r[1].questions) || []);
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
        var labels = { yes: 'Yes', no: 'No', done: "Done — I've added them", skip: 'Leave it', later: 'Later', fine: "That's fine" };
        qBox.innerHTML = '<div class="mw-mia-q-head"><b>Mia has ' + qs.length + ' question' + (qs.length === 1 ? '' : 's') + '</b></div>' +
            qs.map(function (q) {
                return '<div class="mw-mia-q-item" data-q="' + q.id + '"><p>' + esc(q.question) +
                    (q.url ? ' <a href="' + esc(q.url) + '">Open</a>' : '') + '</p><div class="mw-mia-q-btns">' +
                    q.answers.map(function (a) { return '<button type="button" data-a="' + esc(a) + '">' + esc(labels[a] || a) + '</button>'; }).join('') +
                    '</div></div>';
            }).join('');
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
