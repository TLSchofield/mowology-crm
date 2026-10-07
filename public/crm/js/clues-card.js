/**
 * Clues (Yui's card, and any page with #mw-clues): facts Penny and Yui spotted in payments
 * and mail — a strata plan paying for a building on file as a house, a job title and firm in a
 * signature, a phone number the contact lacks, an accountant set up as the quote signer.
 *
 *   Apply      performs exactly the suggested change (server re-checks the record first)
 *   Got it     a flag with nothing to change (e.g. an open quote addressed to an accountant)
 *   Not right  dismissed for good — and the detector learns that pattern was wrong
 *
 * Only admins see the buttons. Nothing changes a record without that click.
 * API: /crm/api/clues.php (?mode=list[&contact_id=]; POST apply / dismiss).
 * Optional: data-contact-id="N" on #mw-clues shows one contact's clues only.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-clues');
    if (!root) return;
    var API = '/crm/api/clues.php';
    var contactId = root.getAttribute('data-contact-id') || '';
    var canDecide = root.getAttribute('data-can-decide') === '1';
    var clues = [], note = '', busy = false;
    var SOURCE = { etransfer: 'e-Transfer', bank: 'bank line', email: 'email' };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function safeUrl(u) { return (typeof u === 'string' && /^\/(?!\/)/.test(u)) ? u : '#'; }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load() {
        return fetch(API + '?mode=list' + (contactId ? '&contact_id=' + encodeURIComponent(contactId) : ''), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { root.innerHTML = ''; return; }
                clues = d.clues || [];
                canDecide = !!d.can_decide;
                render();
            })
            .catch(function () { root.innerHTML = ''; });
    }

    function row(c) {
        var acts = [];
        if (canDecide) {
            acts.push('<button type="button" class="mw-rc-ok" data-do="apply">' + esc(c.apply || 'Apply') + '</button>');
            acts.push('<button type="button" class="mw-rc-sk" data-do="dismiss">Not right</button>');
        }
        acts.push('<a class="mw-rc-ed" href="' + esc(safeUrl(c.url)) + '">Open</a>');
        return '<div class="mw-yui-row mw-clue-row" data-id="' + esc(c.id) + '">' +
            '<div class="mw-yui-line"><div class="mw-yui-main">' +
              '<span class="mw-clue-tag is-' + esc(c.owner) + '">' + (c.owner === 'penny' ? 'Penny' : 'Yui') + ' · ' + esc(SOURCE[c.source] || c.source) + '</span> ' +
              esc(c.summary) +
              '<small class="mw-clue-ev">' + esc(c.evidence) + '</small>' +
            '</div><div class="mw-sam-lead-act mw-yui-act">' + acts.join('') + '</div></div></div>';
    }

    function render() {
        if (!clues.length && !note) { root.innerHTML = ''; return; }
        root.innerHTML = '<div class="mw-yui-sec mw-clue-sec"><div class="mw-yui-sec-head"><b>Clues</b>' +
            (clues.length ? ' <span class="mw-yui-n">' + clues.length + '</span>' : '') +
            ' <small>facts spotted in payments and mail — one click to fix, nothing changes until you do</small></div>' +
            (note ? '<div class="mw-rc-msg">' + esc(note) + '</div>' : '') +
            clues.map(row).join('') + '</div>';
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-do]'); if (!b || busy || b.disabled) return;
        var rowEl = b.closest('[data-id]'); if (!rowEl) return;
        var id = parseInt(rowEl.getAttribute('data-id'), 10);
        var act = b.getAttribute('data-do');
        busy = true;
        rowEl.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        post({ mode: act, id: id }).then(function (d) {
            busy = false;
            note = d && (d.message || d.error) ? (d.message || d.error) : 'That didn\'t work — try again.';
            if (d && d.ok) clues = clues.filter(function (c) { return c.id !== id; });
            render();
        }).catch(function () { busy = false; note = 'Network error — nothing was changed. Try again.'; render(); });
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
