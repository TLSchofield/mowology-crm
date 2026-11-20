/**
 * Tidy iCloud page (/crm/icloud_tidy_appstack.php) — drives /crm/api/icloud-tidy.php.
 * Preview, Apply and Undo each run in ~20 s server steps; this script repeats the call
 * until the step says done, with a progress bar and a Stop button. Nothing moves unless
 * Tim clicks Apply / Move ticked / Undo.
 */
(function () {
  'use strict';
  var root = document.getElementById('mw-tidy');
  if (!root) return;
  var API = '/crm/api/icloud-tidy.php';
  var state = { previewId: null, busy: false, stop: false, summary: null };
  var $ = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function folderName(imap) { return String(imap || '').replace(/&-/g, '&'); }
  function toast(msg, kind) { if (typeof window.mwToast === 'function') window.mwToast(msg, kind || 'success'); }
  function num(n) { return Number(n || 0).toLocaleString(); }

  function post(mode, body) {
    body = body || {};
    body.mode = mode;
    body.csrf_token = window.MW_CSRF_TOKEN || '';
    return fetch(API, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }
  function getPlan() {
    return fetch(API + '?mode=plan', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
  }

  function setBusy(on, text, frac) {
    state.busy = on;
    $('mw-tidy-progress').hidden = !on;
    if (text != null) $('mw-tidy-progress-text').textContent = text;
    if (frac != null) $('mw-tidy-bar').style.width = Math.max(2, Math.min(100, Math.round(frac * 100))) + '%';
    ['mw-tidy-preview', 'mw-tidy-rescue'].forEach(function (id) { if ($(id)) $(id).disabled = on || (id === 'mw-tidy-rescue' && !hasTicked()); });
    root.querySelectorAll('[data-apply],[data-undo]').forEach(function (b) { b.disabled = on; });
  }

  // ── Rendering ─────────────────────────────────────────────────────────────
  function render(s) {
    if (!s || !s.ok) return;
    state.summary = s;
    state.previewId = Number(s.preview.id);
    var p = s.preview;
    var scanning = p.status === 'scanning';
    var html = '<div class="mw-tidy-stats">'
      + stat('Checked', num(p.scanned)) + stat('Would move', num(p.to_move)) + stat('Stays put', num(p.stayed))
      + stat('Junk to look at', num(p.rescue)) + '</div>';
    if (scanning) html += '<p class="mw-tidy-note">Preview still scanning — press Run a preview to carry on where it stopped.</p>';

    var create = s.create || [];
    if (create.length) {
      html += '<p class="mw-tidy-note">New folders that would be created: ' + create.map(function (f) {
        return '<span class="mw-tidy-tag is-new">' + esc(f.label) + '</span>'; }).join(' ') + '</p>';
    }
    var stays = (s.cursors || []).filter(function (c) { return c.role === 'tidy'; }).map(function (c) {
      return esc(folderName(c.folder)) + ': ' + num(c.scanned) + ' checked, ' + num(c.stayed) + ' unsure' + (Number(c.recent) ? ', ' + num(c.recent) + ' recent' : '') + (Number(c.done) ? '' : ' (scanning)');
    });
    if (stays.length) html += '<p class="mw-tidy-note">' + stays.join(' · ') + '</p>';

    if ((s.pairs || []).length) {
      html += '<div class="table-responsive"><table class="table table-sm mw-tidy-table"><thead><tr><th>From</th><th>To</th>'
        + '<th class="text-right">Messages</th><th class="text-right">Moved</th><th>Examples</th></tr></thead><tbody>';
      s.pairs.forEach(function (r) {
        var ex = (r.samples || []).map(function (x) {
          return '<li><span class="mw-tidy-from">' + esc(x.from) + '</span> ' + esc(x.subject) + ' <em>' + esc(x.why) + '</em></li>';
        }).join('');
        html += '<tr><td>' + esc(folderName(r.from)) + '</td><td><strong>' + esc(r.to_label) + '</strong></td>'
          + '<td class="text-right">' + num(r.count) + '</td><td class="text-right">' + num(r.moved) + '</td>'
          + '<td>' + (ex ? '<details><summary>' + (r.samples.length) + ' example' + (r.samples.length === 1 ? '' : 's') + '</summary><ul class="mw-tidy-samples">' + ex + '</ul></details>' : '') + '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
    var pend = (s.pending && s.pending.move) || 0;
    var max = Number((state.settings || {}).batch_max || 2000);
    if (!scanning && pend > 0) {
      html += '<div class="mw-tidy-row"><span class="mw-tidy-note mb-0">' + num(pend) + ' message(s) waiting. One click moves up to ' + num(Math.min(max, pend)) + '.</span>'
        + '<button type="button" class="btn btn-success" data-apply="apply">Apply next batch (' + num(Math.min(max, pend)) + ')</button></div>';
    } else if (!scanning && p.to_move > 0) {
      html += '<p class="mw-tidy-note">Everything in this preview has been filed.</p>';
    } else if (!scanning) {
      html += '<p class="mw-tidy-note">Nothing to move — all tidy.</p>';
    }
    $('mw-tidy-summary').innerHTML = html;
    renderJunk(s.rescue || [], scanning);
    renderBatches(s.batches || []);
  }

  function stat(k, v) { return '<div class="mw-tidy-stat"><div class="mw-k">' + k + '</div><div class="mw-v">' + v + '</div></div>'; }

  function renderJunk(list, scanning) {
    var box = $('mw-tidy-junk');
    if (!list.length) {
      box.innerHTML = '<div class="mw-tidy-empty">' + (scanning ? 'Still checking Junk…' : 'Nothing in Junk looks like your work mail.') + '</div>';
      $('mw-tidy-rescue').disabled = true;
      return;
    }
    box.innerHTML = '<ul class="mw-tidy-junk">' + list.map(function (r) {
      var done = r.status !== 'pending';
      return '<li class="' + (done ? 'is-done' : '') + '"><label><input type="checkbox" data-item="' + Number(r.id) + '"'
        + (Number(r.selected) ? ' checked' : '') + (done ? ' disabled' : '') + '> '
        + '<span class="mw-tidy-from">' + esc(r.from_addr) + '</span> <strong>' + esc(r.subject) + '</strong>'
        + ' <em>' + esc(r.reason) + ' · ' + esc(folderName(r.folder)) + (r.msg_date ? ' · ' + esc(String(r.msg_date).slice(0, 10)) : '') + '</em>'
        + (done ? ' <span class="mw-tidy-tag is-existing">' + esc(r.status) + '</span>' : '') + '</label></li>';
    }).join('') + '</ul>';
    $('mw-tidy-rescue').disabled = state.busy || !hasTicked();
  }

  function hasTicked() {
    return !!root.querySelector('#mw-tidy-junk input[data-item]:checked:not(:disabled)');
  }

  function renderBatches(list) {
    var box = $('mw-tidy-batches');
    if (!list.length) { box.innerHTML = '<div class="mw-tidy-empty">Nothing moved yet.</div>'; return; }
    var names = { apply: 'Filed', rescue: 'Rescued from Junk', undo: 'Undo', keep_tidy: 'Keep it tidy' };
    box.innerHTML = '<table class="table table-sm mw-tidy-table"><thead><tr><th>#</th><th>What</th><th>When</th><th class="text-right">Moved</th><th>Status</th><th></th></tr></thead><tbody>'
      + list.map(function (b) {
        return '<tr><td>' + Number(b.id) + '</td><td>' + esc(names[b.kind] || b.kind) + (b.undo_of ? ' of #' + Number(b.undo_of) : '') + '</td>'
          + '<td>' + esc(String(b.started_at || '').slice(0, 16)) + '</td><td class="text-right">' + num(b.moved) + (Number(b.failed) ? ' <small>(' + num(b.failed) + ' skipped)</small>' : '') + '</td>'
          + '<td>' + esc(b.status) + (b.note ? ' <small>' + esc(b.note) + '</small>' : '') + '</td>'
          + '<td class="text-right">' + (Number(b.undoable) > 0 ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-undo="' + Number(b.id) + '">Undo (' + num(b.undoable) + ')</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  // ── Actions ───────────────────────────────────────────────────────────────
  function refresh() {
    return getPlan().then(function (d) {
      if (!d.ok) return;
      state.settings = d.settings || {};
      if (d.preview) render(d.preview); else renderBatches(d.batches || []);
    });
  }

  function runPreview() {
    if (state.busy) return;
    state.stop = false;
    var id = state.summary && state.summary.preview.status === 'scanning' ? state.previewId : null;
    setBusy(true, 'Reading envelopes (read-only)…', 0.02);
    var step = function () {
      return post('tidy_preview', { preview_id: id }).then(function (s) {
        if (!s.ok) throw new Error(s.error || 'Preview failed');
        id = Number(s.preview.id);
        render(s);
        var total = 0, done = 0;
        (s.cursors || []).forEach(function (c) { total += Number(c.uid_next || 0); done += Math.min(Number(c.last_uid || 0), Number(c.uid_next || 0)); });
        setBusy(true, num(s.preview.scanned) + ' checked…', total ? done / total : 0.5);
        if (s.preview.status === 'ready') return s;
        if (state.stop) { toast('Preview paused — run it again to carry on.', 'info'); return s; }
        return step();
      });
    };
    step().then(function () { setBusy(false); refresh(); })
      .catch(function (e) { setBusy(false); toast(e.message, 'error'); });
  }

  function apply(kind) {
    if (state.busy || !state.previewId) return;
    state.stop = false;
    var batch = null;
    var label = kind === 'rescue' ? 'Moving ticked mail to INBOX…' : 'Filing…';
    setBusy(true, label, 0.02);
    var step = function () {
      return post('tidy_apply', { preview_id: state.previewId, kind: kind, batch_id: batch }).then(function (r) {
        if (!r.ok) throw new Error(r.error || 'Apply failed');
        batch = r.batch_id;
        setBusy(true, num(r.moved) + ' of ' + num(r.target) + ' moved', r.target ? (r.moved + r.failed) / r.target : 1);
        if (r.error) throw new Error(r.error);
        if (r.done || !batch) return r;
        if (state.stop) return r;
        return step();
      });
    };
    step().then(function (r) {
      setBusy(false);
      toast(r.message || (num(r.moved) + ' message(s) moved' + (r.failed ? ', ' + num(r.failed) + ' skipped' : '') + (r.done ? '.' : ' — paused.')), r.failed ? 'warning' : 'success');
      refresh();
    }).catch(function (e) { setBusy(false); toast(e.message, 'error'); refresh(); });
  }

  function undo(batchId) {
    if (state.busy) return;
    if (!window.confirm('Move every message in batch #' + batchId + ' back where it came from?')) return;
    state.stop = false;
    var ub = null;
    setBusy(true, 'Moving batch #' + batchId + ' back…', 0.02);
    var step = function () {
      return post('tidy_undo', { batch: batchId, undo_batch_id: ub }).then(function (r) {
        if (!r.ok) throw new Error(r.error || 'Undo failed');
        ub = r.batch_id;
        setBusy(true, num(r.moved) + ' of ' + num(r.target) + ' moved back', r.target ? (r.moved + r.failed) / r.target : 1);
        if (r.error) throw new Error(r.error);
        if (r.done || !ub || state.stop) return r;
        return step();
      });
    };
    step().then(function (r) {
      setBusy(false);
      toast(r.message || (num(r.moved) + ' moved back' + (r.failed ? ', ' + num(r.failed) + ' not found (moved since)' : '') + '.'), r.failed ? 'warning' : 'success');
      refresh();
    }).catch(function (e) { setBusy(false); toast(e.message, 'error'); refresh(); });
  }

  // ── Wiring ────────────────────────────────────────────────────────────────
  $('mw-tidy-preview').addEventListener('click', runPreview);
  $('mw-tidy-stop').addEventListener('click', function () { state.stop = true; $('mw-tidy-progress-text').textContent = 'Stopping after this step…'; });
  $('mw-tidy-rescue').addEventListener('click', function () { apply('rescue'); });
  root.addEventListener('click', function (e) {
    var a = e.target.closest('[data-apply]');
    if (a) return apply(a.getAttribute('data-apply'));
    var u = e.target.closest('[data-undo]');
    if (u) return undo(Number(u.getAttribute('data-undo')));
  });
  root.addEventListener('change', function (e) {
    var cb = e.target.closest('input[data-item]');
    if (cb && state.previewId) {
      post('tidy_select', { preview_id: state.previewId, ids: [Number(cb.getAttribute('data-item'))], selected: cb.checked ? 1 : 0 })
        .then(function (r) { if (!r.ok) toast(r.error || 'Could not save', 'error'); });
      $('mw-tidy-rescue').disabled = state.busy || !hasTicked();
      return;
    }
    if (e.target.id === 'mw-tidy-keep') {
      var on = e.target.checked;
      post('keep_tidy', { on: on ? 1 : 0 }).then(function (r) {
        if (!r.ok) { e.target.checked = !on; toast(r.error || 'Could not save', 'error'); return; }
        toast(on ? 'Keep it tidy is on — new mail older than a week gets filed every 15 minutes.' : 'Keep it tidy is off.', 'info');
      });
    }
  });

  if (root.getAttribute('data-ready') === '1') refresh();
})();
