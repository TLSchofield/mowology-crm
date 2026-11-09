/**
 * Crew app — "My team" page (/crm/my-team.php): Penny's receipt questions.
 *
 *  Snap it          → a plain link to the receipt camera (expenses_appstack.php?mode=quick&penny_missing=N);
 *                     the receipt page tells /crm/api/penny-chase.php which charge it answers.
 *  It's already in  → bottom sheet of recent receipts (mode=recent) → attach
 *  No receipt       → bottom sheet: lost / vendor didn't give one + note → no_receipt
 *
 * CSRF: this page is standalone (no window.MW_CSRF_TOKEN). The token comes from the page
 * (data-csrf) and is refreshed from /crm/api/get-csrf.php once on a 403, then the POST is retried.
 */
(function () {
    'use strict';

    var API = '/crm/api/penny-chase.php';
    var main = document.getElementById('ct-main');
    if (!main) return;
    var token = main.getAttribute('data-csrf') || '';
    var sheet = document.getElementById('ct-sheet');
    var sheetTitle = document.getElementById('ct-sheet-title');
    var sheetBody = document.getElementById('ct-sheet-body');
    var toastEl = document.getElementById('ct-toast');
    var toastTimer = null;

    function toast(msg, bad) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.toggle('is-bad', !!bad);
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
    }

    function haptic(kind) {
        try { if (window.MwHaptics && window.MwHaptics[kind]) window.MwHaptics[kind](); } catch (e) { /* optional */ }
    }

    function refreshToken() {
        return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.token) { token = d.token; window.MW_CSRF_TOKEN = d.token; }
                return token;
            });
    }

    function post(body, retried) {
        var payload = Object.assign({}, body, { csrf_token: token });
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            if (r.status === 403 && !retried) {
                return refreshToken().then(function () { return post(body, true); });
            }
            if (r.status === 403) console.warn('[crew-team] POST refused after a token refresh', body.mode);
            return r.json();
        });
    }

    function itemEl(id) { return document.getElementById('ct-missing-' + id); }

    function done(id, msg) {
        haptic('success');
        toast(msg || 'Thanks!');
        var li = itemEl(id);
        if (!li) return;
        li.classList.add('is-done');
        setTimeout(function () {
            var list = li.parentNode;
            li.remove();
            var count = document.querySelector('#ct-penny .ct-count');
            var left = list ? list.querySelectorAll('.ct-ask').length : 0;
            if (count) {
                if (left) count.textContent = String(left); else count.remove();
            }
            if (list && !left) {
                var p = document.createElement('p');
                p.className = 'ct-say ct-say-done';
                p.textContent = 'All your receipts are in. Thank you — that makes my job easy.';
                list.replaceWith(p);
            }
        }, 320);
    }

    function openSheet(title) {
        sheetTitle.textContent = title;
        sheetBody.innerHTML = '';
        sheet.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeSheet() {
        sheet.hidden = true;
        document.body.style.overflow = '';
    }
    sheet.addEventListener('click', function (e) {
        if (e.target.closest('[data-close]')) closeSheet();
    });
    // Android back closes the sheet first (capacitor-bridge.js dispatches mw-native-back)
    document.addEventListener('mw-native-back', function (e) {
        if (!sheet.hidden) { e.preventDefault(); closeSheet(); }
    });

    function money(v) { return '$' + Number(v).toFixed(2); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    // ── It's already in ───────────────────────────────────────
    function already(id) {
        openSheet('Which receipt is it?');
        sheetBody.appendChild(el('p', 'ct-hint', 'Looking at your receipts from around that day…'));
        fetch(API + '?mode=recent&id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                sheetBody.innerHTML = '';
                var list = (d && d.receipts) || [];
                if (!list.length) {
                    sheetBody.appendChild(el('p', 'ct-hint', "I can't see a receipt of yours from around then. If you have the paper one, snap it — or tell me it's gone."));
                    return;
                }
                list.forEach(function (rc) {
                    var b = el('button', 'ct-btn ct-pick' + (rc.same_amount ? ' is-same' : ''));
                    b.type = 'button';
                    var left = el('span', null, rc.vendor);
                    left.appendChild(el('small', null, rc.date + (rc.same_amount ? ' · same amount' : '')));
                    b.appendChild(left);
                    b.appendChild(el('span', null, money(rc.total)));
                    b.addEventListener('click', function () {
                        b.disabled = true;
                        post({ mode: 'attach', id: id, expense_id: rc.id }).then(function (res) {
                            if (res && res.ok) { closeSheet(); done(id, res.message); }
                            else { b.disabled = false; toast((res && (res.message || res.error)) || 'Could not save', true); }
                        }).catch(function () { b.disabled = false; toast('No connection — try again', true); });
                    });
                    sheetBody.appendChild(b);
                });
            })
            .catch(function () {
                sheetBody.innerHTML = '';
                sheetBody.appendChild(el('p', 'ct-hint', 'No connection — try again in a minute.'));
            });
    }

    // ── No receipt ────────────────────────────────────────────
    function noReceipt(id) {
        openSheet('No receipt for this one?');
        sheetBody.appendChild(el('p', 'ct-hint', "That's OK — tell me why and I'll stop asking. Tim will see it (we can't claim the GST back without one)."));
        var note = el('textarea', 'ct-note');
        note.placeholder = 'Anything Tim should know? (optional)';
        note.maxLength = 255;
        [['lost', 'I lost it'], ['not_available', "They didn't give me one"]].forEach(function (r) {
            var b = el('button', 'ct-btn', r[1]);
            b.type = 'button';
            b.addEventListener('click', function () {
                b.disabled = true;
                post({ mode: 'no_receipt', id: id, reason: r[0], note: note.value }).then(function (res) {
                    if (res && res.ok) { closeSheet(); done(id, res.message); }
                    else { b.disabled = false; toast((res && (res.message || res.error)) || 'Could not save', true); }
                }).catch(function () { b.disabled = false; toast('No connection — try again', true); });
            });
            sheetBody.appendChild(b);
        });
        sheetBody.appendChild(note);
    }

    main.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var li = btn.closest('.ct-ask');
        if (!li) return;
        var id = parseInt(li.getAttribute('data-id'), 10);
        var act = btn.getAttribute('data-act');
        if (act === 'already') { e.preventDefault(); already(id); }
        else if (act === 'none') { e.preventDefault(); noReceipt(id); }
        // 'snap' is a plain link to the receipt camera
    });

    // ── Deep link from a push tap: ?penny=missing&id=N ────────
    var focus = parseInt(main.getAttribute('data-focus') || '0', 10);
    if (focus) {
        var f = itemEl(focus);
        if (f) {
            setTimeout(function () { f.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 150);
        } else {
            var st = main.getAttribute('data-focus-status');
            if (st === 'received') toast('That receipt is already in — thanks!');
            else if (st === 'no_receipt') toast('Already marked as no receipt');
            else if (st && st !== 'open') toast('Already sorted');
        }
    }
})();
