/**
 * Sam's card (dashboard → department heads deck): follow-ups, leads and questions.
 *
 * One waiting customer at a time: their quotes, the last few emails both ways, and Sam's
 * suggested follow-up — editable — as an email or (with consent) a text. Send · Snooze ·
 * Not now. Nothing goes out without Tim's click; edits teach Sam to write it Tim's way.
 * When the customer wrote back, "Draft a reply" asks Claude for one (Tim's click only).
 * API: /crm/api/sales-head.php (?mode=desk; POST send / park / draft_reply / answer / lead_dismiss).
 *
 * Ask first (field recommendations, migration 1180): a customer who replied to an Ask-first
 * note gets a card with "Build the quote" — the quote is built on that observation (photos
 * included) and then sent to the billing contact on a second click. Asks with no reply
 * after a week show as one quiet line. POST ask_build / ask_send_quote / ask_close.
 *
 * Replies waiting (UnclaimedReplyService, 'quote' lane): replies about a quote no other list
 * caught — a hand-sent email answered, a text. Other client replies are Yui's inbox
 * (yui-card.js). Open goes to the contact; Handled is Charlie's act/dismiss for the same key
 * (POST /crm/api/charlie.php), so it leaves his list too.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-sam-rc');
    if (!root) return;
    var qbox = document.getElementById('mw-sq');
    var lbox = document.getElementById('mw-sam-leads');
    var abox = document.getElementById('mw-sam-asks');
    var rbox = document.getElementById('mw-sam-replies');
    var replies = [];        // unclaimed customer replies (desk.unclaimed)
    var CHARLIE = '/crm/api/charlie.php';
    var asks = { replied: [], silent: [], drafts: 0 };
    var built = {};          // observation id → ask_build result
    var API = '/crm/api/sales-head.php';
    var NAME = root.getAttribute('data-name') || '';
    var SMS_MAX = 160;

    var queue = [], leads = [], questions = [];
    var texts = null;        // messages bridge heartbeat {at, silent, minutes}, null = not set up
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
                queue = d.queue || []; leads = d.leads || []; questions = d.questions || []; texts = d.texts || null;
                asks = d.asks || { replied: [], silent: [], drafts: 0 };
                replies = d.unclaimed || [];
                if (idx >= queue.length) idx = 0;
                render(); renderLeads(); renderQuestions(); renderAsks(); renderReplies();
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

    // ── Ask first ────────────────────────────────────────────────────────
    function renderAsks(msg) {
        if (!abox) return;
        var rep = asks.replied || [], sil = asks.silent || [], drafts = asks.drafts || 0;
        if (!rep.length && !sil.length && !drafts && !msg) { abox.hidden = true; return; }
        abox.hidden = false;
        var html = '<div class="mw-sam-leads-head"><b>Asked first</b> <small>photo notes from the field, no price — the quote comes after a yes</small></div>';
        if (msg) html += '<div class="mw-rc-msg">' + esc(msg) + '</div>';
        if (drafts) {
            html += '<div class="mw-sam-ask-drafts">' + drafts + ' note' + (drafts === 1 ? '' : 's') + ' from the crew waiting for you to read and send · ' +
                '<a href="/crm/products/recommendations.php?tab=ask">Open them →</a></div>';
        }
        html += rep.map(function (a) {
            var b = built[a.observation_id], r = a.reply || {};
            var who = r.from_billing ? (a.billing_name || 'The billing contact') : a.asked_first;
            var act;
            if (b && b.ok) {
                act = '<div class="mw-sam-ask-done">' + esc(b.message) + '</div><div class="mw-sam-ask-act">' +
                    (b.sendable && b.to_email ? '<button type="button" class="mw-rc-ok" data-ask="send">Send quote to ' + esc((b.to_name || '').split(' ')[0] || 'them') + '</button>' : '') +
                    '<a class="mw-rc-ed" href="' + esc(b.url) + '">Open quote ' + esc(b.number || '') + '</a></div>';
            } else {
                act = '<div class="mw-sam-ask-act">' +
                    '<label class="mw-sam-ask-price">$<input type="number" min="0" step="1" inputmode="decimal" data-price placeholder="Price" aria-label="Price (optional)"></label>' +
                    '<button type="button" class="mw-rc-ok" data-ask="build">Build the quote</button>' +
                    '<button type="button" class="mw-rc-sk" data-ask="close">Not now</button></div>';
            }
            return '<div class="mw-sam-ask is-replied" data-o="' + a.observation_id + '">' +
                '<div class="mw-sam-ask-main"><div><b>' + esc(who) + ' replied</b> about ' + esc(a.service) + ' at ' + esc(a.place) +
                ' <small>· ' + esc(ago(r.at)) + (a.photos ? ' · ' + a.photos + ' photo' + (a.photos === 1 ? '' : 's') + ' sent' : '') + '</small></div>' +
                '<div class="mw-sam-msg is-in"><small>' + esc(who) + (r.channel === 'sms' ? ' · text' : (r.subject ? ' · ' + esc(r.subject) : '')) + '</small><div>' +
                esc(r.snippet || '(no text)') + '</div></div>' +
                '<small class="mw-sam-meta">Quote goes to ' + esc(a.billing_name || 'the billing contact') + ' for sign-off.</small></div>' + act + '</div>';
        }).join('');
        html += sil.slice(0, 5).map(function (a) {
            return '<div class="mw-sam-ask is-silent" data-o="' + a.observation_id + '"><div class="mw-sam-ask-main"><div>No reply yet: ' + esc(a.service) + ' at ' + esc(a.place) +
                ' <small>· asked ' + esc(a.asked_first) + ' ' + esc(ago(a.asked_at)) + '</small></div></div>' +
                '<div class="mw-sam-ask-act"><button type="button" class="mw-rc-sk" data-ask="close">Not now</button></div></div>';
        }).join('');
        abox.innerHTML = html;
    }
    if (abox) abox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ask]'); if (!b || busy) return;
        var row = b.closest('[data-o]'), id = +row.getAttribute('data-o'), act = b.getAttribute('data-ask');
        var body = { observation_id: id };
        if (act === 'build') {
            var p = row.querySelector('[data-price]');
            if (p && p.value !== '') body.price = p.value;
        }
        busy = true;
        row.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        post(Object.assign({ mode: act === 'build' ? 'ask_build' : act === 'send' ? 'ask_send_quote' : 'ask_close' }, body)).then(function (d) {
            busy = false;
            if (act === 'build' && d && d.ok) { built[id] = d; renderAsks(); return; }
            if (d && d.ok) {
                asks.replied = asks.replied.filter(function (a) { return a.observation_id !== id; });
                asks.silent = asks.silent.filter(function (a) { return a.observation_id !== id; });
                delete built[id];
            }
            renderAsks(d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t work — try again.');
        }).catch(function () { busy = false; renderAsks('Network error — nothing was sent. Try again.'); });
    });

    // ── Replies waiting ──────────────────────────────────────────────────
    function renderReplies(msg) {
        if (!rbox) return;
        if (!replies.length && !msg) { rbox.hidden = true; return; }
        rbox.hidden = false;
        rbox.innerHTML = '<div class="mw-sam-leads-head"><b>Replies waiting</b> <small>replies about a quote that haven\'t heard from us</small></div>' +
            (msg ? '<div class="mw-rc-msg">' + esc(msg) + '</div>' : '') +
            replies.slice(0, 6).map(function (r) {
                return '<div class="mw-sam-reply' + (r.yes ? ' is-yes' : '') + '" data-k="' + esc(r.key) + '">' +
                    '<div class="mw-sam-reply-main">' + (r.yes ? '<span class="mw-sam-yes">Yes</span> ' : '') +
                    '<b>' + esc(r.name) + '</b> ' + (r.channel === 'sms' ? 'texted' : 'replied') +
                    (r.quote ? ' <q>' + esc(r.quote) + '</q>' : '') +
                    '<small class="mw-sam-meta">' + (r.channel !== 'sms' && r.subject ? esc(r.subject) + ' · ' : '') + esc(ago(r.at)) + '</small></div>' +
                    '<div class="mw-sam-lead-act"><a class="mw-rc-ed" href="' + esc(r.url) + '">Open</a>' +
                    '<button type="button" class="mw-rc-sk" data-handled>Handled</button></div></div>';
            }).join('');
    }
    function charlieDismiss(key) {
        var body = JSON.stringify({ mode: 'act', key: key, what: 'dismiss', csrf_token: window.MW_CSRF_TOKEN || '' });
        var send = function () {
            return fetch(CHARLIE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body })
                .then(function (r) { return r.json(); });
        };
        // Charlie only knows an item once his list has been read today; read it, then retry once.
        return send().then(function (d) {
            if (d && d.ok) return d;
            return fetch(CHARLIE + '?mode=today', { cache: 'no-store' }).then(function () { return send(); });
        });
    }
    if (rbox) rbox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-handled]'); if (!b || busy) return;
        var key = b.closest('[data-k]').getAttribute('data-k'); busy = true; b.disabled = true;
        charlieDismiss(key).then(function (d) {
            busy = false;
            if (d && d.ok) {
                replies = replies.filter(function (r) { return r.key !== key; });
                renderReplies('Marked handled.');
            } else {
                b.disabled = false;
                renderReplies(d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t work — try again.');
            }
        }).catch(function () { busy = false; renderReplies('Network error — try again.'); });
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
                var isText = m.channel === 'sms';
                return '<div class="mw-sam-msg ' + (m.dir === 'inbound' ? 'is-in' : 'is-out') + '"><small>' + (m.dir === 'inbound' ? esc(c.first_name || 'Them') : 'Us') +
                    ' · ' + (isText ? 'text · ' : '') + esc(ago(m.at)) + (!isText && m.subject ? ' · ' + esc(m.subject) : '') + '</small><div>' + esc(m.snippet || '(no text)') + '</div></div>';
            }).join('') + '</div>'
            : '<div class="mw-sam-thread is-empty"><small>No emails' + (texts ? ' or texts' : '') + ' with ' + esc(c.first_name || c.name) + ' yet.</small></div>';
        if (texts && texts.silent) {
            thread += '<div class="mw-sam-thread is-empty"><small>The Mac hasn\'t sent texts for ' +
                (texts.minutes < 2880 ? Math.round(texts.minutes / 60) + ' hours' : Math.round(texts.minutes / 1440) + ' days') +
                ' — it may be asleep, or lost Full Disk Access.</small></div>';
        }

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
