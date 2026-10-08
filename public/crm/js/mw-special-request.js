/**
 * MwSpecialRequest — a client's special request on a visit, for the crew (web / Android app).
 *
 * Printed by jobs/schedule.php ONLY when ops_settings.special_requests_enabled applies to the
 * signed-in user, so with the feature off this file is never on the page.
 *
 * What it does (and, after 2026-10-08, what it must never do):
 *   - one GET on load (+ when the tab comes back after ≥60 s) for the visits on the page;
 *   - an inline "Special request" card inside each job card that has one (part of the card,
 *     data-nosearch so the search filter ignores it) with Done / Not done / Extra work done;
 *   - blocks(visitId): called synchronously at the top of Start / camera / gallery handlers. If
 *     this person hasn't tapped "Got it" yet it shows the request full screen and returns true —
 *     the tap does NOT start the timer or open the camera. The screen element is created at that
 *     moment and removed on dismiss; after "Got it" the crew tap Start / Photo again (the camera
 *     must open from their own tap, never chained from this button).
 *   - NO element in the DOM while nothing is shown, NO capture-phase listeners, NO global CSS
 *     (styles are .mw-sr-* in mowology-brand.css), NO navigator.geolocation.
 *
 * Server: /crm/api/special-requests.php (?mode=visits | POST ack / outcome / create). The server
 * enforces the same gate (409 special_request_unacknowledged) for old cached builds.
 */
