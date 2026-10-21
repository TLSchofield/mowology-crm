/**
 * Receipts from email - pending review: "Open full editor" and "Re-read" (Tim, 2026-10-20).
 *
 * Adds to the existing panel rows (expenses_appstack.php, #receipt-inbox) without changing its markup:
 *   - Open full editor : the same editor a snapped receipt opens in (window.editExpense), so an emailed receipt can be
 *                        edited properly and Penny's read / Approve / Reject are all there. The panel refreshes when it closes.
 *   - Re-read          : read the receipt again on the server (receipt-inbox-reread.php). Fills BLANK fields only; never
 *                        overwrites what is saved, never approves or dismisses.
 *   - Re-read unread   : the same for every receipt in the panel the first read left blank.
 *   - PDF preview      : the editor showed a PDF in an <img> (a broken image), and Chrome will not draw a PDF in a frame on this
 *                        page: the page CSP (public/.htaccess) says object-src 'none', and that applies to the PDF viewer inside the
 *                        frame ("This page has been blocked by Chrome"; also tried 2026-10-20: serve-receipt.php's own PDF CSP is
 *                        now viewer-friendly, which was not enough). So editExpense is wrapped: for a PDF the preview pane gets a note
 *                        and an "Open PDF in a new tab" button, and the photo-only Rescan button is hidden (Penny's read reads PDFs).
 *                        To show it inline, the Expenses page's own CSP needs object-src 'self' (Tim to decide).
 * Loaded by one <script> line in expenses_appstack.php, after the panel's PHP block (so it also covers receipts opened from the list).
 */
