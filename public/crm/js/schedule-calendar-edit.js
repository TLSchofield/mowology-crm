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
  function toast(msg, undo, kind, actionLabel) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'mw-cal-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.className = 'mw-cal-toast mw-cal-toast--show' + (kind === 'error' ? ' mw-cal-toast--error' : '');
    toastEl.innerHTML = '<span></span>';
    toastEl.firstChild.textContent = msg;
    if (undo) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = actionLabel || 'Undo';
      b.addEventListener('click', function () { toastEl.className = 'mw-cal-toast'; undo(); });
      toastEl.appendChild(b);
    }
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.className = 'mw-cal-toast'; }, actionLabel ? 14000 : (undo ? 9000 : 4000));
  }

  // ── Timeline (day view) ───────────────────────────────────
  var panel, crewCols = [], stopsData = [], crewList = null;
  var weekMode = cfg.view === 'week', weekDates = [];
  function dayLabel(d) { return new Date(d + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }); }
  function collectWeekDates() {
    var ds = [].map.call(document.querySelectorAll('.mw-day-column[data-date]'), function (c) { return c.dataset.date; });
    weekDates = ds.filter(function (d, i) { return ds.indexOf(d) === i; });
  }

  function loadCrew() {
    if (crewList) return Promise.resolve(crewList);
    return post(EDIT_API, { action: 'crew' }).then(function (r) { crewList = r.crew || []; return crewList; });
  }

  function buildBlocks() {
    var cols = {}, order = [];
    if (weekMode) weekDates.forEach(function (d) { cols[d] = { id: 0, date: d, name: dayLabel(d), blocks: [], anytime: [] }; order.push(d); });
    function col(id, name) { var k = id || 0; if (!cols[k]) { cols[k] = { id: id || 0, name: name || 'Unassigned', blocks: [], anytime: [] }; order.push(k); } return cols[k]; }
    stopsData.forEach(function (s) {
      var c = weekMode ? cols[s.stop_date] : col(s.crew_id, s.crew_name);
      if (!c) return;
      (s.visits || []).forEach(function (v) {
        var st = toMin(v.scheduled_time_start) != null ? toMin(v.scheduled_time_start) : toMin(s.estimated_arrival);
        var item = { visitId: v.visit_id, stopId: s.stop_id, status: v.visit_status, title: v.plan_title || v.service_type || 'Visit', addr: s.property_address, who: s.company_name || s.contact_name || '', date: s.stop_date || cfg.date, crew: s.crew_name || '', dur: v.estimated_duration || 60 };
        if (st == null) { c.anytime.push(item); return; }
        var en = toMin(v.scheduled_time_end);
        if (en == null || en <= st) en = st + (v.estimated_duration || 60);
        item.start = st; item.end = en; item.origStart = v.scheduled_time_start; item.origEnd = v.scheduled_time_end;
        c.blocks.push(item);
      });
    });
    if (weekMode) return order.map(function (k) { return cols[k]; });
    return order.sort(function (a, b) { return (a === 0) - (b === 0) || cols[a].name.localeCompare(cols[b].name); }).map(function (k) { return cols[k]; });
  }

  // Overlapping blocks in one column sit side by side (like Jobber) instead of printing over each other.
  function layoutLanes(blocks) {
    blocks.sort(function (a, b) { return a.start - b.start || b.end - a.end; });
    var cluster = [], clusterEnd = -1;
    function flush() { var n = 0; cluster.forEach(function (x) { n = Math.max(n, x.lane + 1); }); cluster.forEach(function (x) { x.lanes = n; }); cluster = []; }
    blocks.forEach(function (b) {
      if (b.start >= clusterEnd) { flush(); clusterEnd = -1; }
      var used = {}; cluster.forEach(function (x) { if (x.end > b.start) used[x.lane] = true; });
      var l = 0; while (used[l]) l++;
      b.lane = l; cluster.push(b); clusterEnd = Math.max(clusterEnd, b.end);
    });
    flush();
  }

  function render() {
    crewCols = buildBlocks();
    crewCols.forEach(function (c) { layoutLanes(c.blocks); });
    if (!crewCols.length) crewCols = [{ id: 0, date: cfg.date, name: 'Unassigned', blocks: [], anytime: [] }];
    var hours = '';
    for (var m = DAY_START; m < DAY_END; m += 60) hours += '<div class="mw-cal-hour" style="height:' + (60 * PX_PER_MIN) + 'px">' + label(m) + '</div>';
    var h = '<div class="mw-cal-head"><div class="mw-cal-gutter"></div>' + crewCols.map(function (c) { return '<div class="mw-cal-colhead">' + esc(c.name) + '</div>'; }).join('') + '</div>';
    h += '<div class="mw-cal-any"><div class="mw-cal-gutter">Anytime</div>' + crewCols.map(function (c) {
      return '<div class="mw-cal-anycell">' + c.anytime.map(function (a) { return '<span class="mw-cal-chip' + (a.status === 'scheduled' ? ' mw-cal-chip--drag' : '') + '" data-visit="' + a.visitId + '" title="Drag onto the timeline to give it a time">' + esc((a.who ? a.who + ' \u00b7 ' : '') + a.title) + '</span>'; }).join('') + '</div>';
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
    return '<div class="mw-cal-block' + (live ? '' : ' mw-cal-block--locked') + '" data-visit="' + b.visitId + '" data-stop="' + b.stopId + '" title="' + esc((b.who ? b.who + ' \u2013 ' : '') + b.title + ' \u00b7 ' + label(b.start) + ' \u2013 ' + label(b.end) + (b.addr ? ' \u00b7 ' + b.addr : '')) + '" style="top:' + top + 'px;height:' + ht + 'px;left:calc(' + (100 * (b.lane || 0) / (b.lanes || 1)) + '% + 3px);width:calc(' + (100 / (b.lanes || 1)) + '% - 6px)">' +
      (live ? '<div class="mw-cal-edge mw-cal-edge--top"></div>' : '') +
      '<div class="mw-cal-btitle">' + esc(b.who || b.title) + '</div>' + (b.who ? '<div class="mw-cal-bmeta mw-cal-btype">' + esc(b.title) + '</div>' : '') + '<div class="mw-cal-bmeta">' + label(b.start) + ' \u2013 ' + label(b.end) + '</div>' +
      '<div class="mw-cal-bmeta">' + esc((weekMode && b.crew ? b.crew + ' \u00b7 ' : '') + (b.addr || '')) + '</div>' +
      (live ? '<div class="mw-cal-edge mw-cal-edge--bottom"></div>' : '') + '</div>';
  }

  function nowLine() {
    if (weekMode) return;
    if (cfg.date !== new Date().toISOString().slice(0, 10) && cfg.date !== localToday()) return;
    var now = new Date(), m = now.getHours() * 60 + now.getMinutes();
    if (m < DAY_START || m > DAY_END) return;
    var body = panel.querySelector('.mw-cal-body'); if (!body) return;
    var l = document.createElement('div'); l.className = 'mw-cal-now'; l.style.top = ((m - DAY_START) * PX_PER_MIN) + 'px'; body.appendChild(l);
  }
  function localToday() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

  function refresh() {
    var dates = weekMode ? weekDates : [cfg.date];
    return Promise.all(dates.map(function (d) {
      return fetch(STOPS_API + '?date=' + encodeURIComponent(d) + (cfg.crew ? '&crew=' + encodeURIComponent(cfg.crew) : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { return j.stops || []; });
    })).then(function (lists) { stopsData = [].concat.apply([], lists); render(); });
  }

  // Gesture state machine: one pointer drag at a time.
  function bindGestures() {
    var grid = panel.querySelector('.mw-cal-grid');
    grid.addEventListener('pointerdown', function (e) {
      if (e.button) return;
      var chipEl = e.target.closest('.mw-cal-chip--drag');
      if (chipEl) { startChipDrag(e, chipEl); return; }
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
  function findAnytime(id) { var f = null; crewCols.forEach(function (c) { c.anytime.forEach(function (a) { if (a.visitId === id) f = a; }); }); return f; }

  // Drag an "Anytime" visit onto an hour to give it a time (same day: resize endpoint; other day: reschedule first).
  function startChipDrag(e, chipEl) {
    var item = findAnytime(+chipEl.dataset.visit); if (!item) return;
    e.preventDefault();
    var ghost = chipEl.cloneNode(true); ghost.className = 'mw-cal-chip mw-cal-chipghost'; document.body.appendChild(ghost);
    var mark = document.createElement('div'); mark.className = 'mw-cal-ghost'; mark.hidden = true;
    var target = null;
    function place(ev) {
      ghost.style.left = (ev.clientX + 8) + 'px'; ghost.style.top = (ev.clientY + 8) + 'px';
      target = null; mark.hidden = true;
      var cols = panel.querySelectorAll('.mw-cal-col');
      for (var i = 0; i < cols.length; i++) {
        var r = cols[i].getBoundingClientRect();
        if (ev.clientX >= r.left && ev.clientX < r.right && ev.clientY >= r.top && ev.clientY < r.bottom) {
          var m = Math.max(DAY_START, Math.min(1425, snap(DAY_START + (ev.clientY - r.top) / PX_PER_MIN)));
          target = { ci: i, start: m };
          if (mark.parentNode !== cols[i]) cols[i].appendChild(mark);
          mark.hidden = false; mark.style.top = ((m - DAY_START) * PX_PER_MIN) + 'px'; mark.style.height = (Math.min(item.dur, 1440 - m) * PX_PER_MIN) + 'px';
          break;
        }
      }
    }
    function done() { document.removeEventListener('pointermove', place); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', cancel); ghost.remove(); mark.remove(); }
    function cancel() { done(); }
    function up() { var t = target; done(); if (t) commitChip(item, weekMode ? crewCols[t.ci].date : item.date, t.start); }
    place(e);
    document.addEventListener('pointermove', place); document.addEventListener('pointerup', up); document.addEventListener('pointercancel', cancel);
  }

  function commitChip(item, date, start, force) {
    var end = Math.min(1425, start + item.dur), moved = date !== item.date;
    if (moved && scheduledCount(item.stopId) > 1) { toast('This stop has more than one visit, so it cannot change day here.', null, 'error'); return; }
    var first = moved ? post(MOVE_API, { stop_id: item.stopId, new_date: date, force: !!force }) : Promise.resolve({ success: true });
    first.then(function (r) {
      if (r.warning) { refresh(); toast(r.message || 'Please confirm.', function () { commitChip(item, date, start, true); }, 'error', 'Move anyway'); return; }
      if (r.error || r._status >= 400) { toast(r.error || 'Could not save that change.', null, 'error'); return refresh(); }
      return post(EDIT_API, { action: 'resize', visit_id: item.visitId, start: hhmm(start), end: hhmm(end), force: !!force }).then(function (r2) {
        if (r2.warning) {
          var c = r2.conflicts && r2.conflicts[0];
          refresh();
          toast('This overlaps another visit for the same person' + (c ? ' (' + c.start + '\u2013' + c.end + ')' : '') + '.', function () { commitChip(item, date, start, true); }, 'error', 'Keep anyway');
          return;
        }
        if (r2.error || r2._status >= 400) { toast(r2.error || 'Could not save that change.', null, 'error'); return refresh(); }
        patchDayCards(r2.day_cards);
        var prev = r2.previous || {};
        toast('Scheduled for ' + label(start), function () {
          post(EDIT_API, { action: 'undo', visit_id: item.visitId, previous: prev }).then(function () {
            return moved ? post(MOVE_API, { stop_id: item.stopId, new_date: item.date, force: true }) : null;
          }).then(function (u) { if (u && u.day_cards) patchDayCards(u.day_cards); refresh(); toast('Change undone'); });
        });
        refresh();
      });
    }).catch(function () { toast('Network problem: nothing was saved.', null, 'error'); refresh(); });
  }

  function scheduledCount(stopId) {
    var n = 0;
    stopsData.forEach(function (s) { if (s.stop_id === stopId) (s.visits || []).forEach(function (v) { if (v.visit_status === 'scheduled') n++; }); });
    return n;
  }
  function findBlock(id) { var f = null; crewCols.forEach(function (c) { c.blocks.forEach(function (b) { if (b.visitId === id) f = b; }); }); return f; }

  function startDrag(e, el, colEl, b, mode, minAt) {
    e.preventDefault(); el.setPointerCapture(e.pointerId);
    var s0 = b.start, e0 = b.end, grab = minAt(e) - s0, moved = false, ns = s0, ne = e0, ti = +colEl.dataset.ci;
    var colRects = [].map.call(panel.querySelectorAll('.mw-cal-col'), function (c) { return c.getBoundingClientRect(); });
    el.classList.add('mw-cal-block--drag');
    function move(ev) {
      var m = snap(minAt(ev));
      if (mode === 'end') { ne = Math.min(DAY_END, Math.max(s0 + MIN_LEN, m)); ns = s0; }
      else if (mode === 'start') { ns = Math.max(DAY_START, Math.min(e0 - MIN_LEN, m)); ne = e0; }
      else { var len = e0 - s0; ns = Math.max(DAY_START, Math.min(DAY_END - len, snap(minAt(ev) - grab))); ne = ns + len; }
      if (weekMode && mode === 'move') {
        for (var i = 0; i < colRects.length; i++) { if (ev.clientX >= colRects[i].left && ev.clientX < colRects[i].right) { if (i !== ti) { ti = i; } break; } }
        el.style.transform = 'translateX(' + (colRects[ti].left - colRects[+colEl.dataset.ci].left) + 'px)';
        if (ti !== +colEl.dataset.ci) moved = true;
      }
      if (ns !== s0 || ne !== e0) moved = true;
      el.style.top = ((ns - DAY_START) * PX_PER_MIN) + 'px'; el.style.height = ((ne - ns) * PX_PER_MIN) + 'px';
      el.querySelector('.mw-cal-bmeta').textContent = label(ns) + ' \u2013 ' + label(ne);
    }
    function up() {
      el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', cancel);
      el.classList.remove('mw-cal-block--drag');
      if (!moved) { render(); return; }
      commit(b, mode, ns, ne, false, weekMode && mode === 'move' && crewCols[ti] ? crewCols[ti].date : b.date);
    }
    function cancel() { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', cancel); render(); }
    el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', cancel);
  }

  function commit(b, mode, ns, ne, force, toDate) {
    toDate = toDate || b.date || cfg.date;
    var prevStart = b.start, prevEnd = b.end;
    var req = mode === 'move'
      ? post(MOVE_API, { stop_id: b.stopId, new_date: toDate, new_time: hhmm(ns) + ':00', force: !!force })
      : post(EDIT_API, { action: 'resize', visit_id: b.visitId, start: hhmm(ns), end: hhmm(ne), force: force });
    req.then(function (r) {
      if (r.warning) {
        var c = r.conflicts && r.conflicts[0];
        var ask = c ? 'This overlaps another visit for the same person (' + c.start + '\u2013' + c.end + ').' : (r.message || 'Please confirm.');
        refresh();
        toast(ask, function () { commit(b, mode, ns, ne, true, toDate); }, 'error', mode === 'move' ? 'Move anyway' : 'Keep anyway');
        return;
      }
      if (r.error || r._status >= 400) { toast(r.error || 'Could not save that change.', null, 'error'); return refresh(); }
      patchDayCards(r.day_cards);
      var undo;
      if (mode === 'move') {
        undo = function () { post(MOVE_API, { stop_id: b.stopId, new_date: b.date || cfg.date, new_time: hhmm(prevStart) + ':00', force: true }).then(function (u) { patchDayCards(u.day_cards); refresh(); toast('Change undone'); }); };
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
      openPopover({ x: ev.clientX, y: ev.clientY, date: weekMode && crewCols[ci] ? crewCols[ci].date : cfg.date, start: hhmm(s), end: hhmm(t), crewId: !weekMode && crewCols[ci] ? crewCols[ci].id : 0 });
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
          if (panel && !panel.hidden) refresh(); else if (weekMode) window.location.reload(); else refresh();
        }).catch(function () { btn.disabled = false; err('Network problem: nothing was saved.'); });
    });
  }

  // outside click closes popover
  document.addEventListener('pointerdown', function (e) { if (pop && !pop.contains(e.target) && !e.target.closest('.mw-cal-col') && !e.target.closest('.mw-cal-add')) closePopover(); });

  // ── mount ─────────────────────────────────────────────────
  function mountDay() {
    var tog = document.querySelector('.mw-dv-view-toggle');
    panel = document.createElement('div'); panel.className = 'mw-cal-panel'; panel.hidden = true; panel.innerHTML = '<div class="mw-cal-grid"></div>';
    // The layout group (Cards / Timeline / Map / List) is drawn by the page; build it only when it is missing.
    var seg = document.querySelector('.mw-cal-seg'), serverSeg = !!seg;
    if (!seg) {
      seg = document.createElement('div'); seg.className = 'mw-cal-seg'; seg.setAttribute('role', 'group'); seg.setAttribute('aria-label', 'Schedule layout');
      seg.innerHTML = '<button type="button" data-m="cards" class="on">Cards</button><button type="button" data-m="timeline">Timeline</button>';
    }
    var board = null;
    if (tog && tog.parentElement && tog.parentElement.parentElement) {
      if (!serverSeg) (tog.nextElementSibling || tog).insertAdjacentElement('afterend', seg);
      // Week: the timeline sits right under the page's own weekday + weather rows (they become its column heads),
      // below the stats banner, so those feeds are part of it instead of stranded underneath.
      var dh = weekMode && document.querySelector('.mw-calendar-container .mw-calendar-dates-header');
      if (dh) { dh.insertAdjacentElement('afterend', panel); panel.classList.add('mw-cal-panel--week'); }
      else tog.parentElement.parentElement.insertAdjacentElement('afterend', panel);
    } else {
      var host = document.querySelector('main.content .container-fluid') || document.querySelector('main.content');
      if (!host) return;
      var bar = document.createElement('div'); bar.className = 'mw-cal-bar'; bar.appendChild(seg);
      host.insertBefore(panel, host.firstChild); host.insertBefore(bar, panel);
    }
    // The cards board is what the timeline replaces: the 7 day columns (week) or the stop list + map (day).
    function findBoard() {
      if (weekMode) { var c = document.querySelector('.mw-day-column'); return c ? c.parentElement : null; }
      return document.querySelector('.mw-dv-container');
    }
    var bound = false;
    function setMode(m) {
      var tl = m === 'timeline';
      board = board || findBoard();
      if (board) board.style.display = tl ? 'none' : '';
      panel.hidden = !tl;
      if (weekMode) [].forEach.call(document.querySelectorAll('.mw-calendar-container .mw-calendar-header, .mw-calendar-container .mw-calendar-dates-header'), function (r) {
        r.style.gridTemplateColumns = tl ? '64px repeat(7, minmax(0, 1fr))' : '';
      });
      // The 64px time column needs its (normally hidden) cell, or Monday lands in it and an empty 8th column opens on the right.
      if (weekMode) [].forEach.call(document.querySelectorAll('.mw-calendar-container .mw-calendar-time-label'), function (l) { l.style.display = tl ? 'block' : ''; });
      [].forEach.call(seg.querySelectorAll('button'), function (x) { x.classList.toggle('on', x.dataset.m === m); });
      try { localStorage.setItem('mw_cal_mode', m); } catch (e) {}
      if (tl) { loadCrew(); refresh(); if (!bound) { bindGestures(); bound = true; } }
    }
    seg.addEventListener('click', function (e) { var b = e.target.closest('button[data-m]'); if (b) setMode(b.dataset.m); });
    var saved = null; try { saved = localStorage.getItem('mw_cal_mode'); } catch (e) {}
    var asked = new URLSearchParams(window.location.search).get('layout');   // from the List page's Cards / Timeline links
    if (asked === 'cards' || asked === 'timeline') saved = asked;
    if (saved === 'timeline') setMode('timeline'); else if (asked === 'cards') setMode('cards');
  }

  function mountWeek() {
    document.querySelectorAll('.mw-day-column[data-date]').forEach(function (col) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'mw-cal-add'; b.textContent = '+ Add';
      b.addEventListener('click', function (e) { var r = b.getBoundingClientRect(); openPopover({ x: r.left, y: r.bottom, date: col.dataset.date, start: '09:00', end: '10:00', crewId: 0 }); e.stopPropagation(); });
      col.appendChild(b);
    });
  }

  function init() { if (weekMode) { collectWeekDates(); mountWeek(); } mountDay(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
