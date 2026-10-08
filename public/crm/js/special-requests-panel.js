/**
 * Special requests panel on Yui's / Otto's dashboard cards (includes/special-requests-panel.php).
 * GET  /crm/api/special-requests.php?mode=office&head=yui|otto
 * POST {mode: confirm | dismiss | propose}  — nothing is attached or sent without Tim's tap.
 */
(function () {
    'use strict';
    var API = '/crm/api/special-requests.php';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(m, t) { if (typeof window.mwToast === 'function') window.mwToast(m, t || 'success', 4000); }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': body.csrf_token },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error ' + r.status }; }); });
    }
    function day(d) {
        if (!d) return '';
        var t = new Date(), iso = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
        if (d === iso) return 'today';
        var dt = new Date(d + 'T12:00:00');
        return dt.toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
    }
    function list(items, cls) {
        return items && items.length ? '<ul class="mw-sr-list ' + (cls || '') + '">' + items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>' : '';
    }

    function face(p) {
        var src = p.head_photo || ('/crm/img/heads/' + (p.head === 'yui' ? 'yui' : 'otto') + '.jpg');
        return '<span class="mw-sr-face mw-sr-face-sm" data-initial="' + esc((p.head_name || 'O').charAt(0)) + '">' +
            '<img src="' + esc(src) + '" alt="' + esc(p.head_name || '') + '" onerror="this.remove()"></span>';
    }

    function pendingHtml(p) {
        var who = esc(p.from_name || 'Office') + (p.company_name ? ' · ' + esc(p.company_name) : '');
        var visits = p.visits.length
            ? '<ul class="mw-srp-visits">' + p.visits.map(function (v) {
                return '<li><b>' + esc(v.address) + '</b> — ' + esc(day(v.date)) +
                    (v.plan_number ? ' · ' + esc(v.plan_number) : '') +
                    (v.crew && v.crew.length ? ' · crew: ' + esc(v.crew.join(', ')) : ' · <span class="mw-srp-state is-bad">no crew</span>') +
                    (v.extra && v.extra.length ? ' · <span class="mw-srp-state is-open">extra here</span>' : '') +
                    '<div class="mw-srp-why">' + esc((v.reasons || []).join(' · ')) + '</div></li>';
            }).join('') + '</ul>'
            : '<div class="mw-srp-why">No scheduled visit matched — attach it from the visit on the schedule instead.</div>';
        return '<div class="mw-srp-item is-pending" data-req="' + p.id + '">' +
            '<div class="mw-sr-card-head">' + face(p) +
            '<div><div><span class="mw-sr-head-name">' + esc(p.head_name || '') + '</span> · <span class="mw-sr-head-role">' + esc(p.head_role || '') + '</span> ' +
            '<span class="mw-sr-badge">' + (p.source === 'manual' ? 'Added' : 'From ' + esc(p.source)) + '</span></div>' +
            '<div class="mw-sr-from">' + who + '</div></div></div>' +
            (p.client_words ? '<blockquote class="mw-sr-quote">' + esc(p.client_words).replace(/\n/g, '<br>') + '</blockquote>' : '') +
            (p.included.length ? '<div class="mw-sr-sub">Part of the scheduled work</div>' + list(p.included) : '') +
            (p.extra.length ? '<div class="mw-sr-sub is-extra">Extra — crew decide on site</div>' + list(p.extra, 'is-extra') : '') +
            visits +
            (p.unmatched && p.unmatched.length ? '<div class="mw-srp-why">Not matched: ' + esc(p.unmatched.join(', ')) + '</div>' : '') +
            '<div class="mw-sr-actions">' +
            (p.visits.length ? '<button type="button" class="mw-sr-btn is-primary" data-act="confirm">Attach to ' + (p.visits.length === 1 ? 'this visit' : 'these ' + p.visits.length + ' visits') + ' + tell the crew</button>' : '') +
            '<button type="button" class="mw-sr-btn" data-act="dismiss">Not a request</button></div></div>';
    }

    function visitHtml(r) {
        var state;
        if (r.status === 'attached') {
            state = r.acks.length ? '<span class="mw-srp-state is-open">Read by ' + esc(r.acks.map(function (a) { return a.name; }).join(', ')) + '</span>'
                : '<span class="mw-srp-state is-open">Not read yet</span>';
        } else {
            var o = r.outcome || {};
            var label = { done: 'Done', not_done: 'Not done', extra_done: 'Extra work done' }[r.status] || r.status;
            state = '<span class="mw-srp-state' + (r.status === 'not_done' ? ' is-bad' : '') + '">' + label + (o.by_name ? ' · ' + esc(o.by_name) : '') + '</span>' +
                (o.reason ? ' — ' + esc(o.reason) : '') +
                (r.status === 'extra_done' ? ' — ' + esc(o.extra_description || '') + ' (' + (o.extra_minutes || 0) + ' min, $' + Number(o.extra_amount || 0).toFixed(2) + ')' +
                    (o.billing === 'needs_billing' ? ' <span class="mw-srp-state is-bad">Already invoiced — bill by hand</span>'
                        : o.billing === 'visit_extras' ? ' <span class="mw-srp-state">On the visit\'s extras</span>'
                        : ' <span class="mw-srp-state is-open">Goes on the extras at completion</span>') : '');
        }
        return '<div class="mw-srp-item"><b>' + esc(r.address) + '</b> — ' + esc(day(r.scheduled_date)) + ' · ' + esc(r.summary) + '<div>' + state + '</div></div>';
    }

    function addFormHtml() {
        return '<details class="mw-srp-add"><summary class="mw-sr-link">+ Paste a client\'s request</summary>' +
            '<label class="mw-sr-label">Client<select class="form-control" name="contact"><option value="">Loading clients…</option></select></label>' +
            '<label class="mw-sr-label">Their words<textarea class="form-control" rows="3" name="text"></textarea></label>' +
            '<div class="mw-sr-actions"><button type="button" class="mw-sr-btn is-primary" data-act="propose">Find the visits</button></div></details>';
    }

    function render(box, d) {
        var head = box.getAttribute('data-sr-head');
        var body = box.querySelector('.mw-srp-body');
        var html = '';
        if (d.pending.length) html += d.pending.map(pendingHtml).join('');
        if (d.visits.length) html += '<div class="mw-sr-sub">This week on the crew\'s visits</div>' + d.visits.map(visitHtml).join('');
        if (!html) html = '<div class="mw-rc-empty">Nothing waiting. When a client asks for something on a scheduled visit, it shows up here to attach.</div>';
        if (head === 'otto') html += addFormHtml();
        body.innerHTML = html;
    }

    function load(box) {
        var head = box.getAttribute('data-sr-head');
        return fetch(API + '?mode=office&head=' + encodeURIComponent(head), { credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.enabled) { box.hidden = true; return; }
                box._data = d;
                render(box, d);
            })
            .catch(function () { box.querySelector('.mw-srp-body').innerHTML = '<div class="mw-rc-empty">Couldn\'t load special requests.</div>'; });
    }

    function loadClients(sel) {
        if (sel._loaded) return;
        sel._loaded = true;
        fetch(API + '?mode=clients', { credentials: 'include' }).then(function (r) { return r.json(); }).then(function (d) {
            sel.innerHTML = '<option value="">Pick the client…</option>' + (d.clients || []).map(function (c) {
                return '<option value="' + c.id + '">' + esc((c.company_name ? c.company_name + ' — ' : '') + (c.first_name || '') + ' ' + (c.last_name || '')) + '</option>';
            }).join('');
        });
    }

    function wire(box) {
        box.addEventListener('click', function (e) {
            var sum = e.target.closest('details.mw-srp-add > summary');
            if (sum) { loadClients(sum.parentNode.querySelector('select')); return; }
            var b = e.target.closest('[data-act]');
            if (!b) return;
            var act = b.getAttribute('data-act');
            var item = b.closest('[data-req]');
            b.disabled = true;
            if (act === 'confirm' || act === 'dismiss') {
                post({ mode: act, request_id: parseInt(item.getAttribute('data-req'), 10) }).then(function (d) {
                    if (d && d.ok) {
                        if (act === 'confirm') {
                            var texts = 0, pushes = 0;
                            Object.keys(d.sent || {}).forEach(function (k) { if (d.sent[k].sms && d.sent[k].sms.ok) texts++; pushes += (d.sent[k].push || []).length; });
                            toast('Attached to ' + d.attached.length + ' visit(s) — ' + texts + ' text(s), ' + pushes + ' push(es) to the crew.');
                        }
                        load(box);
                    } else { b.disabled = false; toast((d && d.error) || 'Could not save.', 'error'); }
                });
            } else if (act === 'propose') {
                var f = b.closest('.mw-srp-add');
                var cid = parseInt(f.querySelector('[name=contact]').value, 10);
                var text = f.querySelector('[name=text]').value;
                post({ mode: 'propose', contact_id: cid, text: text }).then(function (d) {
                    b.disabled = false;
                    if (d && d.ok) load(box); else toast((d && d.error) || 'Could not read that.', 'error');
                });
            }
        });
    }

    function init() {
        document.querySelectorAll('.mw-srp[data-sr-head]').forEach(function (box) { wire(box); load(box); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
