/**
 * "Move to another job" modal for an invoice (invoices/view.php and edit.php).
 * Any element with data-mw-invoice-move="<invoice id>" opens it. All logic is server-side
 * (api/invoice-move.php → InvoiceMoveService); this only shows candidates, a preview and posts the move.
 * All text goes in through textContent — nothing from the server is parsed as HTML.
 */
(function () {
  'use strict';

  var API = '/crm/api/invoice-move.php';
  var overlay, body, searchBox, moveBtn, invoiceId = 0, picked = null, timer = null;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function toast(msg, type) {
    if (typeof window.mwToast === 'function') window.mwToast(msg, type); else window.alert(msg);
  }

  function getJson(params) {
    return fetch(API + '?' + params, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function build() {
    overlay = el('div', 'mw-modal-overlay');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    var box = el('div', 'mw-modal mw-modal-wide mw-move');
    var head = el('div', 'mw-modal-header');
    head.appendChild(el('h5', 'mb-0', 'Move invoice to another job'));
    var x = el('button', 'mw-modal-close', '×');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    x.addEventListener('click', close);
    head.appendChild(x);
    box.appendChild(head);

    searchBox = el('input', 'form-control mw-move-search');
    searchBox.type = 'search';
    searchBox.placeholder = 'Search all jobs by number or title';
    searchBox.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(loadCandidates, 250);
    });
    box.appendChild(searchBox);

    body = el('div', 'mw-modal-body mw-move-body');
    box.appendChild(body);

    var foot = el('div', 'mw-move-foot');
    var cancel = el('button', 'btn btn-outline-secondary', 'Cancel');
    cancel.type = 'button';
    cancel.addEventListener('click', close);
    moveBtn = el('button', 'btn btn-primary', 'Move');
    moveBtn.type = 'button';
    moveBtn.disabled = true;
    moveBtn.addEventListener('click', doMove);
    foot.appendChild(cancel);
    foot.appendChild(moveBtn);
    box.appendChild(foot);

    overlay.appendChild(box);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.body.appendChild(overlay);
  }

  function open(id) {
    if (!overlay) build();
    invoiceId = id;
    picked = null;
    searchBox.value = '';
    moveBtn.disabled = true;
    overlay.classList.add('show');
    loadCandidates();
  }

  function close() {
    if (overlay) overlay.classList.remove('show');
  }

  function loadCandidates() {
    body.textContent = 'Loading…';
    getJson('mode=candidates&invoice_id=' + invoiceId + '&q=' + encodeURIComponent(searchBox.value))
      .then(function (d) {
        body.textContent = '';
        if (!d.ok) { body.appendChild(el('div', 'mw-move-error', d.error || 'Could not load jobs.')); return; }
        if (!d.jobs.length) { body.appendChild(el('div', 'mw-move-empty', 'No matching jobs.')); return; }
        d.jobs.forEach(function (j) { body.appendChild(jobRow(j)); });
      })
      .catch(function () { body.textContent = 'Network error — please try again.'; });
  }

  function jobRow(j) {
    var row = el('div', 'mw-move-job' + (j.same_property ? ' mw-move-job--same' : ''));
    var title = el('div', 'mw-move-job-title', j.plan_number + (j.title ? ' — ' + j.title : ''));
    if (j.same_property) title.appendChild(el('span', 'mw-move-badge', 'Same building'));
    else if (j.same_address) title.appendChild(el('span', 'mw-move-badge', 'Same address'));
    row.appendChild(title);
    row.appendChild(el('div', 'mw-move-job-sub', j.property_label));
    var name = 'mw-move-' + j.plan_id;
    j.visits.forEach(function (v) {
      row.appendChild(choice(name, j.plan_id, v.visit_id, v.visit_number + (v.scheduled_date ? ' · ' + v.scheduled_date : '') + ' (completed, not invoiced)'));
    });
    row.appendChild(choice(name, j.plan_id, 0, 'No visit — link the job only'));
    return row;
  }

  function choice(name, planId, visitId, text) {
    var label = el('label', 'mw-move-choice');
    var r = document.createElement('input');
    r.type = 'radio';
    r.name = 'mw-move-pick';
    r.value = planId + ':' + visitId;
    r.addEventListener('change', function () { pick(planId, visitId); });
    label.appendChild(r);
    label.appendChild(el('span', '', ' ' + text));
    return label;
  }

  function pick(planId, visitId) {
    picked = null;
    moveBtn.disabled = true;
    var old = body.querySelector('.mw-move-preview');
    if (old) old.remove();
    var box = el('div', 'mw-move-preview', 'Checking…');
    body.insertBefore(box, body.firstChild);
    getJson('mode=preview&invoice_id=' + invoiceId + '&plan_id=' + planId + '&visit_id=' + visitId)
      .then(function (p) {
        box.textContent = '';
        if (!p.ok) { box.className = 'mw-move-preview mw-move-error'; box.textContent = p.error || 'Cannot move there.'; return; }
        box.appendChild(line('From', p.from));
        box.appendChild(line('To', p.to));
        if (p.property_changes) box.appendChild(el('div', 'mw-move-note', 'The property (service address) changes.'));
        box.appendChild(el('div', 'mw-move-note', 'Recipients now: ' + (p.recipients_before.join(', ') || 'none')));
        if (p.recipients_will_change) {
          box.appendChild(el('div', 'mw-move-note', 'Recipients after: ' + (p.recipients_after.join(', ') || 'none')));
        }
        p.warnings.forEach(function (w) { box.appendChild(el('div', 'mw-move-warn', w)); });
        picked = { plan: planId, visit: visitId };
        moveBtn.disabled = false;
        box.scrollIntoView({ block: 'nearest' });
      })
      .catch(function () { box.textContent = 'Network error — please try again.'; });
  }

  function line(label, s) {
    var t = s.plan_number + (s.title ? ' — ' + s.title : '') + (s.visit_number ? ' / ' + s.visit_number : '')
      + (s.property_label ? ' (' + s.property_label + ')' : '');
    var d = el('div', 'mw-move-line');
    d.appendChild(el('strong', '', label + ': '));
    d.appendChild(document.createTextNode(t));
    return d;
  }

  function doMove() {
    if (!picked) return;
    moveBtn.disabled = true;
    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mode: 'move', invoice_id: invoiceId, plan_id: picked.plan,
                             visit_id: picked.visit, csrf_token: window.MW_CSRF_TOKEN || '' })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.ok) {
        window.location.href = '/crm/invoices/view.php?id=' + invoiceId + '&flash=moved';
      } else {
        toast(d.error || 'Could not move the invoice.', 'error');
        moveBtn.disabled = false;
      }
    }).catch(function () {
      toast('Network error — please try again.', 'error');
      moveBtn.disabled = false;
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-mw-invoice-move]') : null;
    if (!t) return;
    e.preventDefault();
    open(parseInt(t.getAttribute('data-mw-invoice-move'), 10));
  });
})();
