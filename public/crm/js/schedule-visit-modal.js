/**
 * Schedule visit modal — click a job on the Timeline, read everything about it, and change it.
 * Loaded by schedule.php next to schedule-calendar-edit.js.
 *
 * Reads:  /crm/api/visit-summary.php (VisitSummaryService)
 * Writes (office only, jobs.edit; the server checks every one again):
 *   when/who   reschedule-visit.php (date), calendar-edit.php resize (time), assign-crew.php (crew)
 *   notes      pow-actions.php save_notes (add), visit-edit.php note_edit / note_delete
 *   status     pow-actions.php start_visit / end_visit / skip_visit, visit-edit.php cancel
 *   job        visit-edit.php plan_update (title, description, price)
 *
 *   MwVisitModal.open(visitId, { onClose: fn, onChange: fn })   open (or switch to) a visit
 *   MwVisitModal.close()
 *
 * One modal element is built on first use. It traps Tab, closes on Escape, the backdrop or ×,
 * and gives focus back to whatever opened it. Every save re-reads the visit and calls onChange
 * so the Timeline redraws.
 */
(function () {
  'use strict';
  if (window.MwVisitModal) return;

  var API = '/crm/api/visit-summary.php';
  var FULL_PAGE = '/crm/jobs/visit-detail.php?id=';
  var root = null, dialog = null, bodyEl = null, footEl = null, titleEl = null, subEl = null, statusEl = null;
  var currentId = 0, opener = null, onCloseCb = null, onChangeCb = null, token = 0;
  var gen = 0, wrote = false;   // gen changes when the modal opens or closes; answers to an older save are dropped
  var data = null;          // the summary on screen
  var ui = {};              // what is open: { edit: 'when'|'job'|'note-add'|'note-edit', noteId, confirm: {...}, busy, flash, error }
  var crewList = null;
  var datePopups = [];

  var STATUS = {
    scheduled: ['Scheduled', 'info'], in_progress: ['In progress', 'progress'], completed: ['Done', 'done'],
    skipped: ['Skipped', 'warn'], weather: ['Weather hold', 'warn'], cancelled: ['Cancelled', 'bad'], en_route: ['On the way', 'progress']
  };
  var NOTE_TYPE = { general: 'Note', customer_request: 'Client asked', issue: 'Problem', follow_up: 'Follow up', internal: 'Office only' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function clock(t) {
    var m = /^(\d{1,2}):(\d{2})/.exec(t || ''); if (!m) return '';
    var h = +m[1], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    return h + ':' + m[2] + ' ' + ap;
  }
  function hhmm(t) { var m = /^(\d{1,2}):(\d{2})/.exec(t || ''); return m ? ('0' + m[1]).slice(-2) + ':' + m[2] : ''; }
  function dayText(d) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); if (!m) return '';
    return new Date(+m[1], +m[2] - 1, +m[3], 12).toLocaleDateString('en-CA', { weekday: 'long', month: 'long', day: 'numeric' });
  }
  function stamp(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); if (!m) return '';
    return new Date(+m[1], +m[2] - 1, +m[3], 12).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' });
  }
  function money(n) { return n == null ? '' : '$' + Number(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function length(min) {
    if (!min) return '';
    var h = Math.floor(min / 60), m = min % 60;
    return (h ? h + ' h' : '') + (h && m ? ' ' : '') + (m ? m + ' min' : '');
  }
  function chip(status) {
    var s = STATUS[status] || [String(status || '').replace(/_/g, ' '), 'info'];
    return '<span class="mw-vm-chip mw-vm-chip--' + s[1] + '">' + esc(s[0]) + '</span>';
  }
  function row(label, value) {
    return value ? '<div class="mw-vm-row"><dt>' + esc(label) + '</dt><dd>' + value + '</dd></div>' : '';
  }
  function section(title, inner, action) {
    if (!inner && !action) return '';
    return '<section class="mw-vm-sec"><div class="mw-vm-sechead"><h3 class="mw-vm-h">' + esc(title) + '</h3>' + (action || '') + '</div>' + (inner || '') + '</section>';
  }
  function link(label, act, extra) {
    return '<button type="button" class="mw-vm-link" data-vm-act="' + act + '"' + (extra || '') + '>' + esc(label) + '</button>';
  }

  // ── Network ───────────────────────────────────────────────
  function csrf() { return window.MW_CSRF || window.MW_CSRF_TOKEN || ''; }
  function post(url, body) {
    body = body || {}; body.csrf_token = csrf();
    var g = gen;
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j._status = r.status; return j; }); })
      .then(function (j) {
        if (j && j.success && url.indexOf('calendar-edit.php') < 0) wrote = true;
        if (j && j.success && body.action === 'resize') wrote = true;
        return g === gen ? j : new Promise(function () {});   // the modal moved on: nothing left to update
      }, function () { return g === gen ? { success: false, error: 'Can’t reach the server. Check your connection and try again.', _status: 0 } : new Promise(function () {}); });
  }
  function failed(j) {
    if (j && j.success) return null;
    if (j && j.missing_types && j.missing_types.length) return 'These photos are needed first: ' + j.missing_types.join(', ') + '. Add them on the full visit page.';
    var m = j && (j.error || j.message || (j.errors && j.errors[0]));
    if (m) return String(m);
    if (j && j._status === 403) return 'You don’t have permission to do that.';
    return 'Couldn’t save. Nothing was changed.';
  }
  function fetchSummary(id) {
    return fetch(API + '?visit_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
  }
  function loadCrew() {
    if (crewList) return Promise.resolve(crewList);
    return post('/crm/api/calendar-edit.php', { action: 'crew' }).then(function (j) { crewList = j.crew || []; return crewList; });
  }

  // ── Build once ────────────────────────────────────────────
  function build() {
    if (root) return;
    root = document.createElement('div');
    root.className = 'mw-vm';
    root.hidden = true;
    root.innerHTML =
      '<div class="mw-vm-backdrop" data-vm-close></div>' +
      '<div class="mw-vm-dialog" role="dialog" aria-modal="true" aria-labelledby="mwVmTitle" tabindex="-1">' +
        '<header class="mw-vm-head">' +
          '<div class="mw-vm-headtext"><h2 class="mw-vm-title" id="mwVmTitle"></h2><p class="mw-vm-sub"></p></div>' +
          '<span class="mw-vm-status"></span>' +
          '<button type="button" class="mw-vm-x" data-vm-close aria-label="Close">×</button>' +
        '</header>' +
        '<div class="mw-vm-flash" role="status" aria-live="polite" hidden></div>' +
        '<div class="mw-vm-body"></div>' +
        '<footer class="mw-vm-foot"></footer>' +
      '</div>';
    document.body.appendChild(root);
    dialog = root.querySelector('.mw-vm-dialog');
    bodyEl = root.querySelector('.mw-vm-body');
    footEl = root.querySelector('.mw-vm-foot');
    titleEl = root.querySelector('.mw-vm-title');
    subEl = root.querySelector('.mw-vm-sub');
    statusEl = root.querySelector('.mw-vm-status');
    root.addEventListener('click', function (e) {
      if (e.target.closest('[data-vm-close]')) { close(); return; }
      var b = e.target.closest('[data-vm-act]');
      if (b && !ui.busy) act(b.getAttribute('data-vm-act'), b);
    });
    root.addEventListener('submit', function (e) { e.preventDefault(); });
  }

  function focusables() {
    return [].filter.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]'), function (el) { return el.offsetParent !== null; });
  }
  function onKey(e) {
    if (root.hidden) return;
    if (e.key === 'Escape') {
      if (datePopups.some(function (p) { return !p.hidden; })) return;   // the calendar closes itself first
      e.preventDefault(); e.stopPropagation();
      if (ui.edit || ui.confirm) { ui.edit = null; ui.confirm = null; ui.error = ''; paint(); } else close();
      return;
    }
    if (e.key !== 'Tab') return;
    var f = focusables(); if (!f.length) { e.preventDefault(); dialog.focus(); return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  // ── Screens ───────────────────────────────────────────────
  function flash(msg) {
    var f = root.querySelector('.mw-vm-flash');
    f.textContent = msg || ''; f.hidden = !msg;
    clearTimeout(flash._t);
    if (msg) flash._t = setTimeout(function () { f.hidden = true; }, 6000);
  }
  function showLoading() {
    titleEl.textContent = 'Loading job…';
    subEl.textContent = '';
    statusEl.innerHTML = '';
    flash('');
    bodyEl.innerHTML = '<div class="mw-vm-skel"></div><div class="mw-vm-skel mw-vm-skel--short"></div><div class="mw-vm-skel"></div>';
    footEl.innerHTML = '<button type="button" class="mw-vm-btn" data-vm-close>Close</button>';
  }
  function showError(msg) {
    titleEl.textContent = 'Couldn’t open this job';
    subEl.textContent = '';
    statusEl.innerHTML = '';
    bodyEl.innerHTML = '<p class="mw-vm-error">' + esc(msg) + '</p>';
    footEl.innerHTML = '<button type="button" class="mw-vm-btn" data-vm-retry>Try again</button><button type="button" class="mw-vm-btn mw-vm-btn--quiet" data-vm-close>Close</button>';
    var r = footEl.querySelector('[data-vm-retry]');
    if (r) r.addEventListener('click', function () { load(currentId); });
  }

  function errorLine() { return ui.error ? '<p class="mw-vm-error" role="alert">' + esc(ui.error) + '</p>' : ''; }
  function formButtons(saveLabel) {
    return '<div class="mw-vm-formbtns"><button type="button" class="mw-vm-btn mw-vm-btn--main" data-vm-act="save"' + (ui.busy ? ' disabled' : '') + '>' + esc(ui.busy ? 'Saving…' : saveLabel) + '</button>' +
      '<button type="button" class="mw-vm-btn mw-vm-btn--quiet" data-vm-act="cancel-edit">Cancel</button></div>';
  }
  function confirmBox() {
    var c = ui.confirm; if (!c) return '';
    return '<div class="mw-vm-confirm" role="alertdialog" aria-labelledby="mwVmConfirmText"><p id="mwVmConfirmText" class="mw-vm-text">' + esc(c.text) + '</p>' +
      '<div class="mw-vm-formbtns"><button type="button" class="mw-vm-btn' + (c.danger ? ' mw-vm-btn--danger' : ' mw-vm-btn--main') + '" data-vm-act="confirm-yes"' + (ui.busy ? ' disabled' : '') + '>' + esc(ui.busy ? 'Working…' : c.yes) + '</button>' +
      '<button type="button" class="mw-vm-btn mw-vm-btn--quiet" data-vm-act="confirm-no">' + esc(c.no || 'Go back') + '</button></div>' + errorLine() + '</div>';
  }


  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  function shortDay(d) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); if (!m) return d;
    return new Date(+m[1], +m[2] - 1, +m[3], 12).toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' }).replace(',', '');
  }
  function dayList(dates) {
    if (!dates || !dates.length) return '';
    var shown = dates.slice(0, 6).map(shortDay).join(', ');
    return shown + (dates.length > 6 ? ' and ' + (dates.length - 6) + ' more' : '');
  }
  // The end shown in the form: the stored one, or the start plus the usual length when only a start is stored.
  function endGuess(v) {
    if (v.end) return hhmm(v.end);
    var m = /^(\d{1,2}):(\d{2})/.exec(v.start || ''); if (!m) return '';
    var t = Math.min(23 * 60 + 45, (+m[1]) * 60 + (+m[2]) + (v.minutes || 60));
    return ('0' + Math.floor(t / 60)).slice(-2) + ':' + ('0' + (t % 60)).slice(-2);
  }
  function crewChecksHtml(s) {
    return crewList ? crewList.map(function (c) {
      var on = ui.crewPick ? ui.crewPick.indexOf(c.id) >= 0 : (s.crew_ids || []).indexOf(c.id) >= 0;
      return '<label class="mw-vm-check"><input type="checkbox" data-vm-crew="' + c.id + '"' + (on ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
    }).join('') : '<span class="mw-vm-muted">Loading the team…</span>';
  }
  function dayPicker(v) {
    return '<span class="mw-vm-field-label" id="mwVmDayLabel">Day</span>' +
      '<button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#mwVmDay" aria-haspopup="true" aria-expanded="false" aria-labelledby="mwVmDayLabel" data-vm-autofocus>' +
        '<svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
        '<span class="mw-datepicker-date" data-mw-dp-label></span>' +
        '<svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>' +
      '</button><input type="date" id="mwVmDay" data-vm-f="date" hidden value="' + esc(v.date || '') + '">';
  }

  function whenForm(s) {
    var v = s.visit, j = s.job, scope = j.recurring ? (ui.scope || 'one') : 'one';
    var scopeBox = j.recurring
      ? '<fieldset class="mw-vm-scope"><legend class="mw-vm-legend">Change</legend>' +
        '<label class="mw-vm-check"><input type="radio" name="mwVmScope" data-vm-act="scope-one"' + (scope === 'one' ? ' checked' : '') + '> Just this visit</label>' +
        '<label class="mw-vm-check"><input type="radio" name="mwVmScope" data-vm-act="scope-future"' + (scope === 'future' ? ' checked' : '') + '> This and all future visits</label></fieldset>'
      : '';
    var times = '<label class="mw-vm-field">Starts<input type="time" step="900" class="mw-vm-in" data-vm-f="start" value="' + esc(hhmm(v.start)) + '"></label>' +
      '<label class="mw-vm-field">Ends<input type="time" step="900" class="mw-vm-in" data-vm-f="end" value="' + esc(endGuess(v)) + '"></label>';
    var crew = '<fieldset class="mw-vm-crewpick"><legend class="mw-vm-legend">Crew</legend>' + crewChecksHtml(s) + '</fieldset>';
    if (scope === 'one') {
      return section('When and who',
        '<div class="mw-vm-form">' + scopeBox +
          '<div class="mw-vm-fields"><div class="mw-vm-field">' + dayPicker(v) + '</div>' + times + '</div>' + crew +
          '<p class="mw-vm-muted mw-vm-hint">Changing the crew changes it for every visit at this stop that day, and tells the new people.</p>' +
          errorLine() + confirmBox() + (ui.confirm ? '' : formButtons('Save changes')) +
        '</div>');
    }
    var rec = j.recurrence || { every: 1, unit: 'weeks', days: [] };
    var dayBoxes = DAYS.map(function (n, i) {
      var on = ui.daysPick ? ui.daysPick.indexOf(i) >= 0 : (rec.days || []).indexOf(i) >= 0;
      return '<label class="mw-vm-check"><input type="checkbox" data-vm-day="' + i + '"' + (on ? ' checked' : '') + '> ' + n + '</label>';
    }).join('');
    var pv = ui.preview;
    return section('When and who',
      '<div class="mw-vm-form">' + scopeBox +
        '<p class="mw-vm-muted mw-vm-hint">Changes ' + esc(shortDay(v.date)) + ' and every later visit of this job. Earlier visits are left alone.</p>' +
        '<div class="mw-vm-fields">' + times + '</div>' + crew +
        '<fieldset class="mw-vm-repeat"><legend class="mw-vm-legend">Repeats</legend>' +
          '<div class="mw-vm-fields"><label class="mw-vm-field">Every<input type="number" class="mw-vm-in" min="1" max="12" data-vm-f="every" value="' + esc(ui.every || rec.every) + '"></label>' +
          '<label class="mw-vm-field">Unit<select class="mw-vm-in" data-vm-f="unit"><option value="weeks"' + ((ui.unit || rec.unit) === 'weeks' ? ' selected' : '') + '>weeks</option><option value="months"' + ((ui.unit || rec.unit) === 'months' ? ' selected' : '') + '>months</option></select></label></div>' +
          '<div class="mw-vm-crewpick">' + dayBoxes + '</div>' +
          '<p class="mw-vm-muted mw-vm-hint">Weekdays only apply to weekly repeats. A monthly repeat keeps the job’s day of the month. Repeats count from the job’s first visit, so the preview shows the real dates.</p>' +
        '</fieldset>' +
        '<label class="mw-vm-check"><input type="checkbox" data-vm-f="stop_after"> Stop repeating after this visit</label>' +
        '<label class="mw-vm-check"><input type="checkbox" data-vm-f="include_moved"> Also change visits I moved by hand</label>' +
        (pv ? previewHtml(pv) : '') + errorLine() +
        '<div class="mw-vm-formbtns">' +
          (pv ? '<button type="button" class="mw-vm-btn mw-vm-btn--main" data-vm-act="recur-apply"' + (ui.busy ? ' disabled' : '') + '>' + esc(ui.busy ? 'Saving…' : 'Apply to this and future visits') + '</button>' : '') +
          '<button type="button" class="mw-vm-btn' + (pv ? '' : ' mw-vm-btn--main') + '" data-vm-act="recur-preview"' + (ui.busy ? ' disabled' : '') + '>' + (pv ? 'Preview again' : 'Preview changes') + '</button>' +
          '<button type="button" class="mw-vm-btn mw-vm-btn--quiet" data-vm-act="cancel-edit">Cancel</button>' +
        '</div>' +
      '</div>');
  }

  function previewHtml(pv) {
    var lines = [];
    if (pv.time_dates.length) lines.push('New time on ' + pv.time_dates.length + ' visit' + (pv.time_dates.length === 1 ? '' : 's') + ': ' + dayList(pv.time_dates) + '.');
    if (pv.crew_dates.length) lines.push('New crew on ' + pv.crew_dates.length + ' visit' + (pv.crew_dates.length === 1 ? '' : 's') + ': ' + dayList(pv.crew_dates) + '.');
    if (pv.cancel_dates.length) lines.push('Cancelled: ' + dayList(pv.cancel_dates) + '.');
    if (pv.add_dates.length) lines.push('Added: ' + dayList(pv.add_dates) + '.');
    if (!lines.length) lines.push('Nothing would change.');
    var keep = (pv.keep || []).length ? '<p class="mw-vm-text"><strong>Left as they are:</strong> ' + pv.keep.slice(0, 8).map(function (k) { return esc(shortDay(k.date)) + ' (' + esc(k.why) + ')'; }).join(', ') + (pv.keep.length > 8 ? ' and ' + (pv.keep.length - 8) + ' more' : '') + '.</p>' : '';
    return '<div class="mw-vm-confirm" role="status"><p class="mw-vm-text"><strong>Preview, from ' + esc(shortDay(pv.from)) + '</strong></p>' +
      lines.map(function (l) { return '<p class="mw-vm-text">' + esc(l) + '</p>'; }).join('') + keep + '</div>';
  }

  function repeatSection(s) {
    var j = s.job;
    if (!s.can_edit || !j.recurring) return '';
    if (ui.confirm && (ui.confirm.kind === 'pause' || ui.confirm.kind === 'resume')) return section('Repeating job', confirmBox());
    var paused = j.plan_status === 'paused';
    return section('Repeating job',
      '<p class="mw-vm-text">' + (paused ? 'This job is paused. No new visits are being made.' : 'Repeats: ' + esc(j.repeat) + '.') + '</p>' +
      '<div class="mw-vm-formbtns">' + (paused
        ? '<button type="button" class="mw-vm-btn" data-vm-act="resume">Resume this job</button>'
        : '<button type="button" class="mw-vm-btn mw-vm-btn--danger" data-vm-act="pause">Pause this job</button>') + '</div>');
  }

  function whenSection(s) {
    var v = s.visit, j = s.job;
    var canChange = s.can_edit && v.status === 'scheduled' && !v.locked;
    var crewNames = (s.crew || []).length ? esc(s.crew.join(', ')) : '<span class="mw-vm-muted">No one yet</span>';
    if (ui.edit === 'when' && canChange) return whenForm(s);
    var inner = '<dl class="mw-vm-dl">' + row('When', esc(dayText(v.date) + (v.start ? ', ' + clock(v.start) + (v.end ? ' to ' + clock(v.end) : '') : (v.date ? ', any time' : '')))) +
      row('Length', esc(length(v.minutes))) + row('Crew', crewNames) + row('Repeats', esc(j.repeat)) +
      row('Started', v.started_at ? esc(stamp(v.started_at)) + ' ' + esc(clock(String(v.started_at).split(' ')[1])) : '') +
      row('Finished', v.completed_at ? esc(stamp(v.completed_at)) + ' ' + esc(clock(String(v.completed_at).split(' ')[1])) : '') + '</dl>' +
      (s.can_edit && !canChange && v.status === 'scheduled' ? '<p class="mw-vm-muted mw-vm-hint">This visit is locked, so its time and crew can’t change here.</p>' : '');
    return section('When and who', inner, canChange ? link('Change', 'edit-when') : '');
  }

  function jobSection(s) {
    var v = s.visit, j = s.job;
    if (ui.edit === 'job' && s.can_edit) {
      return section('Job',
        '<div class="mw-vm-form">' +
          '<label class="mw-vm-field mw-vm-field--wide">Title<input type="text" class="mw-vm-in" data-vm-f="title" maxlength="255" value="' + esc(j.title) + '" data-vm-autofocus></label>' +
          '<label class="mw-vm-field mw-vm-field--wide">Description<textarea class="mw-vm-in" data-vm-f="description" rows="3">' + esc(j.description) + '</textarea></label>' +
          (s.job_line_items
            ? '<p class="mw-vm-muted mw-vm-hint">This job is priced by its line items. Change the price on the job page.</p>'
            : '<label class="mw-vm-field">Price per visit<input type="number" class="mw-vm-in" data-vm-f="price" min="0.01" step="0.01" value="' + esc(v.price == null ? '' : v.price) + '"></label>' +
              '<label class="mw-vm-check mw-vm-pricecheck"><input type="checkbox" data-vm-f="confirm_price"> I understand a new price applies to every visit of this job that isn’t invoiced yet.</label>') +
          '<p class="mw-vm-muted mw-vm-hint">Title and description change for every visit of this job.</p>' +
          errorLine() + formButtons('Save job') +
        '</div>');
    }
    return section('Job',
      '<dl class="mw-vm-dl">' + row('Service', esc(j.service)) + row('Job', esc(j.title) + (j.plan_number ? ' <span class="mw-vm-muted">' + esc(j.plan_number) + '</span>' : '')) +
      row('Price per visit', esc(money(v.price))) + row('Visit number', esc(v.number)) + '</dl>' +
      (j.description ? '<p class="mw-vm-text">' + esc(j.description) + '</p>' : ''),
      s.can_edit ? link('Edit job', 'edit-job') : '');
  }

  function notesSection(s) {
    var v = s.visit, notes = (s.notes || []).slice();
    if (v.completion_notes) notes.unshift({ id: null, author_id: null, scope: 'visit', type: 'general', text: v.completion_notes, author: null, at: v.completed_at });
    var html = '';
    if (notes.length) {
      html += '<ul class="mw-vm-notes">' + notes.map(function (n) {
        var mine = s.can_edit && n.id && n.author_id;
        if (ui.edit === 'note-edit' && n.id && ui.noteId === n.id) {
          return '<li><div class="mw-vm-form"><label class="mw-vm-field mw-vm-field--wide">Note<textarea class="mw-vm-in" data-vm-f="note" rows="3" maxlength="4000" data-vm-autofocus>' + esc(n.text) + '</textarea></label>' +
            errorLine() + formButtons('Save note') + '</div></li>';
        }
        return '<li><div class="mw-vm-notehead"><span>' + esc(NOTE_TYPE[n.type] || 'Note') + (n.scope === 'job' ? ' <span class="mw-vm-muted">(whole job)</span>' : '') + '</span>' +
          '<span class="mw-vm-muted">' + esc([n.author, stamp(n.at)].filter(Boolean).join(' · ')) + '</span></div><p class="mw-vm-text">' + esc(n.text) + '</p>' +
          (mine ? '<div class="mw-vm-noteacts">' + link('Edit', 'note-edit', ' data-vm-note="' + n.id + '"') + link('Delete', 'note-delete', ' data-vm-note="' + n.id + '"') + '</div>' : '') + '</li>';
      }).join('') + '</ul>';
    }
    if (ui.confirm && ui.confirm.kind === 'note-delete') html += confirmBox();
    if (ui.edit === 'note-add') {
      html += '<div class="mw-vm-form"><label class="mw-vm-field mw-vm-field--wide">New note<textarea class="mw-vm-in" data-vm-f="note" rows="3" maxlength="4000" data-vm-autofocus></textarea></label>' + errorLine() + formButtons('Add note') + '</div>';
    }
    return section('Notes', html, s.can_edit && ui.edit !== 'note-add' ? link('Add note', 'note-add') : '');
  }

  function statusSection(s) {
    var v = s.visit;
    if (!s.can_edit || v.locked || ['completed', 'cancelled', 'skipped'].indexOf(v.status) >= 0) return '';
    if (ui.confirm && ['start', 'complete', 'skip', 'cancel'].indexOf(ui.confirm.kind) >= 0) return section('Change status', confirmBox());
    var b = '';
    if (v.status === 'scheduled') b += '<button type="button" class="mw-vm-btn" data-vm-act="start">Start visit</button>';
    b += '<button type="button" class="mw-vm-btn" data-vm-act="complete">Mark done</button>';
    if (v.status === 'scheduled') b += '<button type="button" class="mw-vm-btn" data-vm-act="skip">Skip visit</button>';
    if (!v.invoiced) b += '<button type="button" class="mw-vm-btn mw-vm-btn--danger" data-vm-act="cancel">Cancel visit</button>';
    return section('Change status', '<div class="mw-vm-formbtns">' + b + '</div>');
  }

  function paint() {
    var s = data; if (!s) return;
    var v = s.visit, c = s.client, p = s.property;
    var who = c.company || c.name || s.job.title || 'Job';
    var addr = [p.address, p.city].filter(Boolean).join(', ');
    titleEl.textContent = who;
    subEl.textContent = addr;
    statusEl.innerHTML = chip(v.status);

    var h = '';
    h += whenSection(s);
    h += jobSection(s);
    h += section('Client',
      '<dl class="mw-vm-dl">' + row('Name', esc(c.name || '')) +
      row('Phone', c.phone ? '<a href="tel:' + esc(c.phone) + '">' + esc(c.phone) + '</a>' : '') +
      row('Email', c.email ? '<a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a>' : '') + '</dl>');
    h += section('Property',
      '<dl class="mw-vm-dl">' + row('Address', esc([addr, p.postal].filter(Boolean).join(' '))) +
      row('Lawn size', p.sqft ? esc(Math.round(p.sqft).toLocaleString('en-CA')) + ' sq ft' : '') + '</dl>' +
      (p.stop_notes ? '<p class="mw-vm-text"><strong>For this stop:</strong> ' + esc(p.stop_notes) + '</p>' : '') +
      (p.notes ? '<p class="mw-vm-text"><strong>About the property:</strong> ' + esc(p.notes) + '</p>' : ''));
    var cl = s.checklist || { items: [], done: 0, total: 0 };
    if (cl.total) {
      h += section('Checklist · ' + cl.done + ' of ' + cl.total + ' done', '<ul class="mw-vm-list">' + cl.items.map(function (i) {
        return '<li class="' + (i.done ? 'mw-vm-done' : '') + '"><span class="mw-vm-tick" aria-hidden="true">' + (i.done ? '✓' : '○') + '</span><span>' + esc(i.label) +
          '<span class="mw-vm-sr">' + (i.done ? ', done' : ', not done') + '</span>' + (i.note ? '<span class="mw-vm-muted"> – ' + esc(i.note) + '</span>' : '') + '</span></li>';
      }).join('') + '</ul>');
    }
    if ((s.materials || []).length) {
      h += section('Materials', '<ul class="mw-vm-list">' + s.materials.map(function (m) {
        return '<li><span>' + esc(m.name) + (m.qty ? '<span class="mw-vm-muted"> · ' + esc(m.qty + (m.unit ? ' ' + m.unit : '')) + '</span>' : '') + '</span></li>';
      }).join('') + '</ul>');
    }
    h += notesSection(s);
    var ph = s.photos || {};
    h += section('Photos', ph.total
      ? '<p class="mw-vm-text">' + ph.total + (ph.total === 1 ? ' photo' : ' photos') + ': ' + ph.before + ' before, ' + ph.during + ' during, ' + ph.after + ' after' + (ph.other ? ', ' + ph.other + ' other' : '') + '.</p>'
      : '<p class="mw-vm-muted">No photos yet.</p>');
    if (s.invoice) {
      h += section('Invoice', '<dl class="mw-vm-dl">' +
        row('Invoice', '<a href="/crm/invoices/view.php?id=' + encodeURIComponent(s.invoice.id) + '">' + esc(s.invoice.number) + '</a> <span class="mw-vm-muted">' + esc(String(s.invoice.status || '').replace(/_/g, ' ')) + '</span>') +
        row('Total', esc(money(s.invoice.total))) + row('Still owing', esc(money(s.invoice.balance_due))) + '</dl>');
    }
    if ((s.same_stop || []).length) {
      h += section('Also at this stop', '<ul class="mw-vm-list">' + s.same_stop.map(function (o) {
        return '<li><span>' + esc(o.title) + (o.start ? '<span class="mw-vm-muted"> · ' + esc(clock(o.start)) + '</span>' : '') + '</span> ' + chip(o.status) + '</li>';
      }).join('') + '</ul>');
    }
    h += statusSection(s);
    h += repeatSection(s);

    // Keep what was typed when the screen redraws for "Saving..." or an error, but only inside the same form.
    var formKey = (ui.edit || '') + ':' + (ui.noteId || '');
    var kept = {};
    if (paint._form === formKey) {
      [].forEach.call(bodyEl.querySelectorAll('[data-vm-f]'), function (el) { kept['f:' + el.getAttribute('data-vm-f')] = el.type === 'checkbox' ? el.checked : el.value; });
      [].forEach.call(bodyEl.querySelectorAll('[data-vm-crew]'), function (el) { kept['c:' + el.getAttribute('data-vm-crew')] = el.checked; });
      [].forEach.call(bodyEl.querySelectorAll('[data-vm-day]'), function (el) { kept['d:' + el.getAttribute('data-vm-day')] = el.checked; });
    }
    paint._form = formKey;
    var scroll = bodyEl.scrollTop;
    bodyEl.innerHTML = h;
    bodyEl.scrollTop = scroll;
    Object.keys(kept).forEach(function (k) {
      var el = k.charAt(0) === 'f' ? bodyEl.querySelector('[data-vm-f="' + k.slice(2) + '"]')
        : k.charAt(0) === 'd' ? bodyEl.querySelector('[data-vm-day="' + k.slice(2) + '"]') : bodyEl.querySelector('[data-vm-crew="' + k.slice(2) + '"]');
      if (!el) return;
      if (el.type === 'checkbox') el.checked = kept[k]; else el.value = kept[k];
    });

    var nav = p.lat != null && p.lng != null
      ? 'https://www.google.com/maps/dir/?api=1&destination=' + p.lat + ',' + p.lng
      : (addr ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr + ', BC, Canada') : '');
    footEl.innerHTML =
      '<a class="mw-vm-btn mw-vm-btn--main" href="' + FULL_PAGE + encodeURIComponent(v.id) + '">Open full visit</a>' +
      (nav ? '<a class="mw-vm-btn" href="' + esc(nav) + '" target="_blank" rel="noopener">Directions</a>' : '') +
      (c.phone ? '<a class="mw-vm-btn" href="tel:' + esc(c.phone) + '">Call client</a>' : '') +
      '<button type="button" class="mw-vm-btn mw-vm-btn--quiet" data-vm-close>Close</button>';

    // The shared date picker builds a popup on <body> for each trigger: drop last paint's, then wire this paint's.
    datePopups.forEach(function (p) { if (p.parentNode) p.parentNode.removeChild(p); });
    datePopups = [];
    if (window.mwInitDatePickers && bodyEl.querySelector('.mw-datepicker-trigger')) {
      var before = [].slice.call(document.querySelectorAll('.mw-datepicker-popup'));
      window.mwInitDatePickers(bodyEl);
      datePopups = [].filter.call(document.querySelectorAll('.mw-datepicker-popup'), function (p) { return before.indexOf(p) < 0; });
    }
    var af = bodyEl.querySelector('[data-vm-autofocus]');
    if (af && ui.focusForm) { ui.focusForm = false; af.focus(); }
  }

  // ── Actions ───────────────────────────────────────────────
  function field(name) { var el = bodyEl.querySelector('[data-vm-f="' + name + '"]'); return el; }
  function done(msg) {
    ui = { flashed: true };
    flash(msg);
    if (onChangeCb) { try { onChangeCb(currentId); } catch (e) {} }
    return reload();
  }
  function reload() {
    var mine = ++token;
    return fetchSummary(currentId).then(function (j) {
      if (mine !== token) return;
      if (j && j.success && j.summary) { data = j.summary; paint(); }
      else showError('This visit no longer exists. Refresh the schedule.');
    }).catch(function () {});
  }
  function fail(msg) { ui.busy = false; ui.error = msg; paint(); }
  function busy() { ui.busy = true; ui.error = ''; paint(); }

  function act(name, el) {
    var s = data;
    switch (name) {
      case 'scope-one': ui.scope = 'one'; ui.preview = null; ui.error = ''; paint(); return;
      case 'scope-future': ui.scope = 'future'; ui.preview = null; ui.error = ''; paint(); return;
      case 'recur-preview': return recurPreview();
      case 'recur-apply': return recurApply();
      case 'pause': ui = { confirm: { kind: 'pause', text: 'Pause this job? Every scheduled visit from today on is cancelled until you resume it. Visits already done are not touched.', yes: 'Pause job', danger: true, no: 'Keep it going' } }; paint(); return;
      case 'resume': ui = { confirm: { kind: 'resume', text: 'Resume this job? New visits are made for the coming weeks.', yes: 'Resume job' } }; paint(); return;
      case 'edit-when':
        ui = { edit: 'when', scope: 'one', focusForm: true }; paint();
        loadCrew().then(function () { if (ui.edit === 'when') { ui.crewPick = null; paint(); } });
        return;
      case 'edit-job': ui = { edit: 'job', focusForm: true }; paint(); return;
      case 'note-add': ui = { edit: 'note-add', focusForm: true }; paint(); return;
      case 'note-edit': ui = { edit: 'note-edit', noteId: +el.getAttribute('data-vm-note'), focusForm: true }; paint(); return;
      case 'note-delete':
        ui = { confirm: { kind: 'note-delete', text: 'Delete this note? This can’t be undone.', yes: 'Delete note', danger: true, noteId: +el.getAttribute('data-vm-note') } }; paint(); return;
      case 'cancel-edit': ui = {}; paint(); return;
      case 'confirm-no': ui = {}; paint(); return;
      case 'save': return save();
      case 'confirm-yes': return confirmYes();
      case 'start': ui = { confirm: { kind: 'start', text: 'Start this visit now? The crew’s timer and the client’s notices follow the usual rules.', yes: 'Start visit' } }; paint(); return;
      case 'complete': ui = { confirm: { kind: 'complete', text: 'Mark this visit done? Required photos must be on the visit first.', yes: 'Mark done' } }; paint(); return;
      case 'skip': ui = { confirm: { kind: 'skip', text: 'Skip this visit? It stays in the history as skipped.', yes: 'Skip visit' } }; paint(); return;
      case 'cancel': ui = { confirm: { kind: 'cancel', text: 'Cancel this visit? It comes off the schedule. Other visits of this job are not touched.', yes: 'Cancel visit', no: 'Keep visit', danger: true } }; paint(); return;
    }
  }

  function confirmYes() {
    var c = ui.confirm; if (!c) return;
    busy();
    var id = currentId, req;
    if (c.kind === 'note-delete') req = post('/crm/api/visit-edit.php', { action: 'note_delete', note_id: c.noteId });
    else if (c.kind === 'cancel') req = post('/crm/api/visit-edit.php', { action: 'cancel', visit_id: id });
    else if (c.kind === 'pause') req = post('/crm/api/visit-edit.php', { action: 'plan_pause', visit_id: id });
    else if (c.kind === 'resume') req = post('/crm/api/visit-edit.php', { action: 'plan_resume', visit_id: id });
    else if (c.kind === 'start') req = post('/crm/api/pow-actions.php', { action: 'start_visit', visit_id: id });
    else if (c.kind === 'complete') req = post('/crm/api/pow-actions.php', { action: 'end_visit', visit_id: id });
    else if (c.kind === 'skip') req = post('/crm/api/pow-actions.php', { action: 'skip_visit', visit_id: id });
    else if (c.kind === 'resize') {
      return doTime(c.start, c.end, true, null, true).then(function () { return c.resume ? c.resume() : null; })
        .then(function () { done('Saved.'); }, function (e) {
          if (e && e.overlap) return;
          var msg = (c.saved && c.saved.length ? 'Saved: ' + c.saved.join(', ') + '. ' : '') + (typeof e === 'string' ? e : 'Couldn’t save the rest.');
          if (onChangeCb) { try { onChangeCb(currentId); } catch (x) {} }
          reload().then(function () { ui.edit = 'when'; ui.error = msg; paint(); });
        });
    }
    else return;
    req.then(function (j) {
      var m = failed(j); if (m) { ui.confirm = c; return fail(m); }
      done({ pause: 'Job paused.', resume: 'Job resumed.', 'note-delete': 'Note deleted.', cancel: 'Visit cancelled.', start: 'Visit started.', complete: 'Visit marked done.', skip: 'Visit skipped.' }[c.kind]);
    });
  }

  function save() {
    if (ui.edit === 'note-add') {
      var t = (field('note').value || '').trim();
      if (!t) return fail('Write a note first.');
      busy();
      return post('/crm/api/pow-actions.php', { action: 'save_notes', visit_id: currentId, content: t, note_type: 'general' }).then(function (j) {
        var m = failed(j); if (m) { ui.edit = 'note-add'; return fail(m); }
        done('Note added.');
      });
    }
    if (ui.edit === 'note-edit') {
      var t2 = (field('note').value || '').trim();
      if (!t2) return fail('Write a note first.');
      var nid = ui.noteId; busy(); ui.edit = 'note-edit'; ui.noteId = nid;
      return post('/crm/api/visit-edit.php', { action: 'note_edit', note_id: nid, text: t2 }).then(function (j) {
        var m = failed(j); if (m) return fail(m);
        done('Note saved.');
      });
    }
    if (ui.edit === 'job') return saveJob();
    if (ui.edit === 'when') return saveWhen();
  }

  // "This and all future visits": send only what actually differs from the job today.
  function futureBody() {
    var s = data, v = s.visit, rec = s.job.recurrence || { every: 1, unit: 'weeks', days: [] };
    var body = { visit_id: currentId };
    var start = field('start').value, end = field('end').value;
    if (start !== hhmm(v.start) || end !== endGuess(v)) { body.time_start = start; body.time_end = end; }
    var picked = [].map.call(bodyEl.querySelectorAll('[data-vm-crew]:checked'), function (c) { return +c.getAttribute('data-vm-crew'); }).sort();
    if (picked.join(',') !== (s.crew_ids || []).slice().sort().join(',')) body.crew_ids = picked;
    var every = +field('every').value, unit = field('unit').value;
    var days = [].map.call(bodyEl.querySelectorAll('[data-vm-day]:checked'), function (c) { return +c.getAttribute('data-vm-day'); }).sort();
    ui.every = every; ui.unit = unit; ui.daysPick = days; ui.crewPick = picked;
    if (every !== rec.every || unit !== rec.unit || (unit === 'weeks' && days.join(',') !== (rec.days || []).join(','))) body.repeat = { every: every, unit: unit, days: unit === 'weeks' ? days : [] };
    if (field('stop_after').checked) body.stop_after = 1;
    if (field('include_moved').checked) body.include_moved = 1;
    return body;
  }
  function recurPreview() {
    var body = futureBody(); body.action = 'recur_preview';
    ui.error = ''; ui.busy = true; ui.preview = null; paint();
    return post('/crm/api/visit-edit.php', body).then(function (j) {
      ui.busy = false;
      var m = failed(j); if (m) return fail(m);
      ui.preview = j.preview; ui.previewSig = JSON.stringify(stripAction(body)); paint();
      var box = bodyEl.querySelector('.mw-vm-confirm'); if (box && box.scrollIntoView) box.scrollIntoView({ block: 'nearest' });
    });
  }
  function stripAction(b) { var c = {}; Object.keys(b).forEach(function (k) { if (k !== 'action' && k !== 'csrf_token') c[k] = b[k]; }); return c; }
  function recurApply() {
    var body = futureBody();
    if (JSON.stringify(stripAction(body)) !== ui.previewSig) { ui.preview = null; return fail('You changed something after the preview. Preview again to check it.'); }
    body.action = 'recur_apply';
    busy();
    return post('/crm/api/visit-edit.php', body).then(function (j) {
      var m = failed(j); if (m) { ui.preview = null; return fail(m); }
      var r = j.result || {}, kept = (r.keep || []).length;
      done('Saved for this and future visits.' + (kept ? ' ' + kept + ' left as they were.' : ''));
    });
  }

  function saveJob() {
    var body = { action: 'plan_update', visit_id: currentId, title: field('title').value, description: field('description').value };
    var price = field('price');
    if (price && price.value !== '' && String(price.value) !== String(data.visit.price)) {
      body.price_per_visit = price.value; body.confirm_price = field('confirm_price').checked ? 1 : 0;
    }
    busy(); ui.edit = 'job';
    return post('/crm/api/visit-edit.php', body).then(function (j) {
      var m = failed(j); if (m) return fail(m);
      done('Job saved.');
    });
  }

  // Date → time → crew, one step at a time so a refusal tells you which part didn't go through.
  function saveWhen() {
    var v = data.visit;
    var date = field('date').value, start = field('start').value, end = field('end').value;
    var picked = [].map.call(bodyEl.querySelectorAll('[data-vm-crew]:checked'), function (c) { return +c.getAttribute('data-vm-crew'); }).sort();
    var was = (data.crew_ids || []).slice().sort();
    ui.crewPick = picked;
    if (!date) return fail('Pick a day.');
    if ((start && !end) || (!start && end)) return fail('Give both a start and an end time, or neither.');
    if (start && end && end <= start) return fail('The end time has to be after the start time.');
    var dateChanged = date !== v.date;
    var timeChanged = !!start && (start !== hhmm(v.start) || end !== hhmm(v.end));
    var crewChanged = picked.join(',') !== was.join(',');
    if (!dateChanged && !timeChanged && !crewChanged) { ui = {}; paint(); return; }
    ui.edit = 'when'; busy();
    var saved = []; ui.savedSoFar = saved;
    function stop(msg) {
      ui.busy = false; ui.error = (saved.length ? 'Saved: ' + saved.join(', ') + '. ' : '') + msg;
      if (saved.length) { if (onChangeCb) { try { onChangeCb(currentId); } catch (e) {} } return reload().then(function () { ui.edit = 'when'; ui.error = (saved.length ? 'Saved: ' + saved.join(', ') + '. ' : '') + msg; paint(); }); }
      paint();
    }
    var chain = Promise.resolve();
    if (dateChanged) chain = chain.then(function () {
      return post('/crm/api/reschedule-visit.php', { visit_id: currentId, new_date: date }).then(function (j) {
        var m = failed(j); if (m) throw m; saved.push('the day');
      });
    });
    function crewStep() {
      if (!crewChanged) return Promise.resolve();
      return fetchSummary(currentId).then(function (j) {
        var stopId = j && j.summary && j.summary.visit.stop_id;
        if (!stopId) throw 'This visit isn’t on a stop yet, so there’s no crew to change.';
        return post('/crm/api/assign-crew.php', { stop_id: stopId, crew_ids: picked, scope: 'this_visit' });
      }).then(function (j) { var m = failed(j); if (m) throw m; saved.push('the crew'); });
    }
    if (timeChanged) chain = chain.then(function () { return doTime(start, end, false, crewStep, true); });
    chain = chain.then(function () { return timeChanged && ui.confirm ? null : crewStep(); });
    return chain.then(function () { done('Saved.'); }, function (e) {
      if (e && e.overlap) return; // doTime opened the confirm box
      stop(typeof e === 'string' ? e : 'Couldn’t save. Nothing more was changed.');
    });
  }

  // Time window. An overlap with the same person's other visit asks first; "Keep anyway" repeats it with force.
  function doTime(start, end, force, then, inChain) {
    return post('/crm/api/calendar-edit.php', { action: 'resize', visit_id: currentId, start: start, end: end, force: !!force }).then(function (j) {
      if (j && j.warning) {
        ui.busy = false; ui.edit = 'when';
        ui.confirm = { kind: 'resize', text: (j.message || 'This overlaps another visit for the same person.') + ' Keep the new time anyway?', yes: 'Keep anyway', no: 'Go back', start: start, end: end, resume: then, saved: ui.savedSoFar };
        paint();
        var e = { overlap: true }; if (inChain) throw e; return;
      }
      var m = failed(j);
      if (m) { if (inChain) throw m; return fail(m); }
      if (!inChain) done('Time saved.');
    });
  }

  // ── Load / open / close ───────────────────────────────────
  function load(id) {
    var mine = ++token;
    ui = {}; data = null;
    showLoading();
    fetchSummary(id)
      .then(function (j) {
        if (mine !== token) return;
        if (j && j.success && j.summary) { data = j.summary; paint(); return; }
        showError(j && j._status === 403 ? 'You are not on this visit, so you can’t open it.'
          : j && j._status === 404 ? 'This visit no longer exists. Refresh the schedule.'
          : 'Something went wrong on our side. Try again.');
      })
      .catch(function () { if (mine === token) showError('Can’t reach the server. Check your connection and try again.'); });
  }

  function open(visitId, opts) {
    visitId = parseInt(visitId, 10);
    if (!visitId) return;
    build();
    opts = opts || {};
    gen++;
    if (root.hidden) {
      wrote = false;
      opener = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      root.hidden = false;
      document.body.classList.add('mw-vm-lock');
      document.addEventListener('keydown', onKey, true);
    }
    onCloseCb = opts.onClose || null;
    onChangeCb = opts.onChange || null;
    currentId = visitId;
    load(visitId);
    dialog.focus();
  }

  function close() {
    if (!root || root.hidden) return;
    token++; gen++;
    root.hidden = true;
    datePopups.forEach(function (p) { if (p.parentNode) p.parentNode.removeChild(p); }); datePopups = [];
    document.body.classList.remove('mw-vm-lock');
    document.removeEventListener('keydown', onKey, true);
    var cb = onCloseCb, ch = onChangeCb; onCloseCb = null; onChangeCb = null;
    if (wrote && ch) { try { ch(currentId); } catch (e) {} }   // a save that was still in flight when the modal closed
    wrote = false;
    if (cb) { try { cb(currentId); } catch (e) {} }
    else if (opener && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
    opener = null;
  }

  window.MwVisitModal = { open: open, close: close };
})();
