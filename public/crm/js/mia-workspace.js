/**
 * Mia: Search & Website workspace (Phase 1.13). Talks to /crm/api/mia.php (MiaApiHandler).
 *
 * Rules this file keeps:
 *  - Everything that comes from the server (page titles, search queries, reasons, wording) is put on the page with
 *    textContent / setAttribute only. There is no innerHTML anywhere, so crawled or typed text can never become markup.
 *  - Approving needs the hash of what was shown (seen_hash). If the wording changed, the server answers 409 and this
 *    file shows the new wording and asks again. Nothing is approved on a stale screen.
 *  - Approving only records a decision. Mia does not change the website in Phase 1, and the page says so.
 */
(function () {
  'use strict';

  var root = document.getElementById('mw-mws');
  if (!root) return;
  var API = root.getAttribute('data-api') || '/crm/api/mia.php';
  var TABS = ['presence', 'growth', 'results'];
  var state = { tab: null, waiting: 0, filter: 'proposed', openId: null };
  var gen = { presence: 0, growth: 0, results: 0 };          // a response older than the latest request is thrown away
  function csrf() { return window.MW_CSRF_TOKEN || root.getAttribute('data-csrf') || ''; }

  // ── tiny DOM helpers (text only) ──────────────────────────────────────────────────────────────
  function el(tag, cls, text, attrs) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    return n;
  }
  function add(parent) {
    for (var i = 1; i < arguments.length; i++) if (arguments[i]) parent.appendChild(arguments[i]);
    return parent;
  }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function $(id) { return document.getElementById(id); }
  function btn(label, cls, onClick, attrs) {
    var b = el('button', 'mw-mws-btn ' + (cls || ''), label, Object.assign({ type: 'button' }, attrs || {}));
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }
  function when(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(s);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }
  var LABELS = { page: 'Page', impressions: 'Times shown in search', clicks: 'Clicks', avg_position: 'Average position', typical_ctr: 'Typical click rate at that position',
    impressions_latest: 'Times shown (latest)', clicks_earlier: 'Clicks (earlier period)', clicks_latest: 'Clicks (latest period)', drop_percent: 'Drop in clicks (%)',
    best_position: 'Best position', query: 'Search', rule: 'Check', severity: 'How serious', pages: 'Pages affected', impressions_on_affected_pages: 'Times shown (affected pages)' };
  function pretty(k) { return LABELS[k] || String(k).replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); }); }
  function value(k, v) { return k === 'typical_ctr' && typeof v === 'number' ? Math.round(v * 1000) / 10 + '%' : v; }

  // Status is always an icon AND words (never colour alone).
  var ICONS = {
    ok:   'M20 6L9 17l-5-5',
    wait: 'M12 6v6l4 2M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
    warn: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    no:   'M18 6L6 18M6 6l12 12'
  };
  function status(kind, text) {
    var s = el('span', 'mw-mws-status mw-mws-status--' + kind);
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('width', '16'); svg.setAttribute('height', '16');
    svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', ICONS[kind] || ICONS.wait);
    svg.appendChild(p);
    s.appendChild(svg);
    s.appendChild(el('span', '', text));
    return s;
  }

  // ── network ───────────────────────────────────────────────────────────────────────────────────
  function request(method, mode, data) {
    var url = API, opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method === 'GET') {
      var q = ['mode=' + encodeURIComponent(mode)];
      Object.keys(data || {}).forEach(function (k) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k])); });
      url += '?' + q.join('&');
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(Object.assign({ mode: mode, csrf_token: csrf() }, data || {}));
    }
    return fetch(url, opts).then(function (r) {
      return r.text().then(function (t) {
        var body = {};
        try { body = JSON.parse(t); } catch (e) { body = { ok: false, error: 'Mia did not answer properly. Try again.' }; }
        return { status: r.status, body: body };
      });
    }, function () { return { status: 0, body: { ok: false, error: 'No connection. Check your internet and try again.' } }; });
  }
  function flash(text, bad) {
    var f = $('mws-flash');
    if (!text) { f.hidden = true; return; }
    f.textContent = text;
    f.className = 'mw-mws-flash' + (bad ? ' mw-mws-flash--bad' : '');
    f.hidden = false;
    if (f.scrollIntoView) f.scrollIntoView({ block: 'nearest' });
  }
  function failText(res) {
    var b = res.body || {};
    if (res.status === 403) return 'You do not have permission, or the page expired. Refresh and try again.';
    if (res.status === 503) return b.error || 'Mia is not set up yet.';
    return b.error || 'Something went wrong.';
  }

  // ── tabs ──────────────────────────────────────────────────────────────────────────────────────
  function showTab(name, push) {
    if (TABS.indexOf(name) < 0) name = 'presence';
    state.tab = name;
    TABS.forEach(function (t) {
      var on = t === name;
      $('mws-' + t).hidden = !on;
      var tb = $('mws-tab-' + t);
      tb.setAttribute('aria-selected', on ? 'true' : 'false');
      tb.tabIndex = on ? 0 : -1;
      tb.classList.toggle('is-on', on);
    });
    if (push && history.replaceState) history.replaceState(null, '', '#' + name);
    flash('');
    if (name === 'presence') loadPresence();
    if (name === 'growth') loadGrowth();
    if (name === 'results') loadResults();
  }
  Array.prototype.forEach.call(root.querySelectorAll('.mw-mws-tab'), function (t) {
    t.addEventListener('click', function () { showTab(t.getAttribute('data-tab'), true); });
    t.addEventListener('keydown', function (e) {
      var i = TABS.indexOf(t.getAttribute('data-tab'));
      if (e.key === 'ArrowRight') { showTab(TABS[(i + 1) % 3], true); $('mws-tab-' + state.tab).focus(); }
      if (e.key === 'ArrowLeft') { showTab(TABS[(i + 2) % 3], true); $('mws-tab-' + state.tab).focus(); }
    });
  });

  function setWaiting(n) {
    state.waiting = n;
    var c = $('mws-count');
    c.textContent = n; c.hidden = !n;
    $('mws-sub').textContent = (n ? 'Mia has ' + n + ' suggestion' + (n === 1 ? '' : 's') + ' waiting for you. ' : 'Nothing is waiting for you. ')
      + 'She suggests and you decide: nothing changes on your website without you.';
  }

  // ── Presence ──────────────────────────────────────────────────────────────────────────────────
  function loadPresence() {
    var host = $('mws-presence'), g = ++gen.presence;
    request('GET', 'health').then(function (res) {
      if (g !== gen.presence) return;
      clear(host);
      if (!res.body.ok) { add(host, el('p', 'mw-mws-note mw-mws-note--bad', failText(res))); return; }
      var sites = res.body.websites || [];
      add(host, el('h2', 'mw-mws-h2', 'Your websites'));
      if (!sites.length) add(host, el('p', 'mw-mws-note', 'No website is connected yet. Add yours below so Mia can look at it. She only reads it. She never changes it.'));
      var list = el('div', 'mw-mws-rows');
      sites.forEach(function (w) { add(list, siteRow(w)); });
      add(host, list, connectForm(sites.length > 0));
    });
  }

  function siteRow(w) {
    var row = el('article', 'mw-mws-row');
    var head = el('div', 'mw-mws-rowhead');
    add(head, el('strong', 'mw-mws-name', w.host), w.status === 'verified' && w.can_crawl ? status('ok', 'Verified. Mia may look at it') : status('wait', w.status === 'verified' ? 'Needs checking again' : 'Not verified yet'));
    add(row, head);

    if (!(w.status === 'verified' && w.can_crawl)) {
      add(row, el('p', 'mw-mws-note', 'To prove this website is yours, do ONE of the things below, then press Check now. Mia will not look at the site until you do.'));
      add(row, instructions(w.id));
      var actions = el('div', 'mw-mws-actions');
      add(actions, btn('Check now', 'mw-mws-btn--primary', function (e) { verify(w.id, e.currentTarget); }), btn('Disconnect', 'mw-mws-btn--quiet', function () { disconnect(w.id, w.host); }));
      add(row, actions);
      return row;
    }

    var facts = el('div', 'mw-mws-facts');
    add(facts, el('span', '', w.pages + ' page' + (w.pages === 1 ? '' : 's') + ' checked'));
    add(facts, el('span', '', w.last_crawl ? 'Last look: ' + when(w.last_crawl.at) + (w.last_crawl.status === 'partial' ? ' (partly)' : '') : 'Not looked at yet. Mia looks every Monday morning.'));
    add(row, facts);

    var f = w.findings || {};
    var total = (f.high || 0) + (f.medium || 0) + (f.low || 0);
    if (w.last_crawl) {
      add(row, total ? status(f.high ? 'warn' : 'wait', total + ' thing' + (total === 1 ? '' : 's') + ' to look at (' + (f.high || 0) + ' important)') : status('ok', 'Nothing to fix right now'));
      if ((w.problems || []).length) {
        var pl = el('ul', 'mw-mws-problems');
        w.problems.forEach(function (p) {
          var li = el('li', 'mw-mws-problem');
          add(li, status(p.severity === 'high' ? 'warn' : 'wait', p.severity === 'high' ? 'Important' : p.severity === 'medium' ? 'Worth fixing' : 'Minor'),
            el('span', 'mw-mws-problem-t', p.label + (p.pages > 1 ? ' (' + p.pages + ' pages)' : '')));
          if (p.why) add(li, el('span', 'mw-mws-problem-why', p.why));
          add(pl, li);
        });
        add(row, pl);
      }
    }
    var sc = w.search_console || {};
    add(row, sc.connected
      ? status('ok', 'Google Search Console connected' + (sc.last_data ? '. Latest data ' + when(sc.last_data) : '. No data yet'))
      : status('wait', 'Google Search Console not connected. Mia needs it to see how people find you'));
    if (!sc.connected) {
      var a = el('a', 'mw-mws-link', 'Connect Search Console', { href: '/crm/gsc/connect.php' });
      add(row, a);
    }
    add(row, btn('Disconnect', 'mw-mws-btn--quiet', function () { disconnect(w.id, w.host); }));
    return row;
  }

  function instructions(id) {
    var box = el('div', 'mw-mws-how', 'Loading the steps…');
    request('GET', 'websites').then(function (res) {
      clear(box);
      var w = ((res.body && res.body.websites) || []).filter(function (x) { return x.id === id; })[0];
      if (!w || !w.instructions) { box.textContent = 'The steps are not available. Refresh the page.'; return; }
      var ins = w.instructions;
      function option(title, lines) {
        var d = el('div', 'mw-mws-opt');
        add(d, el('strong', '', title));
        lines.forEach(function (l) {
          var r = el('div', 'mw-mws-copy');
          add(r, el('code', 'mw-mws-code', l.text));
          add(r, btn('Copy', 'mw-mws-btn--quiet', function (e) { copy(l.text, e.currentTarget); }, { 'aria-label': 'Copy: ' + l.what }));
          if (l.what) add(d, el('span', 'mw-mws-opt-what', l.what));
          add(d, r);
        });
        return d;
      }
      add(box,
        option('Option 1: add a tag to your home page', [{ what: 'Paste this inside the <head> of your home page:', text: ins.meta.snippet }]),
        option('Option 2: upload a small file', [{ what: 'Create a file at ' + ins.file.path + ' on your website containing exactly:', text: ins.file.contents }]),
        option('Option 3: add a DNS record', [{ what: 'Add a TXT record for ' + ins.dns.host + ' with this value:', text: ins.dns.value }]));
    });
    return box;
  }
  function copy(text, button) {
    function done() { var o = button.textContent; button.textContent = 'Copied'; setTimeout(function () { button.textContent = o; }, 1500); }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { flash('Select the text and copy it by hand.', true); });
    else flash('Select the text and copy it by hand.', true);
  }

  function connectForm(hasSites) {
    // With a website already connected this is "add ANOTHER one": closed until asked for, so it does not look like a to-do.
    var wrap = el('div', 'mw-mws-add');
    var f = el('form', 'mw-mws-connect');
    var label = el('label', 'mw-mws-lbl', hasSites ? 'Address of the other website' : 'Your website address', { 'for': 'mws-address' });
    var input = el('input', 'mw-mws-input', null, { id: 'mws-address', type: 'text', inputmode: 'url', autocomplete: 'off', placeholder: 'yourbusiness.ca', maxlength: '255' });
    var go = btn('Connect website', 'mw-mws-btn--primary', null, { type: 'submit' });
    add(f, label, input, go);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!input.value.trim()) { flash('Type your website address first.', true); return; }
      go.disabled = true;
      request('POST', 'connect_website', { address: input.value.trim() }).then(function (res) {
        go.disabled = false;
        if (!res.body.ok) { flash(failText(res), true); return; }
        flash(res.body.existing ? 'That website was already connected.' : 'Connected. Now prove it is yours with one of the options below.');
        loadPresence();
      });
    });
    if (!hasSites) { add(wrap, f); return wrap; }
    f.hidden = true;
    var open = btn('Add another website', 'mw-mws-btn--quiet', function () { f.hidden = !f.hidden; open.setAttribute('aria-expanded', f.hidden ? 'false' : 'true'); if (!f.hidden) input.focus(); }, { 'aria-expanded': 'false' });
    add(wrap, open, f);
    return wrap;
  }
  function verify(id, button) {
    button.disabled = true;
    request('POST', 'verify_website', { id: id }).then(function (res) {
      button.disabled = false;
      if (res.body.ok) { flash('Verified. Mia can now look at your website.'); loadPresence(); setTimeout(function () { flash(''); }, 6000); return; }
      flash(res.status === 429 ? 'Please wait a few seconds, then press Check now again.' : (res.body.error || failText(res)), true);
    });
  }
  function disconnect(id, host) {
    if (!window.confirm('Disconnect ' + host + '? Mia will stop looking at it. You can connect it again later.')) return;
    request('POST', 'disconnect_website', { id: id }).then(function (res) {
      if (!res.body.ok) { flash(failText(res), true); return; }
      flash('Disconnected.'); loadPresence();
    });
  }

  // ── Growth: the queue ─────────────────────────────────────────────────────────────────────────
  var FILTERS = [['proposed', 'Waiting'], ['approved', 'Approved'], ['done', 'Applied'], ['rejected', 'Set aside'], ['expired', 'Passed']];

  function loadGrowth(focus) {
    var host = $('mws-growth'), g = ++gen.growth, filter = state.filter;
    request('GET', 'actions', { status: filter }).then(function (res) {
      if (g !== gen.growth || filter !== state.filter) return;
      clear(host);
      if (!res.body.ok) { add(host, el('p', 'mw-mws-note mw-mws-note--bad', failText(res))); return; }
      if (res.body.counts) setWaiting(parseInt(res.body.counts.proposed || 0, 10));
      var bar = el('div', 'mw-mws-filters', null, { role: 'group', 'aria-label': 'Show' });
      FILTERS.forEach(function (f) {
        var b = btn(f[1], 'mw-mws-pill' + (state.filter === f[0] ? ' is-on' : ''), function () { state.filter = f[0]; state.openId = null; loadGrowth('filter'); }, { 'aria-pressed': state.filter === f[0] ? 'true' : 'false' });
        add(bar, b);
      });
      add(host, bar);
      var items = res.body.items || [];
      if (!items.length) {
        add(host, el('p', 'mw-mws-empty', state.filter === 'proposed'
          ? 'Nothing is waiting. Mia looks again every Monday morning. If no website is connected yet, start on the Presence tab.'
          : 'Nothing here yet.'));
        return;
      }
      var list = el('div', 'mw-mws-rows');
      items.forEach(function (it) { add(list, actionRow(it)); });
      add(host, list);
      restoreFocus(host, focus);
    });
  }
  // Re-drawing removes the control that had focus: put it back, so keyboard users are not thrown to the top of the page.
  function restoreFocus(host, focus) {
    var t = null;
    if (focus === 'row' && state.openId) t = host.querySelector('[data-id="' + state.openId + '"] .mw-mws-rowbtn');
    else if (focus === 'filter') t = host.querySelector('.mw-mws-pill.is-on');
    if (t && t.focus) t.focus();
  }

  function actionRow(it) {
    var open = state.openId === it.id;
    var row = el('article', 'mw-mws-row mw-mws-action' + (open ? ' is-open' : ''), null, { 'data-id': it.id });
    var head = el('button', 'mw-mws-rowhead mw-mws-rowbtn', null, { type: 'button', 'aria-expanded': open ? 'true' : 'false' });
    add(head, el('span', 'mw-mws-type', it.type_label), el('strong', 'mw-mws-name', it.title));
    if (it.status === 'proposed') add(head, el('span', 'mw-mws-score', it.score !== null ? 'Priority ' + Math.round(it.score) : ''));
    else add(head, status(it.status === 'approved' || it.status === 'done' ? 'ok' : 'no', it.status === 'approved' ? 'Approved' : it.status === 'done' ? 'Applied' : it.status === 'rolled_back' ? 'Undone' : it.status === 'rejected' ? 'Set aside' : 'Passed'));
    head.addEventListener('click', function () { state.openId = open ? null : it.id; loadGrowth('row'); });
    add(row, head);
    if (!open) { add(row, el('p', 'mw-mws-why', it.why)); return row; }
    var body = el('div', 'mw-mws-detail', 'Loading…');
    add(row, body);
    request('GET', 'action_detail', { id: it.id }).then(function (res) {
      if (!document.body.contains(body) || state.openId !== it.id) return;       // the row was closed or redrawn meanwhile
      clear(body);
      if (!res.body.ok) { add(body, el('p', 'mw-mws-note mw-mws-note--bad', failText(res))); return; }
      renderDetail(body, res.body.item);                                          // seen_hash lives with THIS render, not in a shared cache
    });
    return row;
  }

  function section(title, text, cls) {
    var s = el('div', 'mw-mws-sec' + (cls ? ' ' + cls : ''));
    add(s, el('h3', 'mw-mws-h3', title), el('p', 'mw-mws-p', text));
    return s;
  }

  function evidenceBlock(ev) {
    var wrap = el('div', 'mw-mws-sec');
    add(wrap, el('h3', 'mw-mws-h3', 'The numbers behind it'));
    var e = (ev && ev[0]) || null;
    if (!e) { add(wrap, el('p', 'mw-mws-p', 'No numbers were recorded.')); return wrap; }
    var dl = el('dl', 'mw-mws-dl');
    Object.keys(e).forEach(function (k) {
      var v = e[k];
      if (v === null || typeof v === 'object' || k === 'rule') return;     // 'rule' is an internal key, not owner wording
      add(dl, el('dt', '', pretty(k)), el('dd', '', value(k, v)));
    });
    add(wrap, dl);
    if (Array.isArray(e.top_queries) && e.top_queries.length) {
      add(wrap, el('h4', 'mw-mws-h4', 'What people searched for'));
      var ul = el('ul', 'mw-mws-list');
      e.top_queries.forEach(function (q) { add(ul, el('li', '', q.query + ' (' + q.impressions + ' views, ' + q.clicks + ' clicks)')); });
      add(wrap, ul);
    }
    if (Array.isArray(e.examples) && e.examples.length) {
      add(wrap, el('h4', 'mw-mws-h4', 'Examples'));
      var ul2 = el('ul', 'mw-mws-list');
      e.examples.forEach(function (u) { add(ul2, el('li', '', u)); });
      add(wrap, ul2);
    }
    return wrap;
  }

  function renderDetail(body, it) {
    add(body, section('Why Mia suggests it', it.why));
    add(body, section('What you might gain', it.expected_benefit || 'No estimate for this one.', 'mw-mws-sec--estimate'));
    add(body, section('What could go wrong', it.risk));
    add(body, evidenceBlock(it.evidence));
    if (it.draft) add(body, draftBlock(it));
    if (it.status === 'approved' && it.apply) add(body, applyBar(it));
    if (it.executions && it.executions.length) add(body, executionsBlock(it));
    if (!it.can_decide) {
      if (it.decision_note) add(body, section('Your note', it.decision_note));
      return;
    }
    add(body, decisionBar(it));
  }

  // Applying changes the live website, so it takes two clicks: the second button says what will happen.
  function applyBar(it) {
    var wrap = el('div', 'mw-mws-sec mw-mws-apply');
    var story = it.type === 'project_story';
    add(wrap, el('h3', 'mw-mws-h3', story ? 'Save the portfolio card' : 'Put it on your website'));
    if (!it.apply.can_apply) {
      add(wrap, el('p', 'mw-mws-p', 'Mia cannot apply this one for you. ' + (it.apply.why_not || '')));
      return wrap;
    }
    add(wrap, el('p', 'mw-mws-p', story
      ? 'Nothing has changed on your website yet. Saving puts a draft card in your Portfolio page. It stays hidden until you publish it there. You can undo it while it is still an untouched draft.'
      : 'Nothing has changed on your website yet. Applying changes the title and description on the live page. You can undo it afterwards.'));
    var actions = el('div', 'mw-mws-actions');
    var go = btn(story ? 'Save as a draft card' : 'Apply to my website', 'mw-mws-btn--primary', function () {
      clear(actions);
      var yes = btn(story ? 'Yes, save the draft card' : 'Yes, change my live page', 'mw-mws-btn--primary', function () { doApply(it, wrap); });
      add(actions, yes, btn('Cancel', 'mw-mws-btn--quiet', function () { loadGrowth('row'); }));
      yes.focus();
    });
    add(actions, go);
    add(wrap, actions);
    return wrap;
  }

  function doApply(it, wrap) {
    var all = wrap.querySelectorAll('button');
    Array.prototype.forEach.call(all, function (c) { c.disabled = true; });
    request('POST', 'action_apply', { id: it.id }).then(function (res) {
      if (!res.body.ok) { Array.prototype.forEach.call(all, function (c) { c.disabled = false; }); flash(res.body.error || failText(res), true); return; }
      flash(res.body.note || 'Applied.', res.body.status !== 'verified');
      state.filter = 'done'; state.openId = it.id; loadGrowth('filter');
    });
  }

  function executionsBlock(it) {
    var wrap = el('div', 'mw-mws-sec mw-mws-exec');
    add(wrap, el('h3', 'mw-mws-h3', 'What was changed'));
    var labels = { applied: 'Applied', verified: 'Applied and seen on the live page', unverified: 'Applied, not yet seen on the live page', rolled_back: 'Undone', failed: 'Failed' };
    it.executions.forEach(function (x) {
      var row = el('div', 'mw-mws-execrow');
      add(row, el('p', 'mw-mws-p', (labels[x.status] || x.status) + ' · ' + when(x.applied_at)));
      if (x.verify_note) add(row, el('p', 'mw-mws-fine', x.verify_note));
      if (x.status === 'applied' || x.status === 'verified' || x.status === 'unverified') {
        var box = el('div', 'mw-mws-actions');
        add(box, btn('Undo this change', 'mw-mws-btn--secondary', function () {
          clear(box);
          var yes = btn('Yes, put the old wording back', 'mw-mws-btn--primary', function () { doRollback(x, row); });
          add(box, yes, btn('Cancel', 'mw-mws-btn--quiet', function () { loadGrowth('row'); }));
          yes.focus();
        }));
        add(row, box);
      }
      add(wrap, row);
    });
    return wrap;
  }

  function doRollback(x, row) {
    var all = row.querySelectorAll('button');
    Array.prototype.forEach.call(all, function (c) { c.disabled = true; });
    request('POST', 'action_rollback', { execution_id: x.id }).then(function (res) {
      if (!res.body.ok) { Array.prototype.forEach.call(all, function (c) { c.disabled = false; }); flash(res.body.error || failText(res), true); return; }
      flash('Put back the way it was.');
      loadGrowth('row');
    });
  }

  function draftBlock(it) {
    var d = it.draft, wrap = el('div', 'mw-mws-sec mw-mws-draft'), story = it.type === 'project_story';
    add(wrap, el('h3', 'mw-mws-h3', story ? 'The portfolio card' : 'The wording'));
    add(wrap, el('p', 'mw-mws-p', story ? 'Goes to: your Portfolio page, saved as a draft that stays hidden until you publish it there. Only the kind of work, the neighbourhood and the month were used.' : 'Page: ' + d.page_url));
    var cols = el('div', 'mw-mws-cols');
    function col(head, title, desc, cls) {
      var c = el('div', 'mw-mws-col ' + cls);
      add(c, el('h4', 'mw-mws-h4', head), el('span', 'mw-mws-lbl', story ? 'Name' : 'Title'), el('p', 'mw-mws-quote', title || '(none)'), el('span', 'mw-mws-lbl', 'Description'), el('p', 'mw-mws-quote', desc || '(none)'));
      return c;
    }
    if (story) { add(cols, col('Mia suggests', d.title, d.meta_description, 'mw-mws-col--new')); }
    else { add(cols, col('Now', d.current_title, d.current_description, 'mw-mws-col--now'), col('Mia suggests', d.title, d.meta_description, 'mw-mws-col--new')); }
    add(wrap, cols);
    if (it.can_decide) {
      var edit = btn('Change the wording', 'mw-mws-btn--quiet', function () { wrap.replaceChild(editForm(it), wrap.querySelector('.mw-mws-editslot') || edit); });
      var slot = el('div', 'mw-mws-editslot'); add(slot, edit); add(wrap, slot);
    }
    return wrap;
  }

  function editForm(it) {
    var d = it.draft, f = el('form', 'mw-mws-edit mw-mws-editslot');
    var t = el('input', 'mw-mws-input', null, { type: 'text', maxlength: '120', value: d.title, id: 'mws-edit-title' });
    var m = el('textarea', 'mw-mws-input mw-mws-textarea', d.meta_description, { rows: it.type === 'project_story' ? '6' : '3', maxlength: it.type === 'project_story' ? '480' : '320', id: 'mws-edit-desc' });
    var save = btn('Save wording', 'mw-mws-btn--primary', null, { type: 'submit' });
    add(f, el('label', 'mw-mws-lbl', 'Title (about 60 characters shows in Google)', { 'for': 'mws-edit-title' }), t,
      el('label', 'mw-mws-lbl', 'Description (about 155 characters shows in Google)', { 'for': 'mws-edit-desc' }), m, save,
      btn('Cancel', 'mw-mws-btn--quiet', function () { loadGrowth('row'); }));
    f.addEventListener('submit', function (e) {
      e.preventDefault(); save.disabled = true;
      request('POST', 'draft_edit', { id: it.id, title: t.value, meta_description: m.value }).then(function (res) {
        save.disabled = false;
        if (!res.body.ok) { flash(res.body.error || failText(res), true); return; }
        flash('Wording saved. Look it over, then approve.');
        loadGrowth('row');
      });
    });
    return f;
  }

  function decisionBar(it) {
    var bar = el('div', 'mw-mws-decide');
    var note = el('p', 'mw-mws-fine', 'Approving records your decision. Mia does not change your website.');
    var approve = btn('Approve', 'mw-mws-btn--primary', function () { decide(it, 'approve', {}, bar); });
    var later = btn('Not now', 'mw-mws-btn--secondary', function () { decide(it, 'later', {}, bar); });
    var reject = btn('No thanks…', 'mw-mws-btn--quiet', function () { rejectBox.hidden = !rejectBox.hidden; reject.setAttribute('aria-expanded', rejectBox.hidden ? 'false' : 'true'); }, { 'aria-expanded': 'false' });
    var rejectBox = el('div', 'mw-mws-reject'); rejectBox.hidden = true;
    var sel = el('select', 'mw-mws-input', null, { id: 'mws-why-' + it.id, 'aria-label': 'Why not' });
    [['not_our_service', 'We do not offer that'], ['not_our_voice', 'It does not sound like us'], ['bad_timing', 'Bad timing'], ['other', 'Another reason']].forEach(function (o) { add(sel, el('option', '', o[1], { value: o[0] })); });
    var txt = el('input', 'mw-mws-input', null, { type: 'text', maxlength: '400', placeholder: 'Anything Mia should remember? (optional)', 'aria-label': 'Note for Mia' });
    var confirm = btn('Set it aside', 'mw-mws-btn--secondary', function () { decide(it, 'reject', { reason_code: sel.value, reason_text: txt.value }, bar); });
    add(rejectBox, sel, txt, confirm);
    add(bar, note, el('div', 'mw-mws-actions', null), rejectBox);
    add(bar.children[1], approve, later, reject);
    return bar;
  }

  function decide(it, decision, extra, bar) {
    var all = bar.querySelectorAll('button, select, input');                    // one decision at a time: freeze every control
    function freeze(on) { Array.prototype.forEach.call(all, function (c) { c.disabled = on; }); }
    freeze(true);
    request('POST', 'action_decide', Object.assign({ id: it.id, decision: decision, seen_hash: it.seen_hash }, extra)).then(function (res) {
      if (res.status === 409 && res.body.code === 'changed') {
        flash(res.body.error, true);
        loadGrowth('row');
        return;
      }
      if (!res.body.ok) { freeze(false); flash(res.body.error || failText(res), true); return; }
      flash(res.body.message || 'Saved.');
      state.openId = null; loadGrowth('filter');
    });
  }

  // ── Results ───────────────────────────────────────────────────────────────────────────────────
  function loadResults() {
    var host = $('mws-results'), g = ++gen.results;
    request('GET', 'actions', { status: 'all' }).then(function (res) {
      if (g !== gen.results) return;
      clear(host);
      if (!res.body.ok) { add(host, el('p', 'mw-mws-note mw-mws-note--bad', failText(res))); return; }
      var c = res.body.counts || {};
      add(host, el('h2', 'mw-mws-h2', 'What you have decided'));
      var nums = el('div', 'mw-mws-nums');
      [['Waiting', c.proposed], ['Approved', c.approved], ['Set aside', c.rejected], ['Passed', c.expired]].forEach(function (n) {
        var d = el('div', 'mw-mws-num'); add(d, el('strong', '', n[1] || 0), el('span', '', n[0])); add(nums, d);
      });
      add(host, nums);
      var decided = (res.body.items || []).filter(function (i) { return i.status !== 'proposed'; });
      if (decided.length) {
        var list = el('div', 'mw-mws-rows');
        decided.slice(0, 15).forEach(function (i) {
          var r = el('article', 'mw-mws-row');
          add(r, el('div', 'mw-mws-rowhead'));
          add(r.firstChild, el('strong', 'mw-mws-name', i.title), status(i.status === 'approved' ? 'ok' : 'no', (i.status === 'approved' ? 'Approved ' : i.status === 'rejected' ? 'Set aside ' : 'Passed ') + when(i.decided_at || i.proposed_at)));
          if (i.decision_note) add(r, el('p', 'mw-mws-why', i.decision_note));
          add(list, r);
        });
        add(host, list);
      }
      add(host, el('p', 'mw-mws-note', 'Leads, quotes won and profit by service will appear here once Mia is connected to your sales and bookkeeping (a later phase). Until then she does not guess at them.'));
    });
  }

  // ── start ─────────────────────────────────────────────────────────────────────────────────────
  var hash = (location.hash || '').replace('#', '');
  request('GET', 'actions', { status: 'proposed' }).then(function (res) {
    if (res.body && res.body.ok && res.body.counts) setWaiting(parseInt(res.body.counts.proposed || 0, 10));
    else if (res.status === 503) { $('mws-sub').textContent = (res.body && res.body.error) || 'Mia is not set up yet.'; }
    var first = TABS.indexOf(hash) >= 0 ? hash : (state.waiting > 0 ? 'growth' : 'presence');
    showTab(first, false);
  });
})();
