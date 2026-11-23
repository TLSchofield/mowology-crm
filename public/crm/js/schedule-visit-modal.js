/**
 * Schedule visit modal — click a job on the Timeline and read everything about it.
 * Loaded by schedule.php next to schedule-calendar-edit.js. Read-only: the data comes from
 * /crm/api/visit-summary.php (VisitSummaryService); changing a visit stays on the full visit page.
 *
 *   MwVisitModal.open(visitId, { onClose: fn })   open (or switch to) a visit
 *   MwVisitModal.close()
 *
 * One modal element is built on first use. It traps Tab, closes on Escape, the backdrop or ×,
 * and gives focus back to whatever opened it.
 */
(function () {
  'use strict';
  if (window.MwVisitModal) return;

  var API = '/crm/api/visit-summary.php';
  var FULL_PAGE = '/crm/jobs/visit-detail.php?id=';
  var root = null, dialog = null, bodyEl = null, footEl = null, titleEl = null, subEl = null, statusEl = null;
  var currentId = 0, opener = null, onCloseCb = null, token = 0;

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
  function section(title, inner) {
    return inner ? '<section class="mw-vm-sec"><h3 class="mw-vm-h">' + esc(title) + '</h3>' + inner + '</section>' : '';
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
        '<div class="mw-vm-body" aria-live="polite"></div>' +
        '<footer class="mw-vm-foot"></footer>' +
      '</div>';
    document.body.appendChild(root);
    dialog = root.querySelector('.mw-vm-dialog');
    bodyEl = root.querySelector('.mw-vm-body');
    footEl = root.querySelector('.mw-vm-foot');
    titleEl = root.querySelector('.mw-vm-title');
    subEl = root.querySelector('.mw-vm-sub');
    statusEl = root.querySelector('.mw-vm-status');
    root.addEventListener('click', function (e) { if (e.target.closest('[data-vm-close]')) close(); });
  }

  function focusables() {
    return [].filter.call(dialog.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"]'), function (el) { return el.offsetParent !== null; });
  }
  function onKey(e) {
    if (root.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key !== 'Tab') return;
    var f = focusables(); if (!f.length) { e.preventDefault(); dialog.focus(); return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  // ── Render ────────────────────────────────────────────────
  function showLoading() {
    titleEl.textContent = 'Loading job…';
    subEl.textContent = '';
    statusEl.innerHTML = '';
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

  function render(s) {
    var v = s.visit, j = s.job, c = s.client, p = s.property;
    var who = c.company || c.name || j.title || 'Job';
    var addr = [p.address, p.city].filter(Boolean).join(', ');
    titleEl.textContent = who;
    subEl.textContent = addr;
    statusEl.innerHTML = chip(v.status);

    var when = dayText(v.date) + (v.start ? ', ' + clock(v.start) + (v.end ? ' to ' + clock(v.end) : '') : (v.date ? ', any time' : ''));
    var crew = (s.crew || []).length ? esc(s.crew.join(', ')) : '<span class="mw-vm-muted">No one yet</span>';
    var h = '';
    h += section('When and who',
      '<dl class="mw-vm-dl">' + row('When', esc(when)) + row('Length', esc(length(v.minutes))) +
      row('Crew', crew) + row('Repeats', esc(j.repeat)) +
      row('Started', v.started_at ? esc(stamp(v.started_at)) + ' ' + esc(clock(String(v.started_at).split(' ')[1])) : '') +
      row('Finished', v.completed_at ? esc(stamp(v.completed_at)) + ' ' + esc(clock(String(v.completed_at).split(' ')[1])) : '') + '</dl>');
    h += section('Job',
      '<dl class="mw-vm-dl">' + row('Service', esc(j.service)) + row('Job', esc(j.title) + (j.plan_number ? ' <span class="mw-vm-muted">' + esc(j.plan_number) + '</span>' : '')) +
      row('Price per visit', esc(money(v.price))) + row('Visit number', esc(v.number)) + '</dl>' +
      (j.description ? '<p class="mw-vm-text">' + esc(j.description) + '</p>' : ''));
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
    var notes = (s.notes || []).slice();
    if (v.completion_notes) notes.unshift({ scope: 'visit', type: 'general', text: v.completion_notes, author: null, at: v.completed_at });
    if (notes.length) {
      h += section('Notes', '<ul class="mw-vm-notes">' + notes.map(function (n) {
        return '<li><div class="mw-vm-notehead"><span>' + esc(NOTE_TYPE[n.type] || 'Note') + (n.scope === 'job' ? ' <span class="mw-vm-muted">(whole job)</span>' : '') + '</span>' +
          '<span class="mw-vm-muted">' + esc([n.author, stamp(n.at)].filter(Boolean).join(' · ')) + '</span></div><p class="mw-vm-text">' + esc(n.text) + '</p></li>';
      }).join('') + '</ul>');
    }
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
    bodyEl.innerHTML = h;
    bodyEl.scrollTop = 0;

    var nav = p.lat != null && p.lng != null
      ? 'https://www.google.com/maps/dir/?api=1&destination=' + p.lat + ',' + p.lng
      : (addr ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr + ', BC, Canada') : '');
    footEl.innerHTML =
      '<a class="mw-vm-btn mw-vm-btn--main" href="' + FULL_PAGE + encodeURIComponent(v.id) + '">Open full visit</a>' +
      (nav ? '<a class="mw-vm-btn" href="' + esc(nav) + '" target="_blank" rel="noopener">Directions</a>' : '') +
      (c.phone ? '<a class="mw-vm-btn" href="tel:' + esc(c.phone) + '">Call client</a>' : '') +
      '<button type="button" class="mw-vm-btn mw-vm-btn--quiet" data-vm-close>Close</button>';
  }

  // ── Load / open / close ───────────────────────────────────
  function load(id) {
    var mine = ++token;
    showLoading();
    fetch(API + '?visit_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); })
      .then(function (j) {
        if (mine !== token) return;
        if (j && j.success && j.summary) { render(j.summary); return; }
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
    var cb = onCloseCb; onCloseCb = null;
    if (cb) { try { cb(currentId); } catch (e) {} }
    else if (opener && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
    opener = null;
  }

  window.MwVisitModal = { open: open, close: close };
})();
