/**
 * Ask first — office side (Products › Field Recommendations).
 *
 * Crew save "Ask first" notes from the field; a manager or admin reads, edits and sends
 * them here. Each note goes to the property's on-site contact (else the site contact)
 * with the visit photos attached and no price. "Wording per service" edits the sentence
 * that says what we do and why now (products.field_ask_pitch); blank = built-in wording.
 *
 * API: /crm/api/field-observations.php?action=ask-drafts | ask-draft | ask-send | ask-close
 *      | ask-pitches | ask-pitch   (FieldAskService does the work; billing.edit required)
 */
(function () {
    'use strict';
    var root = document.getElementById('mwAskOffice');
    if (!root) return;
    var API = '/crm/api/field-observations.php?action=';
    var CSRF = root.getAttribute('data-csrf') || window.MW_CSRF_TOKEN || '';
    var list = document.getElementById('mwAskDrafts');
    var wording = document.getElementById('mwAskWording');
    var forceOpen = /[?&]tab=ask\b/.test(window.location.search);

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function get(action, qs) {
        return fetch(API + action + (qs || ''), { cache: 'no-store' }).then(function (r) { return r.json(); });
    }
    function post(action, body) {
        body.csrf_token = CSRF;
        return fetch(API + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function when(d) {
        var t = new Date(String(d || '').replace(' ', 'T'));
        return isNaN(t) ? '' : t.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function load(msg) {
        get('ask-drafts').then(function (d) {
            if (!d || !d.success || !d.ready) { root.hidden = true; return; }
            var drafts = d.drafts || [];
            root.hidden = !drafts.length && !forceOpen && !msg;
            list.innerHTML = (msg ? '<div class="mw-ask-msg">' + esc(msg) + '</div>' : '') + (drafts.length
                ? drafts.map(function (x) {
                    return '<div class="mw-ask-row" data-id="' + x.observation_id + '">' +
                        '<div class="mw-ask-row-main"><b>' + esc(x.service) + '</b> at ' + esc(x.place) +
                        ' <small>· to ' + esc(x.to_name || '—') + ' · saved by ' + esc(x.by || 'crew') + ' ' + esc(when(x.created_at)) + '</small>' +
                        (x.note ? '<div class="mw-ask-row-note">' + esc(x.note) + '</div>' : '') + '</div>' +
                        '<div class="mw-ask-row-act"><button type="button" class="btn btn-sm btn-success" data-open>Read &amp; send</button>' +
                        '<button type="button" class="btn btn-sm btn-link text-muted" data-del>Delete</button></div>' +
                        '<div class="mw-ask-editor" hidden></div></div>';
                }).join('')
                : '<div class="mw-ask-empty">No notes waiting. When the crew pick "Ask first" in the field, they land here for you.</div>');
        }).catch(function () { root.hidden = true; });
    }

    function openEditor(row) {
        var ed = row.querySelector('.mw-ask-editor');
        ed.hidden = false;
        ed.innerHTML = '<div class="mw-ask-empty">Loading the draft…</div>';
        get('ask-draft', '&id=' + encodeURIComponent(row.getAttribute('data-id'))).then(function (d) {
            if (!d || !d.success) { ed.innerHTML = '<div class="mw-ask-msg is-bad">' + esc((d && d.error) || 'Could not load it') + '</div>'; return; }
            var to = d.to || {}, c = d.consent || {};
            ed.innerHTML =
                '<div class="mw-ask-to">To <b>' + esc(to.name) + '</b> &lt;' + esc(to.email || 'no email') + '&gt; <small>' +
                (to.role === 'onsite' ? 'on-site contact' : 'site contact') + (d.billing && d.billing.contact_id !== to.contact_id ? ' · the quote goes to ' + esc(d.billing.name) + ' after a yes' : '') + '</small></div>' +
                '<div class="mw-ask-consent' + (c.ok ? '' : ' is-bad') + '">' + esc(c.reason || '') + '</div>' +
                ((d.photos || []).length ? '<div class="mw-ask-photos">' + d.photos.map(function (p) { return '<img src="' + esc(p.url) + '" alt="Site photo">'; }).join('') +
                    '<small>' + d.photos.length + ' attached at 1024px</small></div>' : '<div class="mw-ask-consent">No photos — it goes as a note.</div>') +
                '<label class="mw-ask-lbl">Subject<input class="form-control form-control-sm" data-f="subject"></label>' +
                '<label class="mw-ask-lbl">Email' + (d.drafted_by === 'learned' ? ' <small class="mw-ask-learned">written your way</small>' : '') +
                '<textarea class="form-control form-control-sm" rows="12" data-f="body"></textarea></label>' +
                '<small class="text-muted">No price in this one. Our name, address and an unsubscribe line go underneath.</small>' +
                '<div class="mw-ask-act"><button type="button" class="btn btn-sm btn-success" data-send' + (c.ok ? '' : ' disabled') + '>Send to ' + esc(to.first_name || 'them') + '</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-cancel>Close</button></div><div class="mw-ask-msg" data-msg></div>';
            ed.querySelector('[data-f="subject"]').value = d.subject || '';
            ed.querySelector('[data-f="body"]').value = d.body || '';
        });
    }

    list.addEventListener('click', function (e) {
        var row = e.target.closest('.mw-ask-row'); if (!row) return;
        var id = +row.getAttribute('data-id');
        if (e.target.closest('[data-open]')) { openEditor(row); return; }
        if (e.target.closest('[data-cancel]')) { row.querySelector('.mw-ask-editor').hidden = true; return; }
        if (e.target.closest('[data-del]')) {
            if (!confirm('Delete this draft? Nothing has been sent.')) return;
            post('ask-close', { id: id, reason: 'Ask first: deleted by the office' }).then(function (d) { load(d && d.message); });
            return;
        }
        var send = e.target.closest('[data-send]');
        if (send) {
            var ed = row.querySelector('.mw-ask-editor'), m = ed.querySelector('[data-msg]');
            send.disabled = true; send.textContent = 'Sending…';
            post('ask-send', { id: id, subject: ed.querySelector('[data-f="subject"]').value, body: ed.querySelector('[data-f="body"]').value })
                .then(function (d) {
                    if (d && d.success) { load(d.message); return; }
                    send.disabled = false; send.textContent = 'Send';
                    m.className = 'mw-ask-msg is-bad'; m.textContent = (d && (d.message || d.error)) || 'It did not send — still saved.';
                })
                .catch(function () { send.disabled = false; send.textContent = 'Send'; m.className = 'mw-ask-msg is-bad'; m.textContent = 'Network error — still saved.'; });
        }
    });

    // ── Wording per service ──────────────────────────────────────────────
    document.getElementById('mwAskWordingBtn').addEventListener('click', function () {
        if (!wording.hidden) { wording.hidden = true; return; }
        wording.hidden = false;
        wording.innerHTML = '<div class="mw-ask-empty">Loading…</div>';
        get('ask-pitches').then(function (d) {
            var rows = (d && d.pitches) || [];
            wording.innerHTML = '<p class="text-muted small mb-2">The sentence after "They\'re attached." — what we do and why now. Blank uses the built-in wording shown faintly.</p>' +
                (rows.length ? rows.map(function (p) {
                    return '<div class="mw-ask-pitch" data-p="' + p.product_id + '"><label class="mw-ask-lbl">' + esc(p.label) +
                        '<textarea class="form-control form-control-sm" rows="3" placeholder="' + esc(p.default || 'It\'s due for ' + p.label.toLowerCase() + '.') + '">' + esc(p.pitch) + '</textarea></label>' +
                        '<button type="button" class="btn btn-sm btn-outline-success" data-save>Save</button> <small data-msg></small></div>';
                }).join('') : '<div class="mw-ask-empty">No services are published to the field yet.</div>');
        });
    });
    wording.addEventListener('click', function (e) {
        var b = e.target.closest('[data-save]'); if (!b) return;
        var row = b.closest('[data-p]'), m = row.querySelector('[data-msg]');
        post('ask-pitch', { product_id: +row.getAttribute('data-p'), pitch: row.querySelector('textarea').value })
            .then(function (d) { m.textContent = (d && d.message) || 'Saved.'; });
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { load(); }); else load();
})();
