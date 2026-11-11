/**
 * Sam's card (dashboard → department heads deck): follow-ups, leads and questions.
 *
 * One waiting customer at a time: their quotes, the last few emails both ways, and Sam's
 * suggested follow-up — editable — as an email or (with consent) a text. Send · Snooze ·
 * Not now. Nothing goes out without Tim's click; edits teach Sam to write it Tim's way.
 * When the customer wrote back, "Draft a reply" asks Claude for one (Tim's click only).
 * API: /crm/api/sales-head.php (?mode=desk; POST send / park / draft_reply / answer / lead_dismiss /
 * maybe_accept / maybe_dismiss — "maybe a lead" enquiries read from Tim's iCloud;
 * request_draft / request_dismiss — deterministic work-request cards).
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
 *
 * "Send me a test push" (POST test_push): the server's diagnosis of the quote-opened push —
 * APNs configured, the user's active iPhone tokens, and Apple's answer per token.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-sam-rc');
    if (!root) return;
    var qsbox = document.getElementById('mw-sq');            // questions (expired quotes)
    var lbox = document.getElementById('mw-sam-leads');
    var qbox = document.getElementById('mw-sam-quiet');      // quiet clients (ClientRhythm, 2026-11-03)
    var nbox = document.getElementById('mw-sam-renewals');   // renewal radar
    var hbox = document.getElementById('mw-sam-hygiene');    // open quotes not fit to chase
    var abox = document.getElementById('mw-sam-asks');
    var rbox = document.getElementById('mw-sam-replies');
    var apbox = document.getElementById('mw-sam-approved');
    var acbox = document.getElementById('mw-sam-acceptances');   // "Looks like an acceptance" (2026-10-15)
    var reqbox = document.getElementById('mw-sam-requests');
    // Hierarchy (2026-11-04): briefing → next → rows by status → the workspace for one customer.
    var sayEl = document.getElementById('mw-sam-say');
    var nextEl = document.getElementById('mw-sam-first');
    var rowsEl = document.getElementById('mw-sam-rows');
    var briefEl = document.getElementById('mw-sam-brief');
    var workEl = document.getElementById('mw-sam-work');
    var backBtn = document.getElementById('mw-sam-back');
    var section = document.getElementById('mw-sam');
    var working = false;     // true while one customer's workspace is open

    /** A collapsed section: its summary carries the title and the count; empty sections disappear.
     *  `open` opens it the first time it has something (decisions open, radar lists stay shut). */
    var opened = {};
    function sec(box, title, count, html, msg, open) {
        if (!box) return;
        var body = box.querySelector('.mw-sam-sec-body') || box, sum = box.querySelector('summary');
        if (!count && !msg) { box.hidden = true; return; }
        box.hidden = false;
        if (sum) sum.innerHTML = esc(title) + (count ? ' <span class="mw-sam-n">' + count + '</span>' : '');
        if (open && !opened[box.id]) { box.open = true; opened[box.id] = true; }
        body.innerHTML = (msg ? '<div class="mw-rc-msg">' + esc(msg) + '</div>' : '') + html;
    }
    var acceptances = [];
    var requests = [];
    var approved = [];       // quotes just approved — every line ✓ / ✗ (QuoteApprovalService)
    var replies = [];        // unclaimed customer replies (desk.unclaimed)
    var CHARLIE = '/crm/api/charlie.php';
    var asks = { replied: [], silent: [], drafts: 0 };
    var built = {};          // observation id → ask_build result
    var API = '/crm/api/sales-head.php';
    var NAME = root.getAttribute('data-name') || '';
    var SMS_MAX = 160;

    var queue = [], leads = [], questions = [];
    var quiet = [], renewals = [], hygiene = [];
    var maybes = [];         // enquiries from email the rules weren't sure about (desk.maybe_leads)
    var texts = null;        // messages bridge heartbeat {at, silent, minutes}, null = not set up
    var idx = 0, busy = false;
    // ?sam=<card key> (from Charlie's list / "Needs you now"): open on that customer, their emails showing.
    var wanted = (function () { try { return new URLSearchParams(window.location.search).get('sam'); } catch (e) { return null; } })();
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
                quiet = d.quiet || []; renewals = d.renewals || []; hygiene = d.hygiene || [];
                asks = d.asks || { replied: [], silent: [], drafts: 0 };
                replies = d.unclaimed || [];
                maybes = d.maybe_leads || [];
                approved = d.approved || [];
                acceptances = d.acceptances || [];
                requests = d.requests || [];
                var deep = false;
                if (wanted) {
                    for (var w = 0; w < queue.length; w++) if (queue[w].key === wanted) { idx = w; deep = true; break; }
                    wanted = null;   // once: later reloads keep the card the owner moved to
                    if (section && section.scrollIntoView) setTimeout(function () { section.scrollIntoView({ block: 'start' }); }, 0);
                }
                if (idx >= queue.length) idx = 0;
                renderBrief(); renderNext(); renderRows();
                if (deep) openWork(idx);
                if (working) render();
                renderLeads(); renderQuestions(); renderAsks(); renderReplies(); renderApproved(); renderRequests(); renderAcceptances(); renderQuiet(); renderRenewals(); renderHygiene();
            })
            .catch(function () { rowsEl.innerHTML = '<div class="mw-rc-empty">Couldn\'t load Sam\'s list. Refresh to try again.</div>'; });
    }

    // ── Briefing, next action, rows ──────────────────────────────────────
    /** One sentence in Sam's voice: the money, who wrote back, what comes after. */
    function renderBrief() {
        if (!sayEl) return;
        var replied = queue.filter(function (c) { return c.kind === 'replied'; });
        var chase = queue.length - replied.length;
        var total = queue.reduce(function (s, x) { return s + Number(x.amount || 0); }, 0);
        var parts = [];
        if (queue.length) parts.push('<b>' + money(total) + '</b> is waiting on ' + queue.length + ' customer' + (queue.length === 1 ? '' : 's') + '.');
        else parts.push('Nobody is waiting on a follow-up' + (NAME ? ', ' + esc(NAME) : '') + '.');
        if (replied.length) {
            var names = replied.slice(0, 2).map(function (c) { return esc(c.first_name || c.name); });
            parts.push((replied.length === 1 ? names[0] + ' wrote back' : names.join(' and ') + (replied.length > 2 ? ' and ' + (replied.length - 2) + ' more' : '') + ' wrote back') + '. Start there' + (chase ? ', then the ' + chase + ' to chase' : '') + (leads.length ? (chase ? ' and the ' : ', then the ') + leads.length + ' new lead' + (leads.length === 1 ? '' : 's') : '') + '.');
        } else if (queue.length && leads.length) {
            parts.push(leads.length + ' new lead' + (leads.length === 1 ? '' : 's') + ' after that.');
        } else if (leads.length) {
            parts.push(leads.length + ' new lead' + (leads.length === 1 ? '' : 's') + ' to look at.');
        }
        sayEl.innerHTML = parts.join(' ');
    }
    function statusWord(c) {
        return c.kind === 'replied'
            ? '<span class="mw-sam-st is-replied">&#8617; Replied ' + esc(ago(c.last_in)) + '</span>'
            : '<span class="mw-sam-st">&#9711; No reply for ' + c.days + ' day' + (c.days === 1 ? '' : 's') + (c.viewed ? ', opened' : '') + '</span>';
    }
    /** The first card as one row with one button. */
    function renderNext() {
        if (!nextEl) return;
        if (!queue.length) { nextEl.hidden = true; return; }
        var c = queue[0], replied = c.kind === 'replied';
        var q = c.quotes && c.quotes[0];
        nextEl.hidden = false;
        nextEl.innerHTML = '<div class="mw-sam-first-k">Next</div>' +
            '<div class="mw-sam-first-main">' + statusWord(c) +
            '<div class="mw-sam-first-who"><b>' + esc(c.name) + '</b>' + (c.company ? ', ' + esc(c.company) : '') + ' <span class="mw-sam-amt">' + money(c.amount) + '</span></div>' +
            (q ? '<div class="mw-sam-meta">' + esc(q.number) + (q.service || q.title ? ', ' + esc(q.service || q.title) : '') + (c.quotes.length > 1 ? ' and ' + (c.quotes.length - 1) + ' more' : '') + '</div>' : '') +
            '</div>' +
            '<button type="button" class="mw-sam-go" data-open="0">' + (replied ? 'Review and reply' : 'Prepare the follow-up') + '</button>';
    }
    /** Everyone waiting, one row each, grouped by what Sam knows: they wrote back, or they went quiet. */
    function renderRows(msg) {
        if (!rowsEl) return;
        if (!queue.length) {
            rowsEl.innerHTML = msg ? '<div class="mw-rc-msg">' + esc(msg) + '</div>' : '';
            return;
        }
        var groups = [['replied', 'Wrote back'], ['stale', 'To chase']], html = msg ? '<div class="mw-rc-msg">' + esc(msg) + '</div>' : '';
        groups.forEach(function (g) {
            var rows = [];
            queue.forEach(function (c, i) {
                if ((c.kind === 'replied' ? 'replied' : 'stale') !== g[0]) return;
                var q = c.quotes && c.quotes[0];
                rows.push('<button type="button" class="mw-sam-row' + (i === idx && working ? ' is-current' : '') + '" data-open="' + i + '">' +
                    statusWord(c) +
                    '<span class="mw-sam-row-who"><b>' + esc(c.name) + '</b>' + (q ? ' <small>' + esc(q.service || q.title || q.number) + (c.quotes.length > 1 ? ' +' + (c.quotes.length - 1) : '') + '</small>' : '') + '</span>' +
                    '<span class="mw-sam-amt">' + money(c.amount) + '</span><span class="mw-sam-chev" aria-hidden="true">&#8250;</span></button>');
            });
            if (rows.length) html += '<div class="mw-sam-group"><h4>' + g[1] + ' <span class="mw-sam-n">' + rows.length + '</span></h4>' + rows.join('') + '</div>';
        });
        rowsEl.innerHTML = html;
    }
    function openWork(i) {
        idx = Math.max(0, Math.min(i, queue.length - 1));
        working = true;
        if (section) section.classList.add('is-working');
        briefEl.hidden = true; workEl.hidden = false;
        render();
        var first = root.querySelector('textarea, input'); if (first && window.innerWidth > 1100) first.focus({ preventScroll: true });
    }
    function closeWork(msg) {
        working = false;
        if (section) section.classList.remove('is-working');
        workEl.hidden = true; briefEl.hidden = false;
        renderBrief(); renderNext(); renderRows(msg);
    }
    if (briefEl) briefEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-open]'); if (!b || !queue.length) return;
        openWork(+b.getAttribute('data-open'));
    });
    if (backBtn) backBtn.addEventListener('click', function () { closeWork(); });

    // ── Questions ────────────────────────────────────────────────────────
    function renderQuestions(msg) {
        if (!qsbox) return;
        if (!questions.length && !msg) { qsbox.hidden = true; return; }
        qsbox.hidden = false;
        qsbox.innerHTML = '<div class="mw-pq-head"><b>' + (questions.length ? questions.length + ' question' + (questions.length === 1 ? '' : 's') + ' for you' : 'No more questions') + '</b></div>' +
            (msg ? '<div class="mw-pq-item"><p class="mw-pq-done">' + esc(msg) + '</p></div>' : '') +
            questions.slice(0, 3).map(function (q) {
                return '<div class="mw-pq-item" data-q="' + q.id + '"><p>' + esc(q.question) + '</p><div class="mw-pq-btns">' +
                    '<button type="button" data-a="lost">Lost, stop chasing</button>' +
                    '<button type="button" data-a="keep">Still alive, keep 30 days</button>' +
                    '<button type="button" data-a="won">They said yes</button></div></div>';
            }).join('');
    }
    if (qsbox) qsbox.addEventListener('click', function (e) {
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
        var html = '';
        if (drafts) {
            html += '<div class="mw-sam-ask-drafts">' + drafts + ' note' + (drafts === 1 ? '' : 's') + ' from the crew to read and send. ' +
                '<a href="/crm/products/recommendations.php?tab=ask">Open them</a></div>';
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
        sec(abox, 'Asked first', rep.length + sil.length + (drafts ? 1 : 0), html, msg, true);
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

    // ── Just approved ────────────────────────────────────────────────────
    // Every line of an approved quote, ticked or not, so a line the client left out never
    // looks like the system dropped it (owner, 2026-10-10). Stays until "Got it".
    function renderApproved(msg) {
        if (!apbox) return;
        sec(apbox, 'Just approved', approved.length, approved.map(function (a) {
                var lines = (a.lines || []).map(function (l) {
                    return '<li class="mw-sam-appr-line ' + (l.approved ? 'is-in' : 'is-out') + '">' +
                        '<span class="mw-sam-appr-mark">' + (l.approved ? '✓' : '✗') + '</span> ' + esc(l.label) +
                        ' <b>' + money(l.amount) + '</b>' + (l.approved ? '' : ' <small>not included</small>') + '</li>';
                }).join('');
                var who = a.approver ? 'Approved by ' + esc(a.approver) + (a.via && a.via !== 'approved' ? ' (' + esc(a.via) + ')' : '') + ' · ' : '';
                // Otto's line: the schedule is his job, so he says what he set up (tray job / daily route).
                var sc = a.schedule || {};
                var otto = sc.text
                    ? '<div class="mw-sam-appr-otto"><img src="' + esc(sc.face || '/crm/img/heads/otto.jpg') + '" alt="Otto" width="28" height="28">' +
                      (sc.plan_id ? '<a href="/crm/jobs/view.php?id=' + sc.plan_id + '">' + esc(sc.text) + '</a>' : '<span>' + esc(sc.text) + '</span>') + '</div>'
                    : '';
                var job = '';
                return '<div class="mw-sam-appr' + (a.partial ? ' is-partial' : '') + '" data-q="' + a.quote_id + '">' +
                    '<div class="mw-sam-appr-head"><b>' + esc(a.headline) + '</b></div>' +
                    '<ul class="mw-sam-appr-lines">' + lines + '</ul>' +
                    '<small class="mw-sam-meta">' + who + esc(ago(a.at)) + '</small>' + otto +
                    '<div class="mw-sam-lead-act"><a class="mw-rc-ed" href="/crm/quotes/view.php?id=' + a.quote_id + '">Quote</a>' + job +
                    '<button type="button" class="mw-rc-sk" data-seen>Got it</button></div></div>';
            }).join(''), msg, true);
    }
    if (apbox) apbox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-seen]'); if (!b || busy) return;
        var qid = +b.closest('[data-q]').getAttribute('data-q'); busy = true; b.disabled = true;
        post({ mode: 'approval_seen', quote_id: qid }).then(function (d) {
            busy = false;
            approved = approved.filter(function (a) { return a.quote_id !== qid; });
            renderApproved(d && d.ok ? '' : 'That didn\'t work — try again.');
        }).catch(function () { busy = false; b.disabled = false; renderApproved('Network error — try again.'); });
    });

    // ── Looks like a request ─────────────────────────────────────────────
    function requestPrecedent(p) {
        p = p || {};
        if (p.source === 'property_jobber' || p.source === 'property_quote') {
            var names = (p.lines || []).map(function (x) { return x.name; }).filter(Boolean).join(', ');
            return 'Last done ' + shortMonth(p.date) + ': ' + esc(names) + ' — ' + money(p.price);
        }
        if (p.source === 'similar') return 'From ' + Number(p.count || 0) + ' similar quotes: ' + money(p.price);
        return 'No price on record';
    }
    function shortMonth(d) {
        if (!d) return '';
        var t = new Date(String(d).replace(' ', 'T'));
        return isNaN(t) ? esc(d) : t.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
    }
    function renderRequests(msg) {
        if (!reqbox) return;
        sec(reqbox, 'Looks like a request', requests.length, requests.map(function (r) {
            var level = r.level === 'sure' ? 'Sure' : r.level === 'likely' ? 'Likely' : 'Not sure';
            var levelClass = r.level === 'sure' ? 'is-green' : r.level === 'likely' ? 'is-amber' : 'is-red';
            var place = r.property ? r.property.address : Number(r.property_count || 0) > 1 ? r.property_count + ' properties — which one?' : 'no property found';
            var canDraft = r.property && r.level !== 'not_sure';
            var detail = r.level === 'not_sure' ? '' :
                '<small class="mw-sam-meta">' + esc(place) + '</small>' +
                '<div class="mw-sam-req-price">' + requestPrecedent(r.precedent) + '</div>' +
                '<q class="mw-sam-req-quote">' + esc(r.snippet || '') + '</q>';
            return '<div class="mw-sam-appr mw-sam-req-item" data-req="' + r.id + '">' +
                '<div class="mw-sam-appr-head"><b>' + esc(r.from) + '</b> wants ' + esc(r.service_label) + ' <span class="mw-charlie-chip ' + levelClass + '">' + level + '</span></div>' +
                detail +
                '<small class="mw-sam-meta">Why: ' + esc((r.reasons || []).join(' · ')) + '</small>' +
                '<div class="mw-sam-lead-act">' + (canDraft ? '<button type="button" class="mw-rc-ok" data-req-draft>Make draft quote</button>' : '') +
                '<button type="button" class="mw-rc-sk" data-req-no>Not a request</button>' +
                '<button type="button" class="mw-rc-sk" data-req-wrong>Wrong service</button></div></div>';
        }).join(''), msg, true);
    }
    if (reqbox) reqbox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-req-draft],[data-req-no],[data-req-wrong]'); if (!b || busy) return;
        var item = b.closest('[data-req]'), id = +item.getAttribute('data-req'); busy = true;
        item.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        var draft = b.hasAttribute('data-req-draft');
        post(draft ? { mode: 'request_draft', id: id } : { mode: 'request_dismiss', id: id, reason: b.hasAttribute('data-req-no') ? 'not_request' : 'wrong_service' })
            .then(function (d) {
                busy = false;
                if (draft && d && d.ok && d.url) { window.location.href = d.url; return; }
                load().then(function () { renderRequests(d && d.message ? d.message : 'OK.'); });
            }).catch(function () { busy = false; renderRequests('Network error — nothing changed. Try again.'); });
    });

    // ── Looks like an acceptance ─────────────────────────────────────────
    // A client email that reads like a yes (signed copies, "approved"): each quote it matched, with its signed PDF.
    // Nothing is accepted until Tim taps Accept (SamAcceptanceService → QuoteAcceptService).
    function renderAcceptances(msg) {
        if (!acbox) return;
        sec(acbox, 'Looks like a yes', acceptances.length, acceptances.map(function (g) {
                var rows = (g.quotes || []).map(function (q) {
                    var file = q.file ? ' · <a href="' + esc(q.file.url) + '" target="_blank" rel="noopener">' + esc(q.file.name) + '</a>' : '';
                    return '<div class="mw-sam-appr" data-acc="' + q.id + '">' +
                        '<div class="mw-sam-appr-head"><b>' + esc(q.number) + '</b> ' + esc(q.title || '') + ' — ' + money(q.amount) + '</div>' +
                        '<small class="mw-sam-meta">' + esc(q.address || '') + file + (q.still_open ? '' : ' · <b>already decided</b>') + '</small>' +
                        '<div class="mw-sam-lead-act"><a class="mw-rc-ed" href="/crm/quotes/view.php?id=' + q.quote_id + '">Quote</a>' +
                        (q.still_open ? '<button type="button" class="mw-rc-ok" data-acc-yes>Accept</button>' : '') +
                        '<button type="button" class="mw-rc-sk" data-acc-no>Not this one</button></div></div>';
                }).join('');
                return '<div class="mw-sam-appr-group"><small class="mw-sam-meta">' + esc(g.from) + ', “' + esc(g.subject || '') + '”, ' + esc(ago(g.sent_at)) + '</small>' + rows + '</div>';
            }).join(''), msg, true);
    }
    if (acbox) acbox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-acc-yes],[data-acc-no]'); if (!b || busy) return;
        var item = b.closest('[data-acc]'), id = +item.getAttribute('data-acc'), yes = b.hasAttribute('data-acc-yes');
        busy = true; item.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        post({ mode: yes ? 'accept_signed' : 'not_acceptance', id: id }).then(function (d) {
            busy = false;
            load().then(function () { renderAcceptances(d && d.message ? d.message : (yes ? 'Accepted.' : 'OK.')); });
        }).catch(function () { busy = false; renderAcceptances('Network error — nothing changed. Try again.'); });
    });

    // ── Replies waiting ──────────────────────────────────────────────────
    function renderReplies(msg) {
        if (!rbox) return;
        sec(rbox, 'Replies waiting', replies.length, replies.slice(0, 6).map(function (r) {
                return '<div class="mw-sam-reply' + (r.yes ? ' is-yes' : '') + '" data-k="' + esc(r.key) + '">' +
                    '<div class="mw-sam-reply-main">' + (r.yes ? '<span class="mw-sam-yes">Yes</span> ' : '') +
                    '<b>' + esc(r.name) + '</b> ' + (r.channel === 'sms' ? 'texted' : 'replied') +
                    (r.quote ? ' <q>' + esc(r.quote) + '</q>' : '') +
                    '<small class="mw-sam-meta">' + (r.channel !== 'sms' && r.subject ? esc(r.subject) + ' · ' : '') + esc(ago(r.at)) + '</small></div>' +
                    (r.objection && r.objection !== 'unclear' ? '<details class="mw-sam-obj"><summary>Reads like a ' + esc(r.objection.replace('_', ' ')) + ' objection. A reply in shape</summary><pre class="mw-sam-obj-draft">' + esc(r.objection_reply || '') + '</pre></details>' : '') +
                    '<div class="mw-sam-lead-act"><a class="mw-rc-ed" href="' + esc(r.url) + '">Open</a>' +
                    '<button type="button" class="mw-rc-sk" data-handled>Handled</button></div></div>';
            }).join(''), msg, true);
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
    function renderQuiet() {
        if (!qbox) return;
        sec(qbox, 'Gone quiet', quiet.length, quiet.map(function (q) {
                var mailto = q.email && q.draft ? 'mailto:' + encodeURIComponent(q.email) + '?subject=' + encodeURIComponent(q.draft.subject) + '&body=' + encodeURIComponent(q.draft.body) : '';
                return '<div class="mw-sam-lead is-' + esc(q.state) + '"><div class="mw-sam-lead-main"><b>' + esc(q.name) + '</b> <span class="mw-sam-bucket">' + esc(q.state) + '</span>' +
                    (q.tier ? ' <span class="mw-sam-heat" title="fit ' + q.tier.fit + ' / engagement ' + q.tier.engagement + ', cutoff ' + q.tier.cutoff + '">tier ' + esc(q.tier.tier) + '</span>' : '') +
                    '<div class="mw-sam-next">' + esc(q.reason) + '</div>' +
                    '<small class="mw-sam-meta">worth ' + money(q.spend_12m) + ' a year' + (q.tier ? '. ' + esc(q.tier.motion) : '') + '</small></div>' +
                    '<div class="mw-sam-lead-act">' + (mailto ? '<a class="mw-rc-ed" href="' + mailto + '" title="Opens a draft in your mail app — nothing is sent until you send it">Check in</a>' : '') +
                    '<a class="mw-rc-ok" href="' + esc(q.url) + '">Open</a></div></div>';
            }).join(''), '', false);
    }
    function renderRenewals() {
        if (!nbox) return;
        sec(nbox, 'Renewals ahead', renewals.length, renewals.map(function (r) {
                return '<div class="mw-sam-lead is-' + esc(r.verdict) + '"><div class="mw-sam-lead-main"><b>' + esc(r.name) + '</b> · ' + esc(r.contract_number) + ' <span class="mw-sam-bucket">' + esc(r.verdict.replace('_', ' ')) + '</span>' +
                    '<div class="mw-sam-next">renews ' + esc(r.renewal_date) + ' (' + r.days_left + ' days)' + (r.risk_up.length ? ': ' + esc(r.risk_up.join(', ')) : '') + '</div>' +
                    '<small class="mw-sam-meta">talk by ' + esc(r.timeline.talk) + ', terms out ' + esc(r.timeline.terms) + ', signed by ' + esc(r.timeline.signed) + '. About ' + money(r.annual_amount) + ' a year</small></div>' +
                    '<div class="mw-sam-lead-act"><a class="mw-rc-ok" href="' + esc(r.url) + '">Open</a></div></div>';
            }).join(''), '', false);
    }
    function renderHygiene() {
        if (!hbox) return;
        sec(hbox, 'Not fit to chase', hygiene.length, hygiene.map(function (q) {
                return '<div class="mw-sam-lead"><div class="mw-sam-lead-main"><b>' + esc(q.quote_number) + '</b> · ' + esc(q.name) + '<div class="mw-sam-next">' + esc(q.why) + '</div></div>' +
                    '<div class="mw-sam-lead-act"><a class="mw-rc-ok" href="' + esc(q.url) + '">Open</a></div></div>';
            }).join(''), '', false);
    }

    function renderLeads(msg) {
        if (!lbox) return;
        var bySignal = leads.length && leads[0].ranked_by === 'signal';
        var html = (leads.length ? '<div class="mw-sam-order">' + (bySignal ? 'Newest and most urgent first. No replies logged yet, so no heat score.' : 'Warmest first: replies, fit and urgency, then likely value.') + '</div>' : '') +
            leads.slice(0, 8).map(function (l) {
                var tags = [];
                if (l.tier) tags.push(l.tier + ' value');
                if (l.urgency === 'asap') tags.push('wants it soon');
                if (l.source) tags.push(l.source);
                if (l.respond_by) tags.push('reply ' + l.respond_by);
                var bucket = l.bucket || 'qualified';
                var chip = bucket === 'hot' ? 'hot' : bucket === 'unclear' ? 'unclear' : bucket === 'out_of_scope' ? 'not a fit' : '';
                var why = (l.reasons || []).slice(0, 3).join(' · ');
                var mailto = l.ask && l.email ? 'mailto:' + encodeURIComponent(l.email) + '?subject=' + encodeURIComponent('Your enquiry') + '&body=' + encodeURIComponent(l.ask) : '';
                var meta = [l.age_days === 0 ? 'today' : l.age_days + ' day' + (l.age_days === 1 ? '' : 's') + ' old'];
                if (l.value > 0) meta.push('jobs like this average ' + money(l.value));
                if (l.visit_day) meta.push(l.visit_day.line);
                return '<div class="mw-sam-lead is-' + esc(bucket) + (l.hot ? ' is-hot' : '') + '" data-l="' + l.id + '" title="' + esc([why].concat(tags).filter(Boolean).join('. ')) + '">' +
                    '<div class="mw-sam-lead-main"><b>' + esc(l.name) + '</b>, ' + esc(l.services) +
                    (l.address ? ' <small>' + esc(l.address) + '</small>' : '') +
                    (chip ? ' <span class="mw-sam-bucket">' + esc(chip) + '</span>' : '') +
                    (!bySignal && typeof l.heat === 'number' ? ' <span class="mw-sam-heat" title="' + esc(why) + '">heat ' + l.heat + '</span>' : '') +
                    '<div class="mw-sam-next">' + esc(l.next) + '</div>' +
                    '<small class="mw-sam-meta">' + esc(meta.join('. ')) + '</small></div>' +
                    '<div class="mw-sam-lead-act">' +
                    (mailto ? '<a class="mw-rc-ed" href="' + mailto + '" title="Opens a draft in your mail app — nothing is sent until you send it">' + (bucket === 'unclear' ? 'Ask' : 'Reply') + '</a>' : '') +
                    (l.phone ? '<a class="mw-rc-ed" href="tel:' + esc(l.phone.replace(/[^\d+]/g, '')) + '">Call</a>' : '') +
                    '<a class="mw-rc-ok" href="' + esc(l.url) + '">Start quote</a>' +
                    '<button type="button" class="mw-rc-sk" data-dismiss>Not a lead</button></div></div>';
            }).join('') +
            (maybes.length ? '<div class="mw-sam-sub">Maybe a lead? Emails that read like a job enquiry.</div>' +
                maybes.slice(0, 5).map(function (m) {
                    return '<div class="mw-sam-lead" data-m="' + m.id + '">' +
                        '<div class="mw-sam-lead-main"><b>' + esc(m.name) + '</b>' + (m.services ? ' · ' + esc(m.services) : '') +
                        (m.address ? ' <small>· ' + esc(m.address) + '</small>' : '') +
                        (m.snippet ? '<div class="mw-sam-next">' + esc(m.snippet.length > 220 ? m.snippet.slice(0, 219) + '…' : m.snippet) + '</div>' : '') +
                        '<small class="mw-sam-meta">' + (m.subject ? esc(m.subject) + ', ' : '') + esc(m.email) + ', ' + esc(ago(m.at)) + '</small></div>' +
                        '<div class="mw-sam-lead-act">' +
                        '<button type="button" class="mw-rc-ok" data-maybe="maybe_accept">Make it a lead</button>' +
                        '<button type="button" class="mw-rc-sk" data-maybe="maybe_dismiss">Not a lead</button></div></div>';
                }).join('') : '');
        sec(lbox, 'New leads', leads.length + maybes.length, html, msg, true);
    }
    if (lbox) lbox.addEventListener('click', function (e) {
        var mb = e.target.closest('[data-maybe]');
        if (mb && !busy) {
            var mid = +mb.closest('[data-m]').getAttribute('data-m'), how = mb.getAttribute('data-maybe'); busy = true;
            post({ mode: how, candidate_id: mid }).then(function (d) {
                busy = false;
                maybes = maybes.filter(function (m) { return m.id !== mid; });
                if (d && d.ok && how === 'maybe_accept') return load().then(function () { renderLeads(d.message); });
                renderLeads(d && (d.message || d.error));
            }).catch(function () { busy = false; renderLeads('Network error — try again.'); });
            return;
        }
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
        if (!queue.length) { closeWork(msg); return; }
        if (idx >= queue.length) idx = 0;
        var c = queue[idx], st = state(c);
        var total = queue.reduce(function (s, x) { return s + Number(x.amount || 0); }, 0);
        var replied = c.kind === 'replied';
        var why = replied
            ? '<b>' + esc(c.last_in_by || c.first_name || c.name) + ' wrote back ' + esc(ago(c.last_in)) + '</b>' +
              (c.last_in_by ? ' about ' + esc(c.first_name || c.name) + '\'s quote' : '') + '. Read it before you send anything.'
            : 'No reply for <b>' + c.days + ' days</b>' + (c.followups ? ', ' + c.followups + ' follow-up' + (c.followups === 1 ? '' : 's') + ' so far' : '') +
              (c.viewed ? '. They opened it' : '') + '.';

        var quotes = c.quotes.map(function (q) {
            return '<li' + (q.state ? ' class="mw-sam-q-' + esc(q.state) + '"' : '') + '><a href="/crm/quotes/view.php?id=' + q.id + '">' + esc(q.number) + '</a> · ' + esc(q.service || q.title || 'Quote') +
                (q.address ? ' · ' + esc(q.address) : '') + ' · <b>' + money(q.amount) + '</b>' +
                '<small> sent ' + esc(shortDate(q.sent_at)) + (q.valid_until ? ', good until ' + esc(shortDate(q.valid_until)) : '') + (q.views ? ', viewed ' + q.views + '×' : '') + '</small></li>';
        }).join('');

        var thread = (c.thread || []).length
            ? '<div class="mw-sam-thread">' + c.thread.slice(0, 3).map(function (m) {
                var isText = m.channel === 'sms';
                return '<div class="mw-sam-msg ' + (m.dir === 'inbound' ? 'is-in' : 'is-out') + '"><small>' + (m.dir === 'inbound' ? esc(m.by || c.first_name || 'Them') : 'Us') +
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
            '<span><b>' + (idx + 1) + ' of ' + queue.length + '</b> waiting, ' + money(total) + ' in all</span>' +
            '<button type="button" class="mw-rc-arrow" data-nav="1" aria-label="Next"' + (queue.length < 2 ? ' disabled' : '') + '>›</button></div>' +
            '<div class="mw-sam-slide">' +
              '<div class="mw-sam-who">' +
                '<div class="mw-rc-meta"><b>' + esc(c.name) + '</b>' + (c.company ? ', ' + esc(c.company) : '') + ' <span class="mw-sam-amt">' + money(c.amount) + '</span></div>' +
                '<div class="mw-sam-why' + (replied ? ' is-replied' : '') + '">' + why + '</div>' +
                '<ul class="mw-sam-quotes">' + quotes + '</ul>' + thread +
              '</div>' +
              '<div class="mw-sam-draft">' + channelBtns +
                (isSms ? '' : '<label class="mw-sam-lbl">Subject<input class="mw-sam-in" data-f="subject" value="' + esc(st.subject) + '"></label>') +
                '<label class="mw-sam-lbl">' + (isSms ? 'Text' : 'Email') + (st.drafted_by === 'learned' && !isSms ? ' <small class="mw-sam-learned">written your way</small>' : '') +
                  (st.drafted_by === 'claude' ? ' <small class="mw-sam-learned">drafted by Claude — check it</small>' : '') +
                  '<textarea class="mw-sam-in" data-f="' + (isSms ? 'sms' : 'body') + '" rows="' + (isSms ? 3 : 9) + '">' + esc(text) + '</textarea></label>' +
                // DraftSafety badge (Fable 2026-10-12): emails / links / bank details not in our records.
                (!isSms && st.drafted_by === 'claude' && st.flag_label ? '<div class="mw-sam-count is-bad" role="alert">&#9888; ' + esc(st.flag_label) + '</div>' : '') +
                (isSms ? '<div class="mw-sam-count' + (probs.length ? ' is-bad' : '') + '">' + text.length + '/' + SMS_MAX + (probs.length ? ' · ' + esc(probs.join(' · ')) : ' · OK for carriers') + '</div>'
                       : '<div class="mw-sam-count">Quote link' + (c.quotes.length > 1 ? 's are' : ' is') + ' added under your message.</div>') +
                '<div class="mw-rc-actions">' +
                  '<button type="button" class="mw-rc-ok" data-act="send"' + (isSms && probs.length ? ' disabled' : '') + '>Send ' + (isSms ? 'text' : 'email') + '</button>' +
                  (replied && !isSms ? '<button type="button" class="mw-rc-ed" data-act="claude">Draft a reply</button>' : '') +
                  '<button type="button" class="mw-rc-ed" data-act="snooze">Snooze a week</button>' +
                  '<button type="button" class="mw-rc-sk" data-act="skip">Not now</button>' +
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
        if (nav && queue.length) { idx = (idx + Number(nav.getAttribute('data-nav')) + queue.length) % queue.length; render(); renderRows(); return; }
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
                if (d && d.ok) { st.body = d.body; st.suggested_body = d.body; st.drafted_by = 'claude'; st.flag_label = d.flag_label || ''; render('Here\'s a draft — read it before you send.'); }
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
                renderBrief(); renderNext();
                render(d.message);
            } else {
                var msg = d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t work — try again.';
                // The list moved on while Tim typed: reload it — never make him refresh (2026-10-15).
                if (/no longer waiting/.test(msg)) { load(); return; }
                render(msg);
            }
        }).catch(function () { busy = false; render('Network error — nothing was sent. Try again.'); });
    });

    var pushBtn = document.getElementById('mw-sam-testpush');
    var pushOut = document.getElementById('mw-sam-pushdiag');
    if (pushBtn && pushOut) {
        pushBtn.addEventListener('click', function () {
            pushBtn.disabled = true;
            pushOut.hidden = false;
            pushOut.textContent = 'Sending…';
            post({ mode: 'test_push' }).then(function (d) {
                pushBtn.disabled = false;
                if (!d || (d.ok === false && d.error)) { pushOut.textContent = (d && d.error) || 'That didn\'t work.'; return; }
                var lines = [
                    esc(d.message || ''),
                    'APNs configured: ' + (d.apns_configured ? 'yes' + (d.sandbox ? ' (sandbox)' : '') : 'no') +
                        ' · your active iPhones: ' + Number(d.tokens || 0)
                ];
                (d.results || []).forEach(function (r) {
                    lines.push(esc(r.token) + ' → ' + (r.success ? 'accepted' : 'HTTP ' + r.http_code + ' ' + esc(r.error || '')) +
                        (r.deactivated ? ' (token retired)' : ''));
                });
                pushOut.innerHTML = lines.join('<br>');
            }).catch(function () { pushBtn.disabled = false; pushOut.textContent = 'Network error — try again.'; });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
