(function () {
  'use strict';

  var root = document.getElementById('pennyOnboarding');
  if (!root) return;

  var API = '/api/settings/business-identity';
  var line = document.getElementById('itObPennyLine');
  var workspace = document.getElementById('itObWorkspace');
  var error = document.getElementById('itObError');
  var knowledge = document.getElementById('itObKnowledge');
  var stageLabel = document.getElementById('itObStageLabel');
  var triangle = root.querySelector('.it-ob-triangle');
  var sides = Array.prototype.slice.call(triangle.querySelectorAll('line'));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var identity = null;
  var currentStage = null;
  var enteredName = '';
  var matches = [];
  var animationFrame = 0;
  var locked = false;
  var endpoints = [
    [36, 10, 63, 57],
    [63, 57, 9, 57],
    [9, 57, 36, 10]
  ];
  var chaos = [
    [29, 24, -12, 1.1, .2],
    [40, 52, 8, .8, 2.3],
    [26, 43, -9, 1.25, 4.4]
  ];

  function esc(value) {
    var node = document.createElement('div');
    node.textContent = value == null ? '' : String(value);
    return node.innerHTML;
  }
  function escAttr(value) {
    return esc(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function setLine(message) { line.textContent = message; }
  function setError(message) {
    error.textContent = message || '';
    error.hidden = !message;
  }
  function focusFirst() {
    var target = workspace.querySelector('input, select, button');
    if (target) target.focus();
  }
  function busy(on) {
    Array.prototype.forEach.call(workspace.querySelectorAll('button, input, select'), function (el) { el.disabled = on; });
  }
  function calmError(err) {
    if (err && err.status === 403) return 'You do not have permission to change this business identity.';
    if (err && err.status === 401) return 'Your session has ended. Please sign in again.';
    if (err && err.message) return err.message;
    return 'Something interrupted that request. Please try again.';
  }
  function api(mode, options) {
    options = options || {};
    var method = options.method || 'GET';
    var url = API + '?mode=' + encodeURIComponent(mode);
    if (options.query) url += '&' + new URLSearchParams(options.query).toString();
    var headers = { Accept: 'application/json' };
    if (method === 'POST') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = csrf;
    }
    return fetch(url, { method: method, credentials: 'same-origin', headers: headers, body: options.body ? JSON.stringify(options.body) : undefined })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || data.ok === false) {
            var requestError = new Error(data.message || data.error || 'I could not save that just now. Please try again.');
            requestError.status = response.status;
            throw requestError;
          }
          return data;
        });
      });
  }

  function setLineCoords(side, values) {
    side.setAttribute('x1', values[0]); side.setAttribute('y1', values[1]);
    side.setAttribute('x2', values[2]); side.setAttribute('y2', values[3]);
  }
  function looseCoords(i, time) {
    var c = chaos[i];
    var e = endpoints[i];
    var length = Math.hypot(e[2] - e[0], e[3] - e[1]);
    var baseAngle = Math.atan2(e[3] - e[1], e[2] - e[0]);
    var x = c[0] + Math.sin(time * .0007 + c[4]) * c[3];
    var y = c[1] + Math.cos(time * .0009 + c[4]) * c[3];
    var angle = baseAngle + c[2] * Math.PI / 180 + Math.sin(time * .0005 + c[4]) * .025;
    var dx = Math.cos(angle) * length / 2;
    var dy = Math.sin(angle) * length / 2;
    return [x - dx, y - dy, x + dx, y + dy];
  }
  function drift(time) {
    if (locked || reduced) return;
    sides.forEach(function (side, i) { setLineCoords(side, looseCoords(i, time)); });
    animationFrame = window.requestAnimationFrame(drift);
  }
  function beginDrift() {
    locked = false;
    triangle.classList.remove('it-ob-understood');
    window.cancelAnimationFrame(animationFrame);
    if (reduced) {
      sides.forEach(function (side, i) { setLineCoords(side, looseCoords(i, 0)); });
      return;
    }
    animationFrame = window.requestAnimationFrame(drift);
  }
  function lockTriangle(fill, animate) {
    locked = true;
    window.cancelAnimationFrame(animationFrame);
    var starts = sides.map(function (side) {
      return ['x1', 'y1', 'x2', 'y2'].map(function (attr) { return parseFloat(side.getAttribute(attr)); });
    });
    if (!animate || reduced) {
      sides.forEach(function (side, i) { setLineCoords(side, endpoints[i]); });
      triangle.classList.toggle('it-ob-understood', fill);
      return;
    }
    var started;
    function frame(now) {
      if (!started) started = now;
      var p = Math.min(1, (now - started) / 1200);
      var eased = p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
      sides.forEach(function (side, i) {
        setLineCoords(side, starts[i].map(function (v, j) { return v + (endpoints[i][j] - v) * eased; }));
      });
      if (p < 1) window.requestAnimationFrame(frame);
      else triangle.classList.toggle('it-ob-understood', fill);
    }
    window.requestAnimationFrame(frame);
  }
  function updateStage(stage, animate) {
    var labels = { recognized: 'Recognized', identified: 'Identified', understood: 'Understood', mastered: 'Mastered' };
    if (!stage || !labels[stage]) { knowledge.hidden = true; currentStage = stage || null; return; }
    knowledge.hidden = false;
    stageLabel.textContent = labels[stage];
    triangle.setAttribute('aria-label', 'Knowledge stage: ' + labels[stage]);
    if (stage === 'recognized') beginDrift();
    else lockTriangle(stage === 'understood', animate && currentStage !== stage);
    currentStage = stage;
  }

  function field(label, value, provenance) {
    if (value === undefined || value === null || value === '') return '';
    var provenanceLabels = { registry_verified: 'Registry verified', owner_confirmed: 'You confirmed', owner_provided: 'You provided' };
    return '<div class="it-ob-detail"><dt>' + esc(label) + '</dt><dd>' + esc(value) +
      (provenance && provenanceLabels[provenance] ? '<span class="it-ob-provenance">' + provenanceLabels[provenance] + '</span>' : '') + '</dd></div>';
  }
  function operatingName(record) {
    if (record.operating_name) return record.operating_name;
    return record.operating_names && record.operating_names.length ? record.operating_names[0] : '';
  }
  function namePresentation(record) {
    var typed = enteredName || (identity && identity.entered_name) || '';
    if (typed && record.legal_name && typed.toLocaleLowerCase() !== record.legal_name.toLocaleLowerCase()) {
      return 'Operating as ' + typed + ' · Registered as ' + record.legal_name;
    }
    return record.legal_name || typed;
  }
  function registryCard(record, withActions) {
    var html = '<article class="it-ob-card"><h2 class="it-ob-card-title">' + esc(namePresentation(record)) + '</h2><dl class="it-ob-details">';
    html += field('Registered legal name', record.legal_name);
    html += field('Operating name', operatingName(record));
    html += field('BC registration number', record.registration_number);
    html += field('Legal structure', record.legal_structure);
    html += field('Status', record.status || record.registration_status);
    html += field('Jurisdiction', record.jurisdiction);
    html += '</dl><p class="it-ob-attribution">Contains information licensed under the Open Government Licence – British Columbia.</p>';
    if (withActions) html += '<div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="button" data-action="confirm">Yes, that\'s my business</button><button class="it-ob-button" type="button" data-action="others">No, show other matches</button><button class="it-ob-button it-ob-button-link" type="button" data-action="manual">Enter details manually</button></div>';
    return html + '</article>';
  }

  function showNameQuestion() {
    identity = null; enteredName = ''; matches = []; setError(''); updateStage(null, false);
    setLine("Hello! I'm Penny. I'll be looking after your books and helping you keep your business finances organized. Let's start with something simple. What's your business called?");
    workspace.innerHTML = '<form class="it-ob-form" id="itObNameForm"><div class="it-ob-field"><label for="itObBusinessName">Business name</label><input class="it-ob-input" id="itObBusinessName" name="name" type="text" maxlength="255" autocomplete="organization" required></div><div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="submit">Continue</button></div></form>';
    document.getElementById('itObNameForm').addEventListener('submit', start);
    focusFirst();
  }
  function start(event) {
    event.preventDefault(); setError('');
    var input = document.getElementById('itObBusinessName');
    enteredName = input.value.trim();
    if (!enteredName) { setError('Please enter the name you use for your business.'); input.focus(); return; }
    busy(true);
    api('start', { method: 'POST', body: { name: enteredName } }).then(function (data) {
      identity = data.identity || { entered_name: enteredName };
      updateStage(data.knowledge && data.knowledge.stage || 'recognized', true);
      search();
    }).catch(function (err) { busy(false); setError(calmError(err)); });
  }
  function search() {
    setError(''); setLine('Looking this up in the BC Registry…');
    workspace.innerHTML = '<p class="it-ob-progress" role="status">Looking this up in the BC Registry…</p>';
    api('search', { query: { q: enteredName } }).then(renderSearch).catch(function () { renderSearch({ status: 'unavailable', matches: [] }); });
  }
  function renderSearch(data) {
    matches = (data.matches || []).slice(0, 5);
    if (data.status === 'matches' && matches.length === 1) return showMatch(matches[0]);
    if (data.status === 'matches' && matches.length > 1) return showMatches();
    if (data.status === 'none' || (data.status === 'matches' && !matches.length)) {
      setLine("I couldn't find a confident match. That's okay. We can enter your business details ourselves.");
      return showManual();
    }
    setLine("I can't reach the BC Registry right now.");
    workspace.innerHTML = '<div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="button" data-action="retry">Try again</button><button class="it-ob-button" type="button" data-action="manual">Enter details manually</button></div>';
    bindActions(); focusFirst();
  }
  function showMatches() {
    setLine('I found a few businesses that may be yours. Choose one to check the details.');
    workspace.innerHTML = '<div class="it-ob-match-list" role="list">' + matches.map(function (match, i) {
      var secondary = match.registration_number || match.jurisdiction || match.legal_structure || '';
      return '<button type="button" class="it-ob-match-row" data-match="' + i + '" role="listitem"><span><span>' + esc(match.legal_name || operatingName(match)) + '</span>' + (secondary ? '<small>' + esc(secondary) + '</small>' : '') + '</span></button>';
    }).join('') + '</div><div class="it-ob-actions"><button class="it-ob-button it-ob-button-link" type="button" data-action="manual">Enter details manually</button></div>';
    Array.prototype.forEach.call(workspace.querySelectorAll('[data-match]'), function (button) {
      button.addEventListener('click', function () { showMatch(matches[Number(button.getAttribute('data-match'))]); });
    });
    bindActions(); focusFirst();
  }
  function showMatch(record) {
    setLine('I found a business that looks like yours. Is this the right one?');
    workspace.innerHTML = registryCard(record, true);
    workspace.querySelector('[data-action="confirm"]').addEventListener('click', function () { confirmMatch(record); });
    bindActions(); focusFirst();
  }
  function bindActions() {
    var retry = workspace.querySelector('[data-action="retry"]'); if (retry) retry.addEventListener('click', search);
    var manual = workspace.querySelector('[data-action="manual"]'); if (manual) manual.addEventListener('click', showManual);
    var others = workspace.querySelector('[data-action="others"]'); if (others) others.addEventListener('click', function () { matches.length > 1 ? showMatches() : search(); });
  }
  function confirmMatch(record) {
    setError(''); busy(true);
    api('confirm', { method: 'POST', body: { record_id: record.record_id } }).then(function (data) {
      identity = data.identity; updateStage(data.knowledge && data.knowledge.stage || 'identified', true); showComplete();
    }).catch(function (err) { busy(false); setError(calmError(err)); });
  }
  function showManual() {
    setLine("We'll enter the details you know. You can update them later.");
    workspace.innerHTML = '<form class="it-ob-form" id="itObManualForm"><div class="it-ob-field"><label for="itObLegalName">Legal name</label><input class="it-ob-input" id="itObLegalName" name="legal_name" type="text" maxlength="255" required value="' + escAttr(enteredName) + '"></div><div class="it-ob-field"><label for="itObOperatingName">Operating name <span class="it-ob-muted">(optional)</span></label><input class="it-ob-input" id="itObOperatingName" name="operating_name" type="text" maxlength="255"></div><div class="it-ob-form-grid"><div class="it-ob-field"><label for="itObStructure">Legal structure</label><select class="it-ob-select" id="itObStructure" name="legal_structure"><option value="">Select if known</option><option>Sole proprietorship</option><option>Partnership</option><option>Corporation</option><option>Society</option><option>Cooperative</option><option>Other</option></select></div><div class="it-ob-field"><label for="itObJurisdiction">Jurisdiction</label><select class="it-ob-select" id="itObJurisdiction" name="jurisdiction"><option value="">Select if known</option><option value="BC">British Columbia</option><option value="Federal">Federal</option><option value="AB">Alberta</option><option value="Other">Other</option></select></div></div><div class="it-ob-field"><label for="itObRegistration">BC registration number <span class="it-ob-muted">(optional)</span></label><input class="it-ob-input" id="itObRegistration" name="registration_number" type="text" maxlength="100"><small>This is not your CRA Business Number.</small></div><div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="submit">Save business details</button></div></form>';
    document.getElementById('itObManualForm').addEventListener('submit', saveManual); focusFirst();
  }
  function saveManual(event) {
    event.preventDefault(); setError('');
    var form = event.currentTarget;
    var body = {};
    ['legal_name', 'operating_name', 'legal_structure', 'jurisdiction', 'registration_number'].forEach(function (key) {
      var value = form.elements[key].value.trim();
      if (value || key === 'legal_name') body[key] = value;
    });
    busy(true);
    api('manual', { method: 'POST', body: body }).then(function (data) {
      identity = data.identity; updateStage(data.knowledge && data.knowledge.stage || 'identified', true); showComplete();
    }).catch(function (err) { busy(false); setError(calmError(err)); });
  }
  function showComplete() {
    var legal = identity.legal_name || identity.entered_name || 'your business';
    setLine("Thank you — I've noted your business as " + legal + '.');
    workspace.innerHTML = '<p class="it-ob-summary-lead">Business identity saved</p><p class="it-ob-muted">Next, I\'ll ask a couple of questions about how you run your books.</p>';
  }
  function showReturning(data) {
    identity = data.identity; enteredName = identity.entered_name || '';
    updateStage(data.knowledge && data.knowledge.stage, false);
    setLine("Welcome back. Here's the business identity I have saved.");
    var p = identity.provenance || {};
    var html = '<article class="it-ob-card"><h2 class="it-ob-card-title">' + esc(namePresentation(identity)) + '</h2><dl class="it-ob-details">';
    html += field('Name you entered', identity.entered_name, p.entered_name);
    html += field('Registered legal name', identity.legal_name, p.legal_name);
    html += field('Operating name', identity.operating_name, p.operating_name);
    html += field('BC registration number', identity.registration_number, p.registration_number);
    html += field('Legal structure', identity.legal_structure, p.legal_structure);
    html += field('Status', identity.registration_status, p.registration_status);
    html += field('Jurisdiction', identity.jurisdiction, p.jurisdiction);
    html += '</dl>' + (identity.source === 'orgbook_bc' ? '<p class="it-ob-attribution">' + esc(data.attribution || 'Contains information licensed under the Open Government Licence – British Columbia.') + '</p>' : '') + '</article>';
    html += data.can_edit ? '<button class="it-ob-change" type="button" data-action="change">Change</button>' : '';
    workspace.innerHTML = html;
    var change = workspace.querySelector('[data-action="change"]'); if (change) change.addEventListener('click', showNameQuestion);
  }

  setLine('Let me check what I already know about your business.');
  workspace.innerHTML = '<p class="it-ob-progress" role="status">Loading your business identity…</p>';
  api('current').then(function (data) {
    if (data.identity) showReturning(data);
    else if (data.can_edit === false) {
      setLine('No business identity has been added yet. An administrator with edit access can complete this step.');
      workspace.innerHTML = '';
    } else showNameQuestion();
  }).catch(function (err) {
    setLine('I could not load your business identity just now.');
    workspace.innerHTML = '<div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="button" id="itObReload">Try again</button></div>';
    setError(calmError(err)); document.getElementById('itObReload').addEventListener('click', function () { window.location.reload(); });
  });
}());
