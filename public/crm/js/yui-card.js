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
 *
 * Every message is edited and sent by Tim — Send · Handled. Nothing goes out on its own.
 * API: /crm/api/yui.php (?mode=desk; POST draft / send / handled). Texts follow the carrier
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
        delete edits[key];
        if (open === key) open = null;
    }
    function state(it) {
        if (!edits[it.key]) {
            var d = it.draft || { subject: '', body: '', sms: '', drafted_by: 'template' };
            edits[it.key] = { channel: (it.to && it.to.email) || !it.sms_ok ? 'email' : 'sms', subject: d.subject, body: d.body, sms: d.sms, drafted_by: d.drafted_by,
                              suggested_body: d.body, suggested_sms: d.sms };
        }
        return edits[it.key];
    }

    function load() {
        return fetch(API + '?mode=desk', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { root.innerHTML = '<div class="mw-rc-empty">Yui isn\'t set up yet.</div>'; return; }
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
            if (!items.length && !notes[s[0]]) return;
            any = true;
            html += '<div class="mw-yui-sec" data-sec="' + s[0] + '"><div class="mw-yui-sec-head"><b>' + esc(s[1]) + '</b>' +
                (items.length ? ' <span class="mw-yui-n">' + items.length + '</span>' : '') + ' <small>' + esc(s[2]) + '</small></div>' +
                (notes[s[0]] ? '<div class="mw-rc-msg">' + esc(notes[s[0]]) + '</div>' : '') +
                items.map(row).join('') + '</div>';
        });
        root.innerHTML = any ? html : '<div class="mw-rc-empty">Nobody is waiting on you' + (NAME ? ', ' + esc(NAME) : '') + '. I\'ll bring the next one in.</div>';
    }

    function who(it) {
        var t = it.to;
        if (!t) return '<small class="mw-yui-to is-none">Nobody on file to write to</small>';
        return '<small class="mw-yui-to">To ' + esc(t.name) + (t.why ? ' (' + esc(t.why) + ')' : '') +
            (t.email ? '' : ' · no email on file') + '</small>';
    }

    function row(it) {
        var s = it.section, main, acts = [];
        if (s === 'inbox') {
            main = (it.yes ? '<span class="mw-sam-yes">Yes</span> ' : '') + '<b>' + esc(it.name) + '</b> ' + (it.channel === 'sms' ? 'texted' : 'replied') +
                (it.quote ? ' <q>' + esc(it.quote) + '</q>' : '') +
                '<small class="mw-yui-meta">' + (it.channel !== 'sms' && it.subject ? esc(it.subject) + ' · ' : '') + esc(ago(it.at)) + '</small>';
            if (it.to && it.to.email) acts.push('<button type="button" class="mw-rc-ok" data-do="claude"' + (draftsLeft === 0 ? ' disabled title="No drafts left today"' : '') + '>Draft reply</button>');
            if (it.to) acts.push('<button type="button" class="mw-rc-ed" data-do="write">Write</button>');
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Open</a>');
        } else if (s === 'promises') {
            main = '<b>' + esc(it.name) + ':</b> <q>' + esc(it.quote) + '</q> <small class="mw-yui-meta">' + esc(ago(it.at)) +
                ' · no accepted quote in the CRM</small>';
            acts.push('<a class="mw-rc-ok" href="' + esc(safeUrl(it.quotes_url || it.url)) + '">Open quotes</a>');
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Contact</a>');
        } else {
            // The brief's text names the person for Charlie; the card shows them on their own line.
            main = esc(String(it.text || '').replace(/ — a note to .*$/, '')) + (it.template ? who(it) : '');
            if (it.template && it.to && (it.to.email || it.sms_ok)) acts.push('<button type="button" class="mw-rc-ok" data-do="write">' + (s === 'arrears' ? 'Draft note' : 'Draft') + '</button>');
            acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(it.url)) + '">Open</a>');
        }
        acts.push('<button type="button" class="mw-rc-sk" data-do="handled">Handled</button>');
        var isOpen = open === it.key;
        return '<div class="mw-yui-row' + (it.yes ? ' is-yes' : '') + (s === 'arrears' ? ' is-money' : '') + (isOpen ? ' is-open' : '') + '" data-k="' + esc(it.key) + '">' +
            '<div class="mw-yui-line"><div class="mw-yui-main">' + main + '</div><div class="mw-sam-lead-act mw-yui-act">' + acts.join('') + '</div></div>' +
            (isOpen ? editor(it) : '') + '</div>';
    }

    function editor(it) {
        var st = state(it), isSms = st.channel === 'sms', text = isSms ? st.sms : st.body, probs = isSms ? smsProblems(text) : [];
        var thread = (it.thread || []).length ? '<div class="mw-sam-thread">' + it.thread.slice(0, 3).map(function (m) {
            var tx = m.channel === 'sms';
            return '<div class="mw-sam-msg ' + (m.direction === 'inbound' ? 'is-in' : 'is-out') + '"><small>' + (m.direction === 'inbound' ? esc(it.name || 'Them') : 'Us') +
                ' · ' + (tx ? 'text · ' : '') + esc(ago(m.sent_at)) + (!tx && m.subject ? ' · ' + esc(m.subject) : '') + '</small><div>' + esc(m.snippet || '(no text)') + '</div></div>';
        }).join('') + '</div>' : '';
        return '<div class="mw-yui-ed">' + thread +
            '<div class="mw-sam-draft">' +
              '<div class="mw-sam-chan" role="group" aria-label="Send as">' +
                '<button type="button" data-ch="email" class="' + (!isSms ? 'is-on' : '') + '"' + (it.to && it.to.email ? '' : ' disabled title="No email address on file"') + '>Email</button>' +
                '<button type="button" data-ch="sms" class="' + (isSms ? 'is-on' : '') + '"' + (it.sms_ok ? '' : ' disabled title="No mobile number or no consent to texts"') + '>Text</button></div>' +
              (isSms ? '' : '<label class="mw-sam-lbl">Subject<input class="mw-sam-in" data-f="subject" value="' + esc(st.subject) + '"></label>') +
              '<label class="mw-sam-lbl">' + (isSms ? 'Text' : 'Email to ' + esc(it.to ? it.to.name : '')) +
                (st.drafted_by === 'learned' && !isSms ? ' <small class="mw-sam-learned">written your way</small>' : '') +
                (st.drafted_by === 'claude' ? ' <small class="mw-sam-learned">drafted by Claude — check it</small>' : '') +
                '<textarea class="mw-sam-in" data-f="' + (isSms ? 'sms' : 'body') + '" rows="' + (isSms ? 3 : 8) + '">' + esc(text) + '</textarea></label>' +
              (isSms ? '<div class="mw-sam-count' + (probs.length ? ' is-bad' : '') + '">' + text.length + '/' + SMS_MAX + (probs.length ? ' · ' + esc(probs.join(' · ')) : ' · OK for carriers') + '</div>'
                     : '<div class="mw-sam-count">From office@ — their reply comes back to the office inbox.</div>') +
              '<div class="mw-rc-actions">' +
                '<button type="button" class="mw-rc-ok" data-do="send"' + (isSms && probs.length ? ' disabled' : '') + '>Send ' + (isSms ? 'text' : 'email') + '</button>' +
                '<button type="button" class="mw-rc-sk" data-do="close">Close</button>' +
              '</div>' +
            '</div></div>';
    }

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f'); if (!f || !open) return;
        var it = find(open); if (!it) return;
        var st = state(it); st[f] = e.target.value;
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

        if (act === 'write') { open = open === key ? null : key; render(); return; }
        if (act === 'close') { open = null; render(); return; }

        busy = true;
        rowEl.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        var done = function (msg) { busy = false; notes[sec] = msg || ''; render(); };

        if (act === 'claude') {
            open = key;
            post({ mode: 'draft', key: key }).then(function (d) {
                if (d && d.ok) {
                    var st = state(it);
                    st.channel = 'email'; st.body = d.body; st.suggested_body = d.body; st.drafted_by = 'claude';
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
                   suggested_body: sms ? st.suggested_sms : st.suggested_body, drafted_by: st.drafted_by }).then(function (d) {
                if (d && d.ok) { drop(key); if (stats) stats.sent_30 = (stats.sent_30 || 0) + 1; renderTop(); done(d.message); }
                else done(d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t go out — try again.');
            }).catch(function () { done('Network error — nothing was sent. Try again.'); });
        }
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
