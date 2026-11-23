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
  var data = null;          // the summary on screen
  var ui = {};              // what is open: { edit: 'when'|'job'|'note-add'|'note-edit', noteId, confirm: {...}, busy, flash, error }
  var crewList = null;

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
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j._status = r.status; return j; }); })
      .catch(function () { return { success: false, error: 'Can’t reach the server. Check your connection and try again.', _status: 0 }; });
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

  function whenSection(s) {
    var v = s.visit, j = s.job;
    var canChange = s.can_edit && v.status === 'scheduled' && !v.locked;
    var crewNames = (s.crew || []).length ? esc(s.crew.join(', ')) : '<span class="mw-vm-muted">No one yet</span>';
    if (ui.edit === 'when' && canChange) {
      var crewChecks = crewList ? crewList.map(function (c) {
        var on = ui.crewPick ? ui.crewPick.indexOf(c.id) >= 0 : (s.crew_ids || []).indexOf(c.id) >= 0;
        return '<label class="mw-vm-check"><input type="checkbox" data-vm-crew="' + c.id + '"' + (on ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
      }).join('') : '<span class="mw-vm-muted">Loading the team…</span>';
      return section('When and who',
        '<div class="mw-vm-form">' +
          '<div class="mw-vm-fields">' +
            '<label class="mw-vm-field">Day<input type="date" class="mw-vm-in" data-vm-f="date" value="' + esc(v.date || '') + '" data-vm-autofocus></label>' +
            '<label class="mw-vm-field">Starts<input type="time" step="900" class="mw-vm-in" data-vm-f="start" value="' + esc(hhmm(v.start)) + '"></label>' +
            '<label class="mw-vm-field">Ends<input type="time" step="900" class="mw-vm-in" data-vm-f="end" value="' + esc(hhmm(v.end)) + '"></label>' +
          '</div>' +
          '<fieldset class="mw-vm-crewpick"><legend class="mw-vm-legend">Crew</legend>' + crewChecks + '</fieldset>' +
          '<p class="mw-vm-muted mw-vm-hint">Changing the crew changes it for every visit at this stop that day, and tells the new people.</p>' +
          errorLine() + confirmBox() + (ui.confirm ? '' : formButtons('Save changes')) +
        '</div>');
    }
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

    // Keep what was typed when the screen redraws for "Saving..." or an error, but only inside the same form.
    var formKey = (ui.edit || '') + ':' + (ui.noteId || '');
    var kept = {};
    if (paint._form === formKey) {
      [].forEach.call(bodyEl.querySelectorAll('[data-vm-f]'), function (el) { kept['f:' + el.getAttribute('data-vm-f')] = el.type === 'checkbox' ? el.checked : el.value; });
      [].forEach.call(bodyEl.querySelectorAll('[data-vm-crew]'), function (el) { kept['c:' + el.getAttribute('data-vm-crew')] = el.checked; });
    }
    paint._form = formKey;
    var scroll = bodyEl.scrollTop;
    bodyEl.innerHTML = h;
    bodyEl.scrollTop = scroll;
    Object.keys(kept).forEach(function (k) {
      var el = k.charAt(0) === 'f' ? bodyEl.querySelector('[data-vm-f="' + k.slice(2) + '"]') : bodyEl.querySelector('[data-vm-crew="' + k.slice(2) + '"]');
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
      case 'edit-when':
        ui = { edit: 'when', focusForm: true }; paint();
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
    else if (c.kind === 'start') req = post('/crm/api/pow-actions.php', { action: 'start_visit', visit_id: id });
    else if (c.kind === 'complete') req = post('/crm/api/pow-actions.php', { action: 'end_visit', visit_id: id });
    else if (c.kind === 'skip') req = post('/crm/api/pow-actions.php', { action: 'skip_visit', visit_id: id });
    else if (c.kind === 'resize') {
      return doTime(c.start, c.end, true, null, true).then(function () { return c.resume ? c.resume() : null; })
        .then(function () { done('Saved.'); }, function (e) { if (e && e.overlap) return; ui.confirm = null; fail(typeof e === 'string' ? e : 'Couldn’t save. Nothing more was changed.'); });
    }
    else return;
    req.then(function (j) {
      var m = failed(j); if (m) { ui.confirm = c; return fail(m); }
      done({ 'note-delete': 'Note deleted.', cancel: 'Visit cancelled.', start: 'Visit started.', complete: 'Visit marked done.', skip: 'Visit skipped.' }[c.kind]);
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
    var saved = [];
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
        ui.confirm = { kind: 'resize', text: (j.message || 'This overlaps another visit for the same person.') + ' Keep the new time anyway?', yes: 'Keep anyway', no: 'Go back', start: start, end: end, resume: then };
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
    if (root.hidden) {
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
    token++;
    root.hidden = true;
    document.body.classList.remove('mw-vm-lock');
    document.removeEventListener('keydown', onKey, true);
    var cb = onCloseCb; onCloseCb = null; onChangeCb = null;
    if (cb) { try { cb(currentId); } catch (e) {} }
    else if (opener && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
    opener = null;
  }

  window.MwVisitModal = { open: open, close: close };
})();
