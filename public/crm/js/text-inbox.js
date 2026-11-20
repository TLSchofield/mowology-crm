/**
 * "Texts from numbers we don't know" on Yui's card (/crm/includes/text-inbox-panel.php). Data: /crm/api/text-inbox.php.
 * Everything is built with DOM calls and textContent: a text's wording is untrusted, never HTML.
 */
(function () {
    'use strict';
    var API = '/crm/api/text-inbox.php';
    var root = document.getElementById('mw-txi');
    if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    var listEl = document.getElementById('mw-txi-list');
    var countEl = document.getElementById('mw-txi-count');
    var msgEl = document.getElementById('mw-txi-msg');
    var contactsEl = document.getElementById('mw-txi-contacts');

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function say(text, kind) {
        msgEl.hidden = !text;
        msgEl.className = 'mw-txi-msg' + (kind ? ' is-' + kind : '');
        msgEl.textContent = text || '';
    }
    function pretty(p) { return p && p.length === 10 ? '(' + p.slice(0, 3) + ') ' + p.slice(3, 6) + '-' + p.slice(6) : (p || ''); }
    function when(s) {
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    function call(mode, data, method) {
        var url = API + '?mode=' + encodeURIComponent(mode);
        var opts = { credentials: 'same-origin', headers: {} };
        if (method === 'POST') {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(Object.assign({ mode: mode, csrf_token: csrf }, data || {}));
            url = API;
        } else if (data) {
            Object.keys(data).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(data[k]); });
        }
        return fetch(url, opts).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) {
                var error = new Error(d.error || 'Failed');
                error.data = d;
                throw error;
            }
            return d;
        });
    }

    function linkBox(t, card) {
        var box = el('div', 'mw-txi-link');
        var input = el('input', 'form-control form-control-sm');
        input.type = 'search';
        input.placeholder = 'Customer name or number…';
        var res = el('div', 'mw-txi-results');
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { res.textContent = ''; return; }
            timer = setTimeout(function () {
                call('search', { q: q }).then(function (d) {
                    res.textContent = '';
                    if (!d.results.length) res.appendChild(el('div', 'mw-txi-empty', 'No customer found.'));
                    d.results.forEach(function (c) { res.appendChild(resultRow(t, c, res)); });
                }).catch(function (e) { say(e.message, 'bad'); });
            }, 250);
        });
        box.appendChild(input);
        box.appendChild(res);
        return box;
    }

    function resultRow(t, c, res) {
        var row = el('div', 'mw-txi-result');
        var label = c.name + (c.phone || c.mobile ? '  ·  ' + [c.phone, c.mobile].filter(Boolean).join(' / ') : '');
        var pick = el('button', 'btn btn-sm btn-outline-secondary', label);
        pick.type = 'button';
        pick.addEventListener('click', function () {
            if (c.phone && c.mobile) {
                // both numbers are filled: say which one the new number replaces, never overwrite by accident
                res.textContent = '';
                res.appendChild(el('div', 'mw-txi-ask', c.name + ' already has two numbers. Which one does ' + pretty(t.phone10) + ' replace?'));
                [['phone', 'Replace ' + c.phone], ['mobile', 'Replace ' + c.mobile]].forEach(function (o) {
                    var b = el('button', 'btn btn-sm btn-outline-primary me-2', o[1]);
                    b.type = 'button';
                    b.addEventListener('click', function () { doLink(t, c, o[0]); });
                    res.appendChild(b);
                });
                return;
            }
            doLink(t, c, null);
        });
        row.appendChild(pick);
        return row;
    }

    function doLink(t, c, replace) {
        call('link', { phone10: t.phone10, contact_id: c.id, replace: replace }, 'POST').then(function (d) {
            say(pretty(t.phone10) + ' is now ' + c.name + "'s number. " + d.moved + ' text' + (d.moved === 1 ? '' : 's') + ' added to their history.', 'ok');
            load();
        }).catch(function (e) { say(e.message, 'bad'); });
    }

    function threadCard(t) {
        var card = el('div', 'mw-txi-thread');
        var top = el('div', 'mw-txi-top');
        top.appendChild(el('strong', '', pretty(t.phone10)));
        top.appendChild(el('span', 'mw-txi-meta', t.count + ' text' + (t.count === 1 ? '' : 's') + ' · latest ' + when(t.last_at)));
        card.appendChild(top);
        t.messages.forEach(function (m) {
            var line = el('div', 'mw-txi-line ' + (m.direction === 'inbound' ? 'is-in' : 'is-out'));
            line.appendChild(el('span', 'mw-txi-who', m.direction === 'inbound' ? 'They wrote' : 'You wrote'));
            line.appendChild(el('span', 'mw-txi-text', m.snippet));
            card.appendChild(line);
        });
        var acts = el('div', 'mw-txi-acts');
        var linkBtn = el('button', 'btn btn-sm btn-primary', 'Link to a customer');
        linkBtn.type = 'button';
        var box = null;
        linkBtn.addEventListener('click', function () {
            if (box) { box.remove(); box = null; return; }
            box = linkBox(t, card);
            card.appendChild(box);
            box.querySelector('input').focus();
        });
        var personal = el('button', 'btn btn-sm btn-outline-secondary', 'Personal');
        personal.type = 'button';
        personal.title = 'Delete these texts and never bring this number in again';
        personal.addEventListener('click', function () {
            if (!window.confirm('Mark ' + pretty(t.phone10) + ' as personal?\n\nThese texts are deleted and this number is never sent to the CRM again.')) return;
            call('personal', { phone10: t.phone10 }, 'POST').then(function (d) {
                say('Marked personal: ' + d.deleted + ' text' + (d.deleted === 1 ? '' : 's') + ' deleted.', 'ok');
                load();
            }).catch(function (e) { say(e.message, 'bad'); });
        });
        acts.appendChild(linkBtn);
        acts.appendChild(personal);
        card.appendChild(acts);
        return card;
    }

    function contactActions(c, row, actions) {
        actions.textContent = '';
        var keep = el('button', 'btn btn-sm btn-outline-secondary', 'Keep');
        keep.type = 'button';
        keep.addEventListener('click', function () {
            keep.disabled = true;
            call('keep_contact', { contact_id: c.id }, 'POST').then(function () {
                row.remove();
                say(c.name + ' kept as a work contact.', 'ok');
            }).catch(function (e) { say(e.message, 'bad'); contactActions(c, row, actions); });
        });
        var personal = el('button', 'btn btn-sm btn-outline-danger', 'Personal');
        personal.type = 'button';
        personal.addEventListener('click', function () { contactConfirm(c, row, actions, false); });
        actions.appendChild(keep);
        actions.appendChild(personal);
    }

    function contactConfirm(c, row, actions, confirmed) {
        actions.textContent = '';
        var prompt = confirmed ? '' : 'Delete ' + c.texts + ' text' + (c.texts === 1 ? '' : 's') + ' with ' + c.name + ' and never bring this number in again?';
        var line = el('span', 'mw-txi-confirm', prompt);
        var remove = el('button', 'btn btn-sm btn-danger', confirmed ? 'Mark personal anyway' : 'Delete texts');
        remove.type = 'button';
        var cancel = el('button', 'btn btn-sm btn-outline-secondary', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { contactActions(c, row, actions); });
        remove.addEventListener('click', function () {
            remove.textContent = 'Deleting…';
            remove.disabled = true;
            cancel.disabled = true;
            call('personal_contact', { contact_id: c.id, confirmed: confirmed }, 'POST').then(function (d) {
                say('Marked personal: ' + d.deleted + ' text' + (d.deleted === 1 ? '' : 's') + ' deleted.', 'ok');
                load();
            }).catch(function (e) {
                if (e.data && e.data.needs_confirm) {
                    actions.textContent = '';
                    actions.appendChild(el('span', 'mw-txi-confirm', e.message));
                    var anyway = el('button', 'btn btn-sm btn-danger', 'Mark personal anyway');
                    anyway.type = 'button';
                    anyway.addEventListener('click', function () { contactConfirm(c, row, actions, true); });
                    var no = el('button', 'btn btn-sm btn-outline-secondary', 'Cancel');
                    no.type = 'button';
                    no.addEventListener('click', function () { contactActions(c, row, actions); });
                    actions.appendChild(anyway);
                    actions.appendChild(no);
                    return;
                }
                say(e.message, 'bad');
                contactActions(c, row, actions);
            });
        });
        if (prompt) actions.appendChild(line);
        actions.appendChild(remove);
        actions.appendChild(cancel);
        if (confirmed) remove.click();
    }

    function contactRow(c) {
        var row = el('div', 'mw-txi-contact');
        var info = el('div', 'mw-txi-contact-info');
        info.appendChild(el('div', 'mw-txi-contact-name', c.name));
        info.appendChild(el('div', 'mw-txi-meta', c.texts + ' text' + (c.texts === 1 ? '' : 's') + ' · latest ' + when(c.last_at)));
        var actions = el('div', 'mw-txi-contact-actions');
        row.appendChild(info);
        row.appendChild(actions);
        contactActions(c, row, actions);
        return row;
    }

    function load() {
        return call('list').then(function (d) {
            countEl.textContent = d.count ? d.count + (d.count === 1 ? ' number' : ' numbers') : '';
            listEl.textContent = '';
            if (!d.threads.length) listEl.appendChild(el('span', 'mw-txi-empty', 'No unknown numbers waiting.'));
            d.threads.forEach(function (t) { listEl.appendChild(threadCard(t)); });
            contactsEl.textContent = '';
            if (!d.contacts.length) contactsEl.appendChild(el('span', 'mw-txi-empty', 'No contacts waiting for review.'));
            d.contacts.forEach(function (c) { contactsEl.appendChild(contactRow(c)); });
        }).catch(function (e) { listEl.textContent = ''; say(e.message, 'bad'); });
    }

    load();
})();