(function () {
    'use strict';

    // ── PDF preview in the full editor (every page that has the editor, panel or not) ─────────────────
    function installPdfPreview() {
        var orig = window.editExpense;
        if (typeof orig !== 'function' || orig.__mwPdfPreview) return;
        var wrapped = async function (id) {
            var result = await orig.apply(this, arguments);
            try {
                var r = await fetch('/crm/api/expenses.php?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
                var d = await r.json();
                var e = d && d.expense;
                var isPdf = !!(e && e.receipt_path && /pdf/i.test(e.receipt_mime_type || ''));
                var img = document.getElementById('expReceiptImg');
                var box = img && img.closest ? img.closest('.mw-modal-receipt-preview') : null;
                var col = document.getElementById('expReceiptCol');
                var note = document.getElementById('mwPdfNote');
                if (isPdf && col && box) {
                    box.hidden = true;
                    var frame = document.getElementById('expReceiptFrame');
                    if (frame) { frame.hidden = true; frame.src = 'about:blank'; }
                    if (!note) {
                        note = document.createElement('div');
                        note.id = 'mwPdfNote';
                        note.style.cssText = 'padding:24px 16px;text-align:center;';
                        col.insertBefore(note, col.firstChild);
                    }
                    note.textContent = '';
                    var msg = document.createElement('div');
                    msg.className = 'text-muted mb-3';
                    msg.textContent = 'This receipt is a PDF. Chrome can\'t show it inside this window, so open it beside the editor.';
                    var link = document.createElement('a');
                    link.className = 'btn btn-outline-primary';
                    link.href = e.receipt_path;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = 'Open PDF in a new tab';
                    note.appendChild(msg);
                    note.appendChild(link);
                    note.hidden = false;
                    var rescan = document.getElementById('expRescanBtn');
                    if (rescan) rescan.style.display = 'none';
                } else if (note) {
                    note.hidden = true;   // a non-PDF receipt: the editor's own preview applies
                }
            } catch (err) { /* the editor still works without the PDF frame */ }
            return result;
        };
        wrapped.__mwPdfPreview = true;
        window.editExpense = wrapped;
    }
    // editExpense is defined by the page's own script; wait until everything has run.
    if (document.readyState === 'complete') installPdfPreview(); else window.addEventListener('load', installPdfPreview);

    // ── The emailed-receipts panel ─────────────────────────────────────────────────────────────────────
    var card = document.getElementById('receipt-inbox');
    if (!card) return;

    var API = '/crm/api/receipt-inbox-reread.php';
    var SUMMARY_KEY = 'mwRiRereadSummary';

    function post(data) {
        var body = new URLSearchParams(Object.assign({ csrf_token: window.MW_CSRF_TOKEN || '' }, data));
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok || !d.ok) throw new Error(d.message || ('HTTP ' + r.status));
                return d;
            });
        });
    }

    function button(label, cls, title) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + cls;
        b.textContent = label;
        if (title) b.title = title;
        return b;
    }

    function isUnread(row) {
        var total = row.querySelector('.mw-ri-total');
        return !!row.querySelector('.mw-ri-flag') || (total && parseFloat(total.value) <= 0);
    }

    function message(row) {
        var m = row.querySelector('.mw-ri-msg');
        if (!m) {
            m = document.createElement('div');
            m.className = 'mw-ri-msg small text-muted mt-1';
            var actions = row.querySelector('.mw-ri-actions');
            (actions || row).appendChild(m);
        }
        return m;
    }

    function openEditor(id) {
        if (typeof window.editExpense !== 'function') {
            alert('The editor is not ready yet. Reload the page and try again.');
            return;
        }
        if (window.jQuery) {
            // Whatever happens in the editor (save, approve, reject), refresh the panel when it closes.
            window.jQuery('#expenseModal').one('hidden.bs.modal', function () { window.location.reload(); });
        }
        window.editExpense(id);
    }

    function rereadOne(row, btn) {
        var id = row.getAttribute('data-expense-id');
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Reading…';
        post({ action: 'reread', expense_id: id }).then(function (d) {
            var r = (d.results || [])[0] || {};
            if (r.status === 'filled') {
                sessionStorage.setItem(SUMMARY_KEY, 'Receipt #' + id + ' filled from the receipt.');
                window.location.reload();
                return;
            }
            message(row).textContent = r.note || 'Nothing to fill.';
            btn.disabled = false;
            btn.textContent = original;
        }).catch(function (e) {
            message(row).textContent = 'Could not re-read: ' + e.message;
            btn.disabled = false;
            btn.textContent = original;
        });
    }

    var rows = Array.prototype.slice.call(card.querySelectorAll('.mw-ri-row'));
    var unreadCount = 0;
    rows.forEach(function (row) {
        var actions = row.querySelector('.mw-ri-actions');
        if (!actions) return;
        var id = row.getAttribute('data-expense-id');

        var edit = button('Open full editor', 'btn-outline-primary', 'Open this receipt in the full editor, like a snapped receipt');
        edit.addEventListener('click', function () { openEditor(id); });
        actions.insertBefore(edit, actions.firstChild);

        if (isUnread(row)) {
            unreadCount++;
            var reread = button('Re-read', 'btn-outline-secondary', 'Read this receipt again on the server; fills blank fields only');
            reread.addEventListener('click', function () { rereadOne(row, reread); });
            actions.insertBefore(reread, edit.nextSibling);
        }
    });

    var header = card.querySelector('.card-header');
    if (header) {
        var tools = document.createElement('span');
        tools.className = 'mw-ri-tools';
        tools.style.cssText = 'display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;';   // Bootstrap 4 here: no gap-/me- utilities
        var status = document.createElement('span');
        status.className = 'small text-muted';
        tools.appendChild(status);

        var saved = null;
        try { saved = sessionStorage.getItem(SUMMARY_KEY); sessionStorage.removeItem(SUMMARY_KEY); } catch (e) { /* private mode */ }
        if (saved) status.textContent = saved;

        if (unreadCount > 0) {
            var all = button('Re-read unread (' + unreadCount + ')', 'btn-outline-primary',
                'Read every receipt in this list that the first read left blank. Fills blank fields only.');
            all.addEventListener('click', function () {
                all.disabled = true;
                status.textContent = 'Reading ' + unreadCount + ' receipt' + (unreadCount === 1 ? '' : 's') + '… this can take a minute.';
                post({ action: 'reread_unread' }).then(function (d) {
                    var c = d.counts || {};
                    var parts = [];
                    if (c.filled) parts.push(c.filled + ' filled');
                    if (c.unreadable) parts.push(c.unreadable + ' still unreadable (open the full editor and use Penny\'s read)');
                    if (c.check_first) parts.push(c.check_first + ' need a look first (they may be our own invoices; open the full editor)');
                    if (c.nothing_to_fill) parts.push(c.nothing_to_fill + ' had no total to fill');
                    if (c.error) parts.push(c.error + ' failed');
                    if (d.remaining) parts.push(d.remaining + ' more left: press again');
                    var summary = 'Re-read done: ' + (parts.join(', ') || 'nothing changed') + '.';
                    if (c.filled) {
                        try { sessionStorage.setItem(SUMMARY_KEY, summary); } catch (e) { /* ignore */ }
                        window.location.reload();
                    } else {
                        status.textContent = summary;
                        all.disabled = false;
                    }
                }).catch(function (e) {
                    status.textContent = 'Could not re-read: ' + e.message;
                    all.disabled = false;
                });
            });
            tools.appendChild(all);
        }
        header.appendChild(tools);
    }
})();
