/**
 * MwSpecialRequest gate test (public/crm/js/mw-special-request.js) — runs in Node, no browser.
 *
 *   node tests/js/special-request-gate-test.js
 *
 * Proves the rules set after the 2026-10-08 frozen-taps incident:
 *   - nothing is added to the DOM while no request is shown; no capture-phase listeners;
 *   - blocks(visitId) is synchronous: true + the screen when this person hasn't read it,
 *     false (and no element) otherwise — so the Start / camera handler returns before acting;
 *   - "Got it" posts the ack, REMOVES the screen, and the next tap goes through;
 *   - with no signal the ack is kept on the phone and the crew are not trapped;
 *   - a 409 from the server (old cached page path) shows the screen via fromServer().
 * Exit code: 0 = pass, 1 = failures.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let failed = 0, passed = 0;
function check(cond, label) { if (cond) { passed++; } else { failed++; console.log('  ✗ ' + label); } }

const SRC = fs.readFileSync(path.join(__dirname, '../../public/crm/js/mw-special-request.js'), 'utf8');

function makeEnv(opts) {
    const listeners = [];           // [target, type, capture]
    const bodyChildren = [];
    const htmlClasses = new Set();
    const posts = [];
    const storage = {};

    function el(tag) {
        const handlers = {};
        const e = {
            tagName: tag, className: '', attrs: {}, innerHTML: '', parentNode: null, hidden: false,
            setAttribute(k, v) { this.attrs[k] = String(v); },
            getAttribute(k) { return this.attrs[k]; },
            addEventListener(t, h, cap) { listeners.push([this, t, !!cap]); handlers[t] = h; },
            querySelector() { return { focus() {}, disabled: false }; },
            removeChild(c) { const i = bodyChildren.indexOf(c); if (i >= 0) bodyChildren.splice(i, 1); c.parentNode = null; },
            _fire(t, ev) { handlers[t](ev); },
        };
        return e;
    }
    const body = { appendChild(c) { bodyChildren.push(c); c.parentNode = body; },
                   removeChild(c) { const i = bodyChildren.indexOf(c); if (i >= 0) bodyChildren.splice(i, 1); c.parentNode = null; } };
    const document = {
        readyState: 'complete', body,
        documentElement: { classList: { add: c => htmlClasses.add(c), remove: c => htmlClasses.delete(c) } },
        createElement: el,
        querySelector: () => null,
        querySelectorAll: (sel) => {
            if (sel.indexOf('[data-visit-id]') !== -1 && sel.indexOf('.mw-sr') === -1) {
                return [{ getAttribute: () => '5' }];
            }
            return [];
        },
        addEventListener(t, h, cap) { listeners.push([document, t, !!cap]); },
        visibilityState: 'visible',
    };
    const window = {
        MW_CSRF_TOKEN: 'tok', MW_SR: { canAdd: false },
        addEventListener(t, h, cap) { listeners.push([window, t, !!cap]); },
        mwToast() {},
    };
    let ackMode = opts.ackMode || 'ok';
    function fetchStub(url, o) {
        if (!o || o.method !== 'POST') {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, enabled: true, requests: opts.requests || {} }) });
        }
        const b = JSON.parse(o.body);
        posts.push(b);
        if (ackMode === 'offline') return Promise.reject(new TypeError('Failed to fetch'));
        const req = Object.assign({}, (opts.requests[5] || [])[0], { acked_by_me: true, acks: [{ user_id: 31, name: 'Nigel', at: 'now' }] });
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, request: req }) });
    }
    const ctx = {
        window, document, fetch: fetchStub, console,
        navigator: { onLine: ackMode !== 'offline' },
        localStorage: { getItem: k => storage[k] || null, setItem: (k, v) => { storage[k] = v; } },
        setTimeout, Promise, JSON, Date, String, Object, parseInt,
    };
    ctx.window.document = document;
    vm.createContext(ctx);
    vm.runInContext(SRC, ctx);
    return { ctx, window, bodyChildren, listeners, posts, storage, htmlClasses };
}

const tick = () => new Promise(r => setTimeout(r, 0));
const REQ = { request_visit_id: 77, request_id: 9, visit_id: 5, head: 'yui', head_name: 'Yui', head_role: 'Client comms',
    from_name: 'Michelle Henry', company_name: 'Pacific Spirit United Church', client_words: 'Please mow the front lawns',
    included: ['Mow the front lawns'], extra: ['Rake the leaves NW corner'], status: 'attached', acked_by_me: false, acks: [] };

(async function () {
    // 1. No request on the visit → never blocks, nothing in the DOM.
    {
        const env = makeEnv({ requests: {} });
        await tick(); await tick();
        check(env.window.MwSpecialRequest.blocks(5) === false, 'no request: blocks() is false');
        check(env.bodyChildren.length === 0, 'no request: nothing appended to <body>');
        check(env.listeners.every(l => !l[2]), 'no capture-phase listeners');
    }
    // 2. Unread request → blocks + one screen; Got it → removed; next tap passes.
    {
        const env = makeEnv({ requests: { 5: [Object.assign({}, REQ)] } });
        await tick(); await tick();
        check(env.bodyChildren.length === 0, 'loaded: no screen until a tap');
        check(env.window.MwSpecialRequest.blocks(5) === true, 'unread: blocks() is true (Start / camera handler returns)');
        check(env.bodyChildren.length === 1 && env.bodyChildren[0].className === 'mw-sr-gate', 'unread: the request screen is on screen');
        check(env.htmlClasses.has('mw-sr-lock'), 'page scroll locked while shown');
        env.window.MwSpecialRequest.blocks(5);
        check(env.bodyChildren.length === 1, 'a second tap does not stack screens');
        check(env.window.MwSpecialRequest.blocks(6) === false, 'another visit is not blocked');
        // Tap "Got it"
        const gate = env.bodyChildren[0];
        gate._fire('click', { target: { closest: () => ({ getAttribute: () => 'ack', disabled: false, textContent: '' }) } });
        await tick(); await tick(); await tick();
        check(env.posts.length === 1 && env.posts[0].mode === 'ack' && env.posts[0].request_visit_id === 77, 'Got it posts the ack');
        check(env.bodyChildren.length === 0, 'Got it removes the screen from the DOM');
        check(!env.htmlClasses.has('mw-sr-lock'), 'scroll lock released');
        check(env.window.MwSpecialRequest.blocks(5) === false, 'after Got it the next Start / photo tap goes through');
    }
    // 3. Back closes without acking — still blocked next time.
    {
        const env = makeEnv({ requests: { 5: [Object.assign({}, REQ)] } });
        await tick(); await tick();
        env.window.MwSpecialRequest.blocks(5);
        env.bodyChildren[0]._fire('click', { target: { closest: () => ({ getAttribute: () => 'back' }) } });
        check(env.bodyChildren.length === 0, 'Back removes the screen');
        check(env.posts.length === 0, 'Back does not ack');
        check(env.window.MwSpecialRequest.blocks(5) === true, 'still blocked after Back');
    }
    // 4. Already read by this person → not blocked.
    {
        const env = makeEnv({ requests: { 5: [Object.assign({}, REQ, { acked_by_me: true })] } });
        await tick(); await tick();
        check(env.window.MwSpecialRequest.blocks(5) === false, 'read already: not blocked');
    }
    // 5. No signal on Got it → kept on the phone, crew not trapped.
    {
        const env = makeEnv({ requests: { 5: [Object.assign({}, REQ)] }, ackMode: 'offline' });
        await tick(); await tick();
        env.window.MwSpecialRequest.blocks(5);
        env.bodyChildren[0]._fire('click', { target: { closest: () => ({ getAttribute: () => 'ack', disabled: false, textContent: '' }) } });
        await tick(); await tick(); await tick();
        check(env.bodyChildren.length === 0, 'offline ack: screen removed');
        check(env.window.MwSpecialRequest.blocks(5) === false, 'offline ack: not trapped');
        check((env.storage.mwSrPendingAcks || '').indexOf('77') !== -1, 'offline ack kept on the phone to send later');
    }
    // 6. Server 409 (fromServer) → screen.
    {
        const env = makeEnv({ requests: {} });
        await tick(); await tick();
        env.window.MwSpecialRequest.fromServer(5, [Object.assign({}, REQ)]);
        check(env.bodyChildren.length === 1, '409 from the server shows the request screen');
    }

    console.log((failed ? '\x1b[31m' : '\x1b[32m') + `special-request-gate: ${passed} passed, ${failed} failed\x1b[0m`);
    process.exit(failed ? 1 : 0);
})();
