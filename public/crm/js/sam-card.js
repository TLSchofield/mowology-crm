/**
 * Sam's card (dashboard → department heads deck): follow-ups, leads and questions.
 *
 * One waiting customer at a time: their quotes, the last few emails both ways, and Sam's
 * suggested follow-up — editable — as an email or (with consent) a text. Send · Snooze ·
 * Not now. Nothing goes out without Tim's click; edits teach Sam to write it Tim's way.
 * When the customer wrote back, "Draft a reply" asks Claude for one (Tim's click only).
 * API: /crm/api/sales-head.php (?mode=desk; POST send / park / draft_reply / answer / lead_dismiss).
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-sam-rc');
    if (!root) return;
    var qbox = document.getElementById('mw-sq');
    var lbox = document.getElementById('mw-sam-leads');
    var API = '/crm/api/sales-head.php';
    var NAME = root.getAttribute('data-name') || '';
    var SMS_MAX = 160;

    var queue = [], leads = [], questions = [];
    var idx = 0, busy = false;
    var edits = {};          // card key → {channel, subject, body, sms} while Tim types

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(v) {
        v = Number(v || 0);
        return '$' + (v >= 100 ? Math.round(v).toLocaleString() : v.toFixed(2));
    }
    function ago(d) {
        if (!d) return '';
        var t = new Date(String(d).replace(' ', 'T')), now = new Date();
        if (isNaN(t)) return '';
        // calendar days, not 24-hour blocks: yesterday evening is "yesterday", not "today"
        var days = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(t.getFullYear(), t.getMonth(), t.getDate())) / 86400000);
        return days <= 0 ? 'today' : days === 1 ? 'yesterday' : days + ' days ago';
    }
    function shortDate(d) {
        if (!d) return '';
        var t = new Date(String(d).replace(' ', 'T'));
        return isNaN(t) ? String(d) : t.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    /** Same checks as the server: carriers drop long texts, links and special characters. */
    function smsProblems(t) {
        var p = [];
        if (t.length > SMS_MAX) p.push('over ' + SMS_MAX + ' characters');
        if (/https?:|www\.|\b[a-z0-9-]+\.(ca|com|net|org|io|co|info|biz|app|ly|me)\b/i.test(t)) p.push('no links or web addresses');
        if (/[^\x20-\x7E\n]/.test(t)) p.push('no special characters or emoji');
        if (!t.trim()) p.push('empty');
        return p;
    }

    function load() {
        return fetch(API + '?mode=desk', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { root.innerHTML = '<div class="mw-rc-empty">Sam isn\'t set up yet.</div>'; return; }
                queue = d.queue || []; leads = d.leads || []; questions = d.questions || [];
                if (idx >= queue.length) idx = 0;
                render(); renderLeads(); renderQuestions();
            })
            .catch(function () { root.innerHTML = '<div class="mw-rc-empty">Couldn\'t load Sam\'s list — refresh to try again.</div>'; });
    }

    // ── Questions ────────────────────────────────────────────────────────
    function renderQuestions(msg) {
        if (!qbox) return;
        if (!questions.length && !msg) { qbox.hidden = true; return; }
        qbox.hidden = false;
        qbox.innerHTML = '<div class="mw-pq-head"><b>Sam has ' + (questions.length || 'no more') + ' question' + (questions.length === 1 ? '' : 's') + ' for you</b></div>' +
            (msg ? '<div class="mw-pq-item"><p class="mw-pq-done">' + esc(msg) + '</p></div>' : '') +
            questions.slice(0, 3).map(function (q) {
                return '<div class="mw-pq-item" data-q="' + q.id + '"><p>' + esc(q.question) + '</p><div class="mw-pq-btns">' +
                    '<button type="button" data-a="lost">Lost — stop chasing</button>' +
                    '<button type="button" data-a="keep">Still alive — keep 30 days</button>' +
                    '<button type="button" data-a="won">They said yes</button></div></div>';
            }).join('');
    }
    if (qbox) qbox.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-a]'); if (!b || busy) return;
        var item = b.closest('[data-q]'); busy = true;
        item.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        post({ mode: 'answer', question_id: +item.getAttribute('data-q'), answer: b.getAttribute('data-a') }).then(function (d) {
            busy = false;
            questions = questions.filter(function (q) { return String(q.id) !== item.getAttribute('data-q'); });
            renderQuestions(d && d.message);
            if (d && d.url) window.location.href = d.url;
            if (d && d.ok && b.getAttribute('data-a') === 'keep') load();
        }).catch(function () { busy = false; renderQuestions('Network error — try again.'); });
    });

    // ── Leads ────────────────────────────────────────────────────────────
    function renderLeads(msg) {
        if (!lbox) return;
        if (!leads.length && !msg) { lbox.hidden = true; return; }
        lbox.hidden = false;
        lbox.innerHTML = '<div class="mw-sam-leads-head"><b>New leads</b> <small>best first — likely value, urgency and age</small></div>' +
            (msg ? '<div class="mw-rc-msg">' + esc(msg) + '</div>' : '') +
            leads.slice(0, 5).map(function (l) {
                var tags = [];
                if (l.tier) tags.push(l.tier + ' value');
                if (l.urgency === 'asap') tags.push('wants it soon');
                if (l.source) tags.push(l.source);
                return '<div class="mw-sam-lead' + (l.hot ? ' is-hot' : '') + '" data-l="' + l.id + '">' +
                    '<div class="mw-sam-lead-main"><b>' + esc(l.name) + '</b> · ' + esc(l.services) +
                    (l.address ? ' <small>· ' + esc(l.address) + '</small>' : '') +
                    '<div class="mw-sam-next">' + esc(l.next) + '</div>' +
                    '<small class="mw-sam-meta">' + (l.age_days === 0 ? 'today' : l.age_days + ' day' + (l.age_days === 1 ? '' : 's') + ' old') +
                    (l.value > 0 ? ' · jobs like this average ' + money(l.value) : '') + (tags.length ? ' · ' + esc(tags.join(' · ')) : '') + '</small></div>' +
                    '<div class="mw-sam-lead-act">' +
                    (l.phone ? '<a class="mw-rc-ed" href="tel:' + esc(l.phone.replace(/[^\d+]/g, '')) + '">Call</a>' : '') +
                    '<a class="mw-rc-ok" href="' + esc(l.url) + '">Start quote</a>' +
                    '<button type="button" class="mw-rc-sk" data-dismiss>Not a lead</button></div></div>';
            }).join('');
    }
    if (lbox) lbox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-dismiss]'); if (!b || busy) return;
        var id = +b.closest('[data-l]').getAttribute('data-l'); busy = true;
        post({ mode: 'lead_dismiss', lead_id: id }).then(function (d) {
            busy = false;
            leads = leads.filter(function (l) { return l.id !== id; });
            renderLeads(d && d.message);
        }).catch(function () { busy = false; renderLeads('Network error — try again.'); });
    });

    // ── Follow-up carousel ───────────────────────────────────────────────
    function state(c) {
        if (!edits[c.key]) edits[c.key] = { channel: 'email', subject: c.draft.subject, body: c.draft.body, sms: c.draft.sms, drafted_by: c.draft.drafted_by, suggested_body: c.draft.body, suggested_sms: c.draft.sms };
        return edits[c.key];
    }

    function render(msg) {
        if (!queue.length) {
            root.innerHTML = '<div class="mw-rc-empty">' + (msg ? esc(msg) + '<br>' : '') +
                'Nobody is waiting on a follow-up' + (NAME ? ', ' + esc(NAME) : '') + '. I\'ll bring the next one when it\'s due.</div>';
            return;
        }
        var c = queue[idx], st = state(c);
        var total = queue.reduce(function (s, x) { return s + Number(x.amount || 0); }, 0);
        var replied = c.kind === 'replied';
        var why = replied
            ? '<b>' + esc(c.first_name || c.name) + ' wrote back ' + esc(ago(c.last_in)) + '</b> and hasn\'t heard from us since.'
            : 'No reply for <b>' + c.days + ' days</b>' + (c.followups ? ' · ' + c.followups + ' follow-up' + (c.followups === 1 ? '' : 's') + ' so far' : '') +
              (c.viewed ? ' · they opened it' : '') + '.';

        var quotes = c.quotes.map(function (q) {
            return '<li><a href="/crm/quotes/view.php?id=' + q.id + '">' + esc(q.number) + '</a> · ' + esc(q.service || q.title || 'Quote') +
                (q.address ? ' · ' + esc(q.address) : '') + ' · <b>' + money(q.amount) + '</b>' +
                '<small> sent ' + esc(shortDate(q.sent_at)) + (q.valid_until ? ', good until ' + esc(shortDate(q.valid_until)) : '') + (q.views ? ', viewed ' + q.views + '×' : '') + '</small></li>';
        }).join('');

        var thread = (c.thread || []).length
            ? '<div class="mw-sam-thread">' + c.thread.slice(0, 3).map(function (m) {
                return '<div class="mw-sam-msg ' + (m.dir === 'inbound' ? 'is-in' : 'is-out') + '"><small>' + (m.dir === 'inbound' ? esc(c.first_name || 'Them') : 'Us') +
                    ' · ' + esc(ago(m.at)) + (m.subject ? ' · ' + esc(m.subject) : '') + '</small><div>' + esc(m.snippet || '(no text)') + '</div></div>';
            }).join('') + '</div>'
            : '<div class="mw-sam-thread is-empty"><small>No emails with ' + esc(c.first_name || c.name) + ' in office@ yet.</small></div>';

        var isSms = st.channel === 'sms';
        var text = isSms ? st.sms : st.body;
        var probs = isSms ? smsProblems(text) : [];
        var channelBtns = '<div class="mw-sam-chan" role="group" aria-label="Send as">' +
            '<button type="button" data-ch="email" class="' + (!isSms ? 'is-on' : '') + '">Email' + (c.email ? '' : ' (no address)') + '</button>' +
            '<button type="button" data-ch="sms" class="' + (isSms ? 'is-on' : '') + '"' + (c.sms_ok ? '' : ' disabled title="No mobile number or no consent to texts"') + '>Text</button></div>';

        root.innerHTML =
            '<div class="mw-rc-top"><button type="button" class="mw-rc-arrow" data-nav="-1" aria-label="Previous"' + (queue.length < 2 ? ' disabled' : '') + '>‹</button>' +
            '<span><b>' + (replied ? 'Waiting on you' : 'Follow-up') + ' ' + (idx + 1) + ' of ' + queue.length + '</b> · ' + money(total) + ' across the list</span>' +
            '<button type="button" class="mw-rc-arrow" data-nav="1" aria-label="Next"' + (queue.length < 2 ? ' disabled' : '') + '>›</button></div>' +
            '<div class="mw-sam-slide">' +
              '<div class="mw-sam-who">' +
                '<div class="mw-rc-meta"><b>' + esc(c.name) + '</b>' + (c.company ? ' · ' + esc(c.company) : '') + ' · ' + money(c.amount) + '</div>' +
                '<div class="mw-sam-why' + (replied ? ' is-replied' : '') + '">' + why + '</div>' +
                '<ul class="mw-sam-quotes">' + quotes + '</ul>' + thread +
              '</div>' +
              '<div class="mw-sam-draft">' + channelBtns +
                (isSms ? '' : '<label class="mw-sam-lbl">Subject<input class="mw-sam-in" data-f="subject" value="' + esc(st.subject) + '"></label>') +
                '<label class="mw-sam-lbl">' + (isSms ? 'Text' : 'Email') + (st.drafted_by === 'learned' && !isSms ? ' <small class="mw-sam-learned">written your way</small>' : '') +
                  (st.drafted_by === 'claude' ? ' <small class="mw-sam-learned">drafted by Claude — check it</small>' : '') +
                  '<textarea class="mw-sam-in" data-f="' + (isSms ? 'sms' : 'body') + '" rows="' + (isSms ? 3 : 9) + '">' + esc(text) + '</textarea></label>' +
                (isSms ? '<div class="mw-sam-count' + (probs.length ? ' is-bad' : '') + '">' + text.length + '/' + SMS_MAX + (probs.length ? ' · ' + esc(probs.join(' · ')) : ' · OK for carriers') + '</div>'
                       : '<div class="mw-sam-count">The quote link' + (c.quotes.length > 1 ? 's go' : ' goes') + ' under your message automatically.</div>') +
                '<div class="mw-rc-actions">' +
                  '<button type="button" class="mw-rc-ok" data-act="send"' + (isSms && probs.length ? ' disabled' : '') + '>Send ' + (isSms ? 'text' : 'email') + '</button>' +
                  (replied && !isSms ? '<button type="button" class="mw-rc-ed" data-act="claude">Draft a reply</button>' : '') +
                  '<button type="button" class="mw-rc-ed" data-act="snooze">Snooze a week</button>' +
                  '<button type="button" class="mw-rc-sk" data-act="skip">Not now →</button>' +
                '</div>' +
                '<div class="mw-rc-msg">' + (msg ? esc(msg) : '') + '</div>' +
              '</div>' +
            '</div>';
    }

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f'); if (!f || !queue.length) return;
        var st = state(queue[idx]); st[f] = e.target.value;
        if (f === 'sms') {
            var probs = smsProblems(st.sms), cnt = root.querySelector('.mw-sam-count'), btn = root.querySelector('[data-act="send"]');
            cnt.className = 'mw-sam-count' + (probs.length ? ' is-bad' : '');
            cnt.textContent = st.sms.length + '/' + SMS_MAX + (probs.length ? ' · ' + probs.join(' · ') : ' · OK for carriers');
            btn.disabled = probs.length > 0;
        }
    });

    root.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-nav]');
        if (nav && queue.length) { idx = (idx + Number(nav.getAttribute('data-nav')) + queue.length) % queue.length; render(); return; }
        var ch = e.target.closest('[data-ch]');
        if (ch && !ch.disabled) { state(queue[idx]).channel = ch.getAttribute('data-ch'); render(); return; }
        var b = e.target.closest('[data-act]'); if (!b || busy || !queue.length) return;
        var c = queue[idx], st = state(c), act = b.getAttribute('data-act');
        busy = true;
        root.querySelectorAll('.mw-rc-actions button').forEach(function (x) { x.disabled = true; });

        var req;
        if (act === 'send') {
            var sms = st.channel === 'sms';
            req = post({ mode: 'send', card_key: c.key, channel: st.channel, subject: sms ? '' : st.subject, body: sms ? st.sms : st.body,
                         suggested_subject: c.draft.subject, suggested_body: sms ? st.suggested_sms : st.suggested_body,
                         template: c.template, drafted_by: st.drafted_by });
        } else if (act === 'claude') {
            req = post({ mode: 'draft_reply', card_key: c.key }).then(function (d) {
                busy = false;
                if (d && d.ok) { st.body = d.body; st.suggested_body = d.body; st.drafted_by = 'claude'; render('Here\'s a draft — read it before you send.'); }
                else render(d && d.message ? d.message : 'Couldn\'t draft that one.');
                return null;
            });
        } else {
            req = post({ mode: 'park', card_key: c.key, how: act === 'skip' ? 'skip' : 'snooze', days: 7 });
        }
        req.then(function (d) {
            if (d === null) return;
            busy = false;
            if (d && d.ok) {
                delete edits[c.key];
                queue.splice(idx, 1);
                if (idx >= queue.length) idx = 0;
                render(d.message);
            } else {
                render(d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t work — try again.');
            }
        }).catch(function () { busy = false; render('Network error — nothing was sent. Try again.'); });
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
