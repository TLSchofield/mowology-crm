/**
 * Yui's card (dashboard → department heads deck): every one-to-one conversation with
 * existing clients, in five sections.
 *
 *   Inbox                  client replies nobody answered (not about a quote — Sam has those).
 *                          A clear yes is flagged YES and comes first. "Draft reply" asks
 *                          Claude (Tim's click only, capped per day); "Write" opens a blank reply.
 *   Promises               "council approved the quote" with no accepted quote → Open quotes / Handled.
 *   Accounts               PM firms and stratas missing people or emails → Open / Handled (read-only).
 *   Renewals & check-ins   contracts ending, last winter's salt/snow, quiet PM firms → a drafted note.
 *   Arrears                60+ days overdue → a polite note to the right person (Penny keeps the numbers).
 *   Who is this?           (under Inbox) mail from people not on file that I could only half match: name them, pick the
 *                          account, "Add to this account" or "Not a client". Answered in place; Undo for a day.
 *
 * Every message is edited and sent by Tim — Send · Handled. Nothing goes out on its own.
 * API: /crm/api/yui.php (?mode=desk; POST draft / send / handled; who_confirm / who_not / who_undo; ?mode=who_accounts). Texts follow the carrier
 * rules (≤160 characters, no links, the office number, "check your email") on both sides.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-yui-secs');
    if (!root) return;
    var API = '/crm/api/yui.php';
    var NAME = root.getAttribute('data-name') || '';
    var PHONE = '(778) 846-9273';
    var SMS_MAX = 160;
    var SECTIONS = [
        ['inbox', 'Inbox', 'client replies waiting on you — not about a quote'],
        ['promises', 'Promises', 'they said yes, but no quote is accepted in the CRM'],
        ['accounts', 'Accounts', 'property managers and stratas — details to tidy'],
        ['renewals', 'Renewals & check-ins', 'contracts ending, winter coming, firms gone quiet'],
        ['arrears', 'Arrears', 'more than 60 days overdue — Penny keeps the numbers, I draft the note']
    ];
    var data = { inbox: [], promises: [], accounts: [], renewals: [], arrears: [] };
    var stats = null, draftsLeft = null;
    var open = null;         // key of the row with its editor open
    var edits = {};          // key → {channel, subject, body, sms, drafted_by, suggested_body, suggested_sms}
    var notes = {};          // section → message under its header
    var busy = false;
    var who = null;          // desk.who: {items, quiet_count, quiet} — only when migration 1447 has run
    var whoEdits = {};       // id → {first, last, client, other, q, results}
    var whoNote = '', whoUndo = null, quietOpen = false, whoTimer = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function safeUrl(u) { return (typeof u === 'string' && /^\/(?!\/)/.test(u)) ? u : '#'; }
    function money(v) {
        v = Number(v || 0);
        return '$' + (v >= 100 ? Math.round(v).toLocaleString() : v.toFixed(2));
    }
    function ago(d) {
        if (!d) return '';
        var t = new Date(String(d).replace(' ', 'T')), now = new Date();
        if (isNaN(t)) return '';
        var days = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(t.getFullYear(), t.getMonth(), t.getDate())) / 86400000);
        return days <= 0 ? 'today' : days === 1 ? 'yesterday' : days + ' days ago';
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    /** Same checks as the server (YuiRules::smsProblems). */
    function smsProblems(t) {
        var p = [];
        if (!t.trim()) return ['empty'];
        if (t.length > SMS_MAX) p.push('over ' + SMS_MAX + ' characters');
        if (/https?:|www\.|\b[a-z0-9-]+\.(ca|com|net|org|io|co|info|biz|app|ly|me)\b/i.test(t)) p.push('no links or web addresses');
        if (/[^\x20-\x7E\n]/.test(t)) p.push('no special characters or emoji');
        if (t.indexOf(PHONE) < 0) p.push('needs ' + PHONE);
        if (!/\bemail\b/i.test(t)) p.push('point them to their email');
        return p;
    }
    function find(key) {
        for (var s in data) for (var i = 0; i < data[s].length; i++) if (data[s][i].key === key) return data[s][i];
        return null;
    }
    function drop(key) {
        for (var s in data) data[s] = data[s].filter(function (it) { return it.key !== key; });
        delete edits[key]; delete edits[key + '|autopay']; delete edits[key + '|statement']; delete variant[key];
        if (open === key) open = null;
    }
    // A card can hold two drafts: its usual note, and (overdue accounts only) the "offer autopay" email. variant[key] says which one is open.
    var variant = {};
    function vkey(it) { return it.key + (variant[it.key] ? '|' + variant[it.key] : ''); }
    function state(it) {
        var k = vkey(it);
        if (!edits[k]) {
            var d = (variant[it.key] === 'autopay' ? it.draft_autopay : variant[it.key] === 'statement' ? it.draft_statement : it.draft) || { subject: '', body: '', sms: '', drafted_by: 'template' };
            edits[k] = { channel: (it.to && it.to.email) || !it.sms_ok ? 'email' : 'sms', subject: d.subject, body: d.body, sms: d.sms, drafted_by: d.drafted_by,
                         suggested_body: d.body, suggested_sms: d.sms };
        }
        return edits[k];
    }

    function load() {
        return fetch(API + '?mode=desk', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { root.innerHTML = '<div class="mw-rc-empty">Yui isn\'t set up yet.</div>'; return; }
                who = d.who || null;
                data = d.sections || data; stats = d.stats || null; draftsLeft = d.drafts_left;
                if (d.name) NAME = d.name;
                renderTop(); render();
            })
            .catch(function () { root.innerHTML = '<div class="mw-rc-empty">Couldn\'t load Yui\'s list — refresh to try again.</div>'; });
    }

    // ── Greeting + numbers ───────────────────────────────────────────────
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function renderTop() {
        var say = document.getElementById('mw-yui-say');
        var box = document.getElementById('mw-yui-stats');
        if (!stats) return;
        var bits = [];
        if (stats.yes) bits.push('<b>' + plural(stats.yes, 'client said yes', 'clients said yes') + '</b> and ' + (stats.yes === 1 ? 'is' : 'are') + ' waiting on an answer');
        else if (stats.inbox) bits.push('<b>' + plural(stats.inbox, 'client reply is', 'client replies are') + '</b> waiting on you');
        if (stats.promises) bits.push(plural(stats.promises, 'approval has', 'approvals have') + ' no accepted quote in the CRM');
        if (stats.arrears) bits.push('<b>' + money(stats.arrears_total) + '</b> is more than 60 days overdue — I\'ve drafted ' + (stats.arrears === 1 ? 'the note' : 'the notes'));
        if (say) say.innerHTML = 'Hey' + (NAME ? ' ' + esc(NAME) : '') + ' — ' +
            (bits.length ? bits.join('. ') + '.' : 'nobody is waiting on you right now. I\'ll bring the next one in.');
        if (!box) return;
        var set = function (k, v) { var el = box.querySelector('[data-stat="' + k + '"]'); if (el) el.innerHTML = v; };
        set('inbox', esc(stats.inbox) + (stats.yes ? ' <small>' + esc(stats.yes) + ' yes</small>' : ''));
        set('promises', esc(stats.promises));
        set('arrears_total', esc(money(stats.arrears_total)));
        set('sent_30', esc(stats.sent_30));
        var n = box.querySelector('[data-stat-n="arrears"]');
        if (n && stats.arrears) n.textContent = plural(stats.arrears, 'conversation', 'conversations') + ' to have · Penny keeps the numbers';
    }

    // ── Sections ─────────────────────────────────────────────────────────
    function render() {
        var html = '', any = false;
        SECTIONS.forEach(function (s) {
            var items = data[s[0]] || [];
            if (items.length || notes[s[0]]) {
                any = true;
                html += '<div class="mw-yui-sec" data-sec="' + s[0] + '"><div class="mw-yui-sec-head"><b>' + esc(s[1]) + '</b>' +
                    (items.length ? ' <span class="mw-yui-n">' + items.length + '</span>' : '') + ' <small>' + esc(s[2]) + '</small></div>' +
                    (notes[s[0]] ? '<div class="mw-rc-msg">' + esc(notes[s[0]]) + '</div>' : '') +
                    items.map(row).join('') + '</div>';
            }
            if (s[0] === 'inbox') { var w = whoHtml(); if (w) { any = true; html += w; } }
        });
        root.innerHTML = any ? html : '<div class="mw-rc-empty">Nobody is waiting on you' + (NAME ? ', ' + esc(NAME) : '') + '. I\'ll bring the next one in.</div>';
    }

    function toLine(it) {
        var t = it.to;
        if (!t) return '<small class="mw-yui-to is-none">Nobody on file to write to</small>';
        return '<small class="mw-yui-to">To ' + esc(t.name) + (t.why ? ' (' + esc(t.why) + ')' : '') +
            (t.email ? '' : ' · no email on file') + '</small>';
    }

    function row(it) {
        var s = it.section, main, acts = [];
        if (s === 'inbox' && it.hold) {
            // Asked for something only a phone call can confirm: the words, the check, and nothing to write with.
            main = '<span class="mw-yui-hold">Hold</span> ' + esc(it.text) +
                '<small class="mw-yui-meta">' + esc(ago(it.at)) + (it.subject ? ' · ' + esc(it.subject) : '') + '</small>';
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Open</a>');
        } else if (s === 'inbox') {
            main = (it.yes ? '<span class="mw-sam-yes">Yes</span> ' : '') + (it.upset ? '<span class="mw-yui-upset" title="' + esc(it.upset_why || '') + '">Unhappy</span> ' : '') + '<b>' + esc(it.name) + '</b> ' + (it.channel === 'sms' ? 'texted' : 'replied') +
                (it.quote ? ' <q>' + esc(it.quote) + '</q>' : '') +
                '<small class="mw-yui-meta">' + (it.channel !== 'sms' && it.subject ? esc(it.subject) + ' · ' : '') + esc(ago(it.at)) +
                (it.stage === 'late' ? ' · <span class="mw-yui-late">waited ' + (it.waiting_days | 0) + ' weekdays</span>' : '') + '</small>';
            if (it.statement && it.statement.offer) acts.push('<button type="button" class="mw-rc-ok" data-do="statement">Send statement</button>');
            if (it.to && it.to.email) acts.push('<button type="button" class="' + (it.statement && it.statement.offer ? 'mw-rc-ed' : 'mw-rc-ok') + '" data-do="claude"' + (draftsLeft === 0 ? ' disabled title="No drafts left today"' : '') + '>Draft reply</button>');
            if (it.to) acts.push('<button type="button" class="mw-rc-ed" data-do="write">Write</button>');
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Open</a>');
        } else if (s === 'promises') {
            main = '<b>' + esc(it.name) + ':</b> <q>' + esc(it.quote) + '</q> <small class="mw-yui-meta">' + esc(ago(it.at)) +
                ' · no accepted quote in the CRM</small>';
            acts.push('<a class="mw-rc-ok" href="' + esc(safeUrl(it.quotes_url || it.url)) + '">Open quotes</a>');
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Contact</a>');
        } else {
            // The brief's text names the person for Charlie; the card shows them on their own line.
            main = esc(String(it.text || '').replace(/ — a note to .*$/, '')) + (it.template ? toLine(it) : '');
            if (it.template && it.to && (it.to.email || it.sms_ok)) acts.push('<button type="button" class="mw-rc-ok" data-do="write">' + (s === 'arrears' ? 'Draft note' : 'Draft') + '</button>');
            if (s === 'arrears' && it.autopay && it.autopay.offer) acts.push('<button type="button" class="mw-rc-ed" data-do="autopay">Offer autopay</button>');
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Open</a>');
        }
        acts.push('<button type="button" class="mw-rc-sk" data-do="handled">Handled</button>');
        var isOpen = open === it.key;
        return '<div class="mw-yui-row' + (it.yes ? ' is-yes' : '') + (it.hold ? ' is-hold' : '') + (s === 'arrears' ? ' is-money' : '') + (isOpen ? ' is-open' : '') + '" data-k="' + esc(it.key) + '">' +
            '<div class="mw-yui-line"><div class="mw-yui-main">' + main + '</div><div class="mw-sam-lead-act mw-yui-act">' + acts.join('') + '</div></div>' +
            (isOpen ? editor(it) : '') + '</div>';
    }

    // ── Who is this? ─────────────────────────────────────────────────────
    function whoState(it) {
        if (!whoEdits[it.id]) whoEdits[it.id] = { first: it.first_name || '', last: it.last_name || '', client: it.suggested_client_id ? String(it.suggested_client_id) : '', other: false, q: '', results: [] };
        return whoEdits[it.id];
    }
    function whoFind(id) {
        var items = (who && who.items) || [];
        for (var i = 0; i < items.length; i++) if (String(items[i].id) === String(id)) return items[i];
        return null;
    }
    function whoReady(st) { return !!st.first.trim() && Number(st.client) > 0; }
    function opts(list, sel, first) {
        return (first || '') + list.map(function (c) {
            return '<option value="' + esc(c.client_id) + '"' + (String(c.client_id) === String(sel) ? ' selected' : '') + '>' + esc(c.name) + '</option>';
        }).join('');
    }
    function whoRow(it) {
        var st = whoState(it), cands = it.candidates || [];
        var pick = '<select class="mw-sam-in" data-wf="account" aria-label="Account">' +
            (st.other ? '<option value="__other" selected>Another account…</option>' : opts(cands, st.client, '<option value="">Choose an account…</option>') + '<option value="__other">Another account…</option>') +
            '</select>';
        if (st.other) pick += '<input class="mw-sam-in" data-wf="q" type="search" placeholder="Start typing the account name" aria-label="Find an account" value="' + esc(st.q) + '">' +
            '<select class="mw-sam-in" data-wf="found" aria-label="Matching accounts"' + (st.results.length ? '' : ' hidden') + '>' +
            opts(st.results, st.client, '<option value="">Pick the account…</option>') + '</select>';
        return '<div class="mw-yui-row mw-yui-who-row" data-w="' + esc(it.id) + '">' +
            '<div class="mw-yui-who-main"><b>' + esc(it.name) + '</b> <small class="mw-yui-meta">' + esc(it.addr) + '</small>' +
            (it.subject ? '<div>' + esc(it.subject) + '</div>' : '') +
            (it.snippet ? '<q>' + esc(it.snippet) + '</q>' : '') +
            '<small class="mw-yui-meta">' + esc(ago(it.at)) + (it.more ? ' · ' + esc(it.more) + ' earlier from them, answered together' : '') + '</small>' +
            (it.why ? '<small class="mw-yui-who-why">Why I think so: ' + esc(it.why) + '</small>' : '') + '</div>' +
            '<div class="mw-yui-who-form">' +
              '<label class="mw-sam-lbl">First name<input class="mw-sam-in" data-wf="first" value="' + esc(st.first) + '"></label>' +
              '<label class="mw-sam-lbl">Last name<input class="mw-sam-in" data-wf="last" value="' + esc(st.last) + '"></label>' +
              '<label class="mw-sam-lbl mw-yui-who-acct">Account' + pick + '</label>' +
              '<div class="mw-rc-actions">' +
                '<button type="button" class="mw-rc-ok" data-do="who_ok"' + (whoReady(st) ? '' : ' disabled') + '>Add to this account</button>' +
                '<button type="button" class="mw-rc-sk" data-do="who_no">Not a client</button>' +
              '</div></div></div>';
    }
    function whoHtml() {
        if (!who) return '';
        var items = who.items || [], quiet = who.quiet || [], qn = Number(who.quiet_count || 0);
        if (!items.length && !whoNote && !qn) return '';
        return '<div class="mw-yui-sec mw-yui-who" data-sec="who"><div class="mw-yui-sec-head"><b>Who is this?</b>' +
            (items.length ? ' <span class="mw-yui-n">' + items.length + '</span>' : '') + ' <small>I found these but I\'m not sure whose they are</small></div>' +
            (whoNote ? '<div class="mw-rc-msg">' + esc(whoNote) +
                (whoUndo ? ' <button type="button" class="mw-rc-ed mw-yui-who-undo" data-do="who_undo" data-id="' + esc(whoUndo) + '">Undo</button>' : '') + '</div>' : '') +
            items.map(whoRow).join('') +
            (qn > 0 ? '<details class="mw-yui-who-quiet"' + (quietOpen ? ' open' : '') + '><summary>' + esc(qn) + ' more kept, nothing on file matches ' + (qn === 1 ? 'it' : 'them') + '</summary>' +
                quiet.map(function (q) {
                    return '<div class="mw-yui-who-q"><b>' + esc(q.name) + '</b> <small class="mw-yui-meta">' + esc(q.subject) + ' · ' + esc(ago(q.at)) + '</small></div>';
                }).join('') + '</details>' : '') + '</div>';
    }
    function whoButton(rowEl) {
        var it = whoFind(rowEl.getAttribute('data-w')), b = rowEl.querySelector('[data-do="who_ok"]');
        if (it && b) b.disabled = !whoReady(whoState(it));
    }
    function whoSearch(rowEl, it, st) {
        clearTimeout(whoTimer);
        var q = st.q.trim();
        whoTimer = setTimeout(function () {
            if (q.length < 2) { st.results = []; fillFound(rowEl, st); return; }
            fetch(API + '?mode=who_accounts&q=' + encodeURIComponent(q), { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (st.q.trim() !== q) return;   // typed on since
                    st.results = (d && d.ok && d.accounts) || [];
                    fillFound(rowEl, st);
                })
                .catch(function () { st.results = []; fillFound(rowEl, st); });
        }, 250);
    }
    function fillFound(rowEl, st) {
        var sel = rowEl.querySelector('[data-wf="found"]'); if (!sel) return;
        sel.innerHTML = opts(st.results, st.client, '<option value="">Pick the account…</option>');
        sel.hidden = !st.results.length;
        whoButton(rowEl);
    }
    function whoDone(d, id) {
        busy = false;
        if (d && d.ok) {
            who.items = who.items.filter(function (x) { return x.id !== id; });
            delete whoEdits[id];
            whoNote = d.message || 'Done.'; whoUndo = d.undo_id || null;
        } else whoNote = (d && (d.message || d.error)) || 'That didn\'t work — try again.';
        render();
    }

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-wf'), rowEl = f && e.target.closest('[data-w]'); if (!rowEl) return;
        var it = whoFind(rowEl.getAttribute('data-w')); if (!it) return;
        var st = whoState(it);
        if (f === 'first' || f === 'last') st[f] = e.target.value;
        else if (f === 'q') { st.q = e.target.value; st.client = ''; whoSearch(rowEl, it, st); }
        whoButton(rowEl);
    });
    root.addEventListener('change', function (e) {
        var f = e.target.getAttribute('data-wf'), rowEl = f && e.target.closest('[data-w]'); if (!rowEl) return;
        var it = whoFind(rowEl.getAttribute('data-w')); if (!it) return;
        var st = whoState(it);
        if (f === 'account') {
            if (e.target.value === '__other') {
                st.other = true; st.client = ''; render();
                var q = root.querySelector('[data-w="' + it.id + '"] [data-wf="q"]'); if (q) q.focus();
                return;
            }
            st.client = e.target.value;
        } else if (f === 'found') st.client = e.target.value;
        whoButton(rowEl);
    });
    root.addEventListener('toggle', function (e) {
        if (e.target.classList && e.target.classList.contains('mw-yui-who-quiet')) quietOpen = e.target.open;
    }, true);
    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-do^="who_"]'); if (!b || busy || b.disabled) return;
        var act = b.getAttribute('data-do');
        if (act === 'who_undo') {
            busy = true; b.disabled = true;
            post({ mode: 'who_undo', id: Number(b.getAttribute('data-id')) }).then(function (d) {
                busy = false;
                whoNote = (d && (d.message || d.error)) || 'That didn\'t undo — try again.';
                if (d && d.ok) { whoUndo = null; whoEdits = {}; load(); } else render();
            }).catch(function () { busy = false; whoNote = 'Network error — try again.'; render(); });
            return;
        }
        var rowEl = b.closest('[data-w]'), it = rowEl && whoFind(rowEl.getAttribute('data-w')); if (!it) return;
        var st = whoState(it);
        busy = true;
        rowEl.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        var body = act === 'who_ok' ? { mode: 'who_confirm', id: it.id, client_id: Number(st.client), first_name: st.first.trim(), last_name: st.last.trim() } : { mode: 'who_not', id: it.id };
        post(body).then(function (d) { whoDone(d, it.id); })
            .catch(function () { busy = false; whoNote = 'Network error — nothing changed. Try again.'; render(); });
    });

    /** The statement's facts and who it goes to, above the note. The address list is the server's; someone not on file says so. */
    function stmtHead(it, st) {
        var o = it.statement, list = o.recipients || [];
        var pick = null;
        list.forEach(function (r) { if (r.email === st.recipient) pick = r; });
        if (!pick) { pick = list[0]; st.recipient = pick.email; }
        return '<div class="mw-sam-count">Statement attached · ' + esc(o.account) + ' · ' + esc(o.label) + ' · <b>' + esc(money2(o.closing)) + '</b></div>' +
            '<label class="mw-sam-lbl">Send to<select class="mw-sam-in" data-f="recipient">' + list.map(function (r) {
                return '<option value="' + esc(r.email) + '"' + (r.email === st.recipient ? ' selected' : '') + '>' + esc(r.name) + ' · ' + esc(r.email) + (r.on_file ? ' · on file' : '') + '</option>';
            }).join('') + '</select></label>';
    }
    function stmtUnconfirmed(it, st) {
        return (it.statement.recipients || []).some(function (r) { return r.email === st.recipient && !r.on_file; });
    }
    function money2(v) { return '$' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function editor(it) {
        var isStmt = variant[it.key] === 'statement', st = state(it), isSms = st.channel === 'sms', text = isSms ? st.sms : st.body, probs = isSms ? smsProblems(text) : [];
        var thread = (it.thread || []).length ? '<div class="mw-sam-thread">' + it.thread.slice(0, 3).map(function (m) {
            var tx = m.channel === 'sms';
            return '<div class="mw-sam-msg ' + (m.direction === 'inbound' ? 'is-in' : 'is-out') + '"><small>' + (m.direction === 'inbound' ? esc(it.name || 'Them') : 'Us') +
                ' · ' + (tx ? 'text · ' : '') + esc(ago(m.sent_at)) + (!tx && m.subject ? ' · ' + esc(m.subject) : '') + '</small><div>' + esc(m.snippet || '(no text)') + '</div></div>';
        }).join('') + '</div>' : '';
        return '<div class="mw-yui-ed">' + thread +
            '<div class="mw-sam-draft">' +
              (isStmt ? stmtHead(it, st) : '<div class="mw-sam-chan" role="group" aria-label="Send as">' +
                '<button type="button" data-ch="email" class="' + (!isSms ? 'is-on' : '') + '"' + (it.to && it.to.email ? '' : ' disabled title="No email address on file"') + '>Email</button>' +
                '<button type="button" data-ch="sms" class="' + (isSms ? 'is-on' : '') + '"' + (it.sms_ok ? '' : ' disabled title="No mobile number or no consent to texts"') + '>Text</button></div>') +
              (variant[it.key] === 'autopay' ? '<div class="mw-sam-count">Autopay offer' + (it.autopay && it.autopay.card ? ' &middot; ' + esc(it.autopay.card) + ' is on file' : '') + ' &middot; the link is their own invoice link; they re-enter the card on Stripe.</div>' : '') +
              (isSms ? '' : '<label class="mw-sam-lbl">Subject<input class="mw-sam-in" data-f="subject" value="' + esc(st.subject) + '"></label>') +
              '<label class="mw-sam-lbl">' + (isSms ? 'Text' : isStmt ? 'Your note' : 'Email to ' + esc(it.to ? it.to.name : '')) +
                (st.drafted_by === 'learned' && !isSms ? ' <small class="mw-sam-learned">written your way</small>' : '') +
                (st.drafted_by === 'claude' ? ' <small class="mw-sam-learned">drafted by Claude — check it</small>' : '') +
                '<textarea class="mw-sam-in" data-f="' + (isSms ? 'sms' : 'body') + '" rows="' + (isSms ? 3 : 8) + '">' + esc(text) + '</textarea></label>' +
              // DraftSafety badge (Fable 2026-10-12): emails / links / bank details not in our records.
              (!isSms && st.drafted_by === 'claude' && st.flag_label ? '<div class="mw-sam-count is-bad" role="alert">&#9888; ' + esc(st.flag_label) + '</div>' : '') +
              (isSms ? '<div class="mw-sam-count' + (probs.length ? ' is-bad' : '') + '">' + text.length + '/' + SMS_MAX + (probs.length ? ' · ' + esc(probs.join(' · ')) : ' · OK for carriers') + '</div>'
                     : '<div class="mw-sam-count">From office@ — their reply comes back to the office inbox.</div>') +
              '<div class="mw-rc-actions">' +
                '<button type="button" class="mw-rc-ok" data-do="send"' + (isSms && probs.length ? ' disabled' : '') + '>Send ' + (isStmt ? 'statement' : isSms ? 'text' : 'email') + '</button>' +
                (isStmt && stmtUnconfirmed(it, st) ? '<small class="mw-yui-stmt-warn">Not on file for this account. Check before sending.</small>' : '') +
                '<button type="button" class="mw-rc-sk" data-do="close">Close</button>' +
              '</div>' +
            '</div></div>';
    }

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f'); if (!f || !open) return;
        var it = find(open); if (!it) return;
        var st = state(it); st[f] = e.target.value;
        if (f === 'recipient') { render(); return; }
        if (f === 'sms') {
            var probs = smsProblems(st.sms), cnt = root.querySelector('.mw-yui-row.is-open .mw-sam-count'), btn = root.querySelector('.mw-yui-row.is-open [data-do="send"]');
            if (cnt) { cnt.className = 'mw-sam-count' + (probs.length ? ' is-bad' : ''); cnt.textContent = st.sms.length + '/' + SMS_MAX + (probs.length ? ' · ' + probs.join(' · ') : ' · OK for carriers'); }
            if (btn) btn.disabled = probs.length > 0;
        }
    });

    root.addEventListener('click', function (e) {
        var rowEl = e.target.closest('[data-k]'); if (!rowEl) return;
        var key = rowEl.getAttribute('data-k'), it = find(key); if (!it) return;
        var sec = it.section;
        var ch = e.target.closest('[data-ch]');
        if (ch && !ch.disabled) { state(it).channel = ch.getAttribute('data-ch'); render(); return; }
        var b = e.target.closest('[data-do]'); if (!b || busy || b.disabled) return;
        var act = b.getAttribute('data-do');

        if (act === 'write') { variant[key] = null; open = open === key ? null : key; render(); return; }
        if (act === 'statement') { var open2 = open === key && variant[key] === 'statement'; variant[key] = 'statement'; state(it).channel = 'email'; open = open2 ? null : key; render(); return; }
        if (act === 'write') { variant[key] = null; }
        if (act === 'autopay') { var was = open === key && variant[key] === 'autopay'; variant[key] = 'autopay'; open = was ? null : key; render(); return; }
        if (act === 'close') { open = null; render(); return; }

        busy = true;
        rowEl.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        var done = function (msg) { busy = false; notes[sec] = msg || ''; render(); };

        if (act === 'claude') {
            open = key;
            post({ mode: 'draft', key: key }).then(function (d) {
                if (d && d.ok) {
                    var st = state(it);
                    st.channel = 'email'; st.body = d.body; st.suggested_body = d.body; st.drafted_by = 'claude'; st.flag_label = d.flag_label || '';
                    if (typeof d.drafts_left === 'number') draftsLeft = d.drafts_left;
                    done('Here\'s a draft — read it before you send.');
                } else done(d && (d.message || d.error) ? (d.message || d.error) : 'Couldn\'t draft that one.');
            }).catch(function () { done('Network error — try again.'); });
            return;
        }
        if (act === 'handled') {
            post({ mode: 'handled', key: key }).then(function (d) {
                if (d && d.ok) { drop(key); done(d.message || 'Marked handled.'); }
                else done(d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t work — try again.');
            }).catch(function () { done('Network error — try again.'); });
            return;
        }
        if (act === 'send') {
            var st = state(it), sms = st.channel === 'sms';
            post({ mode: 'send', key: key, channel: st.channel, subject: sms ? '' : st.subject, body: sms ? st.sms : st.body,
                   suggested_body: sms ? st.suggested_sms : st.suggested_body, drafted_by: st.drafted_by,
                   template: variant[key] || '', recipient: st.recipient || '' }).then(function (d) {
                if (d && d.ok) { drop(key); if (stats) stats.sent_30 = (stats.sent_30 || 0) + 1; renderTop(); done(d.message); }
                else done(d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t go out — try again.');
            }).catch(function () { done('Network error — nothing was sent. Try again.'); });
        }
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
