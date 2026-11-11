/**
 * Schedule calendar editing — Jobber-style gestures on our schedule.
 * Loaded by schedule.php ONLY when ops_settings it_calendar_edit = 1 and the user may edit jobs.
 *
 *  - Day view: a "Timeline" (hour rows, one column per crew member). Drag a block's bottom or top
 *    edge to resize ONE visit, drag the block to move it, click or drag on an empty slot to add a job
 *    or task through the popover.
 *  - Week view: a "+ Add" on each day column opens the same popover for that date.
 *  - Writes: resize/create/undo → /crm/api/calendar-edit.php; move → the existing reschedule-stop.php.
 *    Every write returns fresh day-summary cards, patched in place like the route-board drag does.
 *
 * Config (set by schedule.php): window.MW_CAL_EDIT = { csrf, view, date }
 */
(function () {
  'use strict';
  var cfg = window.MW_CAL_EDIT;
  if (!cfg || window.__mwCalEditLoaded) return;
  cfg.csrf = cfg.csrf || window.MW_CSRF || '';
  cfg.date = cfg.date || window.MW_SCHEDULE_DATE || '';
  window.__mwCalEditLoaded = true;

  var EDIT_API = '/crm/api/calendar-edit.php';
  var MOVE_API = '/crm/api/reschedule-stop.php';
  var STOPS_API = '/crm/api/calendar-stops.php';
  var SEARCH_API = '/crm/api/field-search.php';
  var DAY_START = 6 * 60, DAY_END = 20 * 60, PX_PER_MIN = 0.8, SNAP = 15, MIN_LEN = 15;

  // ── helpers ───────────────────────────────────────────────
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toMin(t) { if (!t) return null; var m = /^(\d{1,2}):(\d{2})/.exec(t); return m ? (+m[1]) * 60 + (+m[2]) : null; }
  function hhmm(min) { return ('0' + Math.floor(min / 60)).slice(-2) + ':' + ('0' + (min % 60)).slice(-2); }
  function label(min) { var h = Math.floor(min / 60), m = min % 60, ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + ('0' + m).slice(-2) + ' ' + ap; }
  function snap(min) { return Math.round(min / SNAP) * SNAP; }
  function post(url, body) {
    body.csrf_token = cfg.csrf;
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
  }
  function patchDayCards(cards) {
    Object.keys(cards || {}).forEach(function (date) {
      var col = document.querySelector('.mw-day-column[data-date="' + date + '"]');
      if (!col) return;
      var ex = col.querySelector('.mw-dsc');
      if (ex) ex.outerHTML = cards[date]; else col.insertAdjacentHTML('afterbegin', cards[date]);
    });
  }

  // ── toast with optional Undo ──────────────────────────────
  var toastEl;
  function toast(msg, undo, kind) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'mw-cal-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.className = 'mw-cal-toast mw-cal-toast--show' + (kind === 'error' ? ' mw-cal-toast--error' : '');
    toastEl.innerHTML = '<span></span>';
    toastEl.firstChild.textContent = msg;
    if (undo) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = 'Undo';
      b.addEventListener('click', function () { toastEl.className = 'mw-cal-toast'; undo(); });
      toastEl.appendChild(b);
    }
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.className = 'mw-cal-toast'; }, undo ? 9000 : 4000);
  }

  // ── Timeline (day view) ───────────────────────────────────
  var panel, crewCols = [], stopsData = [], crewList = null;

  function loadCrew() {
    if (crewList) return Promise.resolve(crewList);
    return post(EDIT_API, { action: 'crew' }).then(function (r) { crewList = r.crew || []; return crewList; });
  }

  function buildBlocks() {
    var cols = {}, order = [];
    function col(id, name) { var k = id || 0; if (!cols[k]) { cols[k] = { id: id || 0, name: name || 'Unassigned', blocks: [], anytime: [] }; order.push(k); } return cols[k]; }
    stopsData.forEach(function (s) {
      var c = col(s.crew_id, s.crew_name);
      (s.visits || []).forEach(function (v) {
        var st = toMin(v.scheduled_time_start) != null ? toMin(v.scheduled_time_start) : toMin(s.estimated_arrival);
        var item = { visitId: v.visit_id, stopId: s.stop_id, status: v.visit_status, title: v.plan_title || v.service_type || 'Visit', addr: s.property_address, who: s.contact_name || s.company_name || '' };
        if (st == null) { c.anytime.push(item); return; }
        var en = toMin(v.scheduled_time_end);
        if (en == null || en <= st) en = st + (v.estimated_duration || 60);
        item.start = st; item.end = en; item.origStart = v.scheduled_time_start; item.origEnd = v.scheduled_time_end;
        c.blocks.push(item);
      });
    });
    return order.sort(function (a, b) { return (a === 0) - (b === 0) || cols[a].name.localeCompare(cols[b].name); }).map(function (k) { return cols[k]; });
  }

  function render() {
    crewCols = buildBlocks();
    if (!crewCols.length) crewCols = [{ id: 0, name: 'Unassigned', blocks: [], anytime: [] }];
    var hours = '';
    for (var m = DAY_START; m < DAY_END; m += 60) hours += '<div class="mw-cal-hour" style="height:' + (60 * PX_PER_MIN) + 'px">' + label(m) + '</div>';
    var h = '<div class="mw-cal-head"><div class="mw-cal-gutter"></div>' + crewCols.map(function (c) { return '<div class="mw-cal-colhead">' + esc(c.name) + '</div>'; }).join('') + '</div>';
    h += '<div class="mw-cal-any"><div class="mw-cal-gutter">Anytime</div>' + crewCols.map(function (c) {
      return '<div class="mw-cal-anycell">' + c.anytime.map(function (a) { return '<span class="mw-cal-chip">' + esc(a.title) + '</span>'; }).join('') + '</div>';
    }).join('') + '</div>';
    h += '<div class="mw-cal-body"><div class="mw-cal-gutter">' + hours + '</div>' + crewCols.map(function (c, i) {
      return '<div class="mw-cal-col" data-ci="' + i + '" style="height:' + ((DAY_END - DAY_START) * PX_PER_MIN) + 'px">' + c.blocks.map(blockHtml).join('') + '</div>';
    }).join('') + '</div>';
    panel.querySelector('.mw-cal-grid').innerHTML = h;
    nowLine();
  }

  function blockHtml(b) {
    var top = (b.start - DAY_START) * PX_PER_MIN, ht = Math.max(MIN_LEN, b.end - b.start) * PX_PER_MIN;
    var live = b.status === 'scheduled';
    return '<div class="mw-cal-block' + (live ? '' : ' mw-cal-block--locked') + '" data-visit="' + b.visitId + '" data-stop="' + b.stopId + '" style="top:' + top + 'px;height:' + ht + 'px">' +
      (live ? '<div class="mw-cal-edge mw-cal-edge--top"></div>' : '') +
      '<div class="mw-cal-btitle">' + esc(b.title) + '</div><div class="mw-cal-bmeta">' + label(b.start) + ' \u2013 ' + label(b.end) + '</div>' +
      '<div class="mw-cal-bmeta">' + esc(b.addr || '') + '</div>' +
      (live ? '<div class="mw-cal-edge mw-cal-edge--bottom"></div>' : '') + '</div>';
  }

  function nowLine() {
    if (cfg.date !== new Date().toISOString().slice(0, 10) && cfg.date !== localToday()) return;
    var now = new Date(), m = now.getHours() * 60 + now.getMinutes();
    if (m < DAY_START || m > DAY_END) return;
    var body = panel.querySelector('.mw-cal-body'); if (!body) return;
    var l = document.createElement('div'); l.className = 'mw-cal-now'; l.style.top = ((m - DAY_START) * PX_PER_MIN) + 'px'; body.appendChild(l);
  }
  function localToday() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

  function refresh() {
    return fetch(STOPS_API + '?date=' + encodeURIComponent(cfg.date), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { stopsData = j.stops || []; render(); });
  }

  // Gesture state machine: one pointer drag at a time.
  function bindGestures() {
    var grid = panel.querySelector('.mw-cal-grid');
    grid.addEventListener('pointerdown', function (e) {
      if (e.button) return;
      var colEl = e.target.closest('.mw-cal-col'); if (!colEl) return;
      var block = e.target.closest('.mw-cal-block');
      var rect = colEl.getBoundingClientRect();
      function minAt(ev) { return DAY_START + (ev.clientY - rect.top) / PX_PER_MIN; }
      var ci = +colEl.dataset.ci;

      if (block) {
        if (block.classList.contains('mw-cal-block--locked')) return;
        var b = findBlock(+block.dataset.visit); if (!b) return;
        var mode = e.target.classList.contains('mw-cal-edge--bottom') ? 'end' : e.target.classList.contains('mw-cal-edge--top') ? 'start' : 'move';
        if (mode === 'move' && scheduledCount(b.stopId) > 1) {
          toast('This stop has more than one visit, so it cannot be dragged. Change each visit by its edge.', null, 'error');
          return;
        }
        startDrag(e, block, colEl, b, mode, minAt);
      } else {
        startCreate(e, colEl, ci, minAt);
      }
    });
  }
  function scheduledCount(stopId) {
    var n = 0;
    stopsData.forEach(function (s) { if (s.stop_id === stopId) (s.visits || []).forEach(function (v) { if (v.visit_status === 'scheduled') n++; }); });
    return n;
  }
  function findBlock(id) { var f = null; crewCols.forEach(function (c) { c.blocks.forEach(function (b) { if (b.visitId === id) f = b; }); }); return f; }

  function startDrag(e, el, colEl, b, mode, minAt) {
    e.preventDefault(); el.setPointerCapture(e.pointerId);
    var s0 = b.start, e0 = b.end, grab = minAt(e) - s0, moved = false, ns = s0, ne = e0;
    el.classList.add('mw-cal-block--drag');
    function move(ev) {
      var m = snap(minAt(ev));
      if (mode === 'end') { ne = Math.min(DAY_END, Math.max(s0 + MIN_LEN, m)); ns = s0; }
      else if (mode === 'start') { ns = Math.max(DAY_START, Math.min(e0 - MIN_LEN, m)); ne = e0; }
      else { var len = e0 - s0; ns = Math.max(DAY_START, Math.min(DAY_END - len, snap(minAt(ev) - grab))); ne = ns + len; }
      if (ns !== s0 || ne !== e0) moved = true;
      el.style.top = ((ns - DAY_START) * PX_PER_MIN) + 'px'; el.style.height = ((ne - ns) * PX_PER_MIN) + 'px';
      el.querySelector('.mw-cal-bmeta').textContent = label(ns) + ' \u2013 ' + label(ne);
    }
    function up() {
      el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', cancel);
      el.classList.remove('mw-cal-block--drag');
      if (!moved) { render(); return; }
      commit(b, mode, ns, ne, false);
    }
    function cancel() { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', cancel); render(); }
    el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', cancel);
  }

  function commit(b, mode, ns, ne, force) {
    var prevStart = b.start, prevEnd = b.end;
    var req = mode === 'move'
      ? post(MOVE_API, { stop_id: b.stopId, new_date: cfg.date, new_time: hhmm(ns) + ':00', force: !!force })
      : post(EDIT_API, { action: 'resize', visit_id: b.visitId, start: hhmm(ns), end: hhmm(ne), force: force });
    req.then(function (r) {
      if (r.warning && mode !== 'move') {
        var c = r.conflicts[0];
        if (window.confirm('This overlaps another visit for the same person (' + c.start + '–' + c.end + '). Keep it anyway?')) return commit(b, mode, ns, ne, true);
        return refresh();
      }
      if (r.error || r._status >= 400) { toast(r.error || 'Could not save that change.', null, 'error'); return refresh(); }
      patchDayCards(r.day_cards);
      var undo;
      if (mode === 'move') {
        undo = function () { post(MOVE_API, { stop_id: b.stopId, new_date: cfg.date, new_time: hhmm(prevStart) + ':00', force: true }).then(function (u) { patchDayCards(u.day_cards); refresh(); toast('Change undone'); }); };
      } else {
        var prev = r.previous || {};
        undo = function () { post(EDIT_API, { action: 'undo', visit_id: b.visitId, previous: prev }).then(function (u) { patchDayCards(u.day_cards); refresh(); toast('Change undone'); }); };
      }
      toast('Rescheduled visit', undo);
      refresh();
    }).catch(function () { toast('Network problem: nothing was saved.', null, 'error'); refresh(); });
  }

  function startCreate(e, colEl, ci, minAt) {
    var rect = colEl.getBoundingClientRect(), a = snap(minAt(e)), z = a, ghost = document.createElement('div'), dragged = false;
    ghost.className = 'mw-cal-ghost'; colEl.appendChild(ghost);
    var x0 = e.clientX, y0 = e.clientY;
    function draw() { var s = Math.min(a, z), t = Math.max(a, z) || s; ghost.style.top = ((s - DAY_START) * PX_PER_MIN) + 'px'; ghost.style.height = (Math.max(SNAP, t - s) * PX_PER_MIN) + 'px'; }
    draw();
    function move(ev) { if (Math.abs(ev.clientY - y0) > 4) dragged = true; z = snap(minAt(ev)); draw(); }
    function cancel() { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', cancel); ghost.remove(); }
    function up(ev) {
      document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', cancel);
      var s = Math.min(a, z), t = Math.max(a, z);
      if (!dragged || t - s < SNAP) t = s + 60;
      s = Math.max(DAY_START, s); t = Math.min(1425, Math.max(s + SNAP, t));
      ghost.remove();
      openPopover({ x: ev.clientX, y: ev.clientY, date: cfg.date, start: hhmm(s), end: hhmm(t), crewId: crewCols[ci] ? crewCols[ci].id : 0 });
    }
    document.addEventListener('pointermove', move); document.addEventListener('pointerup', up); document.addEventListener('pointercancel', cancel);
  }

  // ── Create popover ────────────────────────────────────────
  var pop;
  function closePopover() { if (pop) { pop.remove(); pop = null; document.removeEventListener('keydown', escClose); } }
  function escClose(e) { if (e.key === 'Escape') closePopover(); }

  function openPopover(o) {
    closePopover();
    var state = { kind: 'job', property: null, assignees: o.crewId ? [o.crewId] : [] };
    pop = document.createElement('div'); pop.className = 'mw-cal-pop'; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Add to calendar');
    pop.innerHTML =
      '<div class="mw-cal-tabs"><button type="button" data-kind="job" class="on">Job</button><button type="button" data-kind="task">Task</button></div>' +
      '<div class="mw-cal-f"><input type="text" class="mw-cal-in" data-f="client" placeholder="Search client or address" autocomplete="off"><div class="mw-cal-list" data-l="client" hidden></div>' +
      '<div class="mw-cal-picked" data-f="picked" hidden></div></div>' +
      '<div class="mw-cal-f"><input type="text" class="mw-cal-in" data-f="title" placeholder="Title" autocomplete="off"><div class="mw-cal-list" data-l="title" hidden></div></div>' +
      '<div class="mw-cal-f"><div class="mw-cal-chips" data-f="assignees"></div><select class="mw-cal-in" data-f="assign"><option value="">+ Assign</option></select></div>' +
      '<div class="mw-cal-row"><label>Start date<input type="date" class="mw-cal-in" data-f="date" value="' + esc(o.date) + '"></label>' +
      '<label>Start<input type="time" step="900" class="mw-cal-in" data-f="start" value="' + esc(o.start) + '"></label>' +
      '<label>End<input type="time" step="900" class="mw-cal-in" data-f="end" value="' + esc(o.end) + '"></label></div>' +
      '<label class="mw-cal-chk"><input type="checkbox" data-f="anytime"> Anytime</label>' +
      '<div class="mw-cal-err" data-f="err" hidden></div>' +
      '<div class="mw-cal-btns"><button type="button" class="mw-cal-btn" data-a="more">More Options</button><button type="button" class="mw-cal-btn mw-cal-btn--go" data-a="save">Save</button></div>';
    document.body.appendChild(pop);
    var W = Math.min(360, window.innerWidth - 16);
    pop.style.width = W + 'px';
    pop.style.left = Math.max(8, Math.min(o.x + 8, window.innerWidth - W - 8)) + 'px';
    pop.style.top = Math.max(8, Math.min(o.y + 8, window.innerHeight - 440)) + 'px';
    document.addEventListener('keydown', escClose);
    var $ = function (f) { return pop.querySelector('[data-f="' + f + '"]'); };
    var L = function (l) { return pop.querySelector('[data-l="' + l + '"]'); };

    pop.querySelectorAll('.mw-cal-tabs button').forEach(function (t) {
      t.addEventListener('click', function () { state.kind = t.dataset.kind; pop.querySelectorAll('.mw-cal-tabs button').forEach(function (x) { x.classList.toggle('on', x === t); }); });
    });

    function chips() {
      $('assignees').innerHTML = state.assignees.map(function (id) {
        var c = (crewList || []).filter(function (x) { return x.id === id; })[0];
        return '<span class="mw-cal-chip">' + esc(c ? c.name : 'Team member') + ' <button type="button" data-rm="' + id + '" aria-label="Remove">\u00d7</button></span>';
      }).join('');
      $('assignees').querySelectorAll('[data-rm]').forEach(function (b) { b.addEventListener('click', function () { state.assignees = state.assignees.filter(function (x) { return x !== +b.dataset.rm; }); chips(); }); });
    }
    loadCrew().then(function (list) {
      var sel = $('assign'); if (!sel) return;
      list.forEach(function (c) { var op = document.createElement('option'); op.value = c.id; op.textContent = c.name; sel.appendChild(op); });
      sel.addEventListener('change', function () { var id = +sel.value; if (id && state.assignees.indexOf(id) < 0) state.assignees.push(id); sel.value = ''; chips(); });
      chips();
    });

    // client search
    var tSearch;
    $('client').addEventListener('input', function () {
      clearTimeout(tSearch); var q = this.value.trim(); var list = L('client');
      if (q.length < 2) { list.hidden = true; return; }
      tSearch = setTimeout(function () {
        fetch(SEARCH_API + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
          var res = (j.results || []).slice(0, 6);
          list.innerHTML = res.map(function (p, i) { return '<button type="button" data-i="' + i + '"><b>' + esc(p.label) + '</b><br><small>' + esc(p.address) + (p.city ? ', ' + esc(p.city) : '') + '</small></button>'; }).join('') || '<div class="mw-cal-none">No match</div>';
          list.hidden = false;
          list.querySelectorAll('button').forEach(function (b) {
            b.addEventListener('click', function () {
              state.property = res[+b.dataset.i]; list.hidden = true; $('client').hidden = true;
              var pk = $('picked'); pk.hidden = false;
              pk.innerHTML = '<div><b>' + esc(state.property.label) + '</b></div><div>' + esc(state.property.address) + '</div>' + (state.property.phone ? '<div>' + esc(state.property.phone) + '</div>' : '') + '<button type="button" aria-label="Clear client">\u00d7</button>';
              pk.querySelector('button').addEventListener('click', function () { state.property = null; pk.hidden = true; $('client').hidden = false; $('client').value = ''; $('client').focus(); });
            });
          });
        });
      }, 200);
    });

    // title autocomplete
    var tTitle;
    function suggest() {
      clearTimeout(tTitle);
      tTitle = setTimeout(function () {
        post(EDIT_API, { action: 'titles', property_id: state.property ? state.property.id : 0, q: $('title').value }).then(function (j) {
          var list = L('title'), ts = j.titles || [];
          list.innerHTML = ts.map(function (t) { return '<button type="button">' + esc(t) + '</button>'; }).join('');
          list.hidden = !ts.length;
          list.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { $('title').value = b.textContent; list.hidden = true; }); });
        });
      }, 200);
    }
    $('title').addEventListener('input', suggest); $('title').addEventListener('focus', suggest);

    $('anytime').addEventListener('change', function () { $('start').disabled = $('end').disabled = this.checked; });

    function err(m) { var e = $('err'); e.textContent = m; e.hidden = !m; }

    pop.querySelector('[data-a="more"]').addEventListener('click', function () {
      var q = new URLSearchParams();
      if (state.property) q.set('property_id', state.property.id);
      q.set('cal_title', $('title').value); q.set('cal_date', $('date').value);
      if (!$('anytime').checked) { q.set('cal_start', $('start').value); q.set('cal_end', $('end').value); }
      window.location.href = '/crm/jobs/create.php?' + q.toString();
    });

    pop.querySelector('[data-a="save"]').addEventListener('click', function () {
      var btn = this; err('');
      if (!state.property) return err('Pick a client or address first.');
      if (!$('title').value.trim()) return err('A title is needed.');
      btn.disabled = true;
      post(EDIT_API, { action: 'create', kind: state.kind, property_id: state.property.id, title: $('title').value, date: $('date').value,
        start: $('start').value, end: $('end').value, anytime: $('anytime').checked ? 1 : 0, assignee_ids: state.assignees })
        .then(function (r) {
          btn.disabled = false;
          if (!r.success) return err(r.error || 'Could not save.');
          closePopover(); patchDayCards(r.day_cards);
          toast(r.message || 'Created visit');
          if (cfg.view === 'day') refresh(); else window.location.reload();
        }).catch(function () { btn.disabled = false; err('Network problem: nothing was saved.'); });
    });
  }

  // outside click closes popover
  document.addEventListener('pointerdown', function (e) { if (pop && !pop.contains(e.target) && !e.target.closest('.mw-cal-col') && !e.target.closest('.mw-cal-add')) closePopover(); });

  // ── mount ─────────────────────────────────────────────────
  function mountDay() {
    var tog = document.querySelector('.mw-dv-view-toggle');
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'mw-cal-toggle'; btn.textContent = 'Timeline'; btn.setAttribute('aria-expanded', 'false');
    btn.title = 'Hour view: drag a block\'s edge to change its length, drag it to move, click an empty slot to add a job';
    panel = document.createElement('div'); panel.className = 'mw-cal-panel'; panel.hidden = true; panel.innerHTML = '<div class="mw-cal-grid"></div>';
    if (tog && tog.parentElement && tog.parentElement.parentElement) {
      // Next to Week / Day / List View, with the timeline opening just under that header row.
      var after = tog.nextElementSibling || tog;
      after.insertAdjacentElement('afterend', btn);
      tog.parentElement.parentElement.insertAdjacentElement('afterend', panel);
    } else {
      var host = document.querySelector('main.content .container-fluid') || document.querySelector('main.content');
      if (!host) return;
      var bar = document.createElement('div'); bar.className = 'mw-cal-bar'; bar.appendChild(btn);
      host.insertBefore(panel, host.firstChild); host.insertBefore(bar, panel);
    }
    var open = false, bound = false;
    btn.addEventListener('click', function () {
      open = !open; panel.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.classList.toggle('on', open);
      if (open) { loadCrew(); refresh(); if (!bound) { bindGestures(); bound = true; } panel.scrollIntoView({ block: 'nearest' }); }
    });
  }

  function mountWeek() {
    document.querySelectorAll('.mw-day-column[data-date]').forEach(function (col) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'mw-cal-add'; b.textContent = '+ Add';
      b.addEventListener('click', function (e) { var r = b.getBoundingClientRect(); openPopover({ x: r.left, y: r.bottom, date: col.dataset.date, start: '09:00', end: '10:00', crewId: 0 }); e.stopPropagation(); });
      col.appendChild(b);
    });
  }

  function init() { if (cfg.view === 'day') mountDay(); else mountWeek(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