(function () {
    'use strict';
    if (window.MwSpecialRequest) return;

    var API = '/crm/api/special-requests.php';
    var cfg = window.MW_SR || {};
    var byVisit = {};          // visit_id → [request payload]
    var loadedAt = 0;
    var gateEl = null;
    var pendingAckKey = 'mwSrPendingAcks';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function toast(msg, type) {
        if (typeof window.mwToast === 'function') window.mwToast(msg, type || 'success', 3500);
    }

    function token() {
        return window.MW_CSRF_TOKEN || (window.MW_SCHEDULE_STATE && window.MW_SCHEDULE_STATE.csrf) || '';
    }

    /** POST JSON with the get-csrf.php refresh-and-retry (crew pages can hold a stale token). */
    function post(body, retried) {
        body.csrf_token = token();
        return fetch(API, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': body.csrf_token },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Server error (' + r.status + ')' }; }).then(function (d) {
                if (r.status === 403 && d && d.code === 'CSRF_INVALID' && !retried) {
                    return fetch('/crm/api/get-csrf.php', { credentials: 'include' }).then(function (t) { return t.json(); }).then(function (t) {
                        if (t && t.token) {
                            window.MW_CSRF_TOKEN = t.token;
                            if (window.MW_SCHEDULE_STATE) window.MW_SCHEDULE_STATE.csrf = t.token;
                        }
                        return post(body, true);
                    });
                }
                if (r.status === 403) console.warn('[MwSpecialRequest] refused:', d && d.error);
                return d;
            });
        });
    }

    function visitIdsOnPage() {
        var ids = {};
        document.querySelectorAll('.mw-mc-card[data-visit-id], .mw-mc-pill-interactive[data-visit-id], [data-pv-footer][data-visit-id]').forEach(function (el) {
            var v = parseInt(el.getAttribute('data-visit-id'), 10);
            if (v > 0) ids[v] = true;
        });
        return Object.keys(ids);
    }

    function load() {
        var ids = visitIdsOnPage();
        if (!ids.length) return Promise.resolve();
        loadedAt = Date.now();
        return fetch(API + '?mode=visits&ids=' + ids.join(','), { credentials: 'include' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) return;
                byVisit = {};
                if (d.enabled && d.requests) {
                    Object.keys(d.requests).forEach(function (vid) { byVisit[vid] = d.requests[vid]; });
                }
                applyLocalAcks();
                renderAll();
            })
            .catch(function () { /* offline: the server gate still guards a live start */ });
    }

    // ── Acks made with no signal: kept on this phone and sent when back online ──────────
    function readPending() {
        try { return JSON.parse(localStorage.getItem(pendingAckKey) || '[]') || []; } catch (e) { return []; }
    }
    function writePending(list) {
        try { localStorage.setItem(pendingAckKey, JSON.stringify(list)); } catch (e) { /* private mode */ }
    }
    function applyLocalAcks() {
        readPending().forEach(function (id) {
            Object.keys(byVisit).forEach(function (vid) {
                byVisit[vid].forEach(function (r) { if (r.request_visit_id === id) r.acked_by_me = true; });
            });
        });
    }
    function flushPending() {
        var list = readPending();
        if (!list.length || !navigator.onLine) return;
        var next = list.slice();
        list.reduce(function (p, id) {
            return p.then(function () {
                return post({ mode: 'ack', request_visit_id: id }).then(function (d) {
                    if (d && d.ok) next = next.filter(function (x) { return x !== id; });
                }).catch(function () {});
            });
        }, Promise.resolve()).then(function () { writePending(next); });
    }

    function unacked(visitId) {
        return (byVisit[String(visitId)] || []).filter(function (r) { return r.status === 'attached' && !r.acked_by_me; });
    }

    function itemsHtml(r) {
        var h = '';
        if (r.included && r.included.length) {
            h += '<div class="mw-sr-sub">Part of today\'s work</div><ul class="mw-sr-list">' +
                r.included.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
        }
        if (r.extra && r.extra.length) {
            h += '<div class="mw-sr-sub is-extra">Extra — decide on site</div><ul class="mw-sr-list is-extra">' +
                r.extra.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
        }
        return h;
    }

    function fromLine(r) {
        var who = (r.from_name || '') + (r.company_name ? (r.from_name ? ' · ' : '') + r.company_name : '');
        return (who ? 'From ' + esc(who) : 'From the office') + ' · via ' + esc(r.head_name) + ' (' + esc(r.head_role) + ')';
    }

    // ── Full-screen gate: created when needed, removed on dismiss ───────────────────────
    function showGate(visitId) {
        var list = unacked(visitId);
        if (!list.length) return false;
        closeGate();
        var r = list[0];
        gateEl = document.createElement('div');
        gateEl.className = 'mw-sr-gate';
        gateEl.setAttribute('role', 'dialog');
        gateEl.setAttribute('aria-modal', 'true');
        gateEl.setAttribute('aria-labelledby', 'mw-sr-gate-title');
        gateEl.innerHTML =
            '<div class="mw-sr-gate-panel">' +
            '  <div class="mw-sr-gate-head">' +
            '    <img src="/crm/img/heads/' + (r.head === 'yui' ? 'yui' : 'otto') + '.jpg" alt="" width="44" height="44">' +
            '    <div><div class="mw-sr-gate-kicker">Special request' + (list.length > 1 ? ' (1 of ' + list.length + ')' : '') + '</div>' +
            '    <h2 id="mw-sr-gate-title">' + esc(r.address || 'This visit') + '</h2>' +
            '    <div class="mw-sr-from">' + fromLine(r) + '</div></div>' +
            '  </div>' +
            (r.client_words ? '<blockquote class="mw-sr-quote">' + esc(r.client_words).replace(/\n/g, '<br>') + '</blockquote>' : '') +
            itemsHtml(r) +
            '  <p class="mw-sr-gate-note">Read it before you start. After <b>Got it</b>, tap Start or the camera again.</p>' +
            '  <div class="mw-sr-gate-actions">' +
            '    <button type="button" class="mw-sr-btn" data-sr="back">Back</button>' +
            '    <button type="button" class="mw-sr-btn is-primary" data-sr="ack">Got it</button>' +
            '  </div>' +
            '</div>';
        gateEl.addEventListener('click', function (e) {
            var b = e.target.closest('[data-sr]');
            if (!b) return;
            if (b.getAttribute('data-sr') === 'back') { closeGate(); return; }
            b.disabled = true;
            b.textContent = 'Saving…';
            ack(r).then(function () {
                closeGate();
                renderAll();
                if (unacked(visitId).length) { showGate(visitId); return; }
                toast('Got it — now tap Start or the camera again.');
            });
        });
        document.body.appendChild(gateEl);
        document.documentElement.classList.add('mw-sr-lock');
        var btn = gateEl.querySelector('[data-sr="ack"]');
        if (btn) btn.focus();
        return true;
    }

    function closeGate() {
        if (gateEl && gateEl.parentNode) gateEl.parentNode.removeChild(gateEl);
        gateEl = null;
        document.documentElement.classList.remove('mw-sr-lock');
    }

    function ack(r) {
        return post({ mode: 'ack', request_visit_id: r.request_visit_id }).then(function (d) {
            if (d && d.ok && d.request) { replace(d.request); return; }
            // Server said no (flag off / gone): don't trap the crew behind a screen they can't clear.
            r.acked_by_me = true;
            if (d && d.error) toast(d.error, 'warning');
        }).catch(function () {
            // No signal: saved on this phone, sent when back online (the start will queue too).
            r.acked_by_me = true;
            var list = readPending();
            if (list.indexOf(r.request_visit_id) === -1) list.push(r.request_visit_id);
            writePending(list);
            toast('Saved on this phone — it will send when you have signal.', 'info');
        });
    }

    function replace(req) {
        var list = byVisit[String(req.visit_id)] || [];
        var found = false;
        list = list.map(function (x) { if (x.request_visit_id === req.request_visit_id) { found = true; return req; } return x; });
        if (!found) list.push(req);
        byVisit[String(req.visit_id)] = list;
    }

    // ── Inline card in the job card ─────────────────────────────────────────────────────
    function cardFor(visitId) {
        var el = document.querySelector('.mw-mc-card[data-visit-id="' + visitId + '"]');
        if (el) return el;
        var pill = document.querySelector('[data-visit-id="' + visitId + '"]');
        return pill ? pill.closest('.mw-mc-card') : null;
    }

    function renderAll() {
        document.querySelectorAll('.mw-sr-card').forEach(function (n) { n.parentNode.removeChild(n); });
        Object.keys(byVisit).forEach(function (vid) {
            var card = cardFor(vid);
            if (!card) return;
            byVisit[vid].forEach(function (r) { insertCard(card, r); });
        });
        if (cfg.canAdd) renderAddLinks();
    }

    function outcomeText(o) {
        if (!o) return '';
        var label = { done: 'Done', not_done: 'Not done', extra_done: 'Extra work done' }[o.status] || o.status;
        return label + (o.reason ? ' — ' + esc(o.reason) : '') +
            (o.status === 'extra_done' ? ' — ' + esc(o.extra_description || '') + ' (' + (o.extra_minutes || 0) + ' min)' : '') +
            (o.by_name ? ' · ' + esc(o.by_name) : '');
    }

    function insertCard(card, r) {
        var box = document.createElement('div');
        box.className = 'mw-sr-card' + (r.status === 'attached' ? '' : ' is-answered');
        box.setAttribute('data-nosearch', '');
        box.setAttribute('data-sr-id', r.request_visit_id);
        var acks = (r.acks || []).map(function (a) { return esc(a.name); }).join(', ');
        box.innerHTML =
            '<div class="mw-sr-card-head"><span class="mw-sr-badge">Special request</span>' +
            '<span class="mw-sr-from">' + fromLine(r) + '</span></div>' +
            (r.client_words ? '<blockquote class="mw-sr-quote">' + esc(r.client_words).replace(/\n/g, '<br>') + '</blockquote>' : '') +
            itemsHtml(r) +
            '<div class="mw-sr-meta">' + (acks ? 'Read by ' + acks : 'Not read yet') + '</div>' +
            (r.status === 'attached'
                ? '<div class="mw-sr-actions">' +
                  (r.acked_by_me ? '' : '<button type="button" class="mw-sr-btn is-primary" data-sr="read">Read it</button>') +
                  '<button type="button" class="mw-sr-btn" data-sr="done">Done</button>' +
                  '<button type="button" class="mw-sr-btn" data-sr="not_done">Not done</button>' +
                  ((r.extra && r.extra.length) ? '<button type="button" class="mw-sr-btn is-extra" data-sr="extra_done">Extra work done</button>' : '') +
                  '</div><div class="mw-sr-form" hidden></div>'
                : '<div class="mw-sr-outcome">' + outcomeText(r.outcome) + '</div>');
        box.addEventListener('click', function (e) {
            var b = e.target.closest('[data-sr]');
            if (!b) return;
            e.stopPropagation(); // the card itself opens/flips on click
            var what = b.getAttribute('data-sr');
            if (what === 'read') { showGate(r.visit_id); return; }
            if (what === 'done') { answer(r, 'done', {}); return; }
            if (what === 'not_done' || what === 'extra_done') { openForm(box, r, what); return; }
            if (what === 'send') {
                var f = box.querySelector('.mw-sr-form');
                var outcome = f.getAttribute('data-outcome');
                answer(r, outcome, {
                    reason: (f.querySelector('[name=reason]') || {}).value || '',
                    extra_description: (f.querySelector('[name=desc]') || {}).value || '',
                    extra_minutes: parseInt((f.querySelector('[name=minutes]') || {}).value, 10) || 0
                });
            }
            if (what === 'cancel') { box.querySelector('.mw-sr-form').hidden = true; }
        });
        var header = card.querySelector('.mw-mc-card-header');
        if (header && header.parentNode) header.parentNode.insertBefore(box, header.nextSibling);
        else (card.querySelector('.mw-mc-card-body') || card).insertBefore(box, (card.querySelector('.mw-mc-card-body') || card).firstChild);
    }

    function openForm(box, r, outcome) {
        var f = box.querySelector('.mw-sr-form');
        f.setAttribute('data-outcome', outcome);
        f.innerHTML = outcome === 'not_done'
            ? '<label class="mw-sr-label">Why not?<textarea name="reason" rows="2" class="form-control" placeholder="e.g. lawn too wet"></textarea></label>'
            : '<label class="mw-sr-label">What did you do?<input name="desc" class="form-control" value="' + esc((r.extra || [])[0] || '') + '"></label>' +
              '<label class="mw-sr-label">Minutes it took<input name="minutes" type="number" min="5" step="5" inputmode="numeric" class="form-control" placeholder="15"></label>';
        f.innerHTML += '<div class="mw-sr-actions"><button type="button" class="mw-sr-btn" data-sr="cancel">Cancel</button>' +
            '<button type="button" class="mw-sr-btn is-primary" data-sr="send">Save</button></div>';
        f.hidden = false;
    }

    function answer(r, outcome, extra) {
        var body = { mode: 'outcome', request_visit_id: r.request_visit_id, outcome: outcome };
        Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
        return post(body).then(function (d) {
            if (d && d.ok && d.request) {
                replace(d.request);
                renderAll();
                toast(outcome === 'extra_done' ? 'Saved — the extra work is on the visit.' : 'Saved.');
            } else {
                toast((d && d.error) || 'Could not save — try again.', 'error');
            }
        }).catch(function () { toast('No signal — try again when you have signal.', 'error'); });
    }

    // ── Office: "Add special request" on a visit (jobs.edit only) ───────────────────────
    function renderAddLinks() {
        document.querySelectorAll('.mw-mc-card[data-visit-id]').forEach(function (card) {
            if (card.querySelector('.mw-sr-add')) return;
            var vid = parseInt(card.getAttribute('data-visit-id'), 10);
            if (!(vid > 0)) return;
            var wrap = document.createElement('div');
            wrap.className = 'mw-sr-add';
            wrap.setAttribute('data-nosearch', '');
            wrap.innerHTML = '<button type="button" class="mw-sr-link" data-sr-add>+ Special request</button>';
            wrap.addEventListener('click', function (e) {
                var b = e.target.closest('[data-sr-add],[data-sr-add-send],[data-sr-add-cancel]');
                if (!b) return;
                e.stopPropagation();
                if (b.hasAttribute('data-sr-add')) {
                    wrap.innerHTML = '<label class="mw-sr-label">What the client asked (their words)' +
                        '<textarea rows="3" class="form-control" name="words"></textarea></label>' +
                        '<div class="mw-sr-actions"><button type="button" class="mw-sr-btn" data-sr-add-cancel>Cancel</button>' +
                        '<button type="button" class="mw-sr-btn is-primary" data-sr-add-send>Attach + tell the crew</button></div>';
                    return;
                }
                if (b.hasAttribute('data-sr-add-cancel')) { wrap.parentNode.removeChild(wrap); renderAddLinks(); return; }
                var words = (wrap.querySelector('[name=words]') || {}).value || '';
                if (!words.trim()) { toast('Type the request first.', 'warning'); return; }
                b.disabled = true;
                post({ mode: 'create', visit_ids: [vid], client_words: words }).then(function (d) {
                    if (d && d.ok) { toast('Attached — the crew leader is texted and the crew get a push.'); wrap.parentNode.removeChild(wrap); load(); }
                    else { b.disabled = false; toast((d && d.error) || 'Could not attach.', 'error'); }
                });
            });
            var header = card.querySelector('.mw-mc-card-header');
            if (header && header.parentNode) header.parentNode.insertBefore(wrap, header.nextSibling);
        });
    }

    window.MwSpecialRequest = {
        /** True (and the request is on screen) when this visit can't be started / photographed yet. */
        blocks: function (visitId) { return showGate(visitId); },
        /** The server refused a start / photo (409): show what it sent. */
        fromServer: function (visitId, requests) {
            (requests || []).forEach(function (r) { if (r && r.request_visit_id) replace(r); });
            renderAll();
            if (!showGate(visitId)) toast('Special request on this visit — read it first.', 'warning');
        },
        reload: load
    };

    function init() {
        load();
        flushPending();
        window.addEventListener('online', flushPending);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && Date.now() - loadedAt > 60000) { load(); flushPending(); }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
