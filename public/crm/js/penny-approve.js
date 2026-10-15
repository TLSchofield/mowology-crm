/**
 * Penny's approve window — what approving a receipt teaches her (Tim, 2026-10-14: "the approve pop up
 * would be way more interesting if it was large, had Penny's face and explained what, if any, lessons
 * are learned; should include the brain graphic").
 *
 *   MwPennyApprove.confirm(expenseId) → Promise<boolean>   true = the owner pressed Approve
 *
 * Asks /crm/api/expenses.php?action=approve_preview (BookkeeperDeskService::approvalPreview) and shows:
 * her face and brain (head-brain.js), one line per field she read — right, or what you set instead —
 * how many of the vendor's other waiting receipts she'll redo, and her track record there.
 * The approval itself stays with the caller. If the preview can't load, falls back to confirm().
 * Listed in COMPONENTS.md.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function loadBrain() {
        if (window.HeadBrain || document.querySelector('script[data-mw-head-brain], script[src*="/crm/js/head-brain.js"]')) return;
        var s = document.createElement('script');
        s.src = '/crm/js/head-brain.js';
        s.defer = true;
        s.setAttribute('data-mw-head-brain', '1');
        s.onload = function () { if (window.HeadBrain && window.HeadBrain.mount) window.HeadBrain.mount(); };
        document.head.appendChild(s);
    }

    function show(v) { return v === null || v === undefined || v === '' ? '—' : (v === 'none' ? 'nothing' : v); }

    function lessonLine(l, vendor) {
        if (l.right) {
            return '<li class="mw-pa-right"><span class="mw-pa-mark" aria-hidden="true">✓</span>' +
                   '<span><strong>' + esc(l.label) + '</strong> — I said ' + esc(show(l.said)) + '. Right.</span></li>';
        }
        var teach = (l.field === 'accounting_category' || l.field === 'asset_tag')
            ? ' I\'ll use that on ' + esc(vendor || 'this vendor\'s') + ' receipts like this one.'
            : l.field === 'job'
                ? ' Jobs go by the purchase, not the store — I won\'t apply this to other ' + esc(vendor || 'vendor') + ' receipts.'
                : (l.field === 'vendor' ? ' I\'ll read the name better next time.' : ' Noted.');
        return '<li class="' + (l.field === 'job' ? 'mw-pa-info' : 'mw-pa-wrong') + '"><span class="mw-pa-mark" aria-hidden="true">' + (l.field === 'job' ? 'ⓘ' : '✎') + '</span>' +
               '<span><strong>' + esc(l.label) + '</strong> — I said ' + esc(show(l.said)) +
               ', you set <strong>' + esc(show(l.set)) + '</strong>.' + teach + '</span></li>';
    }

    function render(p) {
        var vendor = p.vendor || 'this vendor';
        var wrong = (p.lessons || []).filter(function (l) { return !l.right; });
        // The job is per purchase, never a lesson about the vendor (Tim 2026-10-14): shown, not counted.
        var teachable = wrong.filter(function (l) { return l.field !== 'job'; });
        var body;
        if (!p.read) {
            body = '<p class="mw-pa-lead">I didn\'t read this one. Approving it still adds to what I know about ' + esc(vendor) + '.</p>';
        } else if (!wrong.length) {
            body = '<p class="mw-pa-lead">Nothing new to learn — I had this one right.</p>';
        } else if (!teachable.length) {
            body = '<p class="mw-pa-lead">Nothing new to learn about ' + esc(vendor) + ' — you only set the job for this purchase.</p>';
        } else {
            body = '<p class="mw-pa-lead">Approving this teaches me ' + teachable.length + ' thing' + (teachable.length === 1 ? '' : 's') + ':</p>';
        }
        if (p.read && (p.lessons || []).length) {
            var right = p.lessons.filter(function (l) { return l.right; }).map(function (l) { return l.label; });
            body += '<ul class="mw-pa-lessons">' + wrong.map(function (l) { return lessonLine(l, vendor); }).join('') +
                (right.length ? '<li class="mw-pa-right"><span class="mw-pa-mark" aria-hidden="true">✓</span><span>I had ' +
                    '<strong>' + esc(right.join(', ')) + '</strong> right.</span></li>' : '') + '</ul>';
        }
        if (p.redo > 0) {
            body += '<p class="mw-pa-redo">I\'ll redo my other ' + p.redo + ' ' + esc(vendor) + ' receipt' + (p.redo === 1 ? '' : 's') +
                    ' with this' + (p.redo > 10 ? ' — 10 now, the rest in the background' : '') + '.</p>';
        }
        var track = Object.keys(p.track || {}).map(function (k) {
            var label = { accounting_category: 'category', asset_tag: 'For tag', job: 'job', vendor: 'name', total: 'total' }[k] || k;
            return label + ' ' + p.track[k];
        });
        if (track.length) body += '<p class="mw-pa-track">My record at ' + esc(vendor) + ': ' + esc(track.join(' · ')) + '</p>';

        var units = parseInt(p.units, 10) || 0;
        return '<div class="mw-pa-overlay" role="dialog" aria-modal="true" aria-labelledby="mwPaTitle">' +
            '<div class="mw-pa-card">' +
              '<div class="mw-pa-head">' +
                '<div class="mw-pa-portrait">' +
                  '<img class="mw-pa-face" src="/crm/img/heads/penny.jpg" alt="Penny, bookkeeper" width="150" height="150">' +
                  '<button type="button" class="mw-brain mw-pa-brain" data-head="Penny" data-no-page' +
                    ' aria-label="Penny\'s brain: ' + units + ' things learned"' +
                    ' data-empty="Nothing learned yet. Every receipt you approve or correct teaches her something."' +
                    ' data-teach="She glows brighter the more often she\'s right first time"' +
                    ' data-units="' + units + '" data-bright="0.7"' +
                    ' data-parts="' + esc(JSON.stringify(p.parts || [])) + '"' +
                    ' data-since="' + esc(p.since || '') + '"><canvas></canvas></button>' +
                '</div>' +
                '<div><h3 id="mwPaTitle" class="mw-pa-title">Approve this ' + esc(vendor) + ' receipt?</h3>' +
                  '<div class="mw-pa-sub">Penny · what I learn from it</div></div>' +
              '</div>' +
              '<div class="mw-pa-body">' + body + '</div>' +
              '<div class="mw-pa-actions">' +
                '<button type="button" class="btn btn-outline-secondary mw-pa-cancel">Cancel</button>' +
                '<button type="button" class="btn btn-success mw-pa-go">Approve' + (teachable.length ? ' & teach Penny' : '') + '</button>' +
              '</div>' +
            '</div>' +
          '</div>';
    }

    function confirmApprove(expenseId) {
        loadBrain();
        return fetch('/crm/api/expenses.php?action=approve_preview&id=' + encodeURIComponent(expenseId), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (p) {
                if (!p || !p.success) throw new Error('no preview');
                return new Promise(function (resolve) {
                    var wrap = document.createElement('div');
                    wrap.innerHTML = render(p);
                    var el = wrap.firstChild;
                    document.body.appendChild(el);
                    if (window.HeadBrain && window.HeadBrain.mount) window.HeadBrain.mount();
                    var go = el.querySelector('.mw-pa-go');
                    var prev = document.activeElement;
                    var settled = false;
                    function done(ok) {
                        if (settled) return;                           // Approve pressed: Cancel can't undo it
                        settled = true;
                        document.removeEventListener('keydown', onKey, true);
                        el.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                        function close() { el.remove(); if (prev && prev.focus) prev.focus(); resolve(ok); }
                        if (ok) {
                            el.classList.add('is-learning');          // the brain glows as she takes it in
                            setTimeout(close, 650);
                        } else {
                            close();
                        }
                    }
                    function onKey(e) {
                        if (e.key === 'Escape') { e.stopPropagation(); done(false); }
                        if (e.key === 'Tab') {                         // keep focus inside the window
                            var f = el.querySelectorAll('button');
                            var first = f[0], last = f[f.length - 1];
                            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                        }
                    }
                    document.addEventListener('keydown', onKey, true);
                    el.querySelector('.mw-pa-cancel').addEventListener('click', function () { done(false); });
                    go.addEventListener('click', function () { done(true); });
                    el.addEventListener('click', function (e) { if (e.target === el) done(false); });
                    go.focus();
                });
            })
            .catch(function () { return window.confirm('Approve this expense?'); });
    }

    window.MwPennyApprove = { confirm: confirmApprove };
})();
